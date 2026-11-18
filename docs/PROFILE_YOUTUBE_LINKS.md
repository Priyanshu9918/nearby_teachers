# Profile YouTube links

Teacher and institute profile editor step 5 accepts optional `youtubeUrl`:

```http
PATCH /profiles/me/steps/5
Authorization: Bearer <token>
Content-Type: application/json

{"youtubeUrl":"https://www.youtube.com/@example"}
```

The existing teacher-only `/teacher/profile/steps/5` alias and shared
`PUT /profiles/me` also accept the field. Send `null` (or an empty form value)
to remove the link; omitting it preserves the saved value. Other step-5 fields
remain optional for partial edits. A YouTube link is not required to publish.

Only HTTP/HTTPS URLs on `youtube.com`, its subdomains, or `youtu.be` are accepted,
up to 2048 characters, without URL credentials. Channel, handle, video and Shorts
links use the same field. This does not verify channel ownership or fetch videos.

Owner GET responses expose `profile.youtube_url` and
`profileDraft.steps[4].data.youtubeUrl`. Step save responses also restore the
saved field in `profileDraft`. Existing registration and provider ownership
checks apply. Public teacher and institute pages link to valid saved URLs using
escaped attributes and `noopener noreferrer nofollow ugc`.

## Deployment and app integration

Deploy migration `2026_11_18_000001_add_youtube_url_to_provider_profiles.php` with
the API changes, and run `php artisan migrate --force` on the deployment server.
Existing rows start with null; no historical links are invented.

The mobile app source is not in this repository. Add an optional YouTube URL
input to both step-5 forms and bind it to the request/draft keys above. Teacher
LinkedIn and institute website inputs retain their existing keys. Developer
portal request examples and Postman collections include the new field.

## Reporting and history

The shared teacher/institute profile reports explicitly include and search
`youtube_url`; their existing admin-session, read logging, filters, pagination
and streamed CSV contracts apply unchanged.

Version 1 `teacher.youtube_link_updated` and `institute.youtube_link_updated`
events are recorded in the same transaction as changed links, including direct
profile updates. They retain before/after `has_link` flags only. A replacement
emits an event even if both flags are true. Identical values emit no link event.
Step saves also retain existing `profile_step_updated` and draft progress events.
History failure rolls back the link mutation. Historical URL values are not
archived; current URLs are available in the profile reports. No event is backfilled.

Unit tests cover URL validation and restored data. Provider integration tests
cover both account types, partial edits, clearing, rejected hosts, event metadata
and rollback, and require a disposable MySQL `ONBOARDING_TEST_DATABASE` ending
in `_test`.
