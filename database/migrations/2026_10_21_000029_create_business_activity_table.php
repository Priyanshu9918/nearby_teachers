<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_activity', function (Blueprint $table): void {
            $table->char('id', 36)->primary();
            $table->string('event', 80);
            $table->string('entity_type', 40);
            $table->string('entity_id', 64);
            // No cascading foreign keys: events must survive source-record deletion.
            $table->char('actor_user_id', 36)->nullable();
            $table->char('related_user_id', 36)->nullable();
            $table->unsignedSmallInteger('version')->default(1);
            $table->json('before_data');
            $table->json('after_data');
            $table->dateTime('created_at', 6)->useCurrent()->index();
            $table->index(['entity_type', 'entity_id', 'created_at'], 'activity_entity_time');
            $table->index(['actor_user_id', 'created_at'], 'activity_actor_time');
            $table->index(['related_user_id', 'created_at'], 'activity_related_time');
            $table->index(['event', 'created_at'], 'activity_event_time');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_activity');
    }
};
