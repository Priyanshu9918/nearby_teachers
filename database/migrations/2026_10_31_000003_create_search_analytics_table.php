<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('search_analytics', function (Blueprint $table): void {
            $table->id();
            $table->string('search_type', 32);
            $table->string('location_type', 20);
            $table->string('location', 160)->nullable();
            $table->decimal('latitude_band', 4, 1)->nullable();
            $table->decimal('longitude_band', 4, 1)->nullable();
            $table->decimal('radius_km', 5, 1)->nullable();
            $table->unsignedInteger('subject_id')->nullable();
            $table->unsignedInteger('result_count')->default(0);
            $table->boolean('free_only')->default(false);
            $table->dateTime('created_at', 6)->useCurrent();
            $table->index(['created_at', 'result_count'], 'search_analytics_time_results');
            $table->index(['location_type', 'location', 'created_at'], 'search_analytics_location_time');
            $table->index(['subject_id', 'created_at'], 'search_analytics_subject_time');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('search_analytics');
    }
};
