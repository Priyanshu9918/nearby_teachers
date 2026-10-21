<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('otp_codes', function (Blueprint $table): void {
            $table->char('id', 36)->primary();
            $table->string('phone', 32);
            $table->string('code_hash');
            $table->dateTime('expires_at', 6);
            $table->integer('attempts')->default(0);
            $table->dateTime('consumed_at', 6)->nullable();
            $table->dateTime('created_at', 6)->useCurrent();
            $table->index(['phone', 'created_at'], 'idx_otp_codes_phone');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('otp_codes');
    }
};
