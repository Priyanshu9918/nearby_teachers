const express = require('express');
const { query } = require('../db/pool');

const router = express.Router();

// Powers the course catalog screen: a virtual "Instant Teacher Available"
// category first (all of BSL's own courses, gathered from across every
// real domain — BSL already has trainers on staff for these, so a match
// is effectively instant, unlike an independent listing that still needs
// discovery), then the full taxonomy grouped by domain.
//
// "Instant Teacher Available" is NOT a real row in subject_domains — it's
// computed here from is_bsl = TRUE across all domains. A subject like
// IELTS still belongs to exactly one real domain (Test prep &
// admissions) in the database; this view just also surfaces it up front.
// Giving it a real domain_id instead would mean either moving BSL
// subjects out of their natural domain (IELTS would vanish from Test
// prep & admissions) or duplicating each subject as two rows (breaking
// profile_subjects, since a teacher could then only tag one of the two
// duplicate IDs) — both worse than a computed view.
router.get('/', async (req, res) => {
  const bslResult = await query(
    `SELECT id, name, domain_id FROM subjects WHERE is_bsl = TRUE ORDER BY name`
  );

  const domainsResult = await query(
    `SELECT id, name, icon FROM subject_domains ORDER BY sort_order`
  );
  const subjectsResult = await query(
    `SELECT id, domain_id, name, is_bsl FROM subjects ORDER BY name`
  );

  const domains = domainsResult.rows.map((d) => ({
    ...d,
    subjects: subjectsResult.rows.filter((s) => s.domain_id === d.id),
  }));

  const instantTeacherAvailable = {
    id: 'instant-teacher-available', // synthetic, not a subject_domains row
    name: 'Instant Teacher Available',
    icon: 'ti-bolt',
    isVirtual: true,
    subjects: bslResult.rows,
  };

  res.json({
    bslFeatured: bslResult.rows, // kept for any client still using the old featured-strip shape
    domains: [instantTeacherAvailable, ...domains],
  });
});

module.exports = router;
