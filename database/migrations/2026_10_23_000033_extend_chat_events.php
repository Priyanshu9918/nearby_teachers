<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table): void {
            $table->dateTime('delivered_at', 6)->nullable();
            $table->dateTime('edited_at', 6)->nullable();
            $table->dateTime('deleted_at', 6)->nullable();
            $table->unsignedInteger('revision')->default(1);
            $table->char('reply_to_id', 36)->nullable()->index();
            $table->index(['conversation_id', 'sender_id', 'read_at'], 'idx_messages_unread');
        });
        Schema::create('message_reactions', function (Blueprint $table): void {
            $table->char('id', 36)->primary();
            // No cascading deletes: the reaction record remains reportable.
            $table->char('message_id', 36)->index();
            $table->char('conversation_id', 36)->index();
            $table->char('user_id', 36)->index();
            $table->string('reaction', 32)->nullable();
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);
            $table->unique(['message_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_reactions');
        Schema::table('messages', function (Blueprint $table): void {
            $table->dropIndex('idx_messages_unread');
            $table->dropIndex(['reply_to_id']);
            $table->dropColumn(['delivered_at', 'edited_at', 'deleted_at', 'revision', 'reply_to_id']);
        });
    }
};
