<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_presence_devices', function (Blueprint $table): void {
            $table->id();
            // Ephemeral state, not business history. No FK: old ID-conversion
            // rehearsals can create this empty table before converting users.
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('auth_version');
            $table->char('device_hash', 64);
            $table->unsignedBigInteger('activity_at');
            $table->unsignedBigInteger('heartbeat_at');
            $table->unsignedBigInteger('active_until');
            $table->unsignedBigInteger('expires_at');
            $table->unique(['user_id', 'device_hash'], 'presence_user_device_unique');
            $table->index(['user_id', 'auth_version', 'active_until'], 'presence_user_active');
            $table->index(['expires_at', 'id'], 'presence_expiry_cleanup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_presence_devices');
    }
};
