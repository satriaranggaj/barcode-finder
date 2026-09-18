<?php

return [
    'disk' => env('PRODUCT_IMAGE_DISK', 'public'),
    'quality' => (int) env('PRODUCT_IMAGE_QUALITY', 88),
    'catalog_quality' => (int) env('PRODUCT_CATALOG_QUALITY', 83),
    'thumbnail_quality' => (int) env('PRODUCT_THUMBNAIL_QUALITY', 78),
    'catalog_side' => (int) env('PRODUCT_CATALOG_SIDE', 1600),
    'thumbnail_side' => (int) env('PRODUCT_THUMBNAIL_SIDE', 400),
];
