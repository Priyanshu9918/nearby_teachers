const express = require('express');
const { query } = require('../db/pool');
const { logPageView } = require('../utils/analytics');
const { formatPrice, formatDate, formatWeekdayAndTime } = require('../i18n/format');

const router = express.Router();

// Top-level directory: every city with at least one published teacher or
// institute, linking into that city's index page. Without this, /site/city/:city
// pages are only reachable if you already know the city name — this is
// the entry point a search engine (or a curious visitor) actually starts from.
router.get('/', async (req, res) => {
  logPageView({ pageType: 'city_directory', req });

  const result = await query(
    `SELECT city, sum(cnt) AS listing_count FROM (
       SELECT city, count(*) AS cnt FROM teacher_profiles WHERE city IS NOT NULL AND slug IS NOT NULL GROUP BY city
       UNION ALL
       SELECT city, count(*) AS cnt FROM institute_profiles WHERE city IS NOT NULL AND slug IS NOT NULL GROUP BY city
     ) combined
     GROUP BY city
     ORDER BY listing_count DESC, city ASC`
  );
  res.render('city-directory', { cities: result.rows, t: req.t, lang: req.lang });
});

// Public institute landing page — created automatically the moment an
// institute's profile gets a slug (see profiles.routes.js). No login
// required: this is the page a search engine indexes and a prospective
// learner lands on before ever installing the app.
router.get('/institute/:slug', async (req, res) => {
  logPageView({ pageType: 'institute', target: req.params.slug, req });

  const result = await query(
    `SELECT p.*, u.full_name, CASE WHEN u.avatar_status = 'approved' THEN u.avatar_url ELSE NULL END AS avatar_url,
            (SELECT count(*) FROM bookings b WHERE b.provider_id = p.user_id AND b.status = 'completed') AS lessons_completed
     FROM institute_profiles p JOIN users u ON u.id = p.user_id
     WHERE p.slug = $1`,
    [req.params.slug]
  );
  const institute = result.rows[0];
  if (!institute) return res.status(404).render('not-found', { message: 'This institute page could not be found.', t: req.t, lang: req.lang });

  const subjects = await query(
    `SELECT s.name FROM profile_subjects ps JOIN subjects s ON s.id = ps.subject_id
     WHERE ps.profile_type = 'institute' AND ps.profile_id = $1`,
    [institute.user_id]
  );

  res.render('institute', {
    institute,
    subjects: subjects.rows,
    downloadHref: `/download?type=institute&slug=${encodeURIComponent(institute.slug)}`,
    t: req.t,
    lang: req.lang,
    formatPrice, formatDate, formatWeekdayAndTime,
  });
});

// Public, read-only view of a city's feed. Visitors can read posts;
// liking, commenting, and posting all require the app (or a future web
// login) — this page exists for discovery and SEO, not full interaction.
router.get('/feed/:city', async (req, res) => {
  const { city } = req.params;
  logPageView({ pageType: 'city_feed', target: city, req });

  const result = await query(
    `SELECT p.*, u.full_name AS author_name,
            (SELECT count(*) FROM post_likes WHERE post_id = p.id) AS like_count,
            (SELECT count(*) FROM post_comments WHERE post_id = p.id) AS comment_count
     FROM posts p JOIN users u ON u.id = p.author_id
     WHERE lower(p.city) = lower($1)
     ORDER BY p.created_at DESC
     LIMIT 50`,
    [city]
  );

  res.render('feed', {
    city,
    posts: result.rows,
    downloadHref: `/download?type=feed&slug=${encodeURIComponent(city)}`,
    t: req.t,
    lang: req.lang,
    formatPrice, formatDate, formatWeekdayAndTime,
  });
});

// Public teacher landing page — same pattern as the institute page.
router.get('/teacher/:slug', async (req, res) => {
  logPageView({ pageType: 'teacher', target: req.params.slug, req });

  const result = await query(
    `SELECT p.*, u.full_name, CASE WHEN u.avatar_status = 'approved' THEN u.avatar_url ELSE NULL END AS avatar_url,
            (SELECT count(*) FROM bookings b WHERE b.provider_id = p.user_id AND b.status = 'completed') AS lessons_completed
     FROM teacher_profiles p JOIN users u ON u.id = p.user_id
     WHERE p.slug = $1`,
    [req.params.slug]
  );
  const teacher = result.rows[0];
  if (!teacher) return res.status(404).render('not-found', { message: 'This teacher page could not be found.', t: req.t, lang: req.lang });

  const subjects = await query(
    `SELECT s.name FROM profile_subjects ps JOIN subjects s ON s.id = ps.subject_id
     WHERE ps.profile_type = 'teacher' AND ps.profile_id = $1`,
    [teacher.user_id]
  );

  res.render('teacher', {
    teacher,
    subjects: subjects.rows,
    downloadHref: `/download?type=teacher&slug=${encodeURIComponent(teacher.slug)}`,
    t: req.t,
    lang: req.lang,
    formatPrice, formatDate, formatWeekdayAndTime,
  });
});

// City index page — the SEO crawl path into individual teacher/institute
// pages. Lists every published teacher and institute in a city, grouped,
// each linking to their own page, plus the subjects taught there and a
// link to the city's feed. Without this, an institute's page only gets
// found by someone who already knows its exact URL.
router.get('/city/:city', async (req, res) => {
  const { city } = req.params;
  logPageView({ pageType: 'city_index', target: city, req });

  const teachers = await query(
    `SELECT p.slug, p.rating_avg, p.rating_count, p.is_verified, u.full_name
     FROM teacher_profiles p JOIN users u ON u.id = p.user_id
     WHERE lower(p.city) = lower($1) AND p.slug IS NOT NULL
     ORDER BY p.rating_avg DESC`,
    [city]
  );
  const institutes = await query(
    `SELECT p.slug, p.rating_avg, p.rating_count, p.is_verified, p.is_bsl_official, u.full_name
     FROM institute_profiles p JOIN users u ON u.id = p.user_id
     WHERE lower(p.city) = lower($1) AND p.slug IS NOT NULL
     ORDER BY p.is_bsl_official DESC, p.rating_avg DESC`,
    [city]
  );
  const subjects = await query(
    `SELECT DISTINCT s.name FROM profile_subjects ps
     JOIN subjects s ON s.id = ps.subject_id
     WHERE ps.profile_id IN (
       SELECT user_id FROM teacher_profiles WHERE lower(city) = lower($1)
       UNION
       SELECT user_id FROM institute_profiles WHERE lower(city) = lower($1)
     )
     ORDER BY s.name`,
    [city]
  );

  res.render('city-index', {
    city,
    teachers: teachers.rows,
    institutes: institutes.rows,
    subjects: subjects.rows,
    t: req.t,
    lang: req.lang,
  });
});

// Deep-link-aware download route lives in app.js at the root path
// (/download), not here — see app.js. Public pages link to it via
// downloadHref, built with the right ?type=&slug= for each page.

module.exports = router;
