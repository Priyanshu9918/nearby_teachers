<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bookings', function (Blueprint $table): void {
            $table->char('id', 36)->primary();
            $table->char('learner_id', 36);
            $table->char('provider_id', 36);
            $table->unsignedInteger('subject_id')->nullable();
            $table->enum('mode', ['in_person', 'home_tuition', 'online']);
            $table->dateTime('requested_time', 6);
            $table->decimal('price', 10, 2)->nullable();
            $table->enum('status', ['requested', 'accepted', 'declined', 'cancelled', 'completed'])->default('requested');
            $table->dateTime('created_at', 6)->useCurrent();
            $table->dateTime('updated_at', 6)->useCurrent();
            $table->index(['provider_id', 'status'], 'idx_bookings_provider');
            $table->index(['learner_id', 'status'], 'idx_bookings_learner');
            $table->foreign('learner_id', 'bookings_learner_fk')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('provider_id', 'bookings_provider_fk')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('subject_id', 'bookings_subject_fk')->references('id')->on('subjects')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bookings');
    }
};
