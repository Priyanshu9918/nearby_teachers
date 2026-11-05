# Teacher and institute: custom courses under Other

Import [the dedicated Postman collection](postman/teacher-institute-other-subjects.postman_collection.json). Set `baseUrl`, unused teacher/institute phone numbers and emails. Request OTP, enter the received OTP, verify and register each account in its own folder. The collection stores separate bearer tokens. Run `GET /subjects` after the teacher registers; the institute can select the same saved numeric subject ID or submit the same name and reuse it.

## Requests

Phone-verified teacher registration (`POST /auth/register`, also `POST /auth/onboarding/complete`):

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

For an institute, use `accountType: "institute"`, replace `primarySubjects` with `subjectIds`, and include `contactPerson`. IDs in examples must exist in the target database. Custom-only registration can omit the ID array or send an empty ID array alongside a nonempty `otherSubjects` list. Existing Google registration rules are unchanged.

Both provider types also accept `otherSubjects` in `PATCH /auth/onboarding`, `PUT /profiles/me` and `PATCH /profiles/me/steps/2`. On updates a supplied selection replaces the current subjects; send all IDs/names to keep. Omitting both selection fields preserves the current selection. Registration remains required before profile edits. Learners cannot submit custom courses through registration/profile edits; they can submit them in job applications.

`otherSubjects` is an optional, nonempty array of at most five strings. Each course name must be nonblank and at most 200 characters. Extra whitespace is collapsed; matching ignores capitalization. Repeated normalized names reuse one subject. The resolved selection may contain at most five distinct subjects, including existing IDs and typed names. No literal `"Other"` ID is required.

Profile completion/editing exception: an institute whose stored
`institute_profiles.is_bsl` is true has no five-subject cap in
`PATCH /profiles/me/steps/2` or `PUT /profiles/me`, including typed courses and
the combined resolved selection. Teachers and normal institutes retain the
five-subject cap. Official verification (`is_bsl_official`) or a request-supplied
flag does not grant this exception. Registration and other subject consumers
retain their existing limits. Existing same-transaction selection/custom-subject
history records these saves; no new event or historical backfill is required.

## Catalogue and responses

The first successful custom submission creates the shared real `Other` category if missing. Every new typed name creates a subject inside it with a database-generated numeric ID and `is_bsl: false`. Later requests reuse matching names within Other. The same name in another category is a separate subject. Category/subject unique constraints and row locks serialize concurrent submissions.

`GET /subjects` is public and returns saved courses inside `domains[].subjects` for the domain named `Other`. Before its first successful custom submission, that category may be absent; clients should always offer an Other text input. Saved subjects become ordinary selectable IDs for all users. Registration/onboarding responses return resolved IDs in `draft.primarySubjects` for teachers and `draft.subjectIds` for institutes. Profile responses include `subjects` with names and numeric IDs. Typed text is not a separate pending field.

No migration or historical backfill is needed. These custom subjects do not automatically receive reviewed SEO route mappings or BSL status.

## History

Version 1 `subject.other_created` records the new numeric subject ID, category ID and normalized public course name. Version 1 `provider.custom_subjects_selected` records resolved selection IDs and the actor. Both use the shared recorder inside the source transaction; failed validation, source mutations or history writes leave no partial custom subjects, category, links or events. Creation history covers API custom creation, and selection history covers requests using `otherSubjects`; ordinary ID-only updates and direct SQL/admin catalogue edits are outside these event contracts. No backfill is invented.

The existing admin `activity` report provides bounded pages, common search/participant/time filters and streamed CSV exports. Admin authentication and report access logging apply. Subject entity references are included in the numeric conversion allowlist.

Job applications also accept shared Other courses via optional `otherSubjects`, or saved Other IDs in `courseIds`. Existing BSL selections remain supported. Creation and the existing version 1 application event share the source transaction; course name snapshots remain historical. See [job contract and dedicated collection](JOB_APPLICATION_OTHER_SUBJECTS.md).
