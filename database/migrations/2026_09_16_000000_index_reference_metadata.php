<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_photos', function (Blueprint $table) {
            if (! Schema::hasColumn('product_photos', 'disk')) {
                $table->string('disk')->default('public');
            }
            $table->index('photo_hash', 'product_photos_identity_idx');
            $table->index('index_status', 'product_photos_index_status_idx');
            $table->index('source', 'product_photos_source_idx');
        });
    }

    public function down(): void
    {
        Schema::table('product_photos', function (Blueprint $table) {
            $table->dropIndex('product_photos_identity_idx');
            $table->dropIndex('product_photos_index_status_idx');
            $table->dropIndex('product_photos_source_idx');
            if (Schema::hasColumn('product_photos', 'disk')) {
                $table->dropColumn('disk');
            }
        });
    }
};
