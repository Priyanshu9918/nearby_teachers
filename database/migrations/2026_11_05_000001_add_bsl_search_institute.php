<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('institute_profiles', function (Blueprint $table): void {
            $table->boolean('is_bsl')->default(false);
            $table->unsignedTinyInteger('bsl_search_slot')->nullable()->storedAs('CASE WHEN is_bsl = 1 THEN 1 ELSE NULL END');
            $table->unique('bsl_search_slot');
        });
    }

    public function down(): void
    {
        Schema::table('institute_profiles', function (Blueprint $table): void {
            $table->dropUnique(['bsl_search_slot']);
            $table->dropColumn(['bsl_search_slot', 'is_bsl']);
        });
    }
};
