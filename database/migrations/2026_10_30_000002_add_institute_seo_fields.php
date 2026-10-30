<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('institute_profiles', function (Blueprint $table): void {
            $table->string('public_page_slug', 230)->nullable()->unique();
            $table->char('seo_country', 2)->nullable();
            $table->string('seo_city_slug', 230)->nullable();
            $table->char('seo_state_hash', 64)->nullable();
            $table->index(['seo_country', 'seo_city_slug'], 'institute_seo_geography');
        });

        DB::statement('ALTER TABLE seo_urls MODIFY target_type VARCHAR(16) NOT NULL');
    }

    public function down(): void
    {
        // seo_urls is append-only history. Keep its widened type so stored institute
        // ownership rows remain valid if this profile metadata migration is rolled back.
        Schema::table('institute_profiles', function (Blueprint $table): void {
            $table->dropIndex('institute_seo_geography');
            $table->dropUnique(['public_page_slug']);
            $table->dropColumn(['public_page_slug', 'seo_country', 'seo_city_slug', 'seo_state_hash']);
        });
    }
};
