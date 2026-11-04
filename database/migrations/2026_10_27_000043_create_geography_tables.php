<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Existing SQL imports already use this API schema. Never replace them.
        foreach (['countries' => ['id', 'sortname', 'name', 'phonecode'], 'states' => ['id', 'name', 'country_id'], 'cities' => ['id', 'name', 'state_id']] as $table => $columns) {
            if (Schema::hasTable($table) && ! Schema::hasColumns($table, $columns)) {
                throw new RuntimeException("Incompatible legacy geography table: {$table}. Preserve it and plan an explicit conversion before importing the numeric API catalog.");
            }
        }
        if (! Schema::hasTable('countries')) {
            Schema::create('countries', function (Blueprint $table): void {
                $table->increments('id');
                $table->string('sortname', 3)->unique();
                $table->string('name');
                $table->unsignedInteger('phonecode');
            });
        }
        if (! Schema::hasTable('states')) {
            Schema::create('states', function (Blueprint $table): void {
                $table->increments('id');
                $table->string('name');
                $table->unsignedInteger('country_id');
                $table->foreign('country_id')->references('id')->on('countries')->restrictOnDelete();
                $table->index(['country_id', 'name']);
            });
        }
        if (! Schema::hasTable('cities')) {
            Schema::create('cities', function (Blueprint $table): void {
                $table->increments('id');
                $table->string('name');
                $table->unsignedInteger('state_id')->nullable();
                $table->foreign('state_id')->references('id')->on('states')->restrictOnDelete();
                $table->index(['state_id', 'name']);
            });
        }
    }

    public function down(): void
    {
        // Reference catalogs may already be in use or predate this migration.
        // Rollback deliberately preserves them and their IDs.
    }
};
