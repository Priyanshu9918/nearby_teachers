<?php

namespace Tests\Unit;

use App\Services\AuthenticationService;
use Illuminate\Support\Facades\Http;
use Tests\Support\FirebaseTokens;
use Tests\TestCase;

class FirebaseVerificationTest extends TestCase
{
    use FirebaseTokens;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeFirebase();
        config(['services.nearby_teacher.firebase_service_account_path' => '']);
    }

    public function test_verifies_a_signed_token_using_only_the_public_project_id(): void
    {
        config(['services.nearby_teacher.firebase_service_account_json' => '']);
        $claims = app(AuthenticationService::class)->verifyFirebaseClaims($this->firebaseToken());
        $this->assertSame('firebase-google-user', $claims->sub);
        Http::assertSentCount(1);
    }

    public function test_reads_project_id_from_a_private_json_file_and_fails_closed_for_bad_files(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'firebase-test-');
        try {
            file_put_contents($path, json_encode(['project_id' => 'file-project', 'private_key' => 'private-marker']));
            config(['services.nearby_teacher.firebase_project_id' => '',
                'services.nearby_teacher.firebase_service_account_path' => $path]);
            $this->assertSame('file-project', app(AuthenticationService::class)->firebaseProjectId());
            file_put_contents($path, '{broken-private-marker');
            try {
                app(AuthenticationService::class)->firebaseProjectId();
                $this->fail('Invalid file must fail.');
            } catch (\RuntimeException $exception) {
                $this->assertStringNotContainsString('private-marker', $exception->getMessage());
                $this->assertNull($exception->getPrevious());
            }
            unlink($path);
            $this->expectException(\RuntimeException::class);
            app(AuthenticationService::class)->firebaseProjectId();
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    public function test_rejects_wrong_project_issuer_subject_time_and_signature(): void
    {
        $auth = app(AuthenticationService::class);
        foreach ([['aud' => 'wrong-project'], ['iss' => 'https://accounts.google.com'], ['sub' => ''],
            ['exp' => now()->timestamp - 1], ['iat' => now()->timestamp + 30],
            ['auth_time' => now()->timestamp + 30], ['auth_time' => null], ['exp' => now()->timestamp + 7200]] as $claims) {
            $this->assertNull($auth->verifyFirebaseClaims($this->firebaseToken($claims)));
        }
        $token = explode('.', $this->firebaseToken());
        $token[2] = str_repeat('a', strlen($token[2]));
        $this->assertNull($auth->verifyFirebaseClaims(implode('.', $token)));
    }

    public function test_malformed_tokens_do_not_fetch_certificates_and_other_firebase_providers_cannot_resolve_users(): void
    {
        $auth = app(AuthenticationService::class);
        $this->assertNull($auth->verifyFirebaseClaims('not-a-token'));
        Http::assertNothingSent();
        $this->assertNull($auth->verifyFirebaseToken($this->firebaseToken(['firebase' => ['sign_in_provider' => 'password']])));
        $this->assertNull($auth->verifyFirebaseToken($this->firebaseToken(['email_verified' => false])));
    }

    public function test_certificate_cache_respects_google_max_age(): void
    {
        $auth = app(AuthenticationService::class);
        $token = $this->firebaseToken();
        $auth->verifyFirebaseClaims($token);
        $auth->verifyFirebaseClaims($token);
        Http::assertSentCount(1);
        $this->travel(61)->seconds();
        $auth->verifyFirebaseClaims($token);
        Http::assertSentCount(2);
    }

    public function test_setup_checker_requires_configuration_without_printing_secrets(): void
    {
        config(['services.nearby_teacher.jwt_secret' => str_repeat('private-marker-', 4)]);
        $this->artisan('firebase:check', ['--offline' => true])->expectsOutputToContain('nearby-firebase-test')->assertSuccessful();
        config(['services.nearby_teacher.firebase_project_id' => '', 'services.nearby_teacher.firebase_service_account_json' => '']);
        $this->artisan('firebase:check', ['--offline' => true])->expectsOutputToContain('Set FIREBASE_PROJECT_ID')->assertFailed();
    }
}
