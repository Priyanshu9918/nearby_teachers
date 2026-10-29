# Country, state and city API

These public lookup APIs are intended for registration and profile forms. They
are rate limited to 60 requests per minute per IP and return only location
catalog data.

## Endpoints

| Method and path | Purpose |
|---|---|
| `GET /geo/countries` | Complete stored country list with full names and codes; optional `search` narrows results. |
| `GET /geo/countries/IN/states` | All stored states/subdivisions for the selected country. |
| `GET /geo/countries/IN/cities?stateCode=UP&perPage=50&page=1` | Cities restricted to the selected country and state. Optional `search` narrows results. |

Country codes use ISO alpha-2 codes. `stateCode` is required for cities and must
be a subdivision code returned by the selected country states endpoint. Missing
or empty `stateCode` returns 422; an unknown state or one belonging to another
country returns 404. Country and state codes accept lowercase and normalize to
uppercase in the response. Unknown countries return 404. City search requires at least two characters;
results are paginated (default 50, maximum 100) and sorted by population, name and record ID for stable pagination. The cities response includes the GeoNames record ID, name, state, and
coordinates. These endpoints read country, state and city rows already stored
in the application database.

Example:

```http
GET /geo/countries/IN/cities?stateCode=UP&search=kan
Accept: application/json
```

```json
{
  "countryCode": "IN",
  "stateCode": "UP",
  "cities": [
    {"id": 1267995, "name": "Kanpur", "asciiName": "Kanpur", "stateCode": "UP", "stateName": "Uttar Pradesh", "latitude": "26.4652300", "longitude": "80.3497500", "population": 2823249}
  ],
  "pagination": {"page": 1, "perPage": 50, "total": 1, "lastPage": 1},
  "source": "GeoNames"
}
```


Dropdown flow: load `/geo/countries` without `search`, select a country, load its
`/states`, select a state, then load `/cities?stateCode=...`. Clear selected state
and city when country changes, and clear selected city when state changes.
Country and state lists are unpaginated. City lists remain bounded; use
`pagination.lastPage` to load further pages. These are read-only catalog lookups;
no business state or historical events are mutated. No schema migration needed.

Updated Postman requests are in the teacher/geography and institute collections,
and under Common in the app-team collection. Default examples no longer filter
countries to India or states to a name substring. Saved responses are abbreviated
mock examples; the API returns all matching stored catalog entries.
