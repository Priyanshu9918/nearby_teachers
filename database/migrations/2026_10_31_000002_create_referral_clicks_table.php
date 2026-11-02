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
        Schema::create('referral_clicks', function (Blueprint $table) use ($numeric): void {
            $numeric ? $table->id() : $table->char('id', 36)->primary();
            $numeric ? $table->unsignedBigInteger('referrer_id') : $table->char('referrer_id', 36);
            $table->char('visitor_hash', 64);
            $table->dateTime('created_at', 6)->useCurrent();
            $table->unique(['referrer_id', 'visitor_hash'], 'referral_click_visitor_unique');
            $table->index('created_at', 'referral_clicks_created_idx');
            // No cascading foreign key: referral click history remains append-only.
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('referral_clicks');
    }
};
