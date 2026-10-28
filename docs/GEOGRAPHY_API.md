# Country, state and city API

These public lookup APIs are intended for registration and profile forms. They
are rate limited to 60 requests per minute per IP and return only location
catalog data.

## Endpoints

| Method and path | Purpose |
|---|---|
| `GET /geo/countries?search=ind` | Browse countries; optional substring search. |
| `GET /geo/countries/IN/states?search=utt` | Browse ISO subdivisions for one country. |
| `GET /geo/countries/IN/cities?stateCode=UP&search=kan&perPage=50&page=1` | Search cities within a country, optionally by state. |

Country codes use ISO alpha-2 codes. `stateCode` is the subdivision code
returned by the states endpoint. City search requires at least two characters;
results are paginated (default 50, maximum 100) and sorted by population then
name. The cities response includes the GeoNames record ID, name, state, and
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
  "cities": [
    {"id": 1267995, "name": "Kanpur", "asciiName": "Kanpur", "stateCode": "UP", "stateName": "Uttar Pradesh", "latitude": "26.4652300", "longitude": "80.3497500", "population": 2823249}
  ],
  "pagination": {"page": 1, "perPage": 50, "total": 1, "lastPage": 1},
  "source": "GeoNames"
}
```

