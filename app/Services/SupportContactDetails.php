<?php

namespace App\Services;

use App\Reporting\BusinessActivityRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SupportContactDetails
{
    private const FIELDS = ['whatsapp_number', 'email', 'linkedin_url', 'linkedin_name', 'linkedin_title'];

    public static function rules(): array
    {
        return [
            'whatsappNumber' => ['present', 'nullable', 'string', 'max:32', 'regex:/^\+[1-9](?:[0-9][\s().-]?){6,14}$/'],
            'email' => ['present', 'nullable', 'email', 'max:254'],
            'linkedinUrl' => ['present', 'nullable', 'url:https', 'max:2048'],
            'linkedinName' => ['present', 'nullable', 'string', 'max:160'],
            'linkedinTitle' => ['present', 'nullable', 'string', 'max:160'],
        ];
    }

    public function current(): object
    {
        $settings = DB::table('support_contact_settings')->where('id', 1)->first();

        return $settings ?? (object) (array_fill_keys(self::FIELDS, null) + ['id' => 1, 'updated_at' => null]);
    }

    public function publicPayload(): array
    {
        $settings = $this->current();
        $digits = preg_replace('/\D+/', '', (string) ($settings->whatsapp_number ?? ''));

        return [
            'whatsappNumber' => $settings->whatsapp_number,
            'whatsappUrl' => $digits !== '' ? 'https://wa.me/'.$digits : null,
            'email' => $settings->email,
            'linkedinUrl' => $settings->linkedin_url,
            'linkedinName' => $settings->linkedin_name,
            'linkedinTitle' => $settings->linkedin_title,
            'updatedAt' => $settings->updated_at,
        ];
    }

    public function update(array $input, int|string|null $actorId = null): object
    {
        $values = [
            'whatsapp_number' => $this->nullableTrim($input['whatsappNumber']),
            'email' => $this->nullableTrim($input['email']),
            'linkedin_url' => $this->nullableTrim($input['linkedinUrl']),
            'linkedin_name' => $this->nullableTrim($input['linkedinName']),
            'linkedin_title' => $this->nullableTrim($input['linkedinTitle']),
        ];
        $this->validateLinkedInHost($values['linkedin_url']);

        return DB::transaction(function () use ($values, $actorId): object {
            $saved = DB::table('support_contact_settings')->where('id', 1)->lockForUpdate()->first();
            $changed = [];
            foreach (self::FIELDS as $field) {
                if (($saved->{$field} ?? null) !== $values[$field]) {
                    $changed[] = $field;
                }
            }

            if ($saved === null) {
                DB::table('support_contact_settings')->insert(['id' => 1, ...$values, 'created_at' => now(), 'updated_at' => now()]);
            } elseif ($changed !== []) {
                DB::table('support_contact_settings')->where('id', 1)->update([...$values, 'updated_at' => now()]);
            }

            if ($changed !== []) {
                app(BusinessActivityRecorder::class)->record('support.contact_details_updated', '1', $actorId,
                    after: ['changed_fields' => $changed]);
            }

            return DB::table('support_contact_settings')->where('id', 1)->first();
        }, 3);
    }

    private function nullableTrim(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function validateLinkedInHost(?string $url): void
    {
        if ($url === null) {
            return;
        }

        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if ($host !== 'linkedin.com' && ! str_ends_with($host, '.linkedin.com')) {
            throw ValidationException::withMessages(['linkedinUrl' => 'Use a LinkedIn profile or company URL.']);
        }
    }
}
