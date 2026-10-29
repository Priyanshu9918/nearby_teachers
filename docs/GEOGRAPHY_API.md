# Country, state and city API

Public, read-only dropdown lookups use the separately imported SQL tables:

| Table | Required columns |
|---|---|
| `countries` | `id`, `sortname`, `name`, `phonecode` |
| `states` | `id`, `name`, `country_id` |
| `cities` | `id`, `name`, `state_id` |

Only the listed columns are exposed. No authentication is required. Requests
are limited to 60 per minute per IP.

## Endpoints

| Method and path | Result |
|---|---|
| `GET /geo/countries` | All imported countries, with `id`, `sortname`, `name`, `phonecode`. |
| `GET /geo/states?country_id=101` | All states belonging to country ID 101, with `id`, `name`, `country_id`. |
| `GET /geo/cities?state_id=1&page=1&perPage=50` | Cities belonging to state ID 1, with `id`, `name`, `state_id`, plus pagination. |

All three endpoints accept optional `search` to filter names. Omit search for
the full matching list. Results sort by name, then ID. Country and state lists
are unpaginated. Cities default to 50 per page and allow at most 100; use
`pagination.lastPage` to load further pages.

Missing, empty, non-integer or non-positive parent IDs return 422. Unknown
country IDs or state IDs return 404. A known parent without children returns an
empty array. Invalid pagination or search values return 422.

Example response for `GET /geo/cities?state_id=1` (illustrative subset):

```json
{
  "state_id": 1,
  "cities": [{"id": 3, "name": "Port Blair", "state_id": 1}],
  "pagination": {"page": 1, "perPage": 50, "total": 1, "lastPage": 1}
}
```

Load countries first. Pass the selected country's `id` as `country_id` to states,
then the selected state's `id` as `state_id` to cities. Clear state and city
selections when country changes, and clear city when state changes.

## Schema transition and history

These APIs replace the legacy GeoNames country-code URLs and response fields.
Use `/geo/states?country_id=...` and `/geo/cities?state_id=...` instead of
`/geo/countries/{countryCode}/states` and
`/geo/countries/{countryCode}/cities?stateCode=...`.

The supplied SQL tables must already be imported. This change does not alter
or populate application tables. Legacy GeoNames migrations and import/export
commands target a different schema; do not use them to populate these imported
tables. Existing profile location names and country codes are separate from
these dropdown IDs. Ensure indexes on `states.country_id` and `cities.state_id`
when preparing the imported dataset for large catalogues.

These are read-only catalog queries; no new business state or history events
are created. External SQL import history is not reconstructed or recorded by
these endpoints.

## Postman and verification

Import [geography.postman_collection.json](postman/geography.postman_collection.json).
Set `baseUrl`, then select `country_id` and `state_id` from returned rows. The
app-team, teacher/geography and institute collections use the same endpoints.
Saved responses are abbreviated fictional examples.

Integration tests use only an explicitly selected disposable MySQL database
ending in `_test`, with fixtures matching the imported schema and rolled back
after each test. No application migrations or application data clearing run:

```sh
GEOGRAPHY_TEST_DATABASE=nearby_teachers_geography_test php artisan test tests/Feature/GeographyApiTest.php
```
