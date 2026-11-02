<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var array<string, array<string, int>> */
    private array $maps = [];

    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        foreach (['jobs', 'failed_jobs'] as $queueTable) {
            if (Schema::hasTable($queueTable)
                && DB::table($queueTable)->where('payload', 'like', '%SendPushCampaign%')->exists()) {
                throw new RuntimeException('Drain or archive SendPushCampaign jobs before converting push IDs to numbers.');
            }
        }

        $this->dropForeignIfPresent('push_deliveries', 'push_delivery_campaign_fk');

        foreach (['support_tickets', 'referral_clicks', 'push_devices', 'push_campaigns', 'push_deliveries'] as $table) {
            $this->maps[$table] = $this->idMap($table);
        }

        $this->rewritePolymorphicReferences();
        $this->rewriteReference('push_deliveries', 'campaign_id', $this->maps['push_campaigns'] ?? []);
        $this->rewriteReference('push_deliveries', 'device_id', $this->maps['push_devices'] ?? []);

        foreach (['support_tickets', 'referral_clicks', 'push_devices', 'push_campaigns', 'push_deliveries',
            'admin_report_access_log', 'business_activity'] as $table) {
            $this->convertPrimaryKey($table, $this->maps[$table] ?? $this->idMap($table));
        }

        $this->convertReference('support_tickets', 'user_id', false);
        $this->convertReference('referral_clicks', 'referrer_id', false);
        $this->convertReference('push_devices', 'user_id', false);
        $this->convertReference('push_deliveries', 'campaign_id', false);
        $this->convertReference('push_deliveries', 'device_id', false);
        $this->convertReference('admin_report_access_log', 'record_id', true);
        $this->convertReference('business_activity', 'entity_id', false);

        $this->addForeignIfMissing('push_devices', 'push_devices_user_fk', ['user_id'], 'users', ['id'], 'cascade');
        $this->addForeignIfMissing('push_deliveries', 'push_delivery_campaign_fk', ['campaign_id'], 'push_campaigns', ['id'], 'restrict');
    }

    public function down(): void
    {
        // Numeric IDs are the application contract. Reverting would invalidate
        // routes, queued payloads and append-only reporting references.
    }

    /** @return array<string, int> */
    private function idMap(string $table): array
    {
        if (! Schema::hasTable($table)) {
            return [];
        }

        $ids = DB::table($table)->orderBy('id')->pluck('id')->map(static fn ($id): string => (string) $id)->all();
        $used = [];
        $map = [];
        foreach ($ids as $id) {
            $candidate = preg_match('/^[1-9][0-9]*$/D', $id) === 1
                ? (int) $id
                : (preg_match('/^~([1-9][0-9]*)$/D', $id, $matches) === 1 ? (int) $matches[1] : null);
            if ($candidate !== null && ! isset($used[$candidate])) {
                $used[$candidate] = true;
                $map[$id] = $candidate;
            }
        }
        $next = $used === [] ? 0 : max(array_keys($used));
        foreach ($ids as $id) {
            if (isset($map[$id])) {
                continue;
            }
            do {
                $next++;
            } while (isset($used[$next]));
            $used[$next] = true;
            $map[$id] = $next;
        }

        return $map;
    }

    /** @param array<string, int> $map */
    private function convertPrimaryKey(string $table, array $map): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }
        $column = $this->column($table, 'id');
        if (str_contains(strtolower((string) $column->Type), 'bigint')) {
            if (! str_contains(strtolower((string) $column->Extra), 'auto_increment')) {
                DB::statement("ALTER TABLE `{$table}` MODIFY `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT");
            }

            return;
        }

        foreach ($map as $old => $new) {
            // PHP converts numeric-string array keys to integers. Bind the old
            // value as a string so MySQL does not numerically compare a legacy
            // CHAR column containing both numeric values and UUIDs.
            DB::table($table)->where('id', (string) $old)->update(['id' => '~'.$new]);
        }
        DB::statement("UPDATE `{$table}` SET `id` = SUBSTRING(`id`, 2) WHERE `id` LIKE '~%'");
        DB::statement("ALTER TABLE `{$table}` MODIFY `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT");
    }

    private function rewritePolymorphicReferences(): void
    {
        $activities = [
            'support_tickets' => 'support_ticket',
            'referral_clicks' => 'referral_click',
            'push_devices' => 'push_device',
            'push_campaigns' => 'push_campaign',
            'push_deliveries' => 'push_delivery',
        ];
        if (Schema::hasTable('business_activity') && ! $this->isNumeric('business_activity', 'entity_id')) {
            foreach ($activities as $table => $type) {
                foreach ($this->maps[$table] ?? [] as $old => $new) {
                    DB::table('business_activity')->where('entity_type', $type)->where('entity_id', (string) $old)
                        ->update(['entity_id' => $new]);
                }
            }
        }

        $reports = [
            'support_tickets' => ['support'],
            'referral_clicks' => ['referral-clicks'],
            'push_devices' => ['push-devices'],
            'push_campaigns' => ['push-campaigns'],
            'push_deliveries' => ['push-deliveries'],
        ];
        if (Schema::hasTable('admin_report_access_log') && ! $this->isNumeric('admin_report_access_log', 'record_id')) {
            foreach ($reports as $table => $names) {
                foreach ($this->maps[$table] ?? [] as $old => $new) {
                    DB::table('admin_report_access_log')->whereIn('report', $names)->where('record_id', (string) $old)
                        ->update(['record_id' => $new]);
                }
            }
        }
    }

    /** @param array<string, int> $map */
    private function rewriteReference(string $table, string $column, array $map): void
    {
        if (! Schema::hasTable($table) || $this->isNumeric($table, $column)) {
            return;
        }
        foreach ($map as $old => $new) {
            DB::table($table)->where($column, (string) $old)->update([$column => $new]);
        }
    }

    private function convertReference(string $table, string $column, bool $nullable): void
    {
        if (! Schema::hasTable($table) || $this->isNumeric($table, $column)) {
            return;
        }
        $null = $nullable ? 'NULL' : 'NOT NULL';
        DB::statement("ALTER TABLE `{$table}` MODIFY `{$column}` BIGINT UNSIGNED {$null}");
    }

    private function isNumeric(string $table, string $column): bool
    {
        return str_contains(strtolower((string) $this->column($table, $column)->Type), 'int');
    }

    private function column(string $table, string $column): object
    {
        return DB::selectOne("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'")
            ?? throw new RuntimeException("{$table}.{$column} is missing.");
    }

    private function dropForeignIfPresent(string $table, string $name): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }
        if (collect(Schema::getForeignKeys($table))->contains('name', $name)) {
            Schema::table($table, static fn (Blueprint $schema) => $schema->dropForeign($name));
        }
    }

    private function addForeignIfMissing(string $table, string $name, array $columns, string $foreignTable, array $foreignColumns, string $delete): void
    {
        if (! Schema::hasTable($table) || collect(Schema::getForeignKeys($table))->contains('name', $name)) {
            return;
        }
        Schema::table($table, static function (Blueprint $schema) use ($name, $columns, $foreignTable, $foreignColumns, $delete): void {
            $schema->foreign($columns, $name)->references($foreignColumns)->on($foreignTable)->onDelete($delete);
        });
    }
};
