const express = require('express');
const { requireAuth } = require('../middleware/auth');
const { requireAdmin } = require('../middleware/admin');
const { query } = require('../db/pool');
const { generateReferralCode } = require('../utils/referral');

const router = express.Router();

// Reuses the existing /download deep-link route (see website.routes.js)
// rather than inventing a second link format — a referral link is just
// a download link with the referrer's code attached, so whoever opens
// it on a phone that doesn't have the app yet lands on the right store,
// and whoever already has it opens straight into the app.
function referralLink(code) {
  const base = process.env.APP_BASE_URL || 'https://bsl.international';
  return `${base}/download?ref=${code}`;
}

// A user's own referral code and link, plus how many people they've
// brought in. Generates the code lazily on first call (rather than at
// signup) so accounts created before this feature shipped still get one
// the first time this screen is opened.
router.get('/mine', requireAuth, async (req, res) => {
  let code = req.user.referral_code;

  if (!code) {
    // Retry on the rare collision, same pattern as slug generation.
    for (let attempts = 0; attempts < 5; attempts++) {
      const candidate = generateReferralCode();
      try {
        const result = await query(
          `UPDATE users SET referral_code = $1 WHERE id = $2 RETURNING referral_code`,
          [candidate, req.user.id]
        );
        code = result.rows[0].referral_code;
        break;
      } catch (err) {
        if (err.code !== '23505') throw err; // not a unique_violation — a real error
      }
    }
    if (!code) return res.status(500).json({ error: 'Could not generate a referral code, try again' });
  }

  const referred = await query(
    `SELECT u.id, u.full_name, r.created_at
     FROM referrals r JOIN users u ON u.id = r.referred_id
     WHERE r.referrer_id = $1
     ORDER BY r.created_at DESC`,
    [req.user.id]
  );

  res.json({
    code,
    link: referralLink(code),
    referredCount: referred.rows.length,
    referred: referred.rows,
  });
});

// Called once, right after a new account is created (signup, not every
// login) if the app was opened via a referral link. Credits the
// referrer. A person can only ever be credited to one referrer —
// referred_id is UNIQUE, so a second attempt for the same user is
// rejected, not silently overwritten.
router.post('/redeem', requireAuth, async (req, res) => {
  const { code } = req.body;
  if (!code) return res.status(400).json({ error: 'code is required' });

  const referrer = await query(`SELECT id FROM users WHERE referral_code = $1`, [code]);
  if (!referrer.rows[0]) return res.status(404).json({ error: 'Referral code not found' });
  if (referrer.rows[0].id === req.user.id) {
    return res.status(400).json({ error: "You can't refer yourself" });
  }

  const alreadyReferred = await query(`SELECT id FROM referrals WHERE referred_id = $1`, [req.user.id]);
  if (alreadyReferred.rows[0]) {
    return res.status(409).json({ error: 'This account has already been credited to a referrer' });
  }

  const result = await query(
    `INSERT INTO referrals (referrer_id, referred_id) VALUES ($1, $2) RETURNING *`,
    [referrer.rows[0].id, req.user.id]
  );
  res.json({ referral: result.rows[0] });
});

// Admin-only: top referrers, for tracking whether the growth mechanic is
// actually working and who's driving it.
router.get('/top', requireAuth, requireAdmin, async (req, res) => {
  const result = await query(
    `SELECT u.id, u.full_name, count(r.id) AS referred_count
     FROM users u JOIN referrals r ON r.referrer_id = u.id
     GROUP BY u.id, u.full_name
     ORDER BY referred_count DESC
     LIMIT 50`
  );
  res.json({ topReferrers: result.rows.map((r) => ({ ...r, referred_count: Number(r.referred_count) })) });
});

module.exports = router;
