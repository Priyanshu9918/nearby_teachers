<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messages', function (Blueprint $table): void {
            $table->char('id', 36)->primary();
            $table->char('conversation_id', 36);
            $table->char('sender_id', 36);
            $table->text('body');
            $table->dateTime('read_at', 6)->nullable();
            $table->dateTime('created_at', 6)->useCurrent();
            $table->index(['conversation_id', 'created_at'], 'idx_messages_conversation');
            $table->foreign('conversation_id', 'messages_conversation_fk')->references('id')->on('conversations')->cascadeOnDelete();
            $table->foreign('sender_id', 'messages_sender_fk')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
