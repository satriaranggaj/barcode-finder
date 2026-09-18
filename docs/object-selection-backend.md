# Modular Auto Object Selection — Backend

Fase ini mengimplementasikan backend modular untuk auto object selection di
ai-service: dari foto staff dengan background toko, sistem mengusulkan bounding
box (normalized 0..1) untuk objek utama. Tanpa UI — integrasi UI adalah fase
berikutnya.

## Arsitektur

```
                    ┌─────────────────────────────┐
  image ───────────►│       SelectionPipeline     │
                    │  propose(image) -> dict     │
                    └──────────────┬──────────────┘
                                   │
                    1. primary MultiSourceSelector (grabcut_foreground,
                       color_pop, contour — IoU-deduplicated, max 5)
                       ├─ proposal ditemukan  → selesai (reason: foreground_proposal)
                       ├─ abstain bersih      → full image (reason: foreground_uncertain)
                       └─ exception           ↓
                    2. foreground fallback (ForegroundSelector, source: foreground_fallback)
                       ├─ proposal ditemukan  → selesai (reason: foreground_fallback)
                       └─ abstain/gagal       ↓
                    3. terminal: full image (reason: full_image_fallback)
```

Semua tahap berada di `app/preprocessing/selection.py`. Kontrak utama:

```python
class ObjectSelector(Protocol):
    def select(self, image: Image.Image) -> list[Candidate]: ...

@dataclass(frozen=True)
class Candidate:
    box: BoundingBox          # normalized 0..1, kontrak yang sama dengan crop manual
    source: str               # metode asal, mis. 'grabcut_foreground' | 'foreground_fallback'
    score: float | None       # heuristic coverage (fraksi area) — BUKAN probability
```

- `ForegroundSelector(padding=.08, detector=None, source=...)` — detector injectable,
  sehingga heuristic di bawahnya bisa diganti (atau diganti model nanti) tanpa
  menyentuh search pipeline. Score = `box.width * box.height` (coverage heuristic),
  berguna untuk mengurutkan candidate, tidak pernah diklaim sebagai probability.
- `propose(image, selector)` — wrapper yang tidak pernah raise; `boxes` (legacy,
  dipakai JS) + `candidates` (metadata provenance, additive).
- `SelectionPipeline(selector=None, fallback=None, fallback_padding=.15)` —
  rantai fallback tiga tahap di atas. Maksimal 5 candidate.

## Algoritma (GrabCut, gratis/open-source, CPU-only)

`GrabCutForeground` (file `preprocessing/foreground.py`, tidak diubah di fase ini):

1. Thumbnail max 384px (bound CPU cost).
2. `cv2.grabCut` dengan inisialisasi rect (border margin 4% dari sisi terkecil),
   3 iterasi.
3. Foreground mask = `GC_FGD | GC_PR_FGD` → `connectedComponentsWithStats`.
4. Abstain (return kosong) jika:
   - tidak ada komponen foreground, atau
   - area komponen < 12% atau > 90% dari gambar (objek terlalu kecil / foreground
     dominan), atau
   - komponen menyentuh margin border (kemungkinan terpotong), atau
   - gambar < 32px.
5. Padding konfigurabel ditambahkan ke tiap komponen agar ujung/detail objek
   tidak terpotong: default 8% (`settings.selection_padding`, rentang valid
   0..0.25 ≈ 5-10% sesuai spesifikasi).
6. Komponen diurutkan dari yang terluas, maksimal 5, dikonversi ke
   `BoundingBox` normalized.

## Semantik fallback (sengaja ketat)

| Situasi primary selector | Perilaku pipeline |
|---|---|
| Mengembalikan candidate | Dipakai langsung, fallback tidak dijalankan |
| Abstain bersih (`foreground_uncertain`) | Full image — **tanpa** menjalankan detector kedua dengan padding berbeda (menjaga determinisme; test `test_clean_uncertain_result_never_triggers_second_detector`) |
| Exception (`foreground_failed`) | Jalankan foreground fallback (GrabCut padding .15, `source='foreground_fallback'`) |
| Fallback abstain/gagal | Terminal `full_image_fallback` — full image |

**Search tidak pernah gagal karena selection gagal.** `RetrievalService.embed()`
memanggil `self.pipeline.propose(image)`; jika tidak ada box, embedding memakai
gambar penuh dan `info['reason']` mencatat penyebabnya. `RetrievalService`
menerima `pipeline=` injectable sehingga selector lain (mis. model deteksi
objek open-source nanti) bisa dipasang tanpa rewrite search pipeline
(terbukti oleh `test_selector_can_be_swapped_without_touching_search_pipeline`).

## Endpoint `/select`

Memakai `app.state.selection` (`SelectionPipeline` + `MultiSourceSelector`)
yang dibangun saat startup dari `settings.selection_padding`. Empat sumber
komplementer digabung (prioritas + dedupe IoU, maks 5): `grabcut_foreground`
(presisi, foto bersih), `color_pop` (warna menonjol vs median — foto toko
genggam), `contour` (tepi Canny, produk kecil), lalu `center_prior` sebagai
kotak tengah yang bisa diedit bila semua detektor abstain
(`reason='center_fallback'`). Pipeline retrieval tidak berubah dan tetap
fallback ke full image. Respons:

```json
{
  "boxes": [{"x": 0.1, "y": 0.2, "width": 0.5, "height": 0.7}],
  "candidates": [{"box": {...}, "source": "color_pop", "score": 0.35}],
  "reason": "foreground_proposal"
}
```

Alasan yang mungkin: `foreground_proposal`, `foreground_uncertain`,
`center_fallback`, `foreground_fallback`, `full_image_fallback`,
`foreground_failed` (propose() mentah, sebelum fallback).

> Catatan: perubahan kode representasi mewajibkan rebuild indeks
> (`build_index --rebuild`) karena signature menolak snapshot lama —
> itu mekanisme fail-closed yang disengaja, bukan bug.

## Benchmark CPU (sintetik, Windows dev machine)

Script: `ai-service/app/scripts/benchmark_selection.py`; hasil tersimpan di
`ai-service/benchmarks/selection-cpu.json`. 20 runs per skenario, gambar 640px
sintetik, pipeline penuh (GrabCut 3 iterasi pada thumbnail 384px):

| Skenario | Median | p95 | Hasil |
|---|---|---|---|
| clean background | 265 ms | 309 ms | abstain → uncertain |
| single object (16% area) | 273 ms | 331 ms | 1 box ditemukan |
| cluttered (6 blok + noise) | 330 ms | 366 ms | abstain → uncertain |
| small object (<12% area) | 254 ms | 304 ms | abstain → uncertain |
| photo-like noise + objek | 1189 ms | 1363 ms | abstain → uncertain |

Catatan: foto real biasanya di antaranya; noise ekstrem memperlambat GrabCut
secara signifikan (graph cut lebih mahal pada data noisy) — lihat Limitations.

## Limitations (jujur)

1. **Heuristic, bukan deteksi semantik.** GrabCut memisahkan foreground/background
   dari warna, bukan "mengetahui" objek. Kotak yang diusulkan adalah komponen
   foreground yang bisa diedit, bukan deteksi produk. Score adalah coverage
   heuristic, bukan confidence/probability.
2. **Sering abstain justru benar.** Gambar bersih, objek < 12% area, foreground
   dominan (> 90%), komponen terpotong border → tidak ada proposal → full image.
   Ini aman (search tetap jalan) tapi berarti foto dengan background sangat
   ramai bisa tidak mendapat proposal.
3. **Latency noisy ~1.2s.** Pada gambar ber-noise, GrabCut melambat ~4x.
   Endpoint /search punya lock non-blocking → 503 "Inference busy" jika
   bersamaan; selection ditambahkan ke critical section yang sama.
4. **Deterministik tapi versi-dependent.** GrabCut + connectedComponents
   deterministik untuk input yang sama, namun hasil bisa berbeda antar versi
   OpenCV.
5. **Index rebuild diperlukan saat deploy.** `REPRESENTATION_CODE` di
   `search/service.py` meng-hash `preprocessing/selection.py` dan
   `preprocessing/foreground.py`; perubahan file ini mengubah signature
   preprocessing sehingga index lama ditolak ("Preprocessing changed; rebuild
   index"). Vektor referensi tersimpan tidak berubah (selection hanya
   memengaruhi query mode 'object'), tapi rebuild index adalah konsekuensi
   operasional dari safety check ini.
6. **Belum ada UI.** Fase berikutnya: integrasi proposal ke flow upload
   object-selection.js (contract `boxes` sudah kompatibel).

## Files changed

- `ai-service/app/preprocessing/selection.py` — `Candidate` dataclass,
  `ObjectSelector` → `list[Candidate]`, `ForegroundSelector` (detector
  injectable + source + coverage score), `propose` (+`candidates` key),
  `SelectionPipeline` (fallback chain 3 tahap).
- `ai-service/app/search/service.py` — `RetrievalService(pipeline=...)`
  injectable; `embed()` memakai pipeline; komentar invariants dipindah ke
  pipeline.
- `ai-service/app/main.py` — `/select` memakai `app.state.selection`
  (SelectionPipeline dari `settings.selection_padding`).
- `ai-service/tests/test_selection.py` — kontrak `propose` diperbarui
  (+`candidates`); 9 test baru: metadata/source/score, truncation 5,
  validasi padding 0..0.25, preferensi primary, no-second-detector saat
  abstain bersih, fallback `foreground_fallback`, terminal `full_image_fallback`
  (fallback abstain dan fallback gagal), abstain uniform image, bounds pada
  gambar cluttered (GrabCut nyata).
- `ai-service/tests/test_object_retrieval.py` — patch target
  `app.preprocessing.selection.propose`; 2 test service-level: search survive
  kegagalan total selection; selector swap tanpa ubah search pipeline.
- `ai-service/app/scripts/benchmark_selection.py` — benchmark CPU 5 skenario.
- `ai-service/benchmarks/selection-cpu.json` — hasil benchmark.

## Test results

```
73 passed, 1 skipped, 2 warnings, 2 subtests passed in 12.57s
```
