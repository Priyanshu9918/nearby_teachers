<?php

namespace App\Services;

use App\Reporting\BusinessActivityRecorder;
use Illuminate\Support\Facades\DB;
use LogicException;

final class TeacherAddress
{
    public const FIELDS = ['district' => 'district', 'subDistrict' => 'sub_district',
        'locality' => 'locality', 'localityType' => 'locality_type', 'state' => 'state',
        'country' => 'country', 'postalCode' => 'postal_code'];

    public static function rules(): array
    {
        return [
            'district' => ['sometimes', 'nullable', 'string', 'max:120'],
            'subDistrict' => ['sometimes', 'nullable', 'string', 'max:120'],
            'locality' => ['sometimes', 'nullable', 'string', 'max:160'],
            'localityType' => ['sometimes', 'nullable', 'in:town,village,ward,sector'],
            'state' => ['sometimes', 'nullable', 'string', 'max:120'],
            'country' => ['sometimes', 'nullable', 'string', 'max:120'],
            'postalCode' => ['sometimes', 'nullable', 'string', 'max:32'],
        ];
    }

    public static function draft(?object $profile): array
    {
        $result = [];
        foreach (self::FIELDS as $input => $column) {
            $result[$input] = $profile->$column ?? null;
        }

        return $result;
    }

    public function store(int $userId, array $data, string $type = 'teacher'): void
    {
        $table = match ($type) {
            'teacher' => 'teacher_profiles',
            'institute' => 'institute_profiles',
            default => throw new \InvalidArgumentException('Unsupported provider type.'),
        };
        if (array_intersect(array_keys($data), array_keys(self::FIELDS)) === []) {
            return;
        }
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Store provider address inside its source transaction.');
        }
        $profile = DB::table($table)->where('user_id', $userId)->lockForUpdate()->first();
        abort_if($profile === null, 404, 'Provider profile not found');
        $changes = [];
        foreach (self::FIELDS as $input => $column) {
            if (array_key_exists($input, $data)) {
                $value = is_string($data[$input]) ? trim($data[$input]) : null;
                $value = $value === '' ? null : $value;
                if ($value !== $profile->$column) {
                    $changes[$column] = $value;
                }
            }
        }
        if ($changes !== []) {
            DB::table($table)->where('user_id', $userId)->update($changes);
            app(BusinessActivityRecorder::class)->record($type.'.address_updated', $userId, $userId,
                after: ['changed_fields' => array_keys($changes)]);
        }
    }
}
