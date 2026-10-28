<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\OnboardingController;
use App\Services\AuthenticationService;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SessionProfileLinksTest extends TestCase
{
    #[DataProvider('profiles')]
    public function test_session_returns_provider_website_links(string $type, ?array $fields, ?string $path): void
    {
        config(['seo.public_url' => 'https://profiles.example.test']);
        $user = (object) ['id' => 123, 'account_type' => $type, 'suspended_at' => null, 'is_admin' => false, 'phone_verified_at' => null];
        $auth = \Mockery::mock(AuthenticationService::class);
        $auth->shouldReceive('authenticate')->with('session-token')->once()->andReturn($user);
        $this->app->instance(AuthenticationService::class, $auth);
        $onboarding = \Mockery::mock(OnboardingController::class);
        $onboarding->shouldReceive('show')->once()->andReturn(response()->json([
            'registrationCompleted' => true, 'missingFields' => [],
        ]));
        $this->app->instance(OnboardingController::class, $onboarding);

        $profileQuery = \Mockery::mock(Builder::class);
        DB::shouldReceive('table')->with($type === 'teacher' ? 'teacher_profiles' : 'institute_profiles')
            ->once()->andReturn($profileQuery);
        $profileQuery->shouldReceive('where')->with('user_id', 123)->once()->andReturnSelf();
        $profileQuery->shouldReceive('first')->once()->andReturn($fields === null ? null : (object) $fields);

        $subjects = \Mockery::mock(Builder::class);
        DB::shouldReceive('table')->with('profile_subjects as ps')->once()->andReturn($subjects);
        $subjects->shouldReceive('join')->once()->andReturnSelf();
        $subjects->shouldReceive('where')->twice()->andReturnSelf();
        $subjects->shouldReceive('orderBy')->once()->andReturnSelf();
        $subjects->shouldReceive('get')->once()->andReturn(collect());

        $users = \Mockery::mock(Builder::class);
        DB::shouldReceive('table')->with('users')->once()->andReturn($users);
        $users->shouldReceive('where')->with('id', 123)->once()->andReturnSelf();
        $users->shouldReceive('first')->once()->andReturn(clone $user);

        $expected = $path === null ? null : ($type === 'teacher'
            ? 'https://profiles.example.test'.$path : url($path));
        $response = $this->getJson('/auth/session', ['Authorization' => 'Bearer session-token'])
            ->assertOk()->assertJsonPath('webPageUrl', $expected);
        $response->assertJsonPath($type === 'teacher' ? 'teacherProfileUrl' : 'instituteProfileUrl', $expected);
        if ($type === 'teacher') {
            $response->assertJsonPath('teacherSeoUrl', $expected);
        }
    }

    public static function profiles(): array
    {
        return [
            'published teacher' => ['teacher', ['is_profile_complete' => true, 'public_page_slug' => 'ravi-123', 'seo_country' => 'in'], '/teacher/ravi-123'],
            'unpublished teacher' => ['teacher', ['is_profile_complete' => true], null],
            'missing teacher' => ['teacher', null, null],
            'institute with slug' => ['institute', ['slug' => 'academy-123', 'is_profile_complete' => true], '/site/institute/academy-123'],
            'draft institute with slug' => ['institute', ['slug' => 'draft-123', 'is_profile_complete' => false], null],
            'institute without slug' => ['institute', ['slug' => ''], null],
            'missing institute' => ['institute', null, null],
        ];
    }
}
