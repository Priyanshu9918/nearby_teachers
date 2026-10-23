<?php

namespace Tests\Feature;

use App\Events\ChatMessageSent;
use App\Services\AuthenticationService;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * A basic test example.
     */
    public function test_the_application_returns_a_successful_response(): void
    {
        $response = $this->get('/');

        $response->assertOk()
            ->assertSee(route('website.directory'))
            ->assertSee(route('website.location-lookup'));
    }

    public function test_public_website_and_download_page_are_served_by_laravel(): void
    {
        $this->get('/download?lang=fr&ref=friend-code')
            ->assertOk()
            ->assertCookie('nt_lang', 'fr')
            ->assertSee('Français')
            ->assertSee('friend-code');

        $this->get('/robots.txt')
            ->assertOk()
            ->assertSee('Sitemap: '.config('app.url').'/sitemap.xml')
            ->assertSee('Allow: /location-lookup');
    }

    public function test_location_lookup_page_is_public_and_explains_coordinate_lookup(): void
    {
        $this->get('/location-lookup')
            ->assertOk()
            ->assertSee('Coordinate & locality lookup')
            ->assertSee('Use my current location')
            ->assertSee('All address components')
            ->assertSee('OpenStreetMap contributors')
            ->assertSee('name="_token"', false);
    }

    public function test_public_location_lookup_returns_reverse_geocoded_address_details(): void
    {
        $ip = '192.0.2.31';
        RateLimiter::clear('location-lookup:'.sha1($ip));
        Http::fake([
            'https://nominatim.openstreetmap.org/reverse*' => Http::response([
                'place_id' => 123,
                'lat' => '28.6139000',
                'lon' => '77.2090000',
                'display_name' => 'Connaught Place, New Delhi, India',
                'address' => [
                    'neighbourhood' => 'Connaught Place',
                    'city' => 'New Delhi',
                    'state' => 'Delhi',
                    'postcode' => '110001',
                    'country' => 'India',
                    'country_code' => 'in',
                ],
                'category' => 'place',
                'type' => 'neighbourhood',
            ], 200),
        ]);

        $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->postJson('/location-lookup/reverse', [
                'latitude' => '28.6139',
                'longitude' => '77.2090',
            ])
            ->assertOk()
            ->assertJsonPath('location.address.neighbourhood', 'Connaught Place')
            ->assertJsonPath('location.address.city', 'New Delhi')
            ->assertJsonPath('location.address.postcode', '110001')
            ->assertJsonPath('location.display_name', 'Connaught Place, New Delhi, India');

        $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->postJson('/location-lookup/reverse', [
                'latitude' => '28.6139',
                'longitude' => '77.2090',
            ])
            ->assertTooManyRequests()
            ->assertJsonPath('retryAfterSeconds', 1);

        Http::assertSent(function ($request): bool {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return str_starts_with($request->url(), 'https://nominatim.openstreetmap.org/reverse?')
                && ($query['lat'] ?? null) === '28.6139'
                && ($query['lon'] ?? null) === '77.2090'
                && ($query['format'] ?? null) === 'jsonv2'
                && $request->hasHeader('User-Agent');
        });
    }

    public function test_location_lookup_rejects_out_of_range_coordinates(): void
    {
        $this->withHeader('Accept', 'application/json')
            ->post('/location-lookup/reverse', [
                'latitude' => '91',
                'longitude' => '181',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['latitude', 'longitude']);
    }

    public function test_android_download_carries_the_deep_link_and_referral(): void
    {
        config(['services.nearby_teacher.android_play_store_url' => 'https://play.google.com/store/apps/details?id=example']);

        $this->withHeader('User-Agent', 'Mozilla/5.0 (Linux; Android 14)')
            ->get('/download?type=teacher&slug=teacher-name&ref=friend-code')
            ->assertRedirect('https://play.google.com/store/apps/details?id=example&referrer=deep_link_path%3D%2Fteacher%2Fteacher-name%26ref%3Dfriend-code');
    }

    public function test_admin_portal_requires_a_session(): void
    {
        $this->get('/admin/subjects')
            ->assertRedirect('/admin/login');
    }

    public function test_admin_login_page_is_available_without_credentials(): void
    {
        config(['admin.email' => '', 'admin.password_hash' => '']);

        $this->get('/admin/login')
            ->assertOk()
            ->assertSee('Admin access is disabled');
    }

    public function test_admin_workspace_renders_sidebar_and_field_validation_messages(): void
    {
        $this->withViewErrors([
            'domain_id' => 'Choose a valid subject domain.',
            'name' => 'The subject name is required.',
        ])->view('admin.index', [
            'section' => 'subjects',
            'title' => 'Subjects',
            'records' => collect(),
            'domains' => collect(),
            'editing' => null,
        ])->assertSee('aria-label="Admin sidebar"', false)
            ->assertSee('aria-current="page"', false)
            ->assertSee('Choose a valid subject domain.')
            ->assertSee('The subject name is required.')
            ->assertSee('aria-invalid="true"', false);
    }

    public function test_developer_portal_requires_its_separate_session(): void
    {
        config(['developer.email' => '', 'developer.password_hash' => '']);

        $this->get('/developer')
            ->assertRedirect('/developer/login');

        $this->get('/developer/login')
            ->assertOk()
            ->assertSee('php artisan developer:setup-login')
            ->assertSee('Go to admin sign in');

        $this->withSession(['admin_authenticated' => true])
            ->get('/developer')
            ->assertRedirect('/developer/login');

        $this->withSession(['admin_authenticated' => false, 'developer_authenticated' => true])
            ->get('/admin/subjects')
            ->assertRedirect('/admin/login');
    }

    public function test_developer_can_sign_in_with_separate_credentials(): void
    {
        config([
            'developer.email' => 'developer@example.test',
            'developer.password_hash' => password_hash('a-long-developer-password', PASSWORD_BCRYPT),
        ]);

        $this->post('/developer/login', [
            'email' => 'developer@example.test',
            'password' => 'a-long-developer-password',
        ])->assertRedirect('/developer');

        $this->assertTrue(session('developer_authenticated'));
    }

    public function test_developer_portal_lists_registered_api_routes_and_masks_secrets(): void
    {
        $directory = sys_get_temp_dir().'/nearby-teacher-developer-view-'.Str::uuid();
        $this->assertTrue(mkdir($directory, 0700));
        $envFile = $directory.'/.env';
        $this->assertNotFalse(file_put_contents($envFile, "APP_NAME='Developer test app'\nDB_HOST='127.0.0.1'\nDB_PASSWORD='test-database-secret-marker'\nJWT_SECRET='test-jwt-secret-marker'\nFIREBASE_SERVICE_ACCOUNT_JSON='{\"private_key\":\"test-firebase-secret-marker\"}'\n"));
        $originalEnvironmentPath = app()->environmentPath();
        $originalEnvironmentFile = app()->environmentFile();

        try {
            app()->useEnvironmentPath($directory)->loadEnvironmentFrom('.env');

            $this->withSession(['developer_authenticated' => true])
                ->get('/developer')
                ->assertOk()
                ->assertSee('API catalog')
                ->assertSee('registered routes')
                ->assertSee('auth/google')
                ->assertSee('location-lookup')
                ->assertSee('latitude')
                ->assertSee('longitude')
                ->assertSee('health')
                ->assertSee('Common')
                ->assertSee('BroadcastController@authenticate')
                ->assertSee('moderation')
                ->assertSee('Admin access required')
                ->assertSee('Sign-in required')
                ->assertSee('Developer test app')
                ->assertSee('SESSION_SECURE_COOKIE')
                ->assertDontSee('DEVELOPER_PASSWORD_HASH')
                ->assertSee('Secret is set · value hidden')
                ->assertDontSee('test-database-secret-marker')
                ->assertDontSee('test-jwt-secret-marker')
                ->assertDontSee('test-firebase-secret-marker');
        } finally {
            app()->useEnvironmentPath($originalEnvironmentPath)->loadEnvironmentFrom($originalEnvironmentFile);
            unlink($envFile);
            rmdir($directory);
        }
    }

    public function test_developer_api_workbench_shows_ordered_auth_flow_and_request_examples(): void
    {
        $response = $this->withSession(['developer_authenticated' => true])
            ->get('/developer')
            ->assertOk()
            ->assertSee('Google → OTP flow')
            ->assertSee('Google sign-in → phone OTP → verify OTP')
            ->assertSee('Step 1')
            ->assertSee('PASTE_FIREBASE_GOOGLE_ID_TOKEN')
            ->assertSee('+919876543210')
            ->assertSee('00000000-0000-4000-8000-000000000001')
            ->assertSee('data-category="Auth"', false)
            ->assertSee('Use response token as bearer')
            ->assertSee('Join conversation')
            ->assertSee('Connection settings')
            ->assertSee('Forgot password and email verification')
            ->assertSee('POST /auth/reset-password')
            ->assertSee('2. Verify token')
            ->assertDontSee('Configure Reverb')
            ->assertSee('lat=28.6139');

        $html = $response->getContent();
        $googleStep = strpos($html, 'POST /auth/google');
        $otpStep = strpos($html, 'POST /auth/otp/request');
        $phoneLinkStep = strpos($html, 'POST /auth/otp/verify');
        $this->assertNotFalse($googleStep);
        $this->assertNotFalse($otpStep);
        $this->assertNotFalse($phoneLinkStep);
        $this->assertLessThan($otpStep, $googleStep);
        $this->assertLessThan($phoneLinkStep, $otpStep);
    }

    public function test_developer_workbench_keeps_application_subdirectory_in_api_base_url(): void
    {
        $this->withSession(['developer_authenticated' => true])
            ->withServerVariables([
                'SCRIPT_NAME' => '/nearby_teachers/public/index.php',
                'PHP_SELF' => '/nearby_teachers/public/index.php',
                'SCRIPT_FILENAME' => public_path('index.php'),
            ])
            ->get('http://157.245.125.79/nearby_teachers/public/developer')
            ->assertOk()
            ->assertViewHas('baseUrl', 'http://157.245.125.79/nearby_teachers/public')
            ->assertSee('API base URL · same-origin only')
            ->assertSee('http://157.245.125.79/nearby_teachers/public');
    }

    public function test_developer_configuration_update_is_allowlisted_and_preserves_blank_secrets(): void
    {
        $directory = sys_get_temp_dir().'/nearby-teacher-developer-config-'.Str::uuid();
        $this->assertTrue(mkdir($directory, 0700));
        $envFile = $directory.'/.env';
        $this->assertNotFalse(file_put_contents($envFile, "APP_NAME='Before'\nAPP_KEY='base64:".base64_encode(str_repeat('k', 32))."'\nDB_PASSWORD='keep-this-secret'\nSMTP_PASS='remove-this-secret'\n"));
        $originalEnvironmentPath = app()->environmentPath();
        $originalEnvironmentFile = app()->environmentFile();

        try {
            app()->useEnvironmentPath($directory)->loadEnvironmentFrom('.env');

            $this->withSession(['developer_authenticated' => true])
                ->post('/developer/configuration', [
                    'settings' => [
                        'APP_NAME' => 'After',
                        'DB_PASSWORD' => '',
                        'SMTP_PASS' => '',
                    ],
                    'clear' => ['SMTP_PASS'],
                    'confirm_changes' => '1',
                ])
                ->assertRedirect('/developer?tab=configuration')
                ->assertSessionHasNoErrors();

            $contents = file_get_contents($envFile);
            $this->assertIsString($contents);
            $this->assertStringContainsString("APP_NAME='After'", $contents);
            $this->assertStringContainsString("DB_PASSWORD='keep-this-secret'", $contents);
            $this->assertStringContainsString("SMTP_PASS=''", $contents);
            $this->assertStringNotContainsString('remove-this-secret', $contents);

            $this->withSession(['developer_authenticated' => true])
                ->post('/developer/configuration', [
                    'settings' => ['UNRECOGNIZED_SETTING' => 'value'],
                    'confirm_changes' => '1',
                ])
                ->assertRedirect('/developer?tab=configuration')
                ->assertSessionHasErrors('settings');

            $this->withSession(['developer_authenticated' => true])
                ->post('/developer/configuration', [
                    'settings' => ['APP_KEY' => ''],
                    'clear' => ['APP_KEY'],
                    'confirm_changes' => '1',
                ])
                ->assertRedirect('/developer?tab=configuration')
                ->assertSessionHasErrors('settings.APP_KEY');
        } finally {
            app()->useEnvironmentPath($originalEnvironmentPath)->loadEnvironmentFrom($originalEnvironmentFile);
            unlink($envFile);
            rmdir($directory);
        }
    }

    public function test_developer_setup_command_stores_only_a_password_hash_in_its_env_file(): void
    {
        $directory = sys_get_temp_dir().'/nearby-teacher-developer-'.Str::uuid();
        $this->assertTrue(mkdir($directory, 0700));
        $envFile = $directory.'/.env';
        $this->assertNotFalse(file_put_contents($envFile, "DEVELOPER_EMAIL=\nDEVELOPER_PASSWORD_HASH=\n"));
        $originalEnvironmentPath = app()->environmentPath();
        $originalEnvironmentFile = app()->environmentFile();

        try {
            app()->useEnvironmentPath($directory)->loadEnvironmentFrom('.env');

            $this->artisan('developer:setup-login')
                ->expectsQuestion('Developer email address', 'developer@example.test')
                ->expectsQuestion('Developer password (minimum 16 characters)', 'a-long-developer-password')
                ->assertExitCode(0);

            $contents = file_get_contents($envFile);
            $this->assertIsString($contents);
            $this->assertStringContainsString("DEVELOPER_EMAIL='developer@example.test'", $contents);
            $this->assertStringNotContainsString('a-long-developer-password', $contents);
            $this->assertSame(1, preg_match("/^DEVELOPER_PASSWORD_HASH='([^']+)'$/m", $contents, $matches));
            $this->assertTrue(password_verify('a-long-developer-password', $matches[1]));
        } finally {
            app()->useEnvironmentPath($originalEnvironmentPath)->loadEnvironmentFrom($originalEnvironmentFile);
            unlink($envFile);
            rmdir($directory);
        }
    }

    public function test_admin_can_sign_in_with_configured_credentials(): void
    {
        config([
            'admin.email' => 'admin@example.test',
            'admin.password_hash' => password_hash('correct-horse-battery', PASSWORD_BCRYPT),
        ]);

        $this->post('/admin/login', [
            'email' => 'admin@example.test',
            'password' => 'correct-horse-battery',
        ])->assertRedirect('/admin');

        $this->assertTrue(session('admin_authenticated'));
    }

    public function test_admin_setup_command_stores_only_a_password_hash_in_its_env_file(): void
    {
        $directory = sys_get_temp_dir().'/nearby-teacher-admin-'.Str::uuid();
        $this->assertTrue(mkdir($directory, 0700));
        $envFile = $directory.'/.env';
        $this->assertNotFalse(file_put_contents($envFile, "ADMIN_EMAIL=\nADMIN_PASSWORD_HASH=\n"));
        $originalEnvironmentPath = app()->environmentPath();
        $originalEnvironmentFile = app()->environmentFile();

        try {
            app()->useEnvironmentPath($directory)->loadEnvironmentFrom('.env');

            $this->artisan('admin:setup-login')
                ->expectsQuestion('Admin email address', 'admin@example.test')
                ->expectsQuestion('Admin password (minimum 12 characters)', 'correct-horse-battery')
                ->assertExitCode(0);

            $contents = file_get_contents($envFile);
            $this->assertIsString($contents);
            $this->assertStringContainsString("ADMIN_EMAIL='admin@example.test'", $contents);
            $this->assertStringNotContainsString('correct-horse-battery', $contents);
            $this->assertSame(1, preg_match("/^ADMIN_PASSWORD_HASH='([^']+)'$/m", $contents, $matches));
            $this->assertTrue(password_verify('correct-horse-battery', $matches[1]));
        } finally {
            app()->useEnvironmentPath($originalEnvironmentPath)->loadEnvironmentFrom($originalEnvironmentFile);
            unlink($envFile);
            rmdir($directory);
        }
    }

    public function test_api_auth_requires_a_bearer_token(): void
    {
        $this->getJson('/auth/session')
            ->assertUnauthorized()
            ->assertExactJson(['error' => 'Missing Authorization header']);
    }

    public function test_expired_session_tokens_return_403_on_protected_routes(): void
    {
        $secret = 'test-secret-with-sufficient-entropy';
        config(['services.nearby_teacher.jwt_secret' => $secret]);
        $expired = JWT::encode([
            'sub' => (string) Str::uuid(), 'iss' => 'nearby-teacher-auth',
            'iat' => now()->subDays(31)->timestamp, 'exp' => now()->subDay()->timestamp, 'ver' => 0,
        ], $secret, 'HS256');
        Http::preventStrayRequests();
        foreach (['/auth/session', '/auth/onboarding', '/messages'] as $endpoint) {
            $this->withToken($expired)->getJson($endpoint)->assertForbidden()
                ->assertExactJson(['error' => 'Invalid or expired token']);
        }
        $this->withToken($expired)->postJson('/chat/socket/authorize')->assertForbidden()
            ->assertExactJson(['error' => 'Invalid or expired token']);
        $this->withToken($expired)->postJson('/auth/register', [])->assertForbidden()
            ->assertJsonPath('code', 'OTP_VERIFICATION_REQUIRED');
        Http::assertNothingSent();
    }

    public function test_supported_languages_keep_the_existing_mobile_response_shape(): void
    {
        $this->getJson('/i18n/languages')
            ->assertOk()
            ->assertJsonCount(8, 'languages')
            ->assertJsonPath('languages.0.code', 'en')
            ->assertJsonPath('languages.0.nativeName', 'English');
    }

    public function test_unsupported_translation_language_falls_back_to_english(): void
    {
        $this->getJson('/i18n/not-supported')
            ->assertOk()
            ->assertJsonPath('requested', 'not-supported')
            ->assertJsonPath('served', 'en')
            ->assertJsonPath('translations._meta.code', 'en');
    }

    public function test_safety_disclaimer_is_available_without_authentication(): void
    {
        $response = $this->getJson('/auth/safety-disclaimer')
            ->assertOk()
            ->assertJsonStructure(['text']);

        $this->assertStringContainsString(
            'does not conduct criminal background checks',
            $response->json('text')
        );
    }

    public function test_otp_request_rejects_invalid_phone_numbers_before_database_access(): void
    {
        $this->postJson('/auth/otp/request', ['phone' => 'not-a-phone'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['phone']);
    }

    public function test_otp_token_keeps_issuer_and_has_expiry_and_session_version(): void
    {
        config(['services.nearby_teacher.jwt_secret' => 'test-secret-with-sufficient-entropy']);

        $userId = (string) Str::uuid();
        $token = app(AuthenticationService::class)->issueOtpToken($userId);
        $claims = JWT::decode(
            $token,
            new Key('test-secret-with-sufficient-entropy', 'HS256')
        );

        $this->assertSame($userId, $claims->sub);
        $this->assertSame('nearby-teacher-otp', $claims->iss);
        $this->assertGreaterThan(now()->timestamp, $claims->exp);
        $this->assertSame(0, $claims->ver);

        $sessionToken = app(AuthenticationService::class)->issueSessionToken($userId);
        $sessionClaims = JWT::decode(
            $sessionToken,
            new Key('test-secret-with-sufficient-entropy', 'HS256')
        );
        $this->assertSame('nearby-teacher-auth', $sessionClaims->iss);
        $this->assertNotSame($sessionToken, app(AuthenticationService::class)->issueSessionToken($userId));
        $this->assertTrue(Str::isUuid($sessionClaims->jti));
        $this->assertGreaterThan(now()->timestamp, $sessionClaims->exp);
    }

    public function test_profile_stats_still_requires_api_authentication(): void
    {
        $this->getJson('/profiles/me/stats')
            ->assertUnauthorized()
            ->assertExactJson(['error' => 'Missing Authorization header']);
    }

    public function test_booking_and_message_routes_require_api_authentication(): void
    {
        $this->postJson('/bookings', [])
            ->assertUnauthorized()
            ->assertExactJson(['error' => 'Missing Authorization header']);
        $this->getJson('/messages')
            ->assertUnauthorized()
            ->assertExactJson(['error' => 'Missing Authorization header']);
    }

    public function test_broadcast_channel_authentication_requires_api_bearer_token(): void
    {
        $this->postJson('/broadcasting/auth', [
            'socket_id' => '123.456',
            'channel_name' => 'private-conversation.'.Str::uuid(),
        ])->assertUnauthorized()
            ->assertExactJson(['error' => 'Missing Authorization header']);
        $this->postJson('/chat/socket/authorize')->assertUnauthorized();
    }

    public function test_chat_message_event_uses_a_private_conversation_channel(): void
    {
        $message = [
            'id' => (string) Str::uuid(),
            'conversation_id' => (string) Str::uuid(),
            'sender_id' => (string) Str::uuid(),
            'body' => 'Hello',
            'created_at' => now()->toDateTimeString(),
        ];
        $event = new ChatMessageSent($message);

        $this->assertSame('message.sent', $event->broadcastAs());
        $this->assertSame('private-conversation.'.$message['conversation_id'], $event->broadcastOn()[0]->name);
        $this->assertSame(['message' => $message], $event->broadcastWith());
    }

    public function test_private_conversation_channel_checks_membership(): void
    {
        $conversationId = (string) Str::uuid();
        $user = (object) ['id' => (string) Str::uuid()];
        $builder = \Mockery::mock(Builder::class);
        $participantQuery = \Mockery::mock(Builder::class);

        DB::shouldReceive('table')->once()->with('conversations')->andReturn($builder);
        $builder->shouldReceive('where')->once()->with('id', $conversationId)->andReturnSelf();
        $builder->shouldReceive('where')->once()->with(\Mockery::type(\Closure::class))
            ->andReturnUsing(function (\Closure $constraint) use ($builder, $participantQuery, $user) {
                $participantQuery->shouldReceive('where')->once()->with('learner_id', $user->id)->andReturnSelf();
                $participantQuery->shouldReceive('orWhere')->once()->with('provider_id', $user->id)->andReturnSelf();
                $constraint($participantQuery);

                return $builder;
            });
        $builder->shouldReceive('exists')->once()->andReturn(true);

        $channel = Broadcast::getChannels()->get('conversation.{conversationId}');

        $this->assertIsCallable($channel);
        $this->assertTrue($channel($user, $conversationId));
    }

    public function test_search_rejects_missing_or_invalid_coordinates_before_database_access(): void
    {
        $this->getJson('/search')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['lat', 'lng']);
        $this->getJson('/search/counts?lat=91&lng=0')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['lat']);
        $this->getJson('/search/by-subject/1?tier=nearby')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['lat', 'lng']);
    }

    public function test_google_sign_in_requires_a_firebase_id_token(): void
    {
        $this->postJson('/auth/google', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['firebaseIdToken']);
    }

    public function test_google_session_must_verify_a_phone_before_using_protected_apis(): void
    {
        $authentication = \Mockery::mock(AuthenticationService::class);
        $authentication->shouldReceive('authenticate')
            ->twice()
            ->with('google-session-token')
            ->andReturn((object) [
                'suspended_at' => null,
                'is_admin' => false,
                'phone_verified_at' => null,
            ]);
        $this->app->instance(AuthenticationService::class, $authentication);

        $this->getJson('/profiles/me/stats', ['Authorization' => 'Bearer google-session-token'])
            ->assertForbidden()
            ->assertJsonPath('phoneVerificationRequired', true)
            ->assertJsonPath('mobileVerified', false)
            ->assertJsonPath('verifyPhoneEndpoint', '/auth/link-phone');

        $this->patchJson('/auth/link-phone', [
            'phone' => 'not-a-phone',
            'code' => '123456',
        ], ['Authorization' => 'Bearer google-session-token'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['phone']);
    }

    public function test_subject_seed_contains_mysql_compatible_derived_selects(): void
    {
        $seed = file_get_contents(database_path('sql/seed.mysql.sql'));

        $this->assertIsString($seed);
        $this->assertStringNotContainsString('(VALUES', $seed);
        $this->assertStringContainsString("SELECT 'French' AS name", $seed);
        $this->assertStringContainsString('TRUE AS is_bsl', $seed);
        $this->assertStringContainsString("subject_domains.name = 'Languages'", $seed);
    }

    public function test_laravel_exposes_only_a_mysql_database_connection(): void
    {
        $connections = array_filter(config('database.connections'), 'is_array');

        $this->assertSame(['mysql'], array_keys($connections));
        $this->assertSame('mysql', config('database.default'));
    }

    public function test_each_nearby_teacher_table_has_an_individual_migration_with_an_id_primary_key(): void
    {
        $migrations = glob(database_path('migrations/2026_10_17_*_create_*_table.php'));
        $this->assertIsArray($migrations);
        $this->assertCount(23, $migrations);

        foreach ($migrations as $migration) {
            $contents = file_get_contents($migration);
            $this->assertIsString($contents);
            $this->assertMatchesRegularExpression(
                "/(?:\\\$table->char\\('id',\\s*36\\)->primary\\(\\)|\\\$table->increments\\('id'\\))/",
                $contents,
                basename($migration).' must declare an id primary key.'
            );
        }
    }
}
