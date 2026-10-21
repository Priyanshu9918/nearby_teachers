const express = require('express');
const { requireAuth } = require('../middleware/auth');
const { requireAdmin } = require('../middleware/admin');
const { query } = require('../db/pool');

const router = express.Router();

// The review-queue side of photo moderation — the enforcement side
// (every new upload starts 'pending', gated out of every public
// response via a CASE expression at the SQL level) lives in
// auth.routes.js (PATCH /auth/avatar) and across every route that
// selects avatar_url. This is deliberately the same pattern as
// /safety/reports and /suggestions: an admin-reviewed queue, not an
// automated moderation API — no external image-moderation service is
// wired up here, since doing that properly needs a real account/API
// key decision, not something to bolt on as a side effect of this
// pass. Swap in an automated check later without touching the
// enforcement side — it doesn't care how a photo got approved/rejected,
// only that avatar_status says so.

router.get('/photos/pending', requireAuth, requireAdmin, async (req, res) => {
  const result = await query(
    `SELECT id, full_name, avatar_url, account_type, updated_at
     FROM users
     WHERE avatar_status = 'pending'
     ORDER BY updated_at ASC`
  );
  res.json({ pending: result.rows });
});

router.patch('/photos/:userId', requireAuth, requireAdmin, async (req, res) => {
  const { status } = req.body;
  if (!['approved', 'rejected'].includes(status)) {
    return res.status(400).json({ error: "status must be 'approved' or 'rejected'" });
  }

  const result = await query(
    `UPDATE users SET avatar_status = $1, updated_at = now() WHERE id = $2 AND avatar_status = 'pending' RETURNING id, full_name, avatar_status`,
    [status, req.params.userId]
  );
  if (!result.rows[0]) {
    return res.status(404).json({ error: 'No pending photo found for that user (already reviewed, or no photo on file)' });
  }
  res.json({ user: result.rows[0] });
});

// FULL ADMIN VISIBILITY INTO MESSAGES — a deliberate product decision,
// not the default. The alternative considered (and recommended) was
// report-triggered access only, viewing a conversation just after a
// report was filed about it. This is the broader option: any admin can
// view any conversation, any time. Given that breadth, every view is
// logged (admin_message_access_log) — an accountability measure for a
// capability that's a real privacy trade-off, disclosed prominently in
// the Privacy Policy, not just built quietly.

router.get('/conversations', requireAuth, requireAdmin, async (req, res) => {
  const result = await query(
    `SELECT c.id, c.created_at,
            learner.full_name AS learner_name, learner.id AS learner_id,
            provider.full_name AS provider_name, provider.id AS provider_id,
            (SELECT count(*) FROM messages m WHERE m.conversation_id = c.id) AS message_count,
            (SELECT max(created_at) FROM messages m WHERE m.conversation_id = c.id) AS last_message_at
     FROM conversations c
     JOIN users learner ON learner.id = c.learner_id
     JOIN users provider ON provider.id = c.provider_id
     ORDER BY last_message_at DESC NULLS LAST`
  );
  res.json({ conversations: result.rows });
});

router.get('/conversations/:id/messages', requireAuth, requireAdmin, async (req, res) => {
  const { id } = req.params;

  const convResult = await query(
    `SELECT c.id, c.learner_id, c.provider_id,
            learner.full_name AS learner_name, provider.full_name AS provider_name
     FROM conversations c
     JOIN users learner ON learner.id = c.learner_id
     JOIN users provider ON provider.id = c.provider_id
     WHERE c.id = $1`,
    [id]
  );
  if (!convResult.rows[0]) return res.status(404).json({ error: 'Conversation not found' });

  // Log this access before returning anything — the audit trail exists
  // specifically so this never happens silently.
  await query(
    `INSERT INTO admin_message_access_log (admin_id, conversation_id) VALUES ($1, $2)`,
    [req.user.id, id]
  );

  const messagesResult = await query(
    `SELECT m.id, m.sender_id, u.full_name AS sender_name, m.body, m.created_at, m.read_at
     FROM messages m JOIN users u ON u.id = m.sender_id
     WHERE m.conversation_id = $1
     ORDER BY m.created_at ASC`,
    [id]
  );

  res.json({ conversation: convResult.rows[0], messages: messagesResult.rows });
});

// FULL ADMIN VISIBILITY INTO BOOKING REQUESTS — same disclosed,
// deliberate decision as message visibility above, extended to cover
// "applying for a trainer" (a booking request), not just messaging.
// Logged per list-view (admin_booking_access_log), not per-booking —
// see the schema comment for why the granularity differs from the
// message log.
router.get('/bookings', requireAuth, requireAdmin, async (req, res) => {
  await query(
    `INSERT INTO admin_booking_access_log (admin_id) VALUES ($1)`,
    [req.user.id]
  );

  const result = await query(
    `SELECT b.id, b.status, b.mode, b.requested_time, b.price, b.created_at,
            learner.id AS learner_id, learner.full_name AS learner_name,
            provider.id AS provider_id, provider.full_name AS provider_name,
            s.name AS subject_name
     FROM bookings b
     JOIN users learner ON learner.id = b.learner_id
     JOIN users provider ON provider.id = b.provider_id
     LEFT JOIN subjects s ON s.id = b.subject_id
     ORDER BY b.created_at DESC`
  );
  res.json({ bookings: result.rows });
});

// Most-to-least contacted ranking. Aggregate/statistical, not content —
// same sensitivity level as the existing Analytics tab (page-view
// counts), not the same as reading a conversation's actual messages,
// so this doesn't get the per-view audit log that the message/booking
// content endpoints above do. Counts DISTINCT conversations, not raw
// message volume — "most contacted" means how many different people
// reached out, not how chatty any single thread got.
router.get('/contact-stats', requireAuth, requireAdmin, async (req, res) => {
  const [teacherStats, studentStats] = await Promise.all([
    query(
      `SELECT provider.id, provider.full_name, provider.account_type, count(c.id) AS contact_count
       FROM conversations c
       JOIN users provider ON provider.id = c.provider_id
       GROUP BY provider.id, provider.full_name, provider.account_type
       ORDER BY contact_count DESC, provider.full_name ASC`
    ),
    query(
      `SELECT learner.id, learner.full_name, count(c.id) AS contact_count
       FROM conversations c
       JOIN users learner ON learner.id = c.learner_id
       GROUP BY learner.id, learner.full_name
       ORDER BY contact_count DESC, learner.full_name ASC`
    ),
  ]);

  res.json({
    mostContactedTeachers: teacherStats.rows.map((r) => ({ ...r, contact_count: Number(r.contact_count) })),
    mostActiveStudents: studentStats.rows.map((r) => ({ ...r, contact_count: Number(r.contact_count) })),
  });
});

// ACCOUNT SUSPENSION — the other half of the moderation tooling above.
// Full visibility into messages/bookings is only useful if admin can
// actually act on what they find. Enforced in requireAuth itself
// (middleware/auth.js), so a suspended user is blocked from every
// authenticated action platform-wide, not just messaging/booking.

router.post('/users/:userId/suspend', requireAuth, requireAdmin, async (req, res) => {
  const { userId } = req.params;
  const { reason } = req.body;

  // An admin can't suspend themselves — a real footgun otherwise
  // (locking out the only admin able to undo it), cheap to prevent.
  if (userId === req.user.id) {
    return res.status(400).json({ error: 'You cannot suspend your own account.' });
  }

  const result = await query(
    `UPDATE users SET suspended_at = now(), suspended_reason = $1, suspended_by = $2, updated_at = now()
     WHERE id = $3 RETURNING id, full_name, suspended_at, suspended_reason`,
    [reason || null, req.user.id, userId]
  );
  if (!result.rows[0]) return res.status(404).json({ error: 'User not found' });
  res.json({ user: result.rows[0] });
});

router.post('/users/:userId/unsuspend', requireAuth, requireAdmin, async (req, res) => {
  const result = await query(
    `UPDATE users SET suspended_at = NULL, suspended_reason = NULL, suspended_by = NULL, updated_at = now()
     WHERE id = $1 RETURNING id, full_name`,
    [req.params.userId]
  );
  if (!result.rows[0]) return res.status(404).json({ error: 'User not found' });
  res.json({ user: result.rows[0] });
});

router.get('/users/suspended', requireAuth, requireAdmin, async (req, res) => {
  const result = await query(
    `SELECT u.id, u.full_name, u.account_type, u.suspended_at, u.suspended_reason,
            admin.full_name AS suspended_by_name
     FROM users u
     LEFT JOIN users admin ON admin.id = u.suspended_by
     WHERE u.suspended_at IS NOT NULL
     ORDER BY u.suspended_at DESC`
  );
  res.json({ suspended: result.rows });
});

module.exports = router;
