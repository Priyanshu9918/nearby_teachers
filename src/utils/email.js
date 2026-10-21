const nodemailer = require('nodemailer');

// A single shared SMTP transport. Works with any standard SMTP provider
// (SendGrid, Postmark, AWS SES's SMTP interface, etc.) — just point the
// env vars at whichever one you pick; nothing here is provider-specific.
let transporter = null;
function getTransporter() {
  if (!transporter) {
    transporter = nodemailer.createTransport({
      host: process.env.SMTP_HOST,
      port: Number(process.env.SMTP_PORT || 587),
      secure: Number(process.env.SMTP_PORT) === 465,
      auth: process.env.SMTP_USER
        ? { user: process.env.SMTP_USER, pass: process.env.SMTP_PASS }
        : undefined,
    });
  }
  return transporter;
}

/**
 * Sends an email and never throws — a failed notification email should
 * never fail the API request that triggered it (a booking still gets
 * created even if the email bounces). Errors are logged, not raised.
 */
async function sendEmail({ to, subject, text, html }) {
  if (!to) return;
  if (!process.env.SMTP_HOST) {
    console.warn('SMTP_HOST not configured — skipping email:', subject);
    return;
  }
  try {
    await getTransporter().sendMail({
      from: process.env.EMAIL_FROM || 'Nearby Teacher <no-reply@nearbyteacher.app>',
      to,
      subject,
      text,
      html: html || `<p>${text}</p>`,
    });
  } catch (err) {
    console.error('Email send failed:', subject, err.message);
  }
}

module.exports = { sendEmail };
