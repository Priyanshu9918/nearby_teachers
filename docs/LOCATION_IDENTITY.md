# Location identity and localization

Provider locations now accept numeric `countryId`, `stateId`, `cityId`,
`districtId`, `subDistrictId`, `localityId` on registration, onboarding autosave and profile editor
step 3. Existing label inputs remain compatible. Maps-only clients may submit
text; the backend links unique parent-scoped catalog matches automatically.
Language is a display preference. IDs are database generated.
A child derives its known parents; conflicting explicit parents return 422.
Explicit null clears that reference. Unresolved legacy names retain null IDs.
Raw address text and sub-district descriptions remain user-entered strings.

`POST /geo/resolve` previews the same text-to-ID resolution used by registration
and profile saves. It accepts country, state, city, district, subDistrict,
locality and countryCode (plus optional IDs), returning each level's ID, name
and status (`matched`, `unresolved`, or `not_provided`). It creates no catalog
rows. Google Place IDs are not database IDs. On authenticated registration or
profile save, an unmatched city is registered only beneath a known state;
an unmatched district needs a known country/state; an unmatched sub-district
needs a known country and district or city. Each receives a generated numeric
ID in the profile transaction. Existing ambiguous names are not duplicated.
Resolve and search never register catalog entries. Locality remains text until
reviewed catalog data is imported.

`GET /geo/countries`, `/geo/states?country_id=…`, `/geo/cities?state_id=…`,
`/geo/districts?state_id=…`, `/geo/sub-districts?district_id=…`, `/geo/localities?district_id=…` accept `lang` and
`search`. Cities also accept `country_id` for reviewed country-assigned cities
without a state; districts accept `country_id` or `city_id`, sub-districts accept `district_id` or `city_id`, and localities accept `city_id`
when no district is assigned. Search supports the selected language's translated name. Responses
retain `id` and translate `name` when reviewed wording exists, including
`canonical_name` on translated entries. English/default responses retain their
existing shape. Cities additionally support explicit `perPage` (1–100) and `page`;
without pagination parameters the existing complete-list response is preserved. Missing translations fall back to the catalog name. IDs never vary by
language. The city catalog additionally preserves country_id for cities with no
state. Existing such cities require reviewed country assignment; migration does
not infer their country from their name.

`/search/filter` accepts all five IDs. `/search/other-city` accepts city, country,
state, district or locality IDs, preserving the online/BSL eligibility rules.
`/search/by-subject/{id}` accepts the corresponding city/state/country IDs for its
existing tiers. Geographic BSL bypass behavior remains as before. Public search
responses expose snake_case location IDs; translated display fields are resolved
using the public language preference. Profile owner and onboarding drafts expose
translated display names for the requested language and keep camelCase IDs stable.
Legacy text search remains compatible; new clients should always filter by IDs.
Country aggregates group linked records by country_id, not translated names.

## SEO contract

Existing page kinds, country-default-language policy, URL paths, canonical and
sitemap policy remain in place. Language switching does not create SEO pages.
No new state page kind, locale URL family or slug translations are generated.
Existing pages are linked to IDs. Re-publication reuses an existing page for the
same location/subject where that ID is known, preserving its registered path.
Same-location saves preserve previously published location labels. Both count
and listing queries use the linked IDs, with legacy fallback for unlinked rows.
If different numeric locations collide on an existing slug, that page retains its
legacy grouping and is marked `location_identity_ambiguous`; no new URL is made.
Existing historical duplicate pages are retained; the backfill does not merge,
delete or rename them. It never invokes publishers or creates URL reservations.

## Rollout

1. Back up the database and deploy the code with its additive migration:
   `php artisan migrate --force`.
2. Import reviewed district/sub-district/locality and translation records as needed using
   `php artisan locations:import /private/reviewed-locations.json` (preview),
   then the same command with `--apply`. Existing country/state/city IDs stay in
   place. No complete world village/translation dataset is fabricated.
3. Preview `php artisan locations:backfill`, review unresolved records, then run
   `php artisan locations:backfill --apply`. Linking leaves names, paths and page
   counts intact. Repeating is safe. Parent-scoped ambiguous matches stay null.
4. Update mobile clients to submit IDs, retaining labels for display only.
   The mobile application source is separate from this repository.

Catalog import format (a JSON array; maximum 1000 reviewed entries per file):

```json
[
  {"type":"district","countryId":1,"stateId":2,"name":"Example District"},
  {"type":"subDistrict","countryId":1,"stateId":2,"districtId":3,"name":"Example Tehsil"},
  {"type":"locality","countryId":1,"stateId":2,"districtId":3,"name":"Example Village"},
  {"type":"city","id":4,"countryId":1},
  {"type":"city","id":4,"language":"hi","name":"उदाहरण शहर"}
]
```

Numbers above are placeholders: use actual catalog IDs. City country assignment
is allowed only for an existing city whose country is missing. A city linked to a
state must use that state's country; existing assignments cannot be overwritten.
Import districts first, then use their generated IDs for sub-district and locality imports. Existing sub-district text remains when no unique catalog match exists.
Translation rows reference an existing allowlisted entity; no SQL/table names
are accepted. Imports are atomic, preview rolls back, and entries are not
silently duplicated or deleted.

## History and reports

Version 1 `teacher.location_identity_changed` and
`institute.location_identity_changed` record before/after numeric references in
the same profile transaction. Backfill records the time of linking, not a claim
about when the user historically selected a location. Translation/catalog imports
record `location.translation_changed`, `location.city_country_assigned`,
`location.district_registered`, and `location.locality_registered` in the import
transaction. No credentials, raw
requests or complete profiles are snapshotted. Profile-save registration of a new catalog city, district or
sub-district records `location.city_registered`, `location.district_registered`
or `location.subDistrict_registered` in the same transaction. Existing reports expose location
IDs explicitly; new catalog and translation reports use the shared admin
registry, access logger and streamed CSV exporter. Direct SQL edits are outside
this history contract. Page metadata backfill is derived reconciliation and does
not manufacture historical page events.

Integration tests require an isolated MySQL database ending in `_test`; never
point test configuration at application data.
