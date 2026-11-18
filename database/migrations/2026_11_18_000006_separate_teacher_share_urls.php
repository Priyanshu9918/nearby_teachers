<?php

use App\Services\TeacherPublicPages;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('teacher_profiles', 'subject_profile_slug')) {
            Schema::table('teacher_profiles', function (Blueprint $table): void {
                $table->string('subject_profile_slug', 230)->nullable()->unique();
            });
        }
        DB::table('teacher_profiles')->where('is_profile_complete', true)->whereNotNull('public_page_slug')
            ->select('id', 'user_id')->chunkById(100, function ($profiles): void {
                foreach ($profiles as $profile) {
                    DB::transaction(fn () => app(TeacherPublicPages::class)->publish((int) $profile->user_id), 3);
                }
            });
    }

    public function down(): void
    {
        Schema::table('teacher_profiles', fn (Blueprint $table) => $table->dropColumn('subject_profile_slug'));
        // Retain URL ownership and append-only history.
    }
};
