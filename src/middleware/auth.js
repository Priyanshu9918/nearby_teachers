const jwt = require('jsonwebtoken');
const admin = require('../utils/firebase');
const { query } = require('../db/pool');

/**
 * Two login paths issue two different kinds of token, and this middleware
 * accepts either:
 *
 * 1. Google sign-in → Firebase ID token, verified against Firebase (as
 *    before).
 * 2. Phone/OTP sign-in → our own JWT, issued by POST /auth/otp/verify
 *    after a successful OTP check (see auth.routes.js and utils/otp.js).
 *    This path never touches Firebase at all — the OTP itself is
 *    generated, hashed, and checked entirely by our own backend and
 *    sent via MSG91 (utils/sms.js), for the cost reasons noted there.
 *
 * We try our own JWT first (a fast, local check, no network call) and
 * fall back to Firebase verification if that fails — so this function
 * stays a single, unchanged entry point for every other route in the app
 * regardless of which login method the caller used.
 */
async function requireAuth(req, res, next) {
  try {
    const header = req.headers.authorization || '';
    const token = header.startsWith('Bearer ') ? header.slice(7) : null;
    if (!token) {
      return res.status(401).json({ error: 'Missing Authorization header' });
    }

    const user = await verifyOwnJwt(token) || await verifyFirebaseToken(token);
    if (!user) {
      return res.status(401).json({ error: 'Invalid or expired token' });
    }

    // Suspension check happens here, not in individual routes — a
    // suspended user is blocked from EVERY authenticated action, not
    // just messaging/booking. Deliberately broader than the
    // safety-disclaimer/parental-consent gates below, which only apply
    // to three specific endpoints. Applies regardless of which of the
    // two token types above verified successfully — suspension isn't
    // tied to a login method, it's tied to the account.
    if (user.suspended_at) {
      return res.status(403).json({
        error: 'This account has been suspended.',
        suspended: true,
        reason: user.suspended_reason || undefined,
      });
    }

    req.user = user;
    next();
  } catch (err) {
    console.error('Auth error:', err.message);
    res.status(401).json({ error: 'Invalid or expired token' });
  }
}

// Verifies a token issued by our own POST /auth/otp/verify. Returns the
// user row on success, or null (not a throw) so requireAuth can fall
// through to Firebase verification — a token that isn't ours isn't
// necessarily invalid, it might just be a Firebase one.
async function verifyOwnJwt(token) {
  try {
    const payload = jwt.verify(token, process.env.JWT_SECRET);
    if (payload.iss !== 'nearby-teacher-otp') return null; // not one of ours
    const result = await query(`SELECT * FROM users WHERE id = $1`, [payload.sub]);
    return result.rows[0] || null;
  } catch {
    return null; // wrong secret, expired, malformed — just not ours
  }
}

async function verifyFirebaseToken(token) {
  const decoded = await admin.auth().verifyIdToken(token);
  const { uid, phone_number: phone, email, name } = decoded;

  // uid from Firebase is stable per login method; we key our users
  // table on email/phone/google_id instead so the same person logging
  // in via Google or phone still resolves to one account where possible.
  let result = await query(
    `SELECT * FROM users WHERE google_id = $1 OR phone = $2 OR email = $3 LIMIT 1`,
    [uid, phone || null, email || null]
  );

  let user = result.rows[0];
  if (!user) {
    result = await query(
      `INSERT INTO users (account_type, full_name, email, phone, google_id)
       VALUES ('learner', $1, $2, $3, $4)
       RETURNING *`,
      [name || 'New user', email || null, phone || null, decoded.firebase.sign_in_provider === 'google.com' ? uid : null]
    );
    user = result.rows[0];
  }

  return user;
}

// Blocks a request if the caller is a minor (is_minor = TRUE) whose
// parent/guardian hasn't confirmed consent yet. Used on the specific
// actions that matter — starting a conversation, sending a message,
// creating a booking — not on browsing/search, since there's no safety
// reason to block a minor from looking at listings, only from actually
// initiating contact. req.user is already loaded by requireAuth (a
// SELECT * against users), so this reads the same row without a second
// query. An account with date_of_birth still NULL (existing accounts
// from before this feature, or a new account that hasn't completed the
// step yet) is treated as not-a-minor here — age is unknown, not
// assumed; the onboarding flow is what's responsible for prompting
// every account to complete POST /auth/date-of-birth in the first
// place, this middleware only enforces the consequence once age is on
// file and confirms someone under 18.
function requireParentalConsentIfMinor(req, res, next) {
  if (req.user.is_minor && !req.user.parental_consent_confirmed_at) {
    return res.status(403).json({
      error: 'A parent or guardian needs to confirm consent before this account can message or book a lesson. Check the email/contact provided at signup, or complete this step in Settings.',
    });
  }
  next();
}

// Same verification as requireAuth, but never blocks the request — used
// on endpoints that work for anyone (like a public profile page) but
// behave differently once someone's signed in (like including the
// phone number only for a logged-in student, not an anonymous visitor).
// req.user is set if a valid token was present, left undefined
// otherwise — the route itself decides what that difference means, this
// middleware only establishes the fact of whether someone's logged in.
async function optionalAuth(req, res, next) {
  try {
    const header = req.headers.authorization || '';
    const token = header.startsWith('Bearer ') ? header.slice(7) : null;
    if (!token) return next(); // no token — proceed as a logged-out request, not an error

    const user = await verifyOwnJwt(token) || await verifyFirebaseToken(token);
    // A suspended user's token is treated the same as an invalid one
    // here — proceed as logged-out rather than granting authenticated
    // privileges (like the phone-number reveal) through this path.
    // Suspension should block privilege everywhere, not just on routes
    // that use requireAuth outright.
    if (user && !user.suspended_at) req.user = user;
    next();
  } catch (err) {
    console.error('optionalAuth error (proceeding as logged-out):', err.message);
    next();
  }
}

// Enforces the background-check disclaimer as an actual gate, not just
// text in the ToS. Applies to every account, not just minors — the
// underlying risk (no background checks on listings) applies to any
// user booking or messaging a teacher. Same pattern as the parental
// consent check above: reads req.user (already loaded by requireAuth),
// no extra query.
function requireSafetyDisclaimerAcknowledged(req, res, next) {
  if (!req.user.safety_disclaimer_acknowledged_at) {
    return res.status(403).json({
      error: 'Please review and acknowledge the safety disclaimer before messaging or booking a lesson.',
      disclaimerEndpoint: '/auth/safety-disclaimer',
      acknowledgeEndpoint: '/auth/acknowledge-safety-disclaimer',
    });
  }
  next();
}

module.exports = { requireAuth, optionalAuth, requireParentalConsentIfMinor, requireSafetyDisclaimerAcknowledged };
