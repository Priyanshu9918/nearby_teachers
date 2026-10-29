# International teacher SEO directory

This replaces the former two-page teacher SEO design. There is now **one canonical page per teacher**, plus country/city/subject and country/online/subject listing pages. Existing registration, institute address/GPS and messaging functionality remain in place.

## Public address contract

Default origin:

```text
http://157.245.125.79/nearby_teachers/public
```

Configure `SEO_PUBLIC_URL` with this origin for the current server. Public teacher/listing requests on another origin redirect to it. When the live domain is ready, change this setting to `https://teachers.britishschooloflanguages.com`. The SEO origin's homepage redirects to `/directory`.

| Page | Route | Example |
|---|---|---|
| Subject + city | `/{country}/{city}/{subject-slug}` | `/in/kanpur/maths-tuition` |
| Country online | `/{country}/online/{subject-slug}` | `/in/online/ielts-coaching` |
| Country directory | `/{country}` | `/in` |
| District directory | `/{country}/{district}` | `/in/kanpur-nagar` |
| Village/area directory | `/{country}/{district}/{village}` | `/in/kanpur-nagar/bithoor` |
| Subject in village/area | `/{country}/{district}/{village}/{subject-slug}` | `/in/kanpur-nagar/bithoor/maths-tuition` |
| Teacher, country default language | `/teacher/{name-city}` | `/teacher/asha-sharma-kanpur` |
| Browse real published listings | `/directory` | `/directory` |

Client examples are supported by the starter Maths/IELTS mappings:

```text
/in/kanpur/maths-tuition
/gb/london/maths-tutor
/us/houston/math-tutoring
/es/madrid/clases-particulares-de-matematicas
/ae/dubai/maths-tutor
/in/online/ielts-coaching
/us/online/ielts-prep
/in/kanpur/maths-tuition?lang=hi
/teacher/asha-sharma-kanpur
```

Location pages are created when an eligible teacher publishes or when SEO sync
processes that teacher. District comes from the saved teacher profile's explicit
`district`; the area uses `locality`, falling back to `city`. Subject-area pages
use the enabled default-language subject URL mapping for that country. Pages with no
currently eligible teachers return 404 and are omitted from the location sitemap.
They show matching teacher profiles, referral-point ordering, location/subject
links, pagination and an in-page language selector. Country, district, village and subject
pages use their own SEO title/description and canonical URLs.

Teacher subjects are **not concatenated into the profile URL**. All selected subjects remain visible in the profile and determine listing membership.

## Country and language decisions

Country selection uses the explicit profile country name when supplied, otherwise a recognized `country_code`. Names/codes are matched against developer-maintained aliases in `config/seo.php`. A missing/unsupported country is **not inferred from IP, city or language**: the teacher stays unpublished in the international directory until corrected and saved/synced.

| Country | Default page language | Additional versions |
|---|---|---|
| India (`in`) | English | Hindi, Bengali, Urdu |
| UK (`gb`), USA (`us`), Canada (`ca`), Australia (`au`) | English | — |
| Spain (`es`) | Spanish | English |
| UAE (`ae`) | Arabic | English |
| Russia (`ru`) | Russian | — |
| China (`cn`) | Chinese | — |
| Japan (`jp`) | Japanese | — |
| Bangladesh (`bd`) | Bengali | — |
| Pakistan (`pk`) | Urdu | — |
| France (`fr`) | French | — |
| Portugal (`pt`), Brazil (`br`) | Portuguese | — |

These are explicit initial configurations, not guesses about a country's sole language. Adding countries/languages requires matching native UI copy and reviewed subject wording. Changing a country's established default language requires a planned URL migration, not an unreviewed config change.

Canonical paths contain no language segment. The page language selector uses a `lang` query parameter for translated headings and subject display names; every language shares the same clean canonical URL and sitemap entry. Legacy language-in-path URLs permanently redirect to the clean path. Locale labels are in `resources/seo/{language}.json`; Arabic/Urdu pages use RTL direction. The English messaging/download CTA is preserved as requested.

Profile names, biographies and reviews are user-authored text and are not automatically machine-translated. The surrounding interface/headings, approved subject names and listing descriptions are localized. Do not claim an English biography has been translated merely because the page language changed.

City slugs normalize case/spacing/punctuation and use curated native-city aliases where configured, such as 東京 → tokyo and دبي → dubai. Other names use Laravel transliteration. Untransliterable city names have a stable ASCII hash fallback; administrators should extend the curated aliases for preferred English city names. Untransliterable teacher names use `teacher` in the slug. Duplicate slugs add the unique teacher ID only when needed; slugs are bounded and old ownership is retained.

## Registration and edit scenarios

1. Asha registers in India/Kanpur with five subjects and online + in-person modes.
2. Registration persists one teacher URL: `/teacher/asha-sharma-kanpur`.
3. Each configured subject/language mapping creates or reuses a shared Kanpur listing.
4. Online mode also registers/reuses that country's online listing for the subject.
5. Another matching teacher joins the same listing rather than creating a duplicate page.
6. Removing a subject removes the teacher from that listing on the next read. Removing online mode removes online membership. Counts come from current qualified profiles.
7. Changing name/city reserves a new teacher URL; previous URLs redirect directly to the current page. Country changes select the new country/language and listings. Teacher URLs contain no subject list or country segment.
8. Empty, disabled or unavailable listings return `404` and are excluded from hub/sitemaps. The admin inventory retains them with status/count rather than deleting history.

**Online country rule:** `/in/online/...` lists India-based teachers offering online lessons. It does not imply all worldwide teachers who might accept Indian learners. A future served-countries feature would require separate data.

City listings match country plus normalized city slug and include eligible teachers with that subject, including online teachers based in the city. The client URL does not distinguish two cities with the same name in different states; they share the country/city listing. Finer manual addresses and Google `location_details` are never used to infer SEO geography.

Eligibility requires current teacher account type, completed registration, verified phone, non-suspended account, nonblank name/city, a supported explicit country and at least one selected subject. Eligibility is checked again on reads; a suspended/deleted profile cannot remain visible because metadata was previously registered.

`POST /auth/register`, `POST /auth/onboarding/complete`, `PATCH /auth/onboarding`, `PUT /profiles/me` and existing admin teacher name/location edits synchronize the metadata. Both legacy response keys now point to the same canonical page:

```json
{
  "teacherSeoUrl": "http://157.245.125.79/nearby_teachers/public/teacher/asha-sharma-kanpur",
  "teacherProfileUrl": "http://157.245.125.79/nearby_teachers/public/teacher/asha-sharma-kanpur"
}
```

For profiles without enough country/publication data, these URLs are null. There are no invented default countries. Selected subjects remain `primarySubjects` for teacher registration/edits. Institute registration and address/GPS contracts are unchanged.

## Subject wording and translation approval

`seo_subject_routes` stores subject ID, country, language, localized display name, city slug, online slug and enabled status. Slugs do not get translated dynamically per request.

Starter native wording exists for Maths, Physics, Chemistry, English, Hindi and IELTS. English-language countries can seed descriptive English slugs for other catalog subjects. For other languages, an unknown subject waits for an administrator to supply localized wording; the application does not publish English-only copy as a translated listing. Catalog subjects that would collide with an existing slug also need explicit separate wording; distinct subject IDs are not silently merged.

These are editable starter phrases and should receive client editorial review. The long IELTS catalog name is recognized as IELTS. English vs Spanish Maths wording and US IELTS prep vs Indian IELTS coaching follow the client examples.

Admin edits can change display names/slugs or disable mappings. Slug changes update registered listing paths and retain old URL aliases. An existing mapping keeps its subject/country/language identity; create another mapping to add a different variant. Historical URL ownership cannot be reassigned to another teacher/listing.

## Admin screens

- **Sidebar → SEO pages** (`/admin/reports/seo-pages`): country, language, city/online kind, path, eligible-teacher count, enabled flag and public-page link. Status is Published, Disabled or No matching teachers.
- **Sidebar → SEO subject wording** (`/admin/seo/subjects`): create/edit reviewed country/language subject names and slugs, enable/disable mappings and sync existing profiles.
- **Reports → SEO subject wording** (`/admin/reports/seo-subject-routes`): searchable/exportable mappings with Edit wording links.
- **Reports → SEO URL ownership and redirects** (`/admin/reports/seo-url-history`): reserved current/historical URL ownership.
- **Reports → Teacher profiles**: current `public_page_slug`, normalized SEO country/city and publication eligibility, with Open teacher page. Missing country/ineligible profiles show a pending message. Retired long-slug columns are no longer the active report columns.

All report definitions are registered in `config/reports.php`, with explicit output/search/timestamp/participant contracts. They use the shared query/filter services, bounded pages, streamed CSV and report access logger. Mapping reads/writes and sync actions require the admin session, with CSRF protection for mutations and audit logging. No HTTP parameter can provide SQL/table/column names.

## Canonical URLs, sitemap and content

- Every teacher has a single canonical URL. Legacy overview/full-profile routes are redirects, not separate pages.
- Each country/location/subject page has one short canonical path with no language segment. Language query variants point to the same canonical URL and are not separate sitemap entries.
- `en-IN`, `hi-IN`, `bn-IN`, `ur-IN`, `en-GB`, `es-ES`, `ar-AE`, `ja-JP`, etc. use language-region codes. Different cities are not presented as translations of one another.
- Listing pages are paginated at 20 teachers, with a clean canonical page URL and matching pagination. The selected language is a display preference, not another indexed path. Invalid/out-of-range pages return `404`.
- Native page templates display real matching profiles, available fees, teaching modes and actual ratings. Profiles include current subjects, approved avatar, biography, safe LinkedIn, real reviews (10 per page) and messaging/download CTA. Exact address JSON, postcode, phone, email, credentials and guardian data are not added to public SEO templates.
- Titles, descriptions, Open Graph/Twitter tags and escaped JSON-LD use the actual profile/listing. There are no fabricated credentials/review stars or rich-result promises.
- `/sitemap.xml` is a streamed sitemap index. `/sitemaps/teachers-{n}.xml` and `/sitemaps/directory-{n}.xml` stream at most 1,000 entries per part. Only currently eligible/available pages are included; listing XML also includes language alternatives.
- `robots.txt` advertises the configured SEO-domain sitemap and disallows admin/developer paths. The subdomain must be verified in Search Console and its sitemap submitted through the site's normal operational process.

See Google's [localized-page guidance](https://developers.google.com/search/docs/specialty/international/localized-versions) and [spam policies](https://developers.google.com/search/docs/essentials/spam-policies). URL wording alone does not guarantee indexing or ranking; pages need useful matching content.

## Legacy compatibility and storage

Migration `000042` adds current teacher SEO state and tables for subject mappings, directory pages and URL ownership. Existing `slug`, `seo_slug` and `public_profile_slug` columns remain as legacy aliases/app identifiers; no teacher or business history is deleted.

Old `/teachers/{long-subject-slug}`, `/teachers/{name-id}/profile`, `/site/teacher/{slug}` and `/site/teacher-profile/{slug}` requests redirect to the one current teacher page after synchronization. Long legacy paths are stored as TEXT with SHA-256 indexed ownership, avoiding truncation. Future renames keep previous paths reserved. Deleting a teacher makes old links unavailable; another teacher cannot take over their reserved URL.

App download/deep links continue using the existing app `slug`. The new public teacher slug is separate. Existing institute pages and the older generic city/feed screens remain outside this new subject directory; the new SEO sitemap does not advertise the former two teacher-page variants.

## History coverage

Version 1 events in `BusinessActivityRecorder`:

- `teacher.seo_state_changed`: publication/ineligibility state, current public slug, public country/city slug, language, selected subject IDs and online flag. It also records changes that affect listing membership without a teacher URL change.
- `seo.route_configured`: curated country/language wording, slugs and enabled flag, for initial mappings and later admin edits.
- `seo.page_registered` / `seo.page_updated`: new shared listing identity/path and later URL changes.
- `seo.location_page_registered`: country, district, village/area and subject-area URL registration.

Metadata/URL ownership and their events are written inside the source transaction. Failure rolls back source edits, page metadata and history together. Snapshot fields are allowlisted; no raw requests, user objects, exact private addresses, OTPs or credentials are recorded. Historical legacy events remain append-only under their old contracts.

Backfill events use the actual sync time rather than invented historical publication dates. CLI/system and admin mapping events have no fabricated teacher actor. Ordinary teacher profile edits use the authenticated teacher as actor. Private address/GPS histories retain their existing separate contracts.

Teacher views use `page_views.page_type = teacher`, target `profile:<id>`. Listing views reuse `city_index`, target `listing:<id>`; online vs city is identified through the registered page. Redirects are not rendered views. Logging remains best effort. Unsupported countries, missing translations, direct SQL/catalog edits, DNS/TLS setup, traffic before tracking and user-authored content translations are not reconstructed.

## Deployment

```dotenv
SEO_PUBLIC_URL=http://157.245.125.79/nearby_teachers/public
```

1. For now, use `http://157.245.125.79/nearby_teachers/public`. When the live domain is ready, point it to the Laravel application, configure HTTPS, and change `SEO_PUBLIC_URL` to `https://teachers.britishschooloflanguages.com`.
2. Deploy the code with the SEO metadata migrations:

```bash
php artisan migrate --force --path=database/migrations/2026_10_27_000042_create_international_seo_directory.php
php artisan migrate --force --path=database/migrations/2026_10_28_000003_create_seo_location_pages.php
php artisan seo:sync
```

3. Refresh route/view/config caches according to the deployment process. Review mappings and pending profiles in the admin screens.
4. Test the configured public hostname, default/extra-language pages, old-link redirects and sitemap before submitting to Search Console.

Sync is also available through the admin button. It processes profiles in batches of 100, recording current publication state. Existing students/institutes/private address data are not rewritten. No Google API key or paid translation service is needed for this directory. Configuration and mappings intentionally do not generate a world-wide empty URL grid.

## Verification

Integration databases must be disposable and end in `_test`:

```bash
DB_DATABASE=nearby_teachers_seo_test \
REPORTING_TEST_DATABASE=nearby_teachers_seo_test \
ONBOARDING_TEST_DATABASE=nearby_teachers_seo_test \
vendor/bin/phpunit tests/Feature/TeacherPublicPagesTest.php tests/Feature/OnboardingPersistenceTest.php tests/Feature/TeacherGeocodingTest.php tests/Feature/ApiCatalogTest.php tests/Feature/ExampleTest.php
```

Coverage includes country/subject wording, local-language HTML and RTL, language-free canonical paths and redirects from older language URLs, one teacher page, membership/online filters, missing countries, duplicate names, old links/long aliases, pagination, streamed sitemaps, admin mapping edits/disable, CSV access logging, privacy and transaction rollback. Desktop/mobile static previews can be rendered with fictional teacher data without altering application data.
