const express = require('express');
const { requireAuth, requireParentalConsentIfMinor, requireSafetyDisclaimerAcknowledged } = require('../middleware/auth');
const { query } = require('../db/pool');
const { sendEmail } = require('../utils/email');

const router = express.Router();

// Learner requests a lesson — "Request booking" button on a profile.
// Emails the provider immediately: response speed is what makes or
// breaks a booking request, so this shouldn't wait on the provider
// happening to open the app.
router.post('/', requireAuth, requireSafetyDisclaimerAcknowledged, requireParentalConsentIfMinor, async (req, res) => {
  const { providerId, subjectId, mode, requestedTime, price } = req.body;
  const result = await query(
    `INSERT INTO bookings (learner_id, provider_id, subject_id, mode, requested_time, price)
     VALUES ($1, $2, $3, $4, $5, $6) RETURNING *`,
    [req.user.id, providerId, subjectId, mode, requestedTime, price]
  );
  const booking = result.rows[0];

  const provider = await query(`SELECT email, full_name FROM users WHERE id = $1`, [providerId]);
  if (provider.rows[0]) {
    sendEmail({
      to: provider.rows[0].email,
      subject: `New booking request from ${req.user.full_name}`,
      text: `${req.user.full_name} has requested a lesson with you on ${new Date(requestedTime).toLocaleString()}. Open the app to accept or decline.`,
    });
  }

  res.json({ booking });
});

// Requests dashboard for a teacher/institute — new/incoming requests.
router.get('/incoming', requireAuth, async (req, res) => {
  const result = await query(
    `SELECT b.*, u.full_name AS learner_name, s.name AS subject_name
     FROM bookings b
     JOIN users u ON u.id = b.learner_id
     LEFT JOIN subjects s ON s.id = b.subject_id
     WHERE b.provider_id = $1
     ORDER BY b.created_at DESC`,
    [req.user.id]
  );
  res.json({ bookings: result.rows });
});

// Learner's own booking history / upcoming lessons.
router.get('/mine', requireAuth, async (req, res) => {
  const result = await query(
    `SELECT b.*, u.full_name AS provider_name, s.name AS subject_name
     FROM bookings b
     JOIN users u ON u.id = b.provider_id
     LEFT JOIN subjects s ON s.id = b.subject_id
     WHERE b.learner_id = $1
     ORDER BY b.created_at DESC`,
    [req.user.id]
  );
  res.json({ bookings: result.rows });
});

// Accept / decline / cancel — one endpoint, status passed in.
// Emails the learner when a provider accepts or declines, since that's
// the moment they're waiting on.
router.patch('/:id/status', requireAuth, async (req, res) => {
  const { status } = req.body;
  const allowed = ['accepted', 'declined', 'cancelled', 'completed'];
  if (!allowed.includes(status)) return res.status(400).json({ error: 'Invalid status' });

  const result = await query(
    `UPDATE bookings SET status = $1, updated_at = now()
     WHERE id = $2 AND (provider_id = $3 OR learner_id = $3)
     RETURNING *`,
    [status, req.params.id, req.user.id]
  );
  const booking = result.rows[0];
  if (!booking) return res.status(404).json({ error: 'Booking not found' });

  if (status === 'accepted' || status === 'declined') {
    const learner = await query(`SELECT email FROM users WHERE id = $1`, [booking.learner_id]);
    const provider = await query(`SELECT full_name FROM users WHERE id = $1`, [booking.provider_id]);
    if (learner.rows[0] && provider.rows[0]) {
      sendEmail({
        to: learner.rows[0].email,
        subject: status === 'accepted'
          ? `${provider.rows[0].full_name} accepted your booking request`
          : `${provider.rows[0].full_name} declined your booking request`,
        text: status === 'accepted'
          ? `Your lesson request has been confirmed. Open the app for the details.`
          : `Your lesson request wasn't accepted this time. You can try another teacher or institute in the app.`,
      });
    }
  }

  res.json({ booking });
});

module.exports = router;
