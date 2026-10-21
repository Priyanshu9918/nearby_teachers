const express = require('express');
const { requireAuth } = require('../middleware/auth');
const { requireAdmin } = require('../middleware/admin');
const { query } = require('../db/pool');

const router = express.Router();

// Report a profile, post, comment, or message — surfaced from the "..."
// overflow menu on profiles/posts/threads and from the settings screen.
router.post('/reports', requireAuth, async (req, res) => {
  const { targetType, targetId, reasonCategory, reason } = req.body;
  if (!['fraud', 'fake_profile', 'inappropriate', 'spam', 'other'].includes(reasonCategory)) {
    return res.status(400).json({ error: 'Invalid reasonCategory' });
  }
  const result = await query(
    `INSERT INTO reports (reporter_id, target_type, target_id, reason_category, reason)
     VALUES ($1, $2, $3, $4, $5) RETURNING *`,
    [req.user.id, targetType, targetId, reasonCategory, reason || null]
  );
  res.json({ report: result.rows[0] });
});

// Admin review queue — not exposed in the consumer app. Fraud reports are
// surfaced first since they carry the most direct risk to other users.
router.get('/reports', requireAuth, requireAdmin, async (req, res) => {
  const { status = 'open' } = req.query;
  const result = await query(
    `SELECT r.*, u.full_name AS reporter_name FROM reports r
     JOIN users u ON u.id = r.reporter_id
     WHERE r.status = $1
     ORDER BY CASE WHEN r.reason_category = 'fraud' THEN 0 ELSE 1 END, r.created_at DESC`,
    [status]
  );
  res.json({ reports: result.rows });
});

// Admin marks a report reviewed or dismissed.
router.patch('/reports/:id/status', requireAuth, requireAdmin, async (req, res) => {
  const { status } = req.body;
  if (!['open', 'reviewed', 'dismissed'].includes(status)) {
    return res.status(400).json({ error: 'Invalid status' });
  }
  const result = await query(`UPDATE reports SET status = $1 WHERE id = $2 RETURNING *`, [
    status,
    req.params.id,
  ]);
  if (!result.rows[0]) return res.status(404).json({ error: 'Report not found' });
  res.json({ report: result.rows[0] });
});

router.post('/blocks/:userId', requireAuth, async (req, res) => {
  await query(
    `INSERT INTO blocks (blocker_id, blocked_id) VALUES ($1, $2) ON CONFLICT DO NOTHING`,
    [req.user.id, req.params.userId]
  );
  res.json({ ok: true });
});

router.delete('/blocks/:userId', requireAuth, async (req, res) => {
  await query(`DELETE FROM blocks WHERE blocker_id = $1 AND blocked_id = $2`, [
    req.user.id,
    req.params.userId,
  ]);
  res.json({ ok: true });
});

module.exports = router;
