<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $numeric = str_contains(strtolower((string) DB::selectOne("SHOW COLUMNS FROM `users` LIKE 'id'")->Type), 'bigint');
        $this->createTableUnlessPresent('push_devices', [
            'id', 'user_id', 'fid_hash', 'fid_ciphertext', 'platform', 'location_city', 'location_state',
            'location_country', 'location_consent', 'location_source', 'last_registered_at', 'last_seen_at',
            'disabled_at', 'created_at', 'updated_at',
        ], function (Blueprint $table) use ($numeric): void {
            $numeric ? $table->id() : $table->char('id', 36)->primary();
            $numeric ? $table->unsignedBigInteger('user_id') : $table->char('user_id', 36);
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

        $this->createTableUnlessPresent('push_campaigns', [
            'id', 'title', 'body', 'data', 'target_type', 'target_filters', 'recipient_count', 'status',
            'created_by_email', 'queued_at', 'started_at', 'finished_at', 'created_at', 'updated_at',
        ], function (Blueprint $table) use ($numeric): void {
            $numeric ? $table->id() : $table->char('id', 36)->primary();
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

        $this->createTableUnlessPresent('push_deliveries', [
            'id', 'campaign_id', 'device_id', 'status', 'provider_message_id', 'error_code', 'attempt_count',
            'attempted_at', 'created_at', 'updated_at',
        ], function (Blueprint $table) use ($numeric): void {
            $numeric ? $table->id() : $table->char('id', 36)->primary();
            $numeric ? $table->unsignedBigInteger('campaign_id') : $table->char('campaign_id', 36);
            // A plain ID preserves delivery history if the app later removes a device row.
            $numeric ? $table->unsignedBigInteger('device_id') : $table->char('device_id', 36);
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

    /**
     * MySQL keeps successful DDL statements when a later statement in a migration fails.
     * Preserve a matching table on retry, but fail clearly if its columns do not match.
     */
    private function createTableUnlessPresent(string $name, array $requiredColumns, Closure $definition): void
    {
        if (! Schema::hasTable($name)) {
            Schema::create($name, $definition);

            return;
        }

        $missingColumns = array_values(array_filter(
            $requiredColumns,
            static fn (string $column): bool => ! Schema::hasColumn($name, $column),
        ));

        if ($missingColumns !== []) {
            throw new RuntimeException(sprintf(
                'Existing table [%s] is missing required columns: %s. Inspect and reconcile its schema before retrying this migration.',
                $name,
                implode(', ', $missingColumns),
            ));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('push_deliveries');
        Schema::dropIfExists('push_campaigns');
        Schema::dropIfExists('push_devices');
    }
};
