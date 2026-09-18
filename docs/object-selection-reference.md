# Object Selection — Product Reference Image (Admin Upload/Edit)

> CATATAN SUPERSEDEN (2026-09-18): tombol kandidat, reset, foto-penuh, dan
> perluas-area yang disebut di bawah DIHAPUS atas permintaan owner; tersisa
> satu frame otomatis + adjust langsung. Skema, provenance, dan kontrak
> koordinat di dokumen ini tetap berlaku.

Fase ini melengkapi object selection untuk **reference image produk** pada
flow upload/edit admin. Konsep inti: **katalog menyimpan FULL IMAGE; AI memakai
crop sebagai selected object untuk global representation.** Tidak ada file crop
permanen — master + koordinat normalized sudah cukup.

## Schema (inspeksi: tidak ada migration baru)

Tabel `product_photos` sudah memiliki semua kolom yang diperlukan sejak
migration `2026_09_15_000000_add_crop_coordinates_to_product_photos_table`:

| Kolom | Tipe | Makna |
|---|---|---|
| `crop` | json nullable | Koordinat normalized `{x, y, width, height}` 0..1 (kontrak `CropCoordinates`) |
| `selection_source` | string default `auto` | `auto` (usulan AI diterima) / `manual` (koreksi admin) / `full` (foto penuh) |
| `selection_verified` | boolean default false | `true` jika crop tersimpan (area objek eksplisit) |

Tidak dibuat tabel baru; kolom `crop_x/y/width/height` terpisah tidak
diperlukan karena arsitektur existing sudah memakai JSON `crop` (single source
of truth, mirror `ai-service/app/preprocessing/selection.py`).

## Flow Upload Admin

```
Pilih foto (multi, max 10) ──► preview foto asli per panel
        │
        ▼
POST /object-selection per foto (auto object selection, timeout 15s)
        │
        ├─ box diusulkan  ──► overlay bounding box + tombol "Objek N" per candidate
        ├─ tanpa box      ──► status "Objek belum terdeteksi" — tarik kotak manual
        └─ gagal          ──► status "Seleksi otomatis belum tersedia" — tetap bisa
        │
        ▼
Admin koreksi: drag, resize 8 handle, candidate lain, "Reset ke auto",
"Foto penuh", "Perluas area"
        │
        ▼
Submit ──► app.js upload per file: crop_coordinates[i] (JSON) + selection_sources[i]
        ──► uploadPhoto() menyimpan FULL image (catalog/master/thumbnail via /prepare)
            + crop + selection_source + selection_verified
```

Normalisasi konsistensi di `uploadPhoto` (mirror `ObjectSelectionController::update`):
`selection_source = crop ? source : 'full'` — upload foto penuh selalu tersimpan
`full` meskipun input `auto` basi.

## Flow Edit (pasca upload)

- Link "Edit area barang" → `admin.photos.selection.edit` → komponen
  `<x-object-selection>` dengan `data-image-url` (master) + `data-initial-crop`
  + `data-initial-source` (source tersimpan dipertahankan — simpan tanpa ubahan
  tidak mengubah provenance `auto` → `manual`).
- `PUT admin.photos.selection.update`: validasi crop_json + selection_source,
  update crop/source/verified, `index_status = 'pending'` (rebuild index
  menerapkan perubahan reference).
- Foto katalog tidak pernah diganti atau dihapus oleh operasi selection.

## Coordinate Convention & Preprocessing

- Koordinat normalized 0..1 relatif ke gambar ter-orientasi (EXIF), kontrak yang
  sama untuk query dan reference (`CropCoordinates` PHP ↔ `BoundingBox` Python;
  denormalisasi floor/ceil containment).
- Index build (`ai-service/app/scripts/build_index.py`) membaca sidecar export:
  `crop` + `selection_source`; crop null + source `full` → box (0,0,1,1);
  preprocessing embedding memakai box yang sama dengan query manual. **Tidak ada
  perubahan embedding/index selain wiring yang sudah ada** — fase ini hanya
  menjamin crop tersimpan benar dan ter-export.
- Export (`products:export-visual`) menulis sidecar `{crop, selection_source,
  selection_verified, source}` per foto — tanpa perubahan.

## Files Changed

| File | Perubahan |
|---|---|
| `resources/views/products/show.blade.php` | Template editor upload admin: tambah `data-select-url`, `[data-selection-status]`, `[data-proposals]` (perbaikan crash `proposals.replaceChildren()` pada template lama) |
| `app/Http/Controllers/ProductController.php` | `uploadPhoto`: normalisasi `selection_source = crop ? source : 'full'`, `selection_verified = crop !== null` |
| `resources/js/selection/state.js` | `createState(initialBox, touched, initialSource)` — pertahankan source tersimpan yang valid (`auto/manual/full`), fallback `manual` |
| `resources/js/object-selection.js` | Baca `data-initial-source`; styling tombol candidate (kuning brand, terlihat di tema gelap admin) |
| `resources/views/components/object-selection.blade.php` | Prop `source` → `data-initial-source` |
| `resources/views/products/selection.blade.php` | Teruskan `:source="$photo->selection_source"` |
| `tests/Feature/ReferenceSelectionTest.php` | BARU — 9 test reference selection |
| `tests/js/state.test.js` | +2 test initialSource |
| `docs/object-selection-reference.md` | Dokumentasi fase ini |

## Tests

`tests/Feature/ReferenceSelectionTest.php` (9 test):

- **auto selection save**: upload `crop_coordinates` + `selection_sources=['auto']` → crop float presisi tersimpan, source `auto`, verified true
- **manual override** (upload): source `manual` tersimpan
- **manual override** (edit): PUT crop manual menggantikan crop auto, `index_status` pending
- **reset**: PUT crop + source `auto` (reset ke auto) tersimpan
- **full image** (upload): crop null, source `full`, verified false
- **full image** (edit): PUT `crop_json=''` + `full` → crop null
- **normalisasi source**: crop kosong + source `auto` → tersimpan `full`
- **coordinates persisted**: float presisi (0.2625, 0.19666666666666666, …) round-trip JSON persis
- **catalog image unchanged**: hash file katalog == file upload; selection update tidak mengubah/menambah file storage (tanpa crop objects)
- **edit page**: render `data-initial-source="auto"` + `data-initial-crop`

Vitest: 35 passed (state: initialSource preserved/fallback; geometry: 21).

## Test & Build Result

- `artisan test`: **94 passed (362 assertions)**
- `npm test` (vitest): **35 passed**
- `npm run build`: sukses (object-selection bundle 10.26 kB)

## Verifikasi Browser (barcodeindentify.test, ai-service hidup)

1. Upload `test-obj.png` (800×600, objek gelap) ke produk admin → editor tampil
   dengan box auto `{x:0.21875, y:0.145, width:0.575, height:0.7117}`, source
   `auto`, 1 candidate "Objek 1" (sebelumnya template ini crash karena tanpa
   `data-proposals`).
2. Drag sintetis +60px/+20px → crop bergeser sesuai mapping (60/492=0.12195),
   source berubah `manual`.
3. "Reset ke auto" → box auto kembali, source `auto`.
4. Submit → photo tersimpan: `crop` normalized presisi, `selection_source=auto`,
   `selection_verified=true`; file katalog 800×600 (full image utuh), master +
   thumbnail terpisah; tidak ada file crop.
5. Halaman "Edit area barang" memuat crop tersimpan sebagai box dan
   mempertahankan `selection_source=auto` pada hidden input.

## Keterbatasan

- `selection_verified` = crop tersimpan (heuristic area-objek-eksplisit), bukan
  jejak audit klik admin; dipakai sebagai metadata export, bukan filter build.
- Backend /select saat ini GrabCut-only di mesin dev (encoders degraded); jalur
  fallback UI (manual/full) tetap teruji.
