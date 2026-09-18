# DINO Patch Token Extraction (Prompt 11)

Dokumen implementasi DINOv2 patch-token extraction untuk local fine-grained
representation. Tanggal: 2026-09-17. Melanjutkan Prompt 10
(`docs/object-centric-faiss-index.md`). **Final ranking tidak diubah pada prompt
ini** — `PATCH_WEIGHT` default tetap 0 sehingga perilaku default identik.

## Desain

`app/encoders/dino.py` kini menyediakan tiga lapis API:

| API | Bentuk | Kegunaan |
|---|---|---|
| `encode_images(...)` (existing) | `[N, D]` float32 L2-normalized | Global CLS representation — tidak berubah |
| `patch_features(image)` (baru) | `PatchFeatures` | Grid patch penuh + mask validitas padding |
| `encode_patches(image, limit=64)` (rapikan) | `[K, D]` | Kompatibel dengan storage/ranking existing |

```python
@dataclass(frozen=True)
class PatchFeatures:
    features: np.ndarray  # [grid*grid, dim] float32, L2-normalized (termasuk padding)
    valid: np.ndarray     # [grid*grid] bool; True = patch center di dalam konten
    grid: int

    @property
    def content(self) -> np.ndarray   # features[self.valid], urutan grid dipertahankan
    def compact(self, limit) -> np.ndarray  # masking + cap merata (linspace)
```

- `spatial_validity(image_size, side, grid)` adalah fungsi module murni — mudah
  diuji tanpa model.
- `MIN_PATCH_EVIDENCE = 4`: kurang dari 4 token konten → `ValueError` ("Object
  too narrow for robust patch evidence") — konsisten dengan syarat minimal
  `patch_similarity`.
- Tidak ada asumsi semantic part (tip/handle/strap/petal). Sistem hanya
  melakukan visual local correspondence antar token.

## Padding Mask

`pad_square` memberi padding abu-abu (127,127,127) dengan konten di tengah.
Patch token yang pusatnya jatuh di luar kotak konten mendeskripsikan padding,
bukan produk — harus dibuang:

```
left = (side - width)  / (2*side)
top  = (side - height) / (2*side)
valid = (xx >= left) & (xx <= 1-left) & (yy >= top) & (yy <= 1-top)
```

Contoh grid 8, portrait 100×200 (side 200): kolom 2–5 valid → 32 token konten.
Landscape 200×100: baris 2–5 valid → 32 token. Square: semua valid. Gambar
sangat pipih (64×2): 0 valid → `ValueError`.

Catatan: pendekatan lama ("spatial-no-padding-v1") menghindari padding dengan
cara lain; versi baru memakai masking eksplisit sehingga **versi signature
berubah menjadi `masked-square-v2`** — index lama otomatis ditolak ("Preprocessing
changed; rebuild index", lihat Prompt 10).

## Integrasi Service (`app/search/service.py`)

1. **`embed()`**: patch extraction dipisah dari blok try/except per-encoder.
   Sebelumnya kegagalan patch membuang **seluruh** representasi dino (global
   ikut hilang). Sekarang: kegagalan patch hanya log
   `Patch extraction failed; dino global retained` dan global dino tetap
   tersedia. Reference tetap gagal tegas di validasi index
   (`Incomplete crop representations` di `add_product_embedding`) — tidak ada
   index yang diam-diam kehilangan patch.
2. **`search()`**: blok patch rerank kini juga guard
   `'patches' in available['dino']` — query tanpa patch (karena kegagalan)
   langsung memakai skor dino global murni, tidak crash.

## CPU & Memory

- Tidak ada encoder DINO kedua; tetap `HuggingFaceEncoder` tunggal, device CPU
  didukung penuh (inference_mode).
- Satu forward pass per gambar; patch tokens diambil dari
  `last_hidden_state` yang sama dengan global — tidak ada pass ekstra.
- Query patch vectors **tidak disimpan permanen** — hidup hanya selama request
  `embed`/`search` (dict in-memory, dibuang setelah response).
- Reference local vectors memakai storage existing yang efisien: SQLite
  `vectors` (Prompt 10), disimpan hanya saat build index dengan
  `PATCH_WEIGHT > 0`.

## Signature & Rebuild

`encoders/dino.py` termasuk dalam `REPRESENTATION_CODE` (hash source file) dan
`patch_version` naik ke `masked-square-v2`, sehingga index manifest v2 lama
ditolak tegas saat load. Rebuild Windows:
`powershell -File .\rebuild-index.ps1 -Dataset path\to\dataset`.

## Tests — `tests/test_patches.py`

Semua test offline (fake tensor, tanpa download model). Harness `fake_dino()`
mengganti `processor`/`model`/`torch` via `DinoEncoder.__new__`.

| Test | Cakupan |
|---|---|
| `test_spatial_validity_square_portrait_landscape` | Mask murni: square 64/64, portrait 32 (kolom 2–5), landscape 32 (baris 2–5) |
| `test_patch_features_shape_normalization_and_mask` | Shape `[64,16]`, dtype float32, L2-norm ≈ 1 semua baris, valid bool, `content` = `features[valid]` |
| `test_portrait_landscape_and_tiny_square_images` | 100×200, 200×100, 8×8 (semua valid) |
| `test_too_narrow_image_rejected_without_semantic_parts` | 64×2 → ValueError "narrow", tanpa asumsi part |
| `test_non_square_token_layout_rejected` | 63 token → ValueError "layout" |
| `test_repeat_calls_are_bitwise_deterministic` | Dua panggilan identik bitwise |
| `test_compact_caps_by_even_grid_sampling` | Cap 10 = `content[linspace]`; `compact(0)` ditolak |
| `test_encode_patches_matches_compact_content` | Backward-compat `encode_patches == patch_features(...).compact(limit)` |
| `test_dataclass_rejects_inconsistent_shapes` | Validasi dataclass + koersi dtype bool |
| `test_patch_failure_keeps_dino_global_representation` | Patch gagal → embed sukses, `global` ada, `patches` tidak |
| `test_missing_patch_support_keeps_dino_global_representation` | Encoder tanpa `encode_patches` → sama |
| `test_search_skips_patch_rerank_when_query_patches_missing` | `patch_similarity` tidak dipanggil; hasil search tetap benar |

### Menjalankan Tests

```powershell
cd ai-service
.\venv\Scripts\python.exe -m unittest discover -s tests -q
```

Hasil terakhir: 123 tests, OK (skipped=1 — smoke test model asli opt-in via
`RUN_MODEL_SMOKE=1`).

## Yang Tidak Diubah (sesuai prompt)

- Formula final ranking: `patch_similarity` dan blend
  `(1-PATCH_WEIGHT)*dino + PATCH_WEIGHT*patch` tidak disentuh (default 0).
- `faiss_index.py`, `ranking.py`, `config.py`: tidak berubah pada prompt ini.
- Tidak ada encoder baru, tidak ada penyimpanan permanen query patches.
