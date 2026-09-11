<?php

return [
    'optimize_uploads' => (bool) env('PRODUCT_IMAGES_OPTIMIZE_UPLOADS', true),
    'quality' => (int) env('PRODUCT_IMAGES_WEBP_QUALITY', 88),
    'max_pixels' => (int) env('PRODUCT_IMAGES_MAX_PIXELS', 20000000),
    'max_bytes' => (int) env('PRODUCT_IMAGES_MAX_BYTES', 20971520),
    'memory_budget_mb' => (int) env('PRODUCT_IMAGES_MEMORY_BUDGET_MB', 256),
    'max_uploads' => (int) env('PRODUCT_IMAGES_MAX_UPLOADS', 10),
];
