<?php

namespace Database\Seeders;

use App\Services\WorldGeographyImporter;
use Illuminate\Database\Seeder;

class WorldGeographySeeder extends Seeder
{
    public function run(): void
    {
        $counts = app(WorldGeographyImporter::class)->import(database_path('data/geography'));
        $this->command?->info(json_encode($counts, JSON_THROW_ON_ERROR));
    }
}
