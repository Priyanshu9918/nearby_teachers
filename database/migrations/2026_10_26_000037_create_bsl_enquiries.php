<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['franchise_enquiries', 'teaching_job_applications'] as $name) {
            Schema::create($name, function (Blueprint $table) use ($name): void {
                $table->id();
                // Retain submitted contact details and ownership even if an account is removed.
                $table->unsignedBigInteger('user_id');
                $table->string('name', 160);
                $table->string('phone', 16);
                if ($name === 'franchise_enquiries') {
                    $table->string('location', 255);
                } else {
                    $table->decimal('expected_salary', 10, 2);
                    $table->char('currency', 3)->default('INR');
                    $table->string('salary_period', 16)->default('monthly');
                }
                $table->string('status', 24)->default('submitted');
                $table->dateTime('created_at', 6);
                $table->index(['user_id', 'created_at', 'id']);
                $table->index(['created_at', 'id']);
            });
        }

        Schema::create('teaching_job_application_courses', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('application_id');
            $table->unsignedBigInteger('user_id');
            // Course names are immutable submission snapshots, not live catalogue labels.
            $table->unsignedInteger('course_id');
            $table->string('course_name', 200);
            $table->dateTime('created_at', 6);
            $table->unique(['application_id', 'course_id'], 'job_application_course_unique');
            $table->index(['course_id', 'created_at']);
            $table->index(['user_id', 'created_at']);
            $table->index(['created_at', 'id'], 'job_courses_created_index');
            $table->foreign('application_id', 'job_courses_application_fk')
                ->references('id')->on('teaching_job_applications')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('teaching_job_application_courses');
        Schema::dropIfExists('teaching_job_applications');
        Schema::dropIfExists('franchise_enquiries');
    }
};
