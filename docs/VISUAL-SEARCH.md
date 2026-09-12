# Lensku: implementasi visual search lokal

Baseline: `5854fba` (update fitur admin biasa). Tidak ada deploy, push, perubahan `.env` pengguna, atau perubahan database production. Modifikasi logo/layout yang sudah ada sebelum pekerjaan ini dipertahankan.

## Penyebab dan alur runtime

Baseline memakai CLIP seluruh frame dan cosine sebagai keputusan hasil. Kemiripan tangan/rak/komposisi dapat menghasilkan kandidat salah; cosine bukan probabilitas SKU benar. Implementasi non-PostgreSQL sebelumnya juga membaca seluruh embedding ke PHP.

Alur aktif:

`browser compression → validasi Laravel → safe decode → U2NetP foreground → normalisasi → CLIP → pgvector Top-30 → visual verification → calibrated rejection → deduplicate product → hasil atau []`.

Query dan reference memakai `/features`, pipeline `lensku-object-1`, revisi CLIP yang sama, checksum model foreground yang sama. Vector lama tetap ada tetapi tidak digunakan oleh pencarian baru. Ketidakcocokan versi atau response AI rusak menjadi technical failure, bukan no-match. No-match tidak memicu refill atau fallback legacy.

U²-Net-P dipilih sebagai satu model foreground lokal ringan, file sekitar 4,7 MB. ONNX Runtime menggunakan CPU dua thread dan session cached; CLIP loaded sekali. Ini saliency segmentation, bukan detektor tangan sempurna. Soft background suppression, padding, dan mempertahankan semua komponen foreground mengurangi pemotongan produk. Mask kosong/penuh mempertahankan frame. Tidak memakai warna kulit, kategori, nama produk, SKU, atau API eksternal sebagai rule.

Sumber model: [U²-Net resmi](https://github.com/xuebinqin/U-2-Net), [distribusi ONNX rembg](https://github.com/danielgatis/rembg/blob/main/rembg/sessions/u2netp.py). Checksum SHA-256 diverifikasi saat load: `309c8469258dda742793dce0ebea8e6dd393174f89934733ecc8b14c76f4ddd8`.

## Verification dan no-match

Reference menyimpan embedding, histogram HSV, texture LBP, pattern arah gradient dalam empat wilayah, serta proporsi foreground. Query menghitung representasi yang sama sekali. Hanya Top-K yang diverifikasi; tidak ada decode foto reference pada setiap search.

Score final adalah minimum antara global cosine dan rata-rata agreement fitur yang tersedia. Histogram dibandingkan dengan intersection ternormalisasi; proporsi dibandingkan sebagai rasio. Jadi disagreement menurunkan skor, tidak sekadar menambah skor global. Tidak ada feature dianggap cocok ketika seluruh descriptor hilang. Threshold dikalibrasi terhadap score final ini, bukan terhadap cosine lama. Perubahan preprocessing, descriptor, atau scoring wajib menaikkan versi pipeline dan melakukan reindex/kalibrasi.

Policy lokal saat ini: **0.7652154992363234**, hasil pemisahan pada **2 query positif + 8 query negatif** berlabel dari pengguna. Batas salah tertinggi 0.759380455990205; true match terendah 0.7710505424824418. Angka tidak berasal dari screenshot dan tidak ditanam ke runtime/source config. File policy berada di `storage/app/visual-search-policy.json`.

Kalibrasi menolak dataset tanpa kedua jenis query, hash tidak cocok, duplicate foto, query identik reference, positive tanpa kandidat relevan, dan data yang tidak mempunyai threshold pemisah. Tujuannya zero observed false positive dan mempertahankan setiap positive query. Jika tujuan tidak bisa dipenuhi, command gagal dan tidak membuat policy palsu. Policy tidak ditimpa otomatis. Policy hilang/invalid atau pipeline berbeda menghasilkan technical failure yang jelas di log, tidak dianggap semua produk no-match.

**Data ini tuning lokal kecil, belum held-out.** Keberhasilan pada sepuluh foto ini tidak membuktikan akurasi seluruh katalog atau foto baru. Tidak ada klaim semua background/occlusion sudah terselesaikan.

## Kompresi bersama

`resources/js/image-compression.js` dipasang oleh `app.js` pada seluruh named image input aplikasi: `image` dan `images[]`.

| Pengaturan | Reference / tambah design | Query pencarian |
|---|---:|---:|
| Long edge maksimum | 2560 px | 1920 px |
| Quality | 0.90 | 0.88 |
| Output foto | WebP | WebP |
| PNG | PNG lossless, alpha dipertahankan | PNG lossless, alpha dipertahankan |

Tidak upscale. Foto kecil <=512 KiB yang dimensinya sudah sesuai dipertahankan. `createImageBitmap(imageOrientation: from-image)` atau native Image decode mengikuti orientasi; output canvas membawa orientasi visual. Hasil yang sama di-cache agar tidak recompress berulang. Multi-file diproses sequential, urutan dan jumlah dipertahankan, maksimum 10 file. Preview menggunakan file yang sudah disiapkan. Submit menunggu compression, mengganti FileList input asli, lalu mengirim form Laravel normal sekali. External submit button pencarian juga tercakup.

Jika encoding gagal tetapi gambar berhasil didecode dan original <=10 MiB, original dipertahankan. Gambar tidak terbaca, kosong, tidak didukung, atau masih >10 MiB ditolak dengan pesan. Laravel tetap memvalidasi image, MIME, ukuran, dan jumlah file; AI menambahkan verify/container/decode safety. Client bukan security boundary.

Coverage: katalog search; admin biasa dan super admin di halaman detail item; SKU kosong/sudah berfoto; tambah design; camera/gallery/directory; single/multiple selection. Role/route permission tidak diubah. Form import Excel tidak disentuh.

## Database dan indexing

Migration baru `2026_09_12_120000_create_visual_references_table.php` membuat sidecar `visual_references`: FK photo_id cascade, pipeline, source path/hash, descriptors JSON, vector(512), HNSW cosine index. Migration lama tidak diubah. Local: migration sudah dijalankan, **17 reference berhasil**, 0 gagal.

Reference upload menyimpan foto display dahulu. Kegagalan AI meninggalkan foto utuh sebagai pending (belum ada sidecar valid) dan pesan pengguna menjelaskan perlunya reindex. Penulisan fitur lengkap atomic; path/hash diperiksa ulang saat selesai untuk mencegah stale indexing. Penghapusan foto otomatis menghapus sidecar melalui FK.

Reindex menggunakan keyset chunk, snapshot through-id, command lock, skip versi/path yang sudah selesai, progress, exit gagal bila ada kegagalan. Tidak menghapus foto. Gunakan tanpa `--after-id` untuk mencoba ulang kegagalan; completed otomatis dilewati. `--force` diperlukan jika sengaja mengganti isi file pada path yang sama. Reference baru dihitung saat upload, bukan saat query.

## Install dan menjalankan lokal

PHP/Composer/Boost existing dipakai. Dependency Python tambahan: NumPy, OpenCV headless untuk descriptor/image ops, ONNX Runtime 1.24.2 untuk model foreground. Tidak ada dependency frontend baru.

```powershell
# Di root aplikasi
composer install
npm install
npm run build
php artisan migrate
php artisan optimize:clear

# Di ai-service, aktifkan venv existing dahulu
python -m pip install -r requirements.txt
New-Item -ItemType Directory -Force models
Invoke-WebRequest https://github.com/danielgatis/rembg/releases/download/v0.0.0/u2netp.onnx -OutFile models/u2netp.onnx
$env:OBJECT_MODEL_PATH='models/u2netp.onnx'
$env:AI_CPU_THREADS='2'
python -m uvicorn app.main:app --host 127.0.0.1 --port 8001

# Terminal lain, root aplikasi
php artisan products:reindex-visual --chunk=50
php artisan products:reindex-visual --after-id=123 --chunk=50
php artisan products:calibrate-visual path/to/labelled.jsonl
```

Model CLIP existing perlu tersedia di cache atau diunduh sekali saat setup. Sesudah tersedia, `HF_HUB_OFFLINE=1` dapat memaksa penggunaan cache. Tidak ada foto dikirim ke API model eksternal.

Setiap baris JSONL kalibrasi:

```json
{"path":"/absolute/path/query.jpg","sha256":"64-character-actual-sha256","capture_group":"independent-photo-session","relevant_skus":["confirmed-sku"]}
{"path":"/absolute/path/unknown.jpg","sha256":"64-character-actual-sha256","capture_group":"different-photo-session","relevant_skus":[]}
```

`[]` berarti benar-benar tidak ada reference relevan; bukan label yang belum diketahui. Gunakan hash file asli, jangan placeholder di atas. Jangan masukkan reference identik sebagai query kalibrasi. SKU hanya label dataset, tidak menjadi rule pencarian. Gunakan foto baru di luar sesi tuning untuk acceptance.

Config `visual_search.php`: pipeline, timeout, candidates, ef_search, policy path. `.env.example` mendokumentasikan `AI_SERVICE_URL`, `AI_TIMEOUT`, `IMAGE_SEARCH_CANDIDATES`, `IMAGE_SEARCH_EF_SEARCH`, `IMAGE_SEARCH_POLICY_PATH`. Compression config terpusat di `imageSettings` frontend. Untuk maksimum 10 × 10 MiB, total request PHP/web server perlu cukup beserta multipart overhead; validasi tetap 10 MiB/file. PostTooLarge ditampilkan sebagai pesan, bukan stack trace. Tidak ada perubahan php.ini atau .env pengguna.

Saat laporan dibuat, sesi uji aktif menggunakan **http://127.0.0.1:8003**, AI **127.0.0.1:8002**, override environment per proses. PHP uji memakai upload temporary directory workspace karena direktori Temp bawaan tidak bisa membuat file. Ini tidak mengubah konfigurasi Herd permanen. Untuk memakai domain Herd, restart layanan AI yang digunakan domain tersebut dengan code baru dan policy/reindex yang sama.

## Validasi

- Laravel: **33 test, 114 assertion, lulus**, termasuk regression admin baseline, super admin multiple upload, permission, delete selected design, pending indexing/idempotency, compatibility, mismatch, dedup, no-match tanpa legacy refill, technical failure, dan penolakan full-table non-pgvector runtime.
- Python: **6 test lulus**: camera JPEG 6000×4000, decode invalid, orientasi EXIF, mask kosong, descriptor query/reference identik, transparency, mask invalid.
- Frontend native Node test: **4 test lulus**: dimensi/aspect/no-upscale, profile/orientation/PNG/cache/fallback, file invalid, sequential multi-file order/preview/submit-wait/double-submit.
- Vite build dan Pint lulus. Tidak menambah test ecosystem.
- Browser: dumbbell → kosong; obeng 6 inci → hanya 9079590; reference sunglasses → hanya 9056299. Fixture 4000×3000 JPEG **16.778.471 bytes** berubah menjadi `.webp`, berhasil melewati validasi server 10 MiB dan diproses AI. Ini fixture compression, bukan bukti akurasi produk.
- Pemeriksaan runtime penuh: delapan dumbbell → delapan hasil kosong; obeng 6 inci → 9079590; obeng 4 inci → 9079588; self-match sunglasses dan lunch box → masing-masing SKU benar. Self-match bukan held-out accuracy.

Pada 10 query berlabel, satu pengukuran/query: median AI **471 ms**, P95 **507 ms**; median keseluruhan PHP→AI→pgvector→verification **500 ms**, P95 **883 ms** (termasuk koneksi DB pertama). Tidak termasuk browser compression/upload/render. Sample process RSS AI sekitar **1215 MiB**, bukan peak terukur. Jangan jalankan banyak worker pada VPS RAM kecil karena masing-masing memuat model.

## Langkah acceptance manual

1. Buka aplikasi lokal, login memakai akun yang ada untuk pengujian admin.
2. Cari obeng 4/6 inci, lunch box, dan sunglasses dari foto baru. Periksa SKU teratas dan tidak ada SKU asing.
3. Foto produk sama di background berbeda dan dipegang tangan. Periksa bentuk produk tidak terpotong dan SKU tepat.
4. Foto dumbbell tanpa reference: pastikan pesan kosong, tanpa kandidat pengganti.
5. Pilih foto kamera besar: tunggu preview, konfirmasi; pastikan tidak muncul error ukuran.
6. Pada item, tambah beberapa foto/design dari kamera dan folder; periksa urutan, penyimpanan, preview, satu hasil/SKU saat search, dan deletion selected photo.
7. Ulangi upload dengan admin biasa dan super admin. Non-admin tetap dilarang.

Kamera perangkat fisik, UI admin login pada sesi browser ini, dan robustness foto baru belum diuji manual. Automated tests mencakup alur permission/upload; hardware camera perlu acceptance pengguna. Dataset lokal hanya 17 foto reference, sehingga ini bukan bukti recall ANN katalog 100.000 foto. Arsitektur runtime menggunakan index dan Top-K bounded; belum ada pengukuran recall skala tersebut pada data asli.

## File implementasi

- `resources/js/image-compression.js`, `resources/js/app.js`, hasil build JS/CSS/manifest.
- `resources/views/products/search.blade.php`.
- `app/Http/Controllers/ProductController.php`, `bootstrap/app.php`.
- `app/Services/VisualRepresentation.php`, `app/Services/VisualSearch.php`.
- `app/Console/Commands/ReindexVisualReferences.php`, `app/Console/Commands/CalibrateVisualSearch.php`.
- `config/visual_search.php`, `.env.example`.
- `database/migrations/2026_09_12_120000_create_visual_references_table.php`.
- `ai-service/app/visual.py`, `ai-service/app/main.py`, `ai-service/app/model.py`, `ai-service/requirements.txt`, `ai-service/.gitignore`.
- `tests/Feature/VisualSearchTest.php`, update fixture `AdminUserTest.php`, `tests/frontend/image-compression.test.mjs`, `ai-service/tests/test_visual.py`.
- Laporan ini. Model, labelled local manifest, policy dan smoke-test output hanya di storage/model lokal yang diabaikan Git.
