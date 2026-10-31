<?php

namespace Tests\Feature;

use App\Support\NumericIdConversion;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class NumericIdConversionTest extends TestCase
{
    private bool $usesDisposableDatabase = false;

    protected function tearDown(): void
    {
        if ($this->usesDisposableDatabase) {
            // Every row here was created by this test after migrate:fresh on its _test database.
            Schema::withoutForeignKeyConstraints(function (): void {
                foreach (array_keys(config('numeric_ids')) as $table) {
                    DB::table($table)->delete();
                }
                DB::table('subjects')->delete();
                DB::table('subject_domains')->delete();
            });
        }
        parent::tearDown();
    }

    public function test_existing_records_relations_and_history_survive_an_interrupted_conversion(): void
    {
        $database = getenv('ONBOARDING_TEST_DATABASE');
        if (! $database) {
            $this->markTestSkipped('Requires a disposable ONBOARDING_TEST_DATABASE ending in _test.');
        }
        $this->assertStringEndsWith('_test', $database);
        config(['database.connections.mysql.url' => null, 'database.connections.mysql.database' => $database]);
        DB::purge('mysql');
        $this->assertStringEndsWith('_test', DB::connection()->getDatabaseName());
        $this->usesDisposableDatabase = true;
        $paths = array_values(array_filter(glob(database_path('migrations/*.php')), fn ($path) => ! str_contains($path, '000034_')));
        Artisan::call('migrate:fresh', ['--force' => true, '--path' => $paths, '--realpath' => true]);
        $old = fn (int $id) => sprintf('00000000-0000-4000-8000-%012d', $id);
        DB::table('users')->insert([
            ['id' => $old(1), 'account_type' => 'learner', 'full_name' => 'Student'],
            ['id' => $old(2), 'account_type' => 'teacher', 'full_name' => 'Teacher'],
        ]);
        DB::table('teacher_profiles')->insert(['id' => $old(40), 'user_id' => $old(2), 'lesson_modes' => '[]']);
        $domain = DB::table('subject_domains')->insertGetId(['name' => 'Languages']);
        $subject = DB::table('subjects')->insertGetId(['name' => 'English', 'domain_id' => $domain]);
        DB::table('profile_subjects')->insert(['profile_type' => 'teacher', 'profile_id' => $old(2), 'subject_id' => $subject]);
        DB::table('conversations')->insert(['id' => $old(10), 'learner_id' => $old(1), 'provider_id' => $old(2)]);
        DB::table('messages')->insert([
            ['id' => $old(20), 'conversation_id' => $old(10), 'sender_id' => $old(1), 'body' => 'Hello', 'reply_to_id' => null],
            ['id' => $old(21), 'conversation_id' => $old(10), 'sender_id' => $old(2), 'body' => 'Reply', 'reply_to_id' => $old(20)],
        ]);
        DB::table('message_reactions')->insert(['id' => $old(30), 'conversation_id' => $old(10), 'message_id' => $old(20),
            'user_id' => $old(2), 'reaction' => '👍', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('reports')->insert(['id' => $old(50), 'reporter_id' => $old(1), 'target_type' => 'message', 'target_id' => $old(21), 'reason_category' => 'spam']);
        DB::table('reports')->insert(['id' => $old(51), 'reporter_id' => $old(1), 'target_type' => null, 'target_id' => null,
            'reason_category' => 'other', 'reason' => 'Unlinked report']);
        DB::table('admin_report_access_log')->insert(['id' => $old(60), 'admin_email' => 'admin@example.test', 'report' => 'conversation-messages', 'record_id' => $old(10), 'action' => 'view']);
        DB::table('support_tickets')->insert(['id' => $old(61), 'user_id' => $old(1), 'subject' => 'Legacy support ticket',
            'message' => 'Keep this ticket identifier stable.', 'status' => 'pending']);
        DB::table('business_activity')->insert([
            ['id' => $old(70), 'event' => 'message.replied', 'entity_type' => 'message', 'entity_id' => $old(21),
                'actor_user_id' => $old(2), 'related_user_id' => $old(1), 'version' => 1, 'before_data' => '{}',
                'after_data' => json_encode(['conversation_id' => $old(10), 'reply_to_id' => $old(20)])],
            ['id' => $old(71), 'event' => 'message.deleted', 'entity_type' => 'message', 'entity_id' => $old(22),
                'actor_user_id' => $old(3), 'related_user_id' => null, 'version' => 1, 'before_data' => '{}',
                'after_data' => json_encode(['conversation_id' => $old(10), 'revision' => 2])],
        ]);
        DB::table('page_views')->insert(['id' => $old(80), 'page_type' => 'app_profile', 'target' => $old(2)]);
        $conversion = app(NumericIdConversion::class);
        try {
            $conversion->convert();
            $this->fail('Existing data must require explicit offline conversion.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('offline conversion', $error->getMessage());
        }
        $this->assertFalse(Schema::hasTable('id_conversion_state'));
        // Fail after a real data write and verify the entire rewrite rolls back.
        DB::listen(function ($query): void {
            if (str_starts_with($query->sql, 'update `messages`')) {
                throw new RuntimeException('Simulated conversion interruption');
            }
        });
        try {
            $conversion->convert(allowExistingData: true);
            $this->fail('Expected conversion interruption.');
        } catch (RuntimeException $error) {
            $this->assertSame('Simulated conversion interruption', $error->getMessage());
        } finally {
            Event::forget(QueryExecuted::class);
        }
        $this->assertSame($old(1), DB::table('users')->orderBy('id')->value('id'));
        $this->assertSame($old(20), DB::table('messages')->where('id', $old(21))->value('reply_to_id'));
        app(NumericIdConversion::class)->convert(allowExistingData: true);
        $this->assertSame([1, 2], DB::table('users')->orderBy('id')->pluck('id')->all());
        $this->assertSame(1, DB::table('messages')->where('id', 2)->value('reply_to_id'));
        $this->assertSame(1, DB::table('messages')->where('id', 2)->value('conversation_id'));
        $this->assertSame(2, DB::table('messages')->where('id', 2)->value('sender_id'));
        $this->assertSame(2, DB::table('profile_subjects')->value('profile_id'));
        $this->assertSame(2, DB::table('reports')->value('target_id'));
        $this->assertNull(DB::table('reports')->where('reason', 'Unlinked report')->value('target_type'));
        $this->assertNull(DB::table('reports')->where('reason', 'Unlinked report')->value('target_id'));
        $this->assertSame(1, DB::table('admin_report_access_log')->value('record_id'));
        $this->assertSame($old(60), DB::table('admin_report_access_log')->value('id'));
        $this->assertSame($old(61), DB::table('support_tickets')->value('id'));
        $this->assertSame(1, DB::table('support_tickets')->value('user_id'));
        $this->assertSame('2', DB::table('page_views')->value('target'));
        $history = DB::table('business_activity')->where('event', 'message.replied')->first();
        $this->assertSame($old(70), $history->id);
        $this->assertSame(2, $history->entity_id);
        $snapshot = json_decode($history->after_data, true);
        $this->assertSame(1, $snapshot['conversation_id']);
        $this->assertSame(1, $snapshot['reply_to_id']);
        $this->assertSame(2, DB::table('business_activity')->count());
        $this->assertSame(2, DB::table('messages')->count());
        // Deleted source records remain absent, and their historical IDs cannot be reused.
        $this->assertSame(3, DB::table('business_activity')->where('event', 'message.deleted')->value('entity_id'));
        $this->assertSame(4, DB::table('messages')->insertGetId(['conversation_id' => 1, 'sender_id' => 1, 'body' => 'After migration']));
        $this->assertSame(4, DB::table('users')->insertGetId(['account_type' => 'learner', 'full_name' => 'New user']));
        $this->assertNotEmpty(Schema::getForeignKeys('messages'));
        try {
            DB::table('messages')->insert(['conversation_id' => 999, 'sender_id' => 1, 'body' => 'Invalid']);
            $this->fail('Foreign key constraints must remain active.');
        } catch (QueryException $error) {
            $this->assertSame('23000', $error->errorInfo[0]);
        }
        $this->assertFalse(Schema::hasTable('id_conversion_maps'));
        $this->assertFalse(Schema::hasTable('id_conversion_state'));
        $this->assertFalse(app(NumericIdConversion::class)->needed());
        // The pending migration recognizes the already-completed offline conversion.
        Artisan::call('migrate', ['--force' => true]);
    }
}
