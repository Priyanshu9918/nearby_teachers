<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('blocks', function (Blueprint $table): void {
            $table->char('id', 36)->primary();
            $table->char('blocker_id', 36);
            $table->char('blocked_id', 36);
            $table->dateTime('created_at', 6)->useCurrent();
            $table->unique(['blocker_id', 'blocked_id'], 'blocks_blocker_blocked_unique');
            $table->foreign('blocker_id', 'blocks_blocker_fk')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('blocked_id', 'blocks_blocked_fk')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blocks');
    }
};
