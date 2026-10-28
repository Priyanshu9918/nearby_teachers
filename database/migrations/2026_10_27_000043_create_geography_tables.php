<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('countries', function (Blueprint $table): void {
            $table->char('code', 2)->primary();
            $table->char('alpha3', 3)->unique();
            $table->string('name', 120);
            $table->timestamps();
            $table->index('name');
        });

        Schema::create('states', function (Blueprint $table): void {
            $table->id();
            $table->char('country_code', 2);
            $table->string('code', 32);
            $table->string('geonames_code', 32)->nullable()->unique();
            $table->string('name', 160);
            $table->string('type', 80)->nullable();
            $table->timestamps();
            $table->foreign('country_code')->references('code')->on('countries')->cascadeOnDelete();
            $table->unique(['country_code', 'code']);
            $table->index(['country_code', 'name']);
        });

        Schema::create('cities', function (Blueprint $table): void {
            $table->unsignedBigInteger('geoname_id')->primary();
            $table->char('country_code', 2);
            $table->unsignedBigInteger('state_id')->nullable();
            $table->string('name', 200);
            $table->string('ascii_name', 200)->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->unsignedBigInteger('population')->default(0);
            $table->string('feature_code', 16)->nullable();
            $table->timestamps();
            $table->foreign('country_code')->references('code')->on('countries')->cascadeOnDelete();
            $table->foreign('state_id')->references('id')->on('states')->nullOnDelete();
            $table->index(['country_code', 'state_id', 'name'], 'cities_country_state_name');
            $table->index(['country_code', 'population'], 'cities_country_population');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cities');
        Schema::dropIfExists('states');
        Schema::dropIfExists('countries');
    }
};
