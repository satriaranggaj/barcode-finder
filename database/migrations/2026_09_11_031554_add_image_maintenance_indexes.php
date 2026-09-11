<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS product_photos_storage_pending_index ON product_photos (id) WHERE storage_optimized_at IS NULL');
            DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS product_photos_path_index ON product_photos (path)');
            DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS products_photo_index ON products (photo)');

            foreach (['product_photos_storage_pending_index', 'product_photos_path_index', 'products_photo_index'] as $name) {
                $index = DB::selectOne('SELECT indisvalid FROM pg_index WHERE indexrelid = to_regclass(?)', [$name]);
                if (! $index || ! $index->indisvalid) {
                    throw new RuntimeException("Index {$name} tidak valid. DROP INDEX CONCURRENTLY {$name}, lalu ulangi migration.");
                }
            }

            return;
        }
        Schema::table('product_photos', function (Blueprint $table): void {
            $table->index(['storage_optimized_at', 'id'], 'product_photos_storage_pending_index');
            $table->index('path');
        });
        Schema::table('products', fn (Blueprint $table) => $table->index('photo'));
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS product_photos_storage_pending_index');
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS product_photos_path_index');
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS products_photo_index');

            return;
        }
        Schema::table('product_photos', function (Blueprint $table): void {
            $table->dropIndex('product_photos_storage_pending_index');
            $table->dropIndex('product_photos_path_index');
        });
        Schema::table('products', fn (Blueprint $table) => $table->dropIndex('products_photo_index'));
    }
};
