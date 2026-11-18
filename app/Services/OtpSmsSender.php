<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use libphonenumber\PhoneNumberUtil;
use RuntimeException;
use Throwable;

final class OtpSmsSender
{
    private const MUZZTECH_ENDPOINT = 'https://connect.muzztech.com/api/sms/send';

    public function provider(?string $country): string
    {
        $provider = (string) config('services.nearby_teacher.otp_sms_provider', 'auto');

        return $provider === 'auto' ? ($country === 'IN' ? 'msg91' : 'twilio') : $provider;
    }

    public function assertConfigured(?string $country): void
    {
        $provider = $this->provider($country);
        if ($provider === 'muzztech') {
            $this->muzztechSettings();

            return;
        }
        $required = match ($provider) {
            'msg91' => ['msg91_auth_key', 'msg91_template_id'],
            'twilio' => ['twilio_account_sid', 'twilio_auth_token', 'twilio_from_number'],
            default => throw new RuntimeException('Unknown OTP SMS provider.'),
        };
        foreach ($required as $key) {
            if (! filled(config('services.nearby_teacher.'.$key))) {
                throw new RuntimeException('SMS configuration is incomplete.');
            }
        }
    }

    public function send(string $phone, string $code, ?string $country): void
    {
        $provider = $this->provider($country);
        match ($provider) {
            'muzztech' => $this->muzztech($phone, $code),
            'msg91' => $this->msg91($phone, $code),
            'twilio' => $this->twilio($phone, $code),
            default => throw new RuntimeException('Unknown OTP SMS provider.'),
        };
    }

    private function muzztechSettings(): array
    {
        $settings = config('services.nearby_teacher.muzztech', []);
        foreach (['api_key', 'sender_name', 'template_id', 'message', 'success_field', 'success_value'] as $key) {
            if (! isset($settings[$key]) || trim((string) $settings[$key]) === '') {
                throw new RuntimeException('Muzztech OTP configuration is incomplete.');
            }
        }
        if (! str_contains($settings['message'], '{otp}')) {
            throw new RuntimeException('Muzztech message must contain the {otp} placeholder.');
        }
        if (preg_match('/^(?:x+|your[_ -].*|placeholder)$/i', trim($settings['template_id']))) {
            throw new RuntimeException('Set the actual Muzztech template ID.');
        }
        if (! in_array($settings['phone_format'] ?? 'digits', ['digits', 'e164', 'national'], true)) {
            throw new RuntimeException('Invalid Muzztech phone format.');
        }

        return $settings;
    }

    private function muzztech(string $phone, string $code): void
    {
        $settings = $this->muzztechSettings();
        $recipient = match ($settings['phone_format'] ?? 'digits') {
            'digits' => preg_replace('/\D/', '', $phone),
            'e164' => $phone,
            'national' => PhoneNumberUtil::getInstance()->getNationalSignificantNumber(PhoneNumberUtil::getInstance()->parse($phone)),
            default => throw new RuntimeException('Invalid Muzztech phone format.'),
        };

        // This gateway's supplied contract uses credentials in the GET query.
        // Do not follow redirects, retry ambiguous sends, or expose HTTP exceptions.
        $stage = 'request';
        $httpStatus = null;
        try {
            $response = Http::withoutRedirecting()->connectTimeout(3)->timeout(10)->get(self::MUZZTECH_ENDPOINT, [
                'api_key' => $settings['api_key'],
                'phone_number' => $recipient,
                'sender_name' => $settings['sender_name'],
                'message' => str_replace('{otp}', $code, $settings['message']),
                'template_id' => $settings['template_id'],
            ]);
            $httpStatus = $response->status();
            $stage = 'response';
            $payload = $response->json();
            $status = is_array($payload) ? data_get($payload, $settings['success_field']) : null;
            $status = is_bool($status) ? ($status ? 'true' : 'false') : $status;
            $expected = is_bool($settings['success_value']) ? ($settings['success_value'] ? 'true' : 'false') : $settings['success_value'];
            if (! $response->successful() || ! is_scalar($status)
                || strtolower(trim((string) $status)) !== strtolower(trim((string) $expected))) {
                $markers = [];
                foreach (['status', 'success', 'code', 'response', 'data.status', 'data.success', 'data.accepted'] as $field) {
                    if (! is_array($payload) || ! \Illuminate\Support\Arr::has($payload, $field)) {
                        continue;
                    }
                    $value = data_get($payload, $field);
                    $normalized = is_bool($value) ? ($value ? 'true' : 'false') : (is_scalar($value) ? strtolower(trim((string) $value)) : 'non_scalar');
                    // Only known status words/codes may leave the gateway payload.
                    $markers[$field] = in_array($normalized, ['true', 'false', 'success', 'successful', 'ok', 'accepted', 'queued', 'sent', 'error', 'failed', 'failure', '0', '1', '200', '201', '202'], true)
                        ? $normalized : 'redacted';
                }
                Log::warning('Muzztech SMS acknowledgement mismatch.', [
                    'http_status' => $response->status(),
                    'json_response' => is_array($payload),
                    'status_markers' => $markers,
                ]);
                throw new RuntimeException('SMS was not acknowledged by the gateway.');
            }
        } catch (Throwable $exception) {
            Log::warning('Muzztech SMS delivery diagnostic.', [
                'stage' => $stage,
                'http_status' => $httpStatus,
                'failure_kind' => $exception instanceof \Illuminate\Http\Client\ConnectionException
                    ? 'connection_or_timeout' : ($stage === 'request' ? 'request_error' : 'response_not_acknowledged'),
            ]);
            // A previous RequestException can include the complete query/key/OTP.
            throw new RuntimeException('Muzztech SMS delivery was not acknowledged.');
        }
    }

    private function msg91(string $phone, string $code): void
    {
        $authKey = config('services.nearby_teacher.msg91_auth_key');
        if (! filled($authKey) || ! filled(config('services.nearby_teacher.msg91_template_id'))) {
            throw new RuntimeException('MSG91 credentials are not configured.');
        }
        Http::timeout(10)->withHeaders(['authkey' => $authKey])->post('https://control.msg91.com/api/v5/flow/', [
            'template_id' => config('services.nearby_teacher.msg91_template_id'), 'short_url' => '0',
            'recipients' => [['mobiles' => preg_replace('/\D/', '', $phone), 'code' => $code]],
        ])->throw();
    }

    private function twilio(string $phone, string $code): void
    {
        $sid = config('services.nearby_teacher.twilio_account_sid');
        $token = config('services.nearby_teacher.twilio_auth_token');
        $from = config('services.nearby_teacher.twilio_from_number');
        if (! filled($sid) || ! filled($token) || ! filled($from)) {
            throw new RuntimeException('Twilio credentials are not configured.');
        }
        Http::asForm()->withBasicAuth($sid, $token)->timeout(10)
            ->post('https://api.twilio.com/2010-04-01/Accounts/'.$sid.'/Messages.json', [
                'To' => $phone, 'From' => $from,
                'Body' => "Your Nearby Teacher verification code is {$code}. Valid for 10 minutes. Do not share this code with anyone.",
            ])->throw();
    }
}
