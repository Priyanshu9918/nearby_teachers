# Institute registration address and GPS

Institute registration now accepts the same manual address and GPS fields as teacher registration. It does not select subjects.

Use `POST /auth/register` or `POST /auth/onboarding/complete` with the phone-verified bearer:

```json
{
  "accountType": "institute",
  "fullName": "Bright Academy",
  "contactPerson": "Asha Sharma",
  "email": "academy@example.com",
  "password": "a-secure-password",
  "city": "New Delhi",
  "district": "New Delhi",
  "subDistrict": "Chanakyapuri",
  "locality": "Ward 1",
  "localityType": "ward",
  "state": "Delhi",
  "country": "India",
  "postalCode": "110001",
  "latitude": 28.6139,
  "longitude": 77.2090,
  "termsAccepted": true,
  "acknowledgeTeachersNotBackgroundChecked": true
}
```

## Contract

- `city` and `contactPerson` remain required for registration, together with the existing registration requirements and both accepted agreement flags. Google-linked registration retains its existing optional email/password behavior.
- `district`, `subDistrict`, `state`, `country`: optional strings, maximum 120 characters.
- `locality`: optional string, maximum 160 characters. `localityType`: optional `town`, `village`, `ward` or `sector`.
- `postalCode`: optional string, maximum 32 characters; retains leading zeros and supports international postal codes.
- `latitude` / `longitude`: optional paired numeric GPS values, ranges -90..90 / -180..180. Both null clears GPS and enrichment. Omitted coordinates do not trigger lookup.
- Do not send `primarySubjects`, `primarySubjectId` or `subjectIds` in institute registration/onboarding. They are rejected; no institute subject rows are created by registration. Existing separate institute subject listing/update behavior is unchanged.

## Updates and responses

Use the same field names with `PATCH /auth/onboarding` or institute `PUT /profiles/me`. Omitted address fields stay unchanged; null clears optional values. Owner onboarding responses return the address and GPS fields under `draft` plus `draft.locationDetails`. The institute draft has no `primarySubjects` field. Profile update responses include `locationDetails`.

Google enrichment uses the existing `GOOGLE_GEOCODING_ENABLED` and `GOOGLE_GEOCODING_API_KEY` configuration, timeouts and safe error statuses. Address suggestions stay separate from manual city/state/country. Lookup happens before source row locks. Missing keys, zero results and provider failures do not block otherwise valid registration/updates. Changed coordinates replace old enrichment; clearing coordinates clears it. There are no automatic retries or historical backfills.

Available Google structured address components are stored, but village names or police-station jurisdiction are not guaranteed or guessed. The same account-specific Google retention, attribution and reuse limits apply; see [Google setup and storage scope](TEACHER_LOCATION_GEOCODING.md).

## Storage, privacy and reporting

Migration `000041` adds nullable `district`, `sub_district`, `locality`, `locality_type`, `postal_code`, `location_details` columns to `institute_profiles`. Existing latitude/longitude/state/country columns are reused. No existing profile values are rewritten.

The private JSON and finer manual address fields are excluded from public institute profile responses and public website templates. They are returned to the authenticated owner and exposed explicitly in the admin `institute-profiles` report. New address fields and JSON are searchable; pages and streamed CSV use the shared report filters, access logger and admin session guard.

Version 1 `institute.address_updated` stores changed field names only. Version 1 `institute.location_updated` stores coordinate-presence flags and normalized geocoding status. Both are written inside the source transaction. No raw requests, exact addresses, GPS values or credentials are copied into history. Recorder failure rolls back the mutation. History is append-only through the application and is not cascade-deleted. Exact historical addresses, prior records and direct SQL/admin changes are not reconstructed or covered by these API events.

## Migration

```bash
php artisan migrate --force --path=database/migrations/2026_10_27_000041_add_institute_address_and_location_details.php
```

Tests use mocked Google responses and only disposable `_test` databases, covering institute registration/alias, agreements, validation, edits, clearing, private data, reports/CSV, and transaction rollback, alongside teacher regression tests.

Verification: 53 tests / 3,337 assertions passed using `nearby_teachers_seo_test`.
The targeted `000041` migration was applied successfully to the application
database. Live Google lookups still require the server-side key configuration.
