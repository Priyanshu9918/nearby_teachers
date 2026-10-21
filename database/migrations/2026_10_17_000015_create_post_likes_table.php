<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('post_likes', function (Blueprint $table): void {
            $table->char('id', 36)->primary();
            $table->char('post_id', 36);
            $table->char('user_id', 36);
            $table->dateTime('created_at', 6)->useCurrent();
            $table->unique(['post_id', 'user_id'], 'post_likes_post_user_unique');
            $table->foreign('post_id', 'post_likes_post_fk')->references('id')->on('posts')->cascadeOnDelete();
            $table->foreign('user_id', 'post_likes_user_fk')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('post_likes');
    }
};
