const { query } = require('../db/pool');

/**
 * Logs a page view. Never throws and never awaited by the caller in the
 * hot path — a logging failure should never slow down or break a page
 * request. Call it and move on: `logPageView(...)`, not `await logPageView(...)`.
 */
function logPageView({ pageType, target = null, platform = null, req }) {
  query(
    `INSERT INTO page_views (page_type, target, platform, referrer, user_agent)
     VALUES ($1, $2, $3, $4, $5)`,
    [pageType, target, platform, req.get('referer') || null, req.get('user-agent') || null]
  ).catch((err) => console.error('Page view logging failed:', err.message));
}

/**
 * Crude but effective platform detection from the User-Agent header —
 * good enough to route the download link correctly. Not meant to be a
 * general-purpose device-detection library.
 */
function detectPlatform(userAgent = '') {
  const ua = userAgent.toLowerCase();
  if (/iphone|ipad|ipod/.test(ua)) return 'ios';
  if (/android/.test(ua)) return 'android';
  if (/mobile/.test(ua)) return 'unknown'; // some other mobile OS
  return 'desktop';
}

module.exports = { logPageView, detectPlatform };
