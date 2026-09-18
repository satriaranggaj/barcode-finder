# Frozen relevance policy (no-match gate)

Tanpa policy, `/search` selalu mengembalikan Top-K — bahkan untuk foto yang
tidak cocok dengan apa pun (skor 0.26 ikut tampil). Policy ini adalah gerbang
no-match yang dirancang arsitektur: kandidat di bawah threshold dibuang,
`confidence_calibrated`/`relevance_calibrated` menjadi true, dan query asing
dijawab kosong + `low` secara terkalibrasi (bukan tebakan).

## Policy aktif

- File: `ai-service/policies/relevance-v1.json` (artefak turunan, tidak di-commit)
- Threshold: **0.706** (`0.7059600695967674` persis)
- Dituning dari: `storage/app/private/benchmark-20260915/evaluation`
  (2 query positif + 8 negatif, dataset `eval-d58563955a6fdfec`)

## Kenapa 0.706 (dipilih manusia, bukan argmax-F1)

| Threshold | tp | fp | neg_rej | pos_rej |
|---|---|---|---|---|
| 0.8587 (auto-max-F1) | 1 | 0 | 1.0 | **0.5** |
| 0.8319 | 2 | 3 | 1.0 | 0.0 (margin 0 ke skor-benar-min!) |
| **0.706** | 2 | 5 | 1.0 | **0.0** (margin 0.24/0.13) |

- Auto-max-F1 (0.8587) **membuang satu query positif** — ditolak.
- 0.8319 sama dengan skor-benar-min persis: rapuh 1-ulp terhadap
  nondeterminisme thread (satu true positive bisa hilang diam-diam).
- 0.706 = threshold tertinggi dengan **zero positive rejection** dan margin
  aman dua sisi (0.24 di atas negatif-max 0.469; 0.13 di bawah correct-min
  0.832). fp 3–5 yang tersisa adalah klaster near-tie Screwdriver JC414
  (0.83–0.85) yang memang tidak bisa dipisah di level skor — ditangani
  flag `ambiguous`, bukan gerbang.

## Aktivasi (uvicorn manual)

Persisten via `ai-service/.env` (gitignored, auto-load saat startup; env OS
asli selalu menang bila di-set):

```text
RELEVANCE_POLICY=D:/Directory/Pemrograman/barcodeindentify/ai-service/policies/relevance-v1.json
```

Lalu restart total worker (`Ctrl+C`, jalankan lagi `uvicorn`). Verifikasi:

- log startup: `Relevance gating active; threshold=0.706`
- `GET /health` → `"relevance_gated": true`

Tanpa variabel ini, gating mati dan perilaku kembali ke Top-K selalu penuh
(`confidence_calibrated=false`, `relevance_gated=false`). Policy yang tidak
cocok signature ditolak eksplisit saat boot (service 503, bukan diam).

## Kapan harus tune ulang (wajib)

Signature policy mengikat: revisi model, **setiap rebuild index**
(fingerprint berubah tiap generasi!), kode representasi/ranking, bobot,
dan side preprocessing. Setelah salah satunya berubah:

```powershell
# 1. eval melawan index AKTIF
.\venv\Scripts\python.exe -B -m app.scripts.evaluate --dataset <eval-dir> --output eval-new.json
# 2. tune
.\venv\Scripts\python.exe -B -m app.scripts.calibrate tune --input eval-new.json --output tuning-new.json
# 3. REVIEW trials (jangan buta pakai selected_threshold bila pos_rej > 0),
#    freeze eksplisit:
.\venv\Scripts\python.exe -B -m app.scripts.calibrate freeze --input tuning-new.json --threshold <PILIHAN> --output policies/relevance-v2.json
# 4. arahkan RELEVANCE_POLICY ke v2, restart worker
```

## Batasan jujur

- Tuning hanya dari **2 query positif** — threshold valid untuk distribusi
  ini; query benar berskor < 0.71 akan tertolak (false negative). Tambah
  query berlabel varian sulit, lalu tune ulang — itu cara yang benar
  mengetatkan gerbang, bukan menebak angka.
- `evaluate_policy` butuh set evaluasi DISJOIN dari tuning (tolak bila
  hash/grup sama); validasi ronde ini bersifat behavioral (negatif→kosong,
  positif→utuh + flag calibrated), tercatat di skrip verifikasi manual.
