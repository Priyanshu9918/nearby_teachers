# Cities-only server import

This export contains only the application's existing cities/places catalog, not
country or state records. Format: gzip-compressed JSON Lines (`.jsonl.gz`),
version 1. There are 5,232,119 city/place records from the existing GeoNames
catalog. Each line is JSON; this is a streaming import file, not one large JSON
array and not a Postman collection.

Upload these two files to the Laravel project root on the server:

- `cities.jsonl.gz`
- `cities-import-kit.tar.gz`

Then run from the Laravel project root:

```bash
tar -xzf cities-import-kit.tar.gz
php artisan geography:import-cities cities.jsonl.gz
```

The kit contains the GeographyTransfer service, the export/import Artisan commands
and this guide. It does not contain country or state data. The server must already
have the `countries`, `states` and `cities` table schema; countries/states must be
populated. No migration is required when those tables already exist. The command
uses the server Laravel `.env` database configuration.

The importer maps each exported `country_code` + `state_code` to the server's
existing state ID. Local and server state IDs need not match. Unknown countries
or states cause an error and rollback; existing parent records are never changed.
Cities with no catalog state retain a null `state_id`. City GeoNames IDs are
preserved.

The cities table must be empty. Existing cities cause the import to stop without
changing data. The importer never truncates, deletes or overwrites catalog rows.
It inserts batches inside one transaction; invalid/truncated input or a database
error rolls back the import. Run from SSH/CLI and let the command finish. For this
large dataset, allow sufficient database disk space for the data, indexes and
transaction undo log. Memory use is bounded by the batch and country/state maps.

Successful output:

```json
{"cities":5232119}
```

Verify the uploaded file before importing if desired:

```bash
sha256sum cities.jsonl.gz
```

Expected SHA-256:

```text
faffd6c9806c17d9fb35d8fd80c538d8d0548a37e7185b17eff38d8f0093947e
```

After import, use the existing APIs:

```text
GET /geo/countries/IN/states
GET /geo/countries/IN/cities?stateCode=UP&perPage=50&page=1
```

To create a new cities-only export from a populated installation:

```bash
php artisan geography:export-cities cities-new.jsonl.gz
```

Export reads only the catalog. Both commands use explicitly allowlisted catalog
columns; no user profiles, credentials or business history are exported.
