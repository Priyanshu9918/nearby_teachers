<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        (require __DIR__.'/2026_10_27_000043_create_geography_tables.php')->up();
        if (! Schema::hasTable('geography_seed_runs')) {
            Schema::create('geography_seed_runs', function (Blueprint $table): void {
                $table->id();
                $table->string('catalog', 80)->unique();
                $table->char('sha256', 64);
                $table->timestamp('completed_at');
            });
        }
    }

    public function down(): void
    {
        // Keep the import receipt so rolling back cannot enable a duplicate import.
    }
};
