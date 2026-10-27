# Teacher registration address and agreements

Use `POST /auth/register` or `POST /auth/onboarding/complete` after phone OTP verification, with the returned bearer token. Use existing subject IDs from `/subjects`.

```json
{
  "accountType": "teacher",
  "fullName": "Asha Sharma",
  "email": "asha@example.com",
  "password": "a-secure-password",
  "primarySubjects": [1, 2, 3, 4, 5],
  "city": "New Delhi",
  "district": "New Delhi",
  "subDistrict": "Chanakyapuri",
  "locality": "Ward 1",
  "localityType": "ward",
  "state": "Delhi",
  "country": "India",
  "postalCode": "110001",
  "termsAccepted": true,
  "acknowledgeTeachersNotBackgroundChecked": true
}
```

| Form label | API field | Validation |
|---|---|---|
| City | `city` | Required at registration; max 120 characters |
| District | `district` | Optional; max 120 characters |
| Sub-district / Tehsil | `subDistrict` | Optional; max 120 characters |
| Town / Village / Ward / Sector | `locality` | Optional; max 160 characters |
| Locality type | `localityType` | Optional: `town`, `village`, `ward`, `sector` |
| State | `state` | Optional; max 120 characters |
| Country | `country` | Optional; max 120 characters |
| ZIP / PIN code | `postalCode` | Optional string; max 32 characters |
| I agree to the Terms and Conditions | `termsAccepted` | Required and accepted/true |
| I understand that teachers are not background checked | `acknowledgeTeachersNotBackgroundChecked` | Required and accepted/true |

New address fields are optional to preserve existing clients and support addresses without every administrative level. `localityType` describes the supplied locality, rather than requiring four separate names. Postal codes are strings to retain leading zeros and international codes such as `A1B 2C3`.

Both agreement fields already exist and remain mandatory: missing/false values reject registration with `422`. Acceptance timestamps are saved in `users.terms_accepted_at` and `users.safety_disclaimer_acknowledged_at`. The app should display the two labels above with unchecked controls that users select themselves. This backend provides API validation and examples, not a native registration screen.

## Editing and responses

The same address names work with teacher `PATCH /auth/onboarding` and `PUT /profiles/me`. Omitted fields stay unchanged; sending `null` clears an optional field. Owner onboarding responses return values under `draft`, using the same camelCase field names.

These are user-provided fields, separate from Google's private `location_details` JSON. No Google key/lookup is needed for manual addresses. Optional GPS enrichment still runs separately when configured. No village/thana jurisdiction is guessed.

Public profile endpoints and SEO pages do not automatically expose district, sub-district, locality or postcode. Existing public city/state/country behavior continues. City/state/country changes refresh the existing SEO URL; finer address fields do not enter the slug.

## Database, reporting and history

Migration `000040` adds nullable `district`, `sub_district`, `locality`, `locality_type`, `postal_code` columns to `teacher_profiles`. State/country already exist. Existing records are not rewritten.

The `teacher-profiles` report explicitly exposes the new columns. District, sub-district, locality and postcode are searchable. Existing admin-session checks, pagination, shared filters, streamed CSV and access logging remain in use.

Version 1 `teacher.address_updated` records allowlisted `changed_fields` names within the source transaction. It never snapshots exact addresses, raw requests, passwords or tokens. History identifies when/which fields changed but cannot reconstruct old exact values. Unchanged addresses emit no event. Existing registration events cover submission/completion. Recorder failure rolls back the source mutation; no history is invented or cascade-deleted. Direct SQL/admin changes are outside this new address-event coverage.

## Deployment and verification

```bash
php artisan migrate --force --path=database/migrations/2026_10_27_000040_add_teacher_registration_address_fields.php
```

Integration tests must use a disposable database ending in `_test`:

```bash
DB_DATABASE=nearby_teachers_seo_test \
ONBOARDING_TEST_DATABASE=nearby_teachers_seo_test \
REPORTING_TEST_DATABASE=nearby_teachers_seo_test \
vendor/bin/phpunit tests/Feature/OnboardingPersistenceTest.php tests/Feature/TeacherPublicPagesTest.php
```
