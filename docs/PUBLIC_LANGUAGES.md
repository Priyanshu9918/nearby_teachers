# Public language support

The shared catalogue in `resources/languages.json` defines the 14 supported UI
languages in picker order: English (`en`), Hindi (`hi`), Bengali (`bn`), Arabic
(`ar`), Urdu (`ur`), Spanish (`es`), French (`fr`), Portuguese (`pt`), German (`de`),
Russian (`ru`), Chinese (`zh`), Japanese (`ja`), Indonesian (`id`) and Vietnamese
(`vi`). Arabic and Urdu use right-to-left layout. All names are displayed in
their native script. PHP and the legacy Node service read the same allowlist.

## API and public pages

- `GET /i18n/languages` lists all 14 languages, native names and RTL flags.
- `GET /i18n/{code}` serves the full catalogue. Unknown codes fall back to English.
- Authenticated `PATCH /auth/preferred-language` accepts `{"language":"vi"}`
  and returns `{"ok":true,"preferredLanguage":"vi"}` for all supported codes.
- Public website selection uses explicit `?lang=`, then the `nt_lang` cookie,
  then a supported browser Accept-Language preference, then the page default.
- SEO pages accept all 14 UI choices regardless of their provider's country.
  Existing reviewed country/subject URL mappings and publication defaults remain
  separate from the UI allowlist. Switching UI language creates no catalogue
  entities or fake historical publication events.
- Referral invitations and SEO pages use the same shared picker in their header,
  with no duplicate footer selector. Other public layouts retain the footer picker.
- The shared website picker includes referral invitations, download pages,
  directories, profile pages, share/QR poster pages and other public layouts.
  It preserves query parameters such as referral codes and search filters,
  resets pagination, and persists explicit selection for later navigation.
  The web middleware writes an HTTP-only cookie through Laravel EncryptCookies;
  templates never overwrite it with a raw JavaScript cookie. This keeps SEO and
  referral selection consistent on the same application host. An explicit
  `?lang=` remains the highest-priority override by design.
- SEO controls (search/filter/navigation/contact/trials), referral instructions,
  share/copy/print controls and QR poster copy have translations for every code.
  Teacher-entered biographies, names, addresses and unreviewed course names
  remain their original content. Reviewed subject translations remain the
  responsibility of the existing SEO mapping system.

The existing privacy-policy document remains authoritative English content;
its public language selector and footer still support all 14 choices. Admin
and developer workspaces retain their existing English interface. These are
separate from the public/app translation catalogues.

## Validation and deployment

Tests verify complete catalogue keys and interpolation tokens, metadata, RTL,
preference validation, browser/cookie/query precedence and rendered referral,
SEO filter/header, share and QR poster controls for all languages. SEO database
integration tests require a disposable `_test` database. No application data is
migrated, cleared or fabricated by this feature.

Deploy the new JSON files, PHP/Blade changes and shared metadata together, then
run `php artisan optimize:clear` to refresh config and compiled views. If the
legacy Node service is deployed, restart it to reload the startup catalogues.
