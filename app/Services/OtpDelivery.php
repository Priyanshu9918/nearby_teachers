<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class OtpDelivery
{
    public function __construct(private readonly OtpSmsSender $sms, private readonly OtpWhatsAppSender $whatsapp) {}

    public function channels(): array
    {
        $channels = array_values(array_unique(array_filter(array_map('trim', explode(',', (string) config('services.nearby_teacher.otp_delivery_channels', 'sms'))))));
        if ($channels === [] || array_diff($channels, ['sms', 'whatsapp']) !== []) {
            throw new RuntimeException('Invalid OTP delivery channels.');
        }

        return $channels;
    }

    public function send(string $phone, string $code, ?string $country): array
    {
        $channels = $this->channels();
        $result = [];
        $testMode = config('services.nearby_teacher.otp_test_mode') && app()->environment(['local', 'testing']);
        foreach ($channels as $channel) {
            $result[$channel] = ['provider' => $channel === 'sms' ? $this->sms->provider($country) : 'meta', 'status' => $testMode ? 'simulated' : 'not_sent'];
        }
        if ($testMode) {
            return ['channels' => $result, 'testMode' => true];
        }
        try {
            // Validate every configured channel before sending anything.
            foreach ($channels as $channel) {
                if ($channel === 'sms') {
                    $this->sms->assertConfigured($country);
                } else {
                    $this->whatsapp->assertConfigured();
                }
            }
        } catch (Throwable $exception) {
            Log::warning('OTP delivery configuration is incomplete.', ['exception' => $exception::class]);

            return ['channels' => $result, 'testMode' => false];
        }
        foreach ($channels as $channel) {
            try {
                if ($channel === 'sms') {
                    $this->sms->send($phone, $code, $country);
                } else {
                    $this->whatsapp->send($phone, $code);
                }
                $result[$channel]['status'] = 'accepted';
            } catch (Throwable $exception) {
                $result[$channel]['status'] = 'failed';
                Log::warning('OTP channel submission failed.', ['channel' => $channel, 'exception' => $exception::class]);
            }
        }

        return ['channels' => $result, 'testMode' => false];
    }
}
