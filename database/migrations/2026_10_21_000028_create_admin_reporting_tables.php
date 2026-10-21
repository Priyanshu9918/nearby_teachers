<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auth_activity', function (Blueprint $table): void {
            $table->char('id', 36)->primary();
            $table->char('user_id', 36)->nullable()->index();
            $table->string('identifier')->nullable();
            $table->string('event', 40);
            $table->boolean('successful');
            $table->unsignedSmallInteger('status_code');
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 1000)->nullable();
            $table->dateTime('created_at', 6)->useCurrent()->index();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });
        Schema::create('admin_report_access_log', function (Blueprint $table): void {
            $table->char('id', 36)->primary();
            $table->string('admin_email');
            $table->string('report', 80);
            $table->string('record_id', 36)->nullable();
            $table->string('action', 20);
            $table->string('ip_address', 45)->nullable();
            $table->dateTime('created_at', 6)->useCurrent()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_report_access_log');
        Schema::dropIfExists('auth_activity');
    }
};
