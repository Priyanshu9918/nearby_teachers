# Teacher primary subject API

The existing `primarySubjects` array remains the complete merged selection (one
primary plus up to four secondary subjects). `primarySubjectId` identifies one
approved catalogue ID from that selection. Secondary subjects are the remaining
IDs; their order has no effect on URL selection. Other courses retain the existing
approval flow and count toward the total five-selection limit. A pending custom
course cannot be primary until approved and assigned a subject ID.

No database migration or backfill is required: reuse the existing nullable
`teacher_profiles.primary_subject_id`; `profile_subjects` continues to contain all
selected approved subjects. Existing null-primary teachers retain the alphabetical
share-URL fallback. Clients omitting the new field preserve the saved primary;
removing a saved primary requires explicitly selecting its replacement. Explicit
null, nonexistent IDs and IDs outside the saved/replacement selection are rejected.

All endpoints below use `Authorization: Bearer <token>` and JSON content/accept
headers. Routes have no `/api` prefix. Example IDs must exist in `GET /subjects`.

## Registration

`POST /auth/register` (alias: `POST /auth/onboarding/complete`), after phone verification:

```json
{
  "accountType": "teacher",
  "fullName": "Rahul Sharma",
  "email": "rahul@example.com",
  "password": "example-password-123",
  "city": "Delhi",
  "primarySubjects": [12, 34, 54, 67, 45],
  "primarySubjectId": 12,
  "termsAccepted": true,
  "acknowledgeTeachersNotBackgroundChecked": true
}
```

Email/password requirements follow the existing Google-identity registration
rules. Existing provider location/address requirements also remain applicable.
Registration returns HTTP 200, with these fields among the existing response:

```json
{
  "draft": {
    "primarySubjects": [12, 34, 54, 67, 45],
    "primarySubjectId": 12
  }
}
```

Read the saved onboarding draft with `GET /auth/onboarding`. After registration,
`PATCH /auth/onboarding` also accepts the selection fields and returns the same
`draft` fields. Authentication/session and registration gates remain unchanged.

## Profile subject step

`PATCH /teacher/profile/steps/2` (shared alias: `PATCH /profiles/me/steps/2`):

```json
{
  "primarySubjects": [12, 34, 54, 67, 45],
  "primarySubjectId": 12
}
```

HTTP 200 response excerpt for a published eligible teacher, assuming ID 12 is Maths:

```json
{
  "ok": true,
  "step": 2,
  "primarySubjectId": 12,
  "primarySubjects": [12, 34, 54, 67, 45],
  "teacherSeoUrl": "https://teachers.britishschooloflanguages.com/teacher/rahul-sharma-delhi",
  "teacherProfileUrl": "https://teachers.britishschooloflanguages.com/teacher/rahul-sharma-delhi",
  "webPageUrl": "https://teachers.britishschooloflanguages.com/teacher/rahul-sharma-maths-delhi",
  "myWebpageUrl": "https://teachers.britishschooloflanguages.com/teacher/rahul-sharma-maths-delhi",
  "customCoursesPendingApproval": false
}
```

This is an excerpt: the real response also includes `locationDetails` and
`profileDraft`. Returned subject IDs are sorted by subject name; clients must use
`primarySubjectId`, never array order. URLs use configured SEO host and collision
suffixes where needed; they are null before public-page eligibility. Other courses
can be supplied alongside fewer than five IDs, for example four IDs plus one
`otherSubjects` name. Their pending status uses the existing response contract.

`PUT /profiles/me` also accepts the new field. `GET /teacher/profile` and
`GET /profiles/me` expose `profile.primary_subject_id` and the saved primary in
`profileDraft.steps[1].data.primarySubjectId`. Step-save responses include that
same draft field; `GET /auth/session` uses the shared owner draft representation.

A primary outside the selected IDs returns HTTP 422 with:

```json
{
  "errors": {
    "primarySubjectId": [
      "The primary subject must be included in primarySubjects. Select a new primary when removing the current one."
    ]
  }
}
```

## SEO and history

Only `subject_profile_slug` uses the explicit primary instead of the alphabetically
first subject. The full merged selection still feeds directory/location listings.
Name/location URLs, canonical policy and sitemap selection remain unchanged.
Historical share URLs resolve through existing URL ownership plus append-only
`teacher.share_url_changed` snapshots and redirect directly to the latest share
URL. The primary-change event is version 1 and commits with the source mutation;
see `REPORTING_ARCHITECTURE.md` for coverage and gaps.
