<?php

namespace App\Support;

/** Saved fictional examples only: never call an API or read application records. */
class DeveloperResponseExamples
{
    private array $requests = [];

    public function __construct()
    {
        $collection = json_decode(file_get_contents(base_path('docs/postman/app-team.postman_collection.json')), true, flags: JSON_THROW_ON_ERROR);
        foreach ($collection['item'] as $folder) {
            $this->collect($folder['item'], $folder['name']);
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
            $signature = $request['method'].' '.preg_replace('/\{\{[^}]+\}\}/', '{}', $path);
            $this->requests[$category][$signature][] = $item;
        }
    }

    public function forEndpoint(array $endpoint): ?array
    {
        // Both routes invoke the same registration completion action.
        $path = $endpoint['path'] === '/auth/onboarding/complete' ? '/auth/register' : $endpoint['path'];
        $signature = $endpoint['method'].' '.preg_replace('/\{[^}]+\}/', '{}', $path);
        // Conversation start returns the same shape for every account type.
        $category = $signature === 'POST /messages/start' ? 'Learner' : $endpoint['category'];
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

        return ['status' => $best['code'], 'contentType' => $contentType, 'body' => $best['body'] ?? ''];
    }
}
