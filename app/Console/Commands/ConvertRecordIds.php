<?php

namespace App\Console\Commands;

use App\Support\NumericIdConversion;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class ConvertRecordIds extends Command
{
    protected $signature = 'ids:convert {--apply : Convert existing records while offline} {--backup= : Absolute path to the verified pre-conversion SQL backup}';

    protected $description = 'Convert project record IDs and their relations to sequential numeric IDs';

    public function handle(NumericIdConversion $conversion): int
    {
        if (! $this->option('apply')) {
            $this->info($conversion->needed() ? 'Conversion needed. No records changed.' : 'Record IDs are already numeric.');

            return self::SUCCESS;
        }
        $backup = (string) $this->option('backup');
        if (! $this->laravel->isDownForMaintenance() || ! str_starts_with($backup, '/') || ! is_file($backup) || filesize($backup) < 256) {
            $this->error('Enable maintenance, stop all workers and provide a verified SQL backup with --backup=/private/database.sql.');

            return self::FAILURE;
        }
        if ((int) DB::selectOne("SELECT GET_LOCK('nearby-numeric-id-conversion', 0) AS acquired")->acquired !== 1) {
            $this->error('Another ID conversion is running.');

            return self::FAILURE;
        }
        try {
            $conversion->convert(allowExistingData: true);
        } finally {
            DB::select("SELECT RELEASE_LOCK('nearby-numeric-id-conversion')");
        }
        $this->info('Numeric IDs and relations converted. Run pending migrations, restart chat/broadcast workers and refresh login tokens/client caches before leaving maintenance.');

        return self::SUCCESS;
    }
}
