<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

final class OtpWhatsAppSender
{
    public function assertConfigured(): void
    {
        $settings = config('services.nearby_teacher.whatsapp', []);
        foreach (['access_token', 'phone_number_id', 'template_name', 'language', 'graph_version'] as $key) {
            if (! is_string($settings[$key] ?? null) || trim($settings[$key]) === '') {
                throw new RuntimeException('WhatsApp OTP configuration is incomplete.');
            }
        }
        if (! preg_match('/^\d+$/', $settings['phone_number_id']) || ! preg_match('/^v\d+\.\d+$/', $settings['graph_version'])
            || ! preg_match('/^[a-z0-9_]+$/', $settings['template_name'])) {
            throw new RuntimeException('WhatsApp OTP configuration is invalid.');
        }
    }

    public function send(string $phone, string $code): void
    {
        $this->assertConfigured();
        $settings = config('services.nearby_teacher.whatsapp');
        try {
            $response = Http::withToken($settings['access_token'])->withoutRedirecting()->connectTimeout(3)->timeout(10)
                ->post('https://graph.facebook.com/'.$settings['graph_version'].'/'.$settings['phone_number_id'].'/messages', [
                    'messaging_product' => 'whatsapp', 'recipient_type' => 'individual',
                    'to' => preg_replace('/\D/', '', $phone), 'type' => 'template',
                    'template' => [
                        'name' => $settings['template_name'], 'language' => ['code' => $settings['language']],
                        'components' => [
                            ['type' => 'body', 'parameters' => [['type' => 'text', 'text' => $code]]],
                            ['type' => 'button', 'sub_type' => 'url', 'index' => '0', 'parameters' => [['type' => 'text', 'text' => $code]]],
                        ],
                    ],
                ]);
            if (! $response->successful() || $response->json('error') !== null
                || ! is_string($response->json('messages.0.id')) || $response->json('messages.0.id') === '') {
                throw new RuntimeException('WhatsApp submission was not acknowledged.');
            }
        } catch (Throwable) {
            throw new RuntimeException('WhatsApp OTP submission was not acknowledged.');
        }
    }
}
