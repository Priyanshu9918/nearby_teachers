<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // No backfill: historical form-submission times were not recorded.
        Schema::table('users', function (Blueprint $table): void {
            $table->dateTime('registration_submitted_at', 6)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('registration_submitted_at'));
    }
};
