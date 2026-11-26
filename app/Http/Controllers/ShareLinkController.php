<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ShareLinkController extends Controller
{
    public function api(): Response
    {
        return response()->json([
            'shareUrl' => config('services.nearby_teacher.share_url'),
        ]);
    }

    public function show(Request $request): Response
    {
        return response()->view('site.share-download', [
            'androidUrl' => config('services.nearby_teacher.android_play_store_url'),
            'iosUrl' => config('services.nearby_teacher.ios_app_store_url'),
        ])->header('X-Robots-Tag', 'noindex, nofollow');
    }

    public function androidAssociation(): Response
    {
        return response()->json([
            [
                'relation' => ['delegate_permission/common.handle_all_urls'],
                'target' => [
                    'namespace' => 'android_app',
                    'package_name' => 'com.bsl_nearbyteacher',
                    'sha256_cert_fingerprints' => [
                        '3D:7A:3A:FA:DA:48:C1:B4:66:27:BB:E4:32:31:78:B1:DB:2E:6B:E2:3E:77:26:FC:7F:EC:CF:55:38:55:A1:7D',
                        'C7:A3:B8:A7:D7:15:8A:C7:70:B9:0B:64:F0:F8:B0:AC:32:BA:CF:A9:D5:FF:F8:7E:37:0C:89:28:50:56:17:35',
                    ],
                ],
            ],
        ])->header('Cache-Control', 'public, max-age=3600');
    }

    public function iosAssociation(): Response
    {
        return response()->json([
            'applinks' => [
                'apps' => [],
                'details' => [[
                    'appID' => '6A5V7Y4R6A.com.bsl.nearbyteacher',
                    'paths' => ['/share', '/share/', '/share/teacher/*', '/share/city-feed/*'],
                ]],
            ],
        ])->header('Content-Type', 'application/json')
            ->header('Cache-Control', 'public, max-age=3600');
    }
}
