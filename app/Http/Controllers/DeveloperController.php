<?php

namespace App\Http\Controllers;

use Dotenv\Dotenv;
use Illuminate\Console\Command;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\View\View;
use RuntimeException;

class DeveloperController extends Controller
{
    public function login(Request $request): View|RedirectResponse
    {
        if ($request->session()->get('developer_authenticated') === true) {
            return redirect()->route('developer.portal');
        }

        return view('developer.login', [
            'configured' => filled(config('developer.email')) && filled(config('developer.password_hash')),
        ]);
    }

    public function authenticate(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);
        $key = 'developer-login:'.Str::lower($credentials['email']).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, 6)) {
            return back()->withErrors(['email' => 'Too many sign-in attempts. Please try again shortly.']);
        }

        $configuredEmail = (string) config('developer.email');
        $passwordHash = (string) config('developer.password_hash');
        $validEmail = $configuredEmail !== ''
            && hash_equals(Str::lower($configuredEmail), Str::lower($credentials['email']));

        if (! $validEmail || $passwordHash === '' || ! Hash::check($credentials['password'], $passwordHash)) {
            RateLimiter::hit($key, 60);

            return back()->withErrors(['email' => 'The provided developer credentials are invalid.']);
        }

        RateLimiter::clear($key);
        $request->session()->regenerate();
        $request->session()->put('developer_authenticated', true);

        return redirect()->route('developer.portal');
    }

    public function logout(Request $request): RedirectResponse
    {
        $request->session()->forget('developer_authenticated');
        $request->session()->regenerateToken();

        return redirect()->route('developer.login');
    }

    public function portal(): View
    {
        $groups = $this->settingsCatalog();
        $catalog = $this->apiCatalog();

        return view('developer.portal', [
            'catalog' => $catalog,
            'categories' => array_values(array_unique(array_column($catalog, 'category'))),
            'groups' => $groups,
            'settings' => $this->currentSettings($groups),
            'baseUrl' => config('app.url'),
        ]);
    }

    public function updateSettings(Request $request): RedirectResponse
    {
        $settings = $this->settingsCatalog();
        $allowed = collect($settings)->flatten(1)->keyBy('key')->all();
        $submitted = $request->input('settings', []);
        $cleared = $request->input('clear', []);

        if (! is_array($submitted) || ! is_array($cleared)) {
            return redirect()->route('developer.portal', ['tab' => 'configuration'])
                ->withErrors(['settings' => 'Configuration settings must be submitted as a form.']);
        }

        foreach ($cleared as $key) {
            if (! is_string($key)) {
                return redirect()->route('developer.portal', ['tab' => 'configuration'])
                    ->withErrors(['settings' => 'Clear selections must contain configuration key names.']);
            }
        }

        $unknownKeys = array_diff(array_merge(array_keys($submitted), $cleared), array_keys($allowed));
        if ($unknownKeys !== []) {
            return redirect()->route('developer.portal', ['tab' => 'configuration'])
                ->withErrors(['settings' => 'One or more unrecognized configuration keys were submitted.']);
        }

        $changes = [];
        $errors = [];
        foreach ($submitted as $key => $value) {
            if ($value !== null && ! is_string($value)) {
                $errors['settings.'.$key] = 'Enter a plain text value for '.$key.'.';

                continue;
            }

            $value ??= '';
            if (in_array($key, $cleared, true)) {
                if ($value !== '') {
                    $errors['settings.'.$key] = 'Leave the value blank when clearing this setting.';
                }

                continue;
            }

            if ($value === '') {
                continue;
            }

            $setting = $allowed[$key];
            $normalized = in_array($setting['type'], ['secret', 'secret_json'], true) ? $value : trim($value);
            $error = $this->validateSetting($key, $normalized, $setting['type']);

            if ($error !== null) {
                $errors['settings.'.$key] = $error;

                continue;
            }

            $changes[$key] = $normalized;
        }

        foreach ($cleared as $key) {
            if (! is_string($key) || ! array_key_exists($key, $allowed)) {
                continue;
            }

            if ($key === 'APP_KEY') {
                $errors['settings.APP_KEY'] = 'The application encryption key cannot be cleared.';

                continue;
            }

            $changes[$key] = '';
        }

        if ($errors !== []) {
            return redirect()->route('developer.portal', ['tab' => 'configuration'])->withErrors($errors);
        }

        if ($changes === []) {
            return redirect()->route('developer.portal', ['tab' => 'configuration'])
                ->with('status', 'No configuration changes were submitted.');
        }

        if (! $request->boolean('confirm_changes')) {
            return redirect()->route('developer.portal', ['tab' => 'configuration'])
                ->withErrors(['confirm_changes' => 'Confirm that you understand these changes can interrupt the application or its services.']);
        }

        try {
            $this->writeEnvironment($changes);
        } catch (RuntimeException $exception) {
            report($exception);

            return redirect()->route('developer.portal', ['tab' => 'configuration'])
                ->withErrors(['settings' => 'The environment file could not be updated safely. No configuration changes were applied.']);
        }

        if (Artisan::call('config:clear') !== Command::SUCCESS) {
            return redirect()->route('developer.portal', ['tab' => 'configuration'])
                ->withErrors(['settings' => 'The environment file was updated, but cached configuration could not be cleared. Run php artisan config:clear.']);
        }

        return redirect()->route('developer.portal', ['tab' => 'configuration'])
            ->with('status', 'Configuration saved. Restart long-running application, queue, and WebSocket processes to apply every change.');
    }

    private function apiCatalog(): array
    {
        $catalog = [];
        $supportedMethods = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'];

        foreach (Route::getRoutes() as $route) {
            $action = $route->getActionName();
            $path = '/'.ltrim($route->uri(), '/');
            $routeMiddleware = $route->gatherMiddleware();
            if (! str_starts_with($action, 'App\\Http\\Controllers\\Api\\')
                && ! in_array('api', $routeMiddleware, true)
                && ! in_array($path, ['/broadcasting/auth', '/health', '/up', '/location-lookup/reverse'], true)) {
                continue;
            }

            $methods = array_values(array_intersect($supportedMethods, $route->methods()));
            if ($methods === []) {
                continue;
            }

            $middleware = implode(' ', $routeMiddleware);
            $access = match (true) {
                str_contains($middleware, 'RequireAdminApi') => 'Admin bearer',
                str_contains($middleware, 'RequireApiAuthentication') => 'Bearer token',
                str_contains($middleware, 'AttachOptionalApiUser') => 'Optional bearer',
                default => 'Public',
            };
            if (str_contains($middleware, 'RequireApiAuthentication')) {
                $access = 'Bearer token';
            }
            if (str_contains($middleware, 'RequireAdminApi')) {
                $access = 'Admin access required';
            } elseif (str_contains($middleware, 'RequireApiAuthentication')) {
                $access = 'Sign-in required';
            } elseif (str_contains($middleware, 'AttachOptionalApiUser')) {
                $access = 'Optional sign-in';
            }
            if (in_array($path, ['/auth/register', '/auth/onboarding/complete'], true)) {
                $access = 'OTP verification required';
            }
            $category = $this->categoryFor($path);

            foreach ($methods as $method) {
                $catalog[] = [
                    'id' => $method.' '.$path,
                    'method' => $method,
                    'path' => $path,
                    'category' => $category,
                    'access' => $access,
                    'action' => Str::afterLast(str_replace('\\', '/', $action), '/'),
                    'parameters' => $this->routeParameters($path),
                    ...$this->requestExample($method, $path),
                ];
            }
        }

        $categoryOrder = [
            'Authentication' => 0,
            'Catalog' => 1,
            'Localization' => 2,
            'Search' => 3,
            'Profiles' => 4,
            'Bookings' => 5,
            'Chat and realtime' => 6,
            'Community' => 7,
            'Safety and support' => 8,
            'Analytics' => 9,
            'Moderation' => 10,
            'Location' => 11,
            'System' => 12,
            'Other' => 13,
        ];
        $authOrder = [
            'POST /auth/google' => 0,
            'POST /auth/otp/request' => 1,
            'PATCH /auth/link-phone' => 2,
            'POST /auth/otp/verify' => 3,
            'GET /auth/onboarding' => 4,
            'POST /auth/register' => 5,
            'POST /auth/onboarding/complete' => 6,
            'PATCH /auth/onboarding' => 7,
            'POST /auth/login' => 8,
            'POST /auth/forgot-password' => 9,
            'POST /auth/reset-password' => 10,
            'POST /auth/email/verification/request' => 11,
            'POST /auth/email/verification/verify' => 12,
        ];
        usort($catalog, static function (array $left, array $right) use ($categoryOrder, $authOrder): int {
            $categoryComparison = ($categoryOrder[$left['category']] ?? 99) <=> ($categoryOrder[$right['category']] ?? 99);
            if ($categoryComparison !== 0) {
                return $categoryComparison;
            }

            if ($left['category'] === 'Authentication') {
                $workflowComparison = ($authOrder[$left['id']] ?? 99) <=> ($authOrder[$right['id']] ?? 99);
                if ($workflowComparison !== 0) {
                    return $workflowComparison;
                }
            }

            return [$left['path'], $left['method']] <=> [$right['path'], $right['method']];
        });

        return $catalog;
    }

    private function requestExample(string $method, string $path): array
    {
        $examples = [
            'POST /auth/google' => [
                'body' => ['firebaseIdToken' => 'PASTE_FIREBASE_GOOGLE_ID_TOKEN'],
                'guide' => 'Step 1 · Google sign-in. Replace the token with a verified Firebase Google ID token.',
            ],
            'POST /auth/otp/request' => [
                'body' => ['phone' => '+919876543210'],
                'guide' => 'Step 2 · Request registration phone OTP, or link a phone after Google sign-in. In local test mode, read otp from the response; otherwise use the SMS code.',
            ],
            'PATCH /auth/link-phone' => [
                'body' => ['phone' => '+919876543210', 'code' => '123456'],
                'guide' => 'Step 3 · Link the verified phone. Set the bearer token from Google sign-in first.',
            ],
            'POST /auth/otp/verify' => [
                'body' => ['phone' => '+919876543210', 'code' => '123456'],
                'guide' => 'Verify registration OTP and copy the response token for Register. Existing registered accounts receive 409 and must use email/password login.',
            ],
            'POST /auth/login' => [
                'body' => ['email' => 'anita@example.com', 'password' => 'a-secure-password'],
                'guide' => 'Email + password only. Mobile-number password login is not supported.',
            ],
            'POST /auth/forgot-password' => [
                'body' => ['email' => 'anita@example.com'],
                'guide' => 'Send a password reset OTP by email. The response is generic and never includes the OTP.',
            ],
            'POST /auth/reset-password' => [
                'body' => ['email' => 'anita@example.com', 'code' => '123456', 'password' => 'new-secure-password', 'password_confirmation' => 'new-secure-password'],
                'guide' => 'Use the emailed code to reset password; all previous sessions are revoked.',
            ],
            'POST /auth/email/verification/request' => [
                'body' => [], 'guide' => 'Request a code for the signed-in account email. Run the notifications worker; email codes are never shown in the response.',
            ],
            'POST /auth/email/verification/verify' => [
                'body' => ['code' => '123456'], 'guide' => 'Verify the saved email using the code received by email.',
            ],
            'POST /chat/socket/authorize' => [
                'body' => ['conversationId' => '00000000-0000-4000-8000-000000000003'],
                'guide' => 'Socket.IO uses this API for bearer and room authorization. Omit conversationId to check connection identity only.',
            ],
            'GET /auth/onboarding' => [
                'guide' => 'Load the Welcome / complete-profile draft. Wait for mobileVerified=true before enabling Continue.',
            ],
            'PATCH /auth/onboarding' => [
                'body' => ['fullName' => 'Anita Sharma Updated'],
                'guide' => 'Auto-save partial fields only after registration is complete. Before registration, keep form values on the client.',
            ],
            'POST /auth/register' => [
                'body' => [
                    'accountType' => 'student',
                    'fullName' => 'Rohit Verma',
                    'email' => 'rohit@example.com',
                    'password' => 'a-secure-password',
                    'termsAccepted' => true,
                    'acknowledgeTeachersNotBackgroundChecked' => true,
                ],
                'guide' => 'Registration: verify OTP on the same page first, then submit the full form once. Autosave becomes available after success.',
            ],
            'POST /auth/onboarding/complete' => [
                'body' => [
                    'accountType' => 'student',
                    'fullName' => 'Rohit Verma',
                    'email' => 'rohit@example.com',
                    'password' => 'a-secure-password',
                    'termsAccepted' => true,
                    'acknowledgeTeachersNotBackgroundChecked' => true,
                ],
                'guide' => 'Final submit. Rejected until mobileVerified is true. Students then call POST /auth/date-of-birth.',
            ],
            'POST /auth/parental-consent/confirm' => ['body' => ['token' => 'PASTE_ONE_TIME_GUARDIAN_TOKEN']],
            'POST /auth/account-type' => ['body' => ['accountType' => 'learner']],
            'PATCH /auth/preferred-language' => ['body' => ['language' => 'en']],
            'PATCH /auth/avatar' => ['body' => ['avatarUrl' => 'https://example.com/avatar.jpg']],
            'PATCH /auth/link-google' => ['body' => ['firebaseIdToken' => 'PASTE_FIREBASE_GOOGLE_ID_TOKEN']],
            'POST /auth/date-of-birth' => ['body' => ['dateOfBirth' => '1998-06-15']],
            'POST /auth/acknowledge-safety-disclaimer' => ['body' => []],
            'POST /location-lookup/reverse' => [
                'body' => ['latitude' => 28.6139, 'longitude' => 77.2090],
                'guide' => 'Reverse-geocode coordinates using OpenStreetMap. Limited to one lookup per second and 30 requests per minute per IP.',
            ],
            'PUT /profiles/me' => [
                'body' => ['bio' => 'Experienced teacher offering online lessons', 'ratePerHour' => 500, 'currency' => 'INR', 'lessonModes' => ['online'], 'city' => 'New Delhi', 'country' => 'India', 'countryCode' => 'IN', 'subjectIds' => [1]],
                'guide' => 'Teacher example. For an institute account, replace bio/ratePerHour with description/startingRate.',
            ],
            'PATCH /profiles/{type}/{id}/verify' => ['body' => ['verified' => true], 'pathDefaults' => ['type' => 'teacher', 'id' => '00000000-0000-4000-8000-000000000001']],
            'PATCH /profiles/institute/{id}/type' => ['body' => ['institute_type' => 'independent'], 'pathDefaults' => ['id' => '00000000-0000-4000-8000-000000000001']],
            'PATCH /profiles/institute/{id}/bsl-verified' => ['body' => ['verified' => false], 'pathDefaults' => ['id' => '00000000-0000-4000-8000-000000000001']],
            'GET /profiles/{type}/{id}' => ['pathDefaults' => ['type' => 'teacher', 'id' => '00000000-0000-4000-8000-000000000001']],
            'GET /profiles/me/stats' => ['query' => 'days=30'],
            'POST /bookings' => [
                'body' => ['providerId' => '00000000-0000-4000-8000-000000000001', 'mode' => 'online', 'requestedTime' => '2030-01-15T10:00:00Z', 'subjectId' => 1, 'price' => 500],
                'guide' => 'Requires an existing teacher/institute UUID, a valid subject ID, safety acknowledgement, and guardian consent for minors.',
            ],
            'PATCH /bookings/{id}/status' => ['body' => ['status' => 'accepted'], 'pathDefaults' => ['id' => '00000000-0000-4000-8000-000000000002']],
            'POST /messages/start' => [
                'body' => ['providerId' => '00000000-0000-4000-8000-000000000001', 'body' => 'Hello, I would like to ask about your lessons.'],
                'guide' => 'Requires a learner bearer token, an existing teacher/institute UUID, safety acknowledgement, and guardian consent for minors.',
            ],
            'POST /messages/{conversationId}' => ['body' => ['body' => 'Hello, thanks for getting back to me.'], 'pathDefaults' => ['conversationId' => '00000000-0000-4000-8000-000000000003']],
            'GET /messages/{conversationId}' => ['pathDefaults' => ['conversationId' => '00000000-0000-4000-8000-000000000003']],
            'POST /broadcasting/auth' => ['body' => ['socket_id' => '123456.789012', 'channel_name' => 'private-conversation.00000000-0000-4000-8000-000000000003']],
            'POST /reviews' => ['body' => ['bookingId' => '00000000-0000-4000-8000-000000000002', 'rating' => 5, 'comment' => 'Great lesson.']],
            'GET /feed' => ['query' => 'city=New%20Delhi'],
            'POST /feed' => ['body' => ['city' => 'New Delhi', 'body' => 'Looking for a BSL teacher for beginner classes.', 'subjectId' => 1]],
            'POST /feed/{postId}/comments' => ['body' => ['body' => 'Thanks for sharing.'], 'pathDefaults' => ['postId' => '00000000-0000-4000-8000-000000000004']],
            'POST /safety/reports' => ['body' => ['targetType' => 'user', 'targetId' => '00000000-0000-4000-8000-000000000001', 'reasonCategory' => 'fake_profile', 'reason' => 'Example report reason.']],
            'POST /suggestions' => ['body' => ['suggestionType' => 'course', 'name' => 'Beginner Sign Language', 'notes' => 'Example suggestion.']],
            'POST /support' => ['body' => ['subject' => 'Example support request', 'message' => 'Please describe your support question here.']],
            'POST /referrals/redeem' => ['body' => ['code' => 'PASTE_REFERRAL_CODE']],
            'POST /analytics/page-view' => ['body' => ['pageType' => 'teacher', 'target' => 'teacher-profile', 'platform' => 'web', 'referrer' => 'developer-portal']],
            'PATCH /safety/reports/{id}/status' => ['body' => ['status' => 'reviewed'], 'pathDefaults' => ['id' => '00000000-0000-4000-8000-000000000005']],
            'PATCH /suggestions/{id}/status' => ['body' => ['status' => 'added'], 'pathDefaults' => ['id' => '00000000-0000-4000-8000-000000000006']],
            'PATCH /support/{id}/status' => ['body' => ['status' => 'in_progress'], 'pathDefaults' => ['id' => '00000000-0000-4000-8000-000000000007']],
            'PATCH /moderation/photos/{userId}' => ['body' => ['status' => 'approved'], 'pathDefaults' => ['userId' => '00000000-0000-4000-8000-000000000001']],
            'POST /moderation/users/{userId}/suspend' => ['body' => ['reason' => 'Example moderation reason.'], 'pathDefaults' => ['userId' => '00000000-0000-4000-8000-000000000001']],
            'POST /moderation/users/{userId}/unsuspend' => ['body' => [], 'pathDefaults' => ['userId' => '00000000-0000-4000-8000-000000000001']],
            'POST /safety/blocks/{userId}' => ['body' => [], 'pathDefaults' => ['userId' => '00000000-0000-4000-8000-000000000001']],
            'DELETE /safety/blocks/{userId}' => ['pathDefaults' => ['userId' => '00000000-0000-4000-8000-000000000001']],
            'GET /safety/reports' => ['query' => 'status=open'],
            'GET /suggestions' => ['query' => 'status=open'],
            'GET /support' => ['query' => 'status=open'],
            'GET /analytics/summary' => ['query' => 'days=30'],
            'GET /i18n/{lang}' => ['pathDefaults' => ['lang' => 'en']],
            'GET /reviews/for/{providerId}' => ['pathDefaults' => ['providerId' => '00000000-0000-4000-8000-000000000001']],
            'GET /feed/{postId}/comments' => ['pathDefaults' => ['postId' => '00000000-0000-4000-8000-000000000004']],
            'GET /moderation/conversations/{id}/messages' => ['pathDefaults' => ['id' => '00000000-0000-4000-8000-000000000003']],
            'GET /moderation/users/suspended' => ['query' => ''],
        ];
        $examplePath = preg_replace('#^/api(?=/|$)#', '', $path) ?? $path;
        $key = $method.' '.$examplePath;
        $example = $examples[$key] ?? [];

        if ($key === 'GET /search') {
            $example['query'] = 'lat=28.6139&lng=77.2090&radiusKm=7';
            $example['guide'] = 'Nearest 50 providers. Optional subjectId, q and freeOnly filters run before shortlisting. Server-Timing shows search execution time; it excludes network and application startup.';
        } elseif ($key === 'GET /search/dashboard') {
            $example['query'] = 'lat=28.6139&lng=77.2090&radiusKm=7';
            $example['guide'] = 'Home/map dashboard: all active, registered, phone-verified providers in the radius, nearest first, with coordinates, public listing details, subjects and counts. No 50-result cap. Phone/email are not returned.';
        } elseif ($key === 'GET /search/counts') {
            $example['query'] = 'lat=28.6139&lng=77.2090&radiusKm=7';
        } elseif ($key === 'GET /search/other-city') {
            $example['query'] = 'city=New%20Delhi&q=sign%20language';
        } elseif ($key === 'GET /search/by-subject/{subjectId}') {
            $example['query'] = 'tier=nearby&lat=28.6139&lng=77.2090';
            $example['pathDefaults'] = ['subjectId' => '1'];
        }

        if ($method === 'GET' && in_array($examplePath, [
            '/messages', '/messages/{conversationId}', '/bookings/incoming', '/bookings/mine',
            '/reviews/for/{providerId}', '/feed', '/feed/{postId}/comments',
        ], true)) {
            $example['query'] = ltrim(($example['query'] ?? '').'&perPage=50', '&');
            $example['guide'] = 'Cursor pagination: follow pagination.nextCursor using the cursor query parameter. Default 50, maximum 100 records per page.';
        }
        $pathDefaults = $example['pathDefaults'] ?? [];
        foreach ($this->routeParameters($path) as $parameter) {
            $pathDefaults[$parameter] ??= match ($parameter) {
                'type' => 'teacher',
                'lang' => 'en',
                'subjectId' => '1',
                default => '00000000-0000-4000-8000-000000000001',
            };
        }

        return [
            'bodyExample' => $example['body'] ?? null,
            'queryExample' => $example['query'] ?? '',
            'pathDefaults' => $pathDefaults,
            'guide' => $example['guide'] ?? null,
        ];
    }

    private function categoryFor(string $path): string
    {
        return match (true) {
            str_starts_with($path, '/auth/') => 'Authentication',
            str_starts_with($path, '/i18n/') => 'Localization',
            str_starts_with($path, '/subjects') => 'Catalog',
            str_starts_with($path, '/search') => 'Search',
            str_starts_with($path, '/profiles') => 'Profiles',
            str_starts_with($path, '/bookings') => 'Bookings',
            str_starts_with($path, '/messages'), str_starts_with($path, '/chat/'), $path === '/broadcasting/auth' => 'Chat and realtime',
            str_starts_with($path, '/reviews'), str_starts_with($path, '/feed') => 'Community',
            str_starts_with($path, '/safety'), str_starts_with($path, '/support'),
            str_starts_with($path, '/suggestions'), str_starts_with($path, '/referrals') => 'Safety and support',
            str_starts_with($path, '/analytics') => 'Analytics',
            str_starts_with($path, '/moderation') => 'Moderation',
            str_starts_with($path, '/location-lookup') => 'Location',
            in_array($path, ['/health', '/up'], true) => 'System',
            default => 'Other',
        };
    }

    private function routeParameters(string $path): array
    {
        preg_match_all('/\{([^}]+)\}/', $path, $matches);

        return $matches[1] ?? [];
    }

    private function settingsCatalog(): array
    {
        $groups = config('developer.editable_settings');
        $known = [];

        foreach ($groups as $groupSettings) {
            foreach ($groupSettings as $setting) {
                $known[$setting['key']] = true;
            }
        }

        $excluded = ['ADMIN_EMAIL', 'ADMIN_PASSWORD_HASH', 'DEVELOPER_EMAIL', 'DEVELOPER_PASSWORD_HASH'];
        foreach (File::allFiles(config_path()) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            preg_match_all('/\benv\s*\(\s*[\'"]([A-Z][A-Z0-9_]*)[\'"]/', $file->getContents(), $matches);
            $group = Str::headline($file->getFilenameWithoutExtension());
            foreach (array_unique($matches[1] ?? []) as $key) {
                if (isset($known[$key]) || in_array($key, $excluded, true)) {
                    continue;
                }

                $groups[$group][] = $this->settingDefinition($key);
                $known[$key] = true;
            }
        }

        $examplePath = base_path('.env.example');
        if (File::isFile($examplePath)) {
            foreach (array_keys(Dotenv::parse(File::get($examplePath))) as $key) {
                if (isset($known[$key]) || in_array($key, $excluded, true)) {
                    continue;
                }

                $groups['Other project settings'][] = $this->settingDefinition($key);
                $known[$key] = true;
            }
        }

        return $groups;
    }

    private function settingDefinition(string $key): array
    {
        if ($key === 'FIREBASE_SERVICE_ACCOUNT_JSON') {
            $type = 'secret_json';
        } elseif (in_array($key, [
            'APP_DEBUG', 'AWS_USE_PATH_STYLE_ENDPOINT', 'LOG_DEPRECATIONS_TRACE',
            'REVERB_APP_RATE_LIMIT_TERMINATE', 'REVERB_APP_RATE_LIMITING_ENABLED',
            'REVERB_SCALING_ENABLED', 'SESSION_ENCRYPT', 'SESSION_EXPIRE_ON_CLOSE',
            'SESSION_HTTP_ONLY', 'SESSION_PARTITIONED_COOKIE', 'SESSION_SECURE_COOKIE',
        ], true)) {
            $type = 'boolean';
        } elseif (preg_match('/(?:PORT|_DB|_DAYS|_TIMEOUT|_INTERVAL|_ATTEMPTS|_SIZE|_LIFETIME|_AFTER|_CONNECTIONS)$/', $key) === 1) {
            $type = 'number';
        } elseif (preg_match('/(?:^|_)(?:PASSWORD|SECRET|TOKEN|CREDENTIALS?|KEYS?|AUTH|ACCESS_KEY)(?:_|$)/', $key) === 1
            || in_array($key, ['DB_URL', 'DATABASE_URL', 'MAIL_URL', 'REDIS_URL', 'SOCKET_IO_REDIS_URL', 'TWILIO_ACCOUNT_SID'], true)
            || str_contains($key, 'WEBHOOK')) {
            $type = 'secret';
        } elseif (in_array($key, [
            'APP_URL', 'APP_BASE_URL', 'IOS_APP_STORE_URL', 'ANDROID_PLAY_STORE_URL',
            'AWS_URL', 'AWS_ENDPOINT', 'DYNAMODB_ENDPOINT',
        ], true)) {
            $type = 'url';
        } elseif (str_ends_with($key, 'EMAIL') || $key === 'MAIL_FROM_ADDRESS') {
            $type = 'email';
        } else {
            $type = 'text';
        }

        return [
            'key' => $key,
            'label' => Str::headline(Str::lower($key)),
            'type' => $type,
        ];
    }

    private function currentSettings(array $groups): array
    {
        $path = app()->environmentFilePath();
        $values = File::isFile($path) ? Dotenv::parse(File::get($path)) : [];
        $settings = [];

        foreach ($groups as $group => $groupSettings) {
            foreach ($groupSettings as $setting) {
                $value = $values[$setting['key']] ?? '';
                $settings[$group][] = $setting + [
                    'configured' => $value !== '',
                    'value' => in_array($setting['type'], ['secret', 'secret_json'], true) ? '' : $value,
                ];
            }
        }

        return $settings;
    }

    private function validateSetting(string $key, string $value, string $type): ?string
    {
        $maxLength = $key === 'FIREBASE_SERVICE_ACCOUNT_JSON' ? 20000 : 4096;
        if (strlen($value) > $maxLength || str_contains($value, "\n") || str_contains($value, "\r") || str_contains($value, "\0")) {
            return 'Use a single-line value within the allowed length.';
        }

        if ($type === 'boolean' && ! in_array(strtolower($value), ['true', 'false', '1', '0'], true)) {
            return 'Enter true or false.';
        }

        if ($type === 'number' && (! ctype_digit($value) || (int) $value > 65535)) {
            return 'Enter a whole number between 0 and 65535.';
        }

        if (in_array($type, ['url', 'email'], true)
            && filter_var($value, $type === 'url' ? FILTER_VALIDATE_URL : FILTER_VALIDATE_EMAIL) === false) {
            return 'Enter a valid '.($type === 'url' ? 'URL.' : 'email address.');
        }

        if ($key === 'APP_TIMEZONE' && ! in_array($value, timezone_identifiers_list(), true)) {
            return 'Enter a recognized timezone, for example UTC or Asia/Kolkata.';
        }

        if ($key === 'APP_KEY') {
            $keyValue = str_starts_with($value, 'base64:') ? base64_decode(substr($value, 7), true) : $value;
            if (! is_string($keyValue) || strlen($keyValue) !== 32) {
                return 'The encryption key must be 32 bytes or a base64: encoded 32-byte key.';
            }
        }

        if ($key === 'FIREBASE_SERVICE_ACCOUNT_JSON' && (! is_array(json_decode($value, true)) || json_last_error() !== JSON_ERROR_NONE)) {
            return 'Enter valid Firebase service account JSON.';
        }

        return null;
    }

    private function writeEnvironment(array $changes): void
    {
        $path = app()->environmentFilePath();
        if (! File::isFile($path) || ! is_writable($path) || is_link($path)) {
            throw new RuntimeException('Environment file is not a writable regular file.');
        }

        $contents = File::get($path);
        $lines = preg_split('/\r\n|\n|\r/', $contents);
        $updated = [];
        $seen = [];

        foreach ($lines as $line) {
            if (preg_match('/^([A-Z][A-Z0-9_]*)=/', $line, $matches) !== 1
                || ! array_key_exists($matches[1], $changes)) {
                $updated[] = $line;

                continue;
            }

            $key = $matches[1];
            if (isset($seen[$key])) {
                continue;
            }

            $updated[] = $key.'='.$this->quoteEnvValue($changes[$key]);
            $seen[$key] = true;
        }

        foreach ($changes as $key => $value) {
            if (! isset($seen[$key])) {
                $updated[] = $key.'='.$this->quoteEnvValue($value);
            }
        }

        $newContents = rtrim(implode(PHP_EOL, $updated), PHP_EOL).PHP_EOL;
        $temporaryPath = tempnam(dirname($path), '.env-developer-');
        if ($temporaryPath === false) {
            throw new RuntimeException('Could not create a temporary environment file.');
        }

        try {
            $permissions = fileperms($path);
            if ($permissions !== false && ! chmod($temporaryPath, $permissions & 0777)) {
                throw new RuntimeException('Could not preserve environment file permissions.');
            }

            if (file_put_contents($temporaryPath, $newContents, LOCK_EX) !== strlen($newContents)
                || ! rename($temporaryPath, $path)) {
                throw new RuntimeException('Could not atomically replace the environment file.');
            }
        } finally {
            if (is_file($temporaryPath)) {
                unlink($temporaryPath);
            }
        }
    }

    private function quoteEnvValue(string $value): string
    {
        return "'".str_replace(['\\', "'"], ['\\\\', "\\'"], $value)."'";
    }
}
