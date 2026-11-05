<?php

namespace App\Console\Commands;

use App\Services\InstitutePublicPages;
use App\Services\TeacherPublicPages;
use App\Support\RecordId;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class PublishSeoProfile extends Command
{
    protected $signature = 'seo:publish-profile {type : teacher or institute} {user_id : Numeric user ID}';

    protected $description = 'Reconcile SEO mappings and pages for one existing eligible profile';

    public function handle(): int
    {
        $type = $this->argument('type');
        $id = $this->argument('user_id');
        if (! in_array($type, ['teacher', 'institute'], true) || ! RecordId::valid($id)) {
            $this->error('Provide teacher or institute and a positive numeric user ID.');

            return self::FAILURE;
        }
        $table = $type === 'teacher' ? 'teacher_profiles' : 'institute_profiles';
        if (! DB::table($table)->where('user_id', $id)->exists()) {
            $this->error('Profile not found.');

            return self::FAILURE;
        }
        $publisher = $type === 'teacher' ? TeacherPublicPages::class : InstitutePublicPages::class;
        DB::transaction(fn () => app($publisher)->publish((int) $id));
        $profile = DB::table($table)->where('user_id', $id)->first();
        if ($profile->seo_country === null || $profile->public_page_slug === null) {
            $this->error('Profile is not eligible. Complete registration and profile publication first.');

            return self::FAILURE;
        }
        $this->info('SEO reconciliation finished for '.$type.' user '.$id.'. Review mappings and logs for URL conflicts.');

        return self::SUCCESS;
    }
}
