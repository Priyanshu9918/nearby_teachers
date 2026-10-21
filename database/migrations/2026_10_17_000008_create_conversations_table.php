<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table): void {
            $table->char('id', 36)->primary();
            $table->char('learner_id', 36);
            $table->char('provider_id', 36);
            $table->dateTime('created_at', 6)->useCurrent();
            $table->unique(['learner_id', 'provider_id'], 'conversations_learner_provider_unique');
            $table->foreign('learner_id', 'conversations_learner_fk')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('provider_id', 'conversations_provider_fk')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversations');
    }
};
