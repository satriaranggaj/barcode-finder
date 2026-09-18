<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Development runs with FILESYSTEM_DISK=local; everything stays on the
    | machine (catalog on the public disk, private/temporary data under
    | storage/app/private). Production points the image disks at the S3-
    | compatible object storage below and keeps only transient query photos,
    | index builds and processing files on the server.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Storage layout:
    |
    |   public           Catalog images in development (master/catalog/
    |                    thumbnail). Selected via PRODUCT_IMAGE_DISK.
    |   local            Private app data: pending query images
    |                    (search-pending/), index builds, dev fallback for
    |                    verified/training references (TRAINING_IMAGE_DISK).
    |   s3               Object storage for production: catalog images
    |                    (PRODUCT_IMAGE_DISK=s3) and verified/training
    |                    references (TRAINING_IMAGE_DISK=s3). Works with
    |                    Cloudflare R2 and any S3-compatible provider via
    |                    AWS_ENDPOINT; credentials come only from the
    |                    environment, never from code.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
