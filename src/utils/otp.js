const crypto = require('crypto');
const bcrypt = require('bcryptjs');
const { query } = require('../db/pool');

const OTP_LENGTH = 6;
const OTP_TTL_MINUTES = 10;
const MAX_REQUESTS_PER_WINDOW = 3; // rate limit: max 3 OTP sends per phone per window
const RATE_LIMIT_WINDOW_MINUTES = 10;
const MAX_VERIFY_ATTEMPTS = 5; // per otp_codes row, tracked via a companion counter below

/**
 * Generates a random numeric OTP — crypto.randomInt, not Math.random, since
 * this is a security-sensitive code.
 */
function generateOtp() {
  const min = 10 ** (OTP_LENGTH - 1);
  const max = 10 ** OTP_LENGTH - 1;
  return String(crypto.randomInt(min, max + 1));
}

/**
 * Rate limit check: how many OTPs has this phone been sent in the last
 * window? Called before generating a new one. Returns true if the phone
 * is allowed to request another OTP right now.
 */
async function canRequestOtp(phone) {
  const result = await query(
    `SELECT count(*) AS recent_count FROM otp_codes
     WHERE phone = $1 AND created_at > now() - interval '${RATE_LIMIT_WINDOW_MINUTES} minutes'`,
    [phone]
  );
  return Number(result.rows[0].recent_count) < MAX_REQUESTS_PER_WINDOW;
}

/**
 * Creates and stores a new OTP for a phone number (hashed — the plaintext
 * code is returned once, for sending, and never stored). Any previous
 * unconsumed codes for this phone are left in place; only the most recent
 * one is checked on verify, so an old code simply stops being useful once
 * superseded rather than needing explicit invalidation.
 */
async function createOtp(phone) {
  const code = generateOtp();
  const codeHash = await bcrypt.hash(code, 10);
  const expiresAt = new Date(Date.now() + OTP_TTL_MINUTES * 60 * 1000);

  await query(
    `INSERT INTO otp_codes (phone, code_hash, expires_at) VALUES ($1, $2, $3)`,
    [phone, codeHash, expiresAt]
  );

  return code;
}

/**
 * Verifies a submitted code against the most recent unconsumed,
 * unexpired OTP for that phone. Marks it consumed on success so it can't
 * be reused. Returns { ok: true } or { ok: false, reason }.
 */
async function verifyOtp(phone, submittedCode) {
  const result = await query(
    `SELECT id, code_hash, expires_at, consumed_at, attempts FROM otp_codes
     WHERE phone = $1
     ORDER BY created_at DESC
     LIMIT 1`,
    [phone]
  );

  const row = result.rows[0];
  if (!row) return { ok: false, reason: 'No OTP was requested for this number' };
  if (row.consumed_at) return { ok: false, reason: 'This code has already been used' };
  if (new Date(row.expires_at) < new Date()) return { ok: false, reason: 'This code has expired' };
  if (row.attempts >= MAX_VERIFY_ATTEMPTS) {
    return { ok: false, reason: 'Too many incorrect attempts — request a new code' };
  }

  const matches = await bcrypt.compare(submittedCode, row.code_hash);
  if (!matches) {
    await query(`UPDATE otp_codes SET attempts = attempts + 1 WHERE id = $1`, [row.id]);
    return { ok: false, reason: 'Incorrect code' };
  }

  await query(`UPDATE otp_codes SET consumed_at = now() WHERE id = $1`, [row.id]);
  return { ok: true };
}

module.exports = { generateOtp, canRequestOtp, createOtp, verifyOtp, OTP_TTL_MINUTES };
