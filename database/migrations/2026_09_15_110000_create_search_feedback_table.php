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
        Schema::create('search_feedback', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('confirmed_product_id')->constrained('products')->cascadeOnDelete();
            $table->string('predicted_sku')->nullable();
            $table->string('confirmed_sku');
            $table->string('disk');
            $table->string('query_image_path');
            $table->char('photo_hash', 64);
            $table->char('dhash', 16);
            $table->json('crop')->nullable();
            $table->json('evidence')->nullable();
            $table->boolean('reference_eligible')->default(false);
            $table->string('training_status')->default('pending');
            $table->timestamps();

            $table->index('photo_hash');
            $table->index('confirmed_sku');
            $table->index('training_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('search_feedback');
    }
};