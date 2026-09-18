# Baseline Architecture Audit

**Tanggal audit:** 2026-09-16
**Cakupan:** Seluruh repository `barcode-finder` (Laravel + ai-service FastAPI), working tree aktual (bukan hanya git HEAD).
**Sifat:** Read-only. Tidak ada perubahan kode dilakukan selama audit.

> Ringkasan: baseline commit `b80099e` + commit Phase 1 `a7cdf95` (committed) + badan besar pekerjaan uncommitted yang mengimplementasikan Fase 2–8 spesifikasi (object selection lengkap, image preparation, FAISS retrieval client, search feedback, index build, kalibrasi). Semua test suite yang tersedia dijalankan dan lulus.

---

## A. Current Architecture

```
┌─ Laravel 13.30 (PHP 8.3, Herd) ─────────────────────────────┐
│  routes/web.php + console.php                               │
│  Controllers: Product, ObjectSelection, SearchFeedback,     │
│               Auth, AdminUser                               │
│  Services: RetrievalClient, SearchEvidence, ProductImages,  │
│            CropCoordinates, CropCoordinateValidator(dead)   │
│  Console: 6 commands (build index, compress, prune, export) │
│  Views: Blade + Tailwind 4 + vanilla JS (no framework)      │
│  Storage: local public/private disk, s3 tersedia (flysystem)│
└───────────────┬─────────────────────────────────────────────┘
                │ HTTP multipart (config: services.ai.url)
┌─ ai-service FastAPI (Python, CPU-only) ─────────────────────┐
│  main.py: /health /search /select /prepare /embed           │
│  Encoders: HuggingFaceEncoder (SigLIP2-base 768d),          │
│            DinoEncoder (DINOv2-small cls 384d + patches)    │
│  Preprocessing: pipeline, selection (GrabCut), foreground,  │
│                 crop (multi-crop), images (WebP variants)   │
│  Features: OCR (Tesseract, optional), attributes (regex)    │
│  Search: FAISS HNSW shortlist → weighted rerank → kalibrasi │
└─────────────────────────────────────────────────────────────┘
```

**Dua driver retrieval berdampingan:**
- `legacy` (default di `config/retrieval.php`): Laravel memanggil `/embed`, cosine similarity via pgvector `<=>` (atau in-PHP fallback untuk non-pgsql). Path asli dari b80099e.
- `faiss` (opt-in): Laravel → `RetrievalClient::search()` → `/search` ai-service → FAISS HNSW shortlist + rerank + kalibrasi. Path baru, belum default.

---

## B. Laravel Search Flow

**Driver faiss** (`ProductController::search`, ProductController.php:211-262):
1. Validasi image (jpg/jpeg/png/webp, max 10 MB) + crop opsional (normalized, divalidasi `CropCoordinates`).
2. `RetrievalClient::search()` POST `/search` ai-service dengan `top_k`, `preprocessing_mode`, `crop_x/y/width/height` (timeout 25s, tanpa retry).
3. Jika feedback diaktifkan + user consent → `SearchEvidence::stage()` menyimpan query master ke disk private, cache 15 menit, token UUID ke view.
4. Hasil di-hydrate: SKU → Products, `image_id` → photo path, similarity → view `products/search` dengan banner confidence + form konfirmasi/koreksi SKU (search.blade.php:24-34).
5. Gagal FAISS → error friendly, **TANPA fallback ke legacy** (dites: `faiss failure does not fall back to embedding search`).

**Driver legacy** (default, ProductController.php:264-324):
1. `createEmbedding()` POST `/embed` (timeout 45s, retry 2x) → vector.
2. `findSimilarProducts()`: pgsql → cosine `<=>` threshold 0.72 limit 12; selain pgsql → in-PHP `cosineSimilarity()` atas string koma. Path in-PHP tetap ada untuk backward compatibility SQLite.

**Alur upload (sebelum search) pada halaman katalog:** `resources/js/app.js` meng-interupsi form upload, mengirim SATU foto per request secara berurutan, dengan editor object-selection per foto (widget `components/object-selection.blade.php`).

---

## C. Product Upload Flow

`ProductController::uploadPhoto` (ProductController.php:142-200):
1. Validasi: hingga 10 images + `crop_coordinates[]` JSON + `selection_source`.
2. Per foto:
   - `CropCoordinates::validate()` setiap crop (pre-flight rejection sebelum proses berat).
   - Jika driver `faiss` → **skip embedding** (representasi dibuat saat index build). Jika legacy → `createEmbedding()` ke `/embed`.
   - `ProductImages::store()` → POST `/prepare` ai-service → terima master/catalog/thumbnail base64 WebP → tulis ke disk (`config/product_images.php`: disk `public`, quality 88, catalog 1600px, thumb 400px).
   - `ProductPhoto::create()` dengan crop, selection_source, selection_verified, photo_hash, optimization_status.
   - Gagal di tengah → file yang sudah ditulis di-discard, response 503 JSON atau back-with-errors.
3. Katalog tetap menampilkan FULL IMAGE; crop hanya metadata untuk AI indexing (sesuai spesifikasi Fase 2).

**Edit crop foto existing:** `ObjectSelectionController::edit/update/image` + view `products/selection.blade.php`. Edit crop menandai `index_status=pending` — serving snapshot lama tidak berubah sampai rebuild.

---

## D. Database Schema Relevant

**DB aktif:** PostgreSQL `barcodeidentify` (pgsql). Migration batch tercampur (lihat Temuan).

| Tabel | Kolom penting |
|---|---|
| `products` (10.396 rows) | sku, description, `photo`+`embedding` (LEGACY, pgvector) |
| `product_photos` (19 rows) | path, embedding (pgvector), `crop` json, selection_source, selection_verified, `disk`, `master_path`, `thumbnail_path`, `source`, `photo_hash`, `index_status`, `optimization_status`, storage_optimized_at, storage_optimization |
| `search_feedback` | user_id, confirmed_product_id, predicted_sku, confirmed_sku, query_image_path, photo_hash, dhash, crop, evidence json, training_status, reference_eligible |
| `product_photo_features` | **ORPHAN** — tidak direferensikan kode mana pun |
| `visual_references` | **ORPHAN** — tidak direferensikan kode mana pun |
| users, sessions, cache, jobs, dll | standar Laravel |

Data aktual: 2 dari 19 foto punya crop, 1 punya master_path (pipeline baru baru dipakai sekali), FAISS index hanya berisi 4 reference.

**File migration di working tree (12):** users/cache/jobs, products, users.role, product_photos, crop_coordinates (committed), search_feedback, image_metadata, index_reference_metadata, photo_optimization_status, feedback_photo_identity.

---

## E. AI Search Pipeline

`ai-service/app/search/service.py`:
1. **Embed** (service.py:65): proposal box (object mode) → `Preprocessor.prepare` crop → `multi_crop` 6 crop (global/center/left/right/top/bottom) + context → dedupe via `photo_hash` → per-encoder encode → optional SigLIP description + DINO patches.
2. **Search** (service.py:107): per-model HNSW shortlist `candidates=50` → **reciprocal-rank fusion** `weight/(60+rank+1)` → top-50 kandidat.
3. **Rerank per kandidat:**
   - `model_score` = (1−local_weight)·global + local_weight·symmetric best-crop similarity (ranking.py:5-12, default local_weight=0.25)
   - context blend `global_weight` (default 0.0)
   - DINO patch score `patch_similarity` (default 0.0)
   - OCR sekali per query, hanya jika kandidat ada dan `secondary_weight>0`
   - description text similarity jika `description_text_weight>0`
   - final = (1−secondary_weight)·visual + secondary_weight·secondary — **visual tetap dominan** (sesuai spesifikasi Fase 12)
4. `aggregate_skus` (max per-SKU, anti-count-bias), `confidence` (high_score 0.85 / high_gap 0.08 / medium 0.70).

**Bobot model:** siglip 0.35 / dino 0.65 (config.py:58-60). Semua bobot configurable via env, tercatat di evaluation signature.

**API surface (main.py):** `GET /health`, `POST /search`, `POST /select` (boxes normalized + reason), `POST /prepare` (WebP master/catalog/thumbnail + photo_hash), `POST /embed` (legacy single-vector + multi representation). Single non-blocking Lock → 503 "busy" jika ada request berjalan. Model load sekali saat startup (lifespan), tanpa reload per request — sesuai spesifikasi Fase 15.

---

## F. FAISS Architecture

`ai-service/app/search/faiss_index.py`:
- Per-generasi: `dino.faiss` + `siglip.faiss` (IndexHNSWFlat, M=32, efConstruction=200, INNER_PRODUCT), `metadata.sqlite` (refs + vectors), `manifest.json` v1.
- Manifest: SHA-256 per file, **signature** = identitas model (model+revision+pooling+processor+transformers version) + preprocessing version `selected-object-v2` + source-code hashes + dims + count.
- Pointer `CURRENT` diganti atomik; build gagal tidak memindahkan CURRENT.
- Load verifikasi: version, file set, checksum, counts, dims, metric, `PRAGMA query_only=ON`.
- Live index di disk: `generation-4a88fa46…` — siglip 768d, dino 384d, patches/description OFF, **count=4**.
- Build: `scripts/build_index.py` incremental per-generasi dengan build.lock, `--rebuild`, sidecar JSON.

---

## G. Image/Storage Architecture

**Laravel side:**
- Disk: `public` (storage/app/public, URL `/storage`) + `private` (storage/app/private, serve=true) + `s3` siap (flysystem-aws-s3-v3 terinstall). `FILESYSTEM_DISK` via env.
- `config/product_images.php`: disk configurable, WebP quality 88, catalog max 1600px, thumb 400px.
- Feedback images: disk private, path `verified-search/`, cache 15 menit, prune `search:prune-pending` tiap 10 menit (console.php schedule).
- Foto query sementara (evidence) dibersihkan otomatis jika gagal.

**ai-service side** (`preprocessing/images.py`):
- `prepare_upload`: validasi konten, EXIF orientation, strip metadata privat (GPS), RGB conversion, WebP master pada dimensi asli (lossy, quality configurable), catalog ≤1600px, thumb ≤400px.
- Batas resource: MAX_BYTES 20MB, MAX_PIXELS 40MP, MAX_SIDE 1024, decode memory 256MB. Over-limit → preserve original, status `preserved_pixel_limit` / `preserved_memory_budget` (tanpa decode GD full-size) — AI master tidak pernah diganti varian display.
- `photo_hash` untuk dedupe; `verified_candidate` (dhash/blur) untuk gate reference expansion.

**Temporary files:** temp dir per panggilan OCR; evidence cache ber-ttl; build lock file. Ditemukan di storage/app/private: backup dump, benchmark artifacts (`benchmark-20260915`, `scale-100k-20260916.json`), `ui-smoke.png` — sisa uji lokal, bukan alur produksi.

---

## H. Evaluation Architecture

`ai-service/app/scripts/`:
- `evaluate.py` — held-out evaluation, leakage detection (query vs reference), top-1/3/5, MRR, P/R@5, tags.
- `calibrate.py` — tune/freeze/evaluate threshold policy; frozen policy terikat ke `evaluation_signature` (tidak bisa diaplikasikan ke pipeline berbeda).
- `compare.py` — ablation runner skenario A–G dengan index terisolasi.
- `benchmark_scale.py` — synthetic scale test (FAISS+SQLite only, tanpa model).
- `benchmarks/labelled-evidence.json` — historical only, query images/hashes hilang; **bukan tuning dataset** (contoh synthetic).

**Dataset:** `scripts/dataset.py` — `iter_images` (folder per SKU), `read_photo`, `load_metadata`. Laravel side: `search:export-training` (JSONL anchor/positive/hard_negative dari feedback terverifikasi — Fase 9), `search:build-index --include-verified` (retrieval memory expansion, Fase 8; bukan training model).

---

## I. Existing Tests

| Suite | File | Hasil aktual (dijalankan 2026-09-16) |
|---|---|---|
| Laravel Feature | tests/Feature/* (AdminUserTest, ProductsImportTest, RetrievalIntegrationTest, VisualWorkflowTest, ExampleTest) | **35 passed (152 assertions)** |
| Python | ai-service/tests/* (test_retrieval 20, test_calibration 7, test_object_retrieval 7, test_selection 6, test_workflow 4, test_models smoke) | **43 passed, 1 skipped** (skip = real-model smoke opt-in `RUN_MODEL_SMOKE=1`) |
| Frontend node | tests/frontend/object-selection.test.mjs | **3 passed** (node --test) |

Cakupan Laravel: RBAC, upload/delete photo, duplikat SKU, import Excel, multi-crop upload, invalid crop pre-flight, feedback off, FAISS no-fallback, evidence cleanup, compress dry-run, prune retention, crop forwarding/edit, private disk variants.
Cakupan Python: API contract, FAISS generation build, crop/rerank bounds, threshold sweep/leakage, selection fallback, patches, OCR, prepare API, latency.

---

## J. Technical Debt / Risiko

| # | Masalah | Lokasi | Penyebab | Severity | Perlu diperbaiki sebelum fase berikutnya? |
|---|---|---|---|---|---|
| 1 | **6 migration di DB tanpa file** (batches 3–4): `2026_09_11_031553`, `_031554`, `_072456`, `_073004`, `_112733`, `2026_09_12_120000` | database/migrations/ (file hilang dari main) | History surgery: file ada di commit lama (04337cb, f4faf44) dan 3 commit yang di-reset (4582edc/d92898a/4f8dddd, masih di reflog, 1 dangling). DB lokal sudah mengaplikasikannya | **HIGH** | Ya — deploy fresh/produksi tidak akan membuat tabel `product_photo_features`, `visual_references`, kolom storage_optimization, dan index HNSW. File recoverable dari commit dangling jika dibutuhkan |
| 2 | **Tabel orphan di DB**: `product_photo_features`, `visual_references` (dengan kolom pgvector) — 0 referensi kode | PostgreSQL | Sisa era penyimpanan feature pgvector sebelum FAISS | MEDIUM | Tidak blocking; dokumentasikan/drop saat cleanup terjadwal |
| 3 | `CropCoordinateValidator` (app/Services/CropCoordinateValidator.php:8) — dead code, "compatibility facade" 0 penggunaan; duplikat `CropCoordinates` | grep 0 usages | Sisa Phase 1 commit saya, digantikan `CropCoordinates` di uncommitted work | LOW | Ya, hapus sebelum fase berikutnya |
| 4 | **Perubahan uncommitted besar** (~1.100 baris + 24 file baru Laravel + ai-service) belum di-commit | working tree | Reset ke b80099e lalu implementasi ulang Fase 2–8; user belum commit | **HIGH** | Ya — risiko kehilangan kerja. Prioritas tertinggi untuk di-commit |
| 5 | Driver default masih `legacy`; pgvector & path cosine in-PHP masih aktif (ProductController.php:264-324, Product::$fillable photo/embedding) | config/retrieval.php:4-5, .env.example | Kompatibilitas mundur yang disengaja | LOW | Tidak blocking; retire setelah FAISS tervalidasi |
| 6 | Live AI service DEGRADED: `/health` → models: [], search_ready: false; `/search` → "Retrieval models unavailable"; `/select` OK ("foreground_uncertain" untuk foto uji) | proses uvicorn berjalan di 127.0.0.1:8001 | Proses lama di-start sebelum state/kode saat ini; encoder gagal init di proses itu | MEDIUM | Ya untuk uji end-to-end — restart service setelah rebuild index |
| 7 | FAISS live index hanya 4 reference | ai-service/indexes/generation-4a88fa46 (count=4) | Dataset export + build hanya dijalankan sekali untuk smoke test | MEDIUM | Ya sebelum evaluasi akurasi nyata |
| 8 | `service.py:160` field respons `local_score` sebenarnya berisi patch_score (misnomer); `global_fallback_score` selalu dihitung walau `global_weight=0` (service.py:137) | ai-service | Iterasi cepat | LOW | Kosmetik/perf kecil; boleh nanti |
| 9 | `main.py:169` `confidence_calibrated` hardcoded False; sinyal asli `relevance_calibrated` | ai-service | Kalibrasi belum diaktifkan (sesuai batas review) | LOW | Tidak blocking; aktivasi butuh evaluasi |
| 10 | `requirements-dev.txt` tidak memuat pytest — test Python tidak bisa jalan dari fresh venv | ai-service/requirements-dev.txt | Kelalaian deklarasi dev dependency | LOW | Ya, tambahkan pytest (saya install manual untuk audit ini) |
| 11 | `ProductPhoto::imageUrl` pakai `temporaryUrl()` untuk disk non-public; local disk tidak implement temporary URL | app/Models/ProductPhoto.php:47-53 | Belum diuji untuk PRODUCT_IMAGE_DISK=local | LOW | Verifikasi sebelum pindah disk |
| 12 | Upload menerima DUA kontrak crop paralel: `crop_coordinates[]` dan `crops` (ProductController.php:156) | app/Http/Controllers/ProductController.php | Redundansi antar iterasi | LOW | Satukan ke satu kontrak |
| 13 | `.env.example`/`.gitignore` tampil "modified" di git status tapi `git diff HEAD` kosong | working tree | Line-ending churn (CRLF warning) | INFO | Tidak perlu tindakan |
| 14 | `welcome.blade.php` tanpa route; benchmark artifacts & dump files di storage/app/private | resources/views/welcome.blade.php, storage/app/private/ | Sisa snapshot lama | INFO | Cleanup opsional |

**Tidak ditemukan:** TODO/FIXME di kode; hardcoded path mesin (hanya default relatif); model reload per request (model di-load sekali saat lifespan).

---

## K. Existing Functionality yang Harus Dipertahankan

1. **Driver legacy retrieval** — tetap default dan berfungsi (dites; fallback in-PHP untuk non-pgsql).
2. **Katalog publik** — index/search produk per SKU/deskripsi + slider multi-foto.
3. **RBAC admin** — guest/admin/super_admin middleware, user management + import XLSX template.
4. **Import Excel katalog** — preserve SKU lama, update deskripsi terbaru.
5. **Upload foto reference multi-file** — satu request per foto, partial completion, katalog full-image.
6. **Object selection editor** — drag/resize/8-handle/padding/reset, proposal auto via `/select`, fallback aman ke full image (search tidak pernah gagal karena detector gagal).
7. **Image preparation** — WebP master/catalog/thumbnail, EXIF orientation, strip GPS, guard pixel/memory.
8. **Search feedback loop** — opt-in, evidence private + TTL, konfirmasi/koreksi, hard-negative, dedupe dhash, export training JSONL (bukan online training).
9. **FAISS generation build** — incremental, atomic CURRENT, signature verification, `--include-verified`.
10. **Kalibrasi & evaluasi** — held-out evaluation, leakage detection, frozen policy terikat signature.
11. **Legacy `/embed` API** — backward compatibility (ENABLE_LEGACY_EMBED).
12. **Legacy DB kolom** — `products.photo/embedding`, `product_photos.embedding` pgvector tetap dipertahankan.

---

## L. Recommended Implementation Order

1. **Commit working tree** (Temuan #4) — bagi per topik: (a) ai-service search/calibration, (b) Laravel retrieval client + config, (c) feedback + migrations, (d) image preparation + commands. Ini bukan implementasi baru, hanya mengamankan pekerjaan.
2. **Konsolidasi migration** (Temuan #1) — putuskan: restore file lama dari reflog, atau dokumentasikan bahwa tabel orphan tidak diperlukan di deploy baru (migration `_120000` menggantikan `_100000`).
3. **Restart + rebuild live AI service** (Temuan #6, #7) — rebuild index dari dataset ekspor penuh, verifikasi `/health` ready.
4. **Hapus dead code** (Temuan #3, #14) — CropCoordinateValidator, welcome.blade.php; satukan kontrak crop (#12).
5. **Aktivasi FAISS bertahap** — `RETRIEVAL_DRIVER=faiss` di staging, validasi akurasi vs legacy, baru pindah produksi.
6. **Aktivasi kalibrasi** — recalibrate policy terhadap pipeline final, baru set `relevance_policy` (Temuan #9).
7. **Skala 20.000+ SKU** — benchmark_scale + evaluasi pada index penuh sebelum klaim akurasi.

---

## Baseline Verification (hasil aktual)

| Cek | Perintah | Hasil |
|---|---|---|
| Laravel tests | `php artisan test` | ✅ **35 passed (152 assertions)**, 7.72s |
| Python tests | `python -m pytest tests/ -q` | ✅ **43 passed, 1 skipped**, 8.27s (skip = model smoke opt-in). *pytest tidak ada di requirements-dev.txt — saya install manual ke venv untuk menjalankan audit ini* |
| Frontend tests | `node --test tests/frontend/object-selection.test.mjs` | ✅ **3 passed** |
| Build assets | `npm run build` | ✅ Sukses (warning opsional `fontaine`) |
| Live `/select` | POST foto produk nyata | ✅ 200 `{"boxes":[],"reason":"foreground_uncertain"}` — endpoint hidup, GrabCut abstain pada foto uji |
| Live `/search` | POST foto produk nyata | ❌ `{"detail":"Retrieval models unavailable; inspect /health"}` — proses uvicorn lama degraded, bukan kegagalan kode |
| Live `/health` | GET | ⚠️ `degraded`, models: [], references: 0, legacy_embed_ready: true |
| FAISS index on disk | indexes/generation-4a88fa46 | ✅ Manifest v1 valid, signature lengkap, count=4 |

**Kesesuaian working tree dengan prompt audit:** Semua poin inspeksi (1–15) sudah terpenuhi dan didokumentasikan. Perubahan yang "belum sesuai" adalah temuan #1–#14 di atas — sesuai instruksi prompt, belum saya perbaiki (menunggu otorisasi). Tidak ada fitur baru yang diimplementasikan selama audit.
