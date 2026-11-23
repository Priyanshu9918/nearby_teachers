<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('registration_email_verifications', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('email')->index();
            $table->string('code_hash');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->dateTime('expires_at', 6)->index();
            $table->dateTime('verified_at', 6)->nullable();
            $table->dateTime('consumed_at', 6)->nullable();
            $table->dateTime('created_at', 6)->useCurrent();
            $table->index(['email', 'consumed_at', 'verified_at'], 'registration_email_verify_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('registration_email_verifications');
    }
};
