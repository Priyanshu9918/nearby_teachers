<?php

namespace App\Http\Middleware;

use App\Support\PublicLanguages;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class PersistPublicLanguage
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $language = $request->query('lang');
        if (PublicLanguages::supported($language) && $response->getStatusCode() < 400) {
            // The web EncryptCookies middleware encrypts this on the way out.
            // Writing a raw cookie from JavaScript would invalidate it next time.
            $response->headers->setCookie(cookie('nt_lang', $language, 60 * 24 * 365, '/', null, $request->isSecure(), true, false, 'lax'));
        }

        return $response;
    }
}
