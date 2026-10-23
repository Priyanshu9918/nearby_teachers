<?php

namespace Tests\Feature;

use App\Broadcasting\SocketIoBroadcaster;
use App\Events\ChatMessageSent;
use App\Jobs\SendNotificationEmail;
use App\Services\AuthenticationService;
use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\Support\NumericTestIds;
use Tests\TestCase;

class ApiPerformanceTest extends TestCase
{
    private int $viewerId;

    private bool $transactionStarted = false;

    protected function setUp(): void
    {
        parent::setUp();
        $database = getenv('ONBOARDING_TEST_DATABASE');
        if (! $database) {
            $this->markTestSkipped('Set ONBOARDING_TEST_DATABASE to an isolated MySQL database ending in _test.');
        }
        $this->assertStringEndsWith('_test', $database);
        config(['database.connections.mysql.url' => null, 'database.connections.mysql.database' => $database]);
        DB::purge('mysql');
        Artisan::call('migrate', ['--force' => true]);
        DB::beginTransaction();
        $this->transactionStarted = true;
        $this->viewerId = $this->user();
        $authentication = \Mockery::mock(AuthenticationService::class);
        $authentication->shouldReceive('authenticate')->with('performance-token')
            ->andReturnUsing(fn () => (new AuthenticationService)->publicUser(
                DB::table('users')->where('id', $this->viewerId)->first()
            ));
        $this->app->instance(AuthenticationService::class, $authentication);
        $this->withHeader('Authorization', 'Bearer performance-token');
        Event::fake([ChatMessageSent::class]);
        Queue::fake();
    }

    protected function tearDown(): void
    {
        if ($this->transactionStarted) {
            DB::disableQueryLog();
            DB::rollBack();
        }
        parent::tearDown();
    }

    public function test_message_send_checks_membership_without_reading_history(): void
    {
        $conversation = $this->conversation($this->viewerId, $this->user('teacher'));
        $rows = [];
        for ($i = 0; $i < 1000; $i++) {
            $rows[] = [
                'id' => NumericTestIds::next(), 'conversation_id' => $conversation,
                'sender_id' => $this->viewerId, 'body' => 'Old message '.$i,
                'created_at' => now()->subDay(),
            ];
        }
        DB::table('messages')->insert($rows);
        DB::enableQueryLog();
        $this->postJson('/messages/'.$conversation, ['body' => 'New message'])
            ->assertOk()->assertJsonPath('message.body', 'New message');
        $messageReads = collect(DB::getQueryLog())->filter(fn ($entry) => str_contains($entry['query'], 'from `messages`'));
        $this->assertCount(1, $messageReads);
        $this->assertStringContainsString('where `id` = ?', $messageReads->first()['query']);
        Event::assertDispatched(ChatMessageSent::class);
        $this->viewerId = $this->user();
        $this->postJson('/messages/'.$conversation, ['body' => 'Forbidden'])->assertNotFound();
        $this->getJson('/messages/'.$conversation)->assertNotFound();
    }

    public function test_message_pages_are_bounded_and_ties_do_not_skip_records(): void
    {
        $conversation = $this->conversation($this->viewerId, $this->user('teacher'));
        $ids = [];
        foreach (range(1, 5) as $i) {
            $id = NumericTestIds::next();
            $ids[] = $id;
            DB::table('messages')->insert([
                'id' => $id, 'conversation_id' => $conversation, 'sender_id' => $this->viewerId,
                'body' => 'Message '.$i, 'created_at' => '2026-01-01 00:00:00',
            ]);
        }
        $url = '/messages/'.$conversation.'?perPage=2';
        $found = [];
        do {
            $page = $this->getJson($url)->assertOk();
            $this->assertLessThanOrEqual(2, count($page->json('messages')));
            $pageIds = array_column($page->json('messages'), 'id');
            $sorted = $pageIds;
            sort($sorted);
            $this->assertSame($sorted, $pageIds); // Chronological within each newest-first page.
            array_push($found, ...$pageIds);
            $cursor = $page->json('pagination.nextCursor');
            $url = '/messages/'.$conversation.'?perPage=2&cursor='.urlencode($cursor ?? '');
        } while ($cursor !== null);
        sort($found);
        sort($ids);
        $this->assertSame($ids, $found);
        $this->getJson('/messages/'.$conversation.'?perPage=101')->assertUnprocessable();
        $this->getJson('/messages/'.$conversation.'?cursor=invalid')->assertUnprocessable();
        $first = $this->getJson('/messages/'.$conversation.'?perPage=2')->assertOk();
        $second = $this->getJson('/messages/'.$conversation.'?perPage=2&cursor='.urlencode($first->json('pagination.nextCursor')))
            ->assertOk();
        $back = $this->getJson('/messages/'.$conversation.'?perPage=2&cursor='.urlencode($second->json('pagination.previousCursor')))
            ->assertOk();
        $this->assertSame($first->json('messages'), $back->json('messages'));
    }

    public function test_inbox_uses_latest_owned_messages_and_supports_cursor_paging(): void
    {
        $owned = [];
        foreach (range(1, 3) as $i) {
            $conversation = $this->conversation($this->viewerId, $this->user('teacher'));
            $owned[] = $conversation;
            if ($i < 3) {
                foreach (range(1, 2) as $j) {
                    DB::table('messages')->insert([
                        'id' => NumericTestIds::next(), 'conversation_id' => $conversation,
                        'sender_id' => $this->viewerId, 'body' => 'Latest '.$j,
                        'created_at' => '2026-01-0'.$j.' 00:00:00',
                    ]);
                }
            }
        }
        $this->conversation($this->user(), $this->user('teacher'));
        $cursor = null;
        $found = [];
        do {
            $page = $this->getJson('/messages?perPage=1'.($cursor ? '&cursor='.urlencode($cursor) : ''))->assertOk();
            foreach ($page->json('conversations') as $row) {
                $found[] = $row['id'];
                if ($row['last_message'] !== null) {
                    $this->assertSame('Latest 2', $row['last_message']);
                }
            }
            $cursor = $page->json('pagination.nextCursor');
        } while ($cursor !== null);
        sort($found);
        sort($owned);
        $this->assertSame($owned, $found);
        $this->viewerId = DB::table('conversations')->where('id', $owned[0])->value('provider_id');
        $this->getJson('/messages')->assertOk()->assertJsonCount(1, 'conversations')
            ->assertJsonPath('conversations.0.id', $owned[0]);
    }

    public function test_bookings_comments_reviews_and_feed_have_cursor_pages(): void
    {
        $provider = $this->user('teacher');
        $post = NumericTestIds::next();
        DB::table('posts')->insert([
            'id' => $post, 'author_id' => $this->viewerId, 'post_type' => 'request',
            'city' => 'Delhi', 'body' => 'Post',
        ]);
        foreach (range(1, 3) as $i) {
            $booking = $this->booking($this->viewerId, $provider);
            DB::table('reviews')->insert([
                'id' => NumericTestIds::next(), 'booking_id' => $booking,
                'learner_id' => $this->viewerId, 'provider_id' => $provider, 'rating' => 5,
            ]);
            DB::table('post_comments')->insert([
                'id' => NumericTestIds::next(), 'post_id' => $post, 'user_id' => $this->viewerId, 'body' => 'Comment',
            ]);
        }
        foreach ([
            ['/bookings/mine', 'bookings'],
            ['/reviews/for/'.$provider, 'reviews'],
            ['/feed/'.$post.'/comments', 'comments'],
        ] as [$url, $key]) {
            $first = $this->getJson($url.'?perPage=2')->assertOk()->assertJsonCount(2, $key)
                ->assertJsonPath('pagination.hasMore', true);
            $second = $this->getJson($url.'?perPage=2&cursor='.urlencode($first->json('pagination.nextCursor')))
                ->assertOk()->assertJsonCount(1, $key)->assertJsonPath('pagination.hasMore', false);
            $this->assertCount(3, array_unique(array_merge(
                array_column($first->json($key), 'id'), array_column($second->json($key), 'id')
            )));
        }
        $this->getJson('/feed?city=Delhi&perPage=1')->assertOk()->assertJsonCount(1, 'posts');
        $this->viewerId = $provider;
        $this->getJson('/bookings/incoming?perPage=2')->assertOk()->assertJsonCount(2, 'bookings');
    }

    public function test_geo_search_preserves_exact_radius_dateline_and_pole_matches(): void
    {
        $domain = DB::table('subject_domains')->insertGetId(['name' => 'Geo test']);
        $subject = DB::table('subjects')->insertGetId(['domain_id' => $domain, 'name' => 'Geo subject']);
        foreach ([
            [0.0, 0.0, [[0.01, 0.0], [0.1, 0.0], [0.06, 0.06]], 1],
            [0.0, 180.0, [[0.0, 179.99], [0.0, -179.99], [0.0, -180.0]], 3],
            [89.99, 0.0, [[89.99, 180.0], [89.99, -90.0]], 2],
            [-89.99, 0.0, [[-89.99, 180.0], [-89.99, -90.0]], 2],
        ] as [$lat, $lng, $points, $expected]) {
            foreach ($points as [$pLat, $pLng]) {
                $this->provider($pLat, $pLng, $subject);
            }
            $query = http_build_query(['lat' => $lat, 'lng' => $lng, 'radiusKm' => 7]);
            $this->getJson('/search?'.$query)->assertOk()->assertJsonCount($expected, 'results');
            $this->getJson('/search/counts?'.$query)->assertOk()->assertJsonPath('teacher_count', $expected);
            $this->getJson('/search/by-subject/'.$subject.'?tier=nearby&'.$query)
                ->assertOk()->assertJsonCount($expected, 'results')->assertJsonPath('totalCount', $expected);
        }
        $this->getJson('/search?lat=0&lng=-180&radiusKm=7')->assertOk()->assertJsonCount(3, 'results');
        $this->getJson('/search?lat=0&lng=-179.99&radiusKm=7')->assertOk()->assertJsonCount(3, 'results');
    }

    public function test_search_bounds_also_apply_to_institutes_and_subject_counts(): void
    {
        $domain = DB::table('subject_domains')->insertGetId(['name' => 'Institute test']);
        $subject = DB::table('subjects')->insertGetId(['domain_id' => $domain, 'name' => 'Institute subject']);
        $this->provider(0.01, 0, $subject, 'institute');
        $this->provider(0.1, 0, $subject);
        $this->getJson('/search?lat=0&lng=0&radiusKm=7')->assertOk()
            ->assertJsonCount(1, 'results')->assertJsonPath('results.0.profile_type', 'institute');
        $this->getJson('/search/counts?lat=0&lng=0&radiusKm=7')->assertOk()
            ->assertJsonPath('teacher_count', 0)->assertJsonPath('institute_count', 1);
        $this->getJson('/search/by-subject/'.$subject.'?tier=nearby&lat=0&lng=0&radiusKm=7')
            ->assertOk()->assertJsonCount(1, 'results')->assertJsonPath('totalCount', 1);
    }

    public function test_nearby_keeps_global_top_fifty_and_filters_before_branch_limits(): void
    {
        $domain = DB::table('subject_domains')->insertGetId(['name' => 'Nearest shortlist test']);
        $subject = DB::table('subjects')->insertGetId(['domain_id' => $domain, 'name' => 'Requested subject']);
        $other = DB::table('subjects')->insertGetId(['domain_id' => $domain, 'name' => 'Other subject']);
        $expectedNearest = [];
        $expectedMatching = [];
        for ($i = 0; $i < 120; $i++) {
            $type = $i % 2 === 0 ? 'teacher' : 'institute';
            $matches = $i >= 80;
            $id = $this->provider(0.001 + $i * 0.0001, 0, $matches ? $subject : $other, $type);
            DB::table('users')->where('id', $id)->update(['full_name' => $matches ? 'Target teacher' : 'Other teacher']);
            DB::table($type === 'teacher' ? 'teacher_profiles' : 'institute_profiles')->where('user_id', $id)
                ->update([$type === 'teacher' ? 'rate_per_hour' : 'starting_rate' => $matches ? 0 : 100]);
            if ($i < 50) {
                $expectedNearest[] = $id;
            }
            if ($matches) {
                $expectedMatching[] = $id;
            }
        }
        $base = '/search?lat=0&lng=0&radiusKm=7';
        $nearest = $this->getJson($base)->assertOk()->assertJsonCount(50, 'results')->assertHeader('Server-Timing');
        $this->assertSame($expectedNearest, array_column($nearest->json('results'), 'user_id'));
        $matching = $this->getJson($base.'&subjectId='.$subject.'&q=Target&freeOnly=true')
            ->assertOk()->assertJsonCount(40, 'results');
        $this->assertSame($expectedMatching, array_column($matching->json('results'), 'user_id'));
        $this->getJson($base.'&subjectId='.$subject.'&q=Other')->assertOk()->assertJsonCount(0, 'results');
        $this->getJson($base.'&q=Other&freeOnly=true')->assertOk()->assertJsonCount(0, 'results');
    }

    public function test_notifications_are_queued_instead_of_sent_inside_booking_requests(): void
    {
        Mail::fake();
        $provider = $this->user('teacher', 'provider@example.com');
        $this->postJson('/bookings', [
            'providerId' => $provider, 'mode' => 'online', 'requestedTime' => '2027-01-01 12:00:00',
        ])->assertOk();
        Queue::assertPushed(SendNotificationEmail::class, fn ($job) => $job->to === 'provider@example.com'
            && $job->queue === 'notifications' && $job->afterCommit === true);
        Mail::assertNothingSent();
    }

    public function test_chat_broadcast_is_queued_without_blocking_message_response(): void
    {
        Event::except([ChatMessageSent::class]);
        $conversation = $this->conversation($this->viewerId, $this->user('teacher'));
        $this->postJson('/messages/'.$conversation, ['body' => 'Queue this broadcast'])
            ->assertOk()->assertJsonPath('message.body', 'Queue this broadcast');
        Queue::assertPushedOn('broadcasts', BroadcastEvent::class);
    }

    public function test_socketio_authorization_only_allows_verified_participants(): void
    {
        $learner = $this->viewerId;
        $conversation = $this->conversation($learner, $this->user('teacher'));
        $this->postJson('/chat/socket/authorize')->assertOk()->assertJsonPath('userId', $learner);
        $this->postJson('/chat/socket/authorize', ['conversationId' => $conversation])
            ->assertOk()->assertJsonPath('room', 'private-conversation.'.$conversation);
        $this->viewerId = $this->user();
        $this->postJson('/chat/socket/authorize', ['conversationId' => $conversation])->assertNotFound();
        DB::table('users')->where('id', $this->viewerId)->update(['phone_verified_at' => null]);
        $this->postJson('/chat/socket/authorize')->assertForbidden();
    }

    public function test_socketio_broadcast_posts_saved_message_and_both_recipients_to_bridge(): void
    {
        $provider = $this->user('teacher');
        $conversation = $this->conversation($this->viewerId, $provider);
        $message = [
            'id' => NumericTestIds::next(), 'conversation_id' => $conversation,
            'sender_id' => $this->viewerId, 'body' => 'Saved message', 'created_at' => now()->toDateTimeString(),
        ];
        DB::table('messages')->insert($message);
        $message = (array) DB::table('messages')->where('id', $message['id'])->first();
        $secret = str_repeat('socket-secret-', 5);
        Http::fake(['http://socket.test/internal/events' => Http::sequence()->push(['ok' => true])->push([], 503)]);
        config([
            'broadcasting.connections.socketio.url' => 'http://socket.test',
            'broadcasting.connections.socketio.secret' => $secret,
        ]);
        Broadcast::purge('socketio');
        Http::preventStrayRequests();
        $bridge = Broadcast::connection('socketio');
        $this->assertInstanceOf(SocketIoBroadcaster::class, $bridge);
        $bridge->broadcast(['private-conversation.'.$conversation], 'message.sent', ['message' => $message]);
        Http::assertSent(fn ($request) => $request->hasHeader('X-Socket-Secret', $secret)
            && $request['message'] === $message && $request['recipientIds'] === [$this->viewerId, $provider]);

        $this->expectException(RequestException::class);
        $bridge->broadcast(['private-conversation.'.$conversation], 'message.sent', ['message' => $message]);
    }

    private function user(string $type = 'learner', ?string $email = null): int
    {
        $id = NumericTestIds::next();
        DB::table('users')->insert([
            'id' => $id, 'account_type' => $type, 'full_name' => 'Test user', 'email' => $email,
            'phone_verified_at' => now(), 'profile_completed_at' => now(),
            'safety_disclaimer_acknowledged_at' => now(),
        ]);

        return $id;
    }

    private function conversation(int $learner, int $provider): int
    {
        $id = NumericTestIds::next();
        DB::table('conversations')->insert(['id' => $id, 'learner_id' => $learner, 'provider_id' => $provider]);

        return $id;
    }

    private function booking(int $learner, int $provider): int
    {
        $id = NumericTestIds::next();
        DB::table('bookings')->insert([
            'id' => $id, 'learner_id' => $learner, 'provider_id' => $provider,
            'mode' => 'online', 'requested_time' => now(),
        ]);

        return $id;
    }

    private function provider(float $lat, float $lng, int $subject, string $type = 'teacher'): int
    {
        $id = $this->user($type);
        DB::table($type === 'teacher' ? 'teacher_profiles' : 'institute_profiles')->insert([
            'id' => NumericTestIds::next(), 'user_id' => $id, 'lesson_modes' => '[]',
            'latitude' => $lat, 'longitude' => $lng,
        ]);
        DB::table('profile_subjects')->insert(['profile_type' => $type, 'profile_id' => $id, 'subject_id' => $subject]);

        return $id;
    }
}
