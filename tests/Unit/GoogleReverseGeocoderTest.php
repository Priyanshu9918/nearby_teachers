<?php

namespace Tests\Unit;

use App\Services\GoogleReverseGeocoder;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GoogleReverseGeocoderTest extends TestCase
{
    public function test_provider_errors_and_malformed_results_never_echo_error_text_or_secrets(): void
    {
        config(['services.google_geocoding.enabled' => true, 'services.google_geocoding.key' => 'private-test-key']);
        foreach ([
            [['status' => 'REQUEST_DENIED', 'error_message' => 'private-test-key'], 'request_denied'],
            [['status' => 'OVER_QUERY_LIMIT'], 'over_query_limit'],
            [['status' => 'OK', 'results' => []], 'invalid_response'],
            [['status' => 'OK', 'results' => [['address_components' => [['long_name' => ['bad']]]]]], 'invalid_response'],
            [['status' => 'unexpected-secret'], 'invalid_response'],
        ] as [$response, $expected]) {
            Http::swap(new Factory);
            Http::fake(['maps.googleapis.com/*' => Http::response($response)]);
            $result = app(GoogleReverseGeocoder::class)->lookup(28, 77);
            $this->assertSame($expected, $result['status']);
            $this->assertStringNotContainsString('private-test-key', json_encode($result));
        }
        Http::swap(new Factory);
        Http::fake(['maps.googleapis.com/*' => Http::failedConnection()]);
        $this->assertSame('unavailable', app(GoogleReverseGeocoder::class)->lookup(28, 77)['status']);
        Http::swap(new Factory);
        Http::fake(['maps.googleapis.com/*' => Http::response('not json', 200)]);
        $this->assertSame('invalid_response', app(GoogleReverseGeocoder::class)->lookup(28, 77)['status']);
    }
}
