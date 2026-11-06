# BSL subject search

Run the new migration before using the feature. In Admin > Institutes, edit an existing institute and enable **BSL search institute**. Alternatively an authenticated API admin can PATCH `/profiles/institute/{userId}/bsl` with `{"is_bsl":true}`. These API routes have no `/api` prefix in this application's bootstrap configuration.

Only one institute can have `is_bsl=true`; a generated nullable unique slot also enforces this at database level. Disable the current institute before enabling another. Existing BSL Verified badge behavior is independent and unchanged.

Enabling BSL removes existing non-BSL subject assignments and assigns all currently BSL-marked subjects in the same transaction. Re-enabling is idempotent. Creating or updating subjects through the admin catalog adds missing BSL subjects to the selected institute. Direct database edits/imports are outside this synchronization contract. Disabling the institute retains its subject assignments.

Pass `subjectId` to `/search`, `/search/dashboard`, `/search/counts`, or `/search/other-city`; `/search/by-subject/{subjectId}` uses its path parameter. For BSL subjects only the designated institute is returned, independently of radius, city, country or lesson mode. Suspended institutes are excluded. Free-only filtering still applies. No designated institute means empty results, with no teacher fallback. BSL subject searches do not require city, state, country or coordinates on any search endpoint, including `/search/filter`. Supplied values are still validated; other required parameters and non-geographic filters remain in effect. Dashboard center coordinates are null when omitted. Dashboard and counts accept the new optional subjectId; non-BSL behavior remains unchanged. BSL routing uses subjectId only; q retains ordinary provider-name search and does not resolve courses. In the BSL subject branch q does not filter the designated institute.


The institute report and its shared CSV export explicitly expose `is_bsl`. Version 1 events `institute.bsl_search_changed` (before/after flag) , `institute.bsl_subjects_removed` (removed non-BSL numeric subject IDs) and `institute.bsl_subjects_assigned` (added numeric subject IDs) are recorded in the source transaction. Browser admin actions have no application-user actor; API actions record the authenticated admin ID. No historical backfill is inferred. Existing independently editable subject assignments and direct database changes are not reconstructed by these events.

Integration checks are in SearchDashboardTest and require a disposable MySQL database supplied via ONBOARDING_TEST_DATABASE ending in `_test`.

For `/search/other-city`, city may be omitted when subjectId identifies a BSL subject. Ordinary searches still require city.

`/search/other-city` also includes the designated, non-suspended BSL institute for any city/country search without a subject, regardless of its city or online availability. It sorts first within the bounded results. Ordinary providers still require online availability and matching location. Explicit non-BSL subject and name filters still apply. Results expose a boolean `is_bsl` so clients can preserve this geographic exception.

Provider results from `/search/other-city` and every provider-list tier of `/search/by-subject/{subjectId}` include `subjects: [{id, name, is_bsl}]` for assigned courses, or `[]` when none are assigned. Country aggregate responses retain their existing shape.
