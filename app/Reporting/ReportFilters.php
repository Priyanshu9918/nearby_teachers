<?php

namespace App\Reporting;

use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

final class ReportFilters
{
    public static function presets(): array
    {
        $today = Carbon::now(config('app.timezone'))->startOfDay();
        $lastMonth = $today->copy()->subMonthNoOverflow();
        $end = $today->toDateString();

        return [
            'today' => ['label' => 'Today', 'from' => $end, 'to' => $end],
            'last_7_days' => ['label' => '7 days', 'from' => $today->copy()->subDays(6)->toDateString(), 'to' => $end],
            'last_30_days' => ['label' => '30 days', 'from' => $today->copy()->subDays(29)->toDateString(), 'to' => $end],
            'this_month' => ['label' => 'This month', 'from' => $today->copy()->startOfMonth()->toDateString(), 'to' => $end],
            'last_month' => ['label' => 'Last month', 'from' => $lastMonth->copy()->startOfMonth()->toDateString(), 'to' => $lastMonth->copy()->endOfMonth()->toDateString()],
            'last_90_days' => ['label' => '90 days', 'from' => $today->copy()->subDays(89)->toDateString(), 'to' => $end],
            'this_year' => ['label' => 'This year', 'from' => $today->copy()->startOfYear()->toDateString(), 'to' => $end],
            'since_launch' => ['label' => 'Since launch', 'from' => config('reporting.launch_date'), 'to' => $end],
        ];
    }

    public function validate(Request $request, ?string $report = null): array
    {
        $rules = [
            'q' => ['nullable', 'string', 'max:200'],
            'user' => ['nullable', 'string', 'max:36', 'regex:/^(?:[1-9][0-9]{0,15}|[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12})$/'],
            'type' => ['nullable', 'in:learner,teacher,institute'],
            'range' => ['sometimes', Rule::in(array_keys(self::presets()))],
            'per_page' => ['sometimes', 'integer', 'in:15,25,30,50,100'],
            'export' => ['sometimes', 'boolean'], 'page' => ['sometimes', 'integer', 'min:1'],
        ];
        if (! $request->has('range')) {
            $rules['from'] = ['nullable', 'date_format:Y-m-d'];
            $rules['to'] = array_filter(['nullable', 'date_format:Y-m-d', $request->filled('from') ? 'after_or_equal:from' : null]);
        }
        if ($report === 'support') {
            $rules['status'] = ['nullable', 'in:pending,in_progress,resolved,rejected'];
        }

        $filters = $request->validate($rules);
        if (isset($filters['range'])) {
            $preset = self::presets()[$filters['range']];
            $filters['from'] = $preset['from'];
            $filters['to'] = $preset['to'];
        }

        return $filters;
    }

    public function dates(Builder $query, ?string $column, array $filters): void
    {
        if ($column === null) {
            return;
        }
        if (! empty($filters['from'])) {
            $query->where($column, '>=', Carbon::parse($filters['from'], config('app.timezone'))->startOfDay());
        }
        if (! empty($filters['to'])) {
            $query->where($column, '<', Carbon::parse($filters['to'], config('app.timezone'))->addDay()->startOfDay());
        }
    }
}
