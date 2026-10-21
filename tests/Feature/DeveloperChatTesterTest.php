<?php

namespace Tests\Feature;

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
            ->assertSee('vendor/socket.io/socket.io.min.js')
            ->assertSee('js/developer-chat.js')
            ->assertSee('saves to database');
    }
}
