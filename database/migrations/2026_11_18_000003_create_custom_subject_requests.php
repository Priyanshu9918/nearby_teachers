<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('custom_subject_requests', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('user_id')->index();
            $table->string('profile_type', 16);
            $table->string('name', 200);
            $table->char('name_hash', 64);
            $table->string('status', 16)->default('pending')->index();
            $table->unsignedInteger('subject_id')->nullable()->index();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'profile_type', 'name_hash'], 'custom_subject_request_unique');
        });
        Schema::table('teaching_job_application_courses', function (Blueprint $table): void {
            $table->unsignedInteger('course_id')->nullable()->change();
            $table->unsignedBigInteger('custom_request_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('teaching_job_application_courses', function (Blueprint $table): void {
            $table->dropColumn('custom_request_id');
            // Preserve null historical catalogue IDs instead of fabricating IDs.
        });
        Schema::dropIfExists('custom_subject_requests');
    }
};
