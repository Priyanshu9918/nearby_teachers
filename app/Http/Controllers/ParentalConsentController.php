<?php

namespace App\Http\Controllers;

use App\Services\ParentalConsent;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class ParentalConsentController extends Controller
{
    public function show(string $token, ParentalConsent $consent): Response
    {
        $user = $consent->find($token);

        return $this->page(['learnerName' => $user->full_name, 'guardianName' => $user->guardian_name, 'token' => $token]);
    }

    public function decide(Request $request, string $token, ParentalConsent $consent): Response
    {
        $data = $request->validate(['decision' => ['required', 'in:approve,reject']]);
        $status = $consent->decide($token, $data['decision']);

        return $this->page(['status' => $status]);
    }

    private function page(array $data): Response
    {
        return response()->view('parental-consent', $data)
            ->header('Cache-Control', 'no-store, private')
            ->header('Referrer-Policy', 'no-referrer')
            ->header('X-Robots-Tag', 'noindex, nofollow')
            ->header('X-Frame-Options', 'DENY')
            ->header('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
    }
}
