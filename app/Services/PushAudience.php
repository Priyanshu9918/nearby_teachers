<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class PushAudience
{
    public function validate(Request $request): array
    {
        $data = $request->validate([
            'target_type' => ['required', Rule::in(['all', 'user', 'device', 'segment'])],
            'target_id' => ['nullable', 'string', 'max:36'],
            'account_type' => ['nullable', Rule::in(['learner', 'teacher', 'institute'])],
            'city' => ['nullable', 'string', 'max:120'],
            'state' => ['nullable', 'string', 'max:120'],
            'country' => ['nullable', 'string', 'max:120'],
        ]);

        if ($data['target_type'] === 'user') {
            validator($data, ['target_id' => ['required', 'exists:users,id']])->validate();
        } elseif ($data['target_type'] === 'device') {
            validator($data, ['target_id' => ['required', 'exists:push_devices,id']])->validate();
        } elseif ($data['target_type'] === 'segment'
            && ! filled($data['account_type'] ?? null)
            && ! filled($data['city'] ?? null)
            && ! filled($data['state'] ?? null)
            && ! filled($data['country'] ?? null)) {
            throw ValidationException::withMessages(['audience' => 'Select an account type or at least one location filter.']);
        }

        return $data;
    }

    public function filters(array $data): array
    {
        return match ($data['target_type']) {
            'user' => ['user_id' => $data['target_id']],
            'device' => ['device_id' => $data['target_id']],
            'segment' => array_filter([
                'account_type' => $data['account_type'] ?? null,
                'city' => filled($data['city'] ?? null) ? trim($data['city']) : null,
                'state' => filled($data['state'] ?? null) ? trim($data['state']) : null,
                'country' => filled($data['country'] ?? null) ? trim($data['country']) : null,
            ], static fn ($value) => $value !== null),
            default => [],
        };
    }

    public function query(string $targetType, array $filters): Builder
    {
        $query = DB::table('push_devices as d')
            ->join('users as u', 'u.id', '=', 'd.user_id')
            ->whereNull('d.disabled_at')->whereNotNull('d.fid_ciphertext')
            ->whereNull('u.suspended_at')->where('u.is_admin', false)
            ->select(['d.id', 'd.user_id', 'd.fid_ciphertext', 'd.platform']);

        if ($targetType === 'user') {
            $query->where('u.id', $filters['user_id'] ?? '');
        } elseif ($targetType === 'device') {
            $query->where('d.id', $filters['device_id'] ?? '');
        } elseif ($targetType === 'segment') {
            if (filled($filters['account_type'] ?? null)) {
                $query->where('u.account_type', $filters['account_type']);
            }
            foreach (['city', 'state', 'country'] as $dimension) {
                if (filled($filters[$dimension] ?? null)) {
                    $this->whereLocation($query, $dimension, (string) $filters[$dimension]);
                }
            }
        }

        return $query->orderBy('d.id');
    }

    private function whereLocation(Builder $query, string $dimension, string $value): void
    {
        $deviceColumn = 'location_'.$dimension;
        $profileColumn = $dimension;
        $query->where(function (Builder $location) use ($deviceColumn, $profileColumn, $dimension, $value): void {
            $location->whereRaw('LOWER(d.'.$deviceColumn.') = LOWER(?)', [$value]);
            foreach (['teacher_profiles', 'institute_profiles'] as $table) {
                $location->orWhereExists(function (Builder $profile) use ($table, $profileColumn, $dimension, $value): void {
                    $profile->selectRaw('1')->from($table.' as p')
                        ->whereColumn('p.user_id', 'u.id')
                        ->where(function (Builder $match) use ($profileColumn, $dimension, $value): void {
                            $match->whereRaw('LOWER(p.'.$profileColumn.') = LOWER(?)', [$value]);
                            if ($dimension === 'country' && strlen($value) === 2) {
                                $match->orWhereRaw('LOWER(p.country_code) = LOWER(?)', [$value]);
                            }
                        });
                });
            }
        });
    }
}
