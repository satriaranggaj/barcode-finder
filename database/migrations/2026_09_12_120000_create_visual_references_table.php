<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('visual_references', function (Blueprint $table): void {
            $table->foreignId('photo_id')->primary()->constrained('product_photos')->cascadeOnDelete();
            $table->string('pipeline', 64)->index();
            $table->string('source_path');
            $table->char('source_hash', 64);
            $table->json('features');
            $table->timestamps();
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE visual_references ADD COLUMN embedding vector(512) NOT NULL');
            DB::statement('CREATE INDEX visual_references_hnsw ON visual_references USING hnsw (embedding vector_cosine_ops)');
        } else {
            Schema::table('visual_references', fn (Blueprint $table) => $table->text('embedding'));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('visual_references');
    }
};
