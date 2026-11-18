# SEO profile details

SEO subject/city and hierarchical location result cards now display the saved
address, email, biography/description, and safe LinkedIn/YouTube links when those
fields are present. Teacher and institute detail pages share the same address
and contact display. Teacher LinkedIn and both provider types' YouTube fields
are supported; institutes do not currently have a LinkedIn profile field.

This intentionally changes the prior public-display contract at the project
owner's request: saved `address_text`, locality/sub-district/district, city,
state, postcode and country are public on eligible SEO profiles and cards.
Email is also displayed as a mailto link. The existing account eligibility,
suspension, registration and profile-publication checks still apply. Private
`location_details` enrichment JSON, GPS coordinates, credentials, guardian
details and the unmasked phone number are not rendered.

Positive trial durations use localized labels including the actual count, such
as `1 day trial` and `3 days trial`. Null/zero trial durations render no badge.
Trials are informational and do not introduce booking or click behavior.

Previously these SEO templates displayed no provider phone or `tel:` link.
Contact blocks now show `Call · 7800000xxx`: remove the country prefix using
the phone parser, show the first seven national-number digits, and mask every
remaining digit. Short numbers are entirely masked. Full phone values are
removed from view data before rendering. The contact link retains the existing
app-download route, never a `tel:` URL containing the complete phone number.

All profile text and URL attributes are escaped. Link schemes are restricted to
HTTP/HTTPS and YouTube hosts are validated. Empty fields are hidden. Shared
partials cover city/subject and country/district/locality/subject-locality cards;
detail pages use the same address/masking helpers. Hub pages list navigation
links rather than provider cards, so have no provider details to display.

This is a read/display change, with no new business mutations, events, or
historical backfill. Existing access/view tracking and profile reports retain
their contracts. It does not add contact/address values to business history.
The preceding YouTube profile migration must be deployed before pages read
`youtube_url`.

Rendered-template tests cover both listing layouts and both detail pages,
trial visibility/counts, complete addresses, email and social links, escaping,
missing fields and absence of complete phone numbers and tel links. Database
SEO integration tests require a disposable `REPORTING_TEST_DATABASE` ending
in `_test`.
