<?php

namespace Tests\Feature;

use App\Services\AuthenticationService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Cookie\Middleware\EncryptCookies;
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

    public function test_successful_redemption_records_join_without_awarding_a_second_point(): void
    {
        $referrer = $this->user('teacher', 'Referrer');
        $referred = $this->user('learner', 'Referred');
        DB::table('users')->where('id', $referrer)->update(['referral_code' => 'REFERRER01']);

        $this->postJson('/referrals/redeem', ['code' => 'REFERRER01'], $this->bearerFor($referred))
            ->assertOk()->assertJsonPath('referrerReferralPoints', 0);
        $this->assertSame(0, (int) DB::table('users')->where('id', $referrer)->value('referral_points'));
        $this->assertSame(1, DB::table('referrals')->where('referrer_id', $referrer)->count());

        $activity = DB::table('business_activity')->where('event', 'referral.joined')->first();
        $this->assertNotNull($activity);
        $this->assertSame((string) $referred, (string) $activity->actor_user_id);
        $this->assertSame((string) $referrer, (string) $activity->related_user_id);
        $this->assertSame(['source' => 'referral_code'], json_decode($activity->after_data, true, 512, JSON_THROW_ON_ERROR));

        $this->postJson('/referrals/redeem', ['code' => 'REFERRER01'], $this->bearerFor($referred))
            ->assertStatus(409);
        $this->assertSame(0, (int) DB::table('users')->where('id', $referrer)->value('referral_points'));
        $this->assertSame(1, DB::table('business_activity')->where('event', 'referral.joined')->count());

        $this->getJson('/referrals/mine', $this->bearerFor($referrer))
            ->assertOk()->assertJsonPath('referralPoints', 0)->assertJsonPath('referredCount', 1)
            ->assertJsonPath('referred.0.full_name', 'Referred')
            ->assertJsonPath('referred.0.account_type', 'learner')
            ->assertJsonPath('referred.0.avatar_url', null);
    }

    public function test_valid_link_open_awards_once_per_browser_and_referrer(): void
    {
        $referrer = $this->user('teacher', 'Referrer');
        DB::table('users')->where('id', $referrer)->update(['referral_code' => 'CLICK123']);
        config(['services.nearby_teacher.android_play_store_url' => 'https://play.google.com/store/apps/details?id=example']);
        $this->withoutMiddleware(EncryptCookies::class);

        $firstOpen = $this->withHeader('User-Agent', 'Android')
            ->get('/download?ref=CLICK123')->assertRedirect();
        $cookie = collect($firstOpen->headers->getCookies())
            ->first(static fn ($cookie): bool => $cookie->getName() === 'nt_referral_visitor');
        $this->assertNotNull($cookie);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $cookie->getValue());
        $this->assertSame(1, (int) DB::table('users')->where('id', $referrer)->value('referral_points'));
        $this->assertSame(1, DB::table('referral_clicks')->where('referrer_id', $referrer)->count());
        $this->assertSame(1, DB::table('business_activity')->where('event', 'referral.clicked')->count());

        $this->withCookie('nt_referral_visitor', $cookie->getValue())
            ->get('/download?ref=CLICK123')->assertRedirect();
        $this->assertSame(1, (int) DB::table('users')->where('id', $referrer)->value('referral_points'));
        $this->assertSame(1, DB::table('referral_clicks')->where('referrer_id', $referrer)->count());
        $this->assertSame(1, DB::table('business_activity')->where('event', 'referral.clicked')->count());

        $this->withCookie('nt_referral_visitor', str_repeat('b', 64))
            ->get('/download?ref=CLICK123')->assertRedirect();
        $this->assertSame(2, (int) DB::table('users')->where('id', $referrer)->value('referral_points'));
        $this->assertSame(2, DB::table('referral_clicks')->where('referrer_id', $referrer)->count());
        $this->assertSame(2, DB::table('business_activity')->where('event', 'referral.clicked')->count());
    }

    public function test_click_point_and_history_roll_back_together(): void
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

    public function test_join_row_and_activity_roll_back_together(): void
    {
        $referrer = $this->user('institute', 'Referrer');
        $referred = $this->user('learner', 'Referred');
        DB::table('users')->where('id', $referrer)->update(['referral_code' => 'ROLLBACK01']);
        DB::listen(static function ($query): void {
            if (str_contains(strtolower($query->sql), 'insert into `business_activity`')) {
                throw new RuntimeException('Activity write failed');
            }
        });

        $this->postJson('/referrals/redeem', ['code' => 'ROLLBACK01'], $this->bearerFor($referred))
            ->assertServerError();

        $this->assertSame(0, (int) DB::table('users')->where('id', $referrer)->value('referral_points'));
        $this->assertSame(0, DB::table('referrals')->where('referred_id', $referred)->count());
        $this->assertSame(0, DB::table('business_activity')->where('event', 'referral.joined')->count());
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
                ->assertJsonPath($resultsKey.'.0.rating_count', 7);
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
            ->assertJsonPath('posts.0.rating_count', 9);
        $cursor = urlencode($first->json('pagination.nextCursor'));

        $second = $this->getJson('/feed?city=ReferralCity&perPage=1&cursor='.$cursor)->assertOk()
            ->assertJsonPath('posts.0.author_id', $teacher)
            ->assertJsonPath('posts.0.referral_points', 2)
            ->assertJsonPath('posts.0.rating_avg', 4.8);
        $cursor = urlencode($second->json('pagination.nextCursor'));

        $this->getJson('/feed?city=ReferralCity&perPage=1&cursor='.$cursor)->assertOk()
            ->assertJsonPath('posts.0.author_id', $learner)
            ->assertJsonPath('posts.0.author_type', 'learner')
            ->assertJsonPath('posts.0.referral_points', 0)
            ->assertJsonPath('posts.0.rating_avg', null)
            ->assertJsonPath('posts.0.rating_count', 0);
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
