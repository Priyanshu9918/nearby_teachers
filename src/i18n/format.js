// Locale-aware formatting for prices and dates/times, built on the
// standard Intl API rather than hand-rolled per-language rules — the
// same approach any production JS backend or React Native app should
// use, so this is meant to be the actual pattern the real app copies,
// not just what the website happens to use.
//
// Real, verified differences across the 8 supported languages (not
// hypothetical): English/Hindi/Chinese use a 12-hour clock with
// AM/PM; Spanish/French/Portuguese/Russian use 24-hour; Arabic uses
// 12-hour with its own AM/PM markers (ص/م). Currency formatting
// varies in decimal separator (. vs ,) and symbol placement/style
// (£22.00 vs 22,00 £ vs 22.00 UK£) — these are exactly the kinds of
// details worth letting Intl handle rather than guessing.

const SUPPORTED = ['en', 'hi', 'es', 'fr', 'ar', 'zh', 'pt', 'ru'];

function safeLang(lang) {
  return SUPPORTED.includes(lang) ? lang : 'en';
}

/**
 * Formats a price for display, e.g. formatPrice(22, 'GBP', 'fr') -> "22,00 £GB"
 * amount === 0 is the caller's responsibility to handle as "Free" —
 * this function only formats a real positive amount.
 */
function formatPrice(amount, currency, lang) {
  return new Intl.NumberFormat(safeLang(lang), { style: 'currency', currency }).format(amount);
}

/**
 * Formats a date's weekday name, e.g. formatWeekday(date, 'ru') -> "среда"
 */
function formatWeekday(date, lang, style = 'long') {
  return new Date(date).toLocaleDateString(safeLang(lang), { weekday: style });
}

/**
 * Formats a time, correctly switching 12h/24h per language automatically
 * (Intl decides this per-locale — not something we set manually).
 * timeZone defaults to UTC; pass the actual zone once the app has one.
 */
function formatTime(date, lang, timeZone = 'UTC') {
  return new Date(date).toLocaleTimeString(safeLang(lang), {
    hour: 'numeric', minute: '2-digit', timeZone,
  });
}

/**
 * Formats a full date, e.g. "Sep 23, 2026" / "23 sept. 2026" / "٢٣ سبتمبر ٢٠٢٦"
 */
function formatDate(date, lang, style = 'medium') {
  return new Date(date).toLocaleDateString(safeLang(lang), { dateStyle: style });
}

/**
 * Combines weekday + time the way the booking/request screens show it,
 * e.g. "Tuesday, 4:00 PM" (en) / "mardi, 16h00" (fr — note the "h"
 * instead of ":", handled below) / "الثلاثاء، ٤:٠٠ م" (ar).
 */
function formatWeekdayAndTime(date, lang, timeZone = 'UTC') {
  const weekday = formatWeekday(date, lang);
  const time = formatTime(date, lang, timeZone);
  // French conventionally writes clock time with "h" instead of ":"
  // (16h00, not 16:00) — Intl's timeStyle doesn't do this
  // substitution itself, so it's applied explicitly here rather than
  // silently shipping the ":" form for French users.
  const formattedTime = lang === 'fr' ? time.replace(':', 'h') : time;
  return `${weekday}, ${formattedTime}`;
}

module.exports = { formatPrice, formatWeekday, formatTime, formatDate, formatWeekdayAndTime, SUPPORTED };
