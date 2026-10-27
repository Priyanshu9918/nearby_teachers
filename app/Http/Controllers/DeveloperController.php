<?php

namespace App\Http\Controllers;

use App\Support\DeveloperResponseExamples;
use Dotenv\Dotenv;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
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

    public function portal(Request $request): View
    {
        $groups = $this->settingsCatalog();
        $catalog = $this->apiCatalog();

        return view('developer.portal', [
            'catalog' => $catalog,
            'categories' => config('api_catalog.groups'),
            'routeCount' => count(array_unique(array_column($catalog, 'routeKey'))),
            'groups' => $groups,
            'settings' => $this->currentSettings($groups),
            'baseUrl' => $request->root(),
        ]);
    }

    public function socketHealth(): JsonResponse
    {
        // The destination comes from server configuration, never browser input.
        $url = rtrim((string) config('broadcasting.connections.socketio.url'), '/');
        $parts = parse_url($url);
        if (! $parts || ! in_array($parts['scheme'] ?? '', ['http', 'https'], true)
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])) {
            return response()->json(['ok' => false, 'error' => 'Socket.IO internal URL is not configured correctly.'], 503)
                ->header('Cache-Control', 'no-store');
        }

        try {
            $response = Http::acceptJson()->connectTimeout(2)->timeout(4)
                ->withoutRedirecting()->get($url.'/health');
            $ready = $response->successful() && $response->json('ok') === true;
            $status = $ready ? 'ready' : 'not_ready';
        } catch (ConnectionException) {
            $ready = false;
            $status = 'unreachable';
        }

        return response()->json(['ok' => $ready, 'service' => 'Socket.IO',
            'status' => $status,
            'note' => $ready ? 'Configured chat server is ready. Test the public connection separately.'
                : ($status === 'unreachable' ? 'Laravel cannot reach the chat service. Changing the browser Socket URL will not fix this server health check.'
                    : 'The chat service responded but is not ready.'),
            'next_steps' => $ready ? ['Connect using the public Socket.IO origin and path.'] : [
                'Check that Node.js is installed and the chat service is running.',
                'Check the internal service URL and Redis connectivity if Redis is configured.',
                'Once this check passes, verify the public WebSocket proxy and browser connection.',
            ],
        ], $ready ? 200 : 503)->header('Cache-Control', 'no-store');
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
        $examples = new DeveloperResponseExamples;
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
            $adminOnly = str_contains($middleware, 'RequireAdminApi');
            $signedIn = str_contains($middleware, 'RequireApiAuthentication');
            $optionalSignIn = str_contains($middleware, 'AttachOptionalApiUser');
            $access = match (true) {
                $adminOnly => 'Admin access required',
                $signedIn => 'Sign-in required',
                $optionalSignIn => 'Optional sign-in',
                default => 'Public',
            };
            if (in_array($path, ['/auth/register', '/auth/onboarding/complete'], true)) {
                $access = 'OTP verification required';
            }
            foreach ($methods as $method) {
                $routeKey = $method.' '.$path;
                $categories = config('api_catalog.route_groups')[$routeKey] ?? ['Common'];
                foreach ($categories as $category) {
                    foreach ($this->requestVariants($method, $path, $category) as $variant) {
                        $catalog[] = [
                            'id' => $routeKey.($variant['suffix'] ?? ''),
                            'routeKey' => $routeKey,
                            'method' => $method,
                            'path' => $path,
                            'category' => $category,
                            'section' => $adminOnly ? 'Admin only' : 'Application APIs',
                            'access' => $access,
                            'authMode' => $signedIn ? 'bearer' : ($optionalSignIn ? 'optional' : 'none'),
                            'label' => '',
                            'action' => Str::afterLast(str_replace('\\', '/', $action), '/'),
                            'parameters' => $this->routeParameters($path),
                            ...$this->requestExample($method, $path),
                            ...array_diff_key($variant, ['suffix' => true]),
                        ];
                    }
                }
            }
        }

        $categoryOrder = array_flip(config('api_catalog.groups'));
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

            if ($left['category'] === 'Auth') {
                $workflowComparison = ($authOrder[$left['routeKey']] ?? 99) <=> ($authOrder[$right['routeKey']] ?? 99);
                if ($workflowComparison !== 0) {
                    return $workflowComparison;
                }
            }

            if (in_array($left['category'], ['Teacher', 'Institute'], true)) {
                $sectionComparison = ($left['section'] === 'Feed / Posts') <=> ($right['section'] === 'Feed / Posts');
                if ($sectionComparison !== 0) {
                    return $sectionComparison;
                }
            }

            return [$left['section'] === 'Admin only', $left['path'], $left['method'], $left['id']]
                <=> [$right['section'] === 'Admin only', $right['path'], $right['method'], $right['id']];
        });

        foreach ($catalog as &$entry) {
            $entry['mockResponse'] = $examples->forEndpoint($entry);
        }
        unset($entry);

        return $catalog;
    }

    private function requestVariants(string $method, string $path, string $category): array
    {
        $key = $method.' '.$path;
        if (in_array($key, ['POST /auth/otp/request', 'POST /auth/otp/verify'], true)) {
            $body = $this->requestExample($method, $path)['bodyExample'];

            return [
                ['label' => 'Normal signup · phone OTP', 'bodyExample' => [...$body, 'authMethod' => 'phone_otp']],
                [
                    'suffix' => '::google',
                    'label' => 'Google signup · phone OTP',
                    'bodyExample' => [...$body, 'authMethod' => 'google'],
                    'authMode' => 'bearer',
                    'access' => 'Google-linked bearer required',
                    'guide' => $path === '/auth/otp/request'
                        ? 'Send the Google sign-in bearer with authMethod=google. The OTP is bound to that Google account.'
                        : 'Send the same Google bearer and authMethod=google. Save the NEW response token for register and all following calls; the user remains the same Google account.',
                ],
            ];
        }

        if (in_array($key, ['POST /auth/register', 'POST /auth/onboarding/complete'], true)) {
            $variants = [];
            $base = $this->requestExample($method, $path)['bodyExample'];
            foreach (['student', 'teacher', 'institute'] as $role) {
                $body = [...$base, 'accountType' => $role];
                if ($role !== 'student') {
                    $body['city'] = 'New Delhi';
                    if ($role === 'teacher') {
                        $body['primarySubjects'] = [1];
                        $body['latitude'] = 28.6139;
                        $body['longitude'] = 77.2090;
                        $body += ['district' => 'New Delhi', 'subDistrict' => 'Chanakyapuri',
                            'locality' => 'Ward 1', 'localityType' => 'ward', 'state' => 'Delhi',
                            'country' => 'India', 'postalCode' => '110001'];
                    } else {
                        $body['contactPerson'] = 'Anita Sharma';
                    }
                }
                $guide = $role === 'student'
                    ? 'Submit the form after OTP verification, then call Learner → POST /auth/date-of-birth. Registration stays pending until DOB is saved and required guardian consent is approved.'
                    : 'Submit the form after OTP verification. Teacher and institute registration does not require the DOB step.';
                foreach ([false, true] as $google) {
                    $variantBody = $body;
                    if ($google) {
                        unset($variantBody['email'], $variantBody['password']);
                    }
                    $variants[] = [
                        'suffix' => ($role === 'student' ? '' : '::'.$role).($google ? '::google' : ''),
                        'label' => ucfirst($role).' · '.($google ? 'Google signup' : 'normal signup'),
                        'bodyExample' => $variantBody,
                        'guide' => $guide.($google
                            ? ' Use the Google-flow OTP response bearer; Google supplies email, so email/password are optional.'
                            : ' Use the normal OTP response bearer; email and password are required.'),
                    ];
                }
            }

            return $variants;
        }

        $isProvider = in_array($category, ['Teacher', 'Institute'], true);
        $routeGroups = config('api_catalog.route_groups')[$key] ?? [];
        $roleCopy = $isProvider || ($category === 'Learner' && in_array('Common', $routeGroups, true));
        $variant = $roleCopy
            ? ['suffix' => '::'.strtolower($category), 'label' => $category.' account']
            : [];
        if ($isProvider && ($path === '/feed' || str_starts_with($path, '/feed/'))) {
            $variant['section'] = 'Feed / Posts';
        }
        $example = $this->requestExample($method, $path);

        if ($key === 'PUT /profiles/me' && $isProvider) {
            $body = $example['bodyExample'];
            if ($category === 'Institute') {
                $body['subjectIds'] = $body['primarySubjects'];
                unset($body['primarySubjects']);
                unset($body['bio'], $body['ratePerHour']);
                $body['description'] = 'Sign language institute offering beginner classes';
                $body['startingRate'] = 500;
            }
            $variant['bodyExample'] = $body;
            $variant['guide'] = 'Update the signed-in '.strtolower($category).' listing after registration is complete. The API URL is shared by both provider types.';
        } elseif ($key === 'GET /profiles/{type}/{id}') {
            $variant['label'] = $category.' · phone / WhatsApp / call contact';
            $variant['guide'] = 'Use a verified bearer to retrieve profile.phone for a teacher or institute (type path parameter). The app can open tel:<phone> or https://wa.me/<international-digits>. These links launch external apps; this API does not place calls or send WhatsApp messages. Anonymous responses omit phone. Reveal limit: 5 distinct provider profiles per day; hide contact actions when phone is absent. This lookup does not return learner phone numbers.';
        } elseif (in_array($key, ['GET /messages', 'GET /messages/{conversationId}', 'POST /messages/{conversationId}'], true)) {
            $variant['guide'] = trim(($example['guide'] ?? '').' Learner, Teacher and Institute accounts can start or reply to conversations. POST /messages/start accepts the other user ID as providerId and reuses an existing conversation in either direction. Sending requires safety acknowledgement and guardian consent when applicable.');
            if ($method === 'POST' && $isProvider) {
                $variant['bodyExample'] = ['body' => $category === 'Institute'
                    ? 'Thanks for contacting our institute. How can we help with your classes?'
                    : 'Thanks for your message. I can help with your lessons.'];
            }
        } elseif ($key === 'POST /feed') {
            $variant['label'] = ($category === 'Common' ? 'All roles' : $category).' · add post';
            $variant['guide'] = 'Learner, Teacher and Institute accounts can publish city posts, limited to one post per account every 7 days. The server assigns post_type=offer for teacher/institute accounts and request for learners.';
            if ($isProvider) {
                $variant['bodyExample'] = [...$example['bodyExample'], 'body' => $category === 'Institute'
                    ? 'Our institute offers beginner sign language classes in New Delhi.'
                    : 'I offer beginner sign language lessons online and in New Delhi.'];
            }
        } elseif ($key === 'POST /feed/{postId}/comments') {
            $variant['label'] = ($category === 'Common' ? 'All roles' : $category).' · comment on post';
            $variant['guide'] = 'Learner, Teacher and Institute accounts can comment on the same city-feed posts. To reply to a comment, select the reply example and provide a parentCommentId belonging to this post.';

            return [
                $variant,
                [
                    ...$variant,
                    'suffix' => ($variant['suffix'] ?? '').'::reply',
                    'label' => ($category === 'Common' ? 'All roles' : $category).' · reply to comment',
                    'bodyExample' => ['body' => 'Thanks for your comment. Here are the class details.', 'parentCommentId' => 5],
                    'guide' => 'Reply / re-comment using the SAME POST comments API. Replace parentCommentId with a real comment ID on this post. Teacher, Institute and Learner accounts are supported.',
                ],
            ];
        }

        return [$variant];
    }

    private function requestExample(string $method, string $path): array
    {
        foreach (config('chat_events') as $event => $contract) {
            if ($method === 'POST' && $path === '/chat/events/'.$contract['action']) {
                return ['bodyExample' => $contract['body'], 'queryExample' => '', 'guide' => $event.' · '.$contract['guide']];
            }
        }

        $examples = [
            'POST /auth/google' => [
                'body' => ['firebaseIdToken' => 'PASTE_FIREBASE_GOOGLE_ID_TOKEN'],
                'guide' => 'Step 1 · Google sign-in. Replace the token with a verified Firebase Google ID token. Learners aged 13–17 receive status=pending/reject/approve for parent consent; use nextScreen for routing.',
            ],
            'POST /auth/otp/request' => [
                'body' => ['phone' => '+919876543210'],
                'guide' => 'Normal signup: send authMethod=phone_otp without Authorization. In local test mode read otp from the response; otherwise use the delivered SMS code.',
            ],
            'PATCH /auth/link-phone' => [
                'body' => ['phone' => '+919876543210', 'code' => '123456'],
                'guide' => 'Alternative Google phone verification: send the Google bearer and the Google-flow phone/code pair. Save the NEW response token for subsequent requests.',
            ],
            'POST /auth/otp/verify' => [
                'body' => ['phone' => '+919876543210', 'code' => '123456'],
                'guide' => 'Normal signup: send authMethod=phone_otp without Authorization. Save the response token for Register. Incomplete registration can resume; completed accounts receive 409 loginRequired.',
            ],
            'POST /auth/login' => [
                'body' => ['email' => 'anita@example.com', 'password' => 'a-secure-password'],
                'guide' => 'Email/password login requires the saved form and DOB. Learners awaiting parent approval can log in, with profileCompleted=false, registrationCompleted=false and consent next-step flags. Missing form/DOB returns 409 REGISTRATION_INCOMPLETE. Google users sign in through /auth/google. Learners aged 13–17 receive status=pending/reject/approve for parent consent; adults, teachers and institutes omit this field.',
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
                'body' => ['conversationId' => 3],
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
                'guide' => 'Submit the full form after phone OTP verification. Students then save DOB through its separate API before registration is complete.',
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
            'POST /auth/parental-consent/confirm' => [
                'body' => ['token' => 'PASTE_ONE_TIME_GUARDIAN_TOKEN', 'decision' => 'approve'],
                'guide' => 'Public one-time approve/reject decision. Prefer the hosted parentalConsent.link page. The guardian-details response returns the link and parent phone for app-team delivery. Refresh /auth/session after the decision.',
            ],
            'GET /auth/parental-consent/status' => [
                'guide' => 'Authenticated learner consent status. After guardian-details, poll every 15–30 seconds while waiting or refresh on app foreground. Returns status=pending/reject/approve plus detailed parentalConsentStatus, nextScreen, parent details and decision/expiry timestamps. Adult learners return not_required. Missing DOB returns 409 DOB_REQUIRED. Does not create or replace links; tokens and link hashes are never returned.',
            ],
            'POST /auth/guardian-details' => [
                'body' => ['guardianName' => 'Example Parent', 'guardianContact' => '+919876543211'],
                'guide' => 'After minor DOB returns guardianDetailsRequired, submit the parent form here. Returns parentalConsent.link, expiry and parent name/phone for app-team delivery. Each submission creates a new one-time request.',
            ],
            'POST /auth/account-type' => ['body' => ['accountType' => 'learner']],
            'PATCH /auth/preferred-language' => ['body' => ['language' => 'en']],
            'PATCH /auth/avatar' => ['body' => ['avatarUrl' => 'https://example.com/avatar.jpg']],
            'PATCH /auth/link-google' => ['body' => ['firebaseIdToken' => 'PASTE_FIREBASE_GOOGLE_ID_TOKEN']],
            'POST /auth/date-of-birth' => [
                'body' => ['dateOfBirth' => '1998-06-15'],
                'guide' => 'Submit dateOfBirth only. Under 13 is rejected. Ages 13–17 return guardianDetailsRequired and nextScreen=guardian_details; open the parent form and submit it to POST /auth/guardian-details. Adults do not need this form. Learner completion stays false until DOB and required parent approval are complete.',
            ],
            'POST /auth/acknowledge-safety-disclaimer' => ['body' => []],
            'POST /location-lookup/reverse' => [
                'body' => ['latitude' => 28.6139, 'longitude' => 77.2090],
                'guide' => 'Reverse-geocode coordinates using OpenStreetMap. Limited to one lookup per second and 30 requests per minute per IP.',
            ],
            'PUT /profiles/me' => [
                'body' => ['bio' => 'Experienced teacher offering online lessons', 'ratePerHour' => 500, 'currency' => 'INR', 'lessonModes' => ['online'], 'city' => 'New Delhi', 'country' => 'India', 'countryCode' => 'IN', 'primarySubjects' => [1]],
                'guide' => 'Teacher example. For an institute account, replace bio/ratePerHour with description/startingRate.',
            ],
            'PATCH /profiles/{type}/{id}/verify' => ['body' => ['verified' => true], 'pathDefaults' => ['type' => 'teacher', 'id' => 1]],
            'PATCH /profiles/institute/{id}/type' => ['body' => ['institute_type' => 'independent'], 'pathDefaults' => ['id' => 1]],
            'PATCH /profiles/institute/{id}/bsl-verified' => ['body' => ['verified' => false], 'pathDefaults' => ['id' => 1]],
            'GET /profiles/{type}/{id}' => ['pathDefaults' => ['type' => 'teacher', 'id' => 1]],
            'GET /profiles/me/stats' => ['query' => 'days=30'],
            'POST /bookings' => [
                'body' => ['providerId' => 1, 'mode' => 'online', 'requestedTime' => '2030-01-15T10:00:00Z', 'subjectId' => 1, 'price' => 500],
                'guide' => 'Requires an existing teacher/institute ID, a valid subject ID, safety acknowledgement, and guardian consent for minors.',
            ],
            'PATCH /bookings/{id}/status' => ['body' => ['status' => 'accepted'], 'pathDefaults' => ['id' => 2]],
            'POST /messages/start' => [
                'body' => ['providerId' => 1, 'body' => 'Hello, I would like to ask about your lessons.'],
                'guide' => 'Any account can start a conversation. Set providerId to the other user ID (learner, teacher or institute). Starting from either side reuses the same conversation.',
            ],
            'POST /messages/{conversationId}' => ['body' => ['body' => 'Hello, thanks for getting back to me.'], 'pathDefaults' => ['conversationId' => 3]],
            'GET /messages/{conversationId}' => ['pathDefaults' => ['conversationId' => 3]],
            'GET /broadcasting/auth' => ['query' => 'socket_id=123456.789012&channel_name=private-conversation.3', 'guide' => 'Laravel broadcasting compatibility endpoint; bearer and conversation membership required. Socket.IO uses POST /chat/socket/authorize.'],
            'POST /broadcasting/auth' => ['body' => ['socket_id' => '123456.789012', 'channel_name' => 'private-conversation.3'], 'guide' => 'Laravel broadcasting compatibility endpoint; bearer and conversation membership required. Socket.IO uses POST /chat/socket/authorize.'],
            'POST /reviews' => ['body' => ['bookingId' => 2, 'rating' => 5, 'comment' => 'Great lesson.']],
            'GET /feed' => ['query' => 'city=New%20Delhi'],
            'POST /feed' => ['body' => ['city' => 'New Delhi', 'body' => 'Looking for a BSL teacher for beginner classes.', 'subjectId' => 1]],
            'POST /feed/{postId}/comments' => ['body' => ['body' => 'Thanks for sharing.'], 'pathDefaults' => ['postId' => 4]],
            'POST /safety/reports' => ['body' => ['targetType' => 'user', 'targetId' => 1, 'reasonCategory' => 'fake_profile', 'reason' => 'Example report reason.']],
            'POST /presence/update' => [
                'body' => ['deviceId' => 'app-device-unique-id', 'event' => 'foreground'],
                'guide' => 'Database-backed presence for this authenticated device; no Redis required. Send foreground/activity/heartbeat/background. Heartbeat every 30 seconds; foreground/activity reset the five-minute idle timer. Background removes the device immediately; a missing heartbeat expires it in 90 seconds. Only current state is retained; the Laravel scheduler prunes expired rows in bounded batches.',
            ],
            'POST /suggestions' => ['body' => ['suggestionType' => 'course', 'name' => 'Beginner Sign Language', 'notes' => 'Example suggestion.']],
            'POST /support' => ['body' => ['subject' => 'Example support request', 'message' => 'Please describe your support question here.']],
            'POST /franchise/enquiries' => ['body' => ['name' => 'Asha Learner', 'phone' => '+919876543210', 'location' => 'Lucknow, Alambagh'],
                'guide' => 'Submit a BSL franchise enquiry. Bearer authentication and a verified account phone are required. Send the contact phone with its country code. Returns 201; enquiries are visible only in admin reports. Limit: 5 submissions per minute.'],
            'POST /jobs/applications' => ['body' => ['name' => 'Asha Learner', 'phone' => '+919876543210', 'expectedSalary' => 25000, 'courseIds' => [1]],
                'guide' => 'Submit a BSL teaching job enquiry. Use 1–5 distinct course IDs from GET /subjects bslFeatured. expectedSalary is INR per month, positive, up to 99999999.99 with at most two decimals. Bearer authentication and a verified account phone are required. Returns 201; applications are visible only in admin reports. Limit: 5 submissions per minute.'],
            'POST /referrals/redeem' => ['body' => ['code' => 'PASTE_REFERRAL_CODE']],
            'POST /analytics/page-view' => ['body' => ['pageType' => 'teacher', 'target' => 'teacher-profile', 'platform' => 'web', 'referrer' => 'developer-portal']],
            'PATCH /safety/reports/{id}/status' => ['body' => ['status' => 'reviewed'], 'pathDefaults' => ['id' => 5]],
            'PATCH /suggestions/{id}/status' => ['body' => ['status' => 'added'], 'pathDefaults' => ['id' => 6]],
            'PATCH /support/{id}/status' => ['body' => ['status' => 'in_progress'], 'pathDefaults' => ['id' => 7]],
            'PATCH /moderation/photos/{userId}' => ['body' => ['status' => 'approved'], 'pathDefaults' => ['userId' => 1]],
            'POST /moderation/users/{userId}/suspend' => ['body' => ['reason' => 'Example moderation reason.'], 'pathDefaults' => ['userId' => 1]],
            'POST /moderation/users/{userId}/unsuspend' => ['body' => [], 'pathDefaults' => ['userId' => 1]],
            'POST /safety/blocks/{userId}' => ['body' => [], 'pathDefaults' => ['userId' => 1]],
            'DELETE /safety/blocks/{userId}' => ['pathDefaults' => ['userId' => 1]],
            'GET /safety/reports' => ['query' => 'status=open'],
            'GET /suggestions' => ['query' => 'status=open'],
            'GET /support' => ['query' => 'status=open'],
            'GET /analytics/summary' => ['query' => 'days=30'],
            'GET /i18n/{lang}' => ['pathDefaults' => ['lang' => 'en']],
            'GET /reviews/for/{providerId}' => ['pathDefaults' => ['providerId' => 1]],
            'GET /feed/{postId}/comments' => ['pathDefaults' => ['postId' => 4]],
            'GET /moderation/conversations/{id}/messages' => ['pathDefaults' => ['id' => 3]],
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
            $example['pathDefaults'] = ['subjectId' => 1];
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
                'subjectId' => 1,
                default => '1',
            };
        }

        return [
            'bodyExample' => $example['body'] ?? null,
            'queryExample' => $example['query'] ?? '',
            'pathDefaults' => $pathDefaults,
            'guide' => $example['guide'] ?? null,
        ];
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
