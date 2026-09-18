<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('search_feedback', fn (Blueprint $table) => $table->unique('photo_hash', 'feedback_photo_identity_unique'));
    }

    public function down(): void
    {
        Schema::table('search_feedback', fn (Blueprint $table) => $table->dropUnique('feedback_photo_identity_unique'));
    }
};
