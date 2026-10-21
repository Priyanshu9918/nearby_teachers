// Minimum age to hold an account at all. Below this, we don't attempt a
// parental-consent flow — the account is simply not created. This
// mirrors how most consumer platforms handle it (rather than building
// full COPPA-style verifiable-parental-consent infrastructure for
// under-13s, which is a much bigger undertaking than this MVP scope).
const MIN_AGE = 13;

// Below adult age but at/above MIN_AGE: account is created, but gated
// behind parental consent (see routes) until a guardian confirms.
const ADULT_AGE = 18;

function computeAge(dobString) {
  const dob = new Date(dobString);
  if (isNaN(dob.getTime())) return null;
  const today = new Date();
  let age = today.getFullYear() - dob.getFullYear();
  const monthDiff = today.getMonth() - dob.getMonth();
  if (monthDiff < 0 || (monthDiff === 0 && today.getDate() < dob.getDate())) {
    age--;
  }
  return age;
}

/**
 * Classifies a date of birth against the platform's age rules.
 * Returns one of:
 *   { allowed: false, reason: '...' }                — under MIN_AGE, account cannot be created
 *   { allowed: true, isMinor: true }                  — MIN_AGE..17, needs guardian info + consent
 *   { allowed: true, isMinor: false }                 — 18+, no restriction
 */
function classifyAge(dobString) {
  const age = computeAge(dobString);
  if (age === null) return { allowed: false, reason: 'Invalid date of birth' };
  if (age < 0 || age > 120) return { allowed: false, reason: 'Invalid date of birth' };
  if (age < MIN_AGE) {
    return { allowed: false, reason: `You must be at least ${MIN_AGE} to use Nearby Teacher` };
  }
  return { allowed: true, isMinor: age < ADULT_AGE };
}

module.exports = { MIN_AGE, ADULT_AGE, computeAge, classifyAge };
