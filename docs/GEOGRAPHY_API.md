# Country, state, city and locality API

Public, read-only dropdown lookups use the separately imported SQL tables:

| Table | Required columns |
|---|---|
| `countries` | `id`, `sortname`, `name`, `phonecode` |
| `states` | `id`, `name`, `country_id` |
| `cities` | `id`, `name`, `state_id`, `country_id` |
| `districts` | `id`, `name`, `country_id`, optional `state_id` |
| `sub_districts` | `id`, `name`, `country_id`, optional `state_id`, `city_id`, `district_id` |
| `localities` | `id`, `name`, `country_id`, optional `state_id`, `district_id`, `city_id` |

Only the listed columns are exposed. No authentication is required. Requests
are limited to 60 per minute per IP.

## Endpoints

| Method and path | Result |
|---|---|
| `GET /geo/countries` | All imported countries, with `id`, `sortname`, `name`, `phonecode`. |
| `GET /geo/states?country_id=101` | All states belonging to country ID 101, with `id`, `name`, `country_id`. |
| `GET /geo/cities?state_id=1` | All cities in state ID 1, with `id`, `name`, `state_id`. |
| `GET /geo/cities?country_id=101` | Cities assigned to country ID 101 that have no state; includes `country_id`. |
| `GET /geo/districts?state_id=1` | Districts linked to state ID 1; `country_id` can be used for country-only districts. |
| `GET /geo/districts?city_id=3` | Reviewed districts linked directly to city ID 3. |
| `GET /geo/sub-districts?district_id=4` | Sub-districts linked to district ID 4; `city_id` can be used where no district is assigned. |
| `GET /geo/localities?district_id=3` | Localities linked to district ID 3; `city_id` can be used when there is no district. |

All endpoints accept optional `search` and `lang`. Search checks canonical and
reviewed translated names. A translated result keeps its numeric `id`, returns
the translated `name`, and includes `canonical_name`. Missing translations fall
back to the catalog name. IDs never change by language. Results sort by canonical
name, then ID. Country, state and city lists are unpaginated; city queries return the complete matching list.

Missing, empty, non-integer or non-positive parent IDs return 422. A city query
requires `state_id` or `country_id`; supplying both verifies that the state
belongs to the country. District queries require `state_id` or `country_id`;
locality queries require `district_id` or `city_id`. A known parent without
children returns an empty array. Invalid search values return 422.

Example response for `GET /geo/cities?state_id=1` (illustrative subset):

```json
{
  "state_id": 1,
  "cities": [{"id": 3, "name": "Port Blair", "state_id": 1}],
  "cities": [{"id": 3, "name": "Port Blair", "state_id": 1}]
}
```

Load countries first. Pass the selected country's `id` as `country_id` to states,
then the selected state's `id` as `state_id` to cities. Country-only city
results cover cities with no state assignment. Clear state and city selections
when country changes, and clear city when state changes.

## Schema transition and history

These APIs replace the legacy GeoNames country-code URLs and response fields.
Use `/geo/states?country_id=...` and `/geo/cities?state_id=...` instead of
`/geo/countries/{countryCode}/states` and
`/geo/countries/{countryCode}/cities?stateCode=...`.

The supplied SQL tables must already be imported. This change does not alter
or populate application tables. Legacy GeoNames migrations and import/export
commands target a different schema; do not use them to populate these imported
tables. Existing profile location names and country codes are separate from
these dropdown IDs. Ensure indexes on the parent IDs when preparing large
catalogues.

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

### Popular country ordering

`GET /geo/countries` returns one `countries` array. Its popular prefix is India
(IN), United States (US), United Kingdom (GB), Canada (CA), United Arab Emirates
(AE), Bangladesh (BD), Pakistan (PK), Brazil (BR), Australia (AU), in that order.
These entries carry `is_popular: true`. The complete alphabetical country list
follows, including the same nine countries again with the same numeric IDs
and without `is_popular`. Search filters both sections; missing countries are
not fabricated. Clients should identify an occurrence by section and ID.
