<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['teacher_profiles', 'institute_profiles'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->string('youtube_url', 2048)->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['teacher_profiles', 'institute_profiles'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->dropColumn('youtube_url');
            });
        }
    }
};
