const crypto = require('crypto');

// Short, URL-friendly, unambiguous codes: uppercase letters and digits,
// excluding easily-confused characters (0/O, 1/I/L) since these end up
// typed or read aloud, not just tapped as a link.
const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
const CODE_LENGTH = 7;

function generateReferralCode() {
  let code = '';
  const bytes = crypto.randomBytes(CODE_LENGTH);
  for (let i = 0; i < CODE_LENGTH; i++) {
    code += ALPHABET[bytes[i] % ALPHABET.length];
  }
  return code;
}

module.exports = { generateReferralCode };
