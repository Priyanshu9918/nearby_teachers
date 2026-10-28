<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seo_location_pages', function (Blueprint $table): void {
            $table->id();
            $table->string('kind', 24);
            $table->char('country', 2);
            $table->string('language', 8);
            $table->string('district', 120)->nullable();
            $table->string('district_slug', 180)->nullable();
            $table->string('village', 160)->nullable();
            $table->string('village_slug', 180)->nullable();
            $table->unsignedInteger('subject_id')->nullable();
            $table->string('subject_slug', 230)->nullable();
            $table->string('path', 700)->unique();
            $table->timestamps();
            $table->index(['country', 'language', 'kind'], 'seo_location_scope');
            $table->index(['country', 'district_slug', 'village_slug'], 'seo_location_place');
            $table->index('subject_id');
            $table->foreign('subject_id', 'seo_location_subject_fk')->references('id')->on('subjects')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_location_pages');
    }
};
