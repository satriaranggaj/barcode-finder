<?php

return [
    // FAISS /search is the primary retrieval driver. Switch to 'legacy' only for
    // environments without a running ai-service (pgvector cosine fallback).
    'driver' => env('SKU_SEARCH_DRIVER', 'faiss'),
    'connect_timeout' => (int) env('SKU_SEARCH_CONNECT_TIMEOUT', 3),
    'read_timeout' => (int) env('SKU_SEARCH_READ_TIMEOUT', 20),
    'private_disk' => env('TRAINING_IMAGE_DISK', 'local'),
    // Transient query photos must stay on the app server (local) and are
    // pruned within minutes; only confirmed references move to private_disk.
    'query_temp_disk' => env('QUERY_IMAGE_DISK', 'local'),
    // FILTER_VALIDATE_BOOLEAN: a literal SEARCH_FEEDBACK_ENABLED=false in .env
    // must stay disabled ((bool) "false" === true in PHP).
    'feedback_enabled' => filter_var(env('SEARCH_FEEDBACK_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
    'reference_min_blur' => (float) env('REFERENCE_MIN_BLUR', 60),
    'reference_min_side' => (int) env('REFERENCE_MIN_SIDE', 128),
    'reference_min_crop_pixels' => (int) env('REFERENCE_MIN_CROP_PIXELS', 16384),
    'reference_min_std' => (float) env('REFERENCE_MIN_STD', 8),
    // Perceptual-hash Hamming threshold for near duplicates within one SKU.
    // Conservative and scoped: similarity is a flag, never proof of sameness.
    'reference_max_dhash_distance' => (int) env('REFERENCE_MAX_DHASH_DISTANCE', 4),
    // Empty-string env values (e.g. `KEY=` copied from .env.example) must fall
    // back to defaults: env() returns "" instead of the default for them.
    'python' => ($python = env('AI_PYTHON_BINARY')) !== null && $python !== ''
        ? $python
        : (PHP_OS_FAMILY === 'Windows' ? base_path('ai-service/venv/Scripts/python.exe') : base_path('ai-service/venv/bin/python')),
    'index_path' => ($indexPath = env('FAISS_INDEX_PATH')) !== null && $indexPath !== ''
        ? $indexPath
        : base_path('ai-service/indexes'),
    // Retention for published FAISS generations and stale build artifacts.
    // keep<=0 keeps every generation; stale_hours/days<=0 disable that pruner.
    'index_generations_keep' => ($v = env('INDEX_GENERATIONS_KEEP')) !== null && $v !== '' ? (int) $v : 3,
    'index_generation_grace_seconds' => ($v = env('INDEX_GENERATION_GRACE_SECONDS')) !== null && $v !== '' ? (int) $v : 3600,
    'index_build_stale_hours' => ($v = env('INDEX_BUILD_STALE_HOURS')) !== null && $v !== '' ? (int) $v : 24,
    'index_export_retention_days' => ($v = env('INDEX_EXPORT_RETENTION_DAYS')) !== null && $v !== '' ? (int) $v : 3,
];
