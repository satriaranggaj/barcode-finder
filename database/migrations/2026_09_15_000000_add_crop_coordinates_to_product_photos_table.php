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
            if (!Schema::hasColumn('product_photos', 'crop')) {
                $table->json('crop')->nullable()->after('path');
            }
            
            if (!Schema::hasColumn('product_photos', 'selection_source')) {
                $table->string('selection_source')->default('auto')->after('crop');
            }
            
            if (!Schema::hasColumn('product_photos', 'selection_verified')) {
                $table->boolean('selection_verified')->default(false)->after('selection_source');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('product_photos', function (Blueprint $table) {
            $table->dropColumn(['crop', 'selection_source', 'selection_verified']);
        });
    }
};
