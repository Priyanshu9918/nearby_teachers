<?php

namespace Tests\Feature;

use App\Broadcasting\SocketIoBroadcaster;
use App\Events\ChatActivity;
use App\Events\ChatMessageSent;
use App\Events\ChatTyping;
use App\Services\AuthenticationService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class ChatEventsTest extends TestCase
{
    private string $learner;

    private string $teacher;

    private string $outsider;

    private string $conversation;

    private string $message;

    private bool $transaction = false;

    protected function setUp(): void
    {
        parent::setUp();
        $database = getenv('ONBOARDING_TEST_DATABASE');
        if (! $database) {
            $this->markTestSkipped('Use a disposable ONBOARDING_TEST_DATABASE ending in _test.');
        }
        $this->assertStringEndsWith('_test', $database);
        config(['database.connections.mysql.url' => null, 'database.connections.mysql.database' => $database]);
        DB::purge('mysql');
        Artisan::call('migrate', ['--force' => true]);
        DB::beginTransaction();
        $this->transaction = true;
        foreach (['learner', 'teacher', 'outsider'] as $key) {
            $this->$key = (string) Str::uuid();
            DB::table('users')->insert(['id' => $this->$key, 'account_type' => $key === 'teacher' ? 'teacher' : 'learner',
                'full_name' => 'Chat '.ucfirst($key), 'phone_verified_at' => now(), 'safety_disclaimer_acknowledged_at' => now()]);
        }
        $this->conversation = (string) Str::uuid();
        $this->message = (string) Str::uuid();
        DB::table('conversations')->insert(['id' => $this->conversation, 'learner_id' => $this->learner, 'provider_id' => $this->teacher]);
        DB::table('messages')->insert(['id' => $this->message, 'conversation_id' => $this->conversation, 'sender_id' => $this->learner, 'body' => 'Original']);
        $auth = \Mockery::mock(AuthenticationService::class);
        $auth->shouldReceive('authenticate')->andReturnUsing(fn ($token) => DB::table('users')->where('id', $token)->first());
        $this->app->instance(AuthenticationService::class, $auth);
        $this->withToken($this->learner);
        Event::fake([ChatActivity::class, ChatMessageSent::class, ChatTyping::class]);
    }

    protected function tearDown(): void
    {
        if ($this->transaction && DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        parent::tearDown();
    }

    private function callEvent(string $action, array $extra = [])
    {
        return $this->postJson('/chat/events/'.$action, ['conversationId' => $this->conversation, ...$extra]);
    }

    public function test_any_role_can_start_chat_and_both_sides_reuse_the_same_conversation(): void
    {
        foreach (['learner', 'teacher', 'institute'] as $senderRole) {
            foreach (['learner', 'teacher', 'institute'] as $recipientRole) {
                $sender = (string) Str::uuid();
                $recipient = (string) Str::uuid();
                foreach ([$sender => $senderRole, $recipient => $recipientRole] as $id => $role) {
                    DB::table('users')->insert(['id' => $id, 'account_type' => $role,
                        'full_name' => 'Role '.$role, 'phone_verified_at' => now(), 'safety_disclaimer_acknowledged_at' => now()]);
                }
                $id = $this->withToken($sender)->postJson('/messages/start', ['providerId' => $recipient, 'body' => 'Hello'])
                    ->assertOk()->json('conversationId');
                $this->withToken($recipient)->postJson('/messages/start', ['providerId' => $sender])
                    ->assertOk()->assertJsonPath('conversationId', $id);
                $this->postJson('/messages/'.$id, ['body' => 'Reply'])->assertOk()->assertJsonPath('message.sender_id', $recipient);
                $this->postJson('/chat/socket/authorize', ['conversationId' => $id])->assertOk()->assertJsonPath('room', 'private-conversation.'.$id);
                $this->getJson('/messages')->assertOk()->assertJsonPath('conversations.0.id', $id);
                $this->withToken($sender)->getJson('/messages')->assertOk()->assertJsonPath('conversations.0.id', $id);
                $this->getJson('/messages/'.$id)->assertOk()->assertJsonCount(2, 'messages');
                $this->assertSame(1, DB::table('conversations')->whereIn('learner_id', [$sender, $recipient])->whereIn('provider_id', [$sender, $recipient])->count());
                $this->assertSame(1, DB::table('business_activity')->where('event', 'message.created')->where('actor_user_id', $sender)->where('related_user_id', $recipient)->count());
                $this->withToken($this->outsider)->getJson('/messages/'.$id)->assertNotFound();
            }
        }
    }

    public function test_teacher_can_reopen_existing_learner_chat_without_reversing_participant_columns(): void
    {
        $this->withToken($this->teacher)->postJson('/messages/start', ['providerId' => $this->learner])
            ->assertOk()->assertJsonPath('conversationId', $this->conversation);
        $this->assertSame(1, DB::table('conversations')->count());
        $this->assertSame($this->learner, DB::table('conversations')->value('learner_id'));
    }

    public function test_start_still_rejects_self_unknown_users_and_missing_authentication(): void
    {
        $this->postJson('/messages/start', ['providerId' => strtoupper($this->learner)])->assertStatus(400);
        $this->postJson('/messages/start', ['providerId' => (string) Str::uuid()])->assertUnprocessable();
        $this->withoutHeader('Authorization')->postJson('/messages/start', ['providerId' => $this->teacher])->assertUnauthorized();
        $this->assertSame(1, DB::table('conversations')->count());
    }

    public function test_start_rolls_back_new_conversation_and_message_when_history_recording_fails(): void
    {
        DB::listen(function ($query): void {
            if (str_contains($query->sql, 'insert into `business_activity`')) {
                throw new \RuntimeException('History unavailable');
            }
        });
        $this->withoutExceptionHandling();
        try {
            $this->withToken($this->teacher)->postJson('/messages/start', ['providerId' => $this->outsider, 'body' => 'Do not save']);
            $this->fail('Expected history recording failure');
        } catch (\RuntimeException $error) {
            $this->assertSame('History unavailable', $error->getMessage());
        }
        $this->assertSame(1, DB::table('conversations')->count());
        $this->assertSame(1, DB::table('messages')->count());
        $this->assertSame(0, DB::table('business_activity')->count());
        Event::assertNotDispatched(ChatMessageSent::class);
    }

    public function test_all_events_require_auth_and_outsiders_cannot_access_any_conversation_operation(): void
    {
        foreach (['send', 'delivered', 'read', 'typing', 'stop_typing', 'delete', 'edit', 'reply', 'reaction', 'history', 'unread_count'] as $action) {
            $this->withoutHeader('Authorization')->callEvent($action)->assertUnauthorized();
            $this->withToken($this->outsider)->callEvent($action, ['messageId' => $this->message, 'replyToId' => $this->message, 'body' => 'No', 'reaction' => '👍'])->assertNotFound();
        }
        $this->withToken($this->learner)->callEvent('receive')->assertNotFound();
        $this->callEvent('new')->assertNotFound();
    }

    public function test_send_reply_and_bounded_history_keep_real_message_links_and_reject_cross_conversation_targets(): void
    {
        $sent = $this->callEvent('send', ['body' => 'New message'])->assertOk()->json('message.id');
        $reply = $this->callEvent('reply', ['replyToId' => $sent, 'body' => 'Reply'])->assertOk()->json('message');
        $this->assertSame($sent, $reply['reply_to_id']);
        $this->assertSame(1, DB::table('business_activity')->where('event', 'message.replied')->count());
        $this->callEvent('history', ['perPage' => 1])->assertOk()->assertJsonCount(1, 'messages')->assertJsonPath('pagination.hasMore', true);
        $this->callEvent('history', ['perPage' => 101])->assertUnprocessable();
        $this->callEvent('history', ['cursor' => 'not-a-cursor'])->assertUnprocessable();
        $this->callEvent('reply', ['replyToId' => (string) Str::uuid(), 'body' => 'Invalid target'])->assertNotFound();
        $this->callEvent('send', ['body' => '   '])->assertUnprocessable();
        $this->callEvent('reply', ['body' => 'Missing target'])->assertUnprocessable();
        Event::assertDispatched(ChatMessageSent::class);
    }

    public function test_only_recipient_can_confirm_receipts_idempotently_and_unread_counts_use_real_state(): void
    {
        $this->callEvent('read', ['messageId' => $this->message])->assertForbidden();
        $this->withToken($this->teacher);
        $this->callEvent('unread_count')->assertOk()->assertJsonPath('unreadCount', 1);
        $this->callEvent('delivered', ['messageId' => $this->message])->assertOk();
        $this->callEvent('delivered', ['messageId' => $this->message])->assertOk();
        $this->callEvent('read', ['messageId' => $this->message])->assertOk();
        $this->callEvent('read', ['messageId' => $this->message])->assertOk();
        $this->assertSame(1, DB::table('business_activity')->where('event', 'message.delivered')->count());
        $this->assertSame(1, DB::table('business_activity')->where('event', 'message.read')->count());
        $this->callEvent('unread_count')->assertJsonPath('unreadCount', 0);
        $this->postJson('/chat/events/unread_count', [])->assertOk()->assertJsonPath('conversationId', null)->assertJsonPath('unreadCount', 0);
        $saved = DB::table('messages')->where('id', $this->message)->first();
        $this->assertNotNull($saved->delivered_at);
        $this->assertNotNull($saved->read_at);
    }

    public function test_sender_edits_and_deletes_with_revisions_and_tombstones_but_never_snapshots_message_text(): void
    {
        $this->withToken($this->teacher)->callEvent('edit', ['messageId' => $this->message, 'body' => 'Not mine'])->assertForbidden();
        $this->callEvent('delete', ['messageId' => $this->message])->assertForbidden();
        $this->withToken($this->learner)->callEvent('edit', ['messageId' => $this->message, 'body' => 'Private edited content'])->assertOk()->assertJsonPath('message.revision', 2);
        $this->callEvent('edit', ['messageId' => $this->message, 'body' => 'Private edited content'])->assertOk()->assertJsonPath('message.revision', 2);
        $this->callEvent('delete', ['messageId' => $this->message])->assertOk()->assertJsonPath('message.body', '')->assertJsonPath('message.revision', 3);
        $this->callEvent('delete', ['messageId' => $this->message])->assertOk()->assertJsonPath('message.revision', 3);
        $this->callEvent('edit', ['messageId' => $this->message, 'body' => 'No'])->assertStatus(409);
        $this->callEvent('reply', ['replyToId' => $this->message, 'body' => 'No'])->assertStatus(409);
        $this->callEvent('history')->assertOk()->assertJsonPath('messages.0.body', '');
        $this->assertSame(2, DB::table('business_activity')->whereIn('event', ['message.edited', 'message.deleted'])->count());
        $this->assertStringNotContainsString('Private edited content', DB::table('business_activity')->get()->toJson());
    }

    public function test_reactions_are_per_user_replaceable_removable_and_reportable_with_admin_audited_exports(): void
    {
        $this->callEvent('reaction', ['messageId' => $this->message, 'reaction' => '👍'])->assertOk();
        $this->callEvent('reaction', ['messageId' => $this->message, 'reaction' => '👍'])->assertOk();
        $this->withToken($this->teacher)->callEvent('reaction', ['messageId' => $this->message, 'reaction' => '❤️'])->assertOk();
        $this->callEvent('history')->assertOk()->assertJsonCount(2, 'messages.0.reactions');
        $this->callEvent('reaction', ['messageId' => $this->message, 'reaction' => null])->assertOk()->assertJsonPath('reaction', null);
        $this->callEvent('history')->assertJsonCount(1, 'messages.0.reactions');
        $this->callEvent('reaction', ['messageId' => $this->message, 'reaction' => 'arbitrary'])->assertUnprocessable();
        $this->assertSame(3, DB::table('business_activity')->where('event', 'message.reaction_changed')->count());
        $this->get('/admin/reports/message-reactions?export=1')->assertRedirect(route('admin.login'));
        $url = '/admin/reports/message-reactions?search='.$this->message;
        $this->withSession(['admin_authenticated' => true])->get($url)->assertOk()->assertSee($this->message);
        $csv = $this->get($url.'&export=1')->assertOk()->streamedContent();
        $this->assertStringContainsString($this->message, $csv);
        $this->assertStringContainsString('reaction', $csv);
        $this->assertSame(2, DB::table('admin_report_access_log')->where('report', 'message-reactions')->count());
    }

    public function test_typing_is_transient_and_contact_gates_apply_to_mutations(): void
    {
        $this->callEvent('typing')->assertOk()->assertJsonPath('typing', true)->assertJsonPath('userId', $this->learner);
        $this->callEvent('stop_typing')->assertOk()->assertJsonPath('typing', false);
        Event::assertDispatched(ChatTyping::class);
        $this->assertSame(0, DB::table('business_activity')->count());
        DB::table('users')->where('id', $this->learner)->update(['safety_disclaimer_acknowledged_at' => null]);
        foreach (['send', 'reply', 'typing', 'stop_typing', 'edit', 'delete', 'reaction'] as $action) {
            $this->callEvent($action, ['body' => 'No', 'messageId' => $this->message, 'replyToId' => $this->message, 'reaction' => '👍'])->assertForbidden();
        }
    }

    public function test_history_failure_rolls_back_message_mutation_and_does_not_emit_notifications(): void
    {
        DB::listen(function ($query): void {
            if (str_contains($query->sql, 'insert into `business_activity`')) {
                throw new \RuntimeException('History unavailable');
            }
        });
        $this->callEvent('edit', ['messageId' => $this->message, 'body' => 'Must roll back'])->assertStatus(500);
        $this->assertSame('Original', DB::table('messages')->where('id', $this->message)->value('body'));
        $this->assertSame(1, DB::table('messages')->where('id', $this->message)->value('revision'));
        $this->assertSame(0, DB::table('business_activity')->count());
        Event::assertNotDispatched(ChatActivity::class);
    }

    public function test_persistent_notifications_wait_for_source_commit(): void
    {
        $this->callEvent('reaction', ['messageId' => $this->message, 'reaction' => '👍'])->assertOk();
        Event::assertNotDispatched(ChatActivity::class);
        DB::commit();
        $this->transaction = false;
        try {
            Event::assertDispatched(ChatActivity::class, fn ($event) => $event->event === 'chat:reaction' && $event->data['messageId'] === $this->message);
        } finally {
            // Only this test's own fixtures, in the validated disposable _test database.
            DB::table('message_reactions')->where('conversation_id', $this->conversation)->delete();
            DB::table('business_activity')->where('actor_user_id', $this->learner)->delete();
            DB::table('conversations')->where('id', $this->conversation)->delete();
            DB::table('users')->whereIn('id', [$this->learner, $this->teacher, $this->outsider])->delete();
        }
    }

    public function test_delayed_original_broadcast_reloads_tombstone_instead_of_leaking_deleted_text(): void
    {
        $original = (array) DB::table('messages')->where('id', $this->message)->first();
        $this->callEvent('delete', ['messageId' => $this->message])->assertOk();
        Http::preventStrayRequests();
        Http::fake(['http://bridge.test/internal/events' => Http::response(['ok' => true])]);
        $bridge = new SocketIoBroadcaster(['url' => 'http://bridge.test', 'secret' => str_repeat('test-secret', 4)]);
        $bridge->broadcast(['private-conversation.'.$this->conversation], 'message.sent', ['message' => $original]);
        Http::assertSent(fn ($request) => $request['message']['body'] === '' && $request['message']['deleted_at'] !== null);
    }
}
