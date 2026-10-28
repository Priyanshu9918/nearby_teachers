<?php

namespace App\Console\Commands;

use App\Services\GeographyTransfer;
use Illuminate\Console\Command;

class ImportGeography extends Command
{
    protected $signature = 'geography:import-cities {path : Source .jsonl.gz file}';

    protected $description = 'Import cities into an empty cities table using existing country/state codes';

    public function handle(GeographyTransfer $transfer): int
    {
        $this->info(json_encode($transfer->import($this->argument('path')), JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
