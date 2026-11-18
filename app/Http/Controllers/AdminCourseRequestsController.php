<?php

namespace App\Http\Controllers;

use App\Reporting\ReportAccessLog;
use App\Services\CustomSubjectApproval;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class AdminCourseRequestsController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'status' => ['sometimes', 'in:pending,approved,rejected,cancelled'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'in:15,25,50'],
        ]);
        $status = $filters['status'] ?? 'pending';
        $requests = DB::table('custom_subject_requests as r')->leftJoin('users as u', 'u.id', '=', 'r.user_id')
            ->where('r.status', $status)->select('r.id', 'r.name', 'r.profile_type', 'r.status', 'r.user_id', 'r.created_at', 'u.full_name')
            ->orderBy('r.created_at')->orderBy('r.id')->paginate($filters['per_page'] ?? 25)->withQueryString();
        app(ReportAccessLog::class)->record($request, 'custom-course-requests', 'course-request-list');

        return view('admin.moderation.course-requests', compact('requests', 'status'));
    }

    public function review(Request $request, int $id): RedirectResponse
    {
        $data = $request->validate(['status' => ['required', 'in:approved,rejected']]);
        $changed = app(CustomSubjectApproval::class)->review($id, $data['status']);
        app(ReportAccessLog::class)->record($request, 'custom-course-requests', 'course-request-'.$data['status'], $id);

        return back()->with('status', $changed ? 'Course request '.$data['status'].'.' : 'This request has already been reviewed or cancelled.');
    }
}
