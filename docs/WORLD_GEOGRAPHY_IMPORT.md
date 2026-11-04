# Offline world geography import

The bundled snapshot contains **250 countries/territories, 5,327 administrative
divisions and 156,169 cities/towns/districts**. This is the source catalog's
coverage, not a claim that every settlement worldwide is included.

Source: https://github.com/dr5hn/countries-states-cities-database
Pinned commit: `c99697b53dddbf0e02c83c74899ffd716a247d6a`.
The derived database is distributed under ODbL-1.0; see
`database/data/geography/LICENSE`. Retain attribution when using/distributing it.
Only country code/name/phonecode, state names and city names are included.
Upstream numeric IDs are not application IDs; the database generates all IDs.
Phonecodes use digits only (for example `+1-242` becomes `1242`).

## Production deployment

Take the normal database backup, deploy the code including
`database/data/geography/`, then run from the Laravel project root:

```bash
php artisan migrate --force
php artisan db:seed --class=WorldGeographySeeder --force
```

For an installation whose other migrations are already managed independently,
you can run only the new migration:

```bash
php artisan migrate --path=database/migrations/2026_11_04_000001_prepare_world_geography_seeding.php --force
php artisan db:seed --class=WorldGeographySeeder --force
```

Use SSH/CLI. No production internet connection or additional API key is needed.
The seeder is deliberately separate from DatabaseSeeder (subject taxonomy).
It verifies SHA-256 before import and imports all rows plus its completion receipt
in one transaction. Allow disk space for indexes and transaction undo logs.
Use transactional tables (InnoDB on MySQL); nontransactional engines cannot roll back.
A failed import can be retried. A completed identical import is a no-op.
Do not run parallel schema migrations/imports.

**Existing populated geography tables cause the first import to stop, preserving
all existing records and IDs.** This seeder is for empty geography catalogs;
it does not merge or replace a different catalog. Never run `migrate:fresh`,
truncate tables, or delete the receipt on production to bypass this guard.
A merge requires a reviewed mapping for existing country/state/city references.
Legacy GeoNames schemas also stop with a clear error; automatic conversion would
lose fields or break existing IDs. The original geography migration now creates
the numeric schema on fresh installations; the new migration covers servers
where that migration was already applied or geography tables were imported via SQL.
Rollbacks preserve reference tables and the receipt deliberately.

Three Western Sahara cities have no upstream state. They are retained with a
null state_id rather than a fabricated state; the current state-scoped city API
does not expose these three records. Other imported cities use the existing API:

```text
GET /geo/countries
GET /geo/states?country_id=<returned country ID>
GET /geo/cities?state_id=<returned state ID>
```

Catalog loading creates no user/business state transitions. It does not add
business activity events or fabricate historical coverage. The seed receipt
records snapshot identity and completion time, not per-location change history.
The older `CITIES_IMPORT.md` describes a separate, incompatible legacy GeoNames
cities-only export and must not be used for this numeric API catalog.
