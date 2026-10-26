<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ApiCatalogTest extends TestCase
{
    public function test_every_catalog_entry_has_a_saved_mock_response_and_signup_variants_match(): void
    {
        $catalog = $this->withSession(['developer_authenticated' => true])->get('/developer')
            ->assertOk()->viewData('catalog');
        foreach ($catalog as $entry) {
            $this->assertNotNull($entry['mockResponse'], $entry['id']);
            $this->assertGreaterThanOrEqual(200, $entry['mockResponse']['status']);
            $this->assertLessThan(300, $entry['mockResponse']['status']);
            $this->assertNotSame('', $entry['mockResponse']['body']);
        }
        $entries = array_column($catalog, null, 'id');
        foreach (['student', 'teacher', 'institute'] as $role) {
            $key = 'POST /auth/register'.($role === 'student' ? '' : '::'.$role);
            foreach (['', '::google'] as $suffix) {
                $mock = json_decode($entries[$key.$suffix]['mockResponse']['body'], true, flags: JSON_THROW_ON_ERROR);
                $this->assertSame($role === 'student' ? 'learner' : $role, $mock['user']['account_type']);
            }
        }
        $normal = $entries['POST /auth/otp/verify']['mockResponse']['body'];
        $google = $entries['POST /auth/otp/verify::google']['mockResponse']['body'];
        $this->assertNotSame($normal, $google);
    }

    public function test_developer_catalog_covers_registered_routes_in_five_workflow_groups(): void
    {
        $response = $this->withSession(['developer_authenticated' => true])->get('/developer')->assertOk();
        $groups = ['Auth', 'Learner', 'Teacher', 'Institute', 'Common'];
        $response->assertViewHas('categories', $groups);
        $catalog = $response->viewData('catalog');
        $routeGroups = config('api_catalog.route_groups');
        $registered = [];

        foreach (Route::getRoutes() as $route) {
            $path = '/'.ltrim($route->uri(), '/');
            if (! str_starts_with($route->getActionName(), 'App\\Http\\Controllers\\Api\\')
                && ! in_array('api', $route->gatherMiddleware(), true)
                && ! in_array($path, ['/health', '/up', '/location-lookup/reverse', '/broadcasting/auth'], true)) {
                continue;
            }
            foreach (array_intersect(['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], $route->methods()) as $method) {
                $registered[] = $method.' '.$path;
            }
        }

        $this->assertEqualsCanonicalizing($registered, array_keys($routeGroups));
        $this->assertEqualsCanonicalizing($registered, array_values(array_unique(array_column($catalog, 'routeKey'))));
        $this->assertCount(count($catalog), array_unique(array_column($catalog, 'id')));
        $this->assertSame($groups, array_values(array_unique(array_column($catalog, 'category'))));
        foreach ($routeGroups as $key => $expectedGroups) {
            $actualGroups = array_unique(array_column(array_filter($catalog, fn ($entry) => $entry['routeKey'] === $key), 'category'));
            $this->assertEqualsCanonicalizing($expectedGroups, array_values($actualGroups), $key);
        }
        foreach ($catalog as $entry) {
            if ($entry['access'] === 'Admin access required') {
                $this->assertSame('Common', $entry['category']);
                $this->assertSame('Admin only', $entry['section']);
                $this->assertSame('bearer', $entry['authMode']);
            }
        }
    }

    public function test_developer_examples_distinguish_google_and_normal_signup_and_provider_fields(): void
    {
        $catalog = $this->withSession(['developer_authenticated' => true])->get('/developer')
            ->assertOk()->viewData('catalog');
        $entries = array_column($catalog, null, 'id');

        foreach (['request', 'verify'] as $action) {
            $key = 'POST /auth/otp/'.$action;
            $this->assertSame('none', $entries[$key]['authMode']);
            $this->assertSame('phone_otp', $entries[$key]['bodyExample']['authMethod']);
            $this->assertSame('bearer', $entries[$key.'::google']['authMode']);
            $this->assertSame('google', $entries[$key.'::google']['bodyExample']['authMethod']);
        }
        foreach (['student', 'teacher', 'institute'] as $role) {
            $key = 'POST /auth/register'.($role === 'student' ? '' : '::'.$role);
            $normal = $entries[$key]['bodyExample'];
            $google = $entries[$key.'::google']['bodyExample'];
            $this->assertSame($role, $normal['accountType']);
            $this->assertArrayHasKey('email', $normal);
            $this->assertArrayHasKey('password', $normal);
            $this->assertArrayNotHasKey('email', $google);
            $this->assertArrayNotHasKey('password', $google);
            $this->assertArrayNotHasKey('dateOfBirth', $normal);
            $this->assertArrayNotHasKey('dateOfBirth', $google);
        }
        $this->assertArrayHasKey('primarySubjectId', $entries['POST /auth/register::teacher']['bodyExample']);
        $this->assertArrayHasKey('contactPerson', $entries['POST /auth/register::institute']['bodyExample']);
        $teacher = $entries['PUT /profiles/me::teacher']['bodyExample'];
        $institute = $entries['PUT /profiles/me::institute']['bodyExample'];
        $this->assertArrayHasKey('bio', $teacher);
        $this->assertArrayHasKey('ratePerHour', $teacher);
        $this->assertArrayNotHasKey('description', $teacher);
        $this->assertArrayHasKey('description', $institute);
        $this->assertArrayHasKey('startingRate', $institute);
        $this->assertArrayNotHasKey('bio', $institute);
        $this->assertSame('Learner', $entries['POST /auth/date-of-birth']['category']);
    }

    public function test_existing_postman_collection_covers_every_group_and_has_valid_safe_examples(): void
    {
        $collection = json_decode(file_get_contents(base_path('docs/postman/app-team.postman_collection.json')), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(config('api_catalog.groups'), array_column($collection['item'], 'name'));
        $variables = array_column($collection['variable'], 'value', 'key');
        $this->assertSame('http://157.245.125.79/nearby_teachers/public', $variables['baseUrl']);
        $routes = config('api_catalog.route_groups');
        $bySignature = [];
        foreach ($routes as $key => $groups) {
            $bySignature[preg_replace('/\{[^}]+\}/', '{}', $key)] = $key;
        }
        $coverage = [];
        foreach ($collection['item'] as $folder) {
            foreach ($this->collectionRequests($folder['item']) as $item) {
                $request = $item['request'];
                $rawPath = explode('?', $request['url']['raw'])[0];
                $this->assertStringStartsWith('{{baseUrl}}/', $rawPath, $item['name']);
                $signature = $request['method'].' '.preg_replace('/\{\{[^}]+\}\}/', '{}', substr($rawPath, strlen('{{baseUrl}}')));
                $this->assertArrayHasKey($signature, $bySignature, $item['name']);
                $key = $bySignature[$signature];
                $this->assertContains($folder['name'], $routes[$key], $item['name']);
                $coverage[$key][$folder['name']] = true;
                $raw = $request['body']['raw'] ?? null;
                if (is_string($raw) && $raw !== '') {
                    $payload = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
                    if (in_array($key, ['POST /auth/otp/request', 'POST /auth/otp/verify'], true)) {
                        $auth = $request['auth']['type'] ?? $collection['auth']['type'];
                        $this->assertSame($payload['authMethod'] === 'google' ? 'bearer' : 'noauth', $auth);
                    }
                }
                foreach ($item['response'] ?? [] as $example) {
                    if (! empty($example['originalRequest']['body']['raw'])) {
                        json_decode($example['originalRequest']['body']['raw'], true, flags: JSON_THROW_ON_ERROR);
                    }
                    $body = $example['body'] ?? '';
                    if ($body !== '' && str_starts_with(ltrim($body), '{')) {
                        json_decode($body, true, flags: JSON_THROW_ON_ERROR);
                    }
                    $this->assertDoesNotMatchRegularExpression('/"(?:password_hash|parental_consent_token)"\s*:/', $body, $item['name']);
                }
            }
        }
        $this->assertEqualsCanonicalizing(array_keys($routes), array_keys($coverage));
        foreach ($routes as $key => $groups) {
            // This saved collection predates role-independent conversation starts.
            if ($key === 'POST /messages/start') {
                $groups = ['Learner'];
            }
            $this->assertEqualsCanonicalizing($groups, array_keys($coverage[$key]), $key);
        }
    }

    public function test_each_role_category_exposes_chat_posts_comment_replies_and_provider_contact(): void
    {
        $catalog = $this->withSession(['developer_authenticated' => true])->get('/developer')
            ->assertOk()->viewData('catalog');
        $capabilities = [
            'POST /messages/start', 'GET /messages', 'GET /messages/{conversationId}', 'POST /messages/{conversationId}',
            'POST /chat/socket/authorize', 'GET /feed', 'POST /feed',
            'POST /feed/{postId}/like', 'DELETE /feed/{postId}/like',
            'GET /feed/{postId}/comments', 'POST /feed/{postId}/comments',
            'GET /profiles/{type}/{id}',
        ];
        foreach (['Learner', 'Teacher', 'Institute'] as $role) {
            $entries = array_values(array_filter($catalog, fn ($entry) => $entry['category'] === $role));
            foreach ($capabilities as $key) {
                $this->assertContains($key, array_column($entries, 'routeKey'), $role.': '.$key);
            }
            $replies = array_values(array_filter($entries, fn ($entry) => $entry['routeKey'] === 'POST /feed/{postId}/comments'
                && isset($entry['bodyExample']['parentCommentId'])));
            $this->assertCount(1, $replies, $role);
            $this->assertSame('bearer', $replies[0]['authMode']);
            $contact = array_values(array_filter($entries, fn ($entry) => $entry['routeKey'] === 'GET /profiles/{type}/{id}'))[0];
            $this->assertSame('optional', $contact['authMode']);
            $this->assertStringContainsString('profile.phone', $contact['guide']);
            $this->assertStringContainsString('tel:', $contact['guide']);
            $this->assertStringContainsString('wa.me/', $contact['guide']);
            if ($role !== 'Learner') {
                $post = array_values(array_filter($entries, fn ($entry) => $entry['routeKey'] === 'POST /feed'))[0];
                $this->assertStringContainsString('post_type=offer', $post['guide']);
                $this->assertStringNotContainsString('Looking for', $post['bodyExample']['body']);
            }
        }
    }

    public function test_frozen_api_guides_keep_valid_groups_for_published_operations(): void
    {
        $groups = config('api_catalog.groups');
        $routes = config('api_catalog.route_groups');
        foreach (['docs/API.md', 'docs/APP_TEAM_API.md'] as $file) {
            $guide = file_get_contents(base_path($file));
            preg_match_all('/^## (.+)$/m', $guide, $headings);
            $this->assertSame($groups, $headings[1], $file);
            $coverage = [];
            $section = null;
            foreach (explode("\n", $guide) as $line) {
                if (preg_match('/^## (.+)$/', $line, $heading)) {
                    $section = $heading[1];
                }
                if (preg_match('/^\| `(GET|POST|PUT|PATCH|DELETE)` \| `([^`]+)` \|/', $line, $row)) {
                    $key = $row[1].' '.$row[2];
                    if (isset($routes[$key])) {
                        $coverage[$key][$section] = true;
                    }
                }
            }
            // Owner freezes Markdown guides; runtime catalog and Postman above must
            // still cover every route. Validate existing published rows here.
            $this->assertNotEmpty($coverage, $file);
            foreach ($coverage as $key => $publishedGroups) {
                $expectedGroups = $routes[$key];
                // Published guides stay unchanged per owner request; runtime groups are checked above.
                if ($key === 'POST /messages/start') {
                    $expectedGroups = ['Learner'];
                }
                $this->assertEqualsCanonicalizing($expectedGroups, array_keys($coverage[$key]), $file.': '.$key);
            }
        }
    }

    public function test_postman_has_explicit_comment_replies_and_provider_offer_and_contact_examples(): void
    {
        $collection = json_decode(file_get_contents(base_path('docs/postman/app-team.postman_collection.json')), true, flags: JSON_THROW_ON_ERROR);
        foreach ($collection['item'] as $folder) {
            if ($folder['name'] === 'Auth') {
                continue;
            }
            $replyExamples = 0;
            $offerExamples = 0;
            $contactExamples = 0;
            foreach ($this->collectionRequests($folder['item']) as $item) {
                $request = $item['request'];
                $path = explode('?', $request['url']['raw'])[0];
                $body = isset($request['body']['raw']) ? json_decode($request['body']['raw'], true, flags: JSON_THROW_ON_ERROR) : [];
                if ($request['method'] === 'POST' && str_ends_with($path, '/comments') && ! empty($body['parentCommentId'])) {
                    $replyExamples++;
                }
                if (in_array($folder['name'], ['Teacher', 'Institute'], true)) {
                    $this->assertNotSame('{{baseUrl}}/messages/start', $path);
                    if ($request['method'] === 'POST' && $path === '{{baseUrl}}/feed') {
                        foreach ($item['response'] ?? [] as $example) {
                            $response = json_decode($example['body'], true, flags: JSON_THROW_ON_ERROR);
                            if (isset($response['post'])) {
                                $this->assertSame('offer', $response['post']['post_type']);
                                $offerExamples++;
                            }
                        }
                    }
                    if ($request['method'] === 'GET' && $path === '{{baseUrl}}/profiles/{{profileType}}/{{providerId}}'
                        && ($request['auth']['type'] ?? $collection['auth']['type']) === 'bearer') {
                        foreach ($item['response'] ?? [] as $example) {
                            $response = json_decode($example['body'], true, flags: JSON_THROW_ON_ERROR);
                            if (! empty($response['profile']['phone'])) {
                                $contactExamples++;
                            }
                        }
                    }
                }
            }
            $this->assertGreaterThan(0, $replyExamples, $folder['name'].' comment reply');
            if (in_array($folder['name'], ['Teacher', 'Institute'], true)) {
                $this->assertGreaterThan(0, $offerExamples, $folder['name'].' offer response');
                $this->assertGreaterThan(0, $contactExamples, $folder['name'].' phone contact response');
            }
        }
    }

    private function collectionRequests(array $items): iterable
    {
        foreach ($items as $item) {
            if (isset($item['request'])) {
                yield $item;
            } else {
                yield from $this->collectionRequests($item['item'] ?? []);
            }
        }
    }
}
