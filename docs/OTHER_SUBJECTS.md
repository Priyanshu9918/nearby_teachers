# Teacher and institute: Other course approval

New `otherSubjects` names require admin approval. They are stored in
`custom_subject_requests`, not `subjects` or `profile_subjects`. Until approved,
they appear in neither the shared catalogue nor the submitting owner's profile,
onboarding draft, saved step data, public profile, search or SEO subject list.
Existing catalogue names under Other can be selected immediately.

## Requests

Teacher registration (`POST /auth/register`, also `/auth/onboarding/complete`):

```json
{
  "accountType": "teacher",
  "fullName": "Asha Sharma",
  "email": "teacher@example.com",
  "password": "a-secure-password",
  "city": "Delhi",
  "primarySubjects": [1],
  "otherSubjects": ["Abacus"],
  "termsAccepted": true,
  "acknowledgeTeachersNotBackgroundChecked": true
}
```

Institutes use `subjectIds` instead of `primarySubjects`, plus `contactPerson`.
Both types can submit names through `PATCH /auth/onboarding`, `PUT /profiles/me`
and `PATCH /profiles/me/steps/2`. Omit both subject fields to preserve selections
and requests. Supplying an ID list replaces approved selections; pending requests
whose names are omitted are cancelled. When only custom names are sent, existing
approved selections are preserved. Pending names are never returned as saved IDs.

Names are nonblank, at most 200 characters, with whitespace collapsed and
case-insensitive matching. Duplicate names in a submission produce one request.
Resubmitting the same pending name reuses the request. Rejected/cancelled names
may be resubmitted, recording a new pending transition in history.

The combined approved selection and newly requested names may contain at most
five courses. Only a stored BSL institute has the existing uncapped profile-edit
exception; registration remains capped. Approval checks the current limit again.

## Responses and publication

Registration/onboarding responses expose `customCoursesPendingApproval: true`
when requests are pending. Profile update responses expose the same boolean and
an explanatory message. Approved IDs remain in `draft.primarySubjects` /
`draft.subjectIds`, saved step data and `subjects`; pending names do not.

Custom-only registration is accepted, but the profile has no subject until
approval. Publishing still requires at least one approved subject. Other required
registration/profile fields and existing eligibility rules are unchanged.

The admin **Course approvals** page defaults to pending requests and supports
Approve/Reject, bounded pages and status filtering. Approval creates or reuses
the matching course under Other, attaches it to the submitting teacher/institute
profile, and reconciles that profile's SEO eligibility inside the same
transaction. It does not bypass profile publication requirements. Rejection
creates neither catalogue course nor profile selection. Already-reviewed or
cancelled requests cannot be approved again. If an account is deleted or changes
role, its request can be rejected but not approved for a mismatched profile.

Once approved, `GET /subjects` includes the real numeric subject ID for everyone.
Old catalogue courses remain available; no old course is retroactively hidden.
Admin-created catalogue entries are trusted, directly available courses.

## Deployment, reports and history

Run migration `2026_11_18_000003_create_custom_subject_requests.php` before deploying
these read/write paths. It creates numeric-ID requests without cascading source
or history deletion and adds nullable custom-course references to job snapshots.

The `custom-course-requests` report explicitly declares searchable name/status/type,
participant `user_id`, output columns and creation/review timestamps. It uses
shared admin-session protection, access logging, filters and streamed CSV exports.
The admin approval queue and decisions also have access-log entries.

Version 1 `course_request.submitted`, `course_request.cancelled` and
`course_request.reviewed` events record status transitions, plus the resolved
numeric subject ID on decisions, in the source transaction. No raw requests or
complete user objects are archived. Numeric polymorphic/reference mappings include
the new request entity. `subject.other_created` is now emitted at actual approval
when a new catalogue course is created. `provider.custom_subjects_selected` lists
only approved selected IDs; it never pretends pending names have subject IDs.
History failure rolls back requests, catalogue entries, profile links and decisions.

No old approval history is invented. Request names are current source data;
status events do not archive old name spellings. Browser reviewer identity is in
admin access logs. Job submissions use the same queue without attaching profile
subjects; see [job contract](JOB_APPLICATION_OTHER_SUBJECTS.md).

[Postman collection](postman/teacher-institute-other-subjects.postman_collection.json):
register, approve in admin, then refresh `GET /subjects` to obtain the saved ID.
