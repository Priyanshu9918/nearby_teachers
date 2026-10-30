<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('comment_likes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('comment_id')->constrained('post_comments')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->dateTime('created_at', 6)->useCurrent();
            $table->unique(['comment_id', 'user_id'], 'comment_likes_comment_user_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comment_likes');
    }
};
