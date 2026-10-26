<?php

namespace Tests\Feature;

use App\Broadcasting\SocketIoBroadcaster;
use App\Events\ChatActivity;
use App\Events\ChatMessageSent;
use App\Events\ChatTyping;
use App\Services\AuthenticationService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\Support\NumericTestIds;
use Tests\TestCase;

class ChatBlockingTest extends TestCase
{
    private int $first;

    private int $second;

    private int $outsider;

    private int $conversation;

    private int $message;

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
        $this->assertStringEndsWith('_test', DB::connection()->getDatabaseName());
        Artisan::call('migrate', ['--force' => true]);
        DB::beginTransaction();
        $this->transaction = true;
        foreach (['first', 'second', 'outsider'] as $key) {
            $this->$key = NumericTestIds::next();
            DB::table('users')->insert(['id' => $this->$key, 'account_type' => 'learner',
                'full_name' => 'Block test '.$key, 'phone_verified_at' => now(),
                'safety_disclaimer_acknowledged_at' => now()]);
        }
        $this->conversation = DB::table('conversations')->insertGetId(['learner_id' => $this->first, 'provider_id' => $this->second]);
        $this->message = DB::table('messages')->insertGetId(['conversation_id' => $this->conversation, 'sender_id' => $this->first, 'body' => 'Existing history']);
        $auth = \Mockery::mock(AuthenticationService::class);
        $auth->shouldReceive('authenticate')->andReturnUsing(fn ($token) => DB::table('users')->where('id', $token)->first());
        $this->app->instance(AuthenticationService::class, $auth);
        $this->withToken($this->first);
        Event::fake([ChatActivity::class, ChatMessageSent::class, ChatTyping::class]);
    }

    protected function tearDown(): void
    {
        if ($this->transaction) {
            DB::rollBack();
        }
        parent::tearDown();
    }

    private function block(): void
    {
        $this->withToken($this->first)->postJson('/safety/blocks/'.$this->second)
            ->assertOk()->assertJsonPath('chatStatus.blockedByMe', true)->assertJsonPath('chatStatus.canMessage', false);
    }

    public function test_block_and_unblock_are_idempotent_and_only_remove_the_callers_block(): void
    {
        $this->block();
        $this->block();
        $this->assertSame(1, DB::table('blocks')->count());
        $this->assertSame(1, DB::table('business_activity')->where('event', 'user.blocked')->count());
        $this->withToken($this->second)->postJson('/safety/blocks/'.$this->first)->assertOk();
        $this->withToken($this->first)->deleteJson('/safety/blocks/'.$this->second)->assertOk()
            ->assertJsonPath('blocked', false)->assertJsonPath('chatStatus.blockedByMe', false)->assertJsonPath('chatStatus.canMessage', false);
        $this->deleteJson('/safety/blocks/'.$this->second)->assertOk();
        $this->assertSame(1, DB::table('blocks')->count());
        $this->assertSame($this->second, DB::table('blocks')->value('blocker_id'));
        $this->assertSame(1, DB::table('business_activity')->where('event', 'user.unblocked')->count());
        $this->withToken($this->second)->deleteJson('/safety/blocks/'.$this->first)->assertOk()->assertJsonPath('chatStatus.canMessage', true);
        $event = DB::table('business_activity')->where('event', 'user.blocked')->where('actor_user_id', $this->first)->first();
        $this->assertSame(1, $event->version);
        $this->assertSame($this->second, $event->entity_id);
        $this->assertSame($this->second, $event->related_user_id);
        $this->assertSame(['blocked' => false], json_decode($event->before_data, true));
        $this->assertSame(['blocked' => true], json_decode($event->after_data, true));
    }

    public function test_block_prevents_both_sides_sending_or_starting_through_all_message_routes(): void
    {
        $this->block();
        foreach ([$this->first => $this->second, $this->second => $this->first] as $actor => $other) {
            $this->withToken($actor);
            foreach ([[], ['body' => 'Initial message']] as $initial) {
                $this->postJson('/messages/start', ['providerId' => $other, ...$initial])->assertForbidden()->assertJsonPath('code', 'CHAT_BLOCKED');
            }
            $this->postJson('/messages/'.$this->conversation, ['body' => 'Message'])->assertForbidden()->assertJsonPath('code', 'CHAT_BLOCKED');
            foreach (['send', 'reply'] as $action) {
                $this->postJson('/chat/events/'.$action, ['conversationId' => $this->conversation, 'body' => 'Message', 'replyToId' => $this->message])->assertForbidden();
            }
        }
        $this->assertSame(1, DB::table('messages')->count());
        $this->assertSame(1, DB::table('conversations')->count());
        $this->assertSame(0, DB::table('business_activity')->where('event', 'message.created')->count());
        Event::assertNotDispatched(ChatMessageSent::class);
    }

    public function test_block_prevents_typing_receipts_edits_and_reactions_but_preserves_history_and_deletion(): void
    {
        $this->block();
        foreach (['typing', 'stop_typing', 'edit', 'reaction'] as $action) {
            $this->postJson('/chat/events/'.$action, ['conversationId' => $this->conversation,
                'messageId' => $this->message, 'body' => 'Edited', 'reaction' => '👍'])->assertForbidden()->assertJsonPath('code', 'CHAT_BLOCKED');
        }
        $this->withToken($this->second);
        foreach (['read', 'delivered'] as $action) {
            $this->postJson('/chat/events/'.$action, ['conversationId' => $this->conversation, 'messageId' => $this->message])->assertForbidden();
        }
        $this->getJson('/messages/'.$this->conversation)->assertOk()->assertJsonPath('messages.0.body', 'Existing history')
            ->assertJsonPath('chatStatus.blockedByMe', false)->assertJsonPath('chatStatus.canMessage', false);
        $this->postJson('/chat/events/history', ['conversationId' => $this->conversation])->assertOk();
        $this->postJson('/chat/events/unread_count', ['conversationId' => $this->conversation])->assertOk()->assertJsonPath('unreadCount', 1);
        $this->assertNull(DB::table('messages')->where('id', $this->message)->value('read_at'));
        $this->assertSame(0, DB::table('message_reactions')->count());
        Event::assertNotDispatched(ChatTyping::class);
        Event::assertNotDispatched(ChatActivity::class);
        $this->withToken($this->first)->postJson('/chat/events/delete', ['conversationId' => $this->conversation, 'messageId' => $this->message])->assertOk();
        $this->assertSame('', DB::table('messages')->where('id', $this->message)->value('body'));
    }

    public function test_inbox_and_history_expose_the_correct_user_and_block_state(): void
    {
        $this->block();
        $this->getJson('/messages')->assertOk()->assertJsonPath('conversations.0.other_user_id', $this->second)
            ->assertJsonPath('conversations.0.blocked_by_me', 1)->assertJsonPath('conversations.0.can_message', 0);
        $this->getJson('/messages/'.$this->conversation)->assertOk()->assertJsonPath('chatStatus.otherUserId', $this->second)
            ->assertJsonPath('chatStatus.blockedByMe', true)->assertJsonPath('chatStatus.canMessage', false);
        $this->withToken($this->second)->getJson('/messages')->assertOk()->assertJsonPath('conversations.0.blocked_by_me', 0)
            ->assertJsonPath('conversations.0.can_message', 0);
        $this->withToken($this->outsider)->getJson('/messages/'.$this->conversation)->assertNotFound();
    }

    public function test_socket_and_legacy_channel_authorization_cannot_bypass_blocking(): void
    {
        $callback = Broadcast::getChannels()->get('conversation.{conversationId}');
        Broadcast::connection('socketio')->channel('conversation.{conversationId}', $callback);
        config(['broadcasting.default' => 'socketio']);
        $this->postJson('/broadcasting/auth', ['channel_name' => 'private-conversation.'.$this->conversation, 'socket_id' => '1.2'])->assertOk();
        $this->block();
        foreach ([$this->first, $this->second] as $actor) {
            $this->withToken($actor)->postJson('/chat/socket/authorize')->assertOk();
            $this->postJson('/chat/socket/authorize', ['conversationId' => $this->conversation])->assertForbidden()->assertJsonPath('code', 'CHAT_BLOCKED');
            $this->postJson('/broadcasting/auth', ['channel_name' => 'private-conversation.'.$this->conversation, 'socket_id' => '1.2'])->assertForbidden();
        }
    }

    public function test_unblocking_restores_chat_without_affecting_other_conversations(): void
    {
        $this->block();
        $this->postJson('/messages/start', ['providerId' => $this->outsider, 'body' => 'Other chat'])->assertOk();
        $this->deleteJson('/safety/blocks/'.$this->second)->assertOk();
        $this->postJson('/messages/'.$this->conversation, ['body' => 'Allowed again'])->assertOk();
        $this->withToken($this->second)->postJson('/messages/'.$this->conversation, ['body' => 'Reply'])->assertOk();
        $this->postJson('/chat/socket/authorize', ['conversationId' => $this->conversation])->assertOk();
    }

    public function test_queued_socket_deliveries_are_suppressed_after_blocking_including_already_joined_users(): void
    {
        $message = (array) DB::table('messages')->where('id', $this->message)->first();
        $this->block();
        Http::fake();
        $bridge = new SocketIoBroadcaster(['secret' => str_repeat('test-', 10), 'url' => 'http://chat.test']);
        $channel = ['private-conversation.'.$this->conversation];
        $bridge->broadcast($channel, 'message.sent', ['message' => $message]);
        foreach (['chat:typing', 'chat:read', 'chat:reaction', 'chat:edit', 'chat:reply'] as $event) {
            $bridge->broadcast($channel, $event, ['data' => ['conversationId' => $this->conversation, 'message' => $message]]);
        }
        Http::assertNothingSent();
        $this->deleteJson('/safety/blocks/'.$this->second)->assertOk();
        $bridge->broadcast($channel, 'message.sent', ['message' => $message]);
        Http::assertSentCount(1);
    }

    public function test_block_mutations_validate_targets_and_require_authentication(): void
    {
        foreach (['postJson', 'deleteJson'] as $method) {
            $this->$method('/safety/blocks/'.$this->first)->assertStatus(400);
            $this->$method('/safety/blocks/'.NumericTestIds::next())->assertNotFound();
            $this->$method('/safety/blocks/not-an-id')->assertNotFound();
        }
        $this->withHeader('Authorization', '')->postJson('/safety/blocks/'.$this->second)->assertUnauthorized();
        $this->deleteJson('/safety/blocks/'.$this->second)->assertUnauthorized();
        $this->assertSame(0, DB::table('blocks')->count());
        $this->assertSame(0, DB::table('business_activity')->count());
    }

    public function test_history_failure_rolls_back_both_block_and_unblock(): void
    {
        DB::listen(function (QueryExecuted $query): void {
            if (str_starts_with($query->sql, 'insert into `business_activity`')) {
                throw new RuntimeException('Simulated history failure');
            }
        });
        try {
            $this->postJson('/safety/blocks/'.$this->second)->assertStatus(500);
            $this->assertSame(0, DB::table('blocks')->count());
            $this->assertSame(0, DB::table('business_activity')->count());
            DB::table('blocks')->insert(['blocker_id' => $this->first, 'blocked_id' => $this->second]);
            $this->deleteJson('/safety/blocks/'.$this->second)->assertStatus(500);
            $this->assertSame(1, DB::table('blocks')->count());
            $this->assertSame(0, DB::table('business_activity')->count());
        } finally {
            Event::forget(QueryExecuted::class);
        }
    }

    public function test_block_history_remains_reportable_after_unblock_with_filters_exports_and_admin_logging(): void
    {
        $this->block();
        $this->deleteJson('/safety/blocks/'.$this->second)->assertOk();
        $this->get('/admin/reports/activity?export=1')->assertRedirect(route('admin.login'));
        $this->withSession(['admin_authenticated' => true]);
        $filters = '?q=user.block&user='.$this->second.'&type=learner&from='.today()->format('Y-m-d').'&to='.today()->format('Y-m-d');
        $this->get('/admin/reports/activity'.$filters)->assertOk()->assertSee('user.blocked')->assertDontSee('user.unblocked');
        $csv = $this->get('/admin/reports/activity'.$filters.'&export=1')->assertOk()->streamedContent();
        $this->assertStringContainsString('user.blocked', $csv);
        $this->assertStringNotContainsString('user.unblocked', $csv);
        $this->assertStringNotContainsString('password', $csv);
        $unblocked = $this->get('/admin/reports/activity?q=user.unblocked&export=1')->assertOk()->streamedContent();
        $this->assertStringContainsString('user.unblocked', $unblocked);
        $this->assertSame(0, DB::table('blocks')->count());
        $this->assertSame(2, DB::table('business_activity')->count());
        $this->assertSame(3, DB::table('admin_report_access_log')->count());
    }
}
