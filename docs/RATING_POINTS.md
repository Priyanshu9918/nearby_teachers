# Referral points and review ratings

Referral points and review ratings are separate response values. A new account
starts with 100 points from the database default. A successful referral-code
redemption adds 50 points to the code owner and 50 to the redeemer, once per
redeeming account. Earlier point awards and balances are not rewritten.

| Response field | Meaning |
|---|---|
| `referral_points` | Current account point balance |
| `referral_rating` | `round(min(5, max(0, referral_points) / 200), 2)` |
| `rating_avg` | Actual provider review average, or null when no reviews |
| `rating_count` | Actual review count; zero when no reviews |
| `review_rating_avg`, `review_rating_count` | Explicit aliases of the review values |

For an account with 150 points and no reviews, the response is:

```json
{
  "referral_points": 150,
  "referral_rating": 0.75,
  "rating_avg": null,
  "rating_count": 0,
  "review_rating_avg": null,
  "review_rating_count": 0
}
```

Learners have no provider reviews and expose null/zero review fields. Stored
provider review aggregates, review writes, referral history and search ranking
queries are unchanged. The response mapping is computed without rewriting
existing records. Older `referral.recorded` events retain their recorded
point deltas; new redemptions record a 50-point owner event and a 50-point
redeemer event in the same transaction.
