<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teacher_profiles', function (Blueprint $table): void {
            $table->char('id', 36)->primary();
            $table->char('user_id', 36)->unique();
            $table->text('bio')->nullable();
            $table->decimal('rate_per_hour', 10, 2)->nullable();
            $table->char('currency', 3)->default('INR');
            $table->json('lesson_modes');
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->string('address_text', 1000)->nullable();
            $table->string('city', 120)->nullable();
            $table->string('state', 120)->nullable();
            $table->string('country', 120)->nullable();
            $table->char('country_code', 2)->nullable();
            $table->string('linkedin_url', 2048)->nullable();
            $table->string('slug')->nullable()->unique();
            $table->boolean('is_verified')->default(false);
            $table->dateTime('verified_at', 6)->nullable();
            $table->smallInteger('trial_days')->default(0);
            $table->decimal('rating_avg', 3, 2)->default(0);
            $table->integer('rating_count')->default(0);
            $table->dateTime('created_at', 6)->useCurrent();
            $table->dateTime('updated_at', 6)->useCurrent();
            $table->index(['latitude', 'longitude'], 'idx_teacher_profiles_location');
            $table->index('city', 'idx_teacher_profiles_city');
            $table->foreign('user_id', 'teacher_profiles_user_fk')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('teacher_profiles');
    }
};
