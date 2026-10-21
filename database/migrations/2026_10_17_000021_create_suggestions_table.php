<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suggestions', function (Blueprint $table): void {
            $table->char('id', 36)->primary();
            $table->char('submitted_by', 36);
            $table->enum('suggestion_type', ['course', 'city', 'state', 'country']);
            $table->string('name');
            $table->text('notes')->nullable();
            $table->enum('status', ['open', 'added', 'declined'])->default('open');
            $table->dateTime('created_at', 6)->useCurrent();
            $table->index(['status', 'created_at'], 'idx_suggestions_status');
            $table->foreign('submitted_by', 'suggestions_submitter_fk')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suggestions');
    }
};
