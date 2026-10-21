<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dateTime('email_verified_at', 6)->nullable();
            $table->unsignedInteger('auth_version')->default(0);
            $table->dateTime('auth_tokens_invalid_before', 6)->nullable();
        });
        Schema::create('email_otp_codes', function (Blueprint $table): void {
            $table->char('id', 36)->primary();
            $table->char('user_id', 36);
            $table->string('email');
            $table->enum('purpose', ['password_reset', 'email_verification']);
            $table->string('code_hash');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->dateTime('expires_at', 6);
            $table->index('expires_at');
            $table->dateTime('consumed_at', 6)->nullable();
            $table->dateTime('created_at', 6)->useCurrent();
            $table->index(['user_id', 'purpose', 'consumed_at'], 'email_otp_user_purpose');
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_otp_codes');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn([
            'email_verified_at', 'auth_version', 'auth_tokens_invalid_before',
        ]));
    }
};
