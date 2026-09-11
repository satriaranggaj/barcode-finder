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
            $table->timestamp('storage_optimized_at')->nullable();
            $table->string('storage_optimization', 40)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('product_photos', function (Blueprint $table) {
            $table->dropColumn(['storage_optimized_at', 'storage_optimization']);
        });
    }
};
