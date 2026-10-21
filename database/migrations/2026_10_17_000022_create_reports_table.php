<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reports', function (Blueprint $table): void {
            $table->char('id', 36)->primary();
            $table->char('reporter_id', 36);
            $table->enum('target_type', ['user', 'post', 'comment', 'message']);
            $table->char('target_id', 36);
            $table->enum('reason_category', ['fraud', 'fake_profile', 'inappropriate', 'spam', 'other']);
            $table->text('reason')->nullable();
            $table->enum('status', ['open', 'reviewed', 'dismissed'])->default('open');
            $table->dateTime('created_at', 6)->useCurrent();
            $table->index(['reason_category', 'status'], 'idx_reports_category');
            $table->index(['target_type', 'target_id'], 'idx_reports_target');
            $table->foreign('reporter_id', 'reports_reporter_fk')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reports');
    }
};
