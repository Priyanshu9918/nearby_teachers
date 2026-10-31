<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE reports MODIFY target_type ENUM('user', 'post', 'comment', 'message') NULL");
        $targetIdType = collect(Schema::getColumns('reports'))->firstWhere('name', 'target_id')['type_name'];
        DB::statement($targetIdType === 'bigint'
            ? 'ALTER TABLE reports MODIFY target_id BIGINT UNSIGNED NULL'
            : 'ALTER TABLE reports MODIFY target_id CHAR(36) NULL');
    }

    public function down(): void
    {
        if (DB::table('reports')->whereNull('target_type')->orWhereNull('target_id')->exists()) {
            throw new RuntimeException('Cannot require report targets while unlinked reports exist.');
        }

        DB::statement("ALTER TABLE reports MODIFY target_type ENUM('user', 'post', 'comment', 'message') NOT NULL");
        $targetIdType = collect(Schema::getColumns('reports'))->firstWhere('name', 'target_id')['type_name'];
        DB::statement($targetIdType === 'bigint'
            ? 'ALTER TABLE reports MODIFY target_id BIGINT UNSIGNED NOT NULL'
            : 'ALTER TABLE reports MODIFY target_id CHAR(36) NOT NULL');
    }
};
