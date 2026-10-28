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
            $table->string('listing_name', 160)->nullable();
            $table->boolean('is_profile_complete')->default(false);
            $table->json('completed_profile_steps')->nullable();
            $table->timestamp('profile_draft_saved_at')->nullable();
        });
        // Preserve institute pages already reachable before explicit publication existed.
        // This is a compatibility flag, not reconstructed step/terms history.
        DB::table('institute_profiles')->whereNotNull('slug')->where('slug', '!=', '')
            ->update(['is_profile_complete' => true]);
    }

    public function down(): void
    {
        Schema::table('institute_profiles', function (Blueprint $table): void {
            $table->dropColumn(['listing_name', 'is_profile_complete', 'completed_profile_steps', 'profile_draft_saved_at']);
        });
    }
};
