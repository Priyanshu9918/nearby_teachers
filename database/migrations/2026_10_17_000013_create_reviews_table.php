<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reviews', function (Blueprint $table): void {
            $table->char('id', 36)->primary();
            $table->char('booking_id', 36)->unique();
            $table->char('learner_id', 36);
            $table->char('provider_id', 36);
            $table->unsignedTinyInteger('rating');
            $table->text('comment')->nullable();
            $table->dateTime('created_at', 6)->useCurrent();
            $table->index('provider_id', 'idx_reviews_provider');
            $table->foreign('booking_id', 'reviews_booking_fk')->references('id')->on('bookings')->cascadeOnDelete();
            $table->foreign('learner_id', 'reviews_learner_fk')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('provider_id', 'reviews_provider_fk')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reviews');
    }
};
