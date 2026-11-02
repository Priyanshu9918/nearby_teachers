<?php

namespace App\Console\Commands;

use App\Services\SeoDirectory;
use App\Services\SeoMaintenance;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class RepairSeoDirectory extends Command
{
    protected $signature = 'seo:repair {--apply : Back up SEO metadata and commit repairs}';

    protected $description = 'Preview or apply reviewed wording, sync eligible pages and prune unavailable SEO metadata';

    public function handle(SeoDirectory $directory, SeoMaintenance $maintenance): int
    {
        DB::beginTransaction();
        try {
            if ($this->option('apply')) {
                $path = 'seo-backups/'.now()->format('Ymd-His').'-'.bin2hex(random_bytes(4)).'.json';
                $backup = [];
                foreach (['seo_subject_routes', 'seo_directory_pages', 'seo_location_pages', 'seo_urls'] as $table) {
                    $backup[$table] = DB::table($table)->orderBy('id')->get()->all();
                }
                if (! Storage::disk('local')->put($path, json_encode($backup, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT))) {
                    throw new \RuntimeException('SEO backup failed; repair aborted.');
                }
                $this->info('SEO backup: '.Storage::disk('local')->path($path));
            }
            $wording = $maintenance->repairWording();
            $profiles = $directory->sync();
            $removed = $maintenance->prune();
            if ($this->option('apply')) {
                DB::commit();
            } else {
                DB::rollBack();
            }
            $this->info(($this->option('apply') ? 'Applied' : 'Preview (rolled back)').': wording '.$wording
                .', profiles '.$profiles.', removed listings '.$removed['listing'].', removed locations '.$removed['location'].'.');
            $this->info('Profile data, subjects, business history and URL reservations are preserved.');

            return self::SUCCESS;
        } catch (Throwable $error) {
            DB::rollBack();
            throw $error;
        }
    }
}
