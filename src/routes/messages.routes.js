const express = require('express');
const { requireAuth, requireParentalConsentIfMinor, requireSafetyDisclaimerAcknowledged } = require('../middleware/auth');
const { query } = require('../db/pool');

const router = express.Router();

// NOTE: this is a minimal reference implementation. For production, wire
// this up to a managed chat SDK (Stream Chat / Sendbird) for delivery,
// push notifications, and typing indicators — this table-based version
// is enough to prototype against and to model the data shape.

// List the caller's conversations, most recently active first.
router.get('/', requireAuth, async (req, res) => {
  const result = await query(
    `SELECT c.id, c.learner_id, c.provider_id,
            other.full_name AS other_name,
            CASE WHEN other.avatar_status = 'approved' THEN other.avatar_url ELSE NULL END AS other_avatar,
            m.body AS last_message, m.created_at AS last_message_at
     FROM conversations c
     JOIN users other ON other.id = CASE WHEN c.learner_id = $1 THEN c.provider_id ELSE c.learner_id END
     LEFT JOIN LATERAL (
       SELECT body, created_at FROM messages
       WHERE conversation_id = c.id ORDER BY created_at DESC LIMIT 1
     ) m ON true
     WHERE c.learner_id = $1 OR c.provider_id = $1
     ORDER BY m.created_at DESC NULLS LAST`,
    [req.user.id]
  );
  res.json({ conversations: result.rows });
});

// Start (or fetch) a conversation with a teacher/institute, then send the
// first message — this is what "Message" on a profile screen calls.
router.post('/start', requireAuth, requireSafetyDisclaimerAcknowledged, requireParentalConsentIfMinor, async (req, res) => {
  const { providerId, body } = req.body;

  const existing = await query(
    `SELECT id FROM conversations WHERE learner_id = $1 AND provider_id = $2`,
    [req.user.id, providerId]
  );
  let conversationId = existing.rows[0]?.id;

  if (!conversationId) {
    const created = await query(
      `INSERT INTO conversations (learner_id, provider_id) VALUES ($1, $2) RETURNING id`,
      [req.user.id, providerId]
    );
    conversationId = created.rows[0].id;
  }

  if (body) {
    await query(
      `INSERT INTO messages (conversation_id, sender_id, body) VALUES ($1, $2, $3)`,
      [conversationId, req.user.id, body]
    );
  }

  res.json({ conversationId });
});

router.get('/:conversationId', requireAuth, async (req, res) => {
  const result = await query(
    `SELECT * FROM messages WHERE conversation_id = $1 ORDER BY created_at ASC`,
    [req.params.conversationId]
  );
  res.json({ messages: result.rows });
});

router.post('/:conversationId', requireAuth, requireSafetyDisclaimerAcknowledged, requireParentalConsentIfMinor, async (req, res) => {
  const { body } = req.body;
  const result = await query(
    `INSERT INTO messages (conversation_id, sender_id, body) VALUES ($1, $2, $3) RETURNING *`,
    [req.params.conversationId, req.user.id, body]
  );
  res.json({ message: result.rows[0] });
});

module.exports = router;
