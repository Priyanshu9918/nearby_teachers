<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('search_analytics', function (Blueprint $table): void {
            $table->decimal('min_price', 10, 2)->nullable();
            $table->decimal('max_price', 10, 2)->nullable();
            $table->char('currency', 3)->nullable();
            $table->string('lesson_mode', 20)->nullable();
            $table->unsignedTinyInteger('trial_days')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('search_analytics', function (Blueprint $table): void {
            $table->dropColumn(['min_price', 'max_price', 'currency', 'lesson_mode', 'trial_days']);
        });
    }
};
