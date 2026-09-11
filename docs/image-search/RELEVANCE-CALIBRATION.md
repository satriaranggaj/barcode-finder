# Relevance calibration — tooling offline

12 September 2026. Tooling ini mengevaluasi **skor yang sudah dihasilkan pipeline existing**. Tidak menjalankan model baru, tidak mengganti retrieval/reranking, tidak menulis konfigurasi aplikasi, dan tidak memilih threshold secara otomatis. `IMAGE_SEARCH_FINAL_MIN_SCORE` tetap belum diisi; runtime tetap legacy. DINOv2 tetap sebatas pembanding benchmark existing.

## Alur

1. Bekukan snapshot reference catalog dan identitas pipeline. Rekam model/revision, preprocessing, K, ef_search efektif, parameter adaptive, descriptor weights, batas maksimum hasil, serta scope pengukuran latency.
2. Siapkan foto query berbeda dari reference. Pisahkan sesi capture tuning dan evaluation **sebelum** melihat hasil; foto dari sesi yang sama, crop, atau variasi foto asal yang sama masuk `capture_group` yang sama.
3. Jalankan pipeline existing sampai selesai candidate retrieval dan optional reranking, **sebelum final relevance filter dan sebelum limit hasil UI**. Ekspor kandidat Top-K dengan `raw_cosine` dan `final_score` presisi penuh. Skor screenshot yang dibulatkan tidak cukup untuk kalibrasi.
4. Lengkapi label SKU relevan untuk setiap query. Tuning wajib memiliki query positif dan query negatif out-of-catalog. Kandidat salah pada query positif juga dihitung sebagai false positive jika ditampilkan.
5. Jalankan `tune` untuk beberapa threshold uji yang dinyatakan eksplisit. Pilih berdasarkan tradeoff false positive dan false rejection pada tuning, bukan evaluation. Tool tidak menyediakan default/rekomendasi threshold.
6. `freeze` menerima hanya threshold yang sudah tercantum dalam laporan tuning. Simpan file policy; output yang sudah ada tidak ditimpa.
7. `evaluate` memakai tepat satu threshold dari policy tersebut. CLI tidak menerima threshold baru. Perubahan pipeline atau snapshot catalog, duplikasi foto/ID, atau sesi capture yang tumpang tindih dengan tuning ditolak.

Fingerprint mendeteksi konfigurasi/data yang tidak cocok; bukan tanda tangan keamanan. Tool memvalidasi identitas yang diberikan, tetapi tidak dapat membuktikan foto diambil secara independen hanya dari metadata. Hash harus berasal dari bytes foto asli dan capture group harus dianotasi jujur. Foto reference, tuning, dan held-out perlu diaudit saat penyusunan dataset. Dataset contoh di bawah **sintetis untuk menguji tooling**, bukan data acceptance.

## Format pipeline

Contoh lengkap: [pipeline.json](../../ai-service/relevance-examples/pipeline.json).

`catalog_sha256` adalah SHA256 manifest reference yang dibekukan, mencakup SKU, identitas/hash foto reference, dan versi fitur. Gunakan serialisasi manifest yang konsisten. Saat reference bertambah/berubah, buat snapshot dan run baru; jangan memakai policy dari catalog lama tanpa kalibrasi ulang. Penambahan query cukup menambah baris data dan membuat laporan versi baru, tanpa mengubah source code.

`pipeline_sha256` setiap query harus sama dengan hasil `fingerprint(pipeline)` dalam `relevance_benchmark.py`: SHA256 JSON terurut, menggunakan serialisasi Python yang sama. Jangan memakai hash file JSON mentah karena whitespace dapat berbeda. Identitas model/revision dan preprocessing harus mencerminkan ekspor sebenarnya; jangan mengisi label object-v2 pada skor legacy.

## Format JSONL query

Satu baris JSON per foto query. File tuning hanya berisi `split: "tuning"`; file evaluation hanya `split: "evaluation"`. Lihat [tuning.jsonl](../../ai-service/relevance-examples/tuning.jsonl) dan [evaluation.jsonl](../../ai-service/relevance-examples/evaluation.jsonl).

| Field | Makna |
|---|---|
| `query_id` | Identitas unik foto query. Boleh memakai ID koleksi sendiri; ID baru tidak boleh dipakai untuk menyamarkan foto yang sama. |
| `query_sha256` | SHA256 bytes file foto query, 64 karakter hex lowercase. Mengganti nama file tidak mengubah hash. |
| `capture_group` | Sesi/pengambilan foto asal. Semua turunan/crop/angle berdekatan dari sesi tersebut tetap satu group. Tidak boleh tumpang tindih antara tuning/evaluation. |
| `pipeline_sha256` | Fingerprint konfigurasi yang menghasilkan skor. |
| `labels_complete` | Harus `true` setelah anotasi diperiksa. Label yang belum diketahui tidak otomatis menjadi negatif. |
| `relevant_skus` | Daftar seluruh SKU yang diterima sebagai relevan menurut ground truth. SKU berupa string, tanpa duplikasi. |
| `candidates` | Daftar maksimum K kandidat foto sebelum filtering. Boleh beberapa foto dengan SKU sama. Urutan ekspor dipertahankan untuk tie skor. Boleh kosong. |
| `candidates[].sku` | SKU kandidat dalam bentuk string. |
| `candidates[].raw_cosine` | Cosine presisi penuh, finite dalam [-1,1]. Bukan confidence. |
| `candidates[].final_score` | Skor setelah optional reranking, sebelum relevance filter. Field ini yang dibandingkan dengan threshold. |
| `latency_ms` | Opsional: latency terukur untuk query, finite dan nonnegatif. Scope wajib dijelaskan pada pipeline. Jangan mengisi nol untuk pengukuran yang tidak tersedia. |

**Positive query:** `relevant_skus` berisi minimal satu SKU, misalnya `["SKU-A"]`. Bisa lebih dari satu SKU jika label relevansi memang menerima keduanya; kasus lunch box memiliki dua label relevan menurut pengguna. SKU benar yang tidak masuk kandidat tetap dihitung sebagai false negative.

**Negative query out-of-catalog:** `relevant_skus: []`. Tidak ada SKU reference yang layak tampil. `candidates` bisa berisi salah satu kandidat tidak relevan atau kosong. Semua kandidat salah pada positive query juga merupakan pasangan negatif, tetapi pasangan tersebut tidak menggantikan kebutuhan query out-of-catalog dalam tuning.

Untuk ekspor foto identity, field tambahan seperti `photo_id` dan `path` boleh disimpan sebagai provenance; tool memakai `query_id`, hash, dan capture group untuk deduplikasi. Jangan menyalin hash contoh sintetis ke dataset nyata.

## Definisi report

Metrik retrieval dihitung **sebelum threshold**, setelah skor akhir diurutkan dan foto dikelompokkan per SKU terbaik:

- `top1`, `top5`: fraksi positive query yang memiliki setidaknya satu SKU relevan pada posisi tersebut.
- `mrr`: mean reciprocal rank SKU relevan pertama; miss bernilai nol.
- `recall_at_k`: rata-rata fraksi SKU relevan yang masuk candidate set, hanya pada positive query. Dengan dua SKU relevan dan hanya satu ditemukan, recall query = 0.5.
- `positive_candidate_hit_rate`: fraksi positive query dengan minimal satu kandidat relevan.

Untuk setiap threshold di `thresholds`, filtering memakai `final_score >= final_min_score`, diikuti best-photo per SKU dan batas maksimum hasil yang sama dengan ekspor runtime. Tidak ada jumlah hasil minimum; nol, satu, atau beberapa hasil sah.

| Metrik | Definisi |
|---|---|
| `tp` | SKU relevan yang ditampilkan. |
| `fp` | SKU tidak relevan yang ditampilkan, termasuk pada positive query. |
| `fn` | SKU relevan yang tidak ditampilkan: gagal retrieval, terfilter, atau melewati batas maksimum hasil. |
| `tn` | SKU kandidat tidak relevan yang tidak ditampilkan. Tidak menghitung seluruh SKU catalog yang tidak pernah menjadi kandidat. |
| `precision` | TP / (TP + FP). |
| `positive_recall` | TP / (TP + FN). |
| `false_positive_rate` | FP / (FP + TN), **conditional terhadap kandidat negatif yang diekspor**, bukan terhadap semua barang di katalog. |
| `false_negative_rate` | FN / (FN + TP). |
| `negative_rejection` | Fraksi negative query yang menghasilkan nol hasil. |
| `positive_query_false_rejection` | Fraksi positive query yang tidak menampilkan satu pun SKU relevan. Bisa terjadi walaupun Top-1 sebelum filter benar. |
| `queries_with_false_positive` | Jumlah query yang menampilkan setidaknya satu SKU tidak relevan. |
| `median_ms`, `p95_ms` | Statistik `latency_ms` yang tersedia, bukan waktu menjalankan evaluator. P95 memakai interpolasi linear. |

Pembagi nol menghasilkan `null`, bukan klaim 0%/100%. Negative query dengan kandidat kosong merupakan satu true rejection pada level query, namun menyumbang nol TN pasangan SKU karena tidak ada pasangan kandidat. Report mencatat `latency_samples` agar pengukuran parsial terlihat. Pipeline, dataset hash, policy hash, identitas split, jumlah positive/negative query, dan threshold uji disimpan. `acceptance_ready` tetap `false`: lulus tooling tidak otomatis berarti dataset cukup atau model lebih baik.

## Contoh menjalankan end-to-end

Dari folder `ai-service`, menggunakan Python/venv existing. Buat folder output kosong. **Angka 0.4/0.6 berikut hanya fixture pengujian format dan CLI; bukan hasil kalibrasi model, bukan rekomendasi aplikasi, dan tidak berasal dari kasus kacamata/lunch box.** Contoh skor, latency, serta hash foto juga sintetis.

```powershell
New-Item -ItemType Directory -Force benchmark-output/relevance-demo
.\venv\Scripts\python.exe -B relevance_benchmark.py tune relevance-examples/tuning.jsonl --pipeline relevance-examples/pipeline.json --thresholds '0.4,0.6' --output benchmark-output/relevance-demo/tuning-report.json
.\venv\Scripts\python.exe -B relevance_benchmark.py freeze benchmark-output/relevance-demo/tuning-report.json --threshold 0.6 --output benchmark-output/relevance-demo/frozen-policy.json
.\venv\Scripts\python.exe -B relevance_benchmark.py evaluate relevance-examples/evaluation.jsonl --policy benchmark-output/relevance-demo/frozen-policy.json --pipeline relevance-examples/pipeline.json --output benchmark-output/relevance-demo/evaluation-report.json
```

Contoh tuning dan evaluation memakai ID, hash, serta capture group berbeda. Pemilihan threshold contoh sudah ditentukan sebagai fixture sebelum evaluation dijalankan. Hasil contoh tidak dipakai untuk memilih konfigurasi runtime. Untuk mengulang gunakan nama folder/file output baru; file policy/report yang sudah ada sengaja tidak ditimpa.

Dengan data nyata, isi grid threshold uji eksplisit, review hasil tuning, bekukan pilihan beserta konfigurasi, lalu evaluasi dataset held-out. Jangan mengubah threshold setelah melihat evaluation dan tetap menyebutnya held-out. Gunakan dataset evaluation baru yang belum pernah dipakai untuk pemilihan bila desain eksperimen diubah.

## Evidence nyata dan batas penggunaannya

Disimpan di [relevance-evidence.json](relevance-evidence.json):

- **Sunglasses:** SKU 9056299 relevan; obeng 9079588 (0.77) dan 9079590 (0.75) tidak relevan.
- **Lunch box:** SKU 8978322 (1.00) dan 8972198 (0.79) relevan; obeng 9079588 (0.74) dan 9079590 (0.72) tidak relevan.
- **Dumbbell tanpa reference:** negative/no-match query, `relevant_skus: []`, expected 0 hasil. Delapan foto asli dari Downloads sudah disalin ke dataset lokal dengan SHA256, dicatat pada `query_examples` evidence, dan dikelompokkan secara konservatif dalam satu capture group untuk tuning.

Label mengikuti laporan pengguna, bukan rule SKU/category dalam search logic. Kasus sunglasses/lunch box menyimpan `displayed_score` agar tidak disalahartikan sebagai raw cosine/final score presisi penuh. Hash query/capture group kedua kasus tersebut belum diketahui dan tetap `null`; keduanya belum eligible untuk kalibrasi. Ketiga kasus telah dilihat saat pengembangan sehingga ditujukan untuk tuning/regression, **bukan held-out acceptance**. Untuk sunglasses/lunch box, lengkapi foto asli, identitas, anotasi, konfigurasi/snapshot, dan ekspor skor lengkap sebelum memasukkannya ke tuning nyata. Untuk dumbbell, foto/hash dan laporan tuning nyata sudah tersedia pada bagian lanjutan di bawah.

Test regression memakai angka tampilan tersebut semata untuk membuktikan bahwa Top-1 benar dapat bersamaan dengan FP. Tidak ada threshold maupun rule kategori dari evidence yang diterapkan ke runtime.

## Skalabilitas dan pengujian

Evaluator membaca JSONL per baris dengan kandidat maksimal 100 dan batas baris 2 MiB; tidak memuat gambar/reference catalog. Tidak ada batas 1.000 query di evaluator ini. Memori identitas/hash dan sampel latency tumbuh terhadap jumlah query (O(Q)), bukan jumlah reference; biaya hitung O(Q × K × jumlah threshold), grid dibatasi 201. Gunakan ekspor Top-K dari pipeline existing untuk catalog 100.000+ reference, bukan memuat seluruh vector reference ke file query. Tool ini tidak menggantikan benchmark extraction/ANN existing dan tidak mengklaim melakukan ANN Recall@K sendiri.

Jalankan test dari `ai-service`:

```powershell
.\venv\Scripts\python.exe -B -m unittest discover -s tests -p test_relevance_benchmark.py -v
.\venv\Scripts\python.exe -B -m unittest discover -s tests
```

Test mencakup kebutuhan positive/negative tuning, duplicate/leakage tiga identitas, freeze hanya dari tuning, ketidakcocokan policy, TP/FP/TN/FN, no-match, false rejection, multi-SKU/foto, pemakaian final score, statistik latency, evidence pengguna, serta CLI end-to-end. Tidak ada model/service/database production yang diperlukan oleh test kalibrasi.

## Hasil validasi 12 September 2026

- 37 test Python lulus, termasuk 16 test relevance calibration.
- 21 test regresi Laravel terkait image search lulus (72 assertion): ImagePipelineTest, AdaptiveImageSearchTest, VisualRerankerTest.
- CLI tune → freeze → evaluate dijalankan sukses pada fixture contoh; output lokal di `ai-service/benchmark-output/relevance-tooling-validation-20260912/` (diabaikan Git).
- Test memastikan file `.env` dan `config/image_search.php` tidak berubah setelah menjalankan alur CLI. Tidak ada deployment, model baru, perubahan default legacy, atau pengisian threshold aplikasi.

Hasil ini memvalidasi tooling. Angka fixture tidak membuktikan peningkatan accuracy/rejection pada foto nyata, dan kedua evidence belum digunakan untuk menentukan threshold.

## Uji dumbbell nyata lanjutan

Foto ditemukan di Downloads, bukan dibuat secara sintetis. User mengonfirmasi label foto obeng lantai: `18.58.51` = SKU 9079590 (6 inci), `18.58.39` = SKU 9079588 (4 inci). Dua query positif ini dan delapan dumbbell negatif diuji terhadap snapshot 17 reference lokal. Hash kedua query positif tidak sama dengan hash reference. Semua query masuk **tuning**, dengan dua kelompok capture konservatif; tidak ada data held-out baru yang diklaim.

`relevance_benchmark.py tune` sudah dijalankan untuk legacy dan object-v2 global-only. Kandidat dihitung dengan cosine aplikasi; legacy memakai vector database existing, object-v2 memakai ekstraksi reference baru yang hanya disimpan untuk benchmark. Tidak menulis vector database. Semua 17 reference masuk K=50, sehingga run ini exact offline; bukan validasi ANN skala besar. CLIP memakai cache lokal dalam mode offline, tanpa model/API baru.

Grid threshold berasal dari titik tengah skor tuning yang teramati ditambah endpoint rentang skor; bukan angka tebakan dari screenshot. Tidak ada threshold dipilih dari evaluation dan tidak ada policy yang di-freeze atau diterapkan.

| Tuning saja | Legacy | object-v2 |
|---|---:|---:|
| Positive-query Top-1 | 2/2 | 0/2 |
| Positive-query Top-5 | 2/2 | 2/2 |
| MRR | 1.000 | 0.267 |
| Recall@K | 1.000 | 1.000 |
| Positive recall terbaik ketika seluruh 8 negatif ditolak | 1.000 | 0.000 |
| Negative rejection terbaik ketika kedua true match dipertahankan | 1.000 | 0.125 |

Legacy memiliki rentang pemisahan pada sampel ini: skor negatif tertinggi sekitar 0.8185, sedangkan true match positif terendah sekitar 0.8409. **Ini bukan rekomendasi threshold runtime**: data baru hanya mencakup dua positive query dan satu kelompok negatif terkait. Evidence lunch box relevan 0.79 juga menunjukkan risiko membuang hasil benar jika ambang sekadar dinaikkan untuk kasus dumbbell. Skor tersebut masih dibulatkan, sehingga perlu ekspor presisi penuh dan cakupan positive/held-out lebih luas sebelum final calibration.

Pada object-v2 skor negatif dan true match tumpang tindih; threshold saja tidak menyelesaikan seluruh kasus pada data ini. Pipeline tidak diganti atau diaktifkan berdasarkan hasil tersebut.

Detail TP/FP/TN/FN, seluruh kurva threshold, latency, dan fingerprint ada di [dumbbell-tuning-diagnostic.json](dumbbell-tuning-diagnostic.json). Foto asli, manifest, skor per query, serta laporan tuning lengkap berada di `ai-service/benchmark-output/labelled-dumbbell-20260912/` (diabaikan Git). Latency hanya CPU AI dan scoring exact offline, satu pengukuran per query; bukan waktu respons runtime penuh.

Test endpoint tambahan memastikan candidate retrieval yang terisi dapat berakhir dengan 0 hasil setelah filtering, menampilkan “Tidak ditemukan barang yang cukup mirip”, dan tidak memanggil legacy untuk mengisi kembali hasil kosong. 22 test regresi Laravel terkait lulus (79 assertion); 17 test kalibrasi lulus. Default tetap legacy dengan batas existing sampai threshold yang sesuai pipeline tervalidasi; `IMAGE_SEARCH_FINAL_MIN_SCORE` yang tersedia saat ini khusus jalur object-v2 dan tidak boleh diisi menggunakan kalibrasi legacy.
