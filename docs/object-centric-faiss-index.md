# Object-Centric FAISS Index (Prompt 10)

Dokumen implementasi FAISS index object-centric. Tanggal: 2026-09-17.
Melanjutkan Prompt 09 (`docs/object-centric-representation.md`) — scoring sudah
object-centric, prompt ini memindahkan struktur index agar mendukungnya secara
native.

## Format Index Existing → Baru

**Sebelum (manifest v1):** satu FAISS index per model, vector `global` campuran
(object crop bila ada, full image bila tidak) + SQLite untuk reranking.

**Sesudah (manifest v2):** dua FAISS index per model:

| File per model | Isi | Vector |
|---|---|---|
| `{model}.object.faiss` | Hanya reference dengan crop objek nyata | `global` (selected-object) |
| `{model}.global.faiss` | **Semua** reference | `context` (full image) |

- Reference utama (object index) berasal dari **selected object representation**.
- Global representation dipertahankan sebagai fallback/context di index kedua.
- SQLite `vectors` tidak berubah (semua vector global/local tersimpan untuk reranking).
- ID baris FAISS = reference ID di dalam masing-masing (model, representation).

## Metadata Index (menghubungkan vector ke data)

Tabel `refs` kini eksplisit:

| Kolom | Keterangan |
|---|---|
| `id` / `image_id` | reference image |
| `product_id` | product |
| `sku` | SKU |
| `representation` (baru) | `object` atau `full` — tipe representasi utama reference |
| `selection_source` (baru) | provenance: `auto` / `manual` / `full` / null |
| `photo_hash`, `category`, `sub_category`, `family`, `extra` | tetap |

`extra` JSON tetap menyimpan `crop` dan detail lain. Manifest v2 mencatat
`count` (total refs), `object_count`, `dimensions` per model per representation,
dan checksum SHA-256 semua file.

## Anti-Count-Bias

1. **Shortlist cap per SKU (baru):** setelah rank fusion, tiap SKU paling banyak
   menyumbang `CANDIDATES_PER_SKU` (default 3) kandidat — SKU dengan banyak foto
   tidak boleh membanjiri candidate pool.
2. **Agregasi max-score (existing, dipertahankan):** `aggregate_skus` mengambil
   skor terbaik per SKU, bukan rata-rata — SKU tidak diuntungkan oleh jumlah foto.
3. **Cap saat build:** `--reference-limit-per-sku` tersedia untuk ablasi.

## Shortlist Search (service.search)

- Query punya object → object-index shortlist (PRIMARY, bobot
  `OBJECT_INDEX_WEIGHT` × model weight) + global-index shortlist (bobot
  `(1-OBJECT_INDEX_WEIGHT)` × model weight).
- Query tanpa object → hanya global-index shortlist (object-vs-full tidak pernah
  dibandingkan di tahap ini, konsisten dengan Prompt 09).
- Fusion: reciprocal-rank configurable (`RANK_BIAS`), lalu cap per SKU.

Config baru (semua via env): `OBJECT_INDEX_WEIGHT` (0..1, default 0.8),
`CANDIDATES_PER_SKU` (1..50, default 3).

## Publishing: Atomic & Failure-Safe

Alur build (tetap + diperkuat):

1. `build.lock` mencegah publikasi konkuren.
2. Build di temp workspace; database lama di-backup; append inkremental
   menolak perubahan metadata/pipeline.
3. `save_index()` menulis generation baru (SQLite backup + semua file FAISS +
   manifest dengan checksum).
4. **Baru:** generation diverifikasi penuh via `load_index()` (checksum, jumlah,
   dimensi, metric) **sebelum** pointer `CURRENT` di-flip. Bila verifikasi gagal,
   directory generation yang korup **dihapus** dan build gagal.
5. Flip pointer via `CURRENT.tmp` + `os.replace` (atomic pada filesystem yang sama).
6. Build gagal → `CURRENT` tidak tersentuh → **index valid existing tetap usable**.
   Generation lama tidak pernah dihapus otomatis (rollback manual memungkinkan).

Kompatibilitas: manifest v1 ditolak saat load dengan
`Unsupported index version; rebuild the index`; saat append, error dibungkus
`...; use --rebuild`. Tidak ada silent read index incompatible.

## Perubahan File

| File | Perubahan |
|---|---|
| `ai-service/app/search/faiss_index.py` | Dual index per model, kolom `representation`/`selection_source`, `skus()`, manifest v2 + validasi ketat, `search(..., representation=...)` |
| `ai-service/app/search/service.py` | Shortlist object/global + fusion weight + per-SKU cap; signature `object-centric-v4`; evaluation signature diperluas |
| `ai-service/app/config.py` | `object_index_weight`, `candidates_per_sku` + validasi |
| `ai-service/app/scripts/build_index.py` | `representation`/`selection_source` dari box final (termasuk auto proposal), verify-before-publish + cleanup, CLI `--reference-limit-per-sku`, wrap error append |
| `ai-service/rebuild-index.ps1` | Command PowerShell rebuild Windows |
| `ai-service/.env.example` | Dokumentasi env baru |
| `ai-service/tests/test_object_index.py` | 8 test baru |

Tidak ada perubahan sisi Laravel — `ExportVisualReferences` sudah menulis
`crop` + `selection_source` di sidecar.

## Command PowerShell (Windows)

```powershell
# Rebuild dari dataset hasil export Laravel (bukan fixture sintetis `dataset\`):
php artisan products:export-visual "D:\data\lensku-dataset" --include-verified
cd ai-service
.\rebuild-index.ps1 -Dataset "D:\data\lensku-dataset"   # full rebuild (publish atomik)
.\rebuild-index.ps1 -Dataset "D:\data\lensku-dataset" -Append   # append inkremental
.\rebuild-index.ps1 -ReferenceLimitPerSku 1             # ablasi one-per-SKU
.\rebuild-index.ps1                                     # default 'dataset' = fixture sintetis; jangan untuk produksi
```

Script memakai `venv\Scripts\python.exe` (fallback `python`), meneruskan exit
code, dan menampilkan pesan bahwa index lama tetap dipakai bila build gagal.

## Tests

- **ai-service: 91 OK** (1 skipped). 8 test baru di `tests/test_object_index.py`:
  - `test_object_and_global_index_membership` — object index hanya berisi ref ber-object; global index berisi semua.
  - `test_metadata_connects_vectors_to_product_sku_image_and_representation` — konektivitas metadata (product/SKU/image/representation/provenance).
  - `test_manifest_v2_roundtrip_and_tamper_detection` — layout file v2, count per representation, deteksi korupsi.
  - `test_per_sku_shortlist_cap_prevents_sku_flooding` — SKU 6 foto hanya menyumbang 3 kandidat; SKU lain tetap masuk.
  - `test_corrupt_generation_is_never_published` — verifikasi gagal → `CURRENT` tidak dibuat, generation korup dihapus.
  - `test_failed_publish_keeps_existing_index_usable` — rebuild gagal → generation lama tetap ter-load.
  - `test_old_index_version_rejected_clearly` — manifest v1 ditolak eksplisit.
  - `test_smoke_build_object_and_full_references` — **small smoke build**: dataset 2 reference (object sidecar + full sidecar) → build → verify → load → search mengembalikan SKU yang benar.
- **Laravel: 94 passed (362 assertions)** — regresi kontrak API aman.

Menjalankan tests:

```sh
cd ai-service
./venv/Scripts/python.exe -m unittest discover -s tests -q
/c/Users/satri/.config/herd/bin/php83/php.exe artisan test   # dari root repo
```

## Catatan / Batasan

- Fixture dataset nyata untuk smoke build CLI tidak tersedia di repo
  (`ai-service/dataset` tidak ada) — smoke build dijalankan lewat unittest dengan
  MockEncoder (tanpa download model).
- `OBJECT_INDEX_WEIGHT=1` menonaktifkan kontribusi global shortlist saat query
  ber-object — refs tanpa crop tetap ditemukan saat query full image, tapi
  berisiko tidak ditemukan dari query object; dokumentasi di `.env.example`.
- Incremental feedback references **tidak** diimplementasikan (sesuai batasan prompt).
- Rerank tiga-cabang Prompt 09 tidak berubah; patch reranking tetap nonaktif default.
