const express = require('express');
const { requireAuth } = require('../middleware/auth');
const { query } = require('../db/pool');
const { sendEmail } = require('../utils/email');

const router = express.Router();

// GET /feed?city=Kanpur — city-scoped feed, newest first, with like/comment
// counts and whether the caller has already liked each post.
router.get('/', requireAuth, async (req, res) => {
  const { city } = req.query;
  if (!city) return res.status(400).json({ error: 'city is required' });

  const result = await query(
    `SELECT p.*, u.full_name AS author_name,
            CASE WHEN u.avatar_status = 'approved' THEN u.avatar_url ELSE NULL END AS author_avatar,
            (SELECT count(*) FROM post_likes WHERE post_id = p.id) AS like_count,
            (SELECT count(*) FROM post_comments WHERE post_id = p.id) AS comment_count,
            EXISTS (SELECT 1 FROM post_likes WHERE post_id = p.id AND user_id = $2) AS liked_by_me
     FROM posts p
     JOIN users u ON u.id = p.author_id
     WHERE p.city = $1
     ORDER BY p.created_at DESC
     LIMIT 50`,
    [city, req.user.id]
  );
  res.json({ posts: result.rows });
});

// Any account type can post — a learner posting is a "request" ("looking
// for a French tutor near me"), a teacher/institute posting is an "offer"
// (a new batch, a free session). Product rule: at most one post per
// author per rolling 7 days, regardless of account type, to keep the
// feed from turning into a spam channel.
router.post('/', requireAuth, async (req, res) => {
  const recent = await query(
    `SELECT created_at FROM posts
     WHERE author_id = $1 AND created_at > now() - interval '7 days'
     ORDER BY created_at DESC LIMIT 1`,
    [req.user.id]
  );
  if (recent.rows[0]) {
    const nextAllowed = new Date(recent.rows[0].created_at);
    nextAllowed.setDate(nextAllowed.getDate() + 7);
    return res.status(429).json({
      error: 'You can post once every 7 days',
      nextAllowedAt: nextAllowed.toISOString(),
    });
  }

  const postType = req.user.account_type === 'learner' ? 'request' : 'offer';
  const { city, subjectId, body, imageUrl } = req.body;
  const result = await query(
    `INSERT INTO posts (author_id, post_type, city, subject_id, body, image_url)
     VALUES ($1, $2, $3, $4, $5, $6) RETURNING *`,
    [req.user.id, postType, city, subjectId, body, imageUrl]
  );
  res.json({ post: result.rows[0] });
});

router.post('/:postId/like', requireAuth, async (req, res) => {
  await query(
    `INSERT INTO post_likes (post_id, user_id) VALUES ($1, $2) ON CONFLICT DO NOTHING`,
    [req.params.postId, req.user.id]
  );
  res.json({ ok: true });
});

router.delete('/:postId/like', requireAuth, async (req, res) => {
  await query(`DELETE FROM post_likes WHERE post_id = $1 AND user_id = $2`, [
    req.params.postId,
    req.user.id,
  ]);
  res.json({ ok: true });
});

// Returns comments flat with parent_comment_id set on replies; the client
// nests replies under their parent. Any authenticated user — learner,
// teacher, or institute — can comment or reply on any post.
router.get('/:postId/comments', async (req, res) => {
  const result = await query(
    `SELECT c.*, u.full_name AS author_name FROM post_comments c
     JOIN users u ON u.id = c.user_id
     WHERE c.post_id = $1 ORDER BY c.created_at ASC`,
    [req.params.postId]
  );
  res.json({ comments: result.rows });
});

// parentCommentId is optional: omit for a top-level comment, or pass an
// existing comment's id to reply to it — any user can reply to any
// comment (a teacher answering a learner's question, another learner
// chiming in, etc.). Emails the original commenter when their comment
// gets a reply.
router.post('/:postId/comments', requireAuth, async (req, res) => {
  const { body, parentCommentId } = req.body;
  const result = await query(
    `INSERT INTO post_comments (post_id, user_id, parent_comment_id, body)
     VALUES ($1, $2, $3, $4) RETURNING *`,
    [req.params.postId, req.user.id, parentCommentId || null, body]
  );
  const comment = result.rows[0];

  if (parentCommentId) {
    const parent = await query(
      `SELECT c.user_id, u.email FROM post_comments c JOIN users u ON u.id = c.user_id WHERE c.id = $1`,
      [parentCommentId]
    );
    if (parent.rows[0] && parent.rows[0].user_id !== req.user.id) {
      sendEmail({
        to: parent.rows[0].email,
        subject: `${req.user.full_name} replied to your comment`,
        text: `"${body}" — open the app to see the full thread.`,
      });
    }
  }

  res.json({ comment });
});

module.exports = router;
