<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('users') && ! Schema::hasColumn('users', 'phone_pin')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->string('phone_pin', 20)->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('users') && Schema::hasColumn('users', 'phone_pin')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->dropColumn('phone_pin');
            });
        }
    }
};
