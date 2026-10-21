const express = require('express');
const { requireAuth } = require('../middleware/auth');
const { requireAdmin } = require('../middleware/admin');
const { query } = require('../db/pool');
const { sendEmail } = require('../utils/email');

const router = express.Router();

// The "Contact BSL" form in Settings. Distinct from /safety/reports —
// this is a direct line to BSL as the platform operator (billing,
// legal, account issues, general confusion), not a flag on another
// user. Notifies BSL's support inbox by email so a ticket doesn't just
// sit unseen in the database until someone checks the admin dashboard.
router.post('/', requireAuth, async (req, res) => {
  const { subject, message } = req.body;
  if (!subject || !message) {
    return res.status(400).json({ error: 'subject and message are required' });
  }

  const result = await query(
    `INSERT INTO support_tickets (user_id, subject, message) VALUES ($1, $2, $3) RETURNING *`,
    [req.user.id, subject, message]
  );

  sendEmail({
    to: process.env.SUPPORT_EMAIL,
    subject: `New support ticket: ${subject}`,
    text: `From: ${req.user.full_name} (${req.user.email || req.user.phone})\n\n${message}`,
  });

  res.json({ ticket: result.rows[0] });
});

// A user's own ticket history — so "Contact BSL" can show past
// conversations, not just a blank form every time.
router.get('/mine', requireAuth, async (req, res) => {
  const result = await query(
    `SELECT * FROM support_tickets WHERE user_id = $1 ORDER BY created_at DESC`,
    [req.user.id]
  );
  res.json({ tickets: result.rows });
});

// Admin queue — mirrors the pattern used for reports/suggestions.
router.get('/', requireAuth, requireAdmin, async (req, res) => {
  const { status = 'open' } = req.query;
  const result = await query(
    `SELECT t.*, u.full_name AS user_name, u.email AS user_email, u.phone AS user_phone
     FROM support_tickets t
     JOIN users u ON u.id = t.user_id
     WHERE t.status = $1
     ORDER BY t.created_at DESC`,
    [status]
  );
  res.json({ tickets: result.rows });
});

router.patch('/:id/status', requireAuth, requireAdmin, async (req, res) => {
  const { status } = req.body;
  if (!['open', 'in_progress', 'resolved'].includes(status)) {
    return res.status(400).json({ error: 'Invalid status' });
  }
  const result = await query(
    `UPDATE support_tickets SET status = $1, updated_at = now() WHERE id = $2 RETURNING *`,
    [status, req.params.id]
  );
  if (!result.rows[0]) return res.status(404).json({ error: 'Ticket not found' });
  res.json({ ticket: result.rows[0] });
});

module.exports = router;
