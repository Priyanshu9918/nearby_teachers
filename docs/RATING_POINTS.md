# API point rating

`rating_avg` in search, feed, public/owner provider profiles, student profiles,
authentication user payloads, session profiles, onboarding user payloads and
the referral leaderboard represents the current `users.referral_points` score.
100 points produces `rating_avg: 100`; 107 points produces `rating_avg: 107`.
No additional 100 is added while serializing a response. The registration bonus
must already be present in the database through migration `2026_11_05_000002`.

Where review aggregates previously appeared, `review_rating_avg` preserves the
real review average (including null when absent). `rating_count` starts at 100 and adds the actual review count: no reviews
returns 100; seven reviews returns 107. `review_rating_count` preserves the
actual count (0 or 7 respectively). Repeated serialization does not add the
baseline again. `referral_rating` retains its legacy five-star conversion,
so 100 points produces 0.5 there. Frontends displaying the point rating should
use `rating_avg` or `referral_points`, not `referral_rating`.

Example relevant fields for an account with no reviews:

```json
{
  "referral_points": 100,
  "rating_avg": 100,
  "referral_rating": 0.5,
  "review_rating_avg": 0,
  "rating_count": 100,
  "review_rating_count": 0
}
```

This is a response contract change from the former five-star review average.
Stored provider review averages and counts, review writes, report/CSV history,
and ranking queries remain unchanged. No business mutation or event is added.
Country aggregates and count-only responses do not have individual ratings.
