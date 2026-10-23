<?php

namespace App\Http\Controllers;

use App\Reporting\CsvExporter;
use App\Reporting\ReportAccessLog;
use App\Reporting\ReportFilters;
use App\Reporting\ReportQuery;
use App\Reporting\ReportRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminReportController extends Controller
{
    public function __construct(
        private readonly ReportRegistry $registry,
        private readonly ReportQuery $queries,
        private readonly ReportFilters $filters,
        private readonly CsvExporter $exports,
        private readonly ReportAccessLog $access,
    ) {}

    public function dashboard(): View
    {
        $counts = [];
        foreach ($this->registry->all() as $key => $definition) {
            $counts[$key] = ['label' => $definition->title, 'total' => DB::table($definition->table)->count()];
        }
        $types = DB::table('users')->select('account_type')->selectRaw('count(*) as total')->groupBy('account_type')->pluck('total', 'account_type');
        $registered = DB::table('users')->whereNotNull('profile_completed_at')
            ->where(fn ($query) => $query->where('account_type', '!=', 'learner')->orWhereNotNull('date_of_birth'))->count();
        $daily = DB::table('users')->where('created_at', '>=', now()->subDays(29)->startOfDay())
            ->selectRaw('DATE(created_at) as day, count(*) as total')->groupByRaw('DATE(created_at)')->orderBy('day')->get();

        return view('admin.reports.dashboard', compact('counts', 'types', 'registered', 'daily'));
    }

    public function index(Request $request, string $report): View|StreamedResponse
    {
        $definition = $this->registry->get($report);
        $filters = $this->filters->validate($request);
        [$query, $columns] = $this->queries->build($definition, $filters);
        $this->access->record($request, $report, $request->boolean('export') ? 'export' : 'view');
        if ($request->boolean('export')) {
            return $this->exports->download($query, $columns, $report);
        }
        $records = $query->paginate((int) ($filters['per_page'] ?? 30))->withQueryString();
        $title = $definition->title;
        $timestamp = $definition->timestamp;
        $userColumns = $definition->participants;
        $availableReports = $this->registry->all();

        return view('admin.reports.index', compact('report', 'title', 'columns', 'records', 'filters', 'userColumns', 'timestamp', 'availableReports'));
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
}
