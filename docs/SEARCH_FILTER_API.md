# Public search filters

`GET /search/filter` requires no Authorization header or login. It shares the
public search throttle of 60 requests/minute. Existing search endpoints retain
their current contracts. Use `/subjects` for subject/category dropdown values.

| Parameter | Contract |
| --- | --- |
| `mode` | Required: `nearby`, `other-city`, `online` |
| `lat`, `lng` | Optional paired latitude/longitude for nearest-first ordering in any mode; both required when radiusKm is supplied |
| `radiusKm` | Optional radius in km; greater than zero, at most 500. Omit for no distance limit; requires lat/lng |
| `city`, `country` | Other-city requires at least one. Case-insensitive exact match; both supplied means both must match. Optional destination restrictions for online mode. |
| `subjectId` | One existing numeric subject ID |
| `q` | Optional provider/user listing name search, maximum 200 characters |
| `maxPrice` | Inclusive maximum hourly price; zero selects free listings |
| `minPrice` | Inclusive minimum hourly price; use 2000 for the ₹2000+ chip |
| `currency` | Three-letter currency, defaults to INR. Price filters compare only this currency, with no conversion. Explicit currency also filters without a price bound. |
| `freeOnly` | Literal `true` or `false`; true requires price zero and cannot combine with a positive minPrice |
| `lessonMode` | `online`, `in_person`, or `home_tuition` |
| `trialDays` | Exact integer 0–7: 0 = no trial, 7 = one week |
| `page`, `perPage` | Defaults 1/20; page at most 10000, perPage at most 50 |

Online mode always requires online lessons; a conflicting lessonMode returns
422. Other-city can return any class mode unless lessonMode is supplied.
With lat/lng, results in every mode sort nearest to farthest. An explicit radiusKm limits distance; without it, all matches remain eligible, including providers without GPS. Providers without valid GPS have null distance and sort last. Without lat/lng there is no distance sorting or cutoff. To clear filters omit the corresponding
parameters; do not send empty strings. Invalid values return 422 validation errors.

```http
GET /search/filter?mode=nearby&lat=28.58&lng=77.31&subjectId=18&radiusKm=7&maxPrice=500&currency=INR&lessonMode=online&trialDays=3
GET /search/filter?mode=nearby&lat=28.58&lng=77.31&subjectId=18&maxPrice=500
GET /search/filter?mode=other-city&city=Noida&subjectId=18&maxPrice=1000&lessonMode=home_tuition
GET /search/filter?mode=online&country=India&subjectId=18&minPrice=2000&trialDays=7
```

Response includes `mode`, `totalCount`, `page`, `perPage`, and `results`.
Results contain public identity, city/state/country, price/currency, lesson_modes,
trial_days, ratings, referral points/rating, approved avatar and presence status.
They omit contact details, exact coordinates and private addresses. distance_km
is null when caller or provider coordinates are unavailable. Only complete provider profiles with matching
account types, completed registration, verified phones and no suspension qualify.
Filtering occurs before counts and pagination. With lat/lng, sort order is known distance first, distance ascending, referral
points descending, provider type, then numeric user ID. Without coordinates,
referral points descending, provider type and numeric user ID provide stable
ordering. All matches are available across bounded pages, rather than one
unbounded response.

For a BSL subject, the existing BSL discovery rule applies: return the designated
BSL institute, bypassing geography and ordinary subject membership. Price,
currency, explicit lesson mode, trial, name and publication checks still apply.

## Deployment and reporting

Deploy the code and run the new migration before calling the endpoint:

```bash
php artisan migrate --force --path=database/migrations/2026_11_05_000003_add_search_analytics_filters.php
```

Filtered searches append to the existing `search_analytics` history, recording
allowlisted min/max price, currency, lesson mode and trial days alongside mode,
subject, rounded coordinate bands, radius and total matched count. Raw requests,
names/search text, credentials and exact coordinates are not recorded. When both
city and country are supplied, history retains city as its single location field;
it cannot reconstruct the second destination. Historical records have null new
filter fields; history is not backfilled. Currency is recorded only when a
price/currency constraint was applied.

The shared `no-result-searches` admin report and streamed CSV explicitly expose
the new columns with existing filters, admin session protection and access logs.
No new business state event is needed for this read-only search; search history
uses the existing append-only search recorder. Integration tests use disposable
databases ending in `_test` and cover anonymous access, combined filters,
zero/null prices, publication/privacy, BSL behavior, counts/pagination, validation,
and admin report/export access logging.

Each provider in `results` includes `subjects: [{id, name, is_bsl}]` containing its assigned subjects, or an empty array when none are assigned.
