# Lensku visual SKU workflow — implementation and operation

This extends the existing Laravel + FastAPI + SigLIP2/DINOv2 + FAISS application. It does not replace the models, enable a paid API, or train online. The legacy driver remains the default; enabling the new driver is an explicit operational step after building a compatible index.

## Implemented user flows

- Search: camera/gallery → original photo with one auto-selected frame (multi-source GrabCut/saliency/color-pop/contour fusion); drag/resize the frame or draw a new one, or use the full image → search. Crops use normalized coordinates in the displayed, EXIF-oriented image. Failure leaves manual/full-image search usable.
- Reference upload: separate crop per photo; the catalog remains a full-image representation. Multi-upload submits one photo per HTTP request and reports partial completion. Existing photos have an admin “Edit area barang” page. Editing marks their index status pending; the existing serving snapshot is unchanged until rebuilt.
- Image preparation: content validation, EXIF orientation, RGB conversion, lossy WebP master at original oriented dimensions, catalog up to 1600 pixels, thumbnail up to 400 pixels. Display variants never replace the AI master. Product disk is configurable; catalog lists use thumbnails when available.
- If original processing exceeds pixel/memory limits, compression is skipped. The compressed image pixels and dimensions are preserved; EXIF/GPS/comments are removed without full-resolution re-encoding, retaining only orientation where necessary. Therefore container bytes may differ from the original. Status is `preserved_pixel_limit` or `preserved_memory_budget`; no full-size GD decode occurs. These cases have no generated variants and use the preserved image for display. Upload-byte/security validation remains separate; malformed/truncated input is rejected. Pixel guards are not permission to decode enormous images without limits.
- Search feedback: authenticated users explicitly opt in on the search form. A temporary private image expires after 15 minutes. Confirmation can select a candidate or enter another existing SKU; only confirmation creates a verified training record. Duplicate identities cannot create multiple labels; near-duplicates/low-quality images are excluded from reference expansion. A correction records a hard-negative SKU. Session capture groups are retained for later dataset curation.
- Index expansion: `search:build-index --include-verified` exports eligible reference masters/crops and builds a fresh generation. This is retrieval-memory expansion, not model training. Failed builds do not switch `CURRENT`. Running workers retain their old snapshot until restarted.
- Ambiguity: results offer alternatives and explain that missing size/marking information requires checking the variant. Similarity is not presented as a probability. No-match may return zero results; there is no forced minimum result.

## Database and dependencies

Existing production migrations were not edited. Apply pending additive migrations after backing up the target database. This working tree includes the earlier feedback/image metadata migrations plus three 2026-09-16 migrations for disk/index metadata, optimization status, and unique feedback photo identity. The uniqueness migration deliberately fails if historical conflicting duplicates exist; review those labels rather than deleting them automatically.

The S3 adapter `league/flysystem-aws-s3-v3` is installed. Composer's platform is PHP 8.3.33 so updates cannot silently pull PHP-8.4-only runtime dependencies. Python continues using the existing Pillow/OpenCV/FAISS/Transformers stack. Optional OCR uses the local Tesseract executable.

## Configuration

Laravel `.env`:

```dotenv
SKU_SEARCH_DRIVER=legacy
SKU_SEARCH_TIMEOUT=20
PRODUCT_IMAGE_DISK=public
PRODUCT_IMAGE_QUALITY=88
PRODUCT_CATALOG_SIDE=1600
PRODUCT_THUMBNAIL_SIDE=400
TRAINING_IMAGE_DISK=local
SEARCH_FEEDBACK_ENABLED=false
```

For S3/R2 set both image disks to `s3`, configure `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_DEFAULT_REGION`, `AWS_BUCKET`, `AWS_ENDPOINT`, and path-style setting appropriate to the provider. Objects on non-public disks are private and catalog URLs are temporary signed URLs. No credentials are committed. Old rows retain `public`, so moving historical files requires a separate storage migration; changing the environment does not relocate files.

Python environment: `MAX_BYTES`, `MAX_PIXELS`, `PROCESSING_MEMORY_MB`, `CPU_THREADS`, `DEVICE`, `SELECTION_PADDING`, `FAISS_INDEX_PATH`, `RELEVANCE_POLICY`. The `/prepare` pixel/memory guards preserve instead of rejecting a valid image solely because compression is unsafe. AI decode still enforces its own safety limits. Keep actual upload limits aligned: Laravel currently allows 10 MiB per image; PHP `upload_max_filesize` and `post_max_size`, and the proxy body limit, must accommodate that plus multipart overhead. Client requests send individual photos to avoid summing a whole batch into one POST.

Patch, context, text, and OCR weights remain configurable: `PATCH_WEIGHT`, `GLOBAL_WEIGHT`, `SECONDARY_WEIGHT`, `DESCRIPTION_TEXT_WEIGHT`, `OCR_ENABLED`, `OCR_BINARY`, `OCR_MIN_CONFIDENCE`. Their new default weights remain zero and OCR is off. Choose production weights using labelled tuning comparisons, not synthetic examples. Visual evidence must remain the majority. Changes to representation signatures require an index rebuild; incompatible frozen policies fail closed. A policy also binds to immutable index checksums, so changing the reference snapshot requires revalidation/recalibration rather than silently reusing a cutoff.

## Local activation (PowerShell)

From the repository root, using the project's PHP/Composer and Python environment:

```powershell
composer install
php artisan migrate
npm.cmd run build
php artisan storage:link
php artisan search:build-index --include-verified
```

The CLI build uses `AI_PYTHON_BINARY` and `FAISS_INDEX_PATH` when configured. Defaults are `ai-service/venv/Scripts/python.exe` on Windows and `ai-service/venv/bin/python` on Linux, with `ai-service/indexes`. Export/build is a long-running CLI operation, not a web request. It uses bounded database chunks and streamed storage reads. Exports are retained privately for review/rollback; remove obsolete export directories only after checking the active generation and available backups. The serving generation itself includes reference vectors/metadata, so export source files are not needed by live queries.

Start/restart FastAPI from `ai-service` with the same environment used for indexing:

```powershell
.\venv\Scripts\python.exe -m uvicorn app.main:app --host 127.0.0.1 --port 8000
```

Then, from the repository root:

```powershell
php artisan search:check
```

After readiness succeeds, set `SKU_SEARCH_DRIVER=faiss`. Set `SEARCH_FEEDBACK_ENABLED=true` if the private disk and scheduler are configured, then `php artisan config:clear`. Laravel now calls `/search` and will not silently fall back to database cosine search. Neither model weights nor no-match thresholds are changed by these settings.

Run Laravel with Herd or `php artisan serve`. Run the Laravel scheduler (`php artisan schedule:work` locally; the standard scheduler cron on VPS) for `search:prune-pending`. No production deployment is performed by this implementation.

Maintenance:

```powershell
php artisan products:compress-images --dry-run
php artisan products:compress-images --chunk=100
php artisan products:compress-images --after-id=123 --chunk=100
php artisan search:export-training storage/app/private/training.jsonl
```

Maintenance snapshots an upper ID, skips completed/preserved rows, supports `--retry-preserved`, and reports failures/resume position. Unsafe compression is a skip, not a failed upload. Old files remain for rollback; this command does not claim disk reclamation. Rebuild references after changing their representation. Training export is JSONL containing private anchor disk/path/crop, positive SKU, hard-negative SKU, identity, session group, and verifier. Resolve positive/negative SKU references from the curated reference export before offline training.

## API contract

- Laravel `POST /object-selection`: image, throttled; returns bounded normalized proposal boxes; unavailable detection returns no proposals.
- FastAPI `POST /select`: image → boxes and reason.
- FastAPI `POST /prepare`: image plus quality/catalog_side/thumbnail_side → base64 image variants, dimensions, extension, status; preserved images return a master only.
- FastAPI `POST /search`: image, top_k, preprocessing_mode, optional complete crop_x/crop_y/crop_width/crop_height, optional feedback_preview. Output includes ranked SKU scores, component scores, matched image metadata, selected crop, confidence/score gap, calibration flags, and latency; no embeddings.
- Legacy `/embed` remains available and accepts optional normalized crop coordinates.
- Laravel `PUT /admin/photos/{photo}/selection`: crop_json and selection_source; admin-only. A private same-origin image endpoint supports editing remote masters without requiring public buckets/CORS exposure.
- Laravel `POST /search-feedback`: authenticated token and confirmed SKU, throttled. The token is tied to the originating user and expires. AI prediction alone does not create a training record.

## Dataset and calibration

Images are arranged as `dataset/SKU/photo.jpg`. Query sidecars `photo.json` contain `relevant_skus` (SKU strings, preserving leading zeroes), `capture_group`, optional normalized `crop`, and optional `tags`. A negative query uses `relevant_skus: []` and may be stored under `__no_match__`. Different views of the same capture/session must stay in the same split. Reference images and duplicates must never be evaluation queries. Generated reference/session labels are provenance aids, not proof that independently collected photos are held out.

Scored reports contain `evaluation_signature`, `gate_applied`, and `queries`. Each query has canonical decoded-pixel SHA-256 `photo_hash`, `capture_group`, `relevant_skus`, `results: [{sku, score}]`, and `latency_ms`. Candidate `score` is the **final reranked score**, not the initial FAISS score. Do not substitute historical screenshot scores from a different pipeline.

Synthetic format examples: `ai-service/benchmarks/examples/tuning.json` and `evaluation.json`. Historical sunglasses→screwdriver and lunch-box→screwdriver evidence is in `labelled-evidence.json`; it has missing query identity and is not eligible for calibration. No SKU/category rule is added to runtime search. Synthetic policies are explicitly rejected by runtime.

Run in `ai-service`, with unfiltered candidates and no active relevance policy when generating tuning scores:

```powershell
$env:RELEVANCE_POLICY=''
.\venv\Scripts\python.exe -B -m app.scripts.evaluate --dataset D:\data\tuning --output tuning-scored.json
.\venv\Scripts\python.exe -B -m app.scripts.calibrate tune --input tuning-scored.json --output tuning-trials.json
.\venv\Scripts\python.exe -B -m app.scripts.calibrate freeze --input tuning-trials.json --output frozen-policy.json
.\venv\Scripts\python.exe -B -m app.scripts.evaluate --dataset D:\data\heldout --output heldout-scored.json
.\venv\Scripts\python.exe -B -m app.scripts.calibrate evaluate --input heldout-scored.json --policy frozen-policy.json --output heldout-report.json
```

Output files must be new. Tuning requires positive and negative labelled queries. Freeze accepts only an actually tested cutoff. Evaluation checks both photo and capture-group leakage against tuning; evaluation never chooses the threshold. Runtime applies the frozen cutoff after reranking, allowing zero results. `IMAGE_SEARCH_FINAL_MIN_SCORE` is not populated.

Reports include Top-1/3/5, MRR@5, Recall@1/3/5, TP/FP/FN over returned relevant/irrelevant SKUs, TN over correctly rejected negative queries, precision, positive recall, false-positive/false-negative rates, negative rejection, positive-query false rejection, median and P95 latency. Candidate metrics use at most five final results, matching Laravel. Missing latency is reported as unavailable rather than fabricated.

Current data limitation: only two confirmed positive floor-screwdriver query photos and dumbbell negatives are known. The user confirmed no additional independent positive photos are available yet. No production threshold or improved-accuracy claim is therefore delivered. Obtain more independent positive sessions and hard negatives before freezing a production policy.

## Comparisons and scale

`app.scripts.compare` supports A global, B existing dual, C object, D optional patch, E multiple reference, F optional description, and G optional OCR. A–D use one deterministic reference per SKU; E–G use all references. Optional weights are explicit CLI inputs (`--patch-weight`, `--secondary-weight`, `--description-weight`, `--ocr`) and must be selected on tuning data before the final held-out comparison. OCR comparisons fail when the executable is unavailable rather than reporting an OCR gain without OCR.

```powershell
.\venv\Scripts\python.exe -B -m app.scripts.compare --references D:\data\references --evaluation D:\data\heldout --output D:\reports\comparison
.\venv\Scripts\python.exe -B -m app.scripts.benchmark_scale --references 100000 --queries 100 --output D:\reports\scale.json
```

The scale command uses synthetic 768/384-dimensional vectors with the actual FAISS/SQLite storage and 50-reference reads. It measures index capacity/latency, not photo decoding, model inference, OCR, patch cost, or product accuracy. Full end-to-end VPS latency still needs representative deployment hardware and photos.

## Validation commands

```powershell
php artisan test --compact
npm test
npm run build
Set-Location ai-service
.\venv\Scripts\python.exe -m pytest tests -q
$env:RUN_MODEL_SMOKE='1'
$env:HF_HUB_OFFLINE='1' # Only if checkpoints are already cached.
.\venv\Scripts\python.exe -B -m unittest discover -s tests -p test_models.py
```

Real CPU model smoke tests check plumbing and normalized vectors, not accuracy. Browser checks use a synthetic photo and a 390-pixel mobile viewport; they do not replace testing on a physical phone. S3 adapter/storage contracts are tested with fake disks; live R2/S3 credentials are still required to validate a real provider.
