const express = require('express');
const { requireAuth } = require('../middleware/auth');
const { requireAdmin } = require('../middleware/admin');
const { query } = require('../db/pool');

const router = express.Router();

// Summary numbers for the admin dashboard's Analytics tab: top pages by
// views, top cities, and how "download the app" clicks break down by
// platform. Deliberately simple aggregate queries rather than a
// dashboard-building library — this is meant to answer "is this working"
// at a glance, not replace a real analytics product.
router.get('/summary', requireAuth, requireAdmin, async (req, res) => {
  const daysNum = Number(req.query.days);
  const days = Number.isFinite(daysNum) && daysNum > 0 ? Math.floor(daysNum) : 30;
  const since = `now() - interval '${days} days'`;

  const topPages = await query(
    `SELECT page_type, target, count(*) AS views
     FROM page_views
     WHERE created_at > ${since} AND page_type IN ('institute', 'teacher')
     GROUP BY page_type, target
     ORDER BY views DESC
     LIMIT 20`
  );

  const topCities = await query(
    `SELECT target AS city, count(*) AS views
     FROM page_views
     WHERE created_at > ${since} AND page_type IN ('city_index', 'city_feed')
     GROUP BY target
     ORDER BY views DESC
     LIMIT 20`
  );

  const downloadsByPlatform = await query(
    `SELECT platform, count(*) AS clicks
     FROM page_views
     WHERE created_at > ${since} AND page_type = 'download'
     GROUP BY platform
     ORDER BY clicks DESC`
  );

  const totals = await query(
    `SELECT page_type, count(*) AS views
     FROM page_views
     WHERE created_at > ${since}
     GROUP BY page_type`
  );

  res.json({
    sinceDays: days,
    topPages: topPages.rows,
    topCities: topCities.rows,
    downloadsByPlatform: downloadsByPlatform.rows,
    totalsByPageType: totals.rows,
  });
});

module.exports = router;
