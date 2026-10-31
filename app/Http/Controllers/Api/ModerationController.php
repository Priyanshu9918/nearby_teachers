<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Reporting\BusinessActivityRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ModerationController extends Controller
{
    public function pendingPhotos(): JsonResponse
    {
        $pending = DB::table('users')
            ->where('avatar_status', 'pending')
            ->select('id', 'full_name', 'avatar_url', 'account_type', 'updated_at')
            ->orderBy('updated_at')->get();

        return response()->json(['pending' => $pending]);
    }

    public function reviewPhoto(Request $request, int $userId): JsonResponse
    {
        $data = $request->validate(['status' => ['required', 'in:approved,rejected']]);
        $admin = $request->attributes->get('authenticated_user');
        $user = DB::transaction(function () use ($admin, $data, $userId): ?object {
            $user = DB::table('users')->where('id', $userId)->lockForUpdate()
                ->first(['id', 'avatar_status']);
            if ($user === null || $user->avatar_status !== 'pending') {
                return null;
            }

            DB::table('users')->where('id', $userId)->update([
                'avatar_status' => $data['status'], 'updated_at' => now(),
            ]);
            app(BusinessActivityRecorder::class)->record('avatar.reviewed', (string) $userId,
                $admin->id, $userId,
                before: ['avatar_status' => $user->avatar_status], after: ['avatar_status' => $data['status']]);

            return DB::table('users')->where('id', $userId)->select('id', 'full_name', 'avatar_status')->first();
        }, 3);
        if ($user === null) {
            return response()->json(['error' => 'No pending photo found for that user (already reviewed, or no photo on file)'], 404);
        }

        return response()->json(['user' => $user]);
    }

    public function conversations(): JsonResponse
    {
        $conversations = DB::table('conversations as c')
            ->join('users as learner', 'learner.id', '=', 'c.learner_id')
            ->join('users as provider', 'provider.id', '=', 'c.provider_id')
            ->select('c.id', 'c.created_at', 'learner.full_name as learner_name', 'learner.id as learner_id',
                'provider.full_name as provider_name', 'provider.id as provider_id')
            ->selectSub(DB::table('messages as m')->selectRaw('count(*)')
                ->whereColumn('m.conversation_id', 'c.id'), 'message_count')
            ->selectSub(DB::table('messages as m')->selectRaw('max(created_at)')
                ->whereColumn('m.conversation_id', 'c.id'), 'last_message_at')
            ->orderByRaw('(last_message_at IS NULL) ASC')
            ->orderByDesc('last_message_at')->get();

        return response()->json(['conversations' => $conversations]);
    }

    public function conversationMessages(Request $request, int $id): JsonResponse
    {
        $conversation = DB::table('conversations as c')
            ->join('users as learner', 'learner.id', '=', 'c.learner_id')
            ->join('users as provider', 'provider.id', '=', 'c.provider_id')
            ->where('c.id', $id)
            ->select('c.id', 'c.learner_id', 'c.provider_id',
                'learner.full_name as learner_name', 'provider.full_name as provider_name')
            ->first();
        if ($conversation === null) {
            return response()->json(['error' => 'Conversation not found'], 404);
        }

        DB::table('admin_message_access_log')->insert([
            'admin_id' => $request->attributes->get('authenticated_user')->id,
            'conversation_id' => $id,
            'accessed_at' => now(),
        ]);
        $messages = DB::table('messages as m')
            ->join('users as u', 'u.id', '=', 'm.sender_id')
            ->where('m.conversation_id', $id)
            ->select('m.id', 'm.sender_id', 'u.full_name as sender_name', 'm.body', 'm.created_at', 'm.read_at')
            ->orderBy('m.created_at')->get();

        return response()->json(['conversation' => $conversation, 'messages' => $messages]);
    }

    public function bookings(Request $request): JsonResponse
    {
        DB::table('admin_booking_access_log')->insert([
            'admin_id' => $request->attributes->get('authenticated_user')->id,
            'accessed_at' => now(),
        ]);
        $bookings = DB::table('bookings as b')
            ->join('users as learner', 'learner.id', '=', 'b.learner_id')
            ->join('users as provider', 'provider.id', '=', 'b.provider_id')
            ->leftJoin('subjects as s', 's.id', '=', 'b.subject_id')
            ->select('b.id', 'b.status', 'b.mode', 'b.requested_time', 'b.price', 'b.created_at',
                'learner.id as learner_id', 'learner.full_name as learner_name',
                'provider.id as provider_id', 'provider.full_name as provider_name', 's.name as subject_name')
            ->orderByDesc('b.created_at')->get();

        return response()->json(['bookings' => $bookings]);
    }

    public function contactStats(): JsonResponse
    {
        $providerStats = DB::table('conversations as c')
            ->join('users as provider', 'provider.id', '=', 'c.provider_id')
            ->select('provider.id', 'provider.full_name', 'provider.account_type')
            ->selectRaw('count(c.id) as contact_count')
            ->groupBy('provider.id', 'provider.full_name', 'provider.account_type')
            ->orderByDesc('contact_count')->orderBy('provider.full_name')->get();
        $learnerStats = DB::table('conversations as c')
            ->join('users as learner', 'learner.id', '=', 'c.learner_id')
            ->select('learner.id', 'learner.full_name')
            ->selectRaw('count(c.id) as contact_count')
            ->groupBy('learner.id', 'learner.full_name')
            ->orderByDesc('contact_count')->orderBy('learner.full_name')->get();

        return response()->json([
            'mostContactedTeachers' => $providerStats,
            'mostActiveStudents' => $learnerStats,
        ]);
    }

    public function suspend(Request $request, int $userId): JsonResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:5000']]);
        $admin = $request->attributes->get('authenticated_user');
        if ($userId === $admin->id) {
            return response()->json(['error' => 'You cannot suspend your own account.'], 400);
        }
        $user = DB::transaction(function () use ($admin, $data, $userId): ?object {
            $user = DB::table('users')->where('id', $userId)->lockForUpdate()
                ->first(['id', 'is_admin', 'suspended_at', 'suspended_reason']);
            if ($user === null || $user->is_admin) {
                return null;
            }

            $reason = $data['reason'] ?? null;
            $reasonChanged = $reason !== $user->suspended_reason;
            if ($user->suspended_at === null || $reasonChanged) {
                DB::table('users')->where('id', $userId)->update([
                    'suspended_at' => $user->suspended_at ?? now(),
                    'suspended_reason' => $reason,
                    'suspended_by' => $admin->id,
                    'updated_at' => now(),
                ]);

                if ($user->suspended_at === null) {
                    app(BusinessActivityRecorder::class)->record('user.suspended', (string) $userId,
                        $admin->id, $userId,
                        before: ['suspended' => false, 'reason_set' => $user->suspended_reason !== null],
                        after: ['suspended' => true, 'reason_set' => $reason !== null]);
                } else {
                    app(BusinessActivityRecorder::class)->record('user.suspension_reason_changed', (string) $userId,
                        $admin->id, $userId,
                        before: ['reason_set' => $user->suspended_reason !== null, 'reason_changed' => false],
                        after: ['reason_set' => $reason !== null, 'reason_changed' => true]);
                }
            }

            return DB::table('users')->where('id', $userId)
                ->select('id', 'full_name', 'suspended_at', 'suspended_reason')->first();
        }, 3);
        if ($user === null && ! DB::table('users')->where('id', $userId)->exists()) {
            return response()->json(['error' => 'User not found'], 404);
        }
        if ($user === null) {
            return response()->json(['error' => 'Admin accounts cannot be suspended.'], 400);
        }

        return response()->json(['user' => $user]);
    }

    public function unsuspend(Request $request, int $userId): JsonResponse
    {
        $admin = $request->attributes->get('authenticated_user');
        $user = DB::transaction(function () use ($admin, $userId): ?object {
            $user = DB::table('users')->where('id', $userId)->lockForUpdate()
                ->first(['id', 'full_name', 'is_admin', 'suspended_at', 'suspended_reason']);
            if ($user === null || $user->is_admin) {
                return null;
            }
            if ($user->suspended_at !== null) {
                DB::table('users')->where('id', $userId)->update([
                    'suspended_at' => null,
                    'suspended_reason' => null,
                    'suspended_by' => null,
                    'updated_at' => now(),
                ]);
                app(BusinessActivityRecorder::class)->record('user.unsuspended', (string) $userId,
                    $admin->id, $userId,
                    before: ['suspended' => true, 'reason_set' => $user->suspended_reason !== null],
                    after: ['suspended' => false, 'reason_set' => false]);
            }

            return (object) ['id' => $user->id, 'full_name' => $user->full_name];
        }, 3);
        if ($user === null && ! DB::table('users')->where('id', $userId)->exists()) {
            return response()->json(['error' => 'User not found'], 404);
        }
        if ($user === null) {
            return response()->json(['error' => 'Admin accounts cannot be changed here.'], 400);
        }

        return response()->json(['user' => $user]);
    }

    public function suspendedUsers(): JsonResponse
    {
        $suspended = DB::table('users as u')
            ->leftJoin('users as admin', 'admin.id', '=', 'u.suspended_by')
            ->whereNotNull('u.suspended_at')
            ->select('u.id', 'u.full_name', 'u.account_type', 'u.suspended_at', 'u.suspended_reason',
                'admin.full_name as suspended_by_name')
            ->orderByDesc('u.suspended_at')->get();

        return response()->json(['suspended' => $suspended]);
    }
}
