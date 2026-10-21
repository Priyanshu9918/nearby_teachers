<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\SendNotificationEmail;
use App\Reporting\BusinessActivityRecorder;
use App\Support\CursorPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BookingsController extends Controller
{
    public function create(Request $request): JsonResponse
    {
        $user = $request->attributes->get('authenticated_user');
        if ($response = $this->checkContactGates($user)) {
            return $response;
        }

        $data = $request->validate([
            'providerId' => ['required', 'uuid', 'exists:users,id'],
            'subjectId' => ['nullable', 'integer', 'exists:subjects,id'],
            'mode' => ['required', 'in:in_person,home_tuition,online'],
            'requestedTime' => ['required', 'date'],
            'price' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
        ]);
        if ($data['providerId'] === $user->id) {
            return response()->json(['error' => 'You cannot book yourself'], 400);
        }

        $provider = DB::table('users')
            ->leftJoin('teacher_profiles', 'teacher_profiles.user_id', '=', 'users.id')
            ->leftJoin('institute_profiles', 'institute_profiles.user_id', '=', 'users.id')
            ->where('users.id', $data['providerId'])
            ->whereIn('users.account_type', ['teacher', 'institute'])
            ->select('users.email', 'users.full_name')
            ->first();
        if ($provider === null) {
            return response()->json(['error' => 'Provider not found'], 404);
        }

        $bookingId = (string) Str::uuid();
        $now = now();
        DB::transaction(function () use ($bookingId, $user, $data, $now): void {
            DB::table('bookings')->insert([
                'id' => $bookingId,
                'learner_id' => $user->id,
                'provider_id' => $data['providerId'],
                'subject_id' => $data['subjectId'] ?? null,
                'mode' => $data['mode'],
                'requested_time' => $data['requestedTime'],
                'price' => $data['price'] ?? null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            app(BusinessActivityRecorder::class)->record('booking.created', $bookingId, $user->id, $data['providerId'],
                after: ['status' => 'requested', 'mode' => $data['mode'], 'requested_time' => $data['requestedTime'], 'price' => $data['price'] ?? null]);
        });

        if ($provider->email !== null) {
            $this->sendEmail(
                $provider->email,
                'New booking request from '.$user->full_name,
                $user->full_name.' has requested a lesson with you on '.date(DATE_RFC2822, strtotime($data['requestedTime'])).'. Open the app to accept or decline.'
            );
        }

        return response()->json(['booking' => DB::table('bookings')->where('id', $bookingId)->first()]);
    }

    public function incoming(Request $request): JsonResponse
    {
        $user = $request->attributes->get('authenticated_user');
        $bookings = DB::table('bookings as b')
            ->join('users as learner', 'learner.id', '=', 'b.learner_id')
            ->leftJoin('subjects as s', 's.id', '=', 'b.subject_id')
            ->where('b.provider_id', $user->id)
            ->select('b.*', 'learner.full_name as learner_name', 's.name as subject_name')
            ->orderByDesc('b.created_at')->orderByDesc('b.id');

        return CursorPage::respond($request, $bookings, 'bookings');
    }

    public function mine(Request $request): JsonResponse
    {
        $user = $request->attributes->get('authenticated_user');
        $bookings = DB::table('bookings as b')
            ->join('users as provider', 'provider.id', '=', 'b.provider_id')
            ->leftJoin('subjects as s', 's.id', '=', 'b.subject_id')
            ->where('b.learner_id', $user->id)
            ->select('b.*', 'provider.full_name as provider_name', 's.name as subject_name')
            ->orderByDesc('b.created_at')->orderByDesc('b.id');

        return CursorPage::respond($request, $bookings, 'bookings');
    }

    public function updateStatus(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['status' => ['required', 'in:accepted,declined,cancelled,completed']]);
        $user = $request->attributes->get('authenticated_user');

        $booking = DB::transaction(function () use ($user, $id, $data): ?object {
            $booking = DB::table('bookings')->where('id', $id)
                ->where(fn ($query) => $query->where('provider_id', $user->id)->orWhere('learner_id', $user->id))
                ->lockForUpdate()
                ->first();
            if ($booking === null) {
                return null;
            }

            DB::table('bookings')->where('id', $id)->update([
                'status' => $data['status'],
                'updated_at' => now(),
            ]);
            if ($booking->status !== $data['status']) {
                app(BusinessActivityRecorder::class)->record('booking.status_changed', $id, $user->id,
                    $booking->provider_id === $user->id ? $booking->learner_id : $booking->provider_id,
                    before: ['status' => $booking->status], after: ['status' => $data['status']]);
            }

            return (object) [...(array) $booking, 'status' => $data['status'], 'updated_at' => now()];
        });

        if ($booking === null) {
            return response()->json(['error' => 'Booking not found'], 404);
        }

        if (in_array($data['status'], ['accepted', 'declined'], true)) {
            $learner = DB::table('bookings')
                ->join('users as learner', 'learner.id', '=', 'bookings.learner_id')
                ->join('users as provider', 'provider.id', '=', 'bookings.provider_id')
                ->where('bookings.id', $id)
                ->select('learner.email', 'provider.full_name as provider_name')
                ->first();
            if ($learner?->email !== null) {
                $subject = $data['status'] === 'accepted'
                    ? $learner->provider_name.' accepted your booking request'
                    : $learner->provider_name.' declined your booking request';
                $text = $data['status'] === 'accepted'
                    ? 'Your lesson request has been confirmed. Open the app for the details.'
                    : "Your lesson request wasn't accepted this time. You can try another teacher or institute in the app.";
                $this->sendEmail($learner->email, $subject, $text);
            }
        }

        return response()->json(['booking' => DB::table('bookings')->where('id', $id)->first()]);
    }

    private function checkContactGates(object $user): ?JsonResponse
    {
        if ($user->safety_disclaimer_acknowledged_at === null) {
            return response()->json([
                'error' => 'Please review and acknowledge the safety disclaimer before messaging or booking a lesson.',
                'disclaimerEndpoint' => '/auth/safety-disclaimer',
                'acknowledgeEndpoint' => '/auth/acknowledge-safety-disclaimer',
            ], 403);
        }
        if ($user->is_minor && $user->parental_consent_confirmed_at === null) {
            return response()->json([
                'error' => 'A parent or guardian needs to confirm consent before this account can message or book a lesson.',
            ], 403);
        }

        return null;
    }

    private function sendEmail(string $to, string $subject, string $text): void
    {
        SendNotificationEmail::dispatch($to, $subject, $text);
    }
}
