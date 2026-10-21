<?php

namespace App\Console\Commands;

use App\Services\AuthenticationService;
use Illuminate\Console\Command;
use Throwable;

class CheckFirebase extends Command
{
    protected $signature = 'firebase:check {--offline : Only check local backend configuration}';

    protected $description = 'Check Firebase project configuration and public verification certificates without exposing secrets';

    public function handle(AuthenticationService $auth): int
    {
        try {
            $project = $auth->firebaseProjectId();
            if ($project === null) {
                $this->components->error('Set FIREBASE_PROJECT_ID or FIREBASE_SERVICE_ACCOUNT_PATH in .env and run php artisan config:clear.');

                return self::FAILURE;
            }
            $this->components->info('Firebase project ID: '.$project);
            if (strlen((string) config('services.nearby_teacher.jwt_secret')) < 32) {
                $this->components->error('Set a stable JWT_SECRET of at least 32 bytes for Laravel session tokens.');

                return self::FAILURE;
            }
            if (! $this->option('offline')) {
                $certificates = $auth->firebaseCertificates();
                $this->components->info('Public verification certificates available: '.count($certificates));
            }
            $this->components->info('Backend checks passed. Verify a real app-issued Firebase ID token with POST /auth/google to finish acceptance testing.');
            $this->line('This check does not validate Console provider settings, app fingerprints, OAuth configuration or revocation status.');

            return self::SUCCESS;
        } catch (Throwable) {
            $this->components->error('Firebase configuration or certificate verification is unavailable. Check project settings, cache and outbound HTTPS. No credentials were printed.');

            return self::FAILURE;
        }
    }
}
