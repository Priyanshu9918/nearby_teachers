<?php

namespace App\Http\Controllers;

use App\Reporting\CsvExporter;
use App\Reporting\OwnerMetrics;
use App\Reporting\ReportAccessLog;
use App\Reporting\ReportFilters;
use App\Reporting\ReportQuery;
use App\Reporting\ReportRegistry;
use App\Reporting\XlsxExporter;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminReportController extends Controller
{
    public function __construct(
        private readonly ReportRegistry $registry,
        private readonly ReportQuery $queries,
        private readonly ReportFilters $filters,
        private readonly CsvExporter $exports,
        private readonly XlsxExporter $xlsx,
        private readonly OwnerMetrics $ownerMetrics,
        private readonly ReportAccessLog $access,
    ) {}

    public function dashboard(Request $request): View
    {
        $this->access->record($request, 'dashboard', 'view');
        $ownerFilters = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        $timezone = config('app.timezone');
        $dashboardTo = Carbon::parse($ownerFilters['to'] ?? now($timezone)->toDateString(), $timezone)->startOfDay();
        $dashboardFrom = Carbon::parse($ownerFilters['from'] ?? $dashboardTo->copy()->subDays(6)->toDateString(), $timezone)->startOfDay();
        $periodDays = (int) $dashboardFrom->diffInDays($dashboardTo) + 1;
        $dashboardPreviousTo = $dashboardFrom->copy()->subDay();
        $dashboardPreviousFrom = $dashboardPreviousTo->copy()->subDays($periodDays - 1);
        $ownerPeriodMetrics = $this->ownerMetrics->summarize($dashboardFrom, $dashboardTo);
        $ownerPreviousMetrics = $this->ownerMetrics->summarize($dashboardPreviousFrom, $dashboardPreviousTo);
        $ownerSnapshot = $this->ownerMetrics->snapshot();
        $queues = $this->ownerMetrics->queues();
        $counts = [];
        foreach ($this->registry->all() as $key => $definition) {
            $query = DB::table($definition->table);
            if (in_array($key, ['seo-pages', 'seo-location-pages'], true)) {
                $query->where(function ($scope): void {
                    foreach (config('seo.countries') as $country => $settings) {
                        $scope->orWhere(fn ($countryScope) => $countryScope->where('country', $country)
                            ->where('language', $settings['language']));
                    }
                });
            }
            if ($key === 'no-result-searches') {
                $query->where('result_count', 0);
            }
            $counts[$key] = ['label' => $definition->title, 'total' => $query->count()];
        }
        $types = DB::table('users')->select('account_type')->selectRaw('count(*) as total')->groupBy('account_type')->pluck('total', 'account_type');
        $registered = DB::table('users')->whereNotNull('profile_completed_at')
            ->where(fn ($query) => $query->where('account_type', '!=', 'learner')
                ->orWhere(fn ($learner) => $learner->whereNotNull('date_of_birth')
                    ->where(fn ($consent) => $consent->where('is_minor', false)->orWhereNotNull('parental_consent_confirmed_at'))))->count();
        $daily = DB::table('users')->where('created_at', '>=', now()->subDays(29)->startOfDay())
            ->selectRaw('DATE(created_at) as day, count(*) as total')->groupByRaw('DATE(created_at)')->orderBy('day')->get();
        $workCounts = [
            'open_safety' => DB::table('reports')->where('status', 'open')->count(),
            'pending_photos' => DB::table('users')->where('avatar_status', 'pending')->count(),
            'bsl_centres' => DB::table('institute_profiles')->where('is_bsl_official', true)->count(),
            'suspended_users' => DB::table('users')->whereNotNull('suspended_at')->where('is_admin', false)->count(),
            'call_backs' => $queues['new_leads'],
            'possible_duplicates' => $queues['possible_duplicates'],
            'referral_points' => (int) DB::table('users')->sum('referral_points'),
        ];

        return view('admin.reports.dashboard', compact('counts', 'types', 'registered', 'daily', 'workCounts',
            'ownerPeriodMetrics', 'ownerPreviousMetrics', 'ownerSnapshot', 'queues', 'dashboardFrom', 'dashboardTo',
            'dashboardPreviousFrom', 'dashboardPreviousTo'));
    }

    public function index(Request $request, string $report): View|StreamedResponse
    {
        $definition = $this->registry->get($report);
        $filters = $this->filters->validate($request, $report);
        [$query, $columns] = $this->queries->build($definition, $filters);
        $this->scopeReport($query, $report);
        $comparison = null;
        if (! $request->boolean('export') && $definition->timestamp !== null
            && ! empty($filters['from']) && ! empty($filters['to'])) {
            $from = Carbon::parse($filters['from'], config('app.timezone'))->startOfDay();
            $to = Carbon::parse($filters['to'], config('app.timezone'))->startOfDay();
            $periodDays = (int) $from->diffInDays($to) + 1;
            $previousTo = $from->copy()->subDay();
            $previousFrom = $previousTo->copy()->subDays($periodDays - 1);
            $previousFilters = $filters;
            $previousFilters['from'] = $previousFrom->toDateString();
            $previousFilters['to'] = $previousTo->toDateString();
            [$previousQuery] = $this->queries->build($definition, $previousFilters);
            $this->scopeReport($previousQuery, $report);
            $comparison = [
                'current' => (clone $query)->count(),
                'previous' => (clone $previousQuery)->count(),
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'previous_from' => $previousFrom->toDateString(),
                'previous_to' => $previousTo->toDateString(),
            ];
            $comparison['difference'] = $comparison['current'] - $comparison['previous'];
        }
        $this->access->record($request, $report, $request->boolean('export') ? 'export' : 'view');
        if ($request->boolean('export')) {
            return $this->exports->download($query, $columns, $report);
        }
        $records = $query->paginate((int) ($filters['per_page'] ?? 30))->withQueryString();
        $title = $definition->title;
        $timestamp = $definition->timestamp;
        $userColumns = $definition->participants;
        $availableReports = $this->registry->all();

        return view('admin.reports.index', compact('report', 'title', 'columns', 'records', 'filters', 'userColumns', 'timestamp', 'availableReports', 'comparison'));
    }

    public function exportAll(Request $request): BinaryFileResponse
    {
        // The combined workbook includes the support status filter for its
        // support sheet while applying shared filters to every report.
        $filters = $this->filters->validate($request, 'support');
        $reports = $this->registry->all();
        foreach (array_keys($reports) as $key) {
            $this->access->record($request, $key, 'export');
        }

        return $this->xlsx->downloadAll($reports, function (string $key, $definition) use ($filters): array {
            [$query, $columns] = $this->queries->build($definition, $filters);
            $this->scopeReport($query, $key);

            return [$query, $columns];
        });
    }

    private function scopeReport(Builder $query, string $report): void
    {
        $this->scopeSeoReports($query, $report);
        $this->scopeSafetyReports($query, $report);
        if ($report === 'no-result-searches') {
            $query->where('r.result_count', 0);
        }
    }

    private function scopeSeoReports(Builder $query, string $report): void
    {
        if (! in_array($report, ['seo-pages', 'seo-location-pages'], true)) {
            return;
        }

        $query->where(function ($scope): void {
            foreach (config('seo.countries') as $country => $settings) {
                $scope->orWhere(fn ($countryScope) => $countryScope->where('r.country', $country)
                    ->where('r.language', $settings['language']));
            }
        });
    }

    private function scopeSafetyReports(Builder $query, string $report): void
    {
        if ($report !== 'reports') {
            return;
        }

        $query->reorder()
            ->orderByRaw("CASE WHEN r.status = 'open' THEN 0 ELSE 1 END")
            ->orderByRaw("CASE WHEN r.reason_category = 'fraud' THEN 0 ELSE 1 END")
            ->orderByDesc('r.created_at')
            ->orderByDesc('r.id');
    }

    public function user(Request $request, int $id): View
    {
        $user = DB::table('users')->select($this->registry->get('users')->columns)
            ->selectRaw('CASE WHEN password_hash IS NOT NULL THEN 1 ELSE 0 END as password_set, CASE WHEN google_id IS NOT NULL THEN 1 ELSE 0 END as google_linked')
            ->where('id', $id)->first();
        abort_if($user === null, 404);
        $this->access->record($request, 'users', 'view', $id);
        $profile = match ($user->account_type) {
            'teacher' => DB::table('teacher_profiles')->select($this->registry->get('teacher-profiles')->columns)->where('user_id', $id)->first(),
            'institute' => DB::table('institute_profiles')->select($this->registry->get('institute-profiles')->columns)->where('user_id', $id)->first(),
            default => null,
        };
        $subjects = $profile ? DB::table('profile_subjects as ps')->join('subjects as s', 's.id', '=', 'ps.subject_id')
            ->where('ps.profile_id', $profile->id)->where('ps.profile_type', $user->account_type)->pluck('s.name') : collect();
        $related = array_filter($this->registry->all(), fn ($entry) => $entry->participants !== []);

        return view('admin.reports.user', compact('user', 'profile', 'subjects', 'related'));
    }

    public function conversation(Request $request, int $id): View|StreamedResponse
    {
        $conversation = DB::table('conversations as c')
            ->join('users as l', 'l.id', '=', 'c.learner_id')->join('users as p', 'p.id', '=', 'c.provider_id')
            ->where('c.id', $id)->select(array_map(fn ($column) => 'c.'.$column, $this->registry->get('conversations')->columns))
            ->addSelect('l.full_name as learner_name', 'p.full_name as provider_name', 'p.account_type as provider_type')->first();
        abort_if($conversation === null, 404);
        $filters = $this->filters->validate($request);
        $query = DB::table('messages as m')->join('users as u', 'u.id', '=', 'm.sender_id')
            ->where('m.conversation_id', $id)->select(array_map(fn ($column) => 'm.'.$column, $this->registry->get('messages')->columns))
            ->addSelect('u.full_name as sender_name', 'u.account_type as sender_type');
        $this->filters->dates($query, 'm.created_at', $filters);
        if (! empty($filters['q'])) {
            $query->where('m.body', 'like', '%'.$filters['q'].'%');
        }
        $query->orderByDesc('m.created_at')->orderByDesc('m.id');
        $this->access->record($request, 'conversation-messages', $request->boolean('export') ? 'export' : 'view', $id);
        if ($request->boolean('export')) {
            return $this->exports->download($query, ['id', 'conversation_id', 'sender_id', 'sender_name', 'sender_type', 'body', 'created_at', 'read_at'], 'messages-'.$id);
        }
        $messages = $query->paginate((int) ($filters['per_page'] ?? 50))->withQueryString();

        return view('admin.reports.conversation', compact('conversation', 'messages', 'filters'));
    }

    public function screenshot(Request $request, string $id): BinaryFileResponse
    {
        $report = DB::table('reports')->where('id', $id)->first(['screenshot_path']);
        abort_if($report === null || ! is_string($report->screenshot_path)
            || ! str_starts_with($report->screenshot_path, 'reports/'), 404);

        $disk = Storage::disk('local');
        abort_unless($disk->exists($report->screenshot_path), 404);
        $this->access->record($request, 'reports', 'evidence', $id);

        return response()->file($disk->path($report->screenshot_path), [
            'Content-Type' => $disk->mimeType($report->screenshot_path) ?? 'application/octet-stream',
            'Content-Disposition' => 'inline; filename="report-evidence"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
