<?php

namespace App\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/** Offline, resumable identifier conversion. Never called by an HTTP request. */
final class NumericIdConversion
{
    private array $tables;

    private array $next = [];

    private const ENTITIES = ['user' => 'users', 'teacher_profile' => 'teacher_profiles', 'institute_profile' => 'institute_profiles',
        'booking' => 'bookings', 'message' => 'messages',
        'message_reaction' => 'message_reactions', 'otp_delivery' => 'otp_delivery_attempts',
        'post' => 'posts', 'comment' => 'post_comments', 'report' => 'reports'];

    public function __construct()
    {
        $this->tables = config('numeric_ids');
    }

    public function needed(): bool
    {
        return collect(Schema::getColumns('users'))->firstWhere('name', 'id')['type_name'] !== 'bigint';
    }

    public function hasData(): bool
    {
        foreach ($this->tables as $table => $columns) {
            if (DB::table($table)->exists()) {
                return true;
            }
        }

        return false;
    }

    public function convert(bool $allowExistingData = false): void
    {
        if (DB::getDriverName() !== 'mysql') {
            throw new RuntimeException('Numeric ID conversion requires MySQL.');
        }
        if (! $this->needed() && ! Schema::hasTable('id_conversion_state')) {
            return;
        }
        if ($this->hasData() && ! $allowExistingData) {
            throw new RuntimeException('Existing records need offline conversion: back up the database, stop workers, enable maintenance, then run ids:convert --apply --backup=/private/database.sql.');
        }
        foreach (['jobs', 'failed_jobs'] as $queue) {
            if (DB::table($queue)->exists()) {
                throw new RuntimeException('Drain or archive '.$queue.' before changing IDs; queued payloads must not retain old references.');
            }
        }
        if (! Schema::hasTable('id_conversion_state')) {
            Schema::create('id_conversion_state', function (Blueprint $table): void {
                $table->string('name')->primary();
                $table->longText('value');
            });
        }
        if ($this->state('phase') === null) {
            if (! Schema::hasTable('id_conversion_maps')) {
                Schema::create('id_conversion_maps', function (Blueprint $table): void {
                    $table->string('entity', 64);
                    $table->string('old_id', 64);
                    $table->unsignedBigInteger('new_id');
                    $table->primary(['entity', 'old_id']);
                    $table->unique(['entity', 'new_id']);
                });
            }
            $foreign = [];
            foreach ($this->tables as $table => $columns) {
                $foreign[$table] = Schema::getForeignKeys($table);
            }
            $this->state('foreign_keys', json_encode($foreign, JSON_THROW_ON_ERROR));
            $this->state('phase', 'mapping');
        }
        $foreign = json_decode($this->state('foreign_keys'), true, flags: JSON_THROW_ON_ERROR);
        if ($this->state('phase') === 'mapping') {
            $this->buildMaps();
            $this->state('phase', 'mapped');
        }
        // Persisted definitions let a failed DDL step resume without guessing constraints.
        if ($this->state('phase') === 'mapped') {
            foreach ($foreign as $table => $keys) {
                foreach ($keys as $key) {
                    if (collect(Schema::getForeignKeys($table))->contains('name', $key['name'])) {
                        Schema::table($table, fn (Blueprint $schema) => $schema->dropForeign($key['name']));
                    }
                }
            }
            DB::transaction(function (): void {
                $this->rewriteData();
                $this->state('phase', 'converted');
            });
        }
        if ($this->state('phase') === 'converted') {
            foreach ($this->tables as $table => $columns) {
                $metadata = collect(Schema::getColumns($table))->keyBy('name');
                foreach ($columns as $column => $entity) {
                    if ($metadata[$column]['type_name'] === 'bigint') {
                        continue;
                    }
                    $nullable = $metadata[$column]['nullable'] ? 'NULL' : 'NOT NULL';
                    $auto = $column === 'id' ? ' AUTO_INCREMENT' : '';
                    DB::statement("ALTER TABLE `$table` MODIFY `$column` BIGINT UNSIGNED $nullable$auto");
                }
                if (isset($columns['id'])) {
                    // Do not reuse IDs that refer to deleted records in historical events.
                    $next = 1 + (int) DB::table('id_conversion_maps')->where('entity', $table)->max('new_id');
                    DB::statement("ALTER TABLE `$table` AUTO_INCREMENT = $next");
                }
            }
            $this->state('phase', 'typed');
        }
        foreach ($foreign as $table => $keys) {
            foreach ($keys as $key) {
                if (! collect(Schema::getForeignKeys($table))->contains('name', $key['name'])) {
                    Schema::table($table, function (Blueprint $schema) use ($key): void {
                        $schema->foreign($key['columns'], $key['name'])->references($key['foreign_columns'])
                            ->on($key['foreign_table'])->onDelete($key['on_delete'])->onUpdate($key['on_update']);
                    });
                }
            }
        }
        Schema::dropIfExists('id_conversion_maps');
        Schema::dropIfExists('id_conversion_state');
    }

    private function state(string $name, ?string $value = null): ?string
    {
        if ($value !== null) {
            DB::table('id_conversion_state')->updateOrInsert(['name' => $name], ['value' => $value]);
        }

        return DB::table('id_conversion_state')->where('name', $name)->value('value');
    }

    private function mapped(string $entity, mixed $old, bool $create = false): ?int
    {
        if ($old === null) {
            return null;
        }
        $id = DB::table('id_conversion_maps')->where('entity', $entity)->where('old_id', (string) $old)->value('new_id');
        if ($id !== null) {
            return (int) $id;
        }
        if (! $create) {
            throw new RuntimeException('Missing identifier mapping for '.$entity.'.');
        }
        $this->next[$entity] ??= (int) DB::table('id_conversion_maps')->where('entity', $entity)->max('new_id');
        $id = ++$this->next[$entity];
        DB::table('id_conversion_maps')->insert(['entity' => $entity, 'old_id' => (string) $old, 'new_id' => $id]);

        return $id;
    }

    private function polymorphic(string $table, object $row): array
    {
        if ($table === 'reports') {
            return ['target_id' => self::ENTITIES[$row->target_type] ?? throw new RuntimeException('Unknown report target type.')];
        }
        if ($table === 'business_activity') {
            return ['entity_id' => self::ENTITIES[$row->entity_type] ?? throw new RuntimeException('Unknown business history entity type.')];
        }
        if ($table === 'admin_report_access_log' && $row->record_id !== null) {
            $entity = $row->report === 'conversation-messages' ? 'conversations' : (config('reports')[$row->report]['table'] ?? null);
            if ($entity === null || ! isset($this->tables[$entity]['id'])) {
                throw new RuntimeException('Unknown admin report record reference.');
            }

            return ['record_id' => $entity];
        }
        if ($table === 'page_views' && $row->page_type === 'app_profile' && $row->target !== null) {
            return ['target' => 'users'];
        }

        return [];
    }

    private function snapshots(object $row, bool $create): array
    {
        $result = [];
        foreach (['before_data', 'after_data'] as $column) {
            $data = json_decode($row->$column, true, flags: JSON_THROW_ON_ERROR);
            foreach (['conversation_id' => 'conversations', 'message_id' => 'messages', 'reply_to_id' => 'messages'] as $key => $entity) {
                if (isset($data[$key])) {
                    $data[$key] = $this->mapped($entity, $data[$key], $create);
                }
            }
            $result[$column] = json_encode($data, JSON_THROW_ON_ERROR);
        }

        return $result;
    }

    private function buildMaps(): void
    {
        foreach ($this->tables as $table => $columns) {
            if (isset($columns['id'])) {
                foreach (DB::table($table)->orderBy('id')->cursor(['id']) as $row) {
                    if ($table === 'users' && RecordId::valid($row->id)) {
                        throw new RuntimeException('Legacy users contain mixed numeric identifiers. Reconcile these before conversion so old tokens cannot identify a different account.');
                    }
                    $this->mapped($table, $row->id, true);
                }
            }
        }
        foreach ($this->tables as $table => $columns) {
            foreach (DB::table($table)->orderBy('id')->cursor() as $row) {
                foreach ($columns as $column => $entity) {
                    if ($entity !== null) {
                        $this->mapped($entity, $row->$column, true);
                    }
                }
                foreach ($this->polymorphic($table, $row) as $column => $entity) {
                    $this->mapped($entity, $row->$column, true);
                }
                if ($table === 'business_activity') {
                    $this->snapshots($row, true);
                }
            }
        }
    }

    private function rewriteData(): void
    {
        // All columns are still character columns here; all data changes commit together.
        foreach ($this->tables as $table => $columns) {
            $rows = isset($columns['id'])
                ? DB::table('id_conversion_maps')->where('entity', $table)->lazyById(200, 'new_id')
                : DB::table($table)->lazyById(200);
            foreach ($rows as $entry) {
                $row = isset($columns['id']) ? DB::table($table)->where('id', $entry->old_id)->first() : $entry;
                if ($row === null) {
                    continue;
                }
                $updates = [];
                foreach ($columns as $column => $entity) {
                    if ($entity !== null) {
                        $updates[$column] = $this->mapped($entity, $row->$column);
                    }
                }
                foreach ($this->polymorphic($table, $row) as $column => $entity) {
                    $updates[$column] = $this->mapped($entity, $row->$column);
                }
                if ($table === 'business_activity') {
                    $updates += $this->snapshots($row, false);
                }
                // A temporary prefix avoids collisions when old IDs contain numeric strings.
                if (isset($updates['id'])) {
                    $updates['id'] = '~'.$updates['id'];
                }
                DB::table($table)->where('id', $row->id)->update($updates);
            }
            if (isset($columns['id'])) {
                DB::statement("UPDATE `$table` SET `id` = SUBSTRING(`id`, 2)");
            }
        }
    }
}
