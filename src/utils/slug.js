/**
 * Turns "Bright Route Institute" + "Kanpur" into "bright-route-institute-kanpur".
 * Appends a short random suffix on collision (handled by the caller retrying
 * the insert/update if the UNIQUE constraint on slug fails).
 */
function slugify(name, city) {
  const base = [name, city]
    .filter(Boolean)
    .join(' ')
    .toLowerCase()
    .trim()
    .replace(/[^a-z0-9\s-]/g, '')
    .replace(/\s+/g, '-')
    .replace(/-+/g, '-');
  return base || 'listing';
}

function withRandomSuffix(slug) {
  return `${slug}-${Math.random().toString(36).slice(2, 6)}`;
}

module.exports = { slugify, withRandomSuffix };
