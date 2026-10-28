<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teacher_profiles', function (Blueprint $table): void {
            $table->json('completed_profile_steps')->nullable();
            $table->timestamp('profile_draft_saved_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('teacher_profiles', function (Blueprint $table): void {
            $table->dropColumn(['completed_profile_steps', 'profile_draft_saved_at']);
        });
    }
};
