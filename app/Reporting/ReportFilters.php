<?php

namespace App\Reporting;

use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

final class ReportFilters
{
    public function validate(Request $request, ?string $report = null): array
    {
        $rules = [
            'q' => ['nullable', 'string', 'max:200'],
            'user' => ['nullable', 'string', 'max:36', 'regex:/^(?:[1-9][0-9]{0,15}|[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12})$/'],
            'type' => ['nullable', 'in:learner,teacher,institute'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => array_filter(['nullable', 'date_format:Y-m-d', $request->filled('from') ? 'after_or_equal:from' : null]),
            'per_page' => ['sometimes', 'integer', 'in:15,25,30,50,100'],
            'export' => ['sometimes', 'boolean'], 'page' => ['sometimes', 'integer', 'min:1'],
        ];
        if ($report === 'support') {
            $rules['status'] = ['nullable', 'in:pending,in_progress,resolved,rejected'];
        }

        return $request->validate($rules);
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
