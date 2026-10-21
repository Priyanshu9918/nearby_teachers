<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('post_comments', function (Blueprint $table): void {
            $table->char('id', 36)->primary();
            $table->char('post_id', 36);
            $table->char('user_id', 36);
            $table->char('parent_comment_id', 36)->nullable();
            $table->text('body');
            $table->dateTime('created_at', 6)->useCurrent();
            $table->index(['post_id', 'created_at'], 'idx_post_comments_post');
            $table->index('parent_comment_id', 'idx_post_comments_parent');
            $table->foreign('post_id', 'post_comments_post_fk')->references('id')->on('posts')->cascadeOnDelete();
            $table->foreign('user_id', 'post_comments_user_fk')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('parent_comment_id', 'post_comments_parent_fk')->references('id')->on('post_comments')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('post_comments');
    }
};
