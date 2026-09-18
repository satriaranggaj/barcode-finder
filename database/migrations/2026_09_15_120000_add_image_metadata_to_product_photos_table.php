<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('product_photos', function (Blueprint $table) {
            if (!Schema::hasColumn('product_photos', 'master_path')) {
                $table->string('master_path')->nullable()->after('path');
            }
            
            if (!Schema::hasColumn('product_photos', 'thumbnail_path')) {
                $table->string('thumbnail_path')->nullable()->after('master_path');
            }
            
            if (!Schema::hasColumn('product_photos', 'source')) {
                $table->string('source')->default('manual_reference')->after('selection_verified');
            }
            
            if (!Schema::hasColumn('product_photos', 'photo_hash')) {
                $table->char('photo_hash', 64)->nullable()->after('source');
            }
            
            if (!Schema::hasColumn('product_photos', 'index_status')) {
                $table->string('index_status')->default('pending')->after('photo_hash');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('product_photos', function (Blueprint $table) {
            $table->dropColumn(['master_path', 'thumbnail_path', 'source', 'photo_hash', 'index_status']);
        });
    }
};