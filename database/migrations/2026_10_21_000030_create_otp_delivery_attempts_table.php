<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('otp_delivery_attempts', function (Blueprint $table): void {
            $table->char('id', 36)->primary();
            $table->char('otp_code_id', 36)->index();
            $table->string('channel', 20);
            $table->string('provider', 40);
            $table->string('status', 20);
            $table->string('phone_last_four', 4);
            $table->dateTime('created_at', 6)->useCurrent()->index();
            $table->index(['channel', 'status', 'created_at'], 'otp_delivery_channel_status_time');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('otp_delivery_attempts');
    }
};
