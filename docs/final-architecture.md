# Final Architecture — Fine-Grained Visual SKU Retrieval

Status: hasil kerja Prompt 01–43, diverifikasi 2026-09-18 (UTC).
Baseline: `a7cdf95` (+ `b80099e` modular retrieval). Seluruh pekerjaan di
working tree, belum di-commit. Tidak ada angka akurasi di dokumen ini:
tidak ada evaluasi berlabel produksi yang dijalankan.

Audit koreksi terakhir dan matriks kesesuaian seluruh 43 prompt:
[review-2026-09-18.md](review-2026-09-18.md). Dokumen itu mencatat bug mapping
ID object FAISS, kontrak auto/full, split leakage, ekspor, dan evaluasi yang
diperbaiki setelah implementasi awal; juga batas validasi data/perangkat nyata.

## 1. Existing architecture yang ditemukan pada awal pekerjaan

- Laravel (Blade + Tailwind + Vite): katalog, admin workspace, upload foto
  (`ProductController`), pencarian teks.
- `b80099e`: FastAPI + SigLIP2 (`google/siglip2-base-patch16-256`) + DINOv2
  (`facebook/dinov2-small`) + FAISS HNSW + SQLite, representasi
  global + 6 crop, weighted reranking, `build_index`/`evaluate`, kontrak
  `/embed` + cosine similarity database (pgvector/`findSimilarProducts`).
- `a7cdf95` (Phase 1): `ObjectSelectionController` → FastAPI `/select`
  (belum ada), `CropCoordinateValidator`, kolom crop di `product_photos`,
  canvas editor `object-selection.js`, rute `/admin/object-selection`.
- Yang belum ada: `/search` primer, `/select`, `/prepare`, patch tokens,
  attributes, OCR aktif, feedback, export commands, `config/retrieval.php`,
  kebijakan storage, kalibrasi, ablasi, typed contract, instrumentasi.

## 2. Final architecture

```
Foto staff → /select (auto box, GrabCut/OpenCV) → koreksi manual (canvas)
  → /search (crop 0..1) → DINOv2 + SigLIP2 → FAISS shortlist (≤50/SKU cap 3)
  → rerank visual (global/lokal/patch) + sekunder (teks/OCR, <50%)
  → Top-K SKU → konfirmasi staff → search_feedback terverifikasi
  → export-visual (reference memory) / export-training (JSONL offline)
```

Laravel tidak lagi menghitung cosine similarity sebagai primer
(`SKU_SEARCH_DRIVER=faiss`); path legacy (`/embed` + cosine) hidup di
belakang driver `legacy`. FastAPI memuat model sekali saat startup,
inference serial per worker (503 + `Retry-After` bila sibuk).

## 3. Implementation plan yang benar-benar dilakukan

Fase A: Laravel → `POST /search` + `RetrievalClient` + timeout budget.
Fase B: kontrak crop 0..1 + selection_mode + editor manual + selection
reference admin. Fase C: representasi object-centric + indeks dual
object/global + patch DINO + multi-reference max-aggregation. Fase D:
feedback, verified reference expansion, training samples. Fase E:
description parser + atribut + SigLIP teks + OCR sekunder. Fase F:
evaluasi, kalibrasi, kontrak typed, performa, hardening, auditakhir.
Urutan Prompt 01→43 diikuti sekuensial; tiap prompt diuji sebelum lanjut.

## 4. Seluruh file yang diubah

Diubah (49): `.env.example`, `ai-service/.env.example`, `ai-service/README.md`,
`ai-service/app/{config,encoders/dino,encoders/huggingface,features/ocr,main,
preprocessing/{foreground,pipeline},scripts/{build_index,dataset,evaluate},
search/{faiss_index,ranking,service}}`, `ai-service/tests/{test_models,test_retrieval}`,
`app/Http/Controllers/{ObjectSelectionController,ProductController}`,
`app/Imports/ProductsImport.php`, `app/Models/{Product,ProductPhoto}`,
`bootstrap/app.php`, `composer.json`, `config/filesystems.php`,
`package.json`, `resources/{css/app.css,js/app.js,js/object-selection.js}`,
`resources/views/{admin/index,products/{index,search,show}}`,
`routes/{console,web}.php`, `tests/Feature/{AdminUserTest,ProductsImportTest}`,
`app/Services/CropCoordinateValidator.php` (dihapus → `CropCoordinates.php`).

Baru (113, ringkas): `ai-service/app/{features/attributes.py,
preprocessing/{images,selection}.py,schemas.py,scripts/{benchmark_scale,
benchmark_search,benchmark_selection,calibrate,compare}.py}`,
`ai-service/benchmarks/examples/*`, `ai-service/rebuild-index.ps1`,
23 file `ai-service/tests/test_*.py` baru (2 dimodifikasi; daftar lengkap di §26),
`app/Console/Commands/{BuildTrainingDataset,BuildVisualIndex,
CheckVisualSearch,CompressProductImages,ExportTrainingSamples,
ExportVisualReferences,PruneSearchImages,RefreshProductAttributes}.php`,
`app/Exceptions/RetrievalUnavailableException.php`,
`app/Http/Controllers/SearchFeedbackController.php`,
`app/Models/{ProductAttribute,SearchFeedback}.php`,
`app/Services/{CropCoordinates,DescriptionAttributes,DuplicateGuard,
FeedbackPayload,ProductAttributes,ProductImages,ReferenceQuality,
RetrievalClient,RetrievalConfig,SearchEvidence,StoragePaths}.php`,
`config/{product_images,retrieval}.php`, 8 migrasi (§5),
`resources/{js/selection/*,views/components/object-selection,
views/errors/upload-too-large,views/products/selection}.blade.php`,
14 file `tests/Feature/*` + 7 `tests/Unit/*` baru, `tests/js/*`,
`vitest.config.js`, 10 `docs/*.md` tahap awal.

## 5. Migrations

1. `2026_09_15_110000_create_search_feedback_table`
2. `2026_09_15_120000_add_image_metadata_to_product_photos_table`
3. `2026_09_16_000000_index_reference_metadata`
4. `2026_09_16_010000_add_photo_optimization_status`
5. `2026_09_16_020000_enforce_feedback_photo_identity`
6. `2026_09_17_000000_add_feedback_evidence_columns`
7. `2026_09_17_010000_add_training_exported_at`
8. `2026_09_17_020000_create_product_attributes_table`
Semua reversible (down teruji untuk yang berisiko).

## 6. API contract

`POST /search` (multipart, typed `app/schemas.py::SearchResponse`):
request `image`, `top_k` 1..50, `crop_x/y/width/height` 0..1 (keempatnya
atau tidak sama sekali), `selection_mode` auto/manual/full,
`preprocessing_mode`, `category`, `feedback_preview`. Kandidat: rank, sku,
product_id, score + komponen (dino/siglip/local/text/ocr/visual/object/
global), `matched_images/_ids`, family. Metadata: selection_used/mode,
preprocessing + reason, confidence, score_gap, confidence_calibrated,
ambiguity (+reason/alternatives), candidate_images, latency_ms, stage_ms.
Tidak pernah: embedding vectors, absolute path, kredensial. `/embed`
dipertahankan untuk backward compatibility. Laravel `RetrievalClient`
menolak respons bervector dan memvalidasi kontrak.

## 7. Environment variables

Laravel: `SKU_SEARCH_{DRIVER,CONNECT_TIMEOUT,READ_TIMEOUT}`,
`AI_SERVICE_URL`, `AI_SEARCH_MIN_SIMILARITY`, `SEARCH_FEEDBACK_ENABLED`,
`PRODUCT_IMAGE_{DISK,QUALITY,CATALOG_QUALITY,THUMBNAIL_QUALITY,
CATALOG_SIDE,THUMBNAIL_SIDE}`, `TRAINING_IMAGE_DISK`, `QUERY_IMAGE_DISK`,
`REFERENCE_{MIN_BLUR,MIN_SIDE,MIN_CROP_PIXELS,MIN_STD}`,
`REFERENCE_MAX_DHASH_DISTANCE`, `AI_PYTHON_BINARY`, `FAISS_INDEX_PATH`.
ai-service: seluruh 44 field `Settings` terdokumentasi di
`ai-service/.env.example` (model, bobot, patch, sekunder, OCR, ambiguitas,
threshold, resource). Validasi startup dua sisi
(`Settings.__post_init__`, `RetrievalConfig::validate` di boot).

## 8. Dependencies baru

- `league/flysystem-aws-s3-v3` (S3/R2 object storage).
- `vitest` (dev, frontend tests). `laravel/pint` sudah ada (dipakai).
- Python: tidak ada dependensi baru (Tesseract binary eksternal opsional;
  OpenCV sudah ada).

## 9. Auto object selection

`POST /select` → `ObjectSelector`/`ForegroundSelector` (GrabCut, padding
0.08 configurable, ≤5 kandidat + metadata source/score). Gagal → string
reason, pencarian fallback full image/full preprocessing; search tidak
pernah gagal karena seleksi. CPU-only, tanpa model besar.

## 10. Manual selection correction

Editor overlay DOM (bukan canvas): drag, resize via edge/corner grab zones
tak terlihat, tap-to-box; mapping piksel-CSS→ternormalisasi teruji
(21 test geometri + 14 state, deterministik, clamp di semua tepi).
Test usang `tests/frontend/*.mjs` (API lama) dihapus setelah digantikan
vitest. Tombol kandidat/reset/full/padding dan handle bundar dihapus atas
permintaan owner — satu frame auto + adjust langsung; tanpa keyboard
shortcut.

## 11. Image processing/compression

Validasi konten (`decode_image`: format, frame tunggal, pixel/budget
memory, EXIF transpose), flatten alpha, strip EXIF/GPS, WebP master
(kualitas 88, resolusi asli) / catalog 1600px q83 / thumbnail 400px q78 —
semua configurable. AI memakai master (bukan thumbnail). Test: JPEG/PNG/
alpha/EXIF/GPS/korup/oversize/dimensi.

## 12. Storage architecture

Abstraksi filesystem: `local` dev, S3-compatible produksi (R2 didukung
via flysystem). Nama file UUID server-side; master/katalog/thumbnail/
verified/private terpisah visibilitasnya; transien `search-pending`
di-prune terjadwal; cleanup failure-safe; tanpa crop permanen (master +
koordinat cukup). Kredensial hanya via env, `.env` tak ter-commit.

## 13. Object-centric representation

Query object = primer, global = sekunder/fallback; reference menyimpan
keduanya (indeks dual per model). Pasangan campuran object↔full tidak
pernah dibandingkan langsung (fallback global). Bobot configurable,
tercatat di signature; perubahan incompatible wajib rebuild.

## 14. DINO patch/local reranking

`encode_patches`: token spasial tanpa CLS, padding square di-mask,
maks 64 patch, dinormalisasi, gagal aman. Rerank simetris trimmed hanya
untuk shortlist (bulk read ≤50); agregasi configurable; `local_score`
diekspos. Fixed multi-crop dipertahankan sebagai sinyal lokal lain.

## 15. Multiple references

Satu file per foto per folder SKU; agregasi **max** (tanpa average);
cap `candidates_per_sku=3`; `matched_images/_ids` sebagai evidence.
Skor SKU tak naik oleh jumlah foto (teruji).

## 16. Feedback/confirmation

Setelah Top-K: prediksi eksplisit (bukan label), quick-confirm per
kandidat + form koreksi SKU lain, anti-double-submit (client + token
sekali pakai + unique photo_hash), pesan sukses/gagal jelas, expiry
±15 menit. Tidak ada training/FAISS otomatis dari UI ini.

## 17. Confirmed reference expansion

Hanya `verified` + `reference_eligible` (blur/sisi/piksel/std/near-dup)
yang ikut `export-visual --include-verified` (provenance
`confirmed_search`). Disebut memory expansion, bukan training. Revoke/
delete mengeluarkan dari generasi berikutnya (generasi publish immutable).

## 18. Hard-negative/training dataset

`search:export-training` → JSONL (anchor path-reference, positive,
hard_negative bila prediksi≠konfirmasi, provenance, status
pending→verified→exported). `search:build-dataset` → split
train/val/test deterministik anti-leakage + manifest. Tanpa training loop.

## 19. Description evidence

Parser registry Python (`RULES`: drive/measurement/size-bercue/model/
quantity/warna) + mirror PHP untuk penyimpanan; `product_attributes`
table (source, rule, confidence opsional) direfresh idempoten tanpa
menyentuh baris manual; raw description otoritatif. Sekunder saja.

## 20. OCR

Tesseract opsional (default mati), ≤1 invokasi/query, thumbnail 1024px,
timeout 3 dtk, filter confidence, hasil terstruktur
(teks/token/conf/box), engine hilang → graceful. Kompatibilitas OCR
diskala confidence (`confidence_weight`), memakai parse tersimpan
berversi, terekspos `ocr_score` + `ocr_tokens`. Tanpa confusion-correction
(disengaja, terdokumentasi).

## 21. Final ranking

`final = visual` bila sekunder absen, else `(1-sw)*visual + sw*secondary`
(`sw<0.5` divalidasi): visual = DINO+SigLIP (global/lokal/patch/global
fallback), sekunder = teks + OCR ternormalisasi. Semua bobot di env +
signature. Missing signal di-skip. Deterministik, tanpa bonus count.

## 22. Ambiguity/no-match handling

Flag `ambiguous` + reason (`near_tie`/`same_family_variants`) +
alternatif bila ≥2 SKU dalam margin (default 0.05, display-only, bukan
gate). UI meminta staff pilih varian. Confidence heuristic
(high butuh skor + gap) + `confidence_calibrated` hanya `true` di bawah
frozen policy tervalidasi; satu-satunya no-match gate adalah filter
threshold policy. Skor tak pernah disebut probabilitas.

## 23. Performance optimization

`stage_ms` per request (selection/preprocess/encode/faiss/references/
ocr/rerank). Optimasi terukur tanpa ubah semantik: skip matmul lokal
(41.8µs → 5.8µs/panggil, output identik), crop OCR lazy.
Benchmark stub (60 refs/20 queries): baseline median 14.97ms/p95 15.88ms
vs sesudah ~9.0ms — **varians antar-run besar, jadi tidak diklaim
sebagai kemenangan**; porsi encode model asli mendominasi produksi dan
tak tersentuh. Batching encoder, single-load model, shortlist-dulu
dipertahankan.

## 24. Security/privacy

Konten tervalidasi (MIME + decode), bounds bytes/piksel dua sisi, UUID
paths, SKU regex anti-traversal, EXIF/GPS strip, transien ter-prune,
verified privat, log tanpa bytes/filename/embedding/URL sensitif,
timeout bounded tanpa retry inference, `.env` tak ter-commit. Test
`ImageSecurityTest` (termasuk jebakan fake-upload test-mode).

## 25. Evaluation

`evaluate` (Top-1/3/5, MRR@50, median/P95, precision/recall, leakage
hash + capture-group, `dataset_version`, `by_tag`/`by_family`, daftar
`errors`, signature+thresholds), `compare` A–G + `run.json`, `calibrate`
tune/freeze/evaluate, benchmark skala/seleksi/pipeline. **Tidak dijalankan
dengan data produksi** (tidak ada dataset berlabel di repo) — mock smoke
di test. Tanpa klaim akurasi dalam bentuk apapun.

## 26. Test results (aktual, dijalankan)

- Python final: **258 tests OK, tanpa skip** (`ai-service/venv/Scripts/python.exe
  -B -m unittest discover -s tests` dengan `RUN_MODEL_SMOKE=1`,
  `HF_HUB_OFFLINE=1`, `TRANSFORMERS_OFFLINE=1`). Smoke model SigLIP2/DINOv2
  asli di CPU memakai cache lokal; total suite 36,506 detik. Tanpa flag smoke,
  satu test model asli memang dilewati.
- Laravel: **185 passed, 847 assertions** (`php artisan test`).
- Frontend: **35 passed** (`npm test` / vitest).
- Build: **vite ✓ 7.17s** (`npm run build`; warning `fontaine` opsional tidak fatal).
- Runtime readiness: `php artisan search:check` → `search_ready: true`,
  `legacy_embed_ready: true`; `php artisan migrate:status` → semua migration `Ran`.
- Lint: **pint fixed** pada file workstream (15 temuan, termasuk pra-existing
  `User.php` + 4 migrasi lama yang **sengaja tidak diubah**); Python
  `compileall` exit 0 (tanpa ruff/mypy di repo).

## 27. Known limitations

1. GrabCut heuristik; koreksi manual adalah mitigasi.
2. OCR PSM-11 seadanya; Tesseract eksternal opsional.
3. Ambang confidence/ambiguity heuristik sampai kalibrasi data nyata.
4. Single-writer index; concurrency baca snapshot immutable.
5. `photo_hash` menangkap duplikat eksak, bukan near-duplicate semantik.
6. Split dataset grup-level, bukan stratifikasi SKU.
7. Policy beku lama invalid setiap signature direvisi (re-freeze).
8. Threshold `secondary_weight`/fitur sekunder default 0 (konservatif).

## 28. Recommended future fine-tuning work

1. Kumpulkan dataset berlabel held-out + tuning split terpisah.
2. Jalankan `compare` A–G dengan model asli; aktifkan fitur hanya bila
   angka mendukung.
3. Kalibrasi (`tune`→`freeze`) di tuning set; validasi di evaluation set
   yang berbeda.
4. Metric-learning offline (triplet anchor/positive/hard-negative dari
   JSONL) dengan `ImageEncoder` contract; evaluasi sebelum deploy;
   tanpa online training.
5. Backfill 62 produk lama via `products:export-visual` + rebuild
   (prompt terpisah, sesuai instruksi).

## Troubleshooting umum

| Gejala | Penyebab | Tindakan |
|---|---|---|
| `[WinError 10013] ... socket ... access permissions` saat start uvicorn | Port sudah dipegang proses uvicorn lain (termasuk pasangan supervisor+worker dari `--workers`) | `netstat -ano \| findstr :8001`, hentikan PID pemiliknya, start ulang. Jika port benar-benar kosong, cek `netsh int ipv4 show excludedportrange protocol=tcp` |
| `ValueError: Preprocessing changed; rebuild index` saat startup + `/search` 503 | Signature index ≠ kode preprocessing/encoder saat ini — guard kompatibilitas yang memang disengaja | `php artisan products:export-visual <folder-baru> --include-verified`, lalu `.\rebuild-index.ps1 -Dataset <folder-baru>`, lalu restart FastAPI |
| `search_ready: false`, atau `/search` sukses tapi hasil kosong | Index dibangun dari `dataset\` sintetis, atau FastAPI belum di-restart setelah generation baru dipublikasikan | Rebuild dari folder hasil `products:export-visual`; restart proses FastAPI |
| `/select` 200 OK tetapi `/search` 503 | Retrieval gagal init (alasan terlihat di log startup uvicorn) | Perbaiki penyebab init, bukan endpoint selection |

Catatan: `--reload` uvicorn hanya memantau file `*.py`, sehingga publish generation index baru **tidak** memicu reload otomatis — service harus di-restart manual.

## Perintah Windows PowerShell

```powershell
# Laravel dependencies
composer install
# Python dependencies
cd ai-service; .\venv\Scripts\Activate.ps1; pip install -r requirements.txt; cd ..
# Migrate DB
php artisan migrate --force
# Run Laravel
php artisan serve --host=127.0.0.1 --port=8000
# Run FastAPI (terminal lain, venv aktif)
cd ai-service; python -m uvicorn app.main:app --host 127.0.0.1 --port 8001 --workers 1; cd ..
# Build frontend
npm run build
# Python tests
cd ai-service; .\venv\Scripts\python.exe -B -m unittest discover -s tests; cd ..
# Laravel tests
php artisan test
# Frontend tests
npm test
# Export visual references (folder harus baru), lalu rebuild index DARI folder hasil export
php artisan products:export-visual "D:\data\visual" --include-verified
cd ai-service
.\rebuild-index.ps1 -Dataset "D:\data\visual"          # full rebuild (publish atomik)
.\rebuild-index.ps1 -Dataset "D:\data\visual" -Append # append inkremental
# ekuivalen manual: .\venv\Scripts\python.exe -m app.scripts.build_index --dataset "D:\data\visual" --rebuild
cd ..
# PERINGATAN: folder `dataset\` di root repo (dan `ai-service\dataset`) adalah fixture
# sintetis uji/benchmark (SKU 001/002, "Test product 001.."). Jangan pernah dipakai
# untuk index produksi: SKU-nya tidak ada di tabel products sehingga hasil /search kosong.
# Run evaluation (butuh dataset berlabel held-out)
cd ai-service; .\venv\Scripts\python.exe -m app.scripts.evaluate --dataset .\evaluation --output .\evaluation-report.json; cd ..
# Export training dataset
php artisan search:export-training "D:\data\training.jsonl"
php artisan search:build-dataset "D:\data\training.jsonl" "D:\data\lensku-dataset" --seed=42
```

## FINAL READINESS CHECK

| Subsystem | Status | Evidence |
|---|---|---|
| Visual search primer (/search FAISS) | implemented | 185 test Laravel, kontrak typed |
| Auto object selection + fallback | implemented | backend+UI+test |
| Koreksi manual | implemented | editor overlay DOM + 35 test JS |
| Reference selection admin | implemented | edit/update + test |
| Image pipeline/kompresi | implemented | test_images 14 |
| Storage abstraction/privasi | implemented | fake-disk + R2 dep |
| Representasi object-centric | implemented | dual index + test |
| DINO patch rerank | implemented | masking + shortlist-only test |
| Multi-reference anti-bias | implemented | max + cap + test |
| Feedback/konfirmasi UI | implemented | token + anti-duplikat + test |
| Verified reference expansion | implemented | export + revoke + test |
| Hard-negative/dataset | implemented | JSONL + split + manifest + test |
| Description evidence | implemented | parser + tabel + default 0 |
| OCR sekunder | implemented | modular, default mati + test |
| Final rerank | implemented | fusi + signature + test |
| Ambiguity/no-match | implemented | flag + gate policy + test |
| Performa instrumentasi | implemented | stage_ms + benchmark |
| Timeout/failure Laravel | implemented | budget + no-retry + test |
| Security/privacy | implemented | audit + test |
| Kontrak API typed | implemented | schemas + leak scan |
| Konfigurasi/env | implemented | validasi dua sisi + test |
| Evaluasi + ablasi + kalibrasi | implemented | tooling + mock smoke |
| Akurasi produksi terukur | **not implemented** | **blocked**: tanpa dataset berlabel |
| Fine-tuning model | not implemented | by design (offline, nanti) |
| Backfill 62 produk lama | not implemented | prompt terpisah |
| Commit git pekerjaan | not implemented | 49 modifikasi + 113 baru di working tree |
