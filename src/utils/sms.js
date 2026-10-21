/**
 * Sends an OTP SMS, routed by country:
 *
 * - India (+91): MSG91 — roughly ₹0.18-0.25 (~$0.008) per SMS, far
 *   cheaper than any global provider, but MSG91's DLT-registered-template
 *   requirement is an India-specific SMS regulation and doesn't apply
 *   (or reliably deliver) elsewhere.
 * - Everywhere else: Twilio — broad, reliable international coverage.
 *   Costs more per message than MSG91, but only non-India traffic pays
 *   that premium; the bulk of expected volume (India) still gets the
 *   cheap path.
 *
 * `country` is the ISO country code from utils/phone.js's validation
 * (e.g. "IN", "US", "GB") — always pass the already-validated result
 * rather than re-deriving it here, so routing and validation can't
 * disagree with each other.
 */
async function sendOtpSms(e164Phone, code, country) {
  if (country === 'IN') {
    return sendViaMsg91(e164Phone, code);
  }
  return sendViaTwilio(e164Phone, code);
}

async function sendViaMsg91(phone, code) {
  if (!process.env.MSG91_AUTH_KEY) {
    console.warn('MSG91_AUTH_KEY not configured — logging OTP instead of sending:', phone, code);
    return; // dev-mode fallback, same pattern as email.js
  }

  const url = 'https://control.msg91.com/api/v5/flow/';
  const body = {
    // MSG91's template-based flow API — the template must be pre-approved
    // (DLT registration) with a {{code}} variable before this works.
    template_id: process.env.MSG91_TEMPLATE_ID,
    short_url: '0',
    recipients: [
      {
        mobiles: phone.replace(/\D/g, ''), // MSG91 expects digits only, country code prefixed (e.g. 91XXXXXXXXXX)
        code,
      },
    ],
  };

  try {
    const res = await fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', authkey: process.env.MSG91_AUTH_KEY },
      body: JSON.stringify(body),
    });
    if (!res.ok) console.error('MSG91 send failed:', res.status, await res.text());
  } catch (err) {
    // Never throw out of here — a failed SMS send shouldn't crash the
    // request. The caller already tells the user the OTP was sent; if it
    // silently failed, "resend" is the recovery path.
    console.error('MSG91 request error:', err.message);
  }
}

async function sendViaTwilio(phone, code) {
  if (!process.env.TWILIO_ACCOUNT_SID || !process.env.TWILIO_AUTH_TOKEN) {
    console.warn('Twilio not configured — logging OTP instead of sending:', phone, code);
    return; // dev-mode fallback
  }

  const message = `Your Nearby Teacher verification code is ${code}. Valid for 10 minutes. Do not share this code with anyone.`;
  const url = `https://api.twilio.com/2010-04-01/Accounts/${process.env.TWILIO_ACCOUNT_SID}/Messages.json`;
  const params = new URLSearchParams({
    To: phone,
    From: process.env.TWILIO_FROM_NUMBER,
    Body: message,
  });

  try {
    const res = await fetch(url, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded',
        Authorization: 'Basic ' + Buffer.from(`${process.env.TWILIO_ACCOUNT_SID}:${process.env.TWILIO_AUTH_TOKEN}`).toString('base64'),
      },
      body: params,
    });
    if (!res.ok) console.error('Twilio send failed:', res.status, await res.text());
  } catch (err) {
    console.error('Twilio request error:', err.message);
  }
}

module.exports = { sendOtpSms };
