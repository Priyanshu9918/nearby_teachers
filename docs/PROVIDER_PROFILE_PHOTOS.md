# Provider profile photos

Teacher and institute photo uploads publish immediately with
`avatar_status: approved`. The upload response reports that the photo is visible.
This applies to `/profiles/me/photo`, its teacher aliases, and `PATCH /auth/avatar`
for provider accounts. Existing image type/5 MB limits still apply to uploads.
Profile eligibility and account suspension checks still control public listings.
An `approved` avatar means it is displayable, not an administrator endorsement.

Admin **Profile photos** lists uploaded provider photos on bounded pages, with
a **Delete photo** action. Provider approval/rejection actions are disabled;
pending student photos keep their existing review controls. The admin dashboard
count includes provider photos; the weekly owner queue still counts only photos
requiring review.

Browser removal uses `DELETE /admin/moderation/photos/{userId}` with the admin
session and CSRF protection. Reads and successful removal requests are admin
access-logged. The existing bearer-admin moderation API also supports
`DELETE /moderation/photos/{userId}`, returning `{"ok":true,"removed":true}`;
an already empty photo returns `removed: false`. These endpoints are restricted
to provider targets. Removal clears `users.avatar_url` and sets status to
`rejected`, so profiles, search and SEO no longer render the photo. It removes
the profile reference, not remotely hosted files or retained storage objects.
A later user upload is immediately visible again.

Migration `2026_11_18_000002_publish_pending_provider_photos.php` publishes existing
pending provider photos in bounded chunks, recording each change transactionally.
Already rejected images and student photos are not republished. Run normal
deployment migrations; this migration does not change table schemas. Its rollback
does not hide published images or erase business history.

## Reporting history

Version 1 `teacher.avatar_submitted` / `institute.avatar_submitted` continue to
record only status, now `approved` for immediate publication, in the same
transaction as the source update. The generic avatar-URL endpoint now also
records these events for providers.

New allowlisted version 1 `provider.avatar_removed` records before/after
`avatar_status` and `has_photo`, in the same transaction as removal. Browser
admin identity is retained in the access log; bearer-admin removal records actor
ID. `provider.avatar_auto_published` records before/after status at migration
time with a null/system actor; it does not invent historic approval times.
Repeated removal emits no duplicate business event. No image bytes, URLs or
complete user objects are copied into history. The existing users report already
explicitly exposes current avatar URL/status and its normal CSV/admin controls.
`avatar.reviewed` remains available for student reviews and old history stays
append-only.

Unit tests exercise both provider upload paths, safe history, removal, admin
controls/access and unchanged student URL submission. MySQL integration tests
cover browser deletion/access logs, idempotency and rollback on history failure;
they require a disposable `REPORTING_TEST_DATABASE` ending in `_test`.
