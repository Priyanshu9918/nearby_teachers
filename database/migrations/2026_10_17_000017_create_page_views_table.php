<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('page_views', function (Blueprint $table): void {
            $table->char('id', 36)->primary();
            $table->enum('page_type', ['institute', 'teacher', 'city_index', 'city_feed', 'city_directory', 'download', 'app_profile']);
            $table->string('target', 255)->nullable();
            $table->string('platform', 32)->nullable();
            $table->string('referrer', 2048)->nullable();
            $table->string('user_agent', 2048)->nullable();
            $table->dateTime('created_at', 6)->useCurrent();
            $table->index(['page_type', 'target', 'created_at'], 'idx_page_views_type_target');
            $table->index('created_at', 'idx_page_views_created');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('page_views');
    }
};
