# Lensku fine-grained visual SKU retrieval

Pipeline baru berfokus pada **product instance retrieval**, bukan classifier kategori.
Semua model berjalan lokal; tidak memakai layanan inferensi berbayar.

## Arsitektur dan kompatibilitas

```text
JPEG/PNG/WebP → validasi + EXIF orientation + RGB + resize
  → original / conservative GrabCut foreground box (fallback original)
  → global + center + left + right + top + bottom → square padding
  → SigLIP2 pooled image vector / DINOv2 CLS vector (terpisah, float32, L2)
  → FAISS HNSW global shortlist → bounded local/global reranking
  → max score per SKU → Top-K SKU + heuristic confidence
```

- Semantic encoder: `google/siglip2-base-patch16-256`, 768 dimensi.
- Appearance encoder: `facebook/dinov2-small`, 384 dimensi, dipilih untuk CPU.
  `DINO_MODEL=facebook/dinov2-base` didukung, tetapi memerlukan rebuild indeks.
- Tidak ada model yang dimuat saat import. Lifecycle API memuat model sekali;
  `eval()` dan `torch.inference_mode()` dipakai, tanpa float16 pada CPU.
- CUDA dipilih jika tersedia (`DEVICE=auto`), atau pakai `DEVICE=cpu`.
- Model yang gagal pada startup/request dapat dilewati untuk **search**;
  bobot model tersisa dinormalisasi ulang. Build membutuhkan kedua model agar
  setiap reference memiliki representation lengkap dan konsisten.
- `/embed` tanpa parameter tetap mengembalikan **CLIP 512** untuk Laravel lama
  dan kolom PostgreSQL `vector(512)`. CLIP hanya jembatan kompatibilitas;
  `/search` baru tidak memakai CLIP. `representation=multi` menampilkan embedding baru.
- Laravel/UI/upload saat ini **belum dialihkan** ke FAISS. Foto baru di Laravel
  tidak otomatis masuk indeks folder. Evaluasi API ini dahulu; integrasi berikutnya
  perlu sinkronisasi reference upload/edit/delete dan pemetaan SKU ke produk Laravel.
  Tidak ada migration, perubahan database Laravel, push, atau deployment otomatis.
- `ENABLE_LEGACY_EMBED=false` menghemat pemuatan model CLIP untuk penggunaan
  standalone. Jangan matikan bila Laravel masih memakai service ini untuk `/embed`.

## Install (Windows PowerShell)

Gunakan Python **3.11–3.13 64-bit**; lingkungan pengujian menggunakan 3.13 Windows.

```powershell
cd ai-service
python -m venv .venv
.\.venv\Scripts\Activate.ps1
python -m pip install -r requirements-dev.txt
python -m pip check
```

`requirements-dev.txt` menyertakan HTTP test client. Untuk runtime saja gunakan
`requirements.txt`. PyTorch 2.14.0 / torchvision 0.29.0 dan Transformers 4.57.6
dipin sesuai lingkungan yang diuji. Untuk GPU, instal wheel PyTorch CUDA versi yang sama dari
[petunjuk resmi PyTorch](https://pytorch.org/get-started/locally/), lalu requirements.

Startup pertama mengunduh model config, image processor, dan weights SigLIP2,
DINOv2, serta CLIP jika compatibility aktif dari Hugging Face. Siapkan disk beberapa
GB untuk dependency, cache model, dan indeks. Setelah cache lengkap dapat menjalankan
offline dengan `$env:HF_HUB_OFFLINE='1'`. Tidak diperlukan API key untuk model publik.
Model cache bukan bagian Git. Tidak ada download SAM/OCR/segmentation model tambahan.

## Dataset reference dan metadata

```text
dataset/
  metadata.json
  9079588/
    front.jpg
    side.jpg
    shelf.jpg
  9079590/
    front.jpg
    shelf.jpg
evaluation/
  9079588/
    different-capture.jpg
  9079590/
    different-angle.jpg
```

Nama folder adalah label SKU, termasuk angka nol di depan. Contoh SKU di atas
hanya format dataset; tidak menjadi rule pencarian. Setiap foto diindeks terpisah.
Format file single-frame JPEG/PNG/WebP. Subfolder tambahan di dalam SKU tidak dipindai.

`metadata.json` opsional, satu objek per SKU:

```json
{
  "9079588": {
    "product_id": 100,
    "category": "tools",
    "sub_category": "screwdriver",
    "family": "phillips_screwdriver"
  }
}
```

Tanpa mapping, `product_id` bernilai null. Di API, product_id dari SQLite dikembalikan
sebagai string atau null; SKU selalu string. `category` adalah filter exact-match
yang diisi pengelola dataset, tanpa classifier atau daftar kategori hard-coded.
`sub_category` dan `family` tersedia untuk hard-negative/training masa depan.

## Build, incremental, rebuild

```powershell
$env:DEVICE='cpu'
$env:FAISS_INDEX_PATH='indexes'
$env:PREPROCESSING_MODE='object'
python -m app.scripts.build_index --dataset .\dataset

# Jalankan kembali: foto identik dilewati; foto tambahan di-append.
python -m app.scripts.build_index --dataset .\dataset

# Foto diubah/dihapus, metadata berubah, model atau preprocessing berubah:
python -m app.scripts.build_index --dataset .\dataset --rebuild
```

Build memindai satu foto pada satu waktu dan melaporkan progress setiap 25 foto.
Incremental masih decode/hash foto lama untuk mendeteksi perubahan, tetapi tidak
menjalankan model lagi. Gambar invalid atau model gagal membatalkan build; indeks
yang sedang disajikan tetap utuh. Perbaiki input lalu ulangi. Belum ada resume
embedding dari build yang terputus. Foto terhapus tidak ditinggalkan diam-diam:
gunakan `--rebuild`.

Setiap generasi berisi `siglip.faiss`, `dino.faiss`, `metadata.sqlite`, dan manifest
checksum/model revision/dimensi/preprocessing. `CURRENT` diganti atomik setelah
seluruh generasi tersimpan. Satu writer dikunci lewat `build.lock`; jika proses
crash, hapus lock hanya setelah memastikan writer tidak berjalan.
Restart API setelah build agar generasi baru dimuat. Generasi lama tidak dihapus
otomatis; bersihkan setelah tidak ada worker yang memakainya. Disk sementara
dibutuhkan untuk salinan indeks/SQLite saat incremental.

SQLite menyimpan `vector_id`, `ref_id`, `embedding_type`, `crop_type`, dimensi, dan
blob float32. Tabel `refs` menyimpan `image_id` (path relatif), SKU/product_id,
category/sub_category/family, serta hash piksel. Mapping tidak bergantung pada FAISS
saja. Indeks FAISS global memakai implicit row ID yang sama dengan `refs.id`.
Hanya muat snapshot lokal yang dipercaya; checksum mendeteksi kerusakan, bukan
pengganti autentikasi snapshot.

## Jalankan dan uji API

```powershell
# Port kompatibel dengan default Laravel AI_SERVICE_URL.
python -m uvicorn app.main:app --host 127.0.0.1 --port 8001 --workers 1
```

Jangan gunakan banyak worker pada CPU terbatas: setiap worker memuat model dan
indeks sendiri. Inference serial per worker; request bersamaan menerima 503 +
`Retry-After`. Konfigurasikan limit body reverse proxy sesuai MAX_BYTES (dengan
ruang multipart) karena validation file berjalan setelah parsing multipart.
API ditujukan untuk localhost/private network; tidak menambahkan autentikasi publik.

```powershell
curl.exe http://127.0.0.1:8001/health
curl.exe -X POST http://127.0.0.1:8001/search `
  -F "image=@C:/photos/query.jpg" -F "top_k=5" `
  -F "preprocessing_mode=object"

# Optional exact category filter, applied inside FAISS candidate search.
curl.exe -X POST http://127.0.0.1:8001/search `
  -F "image=@C:/photos/query.jpg" -F "category=tools"

# Debug representations, not the old Laravel vector.
curl.exe -X POST http://127.0.0.1:8001/embed `
  -F "image=@C:/photos/query.jpg" -F "representation=multi"
```

Swagger tersedia di `/docs`. Kontrak `POST /search` difinalisasi di
`app/schemas.py` (`SearchResponse`) dan divalidasi setiap respons.

Request (multipart): `image` (wajib), `top_k` (1..50), `crop_x/crop_y/
crop_width/crop_height` (ternormalisasi 0..1, wajib keempatnya bila dipakai),
`selection_mode` (`auto`/`manual`/`full`), `preprocessing_mode`
(`original`/`object`), `category` opsional, `feedback_preview` opsional.

Setiap kandidat: `rank`, `sku`, `product_id`, `score`, `dino_score`,
`siglip_score`, `local_score`, `text_score`, `ocr_score`, `matched_images`
(+ `matched_image_ids`, `visual/object/global_score`, `family` bila ada).
Metadata search (`query`): `selection_used`, `selection_mode`, `preprocessing`
aktual, `reason`, `requested_preprocessing`, `models`, `ocr_tokens`.
Level search: `confidence`, `score_gap`, `confidence_calibrated`,
flag `ambiguous` + `ambiguity_reason`/`ambiguity_alternatives`,
`candidate_images`, `latency_ms`.

Tidak pernah dikirim: full embedding vectors, absolute filesystem path,
atau private metadata. `matched_images` menghitung foto SKU
yang masuk shortlist, bukan total foto katalog atau klaim relevant match.

Invalid/corrupt/unsupported image, top_k di luar 1..50, atau mode salah → 422;
file terlalu besar → 413; model/index tidak tersedia → 503. Empty index/category
tanpa kandidat → sukses dengan hasil kosong. `/health` membedakan kesiapan search
dan legacy embed. Kegagalan segmentation mengembalikan original dengan reason,
bukan error upload. Crop foreground GrabCut dapat tetap salah memilih tangan/rak:
ini heuristic konservatif, bukan detektor objek terlatih. Mode `original` tersedia
untuk perbandingan query; reference mode disimpan di signature indeks.

## Scoring dan confidence

Stage 1 mengambil sampai `CANDIDATES` global neighbors per model dari HNSW/IP.
Gabungan maksimum 2 × CANDIDATES diringkas menjadi CANDIDATES menggunakan weighted
reciprocal rank fusion (`weight/(60+rank)`). Parameter 60 adalah heuristic shortlist,
bukan calibrated relevance threshold. Stage 2 hanya membandingkan shortlist tersebut.

Per model:

```text
local = mean(mean(max(query_local × ref_localᵀ, per query crop)),
             mean(max(query_local × ref_localᵀ, per reference crop)))
model_score = (1-LOCAL_WEIGHT) * global_cosine + LOCAL_WEIGHT * local
image_score = SIGLIP_WEIGHT * siglip_score + DINO_WEIGHT * dino_score
sku_score = max(image_scores dalam shortlist)
```

### DINO patch reranking (opsional, `PATCH_WEIGHT > 0`)

Patch matching hanya berjalan pada kandidat shortlist (maksimum CANDIDATES),
tidak pernah pada seluruh korpus. Patch reference di-cache di SQLite saat build
dan dibaca satu kali dalam bulk untuk shortlist.

```text
patch = symmetric similarity antara query patches (masked content) dan reference patches
        per arah: best correspondence tiap patch, lalu aggregation configurable
dino_score = (1-PATCH_WEIGHT) * dino_score + PATCH_WEIGHT * patch
local_score (field respons) = patch, untuk debug/evaluasi
```

`PATCH_AGGREGATION` (`top_k` default; juga `median`, `trimmed_mean`, `mean`)
membuat skor tahan outlier: satu patch kebetulan tidak menentukan skor, dan
reference dengan lebih banyak patch tidak otomatis unggul — jumlah korespondensi
dibatasi sisi yang lebih kecil (`min(PATCH_TOP_K, |query|, |reference|)`).
`PATCH_TRIM` mengatur pemangkasan untuk `trimmed_mean`. Patch dari padding sudah
dimask saat ekstraksi (Prompt 11). Semua skor tetap heuristic **bukan
probabilitas**.

Max aggregation tidak memberi bonus hanya karena satu SKU memiliki lebih banyak foto.
Bobot model harus nonnegatif dan berjumlah 1; jika satu tidak tersedia, bobot yang
tersisa dinormalisasi ulang. Confidence memakai score top1 dan gap sebelum top_k
dipotong. Singleton tidak dianggap mempunyai gap besar. Default high ≥ .85 dan
gap ≥ .08; medium ≥ .70; selainnya low. Semua ini **heuristic belum terkalibrasi**,
bukan probabilitas benar. Response menandainya `confidence_calibrated=false`.
Top-K adalah kandidat, bukan satu SKU pasti. Belum ada calibrated no-match gate;
jangan memakai score CLIP lama sebagai threshold SigLIP/DINO.

## Evaluasi held-out

```powershell
python -m app.scripts.evaluate --dataset .\evaluation --output .\evaluation-report.json
```

Laporan JSON memuat Top-1/3/5 accuracy (fraksi 0..1), MRR dengan cutoff 50 SKU,
median/P95 latency, `dataset_version` (digest path + hash foto query), hasil per
query, signature pipeline (termasuk confidence thresholds), dan bobot. Rank yang
tidak ditemukan dalam hasil diberi reciprocal rank 0. Waktu tidak termasuk
startup/model download, tetapi termasuk preprocessing/inference/retrieval/reranking.
CLI juga mencetak ringkasan human-readable singkat sebelum JSON.

Breakdown: `by_tag` memakai tag sidecar query (`handheld`, `clean`,
`store-background`, ...), `by_family` memakai family index reference (tanpa
family → bucket eksplisit `unknown`, bukan label buatan). Setiap query
menyimpan SKU prediksi, status ambiguity, dan daftar `errors` berisi analisis
Top-1 yang gagal (SKU expected vs predicted, rank, component scores pemenang
yang salah, matched reference, ambiguity). Report tidak memuat image bytes.

Foto evaluation harus dari pengambilan berbeda, bukan crop/resize/recompress dari
reference yang sama. Tool menolak hash piksel identik antara reference/evaluation
dan duplikat evaluation sebelum menghitung metrik. Hash tidak membuktikan bebas
near-duplicate: pengelola dataset tetap harus memisahkan sesi/capture yang sama.
Tidak ada threshold/bobot yang dipilih dari evaluation. Jika tuning bobot diperlukan,
gunakan split validation terpisah lalu jalankan evaluation sekali dengan config beku.
Belum ada klaim akurasi produk nyata sampai dataset berlabel dievaluasi.

## Ablasi fitur (A–G)

```powershell
python -m app.scripts.compare --references .\references --evaluation .\evaluation --output .\ablation `
  --patch-weight 0.2 --secondary-weight 0.1 --description-weight 0.5 --ocr
```

Setiap skenario (A global-only, B dual baseline, C object crop, D +patch, E
+multiple references, F +description, G +OCR) membangun indeks tersendiri
yang ditandatangani lalu dievaluasi pada split yang sama; `summary.json`
meringkas metrik (Top-1/3/5, MRR, median/P95 latency) dan `run.json` mencatat
argumen plus daftar skenario. Tidak ada kesimpulan otomatis: fitur hanya
diaktifkan di produksi bila angka held-out mendukungnya. Flag `--ocr`
membutuhkan binary Tesseract; tanpa dataset berlabel yang cukup, gunakan mock
smoke test (`test_workflow.py`) dan nyatakan keterbatasannya.

## Benchmark pipeline (sintetis, repeatable)

```powershell
python -m app.scripts.benchmark_search --references 60 --queries 20 --seed bench-v1 --output .\bench.json
```

Encoder stub deterministik menggantikan SigLIP/DINO sehingga yang diukur murni
overhead pipeline per stage (`stage_ms` di respons `/search`: selection,
preprocess, encode, faiss, references, ocr, rerank). Bukan klaim latency
produksi (inference model asli tidak termasuk) dan bukan akurasi.

## Konfigurasi

Lihat `.env.example` untuk semua nama. File itu template; Settings membaca environment
proses, tidak otomatis membaca `.env`. Contoh PowerShell:

```powershell
$env:SIGLIP_WEIGHT='0.35'
$env:DINO_WEIGHT='0.65'
$env:LOCAL_WEIGHT='0.25'
$env:BATCH_SIZE='2'
$env:CPU_THREADS='4'
$env:SIGLIP_REVISION='main'
$env:DINO_REVISION='main'
```

Pin `*_REVISION` ke commit Hugging Face untuk deployment yang reproducible. Manifest
selalu menyimpan resolved revision dan menolak model yang berubah. Mengubah bobot
tidak memerlukan rebuild; mengganti model/dimensi, preprocessing, atau versi processor
memerlukan rebuild. Lokasi metadata sengaja berada dalam generasi `FAISS_INDEX_PATH`
yang sama agar tidak terpisah dari FAISS saat publish.

## Skala, CPU, dan pekerjaan akurasi berikutnya

- Untuk 150.000 reference, vektor global 768+384 float32 saja sekitar **691 MB**
  (desimal), ditambah graf HNSW dan model. Enam view × dua model di SQLite sekitar
  **4,15 GB** data vector mentah, belum termasuk overhead dan generasi lama.
- Local vectors dibaca hanya untuk maksimum 50 kandidat default, tidak dimuat
  seluruhnya ke RAM. Filter category mengambil ID dari indexed SQLite column
  dan menerapkan FAISS IDSelector, bukan membuang kandidat setelah Top-K global.
- Model/crop inference (12 representation per foto), HNSW build, model loading,
  dan decode gambar besar adalah bottleneck CPU. Batch dibatasi untuk RAM;
  incremental build menyalin snapshot dan bisa membutuhkan dua set indeks di RAM.
- Default limit decode 20 MiB/40M pixels membatasi input, bukan jaminan cukup RAM
  pada semua VPS. Turunkan MAX_PIXELS bila RAM sempit. Jangan paksa float16 CPU.
- Langkah akurasi terpenting: kumpulkan multiple reference dan held-out query per SKU
  dengan hard negatives dalam keluarga yang sama, cek detail tip/ukuran/sudut, lalu
  ukur apakah DINO/crop/reranking membantu dibanding global-only. Foto tanpa skala
  tidak selalu dapat membedakan panjang fisik; ambil teks ukuran/barcode bila ada.
- `features/ocr.py` menyediakan OCR modular berbasis executable Tesseract
  (gratis/open-source, CPU-only, opsional). Kata di bawah ambang confidence
  dibuang dan tidak pernah dianggap benar; binary yang hilang menurunkan
  layanan ke tanpa-kata secara graceful. Hasil terstruktur (teks, token
  ternormalisasi, confidence engine, bounding box 0..1) tersedia lewat
  `extract_words()`; `extract_text()` yang dipakai ranking tidak berubah.
  Encoder contract dapat diganti metric-learning model; metadata family membantu
  pembuatan anchor/positive/hard-negative triplet di tahap berikutnya.

## OCR (opsional)

```powershell
# Windows: install executable Tesseract (salah satu), lalu restart terminal.
winget install --id UB-Mannheim.TesseractOCR
# atau: choco install tesseract

tesseract --version
$env:OCR_ENABLED='true'
$env:OCR_BINARY='tesseract'
$env:OCR_MIN_CONFIDENCE='80'
```

Tanpa `OCR_ENABLED=true`, atau bila binary tidak ditemukan, pencarian tetap
berjalan dengan `DisabledOCR` (tanpa kata). OCR hanya berjalan maksimal sekali
per query dan hanya bila ada shortlist serta budget secondary evidence > 0.

## Tests

```powershell
python -B -m unittest discover -s tests -v

# Opsional: unduh dan uji checkpoint asli di CPU, bukan evaluasi akurasi.
$env:RUN_MODEL_SMOKE='1'
python -B -m unittest discover -s tests -p test_models.py -v
```

Tests menggunakan real FAISS/SQLite dengan mock encoder untuk normalisasi,
multi-crop, fallback, scoring, aggregation, category filtering, snapshot corruption,
incremental failure safety, API validation, legacy contract, dan held-out leakage.

Referensi implementasi:
[SigLIP2 Transformers](https://huggingface.co/docs/transformers/v4.53.0/en/model_doc/siglip2),
[DINOv2 Small](https://huggingface.co/facebook/dinov2-small),
[FAISS cosine/IP](https://github.com/facebookresearch/faiss/wiki/MetricType-and-distances).
