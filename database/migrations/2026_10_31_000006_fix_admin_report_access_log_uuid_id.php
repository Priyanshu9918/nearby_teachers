<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $column = DB::selectOne("SHOW COLUMNS FROM `admin_report_access_log` LIKE 'id'");
        if ($column === null) {
            throw new RuntimeException('admin_report_access_log.id is missing.');
        }

        // Some deployed databases created this table with a numeric auto-id,
        // although the migration contract and current writer use UUIDs. MySQL
        // preserves the existing primary-key values as strings during MODIFY.
        if (strtolower((string) $column->Type) !== 'char(36)') {
            DB::statement('ALTER TABLE `admin_report_access_log` MODIFY `id` CHAR(36) NOT NULL');
        }
    }

    public function down(): void
    {
        // Keep the UUID-compatible column: access logs can now contain UUIDs,
        // and converting them back to a number would destroy audit history.
    }
};
