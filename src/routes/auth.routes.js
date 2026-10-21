const express = require('express');
const jwt = require('jsonwebtoken');
const crypto = require('crypto');
const { requireAuth } = require('../middleware/auth');
const admin = require('../utils/firebase');
const { query } = require('../db/pool');
const { canRequestOtp, createOtp, verifyOtp, OTP_TTL_MINUTES } = require('../utils/otp');
const { sendOtpSms } = require('../utils/sms');
const { validatePhone } = require('../utils/phone');
const { classifyAge } = require('../utils/age');
const { sendEmail } = require('../utils/email');
const { isSupported, SUPPORTED_LANGUAGES } = require('../i18n');

const router = express.Router();

// Step 1 of phone/OTP login: request a code. No auth required — this
// *is* the login flow. Rate-limited per phone (see utils/otp.js) so a
// script can't hammer this endpoint and run up SMS costs.
router.post('/otp/request', async (req, res) => {
  const validation = validatePhone(req.body.phone);
  if (!validation.valid) {
    return res.status(400).json({ error: 'Enter a valid phone number with country code, e.g. +919876543210' });
  }
  const { e164, country } = validation;

  const allowed = await canRequestOtp(e164);
  if (!allowed) {
    return res.status(429).json({ error: `Too many codes requested. Try again in a few minutes.` });
  }

  const code = await createOtp(e164);
  await sendOtpSms(e164, code, country); // routes to MSG91 (India) or Twilio (elsewhere); fire-and-forget internally, never throws

  res.json({ ok: true, expiresInMinutes: OTP_TTL_MINUTES });
});

// Step 2: verify the code. On success, finds or creates the user by
// phone and issues our own JWT — this path never touches Firebase.
// The token's payload carries iss: 'nearby-teacher-otp' so
// middleware/auth.js can tell it apart from a Firebase token.
router.post('/otp/verify', async (req, res) => {
  const { code } = req.body;
  const validation = validatePhone(req.body.phone);
  if (!validation.valid || !code) {
    return res.status(400).json({ error: 'A valid phone and code are required' });
  }
  const { e164 } = validation;

  const result = await verifyOtp(e164, code);
  if (!result.ok) {
    return res.status(400).json({ error: result.reason });
  }

  let userResult = await query(`SELECT * FROM users WHERE phone = $1`, [e164]);
  let user = userResult.rows[0];
  if (!user) {
    userResult = await query(
      `INSERT INTO users (account_type, full_name, phone) VALUES ('learner', $1, $2) RETURNING *`,
      ['New user', e164]
    );
    user = userResult.rows[0];
  }

  const token = jwt.sign(
    { sub: user.id, iss: 'nearby-teacher-otp' },
    process.env.JWT_SECRET
    // No expiresIn: the token never expires on its own — logout only
    // happens when the user explicitly logs out (client deletes the
    // stored token). See the README's "Authentication" section for the
    // real security tradeoff this creates: a leaked/stolen token stays
    // valid forever, and there's currently no server-side way to
    // revoke one early (no session table). Fine as a product decision,
    // but worth building revocation before this matters at real scale.
  );

  res.json({ token, user });
});

// Called right after the app verifies login (either OTP or Firebase/Google
// — see middleware/auth.js). Returns the user record (creating it if
// new, for the Firebase path) so the client knows whether onboarding
// ("I'm a teacher" / "I'm an institute" / learner) is still needed.
router.get('/session', requireAuth, async (req, res) => {
  res.json({ user: req.user });
});

// Sets the account type once, at onboarding ("I'm a teacher" / "I'm an
// institute" / learner). Also creates the matching empty profile row.
router.post('/account-type', requireAuth, async (req, res) => {
  const { accountType } = req.body;
  if (!['learner', 'teacher', 'institute'].includes(accountType)) {
    return res.status(400).json({ error: 'Invalid accountType' });
  }

  await query(`UPDATE users SET account_type = $1, updated_at = now() WHERE id = $2`, [
    accountType,
    req.user.id,
  ]);

  if (accountType === 'teacher') {
    await query(
      `INSERT INTO teacher_profiles (user_id) VALUES ($1) ON CONFLICT DO NOTHING`,
      [req.user.id]
    );
  } else if (accountType === 'institute') {
    await query(
      `INSERT INTO institute_profiles (user_id) VALUES ($1) ON CONFLICT DO NOTHING`,
      [req.user.id]
    );
  }

  res.json({ ok: true });
});

// Sets the caller's profile photo. Open to every account type — learner,
// teacher, and institute all get a photo, not just providers. Expects
// avatarUrl to already point at an uploaded file (S3/R2/etc.) — this
// endpoint just records the URL; actual file upload/storage is out of
// scope here and handled client-side against whichever media storage
// the app is wired to (see README: media storage).
// Persists a user's language choice against their account, so it
// follows them across devices rather than being stored only in one
// phone's local storage. Validated against the real supported-language
// list (src/i18n/) — rejecting an unsupported code here rather than
// silently storing it avoids a user's UI silently falling back to
// English later with no explanation of why.
router.patch('/preferred-language', requireAuth, async (req, res) => {
  const { language } = req.body;
  if (!isSupported(language)) {
    return res.status(400).json({
      error: `Unsupported language code. Supported: ${SUPPORTED_LANGUAGES.join(', ')}`,
    });
  }
  await query(
    `UPDATE users SET preferred_language = $1, updated_at = now() WHERE id = $2`,
    [language, req.user.id]
  );
  res.json({ ok: true, preferredLanguage: language });
});

router.patch('/avatar', requireAuth, async (req, res) => {
  const { avatarUrl } = req.body;
  if (!avatarUrl) return res.status(400).json({ error: 'avatarUrl is required' });

  // Every new avatar starts 'pending' — excluded from public profile
  // responses (see profiles.routes.js) until an admin approves it in
  // the moderation queue (src/routes/moderation.routes.js). This is
  // the enforcement half of photo moderation; the review-queue side is
  // in moderation.routes.js.
  const result = await query(
    `UPDATE users SET avatar_url = $1, avatar_status = 'pending', updated_at = now() WHERE id = $2 RETURNING id, avatar_url, avatar_status`,
    [avatarUrl, req.user.id]
  );
  res.json({
    user: result.rows[0],
    message: 'Photo submitted — it will appear on your profile once reviewed.',
  });
});

// ACCOUNT LINKING
//
// Without this, someone who signs up via Google and later logs in via
// phone/OTP (or vice versa) silently gets a second, disconnected
// account — same person, two separate booking histories. Both endpoints
// below require the caller to already be logged into the account
// they're adding an identifier *to*, and require fresh proof of
// ownership of the thing being added (a real OTP for a phone, a real
// Firebase token for a Google identity) — so linking is always
// mutual-consent between "I'm logged in as this account" and "I can
// prove I own this other identifier," never a silent merge.
//
// What this does NOT do: merge two *already-existing* separate
// accounts' data (bookings, reviews, profiles) into one. That's a
// genuinely harder problem — reassigning foreign keys across every
// table that references a user_id — and isn't built. These endpoints
// only *prevent new duplicates* by letting someone add a second login
// method to their existing account before a second account gets
// created. If two accounts already exist for one person, resolving that
// is a manual admin job for now.

// Adds a phone number to the caller's account, verified by OTP. Request
// the code first via the existing POST /auth/otp/request (unauthenticated
// is fine there — it only sends an SMS), then call this with the code.
router.patch('/link-phone', requireAuth, async (req, res) => {
  const { code } = req.body;
  const validation = validatePhone(req.body.phone);
  if (!validation.valid || !code) {
    return res.status(400).json({ error: 'A valid phone and code are required' });
  }
  const { e164 } = validation;

  const result = await verifyOtp(e164, code);
  if (!result.ok) {
    return res.status(400).json({ error: result.reason });
  }

  const existing = await query(`SELECT id FROM users WHERE phone = $1`, [e164]);
  if (existing.rows[0] && existing.rows[0].id !== req.user.id) {
    return res.status(409).json({
      error: 'This phone number is already linked to a different account. Log in with that account to manage it, or contact BSL for help combining accounts.',
    });
  }

  const updated = await query(
    `UPDATE users SET phone = $1, updated_at = now() WHERE id = $2 RETURNING *`,
    [e164, req.user.id]
  );
  res.json({ user: updated.rows[0] });
});

// Adds a Google identity to the caller's account, verified by a real
// Firebase ID token (the client runs the normal Firebase Google sign-in
// flow to get one — this isn't a separate OAuth implementation).
router.patch('/link-google', requireAuth, async (req, res) => {
  const { firebaseIdToken } = req.body;
  if (!firebaseIdToken) return res.status(400).json({ error: 'firebaseIdToken is required' });

  let decoded;
  try {
    decoded = await admin.auth().verifyIdToken(firebaseIdToken);
  } catch {
    return res.status(400).json({ error: 'That Google sign-in could not be verified. Try again.' });
  }
  if (decoded.firebase.sign_in_provider !== 'google.com') {
    return res.status(400).json({ error: 'That token was not from a Google sign-in.' });
  }

  const { uid, email } = decoded;
  const existing = await query(
    `SELECT id FROM users WHERE google_id = $1 OR (email IS NOT NULL AND email = $2)`,
    [uid, email || null]
  );
  if (existing.rows[0] && existing.rows[0].id !== req.user.id) {
    return res.status(409).json({
      error: 'This Google account is already linked to a different account. Log in with that account to manage it, or contact BSL for help combining accounts.',
    });
  }

  const updated = await query(
    `UPDATE users SET google_id = $1, email = COALESCE(email, $2), updated_at = now() WHERE id = $3 RETURNING *`,
    [uid, email || null, req.user.id]
  );
  res.json({ user: updated.rows[0] });
});

// AGE VERIFICATION & PARENTAL CONSENT
//
// Called once, as an onboarding step after first login (before the
// account can message a provider or create a booking — see the checks
// in messages.routes.js and bookings.routes.js). Existing accounts
// predate this and have date_of_birth = NULL; the app should prompt
// them to complete this the next time they try to message or book.
router.post('/date-of-birth', requireAuth, async (req, res) => {
  const { dateOfBirth, guardianName, guardianContact } = req.body;
  if (!dateOfBirth) return res.status(400).json({ error: 'dateOfBirth is required' });

  const classification = classifyAge(dateOfBirth);
  if (!classification.allowed) {
    return res.status(400).json({ error: classification.reason });
  }

  if (classification.isMinor) {
    if (!guardianName || !guardianContact) {
      return res.status(400).json({
        error: 'A parent or guardian name and contact (email or phone) are required for users under 18',
      });
    }
    const token = crypto.randomBytes(24).toString('hex');
    await query(
      `UPDATE users SET
         date_of_birth = $1, is_minor = TRUE, guardian_name = $2, guardian_contact = $3,
         parental_consent_token = $4, parental_consent_confirmed_at = NULL, updated_at = now()
       WHERE id = $5`,
      [dateOfBirth, guardianName, guardianContact, token, req.user.id]
    );

    const consentLink = `${process.env.APP_BASE_URL || 'https://bsl.international'}/parental-consent/${token}`;
    if (guardianContact.includes('@')) {
      sendEmail({
        to: guardianContact,
        subject: `${req.user.full_name} wants to use Nearby Teacher — your consent is needed`,
        text: `${req.user.full_name} has signed up for Nearby Teacher and listed you as their parent/guardian. `
          + `Nearby Teacher connects learners with nearby teachers and institutes; background checks are not `
          + `conducted on listings. If you consent to ${req.user.full_name} using the platform, confirm here: `
          + `${consentLink}\n\nIf you did not expect this, you can ignore this email — no account access is `
          + `granted without your confirmation.`,
      });
    }
    // TODO: if guardianContact is a phone number (no '@'), send via SMS
    // instead of email once an SMS-for-non-OTP-purposes path exists —
    // currently sendOtpSms is scoped specifically to login codes, not
    // general-purpose messages. Flagged rather than silently skipped.

    return res.json({
      isMinor: true,
      parentalConsentPending: true,
      message: 'A consent request has been sent to your parent/guardian. Messaging and booking are unavailable until they confirm.',
    });
  }

  await query(
    `UPDATE users SET date_of_birth = $1, is_minor = FALSE, updated_at = now() WHERE id = $2`,
    [dateOfBirth, req.user.id]
  );
  res.json({ isMinor: false });
});

// Public — no auth, since the guardian confirming consent may not have
// (or want) an account of their own. The token itself is the proof.
router.post('/parental-consent/confirm', async (req, res) => {
  const { token } = req.body;
  if (!token) return res.status(400).json({ error: 'token is required' });

  const result = await query(
    `UPDATE users SET parental_consent_confirmed_at = now(), parental_consent_token = NULL, updated_at = now()
     WHERE parental_consent_token = $1
     RETURNING id, full_name`,
    [token]
  );
  if (!result.rows[0]) {
    return res.status(404).json({ error: 'This consent link is invalid or has already been used.' });
  }
  res.json({ ok: true, confirmedFor: result.rows[0].full_name });
});

// SAFETY DISCLAIMER — an ENFORCED in-app acknowledgment, not just ToS
// text. The point isn't just to have this written down somewhere;
// it's that a user has genuinely seen it before they can act on it.
// This text is the single source of truth the client should display —
// don't hardcode a copy of it in the app, fetch it from here so the
// two can never drift out of sync.
const SAFETY_DISCLAIMER_TEXT =
  'Nearby Teacher does not conduct criminal background checks, identity verification, or credential ' +
  'checks on teachers or institutes — except the "BSL Verified" badge, which only confirms an institute ' +
  'is directly operated by BSL and is not a safety certification. You are solely responsible for ' +
  'evaluating the suitability and safety of any teacher or institute before engaging their services, ' +
  'especially for a minor in your care. We recommend independently verifying credentials, checking ' +
  'references, and meeting before committing to lessons — particularly for in-person or unsupervised ' +
  'sessions involving a minor.';

router.get('/safety-disclaimer', (req, res) => {
  res.json({ text: SAFETY_DISCLAIMER_TEXT });
});

router.post('/acknowledge-safety-disclaimer', requireAuth, async (req, res) => {
  await query(
    `UPDATE users SET safety_disclaimer_acknowledged_at = now(), updated_at = now() WHERE id = $1`,
    [req.user.id]
  );
  res.json({ ok: true });
});

module.exports = router;
