<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->unsignedInteger('referral_points')->default(0)->after('referral_code');
            $table->index('referral_points', 'users_referral_points_idx');
        });

        // Preserve points for referrals already credited before this counter existed.
        DB::statement('UPDATE users u LEFT JOIN (SELECT referrer_id, count(*) AS points FROM referrals GROUP BY referrer_id) r ON r.referrer_id = u.id SET u.referral_points = COALESCE(r.points, 0)');
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex('users_referral_points_idx');
            $table->dropColumn('referral_points');
        });
    }
};
