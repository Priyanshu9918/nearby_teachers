const express = require('express');
const rateLimit = require('express-rate-limit');
const { requireAuth, optionalAuth } = require('../middleware/auth');
const { requireAdmin } = require('../middleware/admin');
const { checkAndRecordPhoneReveal } = require('../utils/phoneReveal');
const { query } = require('../db/pool');
const { slugify, withRandomSuffix } = require('../utils/slug');
const { logPageView } = require('../utils/analytics');
const { currencyForCountry } = require('../utils/currency');

const router = express.Router();

// Applied only to the public single-profile GET below — this is the
// endpoint someone could hit repeatedly with sequential/enumerated IDs
// to bulk-scrape every teacher/institute's public info (name, bio,
// location, LinkedIn). 60/minute per IP is generous for real browsing,
// slow enough to make systematic enumeration impractical.
const profileViewLimiter = rateLimit({
  windowMs: 60 * 1000,
  limit: 60,
  standardHeaders: true,
  legacyHeaders: false,
  message: { error: 'Too many requests. Please slow down and try again shortly.' },
});

// First-time publish: generate the public website URL slug for a teacher
// or institute profile. Once set, it never changes on later edits — a
// stable URL matters more than a slug that stays perfectly in sync with
// the latest name/city. table must be 'teacher_profiles' or
// 'institute_profiles'.
async function ensureSlug(table, userId, name, city) {
  const existing = await query(`SELECT slug FROM ${table} WHERE user_id = $1`, [userId]);
  if (existing.rows[0].slug) return;

  const base = slugify(name, city);
  let slugToUse = base;
  for (let attempts = 0; attempts < 5; attempts++) {
    try {
      await query(`UPDATE ${table} SET slug = $1 WHERE user_id = $2`, [slugToUse, userId]);
      return;
    } catch (err) {
      if (err.code === '23505') { // unique_violation — slug taken, retry with a suffix
        slugToUse = withRandomSuffix(base);
      } else {
        throw err;
      }
    }
  }
}

// Update the caller's own teacher or institute profile. account_type on
// the user row decides which table gets written.
router.put('/me', requireAuth, async (req, res) => {
  const { accountType, id } = req.user;
  const {
    bio, description, ratePerHour, startingRate, currency,
    lessonModes, latitude, longitude, addressText, city, state, country, countryCode, instructorsText,
    linkedinUrl, trialDays, websiteUrl, subjectIds,
  } = req.body;

  if (trialDays !== undefined && (!Number.isInteger(trialDays) || trialDays < 0 || trialDays > 7)) {
    return res.status(400).json({ error: 'trialDays must be an integer from 0 to 7' });
  }
  const trialDaysParam = trialDays === undefined ? null : trialDays;

  // The teacher/institute's currency defaults to their own country's
  // real local currency the moment they set a country — not a single
  // currency imposed on everyone. An explicit currency from the
  // client always wins (someone may deliberately want to quote in a
  // different currency); only when they haven't set one AND they're
  // setting/changing their country do we derive it. If neither
  // currency nor countryCode is present in this request, the SQL
  // COALESCE below preserves whatever's already on the row — so a
  // request that's only updating, say, the bio doesn't accidentally
  // overwrite an already-correct currency.
  const effectiveCurrency = currency || (countryCode ? currencyForCountry(countryCode) : undefined);

  if (accountType === 'teacher') {
    if (bio && bio.trim().split(/\s+/).length > 14) {
      return res.status(400).json({ error: 'Bio must be 14 words or fewer' });
    }
    await query(
      `UPDATE teacher_profiles SET
         bio = $1, rate_per_hour = $2, currency = COALESCE($3, currency),
         lesson_modes = $4,
         location = ST_SetSRID(ST_MakePoint($5, $6), 4326)::geography,
         address_text = $7, city = $8, state = $9, country = $10, country_code = $11,
         linkedin_url = $12, trial_days = COALESCE($13, trial_days), updated_at = now()
       WHERE user_id = $14`,
      [bio, ratePerHour, effectiveCurrency, lessonModes, longitude, latitude, addressText, city, state, country, countryCode, linkedinUrl, trialDaysParam, id]
    );
    await ensureSlug('teacher_profiles', id, req.user.full_name, city);
  } else if (accountType === 'institute') {
    if (description && description.trim().split(/\s+/).length > 14) {
      return res.status(400).json({ error: 'Bio must be 14 words or fewer' });
    }
    await query(
      `UPDATE institute_profiles SET
         description = $1, starting_rate = $2, currency = COALESCE($3, currency),
         lesson_modes = $4,
         location = ST_SetSRID(ST_MakePoint($5, $6), 4326)::geography,
         address_text = $7, city = $8, state = $9, country = $10, country_code = $11,
         instructors_text = $12, trial_days = COALESCE($13, trial_days), website_url = $14, updated_at = now()
       WHERE user_id = $15`,
      [description, startingRate, effectiveCurrency, lessonModes, longitude, latitude, addressText, city, state, country, countryCode, instructorsText, trialDaysParam, websiteUrl, id]
    );
    await ensureSlug('institute_profiles', id, req.user.full_name, city);
  } else {
    return res.status(400).json({ error: 'Only teacher or institute accounts have a listing profile' });
  }

  if (Array.isArray(subjectIds)) {
    if (subjectIds.length > 5) {
      return res.status(400).json({ error: 'You can select up to 5 courses/subjects' });
    }
    await query(`DELETE FROM profile_subjects WHERE profile_type = $1 AND profile_id = $2`, [
      accountType,
      id,
    ]);
    for (const subjectId of subjectIds) {
      await query(
        `INSERT INTO profile_subjects (profile_type, profile_id, subject_id) VALUES ($1, $2, $3)`,
        [accountType, id, subjectId]
      );
    }
  }

  res.json({ ok: true });
});

// Public profile view (learner tapping into a teacher or institute card
// inside the app). Every real view is logged here — this is what powers
// the "profile views" stat on the Requests dashboard (GET /profiles/me/stats
// below), rather than that number being a display-only placeholder.
router.get('/:type(teacher|institute)/:id', profileViewLimiter, optionalAuth, async (req, res) => {
  const { type, id } = req.params;
  const table = type === 'teacher' ? 'teacher_profiles' : 'institute_profiles';

  logPageView({ pageType: 'app_profile', target: id, req });

  const profileResult = await query(
    `SELECT p.*, u.full_name, CASE WHEN u.avatar_status = 'approved' THEN u.avatar_url ELSE NULL END AS avatar_url, u.phone,
            (SELECT count(*) FROM bookings b WHERE b.provider_id = p.user_id AND b.status = 'completed') AS lessons_completed
     FROM ${table} p JOIN users u ON u.id = p.user_id
     WHERE p.user_id = $1`,
    [id]
  );
  if (!profileResult.rows[0]) return res.status(404).json({ error: 'Not found' });

  const profile = profileResult.rows[0];
  // Phone number (and therefore the Call/WhatsApp buttons) only for a
  // signed-in requester — req.user is set by optionalAuth only when a
  // valid token was presented, never assumed. An anonymous/logged-out
  // visitor (including anyone hitting this via the public website,
  // which never sends a token) gets everything else on the profile,
  // just not the phone number.
  //
  // For a signed-in requester, phone visibility is further gated by a
  // daily quota — 5 DISTINCT phone numbers per day; re-viewing a
  // profile already unlocked today never consumes more quota.
  // Messaging and booking are deliberately NOT limited by this at all,
  // only the phone-number reveal itself.
  let phoneQuota = null;
  if (!req.user) {
    delete profile.phone;
  } else {
    const quotaResult = await checkAndRecordPhoneReveal(req.user.id, id);
    phoneQuota = quotaResult;
    if (!quotaResult.allowed) {
      delete profile.phone;
    }
  }

  const subjectsResult = await query(
    `SELECT s.id, s.name, s.is_bsl FROM profile_subjects ps
     JOIN subjects s ON s.id = ps.subject_id
     WHERE ps.profile_type = $1 AND ps.profile_id = $2`,
    [type, id]
  );

  res.json({
    profile,
    subjects: subjectsResult.rows,
    ...(phoneQuota && !phoneQuota.allowed
      ? { phoneLimitReached: true, phoneRevealsRemainingToday: 0 }
      : phoneQuota
      ? { phoneRevealsRemainingToday: phoneQuota.remainingToday }
      : {}),
  });
});

// Admin grants or revokes verification. NOT currently surfaced anywhere
// in the product — the app and public website show only the BSL Verified
// badge (see /:type/:id/bsl-verified below, institute-only). This
// endpoint is left in place rather than deleted since it costs nothing
// to keep and a future "regular Verified" badge could reuse it, but as
// of this build nothing calls it and the admin dashboard's UI for it
// has been removed. Sets verified_at when granting so a profile could
// show "Verified since [date]" if this is ever reintroduced.
router.patch('/:type(teacher|institute)/:id/verify', requireAuth, requireAdmin, async (req, res) => {
  const { type, id } = req.params;
  const { verified } = req.body;
  const table = type === 'teacher' ? 'teacher_profiles' : 'institute_profiles';

  const result = await query(
    `UPDATE ${table} SET
       is_verified = $1,
       verified_at = CASE WHEN $1 THEN COALESCE(verified_at, now()) ELSE NULL END
     WHERE user_id = $2
     RETURNING *`,
    [!!verified, id]
  );
  if (!result.rows[0]) return res.status(404).json({ error: 'Profile not found' });
  res.json({ profile: result.rows[0] });
});

// Admin classifies an institute — independent, franchise, MCP partner, or
// a directly-operated BSL centre. This gates eligibility for the BSL
// Verified badge below; it's a separate step from granting the badge
// itself so the classification has its own audit trail.
router.patch('/institute/:id/type', requireAuth, requireAdmin, async (req, res) => {
  const { institute_type } = req.body;
  if (!['independent', 'franchise', 'mcp_partner', 'bsl_direct'].includes(institute_type)) {
    return res.status(400).json({ error: 'Invalid institute_type' });
  }
  const result = await query(
    `UPDATE institute_profiles SET institute_type = $1 WHERE user_id = $2 RETURNING *`,
    [institute_type, req.params.id]
  );
  if (!result.rows[0]) return res.status(404).json({ error: 'Profile not found' });
  res.json({ profile: result.rows[0] });
});

// Admin grants or revokes the "BSL Verified" badge. Product rule: only
// institutes classified as institute_type = 'bsl_direct' are eligible —
// franchise and MCP partners are explicitly excluded, even if they
// separately hold the regular Verified badge. Classify first via the
// endpoint above, then grant here.
router.patch('/institute/:id/bsl-verified', requireAuth, requireAdmin, async (req, res) => {
  const { verified } = req.body;

  if (verified) {
    const current = await query(`SELECT institute_type FROM institute_profiles WHERE user_id = $1`, [req.params.id]);
    if (!current.rows[0]) return res.status(404).json({ error: 'Profile not found' });
    if (current.rows[0].institute_type !== 'bsl_direct') {
      return res.status(400).json({
        error: 'BSL Verified is reserved for directly-operated BSL centres. Franchise and MCP partners are not eligible.',
      });
    }
  }

  const result = await query(
    `UPDATE institute_profiles SET
       is_bsl_official = $1,
       bsl_official_since = CASE WHEN $1 THEN COALESCE(bsl_official_since, now()) ELSE NULL END
     WHERE user_id = $2
     RETURNING *`,
    [!!verified, req.params.id]
  );
  if (!result.rows[0]) return res.status(404).json({ error: 'Profile not found' });
  res.json({ profile: result.rows[0] });
});

// Powers the two stat cards on the Requests dashboard screen ("New
// requests" and "Profile views") — both are real counts, not
// placeholders. Profile views come from the logPageView('app_profile')
// call in the public profile GET route above; new-request count comes
// from bookings still awaiting a response. Windowed to the last 30 days
// by default so the number reflects recent activity, not
// all-time-since-launch — a teacher who joined a year ago and got 3
// views on day one shouldn't see "3 views" forever.
router.get('/me/stats', requireAuth, async (req, res) => {
  if (!['teacher', 'institute'].includes(req.user.account_type)) {
    return res.status(400).json({ error: 'Only teacher or institute accounts have stats' });
  }
  const daysNum = Number(req.query.days);
  const days = Number.isFinite(daysNum) && daysNum > 0 ? Math.min(Math.floor(daysNum), 365) : 30;

  const [newRequests, profileViews] = await Promise.all([
    query(
      `SELECT count(*) AS total FROM bookings
       WHERE provider_id = $1 AND status = 'requested'`,
      [req.user.id]
    ),
    query(
      `SELECT count(*) AS total FROM page_views
       WHERE page_type = 'app_profile' AND target = $1
         AND created_at > now() - interval '${days} days'`,
      [req.user.id]
    ),
  ]);

  res.json({
    sinceDays: days,
    newRequests: Number(newRequests.rows[0].total),
    profileViews: Number(profileViews.rows[0].total),
  });
});

module.exports = router;
