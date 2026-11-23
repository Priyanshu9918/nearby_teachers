<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cities', function (Blueprint $table): void {
            $table->unsignedInteger('country_id')->nullable()->index();
            $table->foreign('country_id')->references('id')->on('countries')->restrictOnDelete();
        });
        DB::statement('UPDATE cities c JOIN states s ON s.id = c.state_id SET c.country_id = s.country_id WHERE c.country_id IS NULL');
        foreach (['districts', 'sub_districts', 'localities'] as $name) {
            Schema::create($name, function (Blueprint $table) use ($name): void {
                $table->increments('id');
                $table->unsignedInteger('country_id');
                $table->unsignedInteger('state_id')->nullable();
                if ($name === 'districts' || $name === 'sub_districts') {
                    $table->unsignedInteger('city_id')->nullable();
                    $table->foreign('city_id')->references('id')->on('cities')->restrictOnDelete();
                }
                if ($name === 'sub_districts') {
                    $table->unsignedInteger('district_id')->nullable();
                    $table->foreign('district_id')->references('id')->on('districts')->restrictOnDelete();
                }
                if ($name === 'localities') {
                    $table->unsignedInteger('district_id')->nullable();
                    $table->unsignedInteger('city_id')->nullable();
                    $table->foreign('district_id')->references('id')->on('districts')->restrictOnDelete();
                    $table->foreign('city_id')->references('id')->on('cities')->restrictOnDelete();
                }
                $table->string('name', 160);
                $table->foreign('country_id')->references('id')->on('countries')->restrictOnDelete();
                $table->foreign('state_id')->references('id')->on('states')->restrictOnDelete();
                $table->index(['country_id', 'state_id', 'name']);
            });
        }
        Schema::create('location_translations', function (Blueprint $table): void {
            $table->id();
            $table->string('location_type', 16);
            $table->unsignedInteger('location_id');
            $table->string('language', 8);
            $table->string('name', 160);
            $table->unique(['location_type', 'location_id', 'language'], 'location_translation_identity');
            $table->index(['language', 'name']);
        });
        foreach (['teacher_profiles', 'institute_profiles', 'seo_directory_pages', 'seo_location_pages', 'search_analytics'] as $name) {
            Schema::table($name, function (Blueprint $table) use ($name): void {
                if (str_starts_with($name, 'seo_')) {
                    $table->boolean('location_identity_ambiguous')->default(false);
                }
                foreach (['country' => 'countries', 'state' => 'states', 'city' => 'cities', 'district' => 'districts', 'sub_district' => 'sub_districts', 'locality' => 'localities'] as $field => $catalog) {
                    $table->unsignedInteger($field.'_id')->nullable()->index();
                    $table->foreign($field.'_id')->references('id')->on($catalog)->restrictOnDelete();
                }
            });
        }
    }

    public function down(): void
    {
        foreach (['teacher_profiles', 'institute_profiles', 'seo_directory_pages', 'seo_location_pages', 'search_analytics'] as $name) {
            Schema::table($name, function (Blueprint $table) use ($name): void {
                if (str_starts_with($name, 'seo_')) {
                    $table->dropColumn('location_identity_ambiguous');
                }
                foreach (['country', 'state', 'city', 'district', 'sub_district', 'locality'] as $field) {
                    $table->dropForeign([$field.'_id']);
                    $table->dropColumn($field.'_id');
                }
            });
        }
        Schema::dropIfExists('location_translations');
        Schema::dropIfExists('localities');
        Schema::dropIfExists('sub_districts');
        Schema::dropIfExists('districts');
        Schema::table('cities', function (Blueprint $table): void {
            $table->dropForeign(['country_id']);
            $table->dropColumn('country_id');
        });
    }
};
