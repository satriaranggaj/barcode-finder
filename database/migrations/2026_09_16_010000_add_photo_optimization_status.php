<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_photos', fn (Blueprint $table) => $table->string('optimization_status')->nullable());
    }

    public function down(): void
    {
        Schema::table('product_photos', fn (Blueprint $table) => $table->dropColumn('optimization_status'));
    }
};
