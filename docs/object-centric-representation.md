# Object-Centric AI Representation (Prompt 09)

Dokumen implementasi perubahan representasi AI menjadi **object-centric** tanpa
menghapus global representation. Tanggal: 2026-09-17.

## Ringkasan Konsep

| Level | Query | Reference | Peran |
|---|---|---|---|
| 1. Selected object | `global` = crop objek (jika box) | `global` = crop objek (jika metadata `crop` ada) | **PRIMARY** — dibandingkan hanya bila kedua sisi punya object |
| 2. Global (full image) | `context` = full image | `context` = full image | **SECONDARY/FALLBACK** — sinyal tambahan yang configurable; satu-satunya sinyal saat pasangan campuran |
| 3. Local/detail | `center/left/right/top/bottom` dari prepared image | sama | Tetap via `model_score` (LOCAL_WEIGHT) |

**Aturan utama:** `object query vs object reference` diprioritaskan. Pasangan
campuran (object vs full, atau full vs object) **tidak pernah dibandingkan
langsung** — keduanya jatuh ke global pair (full vs full) yang selalu tersedia.

## Keputusan Scoring per Pasangan (Query, Reference)

| Query punya object | Reference punya object | Skor per model |
|---|---|---|
| ya | ya | `(1-GLOBAL_WEIGHT) * object_pair + GLOBAL_WEIGHT * context_pair` |
| tidak | tidak | `model_score` full-vs-full (global + local crops, perilaku lama dipertahankan) |
| ya | tidak | `context_pair` saja (cosine `query.context` vs `ref.context`) |
| tidak | ya | `context_pair` saja |

Deteksi object:

- Query: `info['object_representation']` = `is_object_box(box)` — box manual/auto
  yang bukan full-image (`x/y > TOLERANCE` atau `width/height < 1 - TOLERANCE`).
- Reference: `reference_has_object(extra)` dari metadata `extra.crop` yang
  tersimpan di index; full box `(0,0,1,1)` dan crop tidak valid dianggap **tanpa
  object**.

## Field Respons `/search` (berubah)

| Field | Keterangan |
|---|---|
| `object_score` (baru) | Weighted object-pair; `null` saat pasangan campuran/tanpa object |
| `global_score` (baru) | Weighted context-pair; selalu ada |
| `visual_score` | Fusi visual final (object/global sesuai tabel di atas) |
| `score` | `visual_score` + secondary (OCR/text) bila diaktifkan |
| `global_fallback_score` (dihapus) | Diganti `global_score`; tidak ada konsumen di Laravel/JS |

`query.object_representation` (baru) menandai apakah query punya object
representation. `query.selection_used` tetap ada.

## Config Baru (semua configurable via env)

| Env | Default | Validasi | Keterangan |
|---|---|---|---|
| `GLOBAL_WEIGHT` | `0.0` | `0..1` | Bobot global pair dalam fusi saat kedua sisi punya object (0 = object pair murni) |
| `RANK_BIAS` | `60.0` | finite, `>= 0` | Bias reciprocal-rank fusion shortlist per model (menggantikan konstanta hardcoded 60); 0 = klasik `1/(rank+1)` |

Fungsi fusion diekstrak ke `fuse_ranks()` di `app/search/ranking.py` sehingga
dapat diuji langsung dan tidak ada scoring weight yang hardcoded di `search()`.

## Signature & Kompatibilitas Index

- Versi preprocessing signature naik: `selected-object-v2` → **`object-centric-v3`**.
- `REPRESENTATION_CODE` meng-hash `search/service.py`, jadi perubahan file ini
  otomatis mengubah signature.
- Index lama akan **ditolak dengan jelas**: `ValueError: Preprocessing changed;
  rebuild index` saat `RetrievalService` init (mekanisme existing), dan
  `build_index.py` menolak append dengan `Pipeline/model changed; use --rebuild`.
- Tidak ada perubahan format storage (keys `global/context/center/left/right/top/bottom`
  tetap; FAISS HNSW per model tetap di vector `global`).

## Perubahan File

| File | Perubahan |
|---|---|
| `ai-service/app/config.py` | Field `rank_bias` + validasi |
| `ai-service/app/search/ranking.py` | Fungsi `fuse_ranks()` |
| `ai-service/app/search/service.py` | Helper `is_object_box`/`reference_has_object`; scoring tiga cabang; shortlist pakai `fuse_ranks`; field `object_representation`, `object_score`, `global_score`; version `object-centric-v3` |
| `ai-service/app/scripts/build_index.py` | Saat auto proposal benar-benar memilih region pada reference, `crop` + `selection_source=auto` direkam ke `extra` agar metadata jujur terhadap representasi tersimpan |
| `ai-service/.env.example` | Dokumentasi `GLOBAL_WEIGHT` + `RANK_BIAS` |
| `ai-service/tests/test_object_centric.py` | 9 test baru |

## Tests (9 baru, total suite 82 OK)

- `test_object_pair_is_primary_and_matches_manual_scores` — konsistensi query/reference:
  object score sama dengan perhitungan manual `model_score` + `weighted_score`.
- `test_global_blend_weight_is_configurable` — `visual = .5*object + .5*global`
  saat `GLOBAL_WEIGHT=.5`.
- `test_object_query_vs_full_reference_never_compares_directly` — missing crop:
  object query vs reference tanpa crop → `object_score` null, skor = global pair
  (gambar identik → 1.0; pola lama cropped-vs-full akan jauh di bawah 1.0).
- `test_full_query_vs_object_reference_falls_back_to_global` — query tanpa object
  vs reference ber-object → fallback global.
- `test_reference_object_detection_rules` — aturan deteksi crop (full box, invalid,
  legacy).
- `test_embed_reports_object_representation_flag` — flag pada info embed.
- `test_signature_version_mismatch_fails_clearly` — index dengan version lama
  ditolak `rebuild index`; version aktif `object-centric-v3`.
- `test_rank_fusion_is_configurable_and_validated` — matematika `fuse_ranks` +
  validasi `RANK_BIAS` (negatif/NaN ditolak, 0 diizinkan).

## Cara Menjalankan Tests

```sh
cd ai-service
./venv/Scripts/python.exe -m unittest discover -s tests -q
# Laravel (regresi kontrak API):
/c/Users/satri/.config/herd/bin/php83/php.exe artisan test
```

## Panduan Verifikasi Manual

1. Jalankan ai-service dengan index yang dibangun **sebelum** perubahan ini →
   `/health` menampilkan error init dan `search_ready=false`; log berisi
   `Preprocessing changed; rebuild index` (fail clearly, bukan silent).
2. `python -m app.scripts.build_index --dataset dataset --rebuild` → build
   generation baru dengan version yang berlaku. (Catatan historis: `dataset`
   di sini adalah fixture lokal saat fase itu; untuk katalog nyata gunakan
   folder hasil `php artisan products:export-visual`.)
3. Restart service → `/health` `search_ready=true`.
4. `POST /search` dengan `selection_mode=auto` pada foto ber-object → respons
   berisi `object_score` (bukan null) dan `query.object_representation=true`.
5. `POST /search` dengan `selection_mode=full` → `object_score` null,
   `global_score` terisi.
6. Uji pasangan campuran: query full vs reference ber-crop (atau sebaliknya) →
   `object_score` null; skor diambil dari global pair.

## Catatan / Batasan

- Shortlist FAISS tetap memakai vector `global` per model; pemisahan object/global
  terjadi di reranking (tanpa perubahan storage — sesuai batasan prompt).
- Patch reranking (DINO patch tokens) **tidak disentuh** pada prompt ini; tetap
  di belakang `PATCH_WEIGHT=0` default.
- Default `GLOBAL_WEIGHT=0.0` mempertahankan perilaku object-pair murni; operator
  dapat menyalakan sinyal global sebagai additional signal tanpa rebuild index
  (bukan bagian signature).
