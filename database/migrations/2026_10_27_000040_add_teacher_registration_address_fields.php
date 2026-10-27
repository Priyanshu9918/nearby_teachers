<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teacher_profiles', function (Blueprint $table): void {
            $table->string('district', 120)->nullable();
            $table->string('sub_district', 120)->nullable();
            $table->string('locality', 160)->nullable();
            $table->string('locality_type', 16)->nullable();
            $table->string('postal_code', 32)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('teacher_profiles', function (Blueprint $table): void {
            $table->dropColumn(['district', 'sub_district', 'locality', 'locality_type', 'postal_code']);
        });
    }
};
