<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('profile_subjects', function (Blueprint $table): void {
            $table->index(['subject_id', 'profile_type', 'profile_id'], 'profile_subjects_search_covering');
        });
    }

    public function down(): void
    {
        Schema::table('profile_subjects', function (Blueprint $table): void {
            $table->dropIndex('profile_subjects_search_covering');
        });
    }
};
