<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Normalized verified-feedback evidence: bounded top candidates with
     * component scores plus confidence fields and selection source. The
     * legacy evidence JSON column is left untouched for existing readers.
     */
    public function up(): void
    {
        Schema::table('search_feedback', function (Blueprint $table) {
            $table->json('candidates')->nullable()->after('confirmed_sku');
            $table->string('confidence', 16)->nullable()->after('candidates');
            $table->double('score_gap')->nullable()->after('confidence');
            $table->string('selection_source', 16)->nullable()->after('score_gap');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('search_feedback', function (Blueprint $table) {
            $table->dropColumn(['candidates', 'confidence', 'score_gap', 'selection_source']);
        });
    }
};
