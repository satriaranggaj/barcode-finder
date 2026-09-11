# Image Search Phase 2–6 — laporan lokal

> Laporan historis object-v1. Hasil object-v2, benchmark CLIP/DINO pada foto SKU nyata, adaptive reranking, dan pemeriksaan pgvector lokal terbaru ada di [OPTIMIZATION.md](OPTIMIZATION.md). Klaim PostgreSQL belum tersedia di bawah hanya berlaku pada checkpoint lama.

Tanggal: 11 September 2026. Pekerjaan hanya pada codebase lokal. Tidak ada SSH, deployment, migration, reindex, perubahan gambar, atau restart service production.

**Status: implementasi dan pengujian lokal tersedia; acceptance peningkatan akurasi belum tercapai.** Default tetap `legacy`. Dataset diagnostik tidak menunjukkan kenaikan Top-1. Pengurangan gangguan tangan/rak dan pembedaan SKU yang sangat mirip masih **needs production validation** dengan foto query independen dan label SKU terverifikasi. Jangan mengaktifkan seluruh pipeline hanya karena test software lulus.

## 1. Arsitektur

```mermaid
flowchart TD
    U[Camera / gallery / multiple upload] --> S[Storage optimizer Phase 1]
    U --> L[Embedding original /embed]
    S --> P[Foto tersimpan]
    L --> E[product_photos.embedding: legacy]
    P --> R[Reindex eksplisit atau indexing upload opsional]
    R --> V[Validasi / EXIF / bounded decode]
    Q[Foto query] --> C{Pipeline aktif dan reference lengkap?}
    C -->|Tidak| LQ[/embed + retrieval legacy]
    C -->|Ya| V
    V --> G[GrabCut + pemilihan komponen utama]
    G -->|Hasil meragukan / error| O[Gambar utuh]
    G -->|Diterima| B[Kurangi background + crop]
    O --> N[Letterbox normalization]
    B --> N
    N --> F[CLIP 512 + descriptor ringan]
    F --> RF[Reference: product_photo_features object-v1]
    F --> QF[Query features]
    QF --> ANN[pgvector cosine HNSW Top 30–100 foto]
    RF --> ANN
    ANN --> RR[Reranking opsional Top-N]
    RR --> SKU[Ambil foto terbaik, satu hasil per SKU]
    LQ --> SKU
    M[Marker ukuran diketahui + 4 sudut + konfirmasi sebidang] --> PM[CLI pengukuran bidang terpisah]
```

- Phase 1 optimizer, validator GD, model foto, camera/gallery UI, preview, slider, dan migration lama tidak diubah.
- Upload tetap menghasilkan embedding original. Bila indexing baru aktif, fitur baru dihitung dari file hasil optimizer (sama dengan input reindex). Kegagalan ekstraksi baru membuat foto pending, bukan menggagalkan upload. Kegagalan layanan embedding legacy masih mengikuti flow existing.
- `product_photo_features` merupakan sidecar: satu baris per foto dan versi, vector 512, hash sumber, path sumber, metadata preprocessing/model/descriptor/timing. Foreign key menghapus fitur saat foto dihapus. Embedding legacy tetap tersedia untuk rollback.
- Query memakai pipeline baru hanya setelah tidak ada foto pending untuk versi/path aktif. Jika fitur query gagal, seluruh query kembali ke embedding + reference legacy. Tidak membandingkan query segmented terhadap vector legacy.
- Foto baru yang belum terindex menyebabkan fallback katalog ke legacy sampai reindex selesai. Ini pilihan konsistensi yang konservatif, bukan pencampuran vector.
- Inference satu per worker, antre maksimal dua detik sebelum 503, PyTorch dua thread default, OpenCV satu thread. Worker production harus satu. Model dimuat sekali per proses, memakai `eval()` dan `torch.inference_mode()`.

## 2. Boundary tiap phase

| Phase | Implementasi | Bukti lokal | Batasan |
|---|---|---|---|
| 2 | GrabCut, komponen utama berdasarkan area/posisi, guard kualitas geometris, suppression background 80%, crop dengan padding, letterbox, fallback utuh; sidecar versioning dan reindex | 5 test preprocessing awal; 31 test Laravel terkait upload/reindex pada checkpoint | Bukan semantic detector; tangan yang menyatu dengan produk dapat tetap masuk mask. Confidence merupakan heuristik, bukan probabilitas model |
| 3 | Cosine `<=>`, vector(512), HNSW `vector_cosine_ops`, limit kandidat sebelum join, best photo/group SKU, fallback katalog konsisten | 9 test integrasi checkpoint; adapter SQLite khusus testing | PostgreSQL EXPLAIN belum dieksekusi; Docker engine lokal tidak berjalan. HNSW recall perlu diukur |
| 4 | HSV histogram, radial silhouette, aspect ratio, LBP + gradient orientation, ORB maksimum 32 descriptor; fusion configurable | 12 test Laravel terkait + 7 test Python checkpoint | Bobot tambahan default nol; belum terbukti membantu. ORB tidak melakukan verifikasi geometri patch |
| 5 | Ekstraksi OLD/NEW CPU, manifest berlabel, Top-1/5/10, Recall@K, MRR@K, kasus per kategori, 30/50/75/100, score memakai implementasi PHP yang sama | Benchmark CLIP aktual pada 8 reference/16 variasi diagnostik, plus test kalkulasi metric | Bukan benchmark SKU held-out atau ANN scale; tidak dapat menyimpulkan accuracy production |
| 6 | CLI marker ArUco + homography bidang, input empat sudut objek, konfirmasi coplanar | 3 test pengukuran sintetis | Belum ada UI pengukuran; bukan ukuran 3D, ketebalan, atau cm dari pixel tanpa skala |

## 3. File penting

Semua path di bawah relatif terhadap root repo.

- `ai-service/app/preprocessing.py`: validasi/decode, segmentasi, crop/fallback/normalisasi.
- `ai-service/app/descriptors.py`: descriptor ringan precomputed, versi `visual-v1`.
- `ai-service/app/model.py`: CLIP existing, revision dipin, inference mode dan thread bound.
- `ai-service/app/main.py`: `/embed` kompatibel, `/features`, health metadata, concurrency dan error aman.
- `ai-service/app/measurement.py`, `ai-service/measure.py`: pengukuran bidang terpisah.
- `ai-service/benchmark.py`, `benchmark-preprocessing.py`, `benchmark-manifest.example.json`: benchmark CPU dan manifest.
- `ai-service/requirements.txt`, `requirements-dev.txt`, `.gitignore`: dependency dan output lokal.
- `app/Services/ImageFeatureClient.php`: kontrak model/version/vector, sidecar persistence dan query pending.
- `app/Services/ImageSearch.php`: kandidat bounded, grouping, logging dan pemanggilan reranker.
- `app/Services/VisualReranker.php`: score fusion dan pembanding descriptor.
- `app/Http/Controllers/ProductController.php`: integrasi upload opsional, query dan fallback.
- `app/Console/Commands/ReindexProductImages.php`: reindex aman/resumable.
- `app/Console/Commands/BenchmarkImageSearch.php`: benchmark offline melalui scorer production.
- `config/image_search.php`, `.env.example`, `storage/framework/.gitignore`.
- `tests/Feature/ImagePipelineTest.php`, `VisualRerankerTest.php`, `ai-service/tests/*`, `tests/pgvector-image-search.sql`.

## 4. Dependency dan model

Tidak ada dependency PHP atau frontend baru. Tidak perlu build frontend untuk perubahan ini.

Python tambahan runtime: `opencv-python-headless==4.13.0.92`; `numpy>=2,<3` kini dinyatakan langsung (sebelumnya tersedia sebagai dependency transitif). Test/benchmark opsional: `httpx`, `psutil==7.2.2`. CLIP/PyTorch/Transformers/Pillow/FastAPI tetap stack existing; tidak menambahkan SAM, detector besar, ONNX runtime, atau GPU requirement.

Environment pengukuran: Windows 11, Intel Family 6 Model 158, Python 3.13.1, torch 2.14.0, transformers 5.16.1, Pillow 12.3.0, NumPy 2.5.2, FastAPI 0.141.1. Versi baseline production bisa berbeda; gunakan virtualenv baru untuk validasi dependency sebelum mengganti service.

Model: `openai/clip-vit-base-patch32`, revision `3d74acf9a28c67741b2f4f2ea7635f0aaf6f0268` (revision cache existing lokal yang dipakai benchmark). 151.277.313 parameter; tensor model FP32 **605.109.252 byte / sekitar 577 MiB**. Ini ukuran tensor, bukan total ukuran virtualenv/cache. Process RSS setelah benchmark **885,6 MiB**, peak working set **907,2 MiB**. Startup sekitar **14,18 detik**; CPU dua thread. Bukan estimasi RSS server Ubuntu atau beban concurrent.

GrabCut tidak memiliki file bobot/model tambahan. OpenCV wheel Windows yang diunduh sekitar 40,1 MB; ukuran Linux dan hasil instalasi berbeda.

Evaluasi pilihan (screening dokumentasi, bukan benchmark head-to-head):

| Pilihan | Pertimbangan | Keputusan saat ini |
|---|---|---|
| OpenCV GrabCut | Tanpa bobot tambahan; bounded resize; perlu seed area dan dapat menggabungkan tangan/produk | Implementasi eksperimen awal, dinonaktifkan default |
| U2NetP/rembg | Model saliency ringan, tetapi foreground tidak otomatis berarti SKU/produk, perlu dependency/model baru | Belum ditambahkan tanpa dataset pembuktian |
| MobileSAM | Model ringan dibanding SAM; tetap perlu prompt/selection untuk menentukan produk | Kandidat lanjutan bila dataset membuktikan GrabCut kurang memadai; belum dibenchmark lokal |
| FastSAM | Segment-anything/proposal masih membutuhkan pemilihan objek | Belum dibenchmark atau dipasang |
| SAM/SAM2, Grounding DINO/Florence | Menambah model dan kompleksitas deployment; keuntungan pada kasus Lensku belum diketahui | Tidak ditambahkan berdasarkan popularitas |

Sumber primer: [OpenCV GrabCut](https://docs.opencv.org/4.13.0/dd/dfc/tutorial_js_grabcut.html), [rembg U2NetP](https://github.com/danielgatis/rembg/blob/main/rembg/sessions/u2netp.py), [MobileSAM](https://github.com/ChaoningZhang/MobileSAM), [FastSAM](https://github.com/CASIA-LMC-Lab/FastSAM), [pgvector](https://github.com/pgvector/pgvector), [OpenCV homography](https://github.com/opencv/opencv/blob/4.x/doc/tutorials/features2d/homography/homography.markdown). Tidak memakai angka latency GPU dari paper sebagai klaim CPU VPS.

## 5. Migration baru

1. `2026_09_11_072456_create_product_photo_features_table.php`: tabel sidecar, unique foto/version, FK cascade; PostgreSQL vector(512), SQLite text untuk test.
2. `2026_09_11_073004_add_image_search_hnsw_indexes.php`: concurrent HNSW untuk legacy dan partial index `object-v1` untuk fitur baru; pemeriksaan invalid index setelah build.

Tidak ada migration production lama yang diedit. Migration tidak membaca/mengekstrak ulang foto. Index build dapat memerlukan waktu, disk, dan RAM untuk 100k vector; jadwalkan pada beban rendah. Concurrent index migration berada di luar transaction dan perlu ditangani khusus bila build dibatalkan. Jangan menghapus index valid saat retry; hanya invalid index yang disebut error.

## 6. Konfigurasi baru

Laravel `.env` (default aman):

```dotenv
AI_SERVICE_URL=http://127.0.0.1:8001
IMAGE_SEARCH_PIPELINE=legacy
IMAGE_SEARCH_INDEX_UPLOADS=false
IMAGE_SEARCH_CANDIDATES=50
IMAGE_SEARCH_EF_SEARCH=100
IMAGE_SEARCH_WEIGHT_GLOBAL=1
IMAGE_SEARCH_WEIGHT_SHAPE=0
IMAGE_SEARCH_WEIGHT_COLOR=0
IMAGE_SEARCH_WEIGHT_TEXTURE=0
IMAGE_SEARCH_WEIGHT_LOCAL=0
IMAGE_SEARCH_WEIGHT_PROPORTION=0
```

`AI_SERVICE_URL` sudah dibaca existing config; kini dicantumkan di `.env.example`. `AI_SEARCH_MIN_SIMILARITY` existing tetap .72; threshold belum dituning untuk distribution baru. `IMAGE_SEARCH_CANDIDATES` dibatasi 1–100; `ef_search` minimal jumlah kandidat dan maksimal 1000. Default 50 merupakan provisional, bukan hasil optimasi skala 100k.

Environment proses Python (bukan otomatis dibaca dari Laravel `.env`):

```dotenv
AI_CPU_THREADS=2
AI_DECODE_BUDGET_MB=384
```

Guard AI: 20 MiB encoded, 80 juta pixel maksimum, hanya JPEG/PNG/WebP single-frame; pipeline `/features` menambahkan estimasi decode 12 byte/pixel + 2 × encoded bytes, budget 384 MiB default. JPEG pipeline baru memakai draft decode sebelum RGB. `/embed` mempertahankan decode original tanpa penolakan baru akibat processing budget, sehingga foto valid yang sudah berhasil pada Phase 1 tidak ditolak oleh budget fitur baru. Biaya RAM full decode legacy tetap ada, dibatasi concurrency satu; test foto smartphone production tetap diperlukan. Guard GD Phase 1 tidak berubah. Jangan menaikkan budget untuk menutupi kekurangan RAM VPS. Batasi worker dan request simultan di service.

Konstanta algoritma `object-v1` dan descriptor `visual-v1` bukan knob per-request. Jika mengubah ukuran, threshold mask, model, revision atau algoritma, buat versi baru dan reindex; jangan mencampur reference versi lama dengan query baru.

## 7. API dan command

- `POST /embed`: multipart field `image`; tetap `success`, `dimensions`, `embedding`; tambahan timing. Tanpa segmentasi.
- `POST /features`: multipart field yang sama; vector 512, version, embedding_model, model_revision, preprocessing mode/reason/bbox, descriptors, timing.
- `GET /health`: status model, revision, versi preprocessing, ketersediaan OpenCV. Bukan jaminan kualitas segmentasi.
- `/features` fallback ke gambar utuh jika segmentasi tidak layak; descriptor failure dicatat `unavailable`. Invalid/unsafe image 422, oversize encoded 413, inference busy/unavailable 503. Detail error internal tidak dikirim.
- Tidak menambah endpoint publik Laravel atau endpoint ukuran fisik.

```bash
php artisan products:reindex-images --dry-run --limit=5 --chunk=5 --sleep=250
php artisan products:reindex-images --limit=5 --chunk=5 --sleep=250
php artisan products:reindex-images --after-id=123 --chunk=100 --sleep=250
```

Dry-run benar-benar menjalankan ekstraksi untuk memvalidasi, tetapi tidak menulis fitur/foto. Resume memakai ID terakhir pada output, bukan nomor urut foto. Untuk retry baris gagal, hilangkan `--after-id`; yang selesai dilewati. Hanya mengambil id/path dalam chunk, tanpa offset atau seluruh vector. Snapshot through-id membatasi run; foto baru setelah snapshot diproses run berikutnya. Lock mencegah dua maintenance pada host yang sama. Inference di luar transaction; update memakai row lock dan memeriksa path/hash agar tidak menyimpan hasil dari sumber yang berubah.

Foto yang dimodifikasi di luar aplikasi dengan path yang sama tidak otomatis terdeteksi oleh readiness check; jangan mengedit file in-place. Command storage yang mengganti path membuat fitur pending untuk reindex. Fallback segmentasi dianggap hasil valid versi tersebut; kualitas fallback dianalisis dari metadata, bukan failure count.

## 8. Benchmark OLD vs NEW

Artefak agregat: `benchmark-default.json`, `benchmark-experimental.json`, `preprocessing-sizes.json` di direktori ini. Raw foto/output feature tetap lokal di direktori benchmark yang di-ignore.

Dataset diagnostik: 8 foto lokal; 16 query berupa perubahan brightness/rotasi sintetis. Label merupakan identitas foto, **bukan SKU terverifikasi**. Bisa ada dua foto barang sama dengan label fixture berbeda. Tidak ada query tangan/rak independen. Angka berikut tidak boleh digunakan sebagai acceptance accuracy production.

| Metric pada K=50 | OLD | NEW default | NEW bobot eksperimen |
|---|---:|---:|---:|
| Top-1 | 87,5% | 87,5% | 87,5% |
| Top-5 / Top-10 | 100% / 100% | 100% / 100% | 100% / 100% |
| Recall@K | 100% | 100% | 100% |
| MRR@K | 0,9375 | 0,9375 | 0,9375 |
| Reranking rata-rata | ~0,03 ms | ~0,03 ms | ~32,05 ms |

Bobot eksperimen: global=1, color=.15, shape=.05, texture=.1, local=.1, proportion=.05. Hanya eksperimen, tidak dijadikan default. Tidak menunjukkan keuntungan pada dataset kecil ini. K=30/50/75/100 memberikan hasil sama karena reference hanya 8; **bukan bukti K optimal**.

CPU inference query, median / maksimum:

| Tahap | OLD | NEW |
|---|---:|---:|
| Validasi + decode | 9,84 / 13,14 ms | 11,00 / 14,18 ms |
| Preprocessing | — | 482,44 / 1212,91 ms |
| Embedding | 80,12 / 98,94 ms | 77,82 / 83,48 ms |
| Descriptor | — | 10,28 / 13,99 ms |
| Total di AI | 90,68 / 110,54 ms | 580,35 / 1314,06 ms |

Total ini tidak mencakup transfer multipart, PHP, pgvector, browser atau latency VPS. Durasi validasi/decode dan deteksi/segmentasi dilaporkan gabungan sesuai implementasi, tidak mengarang rincian yang belum diinstrumentasi. PHP mencatat retrieval/rerank dan server processing request secara terpisah.

Uji resize pada 8 reference: 256px median 201ms, 384px 585ms, 512px 972ms; masing-masing menghasilkan mask accepted 8/8. Accepted mask bukan bukti mask benar. 384 merupakan kompromi sementara resolusi/detail sebelum CLIP 224 dan biaya CPU; 512 lebih mahal tanpa pembuktian accuracy. Tidak mengecilkan semua ke 256 hanya untuk mengejar speed sebelum benchmark held-out.

Reproduksi pada dataset berlabel:

```bash
cd ai-service
python benchmark.py /path/manifest.json --output benchmark-output/features.json
python benchmark-preprocessing.py /path/manifest.json --output benchmark-output/sizes.json
cd ..
php artisan images:benchmark ai-service/benchmark-output/features.json --output=report.json
```

`benchmark-manifest.example.json` menunjukkan format. Pisahkan data tuning dan test; query harus foto independen, bukan reference yang sama. Isi seluruh kasus A–O: background, tangan, jarak, sudut, cahaya, warna/bentuk mirip, tekstur, motif kecil, proporsi, occlusion, rak padat, objek kecil, objek memenuhi frame, packaging mirip. Tambahkan SKU negatif yang tidak ada reference. Benchmark offline dibatasi 1000 reference/query dan file 64 MiB; production retrieval menggunakan pgvector, bukan evaluator offline ini.

## 9. Pgvector / skala

Prasyarat PostgreSQL: pgvector dengan dukungan HNSW (minimal 0.5); periksa versi extension sebelum migration. Index: HNSW dengan `vector_cosine_ops`; urutan `embedding <=> query` ascending dan LIMIT di subquery. Similarity `1 - distance`. Tidak meletakkan fungsi similarity sebagai ORDER BY ANN. Foto difilter dengan path aktif setelah kandidat terbatas, lalu descriptor hanya dibaca untuk ID kandidat jika bobot tambahan aktif.

Test lokal reindex memakai **100.000 baris fitur selesai + satu pending**, chunk=1; hanya satu request AI, tambahan memory PHP di bawah 16 MiB. Itu bukti bounded maintenance, bukan benchmark vector database. Fitur JSON berukuran beberapa KB/foto dan vector 2048 byte mentah; 100k reference membutuhkan ratusan MiB hingga lebih dari 1 GiB termasuk JSON/index/overhead. Ukur `pg_total_relation_size`, jangan menjadikan ukuran model sebagai ukuran keseluruhan sistem.

`tests/pgvector-image-search.sql` menyediakan fixture temporer 100k vector, natural EXPLAIN ANALYZE/BUFFERS dan pembanding exact/ANN. Belum dijalankan di sesi ini karena Docker engine PostgreSQL lokal tidak tersedia. Jangan mengklaim index telah digunakan. Jalankan di database disposable lokal terlebih dahulu. Saat validasi, ambil EXPLAIN query `ImageSearch::candidates` yang sebenarnya dengan vector nyata. Pada 40 reference, sequential scan dapat merupakan pilihan planner yang benar; evaluasi indeks pada 50k/100k dan jangan memaksa seqscan off untuk mengklaim performa.

## 10. Pengukuran fisik terpisah

Cetak ArUco `DICT_4X4_50`, ID 0, dengan sisi marker hitam yang benar-benar terukur (bukan termasuk margin putih). Tempatkan sebidang dengan permukaan objek. Tentukan empat sudut boundary berurutan pada gambar tegak setelah EXIF. Contoh CLI:

```bash
cd ai-service
python measure.py image.jpg --marker-cm=5 --marker-id=0 --coplanar \
  --corners='[[250,200],[448,200],[448,299],[250,299]]'
```

Koordinat contoh bukan koordinat gambar Anda. Tanpa marker atau konfirmasi sebidang, command gagal dan tidak mengeluarkan cm. Hasil adalah sisi panjang/pendek bounding rectangle pada bidang, bukan tinggi objek, kedalaman, atau ukuran produk 3D. Lens distortion, cetakan salah skala, sudut manual, dan ketidaksebidangan membatasi akurasi. Kalibrasi kamera atau depth/multiview belum diimplementasikan. Normal search hanya memakai proporsi visual.

## 11. Backup dan deployment bertahap — untuk dilakukan pengguna setelah review

Langkah berikut merupakan rencana, tidak dijalankan dalam sesi ini. Jangan gabungkan seluruh tahap menjadi satu command.

1. **Backup**: simpan `pg_dump` format custom, snapshot `storage/app/public`, release code, `.env` secara aman, service unit, `pip freeze`, dan model cache. Pastikan DB dan foto konsisten melalui snapshot/brief write pause. Uji restore di database/direktori terpisah; jangan overwrite data aktif. Catat release baseline Phase 1.
2. **Deploy code dengan flag off**: `IMAGE_SEARCH_PIPELINE=legacy`, `IMAGE_SEARCH_INDEX_UPLOADS=false`, semua bobot tambahan nol. Upload/search legacy harus tetap bekerja. Rollback: release baseline + config sebelumnya.
3. **Dependency di virtualenv terpisah**: instal stack existing yang kompatibel dan tambahan OpenCV/NumPy; untuk CPU gunakan distribusi PyTorch CPU yang sesuai. Contoh `python -m pip install -r requirements.txt`; jangan upgrade environment service aktif secara membabi buta. Validasi import dan model revision sebelum pengalihan service.
4. **Migration baru**: review `php artisan migrate --pretend`, backup sudah valid, lalu jalankan migration melalui prosedur deployment Anda. Tidak ada reindex otomatis. Periksa kedua index `indisvalid`. Biarkan tabel sidecar saat rollback aplikasi; tidak perlu drop data untuk rollback.
5. **Validasi service baru**: bind hanya `127.0.0.1`; satu worker, contoh `uvicorn app.main:app --host 127.0.0.1 --port 8001 --workers 1 --limit-concurrency 4`. Jalankan candidate service pada port internal berbeda untuk smoke test sebelum mengganti unit aktif. Periksa `/health`, `/embed`, `/features`, foto kecil, smartphone, korup. Atur environment thread/budget pada unit service. Hindari publikasi port AI melalui Nginx/internet.
6. **Small reindex**: dry-run 5 foto, kemudian apply 5 foto, chunk kecil, sleep 250ms. Periksa mode/mask dan kemampuan search pada dataset evaluasi. Pipeline publik tetap legacy. Rollback: biarkan sidecar tidak dipakai, kembali service/config lama.
7. **±40 reference existing**: aktifkan `IMAGE_SEARCH_INDEX_UPLOADS=true` setelah API tervalidasi; reindex sisa foto, pantau failed count, RAM/RSS, CPU, disk, PHP log. Retry gagal tanpa after-id. Jangan menghapus/mengkompres ulang foto sebagai bagian langkah ini.
8. **Validasi query baru terbatas**: benchmark held-out dengan reference baru, test query kamera/gallery/background/tangan/rak dan SKU mirip. Periksa EXPLAIN dan ANN recall. Sesudah semua reference lengkap, aktivasi terbatas `IMAGE_SEARCH_PIPELINE=object-v1` dengan bobot tambahan nol; refresh Laravel config cache sesuai prosedur existing.
9. **Benchmark/tuning**: bobot rerank hanya dinaikkan berdasarkan dataset tuning lalu dibuktikan pada held-out. Ukur p50/p95 CPU/RSS/end-to-end di VPS dengan service lain aktif. Jika benefit tidak nyata, tetap legacy atau global-only.
10. **Full enablement**: hanya setelah accuracy/latency/resource memenuhi hasil review. Tetap pertahankan vector legacy dan backup untuk rollback cepat.

Rollback setiap tahap: kembalikan `IMAGE_SEARCH_PIPELINE=legacy`, `IMAGE_SEARCH_INDEX_UPLOADS=false`, bobot tambahan nol, refresh config cache. Bila masalah berasal dari AI runtime baru, kembalikan virtualenv/service baseline. Bila perlu rollback code, gunakan release baseline; sidecar dan index tambahan boleh tetap ada. Jangan menjalankan `migrate:rollback` massal karena dapat menyentuh migration lain. Tidak ada kebutuhan restore/delete foto untuk mematikan pipeline baru.

## 12. Checklist validasi dan monitoring

- Login, admin/super_admin, authorization/CSRF, SKU/description CRUD, import Excel, text search tetap benar.
- Camera/gallery, single/multiple upload, preview, gambar smartphone, WebP preserve/compression, slider dots dan foto lama tetap benar.
- Upload dengan layanan fitur baru gagal tetap menyimpan foto + embedding legacy; pending dapat direindex.
- Reindex dry-run tidak menulis, apply dapat dihentikan/dilanjutkan, retry tanpa duplikasi, source berubah tidak menyimpan fitur stale.
- Reference/query memakai versi/model/revision sama; seluruh query fallback legacy saat katalog pending; tidak ada duplikasi SKU.
- Periksa `image_features_pending`, `image_reindex_failed`, `image_search_legacy_fallback`, `image_search`, `image_search_request` serta logger `lensku.ai`. Tidak ada log raw embedding.
- Periksa distribusi metadata mode/reason, failed count, p50/p95 server processing, retrieval/rerank timing, RSS, swap, load CPU, 503 busy, ukuran relation/index, dan efek terhadap aplikasi lain.
- Ukuran cm hanya muncul pada CLI marker dengan konfirmasi sebidang; jangan menganggap ukuran visual sebagai ukuran nyata.
- Acceptance akhir yang belum terbukti: pengurangan tangan/rak, peningkatan accuracy SKU sulit, ANN recall/EXPLAIN pada skala target, latency Ubuntu VPS, dan pengukuran fisik dunia nyata. Semuanya **needs production validation**.

## 13. Pengujian

Baseline sebelum perubahan: 61 test Laravel / 257 assertion. Hasil final: **77 test Laravel lulus / 314 assertion**, **15 test Python lulus**, Pint lulus, dan `git diff --check` lulus. Test tambahan mencakup scale 100k, upload fitur sukses/gagal, lock, metric benchmark, API failure/busy, segmentasi/fallback/corrupt, descriptor, determinisme, decode smartphone dan kompatibilitas budget legacy, serta pengukuran bidang. API test menggunakan model stub untuk isolasi; benchmark di atas memakai CLIP sesungguhnya.

Perintah lokal:

```bash
php artisan test --compact
php vendor/bin/pint --dirty --format agent
cd ai-service
python -m unittest discover -s tests -v
```

Tidak ada test lama dihapus. Tidak ada perubahan frontend, sehingga build frontend tidak diperlukan. Tidak ada test PostgreSQL yang dianggap lulus melalui adapter SQLite.


