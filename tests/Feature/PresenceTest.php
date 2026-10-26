<?php

namespace Tests\Feature;

use App\Services\AuthenticationService;
use App\Services\Presence\DatabasePresenceStore;
use App\Services\Presence\PresenceStore;
use App\Services\Presence\UserPresence;
use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Mockery;
use RuntimeException;
use Tests\Support\NumericTestIds;
use Tests\TestCase;

class PresenceTest extends TestCase
{
    private int $teacher;

    private int $institute;

    private int $subject;

    private bool $transaction = false;

    protected function setUp(): void
    {
        parent::setUp();
        $database = getenv('ONBOARDING_TEST_DATABASE');
        if (! $database) {
            $this->markTestSkipped('Requires disposable ONBOARDING_TEST_DATABASE ending in _test.');
        }
        $this->assertStringEndsWith('_test', $database);
        config([
            'database.connections.mysql.url' => null, 'database.connections.mysql.database' => $database,
            'presence.enabled' => true,
        ]);
        DB::purge('mysql');
        $this->assertStringEndsWith('_test', DB::connection()->getDatabaseName());
        Artisan::call('migrate', ['--force' => true]);
        DB::beginTransaction();
        $this->transaction = true;
        foreach (['teacher', 'institute'] as $role) {
            $this->$role = NumericTestIds::next();
            DB::table('users')->insert(['id' => $this->$role, 'account_type' => $role,
                'full_name' => 'Presence '.$role, 'phone_verified_at' => now(), 'profile_completed_at' => now()]);
            DB::table($role.'_profiles')->insert(['id' => NumericTestIds::next(), 'user_id' => $this->$role,
                'latitude' => 10, 'longitude' => 20, 'city' => 'PresenceCity', 'state' => 'PresenceState',
                'country' => 'PresenceCountry', 'lesson_modes' => '["online"]']);
        }
        $domain = DB::table('subject_domains')->insertGetId(['name' => 'Presence domain']);
        $this->subject = DB::table('subjects')->insertGetId(['domain_id' => $domain, 'name' => 'Presence subject']);
        foreach (['teacher', 'institute'] as $type) {
            DB::table('profile_subjects')->insert(['profile_type' => $type, 'profile_id' => $this->$type, 'subject_id' => $this->subject]);
        }
        $auth = Mockery::mock(AuthenticationService::class);
        $auth->shouldReceive('authenticate')->andReturnUsing(fn ($token) => DB::table('users')->where('id', $token)->first());
        $this->app->instance(AuthenticationService::class, $auth);
        $this->withToken($this->teacher);
        Carbon::setTestNow('2026-10-26 12:00:00 UTC');
    }

    protected function tearDown(): void
    {
        if ($this->transaction) {
            DB::disableQueryLog();
            DB::rollBack();
        }
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function event(string $event, string $device = 'test-device-a')
    {
        return $this->postJson('/presence/update', ['deviceId' => $device, 'event' => $event]);
    }

    private function active(int $id, int $version = 0): bool
    {
        return app(PresenceStore::class)->activeFor([$id => $version], now()->timestamp)[$id];
    }

    public function test_heartbeat_does_not_extend_five_minute_idle_deadline_and_activity_reactivates(): void
    {
        $this->event('foreground')->assertOk()->assertExactJson(['isActive' => true])->assertHeader('Cache-Control', 'no-store, private');
        foreach (range(1, 9) as $i) {
            $this->travel(30)->seconds();
            $this->event('heartbeat')->assertOk()->assertJsonPath('isActive', true);
        }
        $this->travel(29)->seconds();
        $this->assertTrue($this->active($this->teacher));
        $this->travel(1)->seconds();
        $this->event('heartbeat')->assertOk()->assertJsonPath('isActive', false);
        $this->assertFalse($this->active($this->teacher));
        $this->event('activity')->assertOk()->assertJsonPath('isActive', true);
    }

    public function test_crash_or_lost_connection_expires_at_ninety_seconds_without_a_cleanup_job(): void
    {
        $this->event('foreground')->assertOk();
        $this->travel(89)->seconds();
        $this->assertTrue($this->active($this->teacher));
        $this->travel(1)->seconds();
        $this->assertFalse($this->active($this->teacher));
        $this->event('heartbeat')->assertStatus(409)->assertJsonPath('code', 'PRESENCE_FOREGROUND_REQUIRED');
        $this->event('foreground')->assertOk()->assertJsonPath('isActive', true);
    }

    public function test_background_is_immediate_idempotent_and_late_heartbeats_cannot_reopen_it(): void
    {
        $this->event('foreground')->assertOk();
        $this->event('background')->assertOk()->assertJsonPath('isActive', false);
        $this->event('background')->assertOk()->assertJsonPath('isActive', false);
        foreach (['heartbeat', 'activity'] as $event) {
            $this->event($event)->assertStatus(409)->assertJsonPath('code', 'PRESENCE_FOREGROUND_REQUIRED');
        }
        $this->assertFalse($this->active($this->teacher));
    }

    public function test_each_device_keeps_its_own_heartbeat_and_activity_and_accounts_are_isolated(): void
    {
        $this->event('foreground', 'device-one')->assertOk();
        $this->event('foreground', 'device-two')->assertOk();
        $this->event('background', 'device-one')->assertOk()->assertJsonPath('isActive', true);
        $this->withToken($this->institute)->event('background', 'device-two')->assertOk()->assertJsonPath('isActive', false);
        $this->assertTrue($this->active($this->teacher));
        $this->withToken($this->teacher)->event('background', 'device-two')->assertOk()->assertJsonPath('isActive', false);
    }

    public function test_activity_on_one_device_cannot_be_kept_alive_by_heartbeats_on_another(): void
    {
        $this->event('foreground', 'device-one')->assertOk();
        $this->event('foreground', 'device-two')->assertOk();
        foreach (range(1, 10) as $i) {
            $this->travel(30)->seconds();
            $this->event('heartbeat', 'device-two')->assertOk();
            if ($i === 9) {
                $this->event('foreground', 'device-one')->assertOk();
                $this->event('background', 'device-one')->assertOk();
            }
        }
        $this->assertFalse($this->active($this->teacher));
    }

    public function test_device_growth_is_bounded_and_stale_devices_can_be_reclaimed(): void
    {
        foreach (range(1, 20) as $i) {
            $this->event('foreground', 'device-id-'.$i)->assertOk();
        }
        $this->event('foreground', 'device-id-21')->assertStatus(429)->assertJsonPath('code', 'PRESENCE_DEVICE_LIMIT');
        $this->travel(90)->seconds();
        $this->event('foreground', 'device-id-21')->assertOk();
        $rows = DB::table('user_presence_devices')->where('user_id', $this->teacher)->get();
        $this->assertCount(1, $rows);
        $this->assertSame(hash('sha256', 'device-id-21'), $rows[0]->device_hash);
        $this->assertSame(now()->timestamp + 90, (int) $rows[0]->expires_at);
        $this->assertStringNotContainsString('device-id-21', json_encode($rows));
    }

    public function test_presence_requires_verified_authentication_and_valid_server_owned_inputs(): void
    {
        foreach ([[], ['deviceId' => '../bad', 'event' => 'foreground'], ['deviceId' => 'valid-device', 'event' => 'online'],
            ['deviceId' => str_repeat('x', 129), 'event' => 'foreground'],
            ['deviceId' => 'valid-device', 'event' => 'foreground', 'userId' => $this->institute],
            ['deviceId' => 'valid-device', 'event' => 'foreground', 'isActive' => true],
            ['deviceId' => 'valid-device', 'event' => 'foreground', 'lastActiveAt' => '2030-01-01']] as $data) {
            $this->postJson('/presence/update', $data)->assertUnprocessable();
        }
        $this->assertFalse($this->active($this->teacher));
        $this->withHeader('Authorization', '')->event('foreground')->assertUnauthorized();
        DB::table('users')->where('id', $this->teacher)->update(['phone_verified_at' => null]);
        $this->withToken($this->teacher)->event('foreground')->assertForbidden();
        DB::table('users')->where('id', $this->teacher)->update(['phone_verified_at' => now(), 'suspended_at' => now()]);
        $this->event('foreground')->assertForbidden();
    }

    public function test_every_provider_search_exposes_boolean_presence_without_filtering_or_reordering_results(): void
    {
        $this->event('foreground')->assertOk();
        $paths = ['/search/dashboard?lat=10&lng=20', '/search?lat=10&lng=20', '/search/other-city?city=PresenceCity'];
        foreach (['nearby&lat=10&lng=20', 'city&city=PresenceCity', 'state&state=PresenceState',
            'country&country=PresenceCountry', 'other-countries&country=PresenceCountry'] as $tier) {
            $paths[] = '/search/by-subject/'.$this->subject.'?tier='.$tier;
        }
        foreach ($paths as $path) {
            $response = $this->getJson($path)->assertOk();
            $rows = collect($response->json('results'));
            $this->assertTrue($rows->firstWhere('user_id', $this->teacher)['isActive'], $path);
            $this->assertFalse($rows->firstWhere('user_id', $this->institute)['isActive'], $path);
            $ids = $rows->pluck('user_id')->all();
            $this->event('background')->assertOk();
            $inactive = $this->getJson($path)->assertOk();
            $this->assertSame($ids, array_column($inactive->json('results'), 'user_id'), $path);
            $this->event('foreground')->assertOk();
        }
        $this->getJson('/search/counts?lat=10&lng=20')->assertOk()->assertJsonMissingPath('isActive');
        $this->getJson('/search/by-subject/'.$this->subject.'?tier=other-countries')->assertOk()->assertJsonMissingPath('isActive');
    }

    public function test_current_auth_version_and_suspension_prevent_stale_active_flags(): void
    {
        $this->event('foreground')->assertOk();
        DB::table('users')->where('id', $this->teacher)->increment('auth_version');
        $rows = app(UserPresence::class)->decorate([(object) ['user_id' => $this->teacher]]);
        $this->assertFalse($rows[0]->isActive);
        $this->event('foreground')->assertOk();
        $rows = app(UserPresence::class)->decorate([(object) ['user_id' => $this->teacher]]);
        $this->assertTrue($rows[0]->isActive);
        DB::table('users')->where('id', $this->teacher)->update(['suspended_at' => now()]);
        $this->assertFalse(app(UserPresence::class)->decorate([(object) ['user_id' => $this->teacher]])[0]->isActive);
    }

    public function test_result_enrichment_batches_user_versions_and_presence_reads(): void
    {
        $store = Mockery::mock(PresenceStore::class);
        $store->shouldReceive('activeFor')->once()->with([$this->teacher => 0, $this->institute => 0], now()->timestamp)
            ->andReturn([$this->teacher => true, $this->institute => false]);
        $rows = collect(range(1, 50))->map(fn ($i) => (object) ['user_id' => $i % 2 ? $this->teacher : $this->institute]);
        DB::enableQueryLog();
        $result = (new UserPresence($store))->decorate($rows);
        $this->assertCount(1, DB::getQueryLog());
        $this->assertTrue($result[0]->isActive);
        $this->assertFalse($result[1]->isActive);
    }

    public function test_outages_return_503_for_updates_and_keep_search_available_with_false_flags(): void
    {
        Log::spy();
        $store = Mockery::mock(PresenceStore::class);
        $store->shouldReceive('update')->once()->andThrow(new RuntimeException('private-connection-secret'));
        $store->shouldReceive('activeFor')->once()->andThrow(new RuntimeException('private-connection-secret'));
        $this->app->instance(PresenceStore::class, $store);
        $this->event('foreground')->assertStatus(503)->assertJsonPath('code', 'PRESENCE_UNAVAILABLE')->assertDontSee('private-connection-secret');
        $response = $this->getJson('/search?lat=10&lng=20')->assertOk();
        foreach ($response->json('results') as $row) {
            $this->assertFalse($row['isActive']);
        }
        Log::shouldHaveReceived('warning')->twice()->with('Presence database store is unavailable.', ['exception' => RuntimeException::class]);
    }

    public function test_disabled_presence_makes_no_store_or_enrichment_database_calls(): void
    {
        config(['presence.enabled' => false]);
        $store = Mockery::mock(PresenceStore::class);
        $store->shouldNotReceive('update');
        $store->shouldNotReceive('activeFor');
        $this->app->instance(PresenceStore::class, $store);
        $this->event('foreground')->assertStatus(503);
        DB::enableQueryLog();
        $rows = (new UserPresence($store))->decorate([(object) ['user_id' => $this->teacher]]);
        $this->assertFalse($rows[0]->isActive);
        $this->assertCount(0, DB::getQueryLog());
    }

    public function test_presence_does_not_mutate_business_records_or_create_history_per_heartbeat(): void
    {
        $before = (array) DB::table('users')->where('id', $this->teacher)->first();
        $this->event('foreground')->assertOk();
        $this->event('heartbeat')->assertOk();
        $this->event('activity')->assertOk();
        $this->event('background')->assertOk();
        $this->assertSame($before, (array) DB::table('users')->where('id', $this->teacher)->first());
        $this->assertSame(0, DB::table('business_activity')->count());
    }

    public function test_database_reads_are_batched_and_heartbeat_updates_do_not_grow_rows(): void
    {
        $this->event('foreground')->assertOk();
        foreach (range(1, 10) as $i) {
            $this->travel(30)->seconds();
            $this->event('heartbeat')->assertOk();
        }
        $this->assertSame(1, DB::table('user_presence_devices')->where('user_id', $this->teacher)->count());
        $this->event('activity')->assertOk();
        $rows = collect(range(1, 500))->map(fn ($i) => (object) ['user_id' => $i % 2 ? $this->teacher : $this->institute]);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $result = app(UserPresence::class)->decorate($rows);
        $this->assertCount(2, DB::getQueryLog());
        $this->assertTrue($result[0]->isActive);
        $this->assertFalse($result[1]->isActive);
        DB::disableQueryLog();
    }

    public function test_prune_is_bounded_and_keeps_unexpired_devices(): void
    {
        foreach (range(1, 5) as $i) {
            $this->event('foreground', 'expired-device-'.$i)->assertOk();
        }
        $this->travel(90)->seconds();
        $this->withToken($this->institute)->event('foreground')->assertOk();
        $this->artisan('presence:prune', ['--batch' => 2, '--batches' => 2])
            ->expectsOutputToContain('Removed 4 expired presence devices.')->assertSuccessful();
        $this->assertSame(2, DB::table('user_presence_devices')->count());
        $this->assertTrue($this->active($this->institute));
        $this->assertFalse($this->active($this->teacher));
        $this->artisan('presence:prune')->expectsOutputToContain('Removed 1 expired presence devices.')->assertSuccessful();
        $this->assertSame(1, DB::table('user_presence_devices')->count());
        $this->artisan('presence:prune', ['--batch' => 0])->assertExitCode(2);
        $this->artisan('presence:prune', ['--batch' => 5001])->assertExitCode(2);
        $this->artisan('presence:prune', ['--batches' => 101])->assertExitCode(2);
    }

    public function test_stale_authenticated_requests_cannot_recreate_logged_out_presence(): void
    {
        $this->event('foreground')->assertOk();
        DB::table('users')->where('id', $this->teacher)->increment('auth_version');
        $store = app(DatabasePresenceStore::class);
        $this->assertSame([3, 0], $store->update($this->teacher, 0, 'stale-device', 'foreground', now()->timestamp));
        $this->assertFalse($this->active($this->teacher, 1));
        $this->event('foreground')->assertOk();
        $this->assertSame(1, DB::table('user_presence_devices')->where('user_id', $this->teacher)->count());
        $this->assertSame(1, (int) DB::table('user_presence_devices')->where('user_id', $this->teacher)->value('auth_version'));
    }

    public function test_presence_is_rate_limited_per_user_including_multiple_devices(): void
    {
        foreach (range(1, 120) as $i) {
            $this->event('background', 'test-device-'.($i % 2))->assertOk();
        }
        $this->event('background')->assertStatus(429);
        $this->withToken($this->institute)->event('background')->assertOk();
    }
}
