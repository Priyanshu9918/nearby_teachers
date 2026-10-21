const express = require('express');
const rateLimit = require('express-rate-limit');
const { query } = require('../db/pool');

const router = express.Router();

// Search is the main endpoint that could be scraped to bulk-extract the
// entire teacher/institute database (search by radius across many
// coordinates, or by-subject across every subject). 60 requests/minute
// per IP is generous for a real user browsing or refreshing search
// results, but makes systematic scraping slow enough to not be worth
// it. Keyed by IP since search doesn't require login — a per-user limit
// alone wouldn't stop an anonymous scraper.
const searchLimiter = rateLimit({
  windowMs: 60 * 1000,
  limit: 60,
  standardHeaders: true,
  legacyHeaders: false,
  message: { error: 'Too many search requests. Please slow down and try again shortly.' },
});
router.use(searchLimiter);

// A raw "ORDER BY rating_avg DESC" lets a single 5-star review (a
// friend, or a fake booking) outrank a profile with 50 reviews
// averaging 4.8 — genuinely misleading, not just a minor quirk. This is
// a standard Bayesian/IMDb-style weighted rating instead: a profile's
// score is pulled toward a neutral prior (PRIOR_RATING) until it has
// enough reviews (MIN_VOTES) of its own to be trusted on its own
// merits. A brand-new profile with zero reviews scores exactly
// PRIOR_RATING — visible, not buried at zero, but not falsely at the
// top either. Tune MIN_VOTES up if 5-review profiles still feel too
// volatile in practice, or down if established profiles feel like
// they're taking too long to differentiate from new ones.
const MIN_VOTES = 5;
const PRIOR_RATING = 4.0;
const weightedRatingSql = (ratingAvgCol, ratingCountCol) =>
  `(${ratingCountCol} * COALESCE(${ratingAvgCol}, 0) + ${MIN_VOTES} * ${PRIOR_RATING}) / (${ratingCountCol} + ${MIN_VOTES})`;

// Referral count as a SECONDARY sort key, after rating — never instead
// of it. A profile with real reviews always outranks one without,
// regardless of referral count; this only decides ordering among
// profiles that would otherwise tie (in practice, almost always
// zero-review profiles all sitting at PRIOR_RATING). That's
// deliberate: it gives new teachers/institutes something to compete on
// before they have reviews, without ever letting sharing the app
// substitute for actually being good at teaching. Not applied to
// Nearby search — distance is the only signal there, on purpose (see
// the search-ranking note in the README for why).
const referralCountSql = (userIdCol) =>
  `(SELECT count(*) FROM referrals WHERE referrer_id = ${userIdCol})`;

// GET /search?lat=..&lng=..&radiusKm=7&subjectId=&q=&freeOnly=true
// Powers the home screen (radius selector + counts) and the search/list
// and map screens. Returns teachers and institutes together, tagged with
// profile_type, sorted by distance. freeOnly=true restricts to listings
// with price = 0 (an explicit "this is free" signal, not just an unset
// rate — see profiles.routes.js, which stores 0 distinctly from null).
router.get('/', async (req, res) => {
  const { lat, lng, radiusKm = 7, subjectId, q, freeOnly } = req.query;
  if (!lat || !lng) return res.status(400).json({ error: 'lat and lng are required' });

  const radiusMeters = Number(radiusKm) * 1000;
  const point = `ST_SetSRID(ST_MakePoint($1, $2), 4326)::geography`;

  const subjectFilter = subjectId
    ? `AND EXISTS (
         SELECT 1 FROM profile_subjects ps
         WHERE ps.profile_type = t.profile_type AND ps.profile_id = t.user_id AND ps.subject_id = $4
       )`
    : '';
  const textFilter = q ? `AND u.full_name ILIKE $5` : '';
  const freeFilter = freeOnly === 'true' ? `AND t.price = 0` : '';

  const sql = `
    WITH t AS (
      SELECT 'teacher' AS profile_type, user_id, location, rate_per_hour AS price, rating_avg, rating_count, is_verified, trial_days
      FROM teacher_profiles
      UNION ALL
      SELECT 'institute' AS profile_type, user_id, location, starting_rate AS price, rating_avg, rating_count, is_verified, trial_days
      FROM institute_profiles
    )
    SELECT t.profile_type, t.user_id, u.full_name, CASE WHEN u.avatar_status = 'approved' THEN u.avatar_url ELSE NULL END AS avatar_url, t.price, t.rating_avg, t.rating_count,
           t.is_verified, t.trial_days,
           ST_Distance(t.location, ${point}) / 1000.0 AS distance_km
    FROM t
    JOIN users u ON u.id = t.user_id
    WHERE t.location IS NOT NULL
      AND ST_DWithin(t.location, ${point}, $3)
      ${subjectFilter}
      ${textFilter}
      ${freeFilter}
    ORDER BY distance_km ASC
    LIMIT 50
  `;

  const params = [lng, lat, radiusMeters];
  if (subjectId) params.push(subjectId);
  if (q) params.push(`%${q}%`);

  const result = await query(sql, params);
  res.json({ results: result.rows });
});

// GET /search/counts?lat=..&lng=..&radiusKm=7
// Powers the two stat boxes on the home screen.
router.get('/counts', async (req, res) => {
  const { lat, lng, radiusKm = 7 } = req.query;
  if (!lat || !lng) return res.status(400).json({ error: 'lat and lng are required' });

  const radiusMeters = Number(radiusKm) * 1000;
  const point = `ST_SetSRID(ST_MakePoint($1, $2), 4326)::geography`;

  const result = await query(
    `SELECT
       (SELECT count(*) FROM teacher_profiles WHERE ST_DWithin(location, ${point}, $3)) AS teacher_count,
       (SELECT count(*) FROM institute_profiles WHERE ST_DWithin(location, ${point}, $3)) AS institute_count`,
    [lng, lat, radiusMeters]
  );

  res.json(result.rows[0]);
});

// GET /search/other-city?city=Berlin&subjectId=&q=
// Powers the "Other city / online" toggle on the home screen: for a
// learner looking beyond their own radius (a different city, or an
// international trainer), instead of PostGIS distance we match on the
// profile's city/country text and require online availability — an
// in-person-only teacher three time zones away isn't a useful result.
router.get('/other-city', async (req, res) => {
  const { city, subjectId, q } = req.query;
  if (!city) return res.status(400).json({ error: 'city is required' });

  const subjectFilter = subjectId
    ? `AND EXISTS (
         SELECT 1 FROM profile_subjects ps
         WHERE ps.profile_type = t.profile_type AND ps.profile_id = t.user_id AND ps.subject_id = $3
       )`
    : '';
  const textFilter = q ? `AND u.full_name ILIKE $4` : '';

  const sql = `
    WITH t AS (
      SELECT 'teacher' AS profile_type, user_id, city, country, lesson_modes,
             rate_per_hour AS price, rating_avg, rating_count, is_verified, trial_days
      FROM teacher_profiles
      UNION ALL
      SELECT 'institute' AS profile_type, user_id, city, country, lesson_modes,
             starting_rate AS price, rating_avg, rating_count, is_verified, trial_days
      FROM institute_profiles
    )
    SELECT t.profile_type, t.user_id, u.full_name, CASE WHEN u.avatar_status = 'approved' THEN u.avatar_url ELSE NULL END AS avatar_url, t.city, t.country,
           t.price, t.rating_avg, t.rating_count, t.is_verified, t.trial_days
    FROM t
    JOIN users u ON u.id = t.user_id
    WHERE 'online' = ANY(t.lesson_modes)
      AND (t.city ILIKE $1 OR t.country ILIKE $2)
      ${subjectFilter}
      ${textFilter}
    ORDER BY ${weightedRatingSql('t.rating_avg', 't.rating_count')} DESC, ${referralCountSql('t.user_id')} DESC
    LIMIT 50
  `;

  const params = [`%${city}%`, `%${city}%`];
  if (subjectId) params.push(subjectId);
  if (q) params.push(`%${q}%`);

  const result = await query(sql, params);
  res.json({ results: result.rows });
});

// GET /search/by-subject/:subjectId?tier=nearby&lat=&lng=&radiusKm=
// GET /search/by-subject/:subjectId?tier=city&city=
// GET /search/by-subject/:subjectId?tier=state&state=
// GET /search/by-subject/:subjectId?tier=country&country=
// GET /search/by-subject/:subjectId?tier=other-countries&country=
//
// Powers the course detail screen's tab strip. Four tiers return a
// trainer list; "other-countries" instead returns a list of countries
// with a trainer count, since listing every trainer worldwide in one
// scroll isn't useful — the client drills into a country by re-calling
// this with tier=country&country=<name>.
router.get('/by-subject/:subjectId', async (req, res) => {
  const { subjectId } = req.params;
  const { tier, lat, lng, radiusKm = 7, city, state, country } = req.query;

  const base = `
    WITH t AS (
      SELECT 'teacher' AS profile_type, user_id, location, city, state, country,
             rate_per_hour AS price, rating_avg, rating_count, is_verified, trial_days
      FROM teacher_profiles
      UNION ALL
      SELECT 'institute' AS profile_type, user_id, location, city, state, country,
             starting_rate AS price, rating_avg, rating_count, is_verified, trial_days
      FROM institute_profiles
    ), matching AS (
      SELECT t.* FROM t
      WHERE EXISTS (
        SELECT 1 FROM profile_subjects ps
        WHERE ps.profile_type = t.profile_type AND ps.profile_id = t.user_id AND ps.subject_id = $1
      )
    )
  `;

  if (tier === 'nearby') {
    if (!lat || !lng) return res.status(400).json({ error: 'lat and lng are required for tier=nearby' });
    const point = `ST_SetSRID(ST_MakePoint($2, $3), 4326)::geography`;
    const [result, countResult] = await Promise.all([
      query(
        `${base}
         SELECT m.profile_type, m.user_id, u.full_name, CASE WHEN u.avatar_status = 'approved' THEN u.avatar_url ELSE NULL END AS avatar_url, m.price, m.rating_avg, m.rating_count,
                m.is_verified, m.trial_days,
                ST_Distance(m.location, ${point}) / 1000.0 AS distance_km
         FROM matching m JOIN users u ON u.id = m.user_id
         WHERE m.location IS NOT NULL
           AND ST_DWithin(m.location, ${point}, $4)
         ORDER BY distance_km ASC LIMIT 50`,
        [subjectId, lng, lat, Number(radiusKm) * 1000]
      ),
      // Separate, unlimited count — the query above caps at 50 rows, so
      // counting its returned array would silently under-report once a
      // subject has more than 50 teachers in range.
      query(
        `${base}
         SELECT count(*) AS total FROM matching m
         WHERE m.location IS NOT NULL AND ST_DWithin(m.location, ${point}, $4)`,
        [subjectId, lng, lat, Number(radiusKm) * 1000]
      ),
    ]);
    return res.json({ tier, totalCount: Number(countResult.rows[0].total), results: result.rows });
  }

  if (tier === 'city' || tier === 'state' || tier === 'country') {
    const value = tier === 'city' ? city : tier === 'state' ? state : country;
    if (!value) return res.status(400).json({ error: `${tier} is required for tier=${tier}` });
    const [result, countResult] = await Promise.all([
      query(
        `${base}
         SELECT m.profile_type, m.user_id, u.full_name, CASE WHEN u.avatar_status = 'approved' THEN u.avatar_url ELSE NULL END AS avatar_url, m.price, m.rating_avg, m.rating_count,
                m.is_verified, m.trial_days, m.city, m.state, m.country
         FROM matching m JOIN users u ON u.id = m.user_id
         WHERE lower(m.${tier}) = lower($2)
         ORDER BY ${weightedRatingSql('m.rating_avg', 'm.rating_count')} DESC, ${referralCountSql('m.user_id')} DESC LIMIT 50`,
        [subjectId, value]
      ),
      query(
        `${base}
         SELECT count(*) AS total FROM matching m WHERE lower(m.${tier}) = lower($2)`,
        [subjectId, value]
      ),
    ]);
    return res.json({ tier, totalCount: Number(countResult.rows[0].total), results: result.rows });
  }

  if (tier === 'other-countries') {
    // If a country is given, drill into that country's trainer list;
    // otherwise return the list of countries (with counts) to choose from.
    if (country) {
      const [result, countResult] = await Promise.all([
        query(
          `${base}
           SELECT m.profile_type, m.user_id, u.full_name, CASE WHEN u.avatar_status = 'approved' THEN u.avatar_url ELSE NULL END AS avatar_url, m.price, m.rating_avg, m.rating_count,
                  m.is_verified, m.trial_days, m.city, m.country
           FROM matching m JOIN users u ON u.id = m.user_id
           WHERE lower(m.country) = lower($2)
           ORDER BY ${weightedRatingSql('m.rating_avg', 'm.rating_count')} DESC, ${referralCountSql('m.user_id')} DESC LIMIT 50`,
          [subjectId, country]
        ),
        query(
          `${base}
           SELECT count(*) AS total FROM matching m WHERE lower(m.country) = lower($2)`,
          [subjectId, country]
        ),
      ]);
      return res.json({ tier, country, totalCount: Number(countResult.rows[0].total), results: result.rows });
    }
    const result = await query(
      `${base}
       SELECT m.country, count(*) AS trainer_count
       FROM matching m
       WHERE m.country IS NOT NULL
       GROUP BY m.country
       ORDER BY trainer_count DESC`,
      [subjectId]
    );
    return res.json({ tier, countries: result.rows });
  }

  res.status(400).json({ error: 'tier must be one of: nearby, city, state, country, other-countries' });
});

module.exports = router;
