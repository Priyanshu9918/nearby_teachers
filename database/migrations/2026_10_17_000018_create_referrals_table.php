<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('referrals', function (Blueprint $table): void {
            $table->char('id', 36)->primary();
            $table->char('referrer_id', 36);
            $table->char('referred_id', 36)->unique();
            $table->dateTime('created_at', 6)->useCurrent();
            $table->index('referrer_id', 'idx_referrals_referrer');
            $table->foreign('referrer_id', 'referrals_referrer_fk')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('referred_id', 'referrals_referred_fk')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('referrals');
    }
};
