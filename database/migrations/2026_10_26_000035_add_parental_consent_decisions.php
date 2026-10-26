<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dateTime('parental_consent_expires_at', 6)->nullable();
            $table->dateTime('parental_consent_rejected_at', 6)->nullable();
            $table->index('parental_consent_token', 'users_parental_consent_token_index');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex('users_parental_consent_token_index');
            $table->dropColumn(['parental_consent_expires_at', 'parental_consent_rejected_at']);
        });
    }
};
