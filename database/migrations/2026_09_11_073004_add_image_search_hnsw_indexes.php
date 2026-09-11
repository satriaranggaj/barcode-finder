<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS product_photos_embedding_hnsw ON product_photos USING hnsw (embedding vector_cosine_ops)');
        DB::statement("CREATE INDEX CONCURRENTLY IF NOT EXISTS product_photo_features_object_v1_hnsw ON product_photo_features USING hnsw (embedding vector_cosine_ops) WHERE version = 'object-v1'");
        foreach (['product_photos_embedding_hnsw', 'product_photo_features_object_v1_hnsw'] as $name) {
            $index = DB::selectOne('SELECT indisvalid FROM pg_index WHERE indexrelid = to_regclass(?)', [$name]);
            if (! $index || ! $index->indisvalid) {
                throw new RuntimeException("Invalid index {$name}; drop only that invalid index concurrently and retry this migration.");
            }
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS product_photo_features_object_v1_hnsw');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS product_photos_embedding_hnsw');
    }
};
