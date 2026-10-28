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
            $endpoint['category'] === 'Teacher Profile' => 'Teacher',
            default => $endpoint['category'],
        };
        $candidates = $this->requests[$category][$signature] ?? [];
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
            return null;
        }

        $contentType = 'application/json';
        foreach ($best['header'] ?? [] as $header) {
            if (strcasecmp($header['key'], 'Content-Type') === 0) {
                $contentType = $header['value'];
            }
        }

        $body = $best['body'] ?? '';
        if (str_contains($contentType, 'json') && $body !== '') {
            $body = json_encode($this->numericExamples(json_decode($body, true, flags: JSON_THROW_ON_ERROR)), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        }

        return ['status' => $best['code'], 'contentType' => $contentType, 'body' => $body];
    }

    private function signature(string $method, string $path): string
    {
        $path = preg_replace('/\{[^}]+\}/', '{}', $path);
        $path = preg_replace('#^(/teacher/profile/steps/)\d+$#', '$1{}', $path);

        return $method.' '.$path;
    }

    private function numericExamples(mixed $value, string $key = ''): mixed
    {
        if (is_array($value)) {
            foreach ($value as $name => $child) {
                $value[$name] = $this->numericExamples($child, (string) $name);
            }
        } elseif (is_string($value) && preg_match('/(?:^id$|_id$|Id$)/', $key)
            && preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/iD', $value)) {
            // Saved documentation examples are immutable; adapt their record IDs for the portal.
            $value = (int) hexdec(substr($value, -6)) ?: 1;
        }

        return $value;
    }
}
