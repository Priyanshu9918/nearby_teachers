<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DeveloperChatTesterTest extends TestCase
{
    public function test_chat_tester_requires_developer_login(): void
    {
        $this->get('/developer')->assertRedirect(route('developer.login'));
    }

    public function test_developer_can_load_the_socket_tester_with_local_assets(): void
    {
        $this->withSession(['developer_authenticated' => true])
            ->get('/developer')
            ->assertOk()
            ->assertSee('Live chat tester')
            ->assertSee('id="chat-token" type="password"', false)
            ->assertSee('id="chat-ping"', false)
            ->assertSee('id="chat-send"', false)
            ->assertSee('id="chat-run"', false)
            ->assertSee('id="chat-operation"', false)
            ->assertSee('id="chat-start"', false)
            ->assertSee('Mock response')
            ->assertSee('vendor/socket.io/socket.io.min.js')
            ->assertSee('js/developer-chat.js')
            ->assertSee('Both methods save messages to the database.')
            ->assertSee('id="chat-navigation"', false)
            ->assertSee('data-chat-view="send"', false)
            ->assertSee('data-chat-view="receive"', false);
    }

    public function test_health_check_requires_developer_session_and_does_not_call_server_for_guests(): void
    {
        Http::preventStrayRequests();
        $this->get('/developer/socket/health')->assertRedirect(route('developer.login'));
        Http::assertNothingSent();
    }

    public function test_health_uses_only_configured_destination_without_credentials_or_raw_upstream_payloads(): void
    {
        config(['broadcasting.connections.socketio.url' => 'http://127.0.0.1:6001']);
        Http::preventStrayRequests();
        Http::fake(['http://127.0.0.1:6001/health' => Http::response(['ok' => true, 'secret' => 'must-not-leak'])]);
        $this->withSession(['developer_authenticated' => true])
            ->getJson('/developer/socket/health?url=http://untrusted.test&token=do-not-forward')
            ->assertOk()->assertJsonPath('ok', true)->assertJsonPath('service', 'Socket.IO')
            ->assertHeader('Cache-Control', 'no-store, private')->assertDontSee('must-not-leak');
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->url() === 'http://127.0.0.1:6001/health'
            && ! $request->hasHeader('Authorization') && ! $request->hasHeader('X-Socket-Secret'));
    }

    public function test_health_fails_closed_for_unready_invalid_and_unreachable_servers(): void
    {
        config(['broadcasting.connections.socketio.url' => 'http://127.0.0.1:6001']);
        Http::fake(['*' => Http::response(['ok' => false], 503)]);
        $this->withSession(['developer_authenticated' => true])->getJson('/developer/socket/health')
            ->assertStatus(503)->assertJsonPath('ok', false);
        Http::fake(['*' => Http::failedConnection()]);
        $this->getJson('/developer/socket/health')->assertStatus(503)->assertJsonPath('ok', false);
        Http::fake();
        config(['broadcasting.connections.socketio.url' => 'http://user:secret@internal.test']);
        $this->getJson('/developer/socket/health')->assertStatus(503)->assertDontSee('secret');
        Http::assertNothingSent();
    }
}
