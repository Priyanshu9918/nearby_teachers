const express = require('express');
const { requireAuth } = require('../middleware/auth');
const { requireAdmin } = require('../middleware/admin');
const { query } = require('../db/pool');

const router = express.Router();

// Submitted from any empty-state screen ("Suggest this to us"). Open to
// learners, teachers, and institutes alike — a teacher suggesting their
// own city is exactly the case that lets them then create a listing
// there once it's reviewed and added.
router.post('/', requireAuth, async (req, res) => {
  const { suggestionType, name, notes } = req.body;
  if (!['course', 'city', 'state', 'country'].includes(suggestionType)) {
    return res.status(400).json({ error: 'Invalid suggestionType' });
  }
  if (!name) return res.status(400).json({ error: 'name is required' });

  const result = await query(
    `INSERT INTO suggestions (submitted_by, suggestion_type, name, notes)
     VALUES ($1, $2, $3, $4) RETURNING *`,
    [req.user.id, suggestionType, name, notes || null]
  );
  res.json({ suggestion: result.rows[0] });
});

// For an internal review queue/admin tool. Lists open suggestions, most
// recent first.
router.get('/', requireAuth, requireAdmin, async (req, res) => {
  const { status = 'open' } = req.query;
  const result = await query(
    `SELECT s.*, u.full_name AS submitted_by_name FROM suggestions s
     JOIN users u ON u.id = s.submitted_by
     WHERE s.status = $1
     ORDER BY s.created_at DESC`,
    [status]
  );
  res.json({ suggestions: result.rows });
});

// Admin marks a suggestion reviewed.
router.patch('/:id/status', requireAuth, requireAdmin, async (req, res) => {
  const { status } = req.body;
  if (!['open', 'added', 'declined'].includes(status)) {
    return res.status(400).json({ error: 'Invalid status' });
  }
  const result = await query(
    `UPDATE suggestions SET status = $1 WHERE id = $2 RETURNING *`,
    [status, req.params.id]
  );
  if (!result.rows[0]) return res.status(404).json({ error: 'Suggestion not found' });
  res.json({ suggestion: result.rows[0] });
});

module.exports = router;
