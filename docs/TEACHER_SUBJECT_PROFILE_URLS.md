# Separate teacher share and SEO URLs

The existing SEO profile remains `/teacher/{name-city}`, for example
`/teacher/sahib-kanpur`, and continues serving HTTP 200. Subject selection does
not replace this canonical URL. Subject/location SEO listings and sitemaps retain
that existing profile URL.

A separate stored `subject_profile_slug` supplies the full-profile share URL:
`/teacher/sahib-english-kanpur`. It shows the same complete public profile,
including photo, all approved courses, fees/currency, story, lesson modes, trial,
address, email and social links. The first approved subject alphabetically (then
numeric ID) supplies the URL word; it is not designated a primary course.
Five courses create one extra share URL, not five extra teacher profile URLs.
Pending course requests never supply URL words or public course names.

`teacherSeoUrl` and `teacherProfileUrl` retain the original SEO URL.
`webPageUrl` and `myWebpageUrl` use the new share URL. Draft/unpublished URLs
remain null. Old `/my-webpage/teacher/{slug}` links redirect to the share URL.
The new share page is served directly without redirecting the existing SEO page;
its canonical metadata points to the original SEO profile to avoid introducing
a second competing indexed copy. Previous alias ownership remains reserved.

## Deployment and history

Migration `2026_11_18_000006_separate_teacher_share_urls.php` adds the independent
nullable share-slug column and reconciles existing published teachers in bounded
batches, one source transaction per profile. Migration 000005 is superseded and
is a no-op on new deployments. If 000005 already ran with the prior publisher,
000006 restores name/city canonical URLs through the original publisher rules
and retains subject URLs separately. No history or ownership records are removed.

Version 1 `teacher.share_url_changed` records old/new share slugs in the source
transaction. The existing `teacher.seo_state_changed` covers any actual canonical
restoration, not fabricated historical times. Current share slugs are explicitly
registered/searchable in the teacher profile report and normal streamed CSV;
existing admin protection, access logging and filter contracts apply. Source
failures roll back the slug, ownership and history together. Institute behavior
is unchanged.
