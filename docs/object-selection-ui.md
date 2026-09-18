# UI Koreksi Object Selection — Halaman Pencarian Staff

> CATATAN SUPERSEDEN (2026-09-18): tombol kandidat "Objek 1..N", "Reset ke
> auto", "Foto penuh", "Perluas area", dan handle bundar yang dideskripsikan
> di bawah DIHAPUS atas permintaan owner. UI saat ini: satu frame otomatis
> (corner bracket tebal + chip ukuran) yang langsung di-adjust
> (geser/tarik sudut-tepi via zona sentuh tak terlihat). Wire format tetap
> `crop_json` + `selection_source`; state machine tidak berubah.

Fase ini menambahkan UI koreksi object selection di halaman pencarian staff
(`/products`, form upload/foto) di atas backend modular dari fase sebelumnya
(dokumentasi: `docs/object-selection-backend.md`). Stack frontend tetap sama:
Vanilla JS + Vite, tanpa framework. Tidak menyentuh reference admin selection
(konstrain fase ini).

## UX Flow

```
Pilih foto (kamera/galeri)
        │
        ▼
Preview foto asli tampil + request POST /object-selection (15s timeout)
        │
        ├─ sukses, ada candidate ─► overlay bounding box terbaik (source: auto)
        │                           + tombol "Objek 1..N" untuk tiap candidate
        ├─ sukses, tanpa box     ─► pesan "Objek belum terdeteksi" — tarik kotak
        │                           manual atau pakai foto penuh
        └─ gagal / timeout       ─► pesan "Seleksi otomatis belum tersedia" —
                                    tarik kotak manual atau pakai foto penuh
        │
        ▼
User koreksi: drag box, resize 8 handle (corner/edge), tap-tarik kotak baru,
"Reset ke auto", "Foto penuh", "Perluas area" (padding +8%)
        │
        ▼
Submit search ──► crop_json (normalized 0..1) + selection_source
                 dikirim sebagai crop_x/y/width/height + selection_mode
                 ke /search (kontrak sama dengan fase sebelumnya)
```

Pencarian **tidak pernah diblokir** oleh seleksi: gagal auto-selection tetap
memungkinkan full-image search atau box manual.

## Files Changed

| File | Perubahan |
|---|---|
| `resources/js/selection/geometry.js` | BARU — math murni: `normalizeBox`, `moveBox`, `resizeBox`, `boxFromDrag`, `boxAt`, `expandBox`, `contentRect`, `clientToNormalized`, `normalizedToClient`, `validBox` |
| `resources/js/selection/state.js` | BARU — state machine murni: `createState`, `transition` (event `user-edit`/`candidate`/`reset-auto`/`auto-applied`), `toWire` |
| `resources/js/selection/editor.js` | BARU — editor DOM overlay: stage > img + overlay (inset:0) > box + 8 handle; Pointer Events (mouse+touch), `setPointerCapture` (try/catch untuk synthetic events), `touchAction:none`, ResizeObserver untuk refit responsif |
| `resources/js/object-selection.js` | REWRITE — `initializeObjectSelections()`; contract data-attribute sama seperti sebelumnya; fetch `/object-selection` dengan AbortController 15s + header CSRF; tombol candidate; status pesan; handle flow existing-image (`data-image-url`) |
| `resources/css/app.css` | Tambah style `.object-selection-*` (stage, overlay, box + dimming `box-shadow 0 0 0 99999px rgba(0,0,0,.55)`, handle 18px) |
| `resources/views/components/object-selection.blade.php` | REWRITE — preview `relative min-h-[200px]`, tombol "Reset ke auto", "Foto penuh", "Perluas area"; hidden input `crop_json` + `selection_source` |
| `resources/views/products/show.blade.php` | Tombol "Foto penuh" + rename "Reset ke auto" pada upload admin (reference edit tidak disentuh) |
| `resources/views/products/index.blade.php` | Pasang `<x-object-selection form="home-image-search-form" />` |
| `app/Http/Controllers/ObjectSelectionController.php` | `propose()`: pass-through candidates `{box, source, score}` dari `/select`, validasi per-candidate (invalid dilewati, tidak membunuh response), fallback legacy `boxes` (source `unknown`, score null) |
| `tests/js/geometry.test.js`, `tests/js/state.test.js` | BARU — vitest (node environment) |
| `tests/Feature/ObjectSelectionProposeTest.php` | BARU — Laravel feature test (4 test) |
| `vitest.config.js`, `package.json` | BARU + script `npm test` = `vitest run` |

## Coordinate Mapping (scale-independent)

Prinsip: **tidak pernah mengkonversi CSS pixel → normalized langsung**. Editor
menggunakan persegi overlay yang `inset:0` di atas gambar yang ditampilkan —
persegi tampilan gambar *adalah* sistem koordinatnya, sehingga mapping tidak
bergantung pada object-fit/letterbox/scale:

```
contentRect(containerW, containerH, imageW, imageH)  // rect tampilan gambar
    = perhitungan object-fit: contain (letterbox centered)

clientToNormalized(clientX, clientY, rect)
    x = (clientX - rect.left) / rect.width   // clamp 0..1, null jika degenerate

normalizedToClient(box, rect)                 // invers untuk render box
```

- Normalisasi akhir: 6 desimal (`normalizeBox`), deterministik untuk wire payload;
  backend toleransi 1e-4 menyerap rounding ini.
- Kontrak crop sama persis dengan fase sebelumnya: normalized 0..1, `x`, `y`,
  `width`, `height`, `MIN_EXTENT=.01` (Laravel `CropCoordinates`), batas gambar
  tidak boleh dilewati (clamp di `moveBox`/`resizeBox`), `x+width <= 1+TOL`,
  `y+height <= 1+TOL`.
- Orientasi konsisten: `<img>` browser menerapkan EXIF auto-orient, sama dengan
  `exif_transpose` ai-service — koordinat normalized selalu relatif ke gambar
  yang sudah di-orientasi-kan (konvensi `CropCoordinates`).
- Box tidak bisa keluar gambar: semua operasi geometri lewat `normalizeBox`
  (clamp), dan pointer capture dipasang di overlay sehingga drag tidak lepas
  dari area gambar.

State → wire:

| Aksi user | Box | `selection_source` |
|---|---|---|
| Auto-selection diterapkan (belum disentuh) | candidate terbaik | `auto` |
| Klik candidate lain / drag / resize / kotak baru | box hasil edit | `manual` |
| "Foto penuh" | null | `full` |
| "Reset ke auto" | box auto tersimpan | `auto` (atau `full` jika auto kosong) |

## Fallback

1. **Auto-selection gagal/tanpa box** (backend `foreground_uncertain`,
   `full_image_fallback`, HTTP error, atau timeout 15s): UI menampilkan pesan
   dan tetap memungkinkan **full-image search** (`full`) atau **box manual**
   (tarik kotak pada foto / tap untuk kotak 20% di tengah).
2. **Candidate invalid**: `ObjectSelectionController` memvalidasi per-candidate
   dengan `CropCoordinates::validate()`; candidate invalid dilewati tanpa
   membatalkan response (test: `test_invalid_candidate_is_skipped_without_failing_others`).
3. **Backend lama / kontrak lama**: response `boxes` tanpa `candidates` tetap
   didukung (source `unknown`, score `null`); JS juga menerima bentuk legacy.
4. **Pencarian**: `RetrievalClient::search()` meneruskan `selection_mode`;
   tanpa crop → `object`, dengan crop/full → `original` (preprocessing mode).
   Pencarian tidak pernah gagal hanya karena seleksi gagal.

## Tests & Build Result

- **vitest** (`npm test`): **33 passed** — `tests/js/geometry.test.js` (contentRect
  landscape/portrait/upscale/degenerate, mapping client↔normalized + clamping,
  moveBox/resizeBox 8 handle × delta dengan batas, min-extent, boxFromDrag/boxAt/
  expandBox, kontrak validBox) dan `tests/js/state.test.js` (semua transisi +
  event invalid diabaikan).
- **Laravel feature tests**: **41 passed (176 assertions)** — termasuk
  `ObjectSelectionProposeTest` (pass-through metadata, legacy boxes-only,
  candidate invalid dilewati, backend 500 → `selection_unavailable`),
  `VisualWorkflowTest`, `RetrievalIntegrationTest`, `StorageArchitectureTest`.
- **Build**: `npm run build` sukses — `public/build/assets/object-selection-BkWN2hZ-.js` (10.09 kB).
- **Verifikasi browser** (https://barcodeindentify.test, ai-service hidup):
  auto-selection menampilkan box `{x:0.2625, y:0.1967, w:0.4925, h:0.6183}`
  (source `auto`); drag +150px clamp di tepi kanan; resize handle `se` +200px
  clamp ke min-extent; "Foto penuh" → source `full`; "Reset ke auto" mengembalikan
  box auto; klik candidate → source `manual`. Mapping terverifikasi: 133.05px /
  507px lebar tampilan = 0.2625 (natural 800×600).

## Keterbatasan

- Backend /select saat ini GrabCut-only (encoders degraded di mesin dev);
  UI tidak bergantung pada kualitas detector — semua jalur fallback teruji.
- Reference admin selection (edit crop setelah upload) **tidak** diimplementasikan
  sesuai konstrain fase ini; upload admin sudah memiliki tombol "Foto penuh"/
  "Reset ke auto" tapi alur edit crop-nya masih fase sebelumnya.
