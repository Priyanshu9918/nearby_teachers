# Laravel Admin Portal

The Laravel admin portal is a browser-based session-authenticated interface
for maintaining the subject catalog and learner/provider accounts.

## Access and setup

1. Configure the Laravel application and MySQL 8 connection as described in
   the root [README](../README.md).
2. Configure browser admin credentials using the interactive Artisan command:

   ```sh
   php artisan admin:setup-login
   ```

   Enter the admin email and a password of at least 12 characters when
   prompted. Password input is hidden; the command stores only its bcrypt
   hash in the local, untracked `.env` and clears cached configuration.

3. Start Laravel and visit `/admin`. Unauthenticated users are redirected to
   `/admin/login`. Successful login opens the project dashboard.

## Project dashboard and reports

Deploy the reporting migration before opening the new dashboard:

```sh
php artisan migrate --force
php artisan optimize:clear
```

`/admin/dashboard` shows platform totals, student/teacher/institute counts,
completed registration count and user creation activity for the last 30 days.
`/admin/reports/users` opens the report selector. Reports cover users and
registrations, chat participants, authentication activity, bookings, reviews,
feed posts/comments/likes, support, suggestions, safety reports, blocks,
referrals, phone reveals, page views, provider profiles and subjects. Admin
access logs are also available.

Reports support text search, user UUID and participant account-type filters
where applicable, date ranges for timestamped records, pagination and CSV
export of all matching rows. Dates use the application timezone. CSV text
starting with spreadsheet formula characters is escaped. Exports stream in
chunks rather than loading the whole table into PHP memory.

Open a user report to inspect registration/contact/consent/status data,
the provider profile and subject assignments. Related-report links filter
chat, bookings, reviews and activity by that user. Chat participant rows show
the learner and teacher/institute with names, emails, message count and last
message timestamp. **Read messages** opens searchable, paginated history with
sender identity and timestamps, plus CSV export.

All these routes require the separate browser admin session. Report listings,
user detail and chat history access, and CSV exports, are recorded in
`admin_report_access_log` using the configured browser admin email. This is
separate from API admin audit records, whose actor is a database user.
Admin responses use `Cache-Control: no-store, private`.

Authentication tracking starts at deployment. It records successful and
controller-rejected email/Google sign-ins, OTP verifications and completed
registration events with status, known user ID, submitted email, IP address,
user agent and timestamp. Validation/throttle errors raised before controller
responses are not recorded. OTP verification is not classified as a login.
The history does not track active sessions, logout, device identity or online
status. Historical logins are unavailable; existing user creation and
registration-completion timestamps remain reportable. A recording failure
logs an operational warning without blocking authentication.

Passwords/hashes, OTP values, bearer tokens, guardian consent tokens and
infrastructure secrets are excluded. Reports show currently stored business
records; deletion is not preserved as a complete historical snapshot. Selected
registration, booking and message events are retained separately in the
**Business activity history** report after the history migration is deployed.
No payment
ledger exists in this project, so booking prices are not a revenue report.

New reports are registered centrally in `config/reports.php` and share query,
filter, CSV and audit services. See [Reporting architecture](REPORTING_ARCHITECTURE.md)
for extension contracts, event history coverage and future development rules.

Integration checks use a disposable MySQL database only:

```sh
REPORTING_TEST_DATABASE=nearby_admin_reports_test php artisan test --filter=AdminReportingTest
```

Admin access is disabled when either setting is empty. Login is rate-limited
after repeated failures, and successful authentication uses a regenerated
Laravel session with CSRF protection. Sign out using the **Sign out** button.
The browser portal uses the configured admin email/hash and does not require a
database `is_admin` user.

## Sections and available operations

Each section supports listing, adding, editing, and deleting records.

| Section | URL | Fields and behavior |
|---|---|---|
| Subjects | `/admin/subjects` | `domain_id`, `name`, and `is_bsl`. Domain must already exist; names must be unique within a domain. A subject assigned to a profile, booking, or post cannot be deleted. |
| Students | `/admin/students` | Creates/edits the user's `full_name` and at least one of `email` or `phone`. The account type is stored as `learner`. |
| Teachers | `/admin/teachers` | User fields plus optional `bio` (maximum 14 words), `rate_per_hour`, `currency`, `city`, `state`, `country`, `country_code`, and `linkedin_url`. A teacher profile is created with the user. |
| Institutes | `/admin/institutes` | User fields plus optional `description` (maximum 14 words), `starting_rate`, `currency`, `city`, `state`, `country`, `country_code`, `instructors_text`, and `website_url`. An institute profile is created with the user. |

For student, teacher, and institute forms, email and phone are individually
optional, but at least one is required and each must be unique across users.
Deleting a student, teacher, or institute deletes that account; related profile
records follow the database's configured foreign-key cascade behavior. Review
the impact before deleting production accounts.

Subject domains are displayed as options and are seeded by the catalog setup;
there is no subject-domain CRUD screen in this portal.

## Database and query behavior

The Laravel portal uses Laravel's Query Builder and joins, not Eloquent
relationships. The Laravel project only enables `DB_CONNECTION=mysql` and
uses the MySQL 8 schema. The legacy Express application still uses its
PostgreSQL schema; this portal does not migrate that service or automatically
copy its database contents.

The Nearby Teacher schema is created using ordered, per-table migrations.
Before connecting to a database that already contains data, take a backup
and check the existing schema and Laravel migration history; do not run these
fresh-database create-table migrations against an existing schema.

## Browser portal versus admin API

The browser portal's `ADMIN_EMAIL`/`ADMIN_PASSWORD_HASH` credentials are
separate from admin-only mobile API authorization. API moderation and
verification routes require a bearer token belonging to a user whose
`users.is_admin` column is true. See the [API reference](./API.md#admin-only-api)
for those endpoints.
