<?php

namespace App\Reporting;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;

final class BusinessActivityRecorder
{
    // Each event explicitly owns its snapshot contract. No raw request/user arrays.
    private const EVENTS = [
        'auth.logged_out' => ['user', ['auth_version', 'scope']],
        // Form submission and completion are distinct; students complete after valid DOB.
        'registration.submitted' => ['user', ['account_type', 'registration_submitted_at']],
        'registration.completed' => ['user', ['account_type', 'profile_completed_at']],
        // Age history stores flags, never birth dates, guardian contacts or consent tokens.
        'onboarding.age_verified' => ['user', ['age_verified', 'is_minor']],
        'identity.phone_verified' => ['user', ['verified', 'phone_changed']],
        'identity.google_linked' => ['user', ['linked']],
        'otp.delivery_result' => ['otp_delivery', ['channel', 'provider', 'status']],
        'booking.created' => ['booking', ['status', 'mode', 'requested_time', 'price']],
        'booking.status_changed' => ['booking', ['status']],
        'message.created' => ['message', ['conversation_id']],
    ];

    public function record(string $event, string $entityId, ?string $actorUserId, ?string $relatedUserId = null, array $before = [], array $after = []): string
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
        $id = (string) Str::uuid();
        DB::table('business_activity')->insert([
            'id' => $id, 'event' => $event, 'entity_type' => $entityType, 'entity_id' => $entityId,
            'actor_user_id' => $actorUserId, 'related_user_id' => $relatedUserId, 'version' => 1,
            'before_data' => json_encode($before, JSON_THROW_ON_ERROR),
            'after_data' => json_encode($after, JSON_THROW_ON_ERROR), 'created_at' => now(),
        ]);

        return $id;
    }
}
