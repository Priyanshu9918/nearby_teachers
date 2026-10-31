<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['admin_report_access_log', 'business_activity', 'support_tickets'] as $table) {
            $column = DB::selectOne("SHOW COLUMNS FROM `{$table}` LIKE 'id'");
            if ($column === null) {
                throw new RuntimeException("{$table}.id is missing.");
            }

            // The one-way numeric ID conversion changed these UUID keys to
            // BIGINT. Keep converted historical IDs as strings and restore the
            // UUID-compatible columns expected by the log/event/ticket writers.
            if (strtolower((string) $column->Type) !== 'char(36)') {
                DB::statement("ALTER TABLE `{$table}` MODIFY `id` CHAR(36) NOT NULL");
            }
        }
    }

    public function down(): void
    {
        // New UUIDs may be present and converted identifiers must remain stable.
    }
};
