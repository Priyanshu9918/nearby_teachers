<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('phone_reveals', function (Blueprint $table): void {
            $table->char('id', 36)->primary();
            $table->char('viewer_id', 36);
            $table->char('profile_user_id', 36);
            $table->dateTime('revealed_at', 6)->useCurrent();
            $table->index(['viewer_id', 'revealed_at'], 'idx_phone_reveals_viewer_day');
            $table->foreign('viewer_id', 'phone_reveals_viewer_fk')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('profile_user_id', 'phone_reveals_profile_fk')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('phone_reveals');
    }
};
