<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Audit status for training exports: distinguishes verified rows that
     * have been included in an offline training dataset from those that
     * have not. Never altered by search or confirmation flows.
     */
    public function up(): void
    {
        Schema::table('search_feedback', function (Blueprint $table) {
            $table->timestamp('training_exported_at')->nullable()->after('training_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('search_feedback', function (Blueprint $table) {
            $table->dropColumn('training_exported_at');
        });
    }
};
