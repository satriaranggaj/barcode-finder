# Optimasi accuracy + speed — object-v2

11 September 2026. Lokal saja; tidak ada deployment, perubahan data/gambar production, atau overwrite embedding legacy.

**Acceptance belum tercapai: NEW Top-1 belum lebih tinggi daripada legacy. Default tetap legacy; bobot descriptor tambahan tetap nol.**

## Data dan batas bukti

Snapshot katalog saat ekspor benchmark berisi 15 foto dari 12 SKU. Dipakai 12 reference, satu foto tuning, dan dua foto evaluasi yang berbeda byte dari reference. Query evaluasi: kacamata dipegang tangan di depan rak (foto 15) dan sandal di lantai (foto 16). Tidak ada variasi sintetis dalam pengukuran ini. Identitas SKU berasal dari katalog; independensi sesi pemotretan belum terverifikasi. Tiga SKU obeng ada sebagai distractor reference, tetapi belum ada query independen obeng. Tidak ada query negatif (barang di luar katalog).

Pemeriksaan akhir melihat katalog sudah berisi 17 foto dari 13 SKU, termasuk dua foto ARTIFICIAL ROSE yang masuk setelah snapshot. Angka benchmark di laporan ini tetap merujuk manifest yang dibekukan sebelum run, bukan seluruh katalog yang terus berubah.

Dua query bukan benchmark acceptance atau bukti robustness umum. Top-1 100% hanya berarti 2/2. Tidak ada tuning bobot, pemilihan model, atau threshold berdasarkan dua foto evaluasi. Manifest menolak duplicate SHA256 lintas split. Top20/30/50 berisi seluruh 12 reference pada dataset kecil ini, sehingga tidak dapat memilih K production dari hasil tersebut.

## Perbandingan

Waktu dalam ms, komposisi pengukuran CPU AI + scoring PHP offline. Reranking memasukkan pengukuran decode/preprocess/descriptor kedua karena endpoint stateless. **Bukan latency HTTP end-to-end:** belum termasuk antrean, transfer, pgvector, hydration, dan render. Setiap query diukur tiga kali (enam sampel waktu); P95 sangat tidak stabil pada jumlah sekecil ini. RSS adalah proses model setelah benchmark, bukan tambahan RAM per request, dan bukan angka server production.

| Pipeline | Top1 | Top5 | MRR | Median ms | P95 ms | RSS MiB |
|---|---:|---:|---:|---:|---:|---:|
| CLIP original | 100% | 100% | 1.00 | 120.2 | 157.0 | 863.6 |
| CLIP object-v2 | 100% | 100% | 1.00 | 186.3 | 244.5 | 863.6 |
| CLIP v2 + shape | 100% | 100% | 1.00 | 277.3 | 303.2 | 863.6 |
| CLIP v2 + texture | 100% | 100% | 1.00 | 281.7 | 305.7 | 863.6 |
| CLIP v2 + local | 100% | 100% | 1.00 | 324.5 | 344.6 | 863.6 |
| CLIP v2 + color | 100% | 100% | 1.00 | 268.5 | 312.6 | 863.6 |
| CLIP v2 + proportion | 100% | 100% | 1.00 | 258.7 | 312.9 | 863.6 |
| CLIP v2 + adaptive local | 100% | 100% | 1.00 | 318.3 | 347.4 | 863.6 |
| DINOV2 original | 100% | 100% | 1.00 | 113.7 | 125.2 | 604.9 |
| DINOV2 object-v2 | 100% | 100% | 1.00 | 173.8 | 191.0 | 604.9 |
| DINOV2 v2 + shape | 100% | 100% | 1.00 | 244.5 | 290.7 | 604.9 |
| DINOV2 v2 + texture | 100% | 100% | 1.00 | 253.3 | 295.9 | 604.9 |
| DINOV2 v2 + local | 100% | 100% | 1.00 | 291.7 | 332.6 | 604.9 |
| DINOV2 v2 + color | 100% | 100% | 1.00 | 253.6 | 282.8 | 604.9 |
| DINOV2 v2 + proportion | 100% | 100% | 1.00 | 247.7 | 272.3 | 604.9 |
| DINOV2 v2 + adaptive local | 100% | 100% | 1.00 | 173.9 | 191.1 | 604.9 |

Bobot ablation yang dideklarasikan sebelum penilaian: global=1 dan satu descriptor=0.25. Ini hipotesis eksperimen, bukan bobot hasil tuning atau rekomendasi production. Adaptive menggunakan similarity 0.90 dan margin 0.08 sebagai heuristik routing, bukan calibrated confidence atau relevance threshold. CLIP mererank 2/2 query; DINO 0/2. Tidak ada descriptor yang meningkatkan hasil pada sampel ini; semua tetap nonaktif secara default. Tidak ada perpindahan runtime ke DINO.

## Profile preprocessing foto asli

Pengukuran dari run CLIP yang sama agar perbandingan tidak memakai run berbeda.

| Tahap | object-v1 median ms | object-v2 median ms |
|---|---:|---:|
| resize_ms | 17.070 | 7.760 |
| prior_ms | — | 2.981 |
| grabcut_ms | 900.892 | 65.529 |
| component_selection_ms | 0.682 | 0.595 |
| crop_ms | 7.810 | 0.789 |
| normalization_ms | 0.077 | 0.069 |
| preprocessing_ms | 927.784 | 75.409 |

Median preprocessing turun dari 927.784 menjadi 75.409 ms (sekitar 12.3× lebih cepat). AI object-v1 termasuk seluruh descriptor: median 1085.7 / P95 1350.3 ms. Fast AI object-v2 tanpa descriptor: median 185.9 / P95 244.1 ms. CLIP original tetap lebih cepat daripada v2 pada sampel ini; tidak ada klaim v2 lebih akurat atau lebih cepat daripada legacy.

## Audit crop

WORK_SIZE tetap 256, SEGMENT_SIZE tetap 160 dan GrabCut satu iterasi. Background bersih menggunakan prior murah tanpa GrabCut. Crop diterima hanya jika seluruh guard lolos: foreground 3–88%, dominance ≥80%, jarak pusat ternormalisasi ≤0.28, tidak menyentuh band tepi 3% (minimal 2 px), bbox fill ≥18%, compactness ≥0.015. Fragmentation dicatat sebagai 1−dominance. Ini batas geometris konservatif, bukan probabilitas dan bukan semantic product identification.

Pada 12 reference: enam crop diterima, enam fallback. Kacamata query foto 15 fallback karena mask menyentuh tepi; sandal query foto 16 diterima. Ini belum membuktikan tangan terpisah dari produk. Fallback mempertahankan seluruh frame dengan resize/letterbox; file original tidak diubah. Metadata mencatat semua metrik dan failed_checks; mask meragukan tidak menghasilkan descriptor shape palsu.

Pemeriksaan visual output foto 14/15/16: seluruh produk tetap terlihat. Crop foto tuning 14 masih menyertakan jari yang memegang kacamata, walaupun guard geometris lolos. Foto 15 mempertahankan tangan/rak melalui fallback. Foto 16 memusatkan sandal dengan sedikit latar lantai tersisa. Jadi guard mengurangi keputusan crop berisiko, tetapi tidak menyelesaikan pemisahan tangan secara semantik.

## Model

| Model | Parameter terukur | Dimensi | RSS / peak MiB |
|---|---:|---:|---:|
| CLIP ViT-B/32 | 151,277,313 | 512 | 863.6 / 977.3 |
| DINOv2-small | 22,056,576 | 384 | 604.9 / 716.1 |

CLIP memakai model existing penuh (termasuk text encoder), revision 3d74acf9a28c67741b2f4f2ea7635f0aaf6f0268. DINOv2-small memakai CLS normalized, revision ed25f3a31f01632728cabb09d1542f84ab7b0056. Kedua model diukur pada proses terpisah, CPU dua thread, OpenCV satu thread, setelah warm-up. DINO startup 104.6 detik termasuk unduhan pertama; jangan dibandingkan dengan startup CLIP cache 14.6 detik. Windows lokal, bukan proyeksi Ubuntu VPS.

DINO lebih kecil dalam pengukuran ini tetapi belum terbukti lebih akurat untuk SKU mirip. Karena itu tidak ditambahkan ke service runtime, tidak dibuat migration embedding DINO, dan vector legacy 512 tidak disentuh. Referensi model: [DINOv2-small](https://huggingface.co/facebook/dinov2-small), [CLIP](https://huggingface.co/openai/clip-vit-base-patch32).

## Fast path dan filtering

Query object-v2 memanggil /features dengan details=false. Hanya jika ada bobot tambahan aktif dan kandidat SKU berbeda ambigu, /descriptors menghitung sinyal yang diminta. Foto berbeda dengan SKU sama tidak dianggap pesaing. Reference descriptor dibaca hanya untuk kandidat reranking dan sudah dihitung saat indexing; tidak membaca/decode foto reference pada query. Kegagalan descriptor mempertahankan ranking global hasil query baru.

Pemeriksaan kelengkapan reference sebelum query masih memakai anti-join `pending()->exists()`. Pada katalog yang seluruhnya selesai, pemeriksaan ini tetap dapat membaca banyak baris. Pengukuran tabel di atas belum mencakup biaya readiness ini; target request penuh <300 ms belum terbukti pada 100.000 foto. Jangan menganggap latency AI sebagai latency aplikasi keseluruhan.

IMAGE_SEARCH_FINAL_MIN_SCORE adalah threshold score akhir khusus object-v2, diterapkan setelah reranking, sebelum grouping/limit hasil. Nilai belum diisi: data negatif dan tuning yang memadai tidak tersedia. Saat belum dikalibrasi, validasi similarity existing tetap berlaku. Nilai contoh dalam unit test hanya fixture pengujian dan bukan rekomendasi. Legacy tetap menggunakan batas existing.

Kalibrasi yang masih diperlukan: kumpulkan query positif dan negatif berlabel; pisahkan sesi capture tuning/evaluation; pilih bobot dan threshold pada tuning berdasarkan precision/recall hasil akhir SKU sesuai biaya false positive yang disepakati; bekukan model revision, preprocessing, bobot, K, ef_search, dan threshold; laporkan ulang Top1/Top5/MRR serta false-positive/no-match pada held-out tanpa retuning. Jangan mengisi threshold dari cosine tertinggi pada dua query ini.

UI menampilkan Skor desimal, bukan XX% match. Data memisahkan raw_cosine dan final_score. Tidak ada calibrated confidence yang dibuat-buat. Filter dapat menghasilkan satu atau nol hasil; state nol: “Tidak ditemukan barang yang cukup mirip”.

## Reproduksi

Data mentah dan embedding lokal berada di ai-service/benchmark-output (diabaikan Git). Ringkasan tanpa gambar/embedding: optimization-real-sku.json. Gunakan manifest references/tuning/evaluation dengan path, sku, photo_id, case dan dataset_note. Tandai query negatif dengan sku=null. Pisahkan file dan sesi pemotretan; file berbeda saja tidak menjamin independensi. Jalankan dari ai-service:

```text
venv/Scripts/python.exe -B benchmark_models.py benchmark-output/real-sku-manifest.json --model clip --output benchmark-output/real-clip.json
venv/Scripts/python.exe -B benchmark_models.py benchmark-output/real-sku-manifest.json --model dinov2 --output benchmark-output/real-dinov2.json
venv/Scripts/python.exe -B benchmark_lazy.py benchmark-output/real-sku-manifest.json --output benchmark-output/real-lazy.json
```

Kemudian dari root:

```text
php tests/benchmark-retrieval.php
php tests/benchmark-pgvector.php
```

Script retrieval menggunakan VisualReranker dan ImageSearch milik aplikasi. Metrik Top1/5/MRR pada ranking tanpa threshold agar perbandingan embedding berbeda adil; bukan hasil operational rejection policy yang belum dikalibrasi. Script pgvector menolak environment selain local/loopback, memakai tabel TEMP dalam transaksi dan rollback, tanpa menjalankan migration aplikasi. Jangan menjalankan benchmark model bersamaan dengan benchmark database.

## Status validasi

82 test Laravel lulus (329 assertion), termasuk adaptive skip descriptor/metadata, kegagalan descriptor, filter setelah reranking, satu/nol hasil, UI tanpa persen match, dan threshold invalid. 21 test Python lulus, termasuk crop fallback, fast path tanpa descriptor, dan endpoint descriptor tanpa embedding. Acceptance accuracy tetap pending sampai tersedia foto independen SKU sulit, variasi tangan/rak/sudut/jarak/cahaya/occlusion dan query negatif berlabel.

## Skala pgvector lokal

PostgreSQL lokal dengan pgvector 0.8.6. Vector sintetis acak 512 dimensi, lima query acak yang berbeda dari reference; K=20/30/50, ef_search=100. Query memakai ImageSearch::candidates aplikasi, termasuk join foto/produk; planner natural memilih HNSW. Baseline exact menonaktifkan index scan dalam transaksi yang sama. Ini stress test ANN pada distribusi acak, **bukan akurasi SKU atau distribusi embedding foto production**.

| Reference | K | Exact median/P95 ms | HNSW median/P95 ms | ANN Recall@K |
|---:|---:|---:|---:|---:|
| 10,000 | 20 | 57.4 / 62.8 | 14.3 / 15.4 | 61.0% |
| 10,000 | 30 | 57.8 / 64.1 | 13.5 / 19.4 | 51.3% |
| 10,000 | 50 | 60.1 / 61.9 | 15.2 / 18.7 | 50.8% |
| 100,000 | 20 | 607.1 / 874.2 | 18.4 / 19.1 | 12.0% |
| 100,000 | 30 | 591.5 / 607.4 | 17.9 / 19.0 | 10.0% |
| 100,000 | 50 | 572.3 / 603.6 | 17.3 / 21.8 | 10.8% |

**HNSW cepat tetapi recall sintetis rendah pada ef_search=100. Ini tidak lolos sebagai bukti kualitas retrieval production.** Jangan memilih konfigurasi berdasarkan latency saja. Ef_search dan K perlu diukur terhadap exact search pada embedding SKU nyata berskala besar sebelum mengaktifkan indeks/pipeline baru; angka ini tidak membuktikan 10% akurasi foto.

Build HNSW 10.000 baris sekitar 6.1 detik; 100.000 baris sekitar 743.8 detik (12.4 menit) dengan maintenance_work_mem=64 MiB. Ini wall time lokal, sempat ada uji regresi singkat saat build; bukan estimasi waktu build production. Sampling query dilakukan setelah build dan uji regresi selesai. Tidak menaikkan anggaran memori, tidak membangun index dalam request upload/search. Tabel TEMP dan indeks dibersihkan melalui rollback; tidak menjalankan migration aplikasi atau mengubah reference asli.

Ringkasan skala tersimpan di optimization-pgvector.json. Lima query/P95 bukan load test concurrency, dan biaya readiness katalog serta HTTP belum termasuk. Default legacy dan descriptor off dipertahankan; target akurasi dan latency end-to-end masih memerlukan dataset representatif.
