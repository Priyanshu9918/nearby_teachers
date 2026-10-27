# Teacher location enrichment with Google Geocoding

Teacher registration and edits accept an optional GPS coordinate pair:

```json
{
  "accountType": "teacher",
  "fullName": "Asha Sharma",
  "email": "asha@example.com",
  "password": "a-secure-password",
  "city": "Delhi",
  "primarySubjects": [1, 2, 3, 4, 5],
  "latitude": 28.6139,
  "longitude": 77.2090,
  "termsAccepted": true,
  "acknowledgeTeachersNotBackgroundChecked": true
}
```

Use existing subject IDs. Registration still requires the teacher's own city; reverse-geocoded suggestions do not silently replace city/state/country/address fields or alter SEO URL content.

## Supported endpoints

| Endpoint | Behavior |
|---|---|
| `POST /auth/register` | Saves GPS and enriches the teacher address during registration |
| `POST /auth/onboarding/complete` | Same registration behavior |
| `PATCH /auth/onboarding` | Refreshes location on an explicitly submitted coordinate pair |
| `PUT /profiles/me` | Refreshes the authenticated teacher's location on an explicitly submitted pair |
| `GET /auth/onboarding` | Returns the owner's saved `draft.latitude`, `draft.longitude`, `draft.locationDetails` |

`latitude` is -90..90; `longitude` is -180..180. Zero coordinates are valid. Send both or omit both. Send both as `null` on update to clear GPS and enrichment. Invalid/mixed/partial pairs return `422` without calling Google. An update without coordinates leaves existing GPS/details unchanged and does not call Google.

Institute registration and edits now use the same geocoding mapper and configuration, storing data in `institute_profiles.location_details`; see [institute details](INSTITUTE_REGISTRATION_ADDRESS.md). The existing standalone OpenStreetMap lookup page is separate and unchanged.

Profile update example:

```http
PUT /profiles/me
Authorization: Bearer <teacher-token>
Content-Type: application/json

{
  "latitude": 28.6139,
  "longitude": 77.2090
}
```

The authenticated teacher's update response includes `locationDetails`, in addition to `ok` and the existing page URLs. Registration and onboarding responses include the same object as `draft.locationDetails`.

## Database column and structure

The migration adds **one nullable JSON column**, `teacher_profiles.location_details`. Existing `latitude` and `longitude` columns store the submitted GPS values, not Google's potentially approximate returned point. Existing records are not backfilled.

Illustrative successful JSON (names below are fictional):

```json
{
  "version": 1,
  "provider": "google",
  "status": "ok",
  "checked_at": "2026-10-27T10:00:00+00:00",
  "attribution": "Google Maps",
  "place_id": "example-place-id",
  "formatted_address": "7, Sample Lane, Example Locality, India",
  "village": null,
  "police_station": null,
  "postal_code": "122001",
  "address_values": {
    "street_number": "7",
    "route": "Sample Lane",
    "sublocality_level_2": "Ward Four",
    "locality": "Example Locality",
    "administrative_area_level_2": "Example District",
    "administrative_area_level_1": "Haryana",
    "country": "India",
    "country_code": "IN",
    "postal_code": "122001"
  },
  "addresses": [
    {
      "place_id": "example-place-id",
      "formatted_address": "7, Sample Lane, Example Locality, India",
      "types": ["street_address"],
      "components": [
        {"long_name": "Ward Four", "short_name": "Ward Four", "types": ["sublocality_level_2", "political"]}
      ]
    }
  ]
}
```

All returned structured address component types are retained, including available premises, subpremises, street, neighbourhood, sublocality levels, locality, administrative levels, country and postcode. `address_values` and the convenience fields describe the first usable result. Alternate results are retained separately in `addresses`; components from different possible addresses are not merged into a misleading composite address.

For bounded storage the mapper keeps at most 20 results, 64 components per result and 20 type labels per component. It stores explicitly selected address values, not the raw HTTP response, raw error message, request, API key, Google geometry, viewport or unrelated provider payload fields.

**Village/thana limitations:** Google reverse geocoding does not guarantee village names or police-station jurisdiction. A locality is not automatically labelled as a village; administrative levels are not guessed to be a thana. The convenience fields remain `null` unless the corresponding explicit component type is actually returned. Google generally returns locality/sublocality/admin-area component types, which remain accessible by their original names. Finding a nearby police station requires a separate POI lookup and still does not establish the responsible police jurisdiction. No extra Places API calls are made here.

Google reverse geocoding estimates an address; it does not verify residence, teaching location, boundary jurisdiction or GPS accuracy. See [Google reverse-geocoding documentation](https://developers.google.com/maps/documentation/geocoding/requests-reverse-geocoding).

## Failure behavior and costs

- Lookup disabled or key absent: GPS is saved and `status = not_configured`; no HTTP request is made.
- No results: `zero_results`.
- Key/permission rejected: `request_denied`.
- Quota errors: `over_query_limit` / `over_daily_limit`.
- Connection/HTTP errors: `unavailable`.
- Invalid provider payload: `invalid_response`.
- Other documented Google errors use bounded normalized status names.

Provider failure does not block an otherwise valid registration/edit. On a changed GPS pair, old enrichment is replaced with the latest result/status so an old address cannot be attached to the new point. User-entered public address/city fields remain untouched.

Every explicitly submitted non-null pair makes at most one lookup when enabled, even if that pair was submitted previously. There are no automatic retries, global address caches or background backfills. Omit unchanged coordinates from routine updates to avoid extra billable requests. The call happens before the source mutation transaction acquires row locks. A successful Google call may still be billed if the subsequent database transaction rolls back.

## Privacy, reporting and history

Details are available only through the authenticated owner's onboarding/update responses and the admin `teacher-profiles` report. Public `/profiles/teacher/{id}`, search results and SEO pages do not expose the new JSON. Owner responses should not be shared as public profile payloads.

`config/reports.php` explicitly includes `location_details` in output and search columns. Admin users can search a postcode/address term using the existing filters and export matching rows through the existing streamed CSV exporter. Admin-session checks, pagination and report access logging remain in force. These are current profile details; there is no added immutable archive of exact past addresses.

Version 1 `teacher.location_updated` events are recorded with the source coordinate/JSON mutation in the same transaction. Events store only `has_coordinates` and normalized `geocoding_status`, before/after. Exact coordinates, address text, raw requests and credentials are never copied into append-only history. Recorder failure rolls back the source changes. Existing user/profile deletion behavior does not cascade-delete business activity history.

Coverage gaps: changes made outside these teacher APIs, historical locations before this migration and provider results from calls whose database transaction fails are not reconstructed. Direct SQL/admin coordinate writes do not trigger geocoding. This feature enriches an explicitly submitted teacher profile location; it does not track movement.

## Google storage and attribution scope

The JSON is associated with a specific teacher and supports that teacher's profile functionality. It is not a shared postal-code/POI database or an unrestricted historical data warehouse. Future reuse must be checked against the account's applicable Google agreement.

Current non-EEA service-specific terms allow persistent address-value caching for certain direct end-user functionality, with user-level isolation and limits on reuse. This is narrower than arbitrary future archival/reporting reuse; EEA accounts have different terms. Place IDs have their own storage exception. See [Geocoding policies](https://developers.google.com/maps/documentation/geocoding/policies) and [Google Maps service-specific terms, section 6](https://cloud.google.com/maps-platform/terms/maps-service-terms#6.-geocoding-api). For unrestricted long-term geographic datasets, use a data source with an appropriate storage/reuse licence.

Clients displaying the Google-sourced address must provide Google Maps attribution, using the returned `attribution` value. Do not display this Google-sourced content on a non-Google map. The feature does not insert precise addresses into public pages/maps or change the separate OpenStreetMap lookup's attribution.

## Setup and activation

Configure the key **on the server**, not in an API request, Postman collection, public JavaScript or committed documentation:

```dotenv
GOOGLE_GEOCODING_ENABLED=true
GOOGLE_GEOCODING_API_KEY=<your-server-key>
```

Enable Geocoding API and billing in the Google Cloud project. Restrict the key to the Geocoding API and the appropriate server IPs. Set quotas appropriate to expected registration/update traffic. Defaults keep the integration disabled until configured.

Deploy the nullable column migration:

```bash
php artisan migrate --force --path=database/migrations/2026_10_27_000039_add_teacher_location_details.php
```

After changing `.env`, refresh configuration through the deployment's normal `php artisan config:cache` process, or `php artisan config:clear` when not using a config cache. No live Google call can be verified until a working key is configured.

Tests use fake Google responses and a disposable database ending in `_test`; they incur no Google charges:

```bash
DB_DATABASE=nearby_teachers_seo_test \
ONBOARDING_TEST_DATABASE=nearby_teachers_seo_test \
REPORTING_TEST_DATABASE=nearby_teachers_seo_test \
vendor/bin/phpunit tests/Unit/GoogleReverseGeocoderTest.php tests/Feature/TeacherGeocodingTest.php
```

Implementation verification: 81 tests / 3,789 assertions passed across the geocoder,
teacher registration/edits, public pages, API catalogue and registration completion
suites using `nearby_teachers_seo_test`. Google calls were mocked. The targeted
`000039` migration was applied successfully to the application database; the
server API key has not been supplied, so live Google lookup verification remains
pending. No existing teacher location records were backfilled.
