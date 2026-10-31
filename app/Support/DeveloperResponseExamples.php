<?php

namespace App\Support;

/** Saved fictional examples only: never call an API or read application records. */
class DeveloperResponseExamples
{
    private array $requests = [];

    public function __construct()
    {
        foreach ([
            'docs/postman/app-team.postman_collection.json',
            'docs/postman/teacher-profile-geography.postman_collection.json',
            'docs/postman/student-profile.postman_collection.json',
        ] as $file) {
            $collection = json_decode(file_get_contents(base_path($file)), true, flags: JSON_THROW_ON_ERROR);
            foreach ($collection['item'] as $folder) {
                $this->collect($folder['item'], $folder['name']);
            }
        }
    }

    private function collect(array $items, string $category): void
    {
        foreach ($items as $item) {
            if (isset($item['item'])) {
                $this->collect($item['item'], $category);

                continue;
            }
            $request = $item['request'];
            $path = explode('?', $request['url']['raw'])[0];
            $path = substr($path, strlen('{{baseUrl}}'));
            $signature = $this->signature($request['method'], preg_replace('/\{\{[^}]+\}\}/', '{}', $path));
            $this->requests[$category][$signature][] = $item;
        }
    }

    public function forEndpoint(array $endpoint): ?array
    {
        // Both routes invoke the same registration completion action.
        $path = $endpoint['path'] === '/auth/onboarding/complete' ? '/auth/register' : $endpoint['path'];
        $signature = $this->signature($endpoint['method'], $path);
        // Conversation start returns the same shape for every account type.
        $category = match (true) {
            $signature === 'POST /messages/start' => 'Learner',
            in_array($signature, ['POST /franchise/enquiries', 'POST /jobs/applications'], true) => 'Common',
            default => $endpoint['category'],
        };
        $categories = match ($category) {
            'Learner' => ['Learner', 'Student'],
            'Teacher' => ['Teacher', 'Teacher Profile'],
            default => [$category],
        };
        $candidates = [];
        foreach ($categories as $source) {
            array_push($candidates, ...($this->requests[$source][$signature] ?? []));
        }
        $best = null;
        $bestScore = PHP_INT_MIN;
        foreach ($candidates as $candidate) {
            $body = json_decode($candidate['request']['body']['raw'] ?? '{}', true, flags: JSON_THROW_ON_ERROR);
            $expected = $endpoint['bodyExample'] ?? [];
            $score = 0;
            foreach (['authMethod' => 20, 'accountType' => 100] as $key => $penalty) {
                if (isset($body[$key], $expected[$key]) && $body[$key] !== $expected[$key]) {
                    $score -= $penalty;
                }
            }
            // These fields distinguish signup methods, roles and comment replies.
            foreach (['authMethod', 'accountType', 'parentCommentId', 'email', 'password'] as $key) {
                if (array_key_exists($key, $body) === array_key_exists($key, $expected)) {
                    $score++;
                    if (isset($body[$key], $expected[$key]) && $body[$key] === $expected[$key]) {
                        $score++;
                    }
                }
            }
            foreach (array_keys($expected) as $key) {
                if (array_key_exists($key, $body)) {
                    $score += 10;
                    if (($body[$key] ?? null) === $expected[$key]) {
                        $score += 2;
                    }
                }
            }
            if ($score > $bestScore && ! empty($candidate['response'])) {
                $best = $candidate['response'][0];
                $bestScore = $score;
            }
        }
        if ($best === null) {
            return $this->documentedFallback($signature);
        }

        $contentType = 'application/json';
        foreach ($best['header'] ?? [] as $header) {
            if (strcasecmp($header['key'], 'Content-Type') === 0) {
                $contentType = $header['value'];
            }
        }

        $body = $best['body'] ?? '';
        if (str_contains($contentType, 'json') && $body !== '') {
            $preserveTicketId = str_starts_with($signature, 'POST /support')
                || str_starts_with($signature, 'GET /support')
                || str_starts_with($signature, 'PATCH /support/');
            $body = json_encode($this->numericExamples(json_decode($body, true, flags: JSON_THROW_ON_ERROR), '', $preserveTicketId), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        }

        return ['status' => $best['code'], 'contentType' => $contentType, 'body' => $body];
    }

    private function signature(string $method, string $path): string
    {
        $path = preg_replace('/\{[^}]+\}/', '{}', $path);
        $path = preg_replace('#^(/(?:teacher/profile|profiles/me)/steps/)\d+$#', '$1{}', $path);

        return $method.' '.$path;
    }

    private function numericExamples(mixed $value, string $key = '', bool $preserveId = false): mixed
    {
        if (is_array($value)) {
            foreach ($value as $name => $child) {
                $value[$name] = $this->numericExamples($child, (string) $name, $preserveId);
            }
        } elseif (is_string($value) && ! ($preserveId && $key === 'id') && preg_match('/(?:^id$|_id$|Id$)/', $key)
            && preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/iD', $value)) {
            // Saved documentation examples are immutable; adapt their record IDs for the portal.
            $value = (int) hexdec(substr($value, -6)) ?: 1;
        }

        return $value;
    }

    private function documentedFallback(string $signature): ?array
    {
        $contact = [
            'whatsappNumber' => '+919876543210', 'whatsappUrl' => 'https://wa.me/919876543210',
            'email' => 'support@example.test', 'linkedinUrl' => 'https://www.linkedin.com/in/example-profile',
            'linkedinName' => 'Example Contact', 'linkedinTitle' => 'Executive Director',
            'updatedAt' => '2026-10-31 10:00:00',
        ];
        $example = match ($signature) {
            'GET /contact-info', 'GET /admin/contact-info', 'PUT /admin/contact-info' => [200, ['contact' => $contact]],
            'POST /feed/socket/authorize' => [200, ['userId' => 1, 'postId' => 1, 'room' => 'feed-post.1', 'expiresInSeconds' => 900, 'authVersion' => 0]],
            'POST /reports' => [201, ['report' => [
                'id' => 1, 'reporterId' => 1, 'targetType' => null, 'targetId' => null,
                'reasonCategory' => 'fake_profile', 'additionalDetails' => 'Example report details.',
                'evidenceLink' => null, 'screenshotAttached' => false, 'status' => 'open',
                'createdAt' => '2026-10-31 10:00:00',
            ]]],
            default => null,
        };

        return $example === null ? null : [
            'status' => $example[0], 'contentType' => 'application/json',
            'body' => json_encode($example[1], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ];
    }
}
