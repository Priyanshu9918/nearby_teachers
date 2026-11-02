<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('push_devices', function (Blueprint $table): void {
            $table->char('id', 36)->primary();
            $table->char('user_id', 36);
            $table->char('fid_hash', 64)->unique();
            // FCM instance identifiers are encrypted at rest before storage.
            $table->longText('fid_ciphertext')->nullable();
            $table->string('platform', 16);
            $table->string('location_city', 120)->nullable();
            $table->string('location_state', 120)->nullable();
            $table->string('location_country', 120)->nullable();
            $table->boolean('location_consent')->default(false);
            $table->string('location_source', 24)->nullable();
            $table->dateTime('last_registered_at', 6)->nullable();
            $table->dateTime('last_seen_at', 6)->nullable();
            $table->dateTime('disabled_at', 6)->nullable();
            $table->dateTime('created_at', 6)->useCurrent();
            $table->dateTime('updated_at', 6)->useCurrent();
            $table->index(['user_id', 'disabled_at'], 'push_devices_user_active');
            $table->index(['location_country', 'location_state', 'location_city'], 'push_devices_location');
            $table->foreign('user_id', 'push_devices_user_fk')->references('id')->on('users')->cascadeOnDelete();
        });

        Schema::create('push_campaigns', function (Blueprint $table): void {
            $table->char('id', 36)->primary();
            $table->string('title', 120);
            $table->text('body');
            $table->json('data')->nullable();
            $table->string('target_type', 20);
            $table->json('target_filters')->nullable();
            $table->unsignedInteger('recipient_count')->default(0);
            $table->string('status', 32)->default('queued');
            $table->string('created_by_email')->nullable();
            $table->dateTime('queued_at', 6)->nullable();
            $table->dateTime('started_at', 6)->nullable();
            $table->dateTime('finished_at', 6)->nullable();
            $table->dateTime('created_at', 6)->useCurrent();
            $table->dateTime('updated_at', 6)->useCurrent();
            $table->index(['status', 'created_at'], 'push_campaigns_status_created');
        });

        Schema::create('push_deliveries', function (Blueprint $table): void {
            $table->char('id', 36)->primary();
            $table->char('campaign_id', 36);
            // A plain ID preserves delivery history if the app later removes a device row.
            $table->char('device_id', 36);
            $table->string('status', 20)->default('pending');
            $table->string('provider_message_id', 255)->nullable();
            $table->string('error_code', 120)->nullable();
            $table->unsignedTinyInteger('attempt_count')->default(0);
            $table->dateTime('attempted_at', 6)->nullable();
            $table->dateTime('created_at', 6)->useCurrent();
            $table->dateTime('updated_at', 6)->useCurrent();
            $table->unique(['campaign_id', 'device_id'], 'push_delivery_campaign_device');
            $table->index(['campaign_id', 'status'], 'push_delivery_campaign_status');
            $table->foreign('campaign_id', 'push_delivery_campaign_fk')->references('id')->on('push_campaigns')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_deliveries');
        Schema::dropIfExists('push_campaigns');
        Schema::dropIfExists('push_devices');
    }
};
