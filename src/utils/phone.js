const { parsePhoneNumberFromString } = require('libphonenumber-js');

/**
 * Validates a phone number against real per-country formatting rules
 * (correct digit count, valid area/prefix patterns, etc.) rather than a
 * generic regex — a regex like /^\+?\d{8,15}$/ would accept a
 * too-short Indian number or a number with no country code at all as
 * "plausible", which a support ticket eventually turns into a bug report.
 *
 * Returns { valid: true, e164, country } or { valid: false }. `e164` is
 * the normalized form (e.g. "+919876543210") — always store and query
 * this, never whatever format the user typed, so the same number always
 * matches itself regardless of spacing/formatting differences.
 */
function validatePhone(input) {
  if (typeof input !== 'string') return { valid: false };
  const parsed = parsePhoneNumberFromString(input);
  if (!parsed || !parsed.isValid()) return { valid: false };
  return { valid: true, e164: parsed.format('E.164'), country: parsed.country };
}

module.exports = { validatePhone };
