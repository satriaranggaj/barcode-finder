# DINO Patch-Level Reranking (Prompt 12)

Dokumen implementasi DINO patch/local reranking untuk shortlist FAISS.
Tanggal: 2026-09-17. Melanjutkan Prompt 11 (`docs/dino-patch-tokens.md`) —
Prompt 11 menyediakan ekstraksi patch + mask; prompt ini memakai patch tersebut
untuk reranking. Prompt 11 belum mengubah ranking; prompt ini mengubahnya
(melalui `PATCH_WEIGHT > 0`, default tetap 0 = nonaktif).

## Flow Retrieval

```
global/object FAISS retrieval (HNSW/IP, per model)
 -> rank fusion shortlist (bounded: CANDIDATES + CANDIDATES_PER_SKU per SKU)
 -> load local features HANYA untuk shortlist (bulk, 2 query SQLite)
 -> patch correspondence query vs reference (per kandidat)
 -> local similarity -> rerank skor dino
 -> aggregate per SKU (max) -> top_k
```

Patch matching **tidak pernah** menyentuh seluruh 20.000+ SKU — hanya maksimum
`CANDIDATES` kandidat hasil FAISS (default 50, dibatasi per-SKU).

## Symmetric Local Similarity (`app/search/ranking.py`)

`patch_similarity(query, reference, aggregation, top_k, trim, query_mask, reference_mask)`:

1. **Cross-similarity matrix** `S = query @ referenceᵀ` (kedua sisi L2-normalized;
   patchnya sudah content-masked sejak Prompt 11).
2. **Best correspondence per arah**: `q2r = S.max(axis=1)` (tiap query patch
   mencari match terbaiknya) dan `r2q = S.max(axis=0)` — bukan satu pemenang
   global.
3. **Aggregation configurable per arah**, lalu **symmetric mean**:
   `symmetric = clip((agg(q2r) + agg(r2q)) / 2, -1, 1)`.

| Aggregation | Perilaku |
|---|---|
| `top_k` (default) | Mean dari `k = min(PATCH_TOP_K, |query|, |reference|)` korespondensi terbaik tiap arah |
| `median` | Median per arah |
| `trimmed_mean` | Mean setelah memangkas `PATCH_TRIM` fraksi tiap ujung (sorted) |
| `mean` | Mean semua korespondensi (baseline rapuh, tersedia untuk ablasi) |

Sifat yang dijamin (dan diuji):

- **Simetri**: `patch_similarity(q, r) == patch_similarity(r, q)` karena kedua
  arah memakai aggregation dan `k` yang sama.
- **Satu accidental patch tidak menentukan skor**: korespondensi per-patch
  di-aggregate; `top_k`/`median`/`trimmed_mean` membuang outlier. Outlier
  tunggal di reference tidak mengubah skor `top_k` sama sekali.
- **Background patch tidak mendominasi**: masking padding sudah terjadi saat
  ekstraksi (Prompt 11); `query_mask`/`reference_mask` opsional memungkinkan
  mask tambahan dan tervalidasi shape/dtype-nya.
- **Jumlah patch tidak memberi keuntungan**: `k` dibatasi sisi yang lebih
  kecil (`min(PATCH_TOP_K, nq, nr)`), jadi reference 64-patch dengan 4 konten
  match mendapat skor yang sama dengan reference 8-patch. Pada uji, skor
  4-match dalam 8 patch == 4-match dalam 64 patch (1.0).
- **Anti-count-bias SKU**: dipertahankan dari Prompt 09/10 — cap kandidat per
  SKU (`CANDIDATES_PER_SKU`) + agregasi max per SKU; SKU dengan banyak
  reference tidak unggul otomatis.

`patch_correspondence_scores(...)` mengembalikan detail per arah
(`query_to_reference`, `reference_to_query`, `symmetric`, `query_patches`,
`reference_patches`) untuk debug/evaluasi.

Skor adalah similarity, **bukan probabilitas** — istilah "probability" tidak
dipakai di kode, docs, maupun respons.

## Integrasi Service (`app/search/service.py`)

- Rerank loop hanya atas `candidate_ids` (shortlist bounded). Skor patch
  di-blend ke dino saja:
  `scores['dino'] = (1-PATCH_WEIGHT)*scores['dino'] + PATCH_WEIGHT*patch_score`.
- **`local_score` diekspos per hasil** (field `local_score` = patch similarity)
  untuk debug/evaluasi; `None` saat patch nonaktif.
- Guard `'patches' in available['dino']` (Prompt 11) tetap: query tanpa patch
  (kegagalan ekstraksi) memakai skor global murni.
- `evaluation_signature` kini memuat `patch_aggregation`, `patch_top_k`,
  `patch_trim` — kebijakan relevansi beku terikat pada konfigurasi reranking.

## Optimasi CPU/IO

- **Shortlist bounded**: patch matching ≤ `CANDIDATES` kandidat.
- **Reference patch features cached**: disimpan di SQLite `vectors` saat build
  (Prompt 10) — tidak ada komputasi ulang saat serving.
- **Bulk load**: `FaissIndexManager.references(ids)` baru — metadata + semua
  local vectors untuk seluruh shortlist dalam **2 query SQLite** (sebelumnya
  2 per kandidat). Caller tidak boleh melewatkan id seluruh korpus.
- Local vectors seluruh korpus **tidak pernah** dimuat; `reference()` per-baris
  tetap tersedia untuk tooling.
- Batching matmul tidak diperlukan — matriks korespondensi per kandidat kecil
  (≤64×64 float32) dan jumlah kandidat dibatasi.

## Config Baru (semua via env)

| Env | Default | Validasi |
|---|---|---|
| `PATCH_WEIGHT` | 0.0 (off) | 0..1 |
| `PATCH_AGGREGATION` | `top_k` | top_k / median / trimmed_mean / mean |
| `PATCH_TOP_K` | 16 | 4..256 |
| `PATCH_TRIM` | 0.1 | 0 ≤ x < 0.5 (untuk trimmed_mean) |
| `MAX_PATCHES` | 64 | 4..256 (existing, Prompt 11) |

Perubahan ranking tercakup `RANKING_CODE` (hash `ranking.py`) sehingga
evaluasi/kebijakan beku invalid bila kode berubah. Format index **tidak**
berubah — patch reference yang tersimpan sama, tidak perlu rebuild bila hanya
mengubah `PATCH_WEIGHT`/aggregation. (Index lama tetap harus di-rebuild dari
Prompt 11 karena `patch_version`; index yang dibangun setelah Prompt 11 tetap
valid.)

## Tests — `tests/test_patch_rerank.py`

Semua offline, synthetic fixture (unit vectors deterministik, seed tetap).
Harness service memakai MockEncoder mean-color: dua SKU dengan global vector
identik, berbeda hanya di patch (fine detail).

| Test | Cakupan |
|---|---|
| `test_strong_correspondence_beats_random_correspondence` | Identik = 1.0 > random + 0.3 |
| `test_symmetry_holds_for_all_aggregations_and_unequal_sizes` | Semua aggregation, ukuran 8×12 dan 4×64 |
| `test_outlier_patch_does_not_dominate` | 16 match + 1 outlier → skor = skor tanpa outlier; `mean` lebih rendah (baseline rapuh) |
| `test_masking_excludes_background_patches` | Masked = content-only; unmasked < masked; mask invalid ditolak |
| `test_reference_patch_count_does_not_change_score` | 4 match dalam 8 vs 64 patch → skor sama persis |
| `test_correspondence_scores_expose_directions_for_debug` | Detail per arah + count |
| `test_invalid_evidence_and_aggregation_rejected` | <4 patch, aggregation asing, trim 0.5, config invalid |
| `test_fine_detail_reranks_shortlist_and_exposes_local_score` | PH1 vs PH2 (global identik) → PH1 menang; `local_score` > 0.9 terekspos; patch_similarity dipanggil tepat `candidate_images` kali; bulk `references()` sekali |
| `test_more_references_do_not_win_by_count` | 1 ref PH1 vs 2 ref PH2 → PH1 tetap menang (anti-count) |
| `test_patch_weight_zero_keeps_scores_global_only` | Default off → `local_score` None |

### Menjalankan Tests

```powershell
cd ai-service
.\venv\Scripts\python.exe -m unittest discover -s tests -q
```

Hasil terakhir: **153 tests, OK** (skipped=1 — smoke test model asli opt-in
`RUN_MODEL_SMOKE=1`).

## Yang Tidak Diubah

- FAISS retrieval stage 1, rank fusion, cap per-SKU (Prompt 10).
- Format index/manifest: patch reference tersimpan sama seperti Prompt 11.
- OCR/text evidence: tidak ditambah (sesuai prompt).
- Skor tetap similarity heuristic, bukan probabilitas.
