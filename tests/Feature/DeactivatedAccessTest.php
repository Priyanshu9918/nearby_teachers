<?php

namespace Tests\Feature;

use App\Services\AuthenticationService;
use Tests\TestCase;

class DeactivatedAccessTest extends TestCase
{
    public function test_deactivated_token_cannot_access_protected_api(): void
    {
        $authentication = \Mockery::mock(AuthenticationService::class);
        $authentication->shouldReceive('authenticate')->with('deactivated-token')->once()
            ->andReturn((object) ['id' => 123, 'deactivated_at' => now(), 'suspended_at' => null]);
        $this->app->instance(AuthenticationService::class, $authentication);

        $this->getJson('/auth/session', ['Authorization' => 'Bearer deactivated-token'])
            ->assertForbidden()->assertJsonPath('code', 'ACCOUNT_DEACTIVATED');
    }
}
