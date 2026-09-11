<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_photo_features', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_photo_id')->constrained('product_photos')->cascadeOnDelete();
            $table->string('version', 40);
            $table->string('source_path');
            $table->string('source_sha256', 64);
            $table->json('metadata');
            $table->timestamp('processed_at');
            $table->unique(['product_photo_id', 'version']);
        });
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE product_photo_features ADD COLUMN embedding vector(512) NOT NULL');
        } else {
            Schema::table('product_photo_features', fn (Blueprint $table) => $table->text('embedding'));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('product_photo_features');
    }
};
