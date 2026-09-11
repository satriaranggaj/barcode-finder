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
        DB::statement("CREATE INDEX CONCURRENTLY IF NOT EXISTS product_photo_features_object_v2_hnsw ON product_photo_features USING hnsw (embedding vector_cosine_ops) WHERE version = 'object-v2'");
        $index = DB::selectOne("SELECT indisvalid FROM pg_index WHERE indexrelid = to_regclass('product_photo_features_object_v2_hnsw')");
        if (! $index || ! $index->indisvalid) {
            throw new RuntimeException('Invalid object-v2 HNSW index; drop only that invalid index concurrently and retry.');
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS product_photo_features_object_v2_hnsw');
        }
    }
};
