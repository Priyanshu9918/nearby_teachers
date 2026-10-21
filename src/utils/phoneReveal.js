const { query } = require('../db/pool');

// 5 distinct teachers/institutes whose phone number a learner can view
// per day. Re-viewing a profile already unlocked today is always free
// — the quota is about how many NEW phone numbers you see today, not
// how many times you look. Calendar-day reset (not a rolling 24h
// window), since "on a daily basis" is how this was specified — simpler
// to reason about, and resets predictably at midnight rather than on a
// per-user sliding clock.
const DAILY_PHONE_REVEAL_LIMIT = 5;

/**
 * Checks whether `viewerId` can see `profileUserId`'s phone number
 * right now, and records the reveal if it's a new one. Returns:
 *   { allowed: true }                                    — already revealed today, or quota not yet hit
 *   { allowed: false, remainingToday: 0 }                 — quota hit, this would be a NEW profile today
 */
async function checkAndRecordPhoneReveal(viewerId, profileUserId) {
  // Already revealed this exact profile today? Free — no quota impact.
  const already = await query(
    `SELECT id FROM phone_reveals
     WHERE viewer_id = $1 AND profile_user_id = $2
       AND revealed_at >= date_trunc('day', now())
     LIMIT 1`,
    [viewerId, profileUserId]
  );
  if (already.rows[0]) return { allowed: true };

  const countResult = await query(
    `SELECT count(DISTINCT profile_user_id) AS distinct_count FROM phone_reveals
     WHERE viewer_id = $1 AND revealed_at >= date_trunc('day', now())`,
    [viewerId]
  );
  const distinctToday = Number(countResult.rows[0].distinct_count);

  if (distinctToday >= DAILY_PHONE_REVEAL_LIMIT) {
    return { allowed: false, remainingToday: 0 };
  }

  await query(
    `INSERT INTO phone_reveals (viewer_id, profile_user_id) VALUES ($1, $2)`,
    [viewerId, profileUserId]
  );
  return { allowed: true, remainingToday: DAILY_PHONE_REVEAL_LIMIT - distinctToday - 1 };
}

module.exports = { checkAndRecordPhoneReveal, DAILY_PHONE_REVEAL_LIMIT };
