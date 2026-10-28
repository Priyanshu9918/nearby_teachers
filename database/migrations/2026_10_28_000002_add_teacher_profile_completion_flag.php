<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teacher_profiles', function (Blueprint $table): void {
            $table->boolean('is_profile_complete')->default(false)->after('listing_name');
        });

        // Preserve the completed SEO listings that already existed before this flag.
        DB::table('teacher_profiles')->whereNotNull('public_page_slug')->update(['is_profile_complete' => true]);
    }

    public function down(): void
    {
        Schema::table('teacher_profiles', function (Blueprint $table): void {
            $table->dropColumn('is_profile_complete');
        });
    }
};
