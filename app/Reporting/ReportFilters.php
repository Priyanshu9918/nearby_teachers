<?php

namespace App\Reporting;

use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

final class ReportFilters
{
    public function validate(Request $request): array
    {
        return $request->validate([
            'q' => ['nullable', 'string', 'max:200'], 'user' => ['nullable', 'uuid'],
            'type' => ['nullable', 'in:learner,teacher,institute'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => array_filter(['nullable', 'date_format:Y-m-d', $request->filled('from') ? 'after_or_equal:from' : null]),
            'export' => ['sometimes', 'boolean'], 'page' => ['sometimes', 'integer', 'min:1'],
        ]);
    }

    public function dates(Builder $query, ?string $column, array $filters): void
    {
        if ($column === null) {
            return;
        }
        if (! empty($filters['from'])) {
            $query->where($column, '>=', Carbon::parse($filters['from'])->startOfDay());
        }
        if (! empty($filters['to'])) {
            $query->where($column, '<', Carbon::parse($filters['to'])->addDay()->startOfDay());
        }
    }
}
