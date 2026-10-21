<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('posts', function (Blueprint $table): void {
            $table->char('id', 36)->primary();
            $table->char('author_id', 36);
            $table->enum('post_type', ['offer', 'request']);
            $table->string('city', 160);
            $table->unsignedInteger('subject_id')->nullable();
            $table->text('body');
            $table->string('image_url', 2048)->nullable();
            $table->dateTime('created_at', 6)->useCurrent();
            $table->index(['city', 'created_at'], 'idx_posts_city');
            $table->foreign('author_id', 'posts_author_fk')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('subject_id', 'posts_subject_fk')->references('id')->on('subjects')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('posts');
    }
};
