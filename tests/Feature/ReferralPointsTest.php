<?php

namespace Tests\Feature;

use App\Services\AuthenticationService;
use App\Services\RegistrationPoints;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Mockery;
use RuntimeException;
use Tests\Support\NumericTestIds;
use Tests\TestCase;

class ReferralPointsTest extends TestCase
{
    private bool $transactionStarted = false;

    /** @var array<string, object> */
    private array $authenticatedUsers = [];

    protected function setUp(): void
    {
        parent::setUp();

        $database = getenv('ONBOARDING_TEST_DATABASE');
        if (! $database) {
            $this->markTestSkipped('Set ONBOARDING_TEST_DATABASE to a disposable MySQL database ending in _test.');
        }
        $this->assertStringEndsWith('_test', $database);
        config(['database.connections.mysql.url' => null, 'database.connections.mysql.database' => $database]);
        DB::purge('mysql');
        $this->assertStringEndsWith('_test', DB::connection()->getDatabaseName());
        Artisan::call('migrate', ['--force' => true]);
        DB::beginTransaction();
        $this->transactionStarted = true;

        $authentication = Mockery::mock(AuthenticationService::class);
        $authentication->shouldReceive('authenticate')->andReturnUsing(
            fn (string $token): ?object => (new AuthenticationService)->publicUser($this->authenticatedUsers[$token] ?? null)
        );
        $this->app->instance(AuthenticationService::class, $authentication);
    }

    protected function tearDown(): void
    {
        if ($this->transactionStarted) {
            DB::rollBack();
        }

        parent::tearDown();
    }

    public function test_successful_redemption_awards_both_users_50_points_only_once(): void
    {
        $referrer = $this->user('teacher', 'Referrer');
        $referred = $this->user('learner', 'Referred');
        DB::table('users')->where('id', $referrer)->update(['referral_code' => 'REFERRER01']);

        $this->postJson('/referrals/redeem', ['code' => 'REFERRER01'], $this->bearerFor($referred))
            ->assertOk()->assertJsonPath('referrerReferralPoints', 50)->assertJsonPath('redeemerReferralPoints', 50);
        $this->assertSame(50, (int) DB::table('users')->where('id', $referrer)->value('referral_points'));
        $this->assertSame(50, (int) DB::table('users')->where('id', $referred)->value('referral_points'));
        $this->assertSame(1, DB::table('referrals')->where('referrer_id', $referrer)->count());

        $activity = DB::table('business_activity')->where('event', 'referral.joined')->first();
        $this->assertNotNull($activity);
        $this->assertSame((string) $referred, (string) $activity->actor_user_id);
        $this->assertSame((string) $referrer, (string) $activity->related_user_id);
        $this->assertSame(['source' => 'referral_code'], json_decode($activity->after_data, true, 512, JSON_THROW_ON_ERROR));
        $pointActivity = DB::table('business_activity')->where('event', 'referral.recorded')->first();
        $this->assertNotNull($pointActivity);
        $this->assertSame(['point_delta' => 50], json_decode($pointActivity->after_data, true, 512, JSON_THROW_ON_ERROR));
        $redeemerActivity = DB::table('business_activity')->where('event', 'referral.redeemer_credited')->first();
        $this->assertNotNull($redeemerActivity);
        $this->assertSame(['point_delta' => 50], json_decode($redeemerActivity->after_data, true, 512, JSON_THROW_ON_ERROR));

        $this->postJson('/referrals/redeem', ['code' => 'REFERRER01'], $this->bearerFor($referred))
            ->assertStatus(409);
        $this->assertSame(50, (int) DB::table('users')->where('id', $referrer)->value('referral_points'));
        $this->assertSame(50, (int) DB::table('users')->where('id', $referred)->value('referral_points'));
        $this->assertSame(1, DB::table('business_activity')->where('event', 'referral.joined')->count());
        $this->assertSame(1, DB::table('business_activity')->where('event', 'referral.recorded')->count());
        $this->assertSame(1, DB::table('business_activity')->where('event', 'referral.redeemer_credited')->count());

        $this->getJson('/referrals/mine', $this->bearerFor($referrer))
            ->assertOk()->assertJsonPath('referralPoints', 50)->assertJsonPath('referredCount', 1)
            ->assertJsonPath('referred.0.full_name', 'Referred')
            ->assertJsonPath('referred.0.account_type', 'learner')
            ->assertJsonPath('referred.0.avatar_url', null);
    }

    public function test_valid_link_open_tracks_analytics_without_awarding_points(): void
    {
        $referrer = $this->user('teacher', 'Referrer');
        DB::table('users')->where('id', $referrer)->update(['referral_code' => 'CLICK123']);
        config(['services.nearby_teacher.android_play_store_url' => 'https://play.google.com/store/apps/details?id=example']);
        $this->withoutMiddleware(EncryptCookies::class);

        $firstOpen = $this->withHeader('User-Agent', 'Android')
            ->get('/download?ref=CLICK123')->assertOk()
            ->assertViewIs('site.referral')->assertSee('CLICK123')->assertSee('Redeem referral');
        $cookie = collect($firstOpen->headers->getCookies())
            ->first(static fn ($cookie): bool => $cookie->getName() === 'nt_referral_visitor');
        $this->assertNotNull($cookie);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $cookie->getValue());
        $this->assertSame(0, (int) DB::table('users')->where('id', $referrer)->value('referral_points'));
        $this->assertSame(1, DB::table('referral_clicks')->where('referrer_id', $referrer)->count());
        $this->assertSame(1, DB::table('business_activity')->where('event', 'referral.clicked')->count());

        $this->withCookie('nt_referral_visitor', $cookie->getValue())
            ->get('/download?ref=CLICK123')->assertOk();
        $this->assertSame(0, (int) DB::table('users')->where('id', $referrer)->value('referral_points'));
        $this->assertSame(1, DB::table('referral_clicks')->where('referrer_id', $referrer)->count());
        $this->assertSame(1, DB::table('business_activity')->where('event', 'referral.clicked')->count());

        $this->withCookie('nt_referral_visitor', str_repeat('b', 64))
            ->get('/download?ref=CLICK123')->assertOk();
        $this->assertSame(0, (int) DB::table('users')->where('id', $referrer)->value('referral_points'));
        $this->assertSame(2, DB::table('referral_clicks')->where('referrer_id', $referrer)->count());
        $this->assertSame(2, DB::table('business_activity')->where('event', 'referral.clicked')->count());
    }

    public function test_click_analytics_and_history_roll_back_together_without_points(): void
    {
        $referrer = $this->user('institute', 'Referrer');
        DB::table('users')->where('id', $referrer)->update(['referral_code' => 'ROLLCLICK']);
        DB::listen(static function ($query): void {
            if (str_contains(strtolower($query->sql), 'insert into `business_activity`')) {
                throw new RuntimeException('Activity write failed');
            }
        });

        $this->get('/download?ref=ROLLCLICK')->assertServerError();

        $this->assertSame(0, (int) DB::table('users')->where('id', $referrer)->value('referral_points'));
        $this->assertSame(0, DB::table('referral_clicks')->where('referrer_id', $referrer)->count());
        $this->assertSame(0, DB::table('business_activity')->where('event', 'referral.clicked')->count());
    }

    public function test_join_and_point_roll_back_together_when_point_history_fails(): void
    {
        $referrer = $this->user('institute', 'Referrer');
        $referred = $this->user('learner', 'Referred');
        DB::table('users')->where('id', $referrer)->update(['referral_code' => 'ROLLBACK01']);
        DB::listen(static function ($query): void {
            if (str_contains(strtolower($query->sql), 'insert into `business_activity`')
                && in_array('referral.recorded', $query->bindings, true)) {
                throw new RuntimeException('Activity write failed');
            }
        });

        $this->postJson('/referrals/redeem', ['code' => 'ROLLBACK01'], $this->bearerFor($referred))
            ->assertServerError();

        $this->assertSame(0, (int) DB::table('users')->where('id', $referrer)->value('referral_points'));
        $this->assertSame(0, (int) DB::table('users')->where('id', $referred)->value('referral_points'));
        $this->assertSame(0, DB::table('referrals')->where('referred_id', $referred)->count());
        $this->assertSame(0, DB::table('business_activity')->where('event', 'referral.joined')->count());
    }

    public function test_both_point_awards_roll_back_when_redeemer_history_fails(): void
    {
        $referrer = $this->user('teacher', 'Referrer');
        $referred = $this->user('learner', 'Referred');
        DB::table('users')->where('id', $referrer)->update(['referral_code' => 'ROLLBACK02']);
        DB::listen(static function ($query): void {
            if (str_contains(strtolower($query->sql), 'insert into `business_activity`')
                && in_array('referral.redeemer_credited', $query->bindings, true)) {
                throw new RuntimeException('Redeemer activity write failed');
            }
        });

        $this->postJson('/referrals/redeem', ['code' => 'ROLLBACK02'], $this->bearerFor($referred))
            ->assertServerError();

        $this->assertSame(0, (int) DB::table('users')->where('id', $referrer)->value('referral_points'));
        $this->assertSame(0, (int) DB::table('users')->where('id', $referred)->value('referral_points'));
        $this->assertSame(0, DB::table('referrals')->where('referred_id', $referred)->count());
        $this->assertSame(0, DB::table('business_activity')->whereIn('event', [
            'referral.joined', 'referral.recorded', 'referral.redeemer_credited',
        ])->count());
    }

    public function test_provider_searches_rank_by_points_and_return_rating_fields(): void
    {
        $teacher = $this->provider('teacher', 'Low score teacher', 1, 4.9, 12);
        $institute = $this->provider('institute', 'High score institute', 5, 4.2, 7);
        $domain = DB::table('subject_domains')->insertGetId(['name' => 'Referral ranking']);
        $subject = DB::table('subjects')->insertGetId(['domain_id' => $domain, 'name' => 'Referral subject']);
        foreach ([['teacher', $teacher], ['institute', $institute]] as [$type, $userId]) {
            DB::table('profile_subjects')->insert([
                'profile_type' => $type, 'profile_id' => $userId, 'subject_id' => $subject,
            ]);
        }

        $checks = [
            '/search?lat=10&lng=20' => 'results',
            '/search/dashboard?lat=10&lng=20' => 'results',
            '/search/other-city?city=ReferralCity' => 'results',
            '/search/by-subject/'.$subject.'?tier=nearby&lat=10&lng=20' => 'results',
            '/search/by-subject/'.$subject.'?tier=city&city=ReferralCity' => 'results',
        ];
        foreach ($checks as $url => $resultsKey) {
            $this->getJson($url)->assertOk()
                ->assertJsonPath($resultsKey.'.0.user_id', $institute)
                ->assertJsonPath($resultsKey.'.0.referral_points', 5)
                ->assertJsonPath($resultsKey.'.0.rating_avg', 4.2)
                ->assertJsonPath($resultsKey.'.0.review_rating_avg', 4.2)
                ->assertJsonPath($resultsKey.'.0.rating_count', 7)
                ->assertJsonPath($resultsKey.'.0.review_rating_count', 7);
        }
        $this->assertNotSame($teacher, $institute);
    }

    public function test_city_feed_ranks_learner_teacher_and_institute_authors_by_points_with_ratings(): void
    {
        $learner = $this->user('learner', 'Learner', 0);
        $teacher = $this->provider('teacher', 'Teacher', 2, 4.8, 3);
        $institute = $this->provider('institute', 'Institute', 4, 4.5, 9);
        foreach ([[$learner, 'request'], [$teacher, 'offer'], [$institute, 'offer']] as [$authorId, $postType]) {
            DB::table('posts')->insert([
                'id' => NumericTestIds::next(), 'author_id' => $authorId, 'post_type' => $postType,
                'city' => 'ReferralCity', 'body' => 'Feed post by '.$authorId, 'created_at' => now(),
            ]);
        }

        $first = $this->getJson('/feed?city=ReferralCity&perPage=1')->assertOk()
            ->assertJsonPath('posts.0.author_id', $institute)
            ->assertJsonPath('posts.0.author_type', 'institute')
            ->assertJsonPath('posts.0.referral_points', 4)
            ->assertJsonPath('posts.0.rating_avg', 4.5)
            ->assertJsonPath('posts.0.review_rating_avg', 4.5)
            ->assertJsonPath('posts.0.rating_count', 9)
            ->assertJsonPath('posts.0.review_rating_count', 9);
        $cursor = urlencode($first->json('pagination.nextCursor'));

        $second = $this->getJson('/feed?city=ReferralCity&perPage=1&cursor='.$cursor)->assertOk()
            ->assertJsonPath('posts.0.author_id', $teacher)
            ->assertJsonPath('posts.0.referral_points', 2)
            ->assertJsonPath('posts.0.rating_avg', 4.8)
            ->assertJsonPath('posts.0.review_rating_avg', 4.8);
        $cursor = urlencode($second->json('pagination.nextCursor'));

        $this->getJson('/feed?city=ReferralCity&perPage=1&cursor='.$cursor)->assertOk()
            ->assertJsonPath('posts.0.author_id', $learner)
            ->assertJsonPath('posts.0.author_type', 'learner')
            ->assertJsonPath('posts.0.referral_points', 0)
            ->assertJsonPath('posts.0.rating_avg', null)
            ->assertJsonPath('posts.0.review_rating_avg', null)
            ->assertJsonPath('posts.0.rating_count', 0)
            ->assertJsonPath('posts.0.review_rating_count', 0);
    }

    public function test_referral_points_and_review_ratings_are_separate_across_responses(): void
    {
        $id = $this->provider('teacher', '100 point teacher', 100, 4.2, 7);
        DB::table('teacher_profiles')->where('user_id', $id)->update(['is_profile_complete' => true]);
        foreach (['/search?lat=10&lng=20', '/search/dashboard?lat=10&lng=20',
            '/search/filter?mode=nearby&lat=10&lng=20', '/search/other-city?city=ReferralCity'] as $url) {
            $this->getJson($url)->assertOk()->assertJsonPath('results.0.rating_avg', 4.2)
                ->assertJsonPath('results.0.referral_points', 100)
                ->assertJsonPath('results.0.review_rating_avg', 4.2)->assertJsonPath('results.0.rating_count', 7)
                ->assertJsonPath('results.0.review_rating_count', 7);
        }
        $this->getJson('/profiles/teacher/'.$id)->assertOk()->assertJsonPath('profile.rating_avg', 4.2)
            ->assertJsonPath('profile.referral_points', 100)->assertJsonPath('profile.review_rating_avg', 4.2);
        $headers = $this->bearerFor($id);
        $this->getJson('/profiles/me', $headers)->assertOk()->assertJsonPath('profile.rating_avg', 4.2)
            ->assertJsonPath('user.rating_avg', null)->assertJsonPath('profile.review_rating_avg', 4.2);
        $this->getJson('/auth/session', $headers)->assertOk()->assertJsonPath('user.rating_avg', null)
            ->assertJsonPath('profile.rating_avg', 4.2)->assertJsonPath('profile.review_rating_avg', 4.2);
        $learner = $this->user('learner', '100 point learner', 100);
        $this->getJson('/student/profile', $this->bearerFor($learner))->assertOk()->assertJsonPath('profile.rating_avg', null)->assertJsonPath('profile.rating_count', 0)
            ->assertJsonPath('profile.review_rating_count', 0);
        DB::table('posts')->insert(['id' => NumericTestIds::next(), 'author_id' => $id, 'post_type' => 'offer',
            'city' => 'Rating100City', 'body' => 'Point rating example', 'created_at' => now()]);
        $this->getJson('/feed?city=Rating100City')->assertOk()->assertJsonPath('posts.0.rating_avg', 4.2);
        $this->assertDatabaseHas('teacher_profiles', ['user_id' => $id, 'rating_avg' => 4.2, 'rating_count' => 7]);
    }

    public function test_new_users_start_with_100_points(): void
    {
        $id = DB::table('users')->insertGetId(['account_type' => 'learner', 'full_name' => 'New registration']);
        $this->assertSame(100, (int) DB::table('users')->where('id', $id)->value('referral_points'));
    }

    public function test_existing_bonus_preserves_points_is_idempotent_and_rolls_back_with_history(): void
    {
        $id = $this->user('teacher', 'Existing registration', 7);
        $service = app(RegistrationPoints::class);
        DB::beginTransaction();
        $service->awardExisting($id);
        DB::rollBack();
        $this->assertSame(7, (int) DB::table('users')->where('id', $id)->value('referral_points'));
        $this->assertSame(0, DB::table('business_activity')->where('event', 'user.registration_points_granted')->where('entity_id', $id)->count());
        $service->awardExisting($id);
        $service->awardExisting($id);
        $this->assertSame(107, (int) DB::table('users')->where('id', $id)->value('referral_points'));
        $this->assertSame(1, DB::table('business_activity')->where('event', 'user.registration_points_granted')->where('entity_id', $id)->count());
    }

    private function user(string $type, string $name, int $points = 0): int
    {
        $id = NumericTestIds::next();
        DB::table('users')->insert([
            'id' => $id, 'account_type' => $type, 'full_name' => $name,
            'phone_verified_at' => now(), 'profile_completed_at' => now(), 'referral_points' => $points,
        ]);

        return $id;
    }

    private function provider(string $type, string $name, int $points, float $rating, int $ratingCount): int
    {
        $userId = $this->user($type, $name, $points);
        DB::table($type.'_profiles')->insert([
            'id' => NumericTestIds::next(), 'user_id' => $userId, 'lesson_modes' => '["online"]',
            'latitude' => 10, 'longitude' => 20, 'city' => 'ReferralCity', 'state' => 'ReferralState',
            'country' => 'ReferralCountry',
            ($type === 'teacher' ? 'rate_per_hour' : 'starting_rate') => 500,
            'rating_avg' => $rating, 'rating_count' => $ratingCount,
        ]);

        return $userId;
    }

    /** @return array{Authorization: string} */
    private function bearerFor(int $userId): array
    {
        $token = 'referral-test-'.$userId;
        $this->authenticatedUsers[$token] = DB::table('users')->where('id', $userId)->first();

        return ['Authorization' => 'Bearer '.$token];
    }
}
