<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('profile_subjects', function (Blueprint $table): void {
            $table->increments('id');
            $table->enum('profile_type', ['teacher', 'institute']);
            $table->char('profile_id', 36);
            $table->unsignedInteger('subject_id');
            $table->unique(['profile_type', 'profile_id', 'subject_id'], 'profile_subjects_unique');
            $table->index('subject_id', 'idx_profile_subjects_subject');
            $table->index(['profile_type', 'profile_id'], 'idx_profile_subjects_profile');
            $table->foreign('subject_id', 'profile_subjects_subject_fk')->references('id')->on('subjects')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('profile_subjects');
    }
};
