<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subjects', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('domain_id');
            $table->string('name', 200);
            $table->boolean('is_bsl')->default(false);
            $table->unique(['domain_id', 'name'], 'subjects_domain_name_unique');
            $table->index('is_bsl', 'idx_subjects_is_bsl');
            $table->foreign('domain_id', 'subjects_domain_fk')->references('id')->on('subject_domains')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subjects');
    }
};
