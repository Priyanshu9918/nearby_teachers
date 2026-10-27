<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teacher_profiles', function (Blueprint $table): void {
            // Subject names can exceed the legacy 255-character slug limit.
            // Lookup uses the indexed user_id suffix, not a TEXT index.
            $table->text('seo_slug')->nullable();
            $table->string('public_profile_slug')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('teacher_profiles', function (Blueprint $table): void {
            $table->dropColumn(['seo_slug', 'public_profile_slug']);
        });
    }
};
