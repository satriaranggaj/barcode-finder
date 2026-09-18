<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Structured product attributes with provenance. Raw descriptions stay
     * authoritative on products.description; parser rows regenerate
     * idempotently while manual/vision/ocr rows are never touched.
     */
    public function up(): void
    {
        Schema::create('product_attributes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('key', 64);
            $table->string('value', 255);
            $table->string('source', 32);
            $table->string('rule', 64)->nullable();
            $table->float('confidence')->nullable();
            $table->timestamps();

            $table->unique(['product_id', 'source', 'key', 'value'], 'product_attributes_identity_unique');
            $table->index(['source', 'key']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_attributes');
    }
};
