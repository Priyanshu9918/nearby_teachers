<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teacher_profiles', function (Blueprint $table): void {
            $table->string('public_page_slug', 230)->nullable()->unique();
            $table->char('seo_country', 2)->nullable();
            $table->string('seo_city_slug', 230)->nullable();
            $table->char('seo_state_hash', 64)->nullable();
            $table->index(['seo_country', 'seo_city_slug'], 'teacher_seo_geography');
        });
        Schema::create('seo_subject_routes', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('subject_id');
            $table->char('country', 2);
            $table->string('language', 8);
            $table->string('display_name', 200);
            $table->string('city_slug', 230);
            $table->string('online_slug', 230);
            $table->boolean('enabled')->default(true);
            $table->timestamps();
            $table->unique(['subject_id', 'country', 'language'], 'seo_subject_locale');
            $table->unique(['country', 'language', 'city_slug'], 'seo_city_subject_slug');
            $table->unique(['country', 'language', 'online_slug'], 'seo_online_subject_slug');
        });
        Schema::create('seo_directory_pages', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('route_id');
            $table->unsignedInteger('subject_id');
            $table->char('country', 2);
            $table->string('language', 8);
            $table->string('kind', 8);
            $table->string('city', 120)->nullable();
            $table->string('city_slug', 230);
            $table->string('path', 700)->unique();
            $table->timestamps();
            $table->unique(['route_id', 'kind', 'city_slug'], 'seo_page_identity');
        });
        // URL ownership survives profile deletion; old URLs cannot be reassigned to another teacher.
        Schema::create('seo_urls', function (Blueprint $table): void {
            $table->id();
            $table->text('path');
            $table->char('path_hash', 64)->unique();
            $table->string('target_type', 8);
            $table->unsignedBigInteger('target_id');
            $table->timestamp('created_at')->useCurrent();
            $table->index(['target_type', 'target_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_urls');
        Schema::dropIfExists('seo_directory_pages');
        Schema::dropIfExists('seo_subject_routes');
        Schema::table('teacher_profiles', function (Blueprint $table): void {
            $table->dropIndex('teacher_seo_geography');
            $table->dropUnique(['public_page_slug']);
            $table->dropColumn(['public_page_slug', 'seo_country', 'seo_city_slug', 'seo_state_hash']);
        });
    }
};
