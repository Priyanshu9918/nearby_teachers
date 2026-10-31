<?php

namespace App\Http\Middleware;

use App\Support\PublicMediaUrl;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class NormalizePublicMediaUrls
{
    private const URL_FIELDS = ['avatar_url', 'author_avatar', 'other_avatar', 'profileImage', 'avatarUrl'];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        if (! $response instanceof JsonResponse) {
            return $response;
        }

        $data = $response->getData(true);
        if (is_array($data)) {
            $response->setData($this->normalize($data));
        }

        return $response;
    }

    private function normalize(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($value) && in_array($key, self::URL_FIELDS, true)) {
                $data[$key] = PublicMediaUrl::resolve($value);
            } elseif (is_array($value)) {
                $data[$key] = $this->normalize($value);
            }
        }

        return $data;
    }
}
