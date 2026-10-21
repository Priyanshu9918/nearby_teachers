<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->index(['provider_id', 'created_at'], 'idx_bookings_provider_created');
            $table->index(['learner_id', 'created_at'], 'idx_bookings_learner_created');
        });
        Schema::table('reviews', function (Blueprint $table): void {
            $table->index(['provider_id', 'created_at'], 'idx_reviews_provider_created');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->dropIndex('idx_bookings_provider_created');
            $table->dropIndex('idx_bookings_learner_created');
        });
        Schema::table('reviews', function (Blueprint $table): void {
            $table->dropIndex('idx_reviews_provider_created');
        });
    }
};
