<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Track per-feedback index membership for auto incremental indexing.
     * Null = not yet in any published FAISS generation; set only after a
     * generation containing the reference is published. Unlike
     * training_exported_at (training snapshots), this gates retrieval memory.
     */
    public function up(): void
    {
        Schema::table('search_feedback', function (Blueprint $table): void {
            if (! Schema::hasColumn('search_feedback', 'indexed_at')) {
                $table->timestamp('indexed_at')->nullable()->after('training_exported_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('search_feedback', function (Blueprint $table): void {
            $table->dropColumn('indexed_at');
        });
    }
};
