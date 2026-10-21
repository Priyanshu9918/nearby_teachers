<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        if (! Schema::hasTable('subject_domains')) {
            throw new RuntimeException('The application schema must be migrated before seeding subjects.');
        }

        if (DB::table('subject_domains')->exists()) {
            return;
        }

        $seed = file_get_contents(database_path('sql/seed.mysql.sql'));
        if ($seed === false) {
            throw new RuntimeException('Unable to read the subject taxonomy seed data.');
        }

        $seed = preg_replace('/^\s*--.*$/m', '', $seed);
        foreach (explode(';', $seed) as $statement) {
            if (trim($statement) !== '') {
                DB::unprepared($statement);
            }
        }
    }
}
