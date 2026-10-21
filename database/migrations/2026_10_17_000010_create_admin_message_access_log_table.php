<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_message_access_log', function (Blueprint $table): void {
            $table->char('id', 36)->primary();
            $table->char('admin_id', 36);
            $table->char('conversation_id', 36);
            $table->dateTime('accessed_at', 6)->useCurrent();
            $table->index(['conversation_id', 'accessed_at'], 'idx_admin_message_access_conversation');
            $table->foreign('admin_id', 'admin_message_access_admin_fk')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('conversation_id', 'admin_message_access_conversation_fk')->references('id')->on('conversations')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_message_access_log');
    }
};
