<?php

namespace App\Console\Commands;

use App\Services\SeoDirectory;
use Illuminate\Console\Command;

class SyncSeoDirectory extends Command
{
    protected $signature = 'seo:sync';

    protected $description = 'Register current teacher URLs and eligible country/city/online listing pages';

    public function handle(SeoDirectory $directory): int
    {
        $count = $directory->sync();
        $this->info('Processed '.$count.' teacher profiles. Only eligible profiles with supported countries are published.');

        return self::SUCCESS;
    }
}
