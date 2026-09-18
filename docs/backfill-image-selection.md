# Backfill gambar produk existing (auto-selection)

Perintah: `php artisan products:backfill-images [--dry-run] [--product=SKU] [--force]`

Mengisi auto object selection untuk foto produk yang sudah ada TANPA
mengharuskan admin upload ulang. Bekerja berpasangan dengan dua command
yang sudah ada — tidak menduplikasi mekanismenya:

| Tahap | Command | Status |
|---|---|---|
| Varian WebP (EXIF, master/katalog/thumbnail) | `products:compress-images` | sudah ada, idempotent |
| **Auto-selection (crop + source + verified)** | **`products:backfill-images`** | **baru (file ini)** |
| Embedding + index FAISS | `products:export-visual` + `search:build-index` | sudah ada, aman |

## Perilaku

- Sumber piksel: `master_path` bila ada, jika tidak `path` (gambar existing
  sebagai fallback — katalog tidak pernah rusak saat migrasi bertahap).
- Berjalan di disk apa pun (`local`, S3-kompatibel) via abstraksi Storage;
  tidak ada path hardcoded. File asli tidak pernah dihapus/ditimpa.
- Idempotent: foto yang sudah punya `crop` di-SKIP, aman diulang. `--force`
  memproses ulang semuanya.
- Sukses → `crop` + `selection_source=auto` + `selection_verified=false` +
  `index_status=pending` (ditulis dengan optimistic-concurrency guard, tidak
  menimpa edit admin yang masuk bersamaan).
- Tanpa usulan box → `SELECTION FALLBACK` (tanpa tulis DB; dicoba lagi di
  run berikutnya; saat build-index, auto-selection build-time tetap memberi
  fallback full-image).
- Box AI tak valid / AI mati / file hilang → `FAILED`, dicatat, lanjut
  foto berikutnya. Exit code FAILURE bila ada yang gagal.
- `--dry-run` memanggil AI untuk klasifikasi OK/FALLBACK tetapi tidak
  menulis file, database, atau index.
- `--product=SKU123` untuk uji satu SKU. SKU tak dikenal → gagal cepat.

Progress dan ringkasan:

```text
[1/62] SKU123 - OK
[2/62] SKU124 - SELECTION FALLBACK
[3/62] SKU125 - FAILED
Total: 62 | Berhasil: 60 | Gagal: 1 | Skip: 1 | Auto-selection berhasil: 60 | Selection fallback: 1
```

Jumlah "reference berhasil dibuat" dilaporkan oleh
`search:build-index` (mekanisme dedup `image_id` di sana mencegah
duplikat bila command dijalankan dua kali).

## Keputusan desain yang disengaja (menyimpang dari draf awal)

- **Varian gambar tidak dibuat di command ini** — `products:compress-images`
  sudah melakukannya secara aman (chunked, resume, file lama dipertahankan).
  Menyalin logikanya berarti dua jalur kode untuk satu pekerjaan.
- **Koordinat disimpan sebagai JSON `crop {x,y,width,height}`** (kolom yang
  sudah ada), bukan empat kolom `crop_x/...` — konvensi ternormalisasi 0..1
  yang sama, divalidasi `CropCoordinates` + kontrak ai-service.
- **Kolom `source` TIDAK ditimpa** `existing_product_backfill`. Seluruh baris
  existing bernilai `catalog`/`manual_reference`; menimpanya menghancurkan
  asal-usul. Provenance backfill sudah tercatat berlapis: `selection_source`
  + `selection_verified` di DB, sidecar export, dan metadata index.
- **Tidak ada file crop permanen** — crop dibuat dari master + koordinat
  sesuai kebutuhan.

## Review admin

Halaman produk admin menampilkan badge per foto (terverifikasi / auto atau
manual belum diverifikasi / tanpa seleksi) dan filter
`?selection=all|unverified|verified|failed`. Halaman edit seleksi yang sudah
ada dipakai untuk konfirmasi (simpan tanpa ubah → verified), drag, resize,
reset ke auto, full image, dan koreksi (→ `manual` + `verified` + tandai
`index_status=pending` untuk rebuild; representasi di-regenerate oleh
`search:build-index`, bukan ditulis langsung ke FAISS).

## Urutan deployment yang aman (production, ~62 produk)

a. **Backup database** (`pg_dump`) dan snapshot volume `storage/`.
b. Deploy migration/code. Tidak ada migration baru untuk fitur ini.
c. `php artisan products:compress-images --dry-run`, lalu tanpa dry-run
   (boleh dicicil `--chunk`, resume `--after-id`).
d. `php artisan products:backfill-images --dry-run`, lalu
   `php artisan products:backfill-images --product=SKU123` (satu SKU).
e. Periksa master/katalog/thumbnail SKU tersebut di katalog.
f. Periksa auto-selection di halaman admin (badge + filter).
g. `php artisan search:build-index --include-verified`, restart AI worker,
   lakukan test search untuk SKU tersebut.
h. Bila hijau: `php artisan products:backfill-images` untuk seluruh produk,
   rebuild index sekali lagi, restart worker, uji sampel acak.
