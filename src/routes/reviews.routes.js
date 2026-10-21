const express = require('express');
const { requireAuth } = require('../middleware/auth');
const { query } = require('../db/pool');

const router = express.Router();

// Submit a review after a completed lesson ("Leave a review" screen).
router.post('/', requireAuth, async (req, res) => {
  const { bookingId, rating, comment } = req.body;

  const booking = await query(`SELECT * FROM bookings WHERE id = $1`, [bookingId]);
  const b = booking.rows[0];
  if (!b || b.learner_id !== req.user.id) {
    return res.status(403).json({ error: 'Not allowed to review this booking' });
  }

  const inserted = await query(
    `INSERT INTO reviews (booking_id, learner_id, provider_id, rating, comment)
     VALUES ($1, $2, $3, $4, $5) RETURNING *`,
    [bookingId, req.user.id, b.provider_id, rating, comment]
  );

  // Recalculate the provider's aggregate rating. Works whether the
  // provider is a teacher or an institute.
  await query(
    `UPDATE teacher_profiles SET
       rating_count = rating_count + 1,
       rating_avg = ((rating_avg * rating_count) + $1) / (rating_count + 1)
     WHERE user_id = $2`,
    [rating, b.provider_id]
  );
  await query(
    `UPDATE institute_profiles SET
       rating_count = rating_count + 1,
       rating_avg = ((rating_avg * rating_count) + $1) / (rating_count + 1)
     WHERE user_id = $2`,
    [rating, b.provider_id]
  );

  res.json({ review: inserted.rows[0] });
});

router.get('/for/:providerId', async (req, res) => {
  const result = await query(
    `SELECT r.*, u.full_name AS learner_name FROM reviews r
     JOIN users u ON u.id = r.learner_id
     WHERE r.provider_id = $1 ORDER BY r.created_at DESC`,
    [req.params.providerId]
  );
  res.json({ reviews: result.rows });
});

module.exports = router;
