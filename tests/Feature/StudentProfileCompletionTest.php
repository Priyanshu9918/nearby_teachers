<?php

namespace Tests\Feature;

use App\Services\AuthenticationService;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class StudentProfileCompletionTest extends TestCase
{
    #[DataProvider('completionCases')]
    public function test_learner_profile_reports_saved_completion_state(array $overrides, bool $complete): void
    {
        $saved = (object) array_replace([
            'id' => 123,
            'account_type' => 'learner',
            'full_name' => 'Asha Sharma',
            'email' => 'asha@example.test',
            'email_verified_at' => null,
            'date_of_birth' => '2000-04-12',
            'is_minor' => false,
            'phone' => '+919876543210',
            'phone_verified_at' => '2026-10-22 10:00:00',
            'phone_pin' => '226010',
            'avatar_url' => null,
            'avatar_status' => null,
            'created_at' => '2026-10-22 10:00:00',
            'updated_at' => '2026-10-22 10:00:00',
            'registration_submitted_at' => '2026-10-22 10:00:00',
            'profile_completed_at' => '2026-10-22 10:00:00',
            'parental_consent_confirmed_at' => null,
        ], $overrides);

        $authentication = \Mockery::mock(AuthenticationService::class);
        $authentication->shouldReceive('authenticate')->with('student-token')->once()
            ->andReturn((object) [
                'id' => $saved->id, 'account_type' => 'learner',
                'phone_verified_at' => $saved->phone_verified_at,
                'suspended_at' => null, 'is_admin' => false,
            ]);
        $this->app->instance(AuthenticationService::class, $authentication);

        $query = \Mockery::mock(Builder::class);
        DB::shouldReceive('table')->with('users')->once()->andReturn($query);
        $query->shouldReceive('where')->with('id', $saved->id)->once()->andReturnSelf();
        // Return only selected columns so missing completion fields reproduce the bug.
        $query->shouldReceive('first')->once()->andReturnUsing(
            fn (array $columns) => (object) array_intersect_key((array) $saved, array_flip($columns))
        );

        $response = $this->getJson('/student/profile', ['Authorization' => 'Bearer student-token'])
            ->assertOk()
            ->assertJsonPath('profileComplete', $complete)
            ->assertJsonPath('profile.phone_pin', '226010');

        foreach (['account_type', 'registration_submitted_at', 'profile_completed_at', 'parental_consent_confirmed_at'] as $field) {
            $this->assertArrayNotHasKey($field, $response->json('profile'));
        }
    }

    public static function completionCases(): array
    {
        return [
            'completed adult' => [[], true],
            'incomplete registration' => [['profile_completed_at' => null], false],
            'missing DOB' => [['date_of_birth' => null], false],
            'minor awaiting consent' => [['is_minor' => true, 'date_of_birth' => '2012-04-12'], false],
            'minor with consent' => [[
                'is_minor' => true, 'date_of_birth' => '2012-04-12',
                'parental_consent_confirmed_at' => '2026-10-22 10:00:00',
            ], true],
            'legacy completion without submission timestamp' => [['registration_submitted_at' => null], true],
        ];
    }
}
