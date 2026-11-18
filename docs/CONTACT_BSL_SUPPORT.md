# Contact BSL and support tickets

The app reads BSL's contact actions from `GET /contact-info`. Administrators can
manage the WhatsApp number, email address and LinkedIn profile through the admin
API or the browser admin sidebar's **Contact BSL links** page. The WhatsApp URL
is derived from the saved number, including its country code.

Learners, teachers and institutes can submit a subject, message and optional screenshot with
`POST /support`. New tickets receive a numeric ID and start as `pending`. The app
loads the signed-in user's history from `GET /support/mine`; the response
includes the latest status and timestamps, with bounded pagination. Admins can
filter and update tickets through the admin API or the **Support tickets** admin
report. Supported states are `pending`, `in_progress`, `resolved` and
`rejected`. The old `open` query value is accepted as an alias for `pending`.

## App requests

```http
GET /contact-info
Accept: application/json
```

```json
{
  "contact": {
    "whatsappNumber": "+919876543210",
    "whatsappUrl": "https://wa.me/919876543210",
    "email": "support@example.com",
    "linkedinUrl": "https://www.linkedin.com/in/example-profile",
    "linkedinName": "Prateek Gupta",
    "linkedinTitle": "Executive Director",
    "updatedAt": "2026-10-31 10:00:00"
  }
}
```

```http
POST /support
Authorization: Bearer <user-token>
Content-Type: application/json
```

```json
{
  "subject": "Booking help",
  "message": "Please help me with my booking."
}
```

Creation returns `201` with the submitted ticket. `/support/mine?page=1&perPage=20`
returns the caller's tickets and `pagination.hasMore`; `perPage` is capped at 50.

## Optional screenshot upload

Keep using `POST /support`; send `multipart/form-data` when attaching an image.
The fields are `subject` (required, max 255), `message` (required, max 5000), and
`screenshot` (optional single JPEG/PNG/WebP file, max 5 MB). SVG/PDF and non-image
uploads are rejected with HTTP 422 under `errors.screenshot`. Existing JSON
requests without a screenshot remain valid.

```bash
curl --request POST "{{BASE_URL}}/support" \
  --header "Authorization: Bearer {{ACCESS_TOKEN}}" \
  --header "Accept: application/json" \
  --form "subject=Profile update issue" \
  --form "message=Please check the error shown in the screenshot." \
  --form "screenshot=@/path/to/screenshot.png"
```

Let the HTTP client set the multipart boundary; do not manually set a JSON
Content-Type header for this request.

HTTP 201 response (fictional example):

```json
{
  "ticket": {
    "id": 123,
    "user_id": 45,
    "subject": "Profile update issue",
    "message": "Please check the error shown in the screenshot.",
    "status": "pending",
    "created_at": "2026-11-18 10:00:00",
    "updated_at": "2026-11-18 10:00:00",
    "screenshotAvailable": true,
    "screenshotUrl": "https://example.com/support/123/screenshot"
  }
}
```

Without an attachment, `screenshotAvailable` is false and `screenshotUrl` is null.
Creation, owner ticket history, admin ticket listing and status responses use
these fields and never expose the internal storage path.

```http
GET /support/123/screenshot
Authorization: Bearer <ticket-owner-or-admin-token>
```

This returns image bytes with the correct image Content-Type and private/no-store
headers, not JSON. App image widgets must include the bearer header when loading
`screenshotUrl`. Other users receive 404; missing authentication receives 401.
Files are on the private local disk, outside public `/storage` URLs. There is no
standalone anonymous upload endpoint or attachment-replacement endpoint.

Browser admins use `/admin/support/tickets/123/screenshot` through the **View
screenshot** action in the support report. This requires the admin session and
logs the read. Bearer-admin screenshot reads are also access-logged.

Deploy migration `2026_11_18_000004_add_support_ticket_screenshot.php` with the
API changes. Existing tickets start without screenshots. The report/CSV exposes
only `screenshot_available`, not private file paths or image content. Existing
status/search/date/participant filters and streaming exports remain shared.

Version 1 `support_ticket.screenshot_attached` records only `has_screenshot: true`
in the source ticket transaction; existing submission/status events are unchanged.
No image bytes, storage paths, subjects/messages or credentials are copied to
business history. A failed ticket transaction deletes the newly stored file;
no historical attachments are invented. Notification emails still dispatch after
commit and do not contain the private file bytes.

## Admin API

All admin API routes require an authenticated bearer whose account has
`users.is_admin=true`.

```http
GET /admin/contact-info
PUT /admin/contact-info
```

The PUT body must include all five camelCase fields: `whatsappNumber`, `email`,
`linkedinUrl`, `linkedinName` and `linkedinTitle`. Values may be `null` to hide
that contact option. A non-empty WhatsApp number must include a country code;
LinkedIn URLs must use a `linkedin.com` host.

```http
GET /support?status=pending&page=1&perPage=30
PATCH /support/{ticketId}/status
```

The list accepts `pending`, `in_progress`, `resolved`, `rejected` or `all`, plus
an optional `q` search. Status updates accept `pending`, `in_progress`,
`resolved` or `rejected`.

## Admin browser

- `/admin/support/contact-details` edits the same public contact settings.
- `/admin/reports/support` lists, filters, exports and updates tickets.
- `/admin/reports/support-contact-settings` is the access-logged settings report.

## Reporting and history

Ticket submission and status changes record version 1 business events in the
same database transaction as the ticket mutation. Events contain status values
only; they do not copy ticket subjects/messages or the contact information.
Contact-setting updates record changed field names only. The `support` and
`support-contact-settings` reports declare their output and search columns in
`config/reports.php`; admin reads and exports use the shared report access log.
History starts after deployment; existing ticket statuses are migrated from
`open` to `pending`, but no earlier status history is invented.

Import [contact-support.postman_collection.json](postman/contact-support.postman_collection.json)
for app and admin requests.
