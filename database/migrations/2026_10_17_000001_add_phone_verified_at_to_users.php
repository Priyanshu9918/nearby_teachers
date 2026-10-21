<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('users') && ! Schema::hasColumn('users', 'phone_verified_at')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->dateTime('phone_verified_at', 6)->nullable();
            });
        }

        if (Schema::hasTable('users') && Schema::hasColumn('users', 'phone_verified_at')) {
            DB::table('users')->whereNotNull('phone')->whereNull('phone_verified_at')->update([
                'phone_verified_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('users') && Schema::hasColumn('users', 'phone_verified_at')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->dropColumn('phone_verified_at');
            });
        }
    }
};
