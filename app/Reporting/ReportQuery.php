<?php

namespace App\Reporting;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ReportQuery
{
    public function __construct(private readonly ReportFilters $filters) {}

    /** @return array{0: Builder, 1: array<string>} */
    public function build(ReportDefinition $report, array $filters): array
    {
        $query = DB::table($report->table.' as r')->select(array_map(fn ($column) => 'r.'.$column, $report->columns));
        $columns = $report->columns;
        $searchable = array_merge(['r.id'], array_map(fn ($column) => 'r.'.$column, $report->search));
        foreach ($report->computed as $name => $expression) {
            // Expressions are developer-authored config, never request parameters.
            $query->selectRaw($expression.' as '.$name);
            $columns[] = $name;
        }
        foreach ($report->participants as $index => $column) {
            $alias = 'person'.$index;
            $prefix = $column === 'id' ? 'user' : Str::beforeLast($column, '_id');
            $query->leftJoin('users as '.$alias, $alias.'.id', '=', 'r.'.$column)
                ->addSelect($alias.'.full_name as '.$prefix.'_name', $alias.'.email as '.$prefix.'_email', $alias.'.account_type as '.$prefix.'_account_type');
            array_push($columns, $prefix.'_name', $prefix.'_email', $prefix.'_account_type');
            array_push($searchable, $alias.'.full_name', $alias.'.email', $alias.'.phone');
        }
        if (! empty($filters['q'])) {
            $query->where(function (Builder $nested) use ($searchable, $filters): void {
                foreach ($searchable as $column) {
                    $nested->orWhere($column, 'like', '%'.$filters['q'].'%');
                }
            });
        }
        if (! empty($filters['user']) && $report->participants !== []) {
            $query->where(function (Builder $nested) use ($report, $filters): void {
                foreach ($report->participants as $column) {
                    $nested->orWhere('r.'.$column, $filters['user']);
                }
            });
        }
        if (! empty($filters['type']) && $report->participants !== []) {
            $query->where(function (Builder $nested) use ($report, $filters): void {
                foreach (array_keys($report->participants) as $index) {
                    $nested->orWhere('person'.$index.'.account_type', $filters['type']);
                }
            });
        }
        $this->filters->dates($query, $report->timestamp ? 'r.'.$report->timestamp : null, $filters);
        if ($report->timestamp) {
            $query->orderByDesc('r.'.$report->timestamp);
        }
        $query->orderByDesc('r.id');

        return [$query, $columns];
    }
}
