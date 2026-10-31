# Contact BSL and support tickets

The app reads BSL's contact actions from `GET /contact-info`. Administrators can
manage the WhatsApp number, email address and LinkedIn profile through the admin
API or the browser admin sidebar's **Contact BSL links** page. The WhatsApp URL
is derived from the saved number, including its country code.

Learners, teachers and institutes can submit a subject and message with
`POST /support`. New tickets receive a UUID and start as `pending`. Older tickets
whose IDs were converted to numbers remain addressable by their existing ID. The app
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
