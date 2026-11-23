<?php

namespace App\Console\Commands;

use App\Services\LocationIdentity;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillLocationIds extends Command
{
    protected $signature = 'locations:backfill {--apply : Link uniquely resolved records; default is preview only}';

    protected $description = 'Resolve existing provider and SEO location IDs without publishing pages or changing URLs';

    public function handle(LocationIdentity $locations): int
    {
        $linked = 0;
        $unresolved = 0;
        foreach (['teacher' => 'teacher_profiles', 'institute' => 'institute_profiles'] as $type => $table) {
            foreach (DB::table($table)->orderBy('id')->lazyById(200) as $profile) {
                $data = [];
                foreach (LocationIdentity::TABLES as $field => $_) {
                    $column = $field === 'subDistrict' ? 'sub_district' : $field;
                    $data[$field] = $profile->$column;
                    if (isset($profile->{$column.'_id'})) {
                        $data[$field.'Id'] = $profile->{$column.'_id'};
                    }
                }
                $data['countryCode'] = $profile->country_code;
                $resolved = $locations->resolveLegacy($data);
                if (! isset($resolved['countryId']) || (! empty($profile->city) && ! isset($resolved['cityId']))) {
                    $unresolved++;
                }
                if (isset($resolved['countryId'])) {
                    $linked++;
                }
                if ($this->option('apply')) {
                    DB::transaction(fn () => $locations->store((int) $profile->user_id, [], $type, true));
                }
            }
        }
        // Existing page identities only. No publishers, URL reservations or redirects are invoked.
        foreach (['seo_directory_pages', 'seo_location_pages'] as $table) {
            foreach (DB::table($table)->where('location_identity_ambiguous', false)->orderBy('id')->lazyById(200) as $page) {
                $data = ['country' => $page->country, 'countryCode' => strtoupper($page->country)];
                foreach (['city', 'district'] as $field) {
                    $data[$field] = $page->$field ?? null;
                }
                $data['locality'] = $page->village ?? null;
                $resolved = $locations->resolveLegacy($data);
                $ids = [];
                foreach (LocationIdentity::TABLES as $field => $_) {
                    if ($field !== 'subDistrict' && isset($resolved[$field.'Id'])) {
                        $ids[$field.'_id'] = $resolved[$field.'Id'];
                    }
                }
                if ($this->option('apply') && $ids !== []) {
                    DB::table($table)->where('id', $page->id)->update($ids);
                }
            }
        }
        $this->info(($this->option('apply') ? 'Linked' : 'Would link')." country identities for {$linked} providers; {$unresolved} providers need location review. URLs and page count preserved.");

        return self::SUCCESS;
    }
}
