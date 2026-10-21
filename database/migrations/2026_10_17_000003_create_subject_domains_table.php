<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subject_domains', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name', 160)->unique();
            $table->string('icon', 160)->nullable();
            $table->integer('sort_order')->default(0);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subject_domains');
    }
};
