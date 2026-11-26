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
            ->assertForbidden()->assertExactJson([
                'code' => 'ACCOUNT_DELETED',
                'error' => 'This account has been deleted. Contact support to restore it.',
            ]);
    }

    public function test_reactivation_requires_admin_authentication(): void
    {
        $this->postJson('/moderation/users/123/reactivate')->assertUnauthorized();

        $authentication = \Mockery::mock(AuthenticationService::class);
        $authentication->shouldReceive('authenticate')->with('user-token')->once()
            ->andReturn((object) [
                'id' => 456, 'is_admin' => false, 'phone_verified_at' => now(),
                'deactivated_at' => null, 'suspended_at' => null,
            ]);
        $this->app->instance(AuthenticationService::class, $authentication);
        $this->postJson('/moderation/users/123/reactivate', [], ['Authorization' => 'Bearer user-token'])
            ->assertForbidden()->assertJsonPath('error', 'Admin access required');
    }
}
