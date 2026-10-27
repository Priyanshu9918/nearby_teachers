<?php

namespace App\Reporting;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

final class BusinessActivityRecorder
{
    // Each event explicitly owns its snapshot contract. No raw request/user arrays.
    private const EVENTS = [
        'teacher.address_updated' => ['teacher_profile', ['changed_fields']],
        'teacher.location_updated' => ['teacher_profile', ['has_coordinates', 'geocoding_status']],
        'teacher.pages_published' => ['teacher_profile', ['seo_slug', 'public_profile_slug', 'subject_ids', 'city', 'state', 'country']],
        'teacher.pages_updated' => ['teacher_profile', ['seo_slug', 'public_profile_slug', 'subject_ids', 'city', 'state', 'country']],
        'auth.logged_out' => ['user', ['auth_version', 'scope']],
        'user.blocked' => ['user', ['blocked']],
        'user.unblocked' => ['user', ['blocked']],
        // Version 1 submission history excludes contact details; no follow-up/decision workflow exists yet.
        'franchise_enquiry.submitted' => ['franchise_enquiry', ['status']],
        'teaching_job_application.submitted' => ['teaching_job_application', ['status', 'course_ids', 'expected_salary', 'currency', 'salary_period']],
        // Form submission and completion are distinct; learners complete after DOB and required parent approval.
        'registration.submitted' => ['user', ['account_type', 'registration_submitted_at']],
        'registration.completed' => ['user', ['account_type', 'profile_completed_at']],
        // Age history stores flags, never birth dates, guardian contacts or consent tokens.
        'parental_consent.requested' => ['user', ['status']],
        'parental_consent.decided' => ['user', ['status']],
        'onboarding.age_verified' => ['user', ['age_verified', 'is_minor']],
        'identity.phone_verified' => ['user', ['verified', 'phone_changed']],
        'identity.google_linked' => ['user', ['linked']],
        'otp.delivery_result' => ['otp_delivery', ['channel', 'provider', 'status']],
        'booking.created' => ['booking', ['status', 'mode', 'requested_time', 'price']],
        'booking.status_changed' => ['booking', ['status']],
        'message.created' => ['message', ['conversation_id']],
        'message.replied' => ['message', ['conversation_id', 'reply_to_id']],
        'message.delivered' => ['message', ['conversation_id', 'delivered_at']],
        'message.read' => ['message', ['conversation_id', 'read_at']],
        'message.edited' => ['message', ['conversation_id', 'revision', 'edited_at']],
        'message.deleted' => ['message', ['conversation_id', 'revision', 'deleted_at']],
        'message.reaction_changed' => ['message_reaction', ['conversation_id', 'message_id', 'reaction']],
    ];

    public function record(string $event, int $entityId, ?int $actorUserId, ?int $relatedUserId = null, array $before = [], array $after = []): int
    {
        if (! isset(self::EVENTS[$event])) {
            throw new InvalidArgumentException('Unknown business activity event.');
        }
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Record business activity inside the source mutation transaction.');
        }
        [$entityType, $allowed] = self::EVENTS[$event];
        $unexpected = array_diff(array_unique(array_merge(array_keys($before), array_keys($after))), $allowed);
        if ($unexpected !== []) {
            throw new InvalidArgumentException('Business activity contains undeclared snapshot fields.');
        }
        $id = DB::table('business_activity')->insertGetId(['event' => $event, 'entity_type' => $entityType, 'entity_id' => $entityId,
            'actor_user_id' => $actorUserId, 'related_user_id' => $relatedUserId, 'version' => 1,
            'before_data' => json_encode($before, JSON_THROW_ON_ERROR),
            'after_data' => json_encode($after, JSON_THROW_ON_ERROR), 'created_at' => now(),
        ]);

        return $id;
    }
}
