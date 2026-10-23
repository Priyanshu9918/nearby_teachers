<?php

use App\Support\NumericIdConversion;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        app(NumericIdConversion::class)->convert();
    }

    public function down(): void
    {
        throw new RuntimeException('ID conversion is one-way. Restore the verified pre-conversion database backup to recover old identifiers.');
    }
};
