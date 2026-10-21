/**
 * Gate for the internal admin dashboard. Must run after requireAuth so
 * req.user is already loaded. There is no self-serve way to become an
 * admin — is_admin is set manually via SQL for trusted BSL staff.
 */
function requireAdmin(req, res, next) {
  if (!req.user || !req.user.is_admin) {
    return res.status(403).json({ error: 'Admin access required' });
  }
  next();
}

module.exports = { requireAdmin };
