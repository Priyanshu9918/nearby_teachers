<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->char('id', 36)->primary();
            $table->enum('account_type', ['learner', 'teacher', 'institute']);
            $table->string('full_name', 160);
            $table->string('email')->nullable()->unique();
            $table->string('phone', 32)->nullable()->unique();
            $table->string('google_id', 128)->nullable()->unique();
            $table->text('avatar_url')->nullable();
            $table->boolean('is_admin')->default(false);
            $table->date('date_of_birth')->nullable();
            $table->boolean('is_minor')->default(false);
            $table->string('guardian_name', 160)->nullable();
            $table->string('guardian_contact')->nullable();
            $table->string('parental_consent_token', 128)->nullable();
            $table->dateTime('parental_consent_confirmed_at', 6)->nullable();
            $table->dateTime('safety_disclaimer_acknowledged_at', 6)->nullable();
            $table->enum('avatar_status', ['pending', 'approved', 'rejected'])->default('approved');
            $table->string('preferred_language', 8)->default('en');
            $table->dateTime('suspended_at', 6)->nullable();
            $table->text('suspended_reason')->nullable();
            $table->char('suspended_by', 36)->nullable();
            $table->dateTime('phone_verified_at', 6)->nullable();
            $table->string('referral_code', 32)->nullable()->unique();
            $table->dateTime('created_at', 6)->useCurrent();
            $table->dateTime('updated_at', 6)->useCurrent();
            $table->foreign('suspended_by', 'users_suspended_by_fk')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
