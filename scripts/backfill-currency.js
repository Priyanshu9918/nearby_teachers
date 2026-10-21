#!/usr/bin/env node
// One-time backfill for the currency-by-country fix (see
// src/utils/currency.js and the README's "Local currency by country"
// section). The forward fix in PUT /profiles/me only affects new
// writes — a profile created before that fix shipped, whose owner
// never happened to touch their currency field, is still sitting on
// the old schema default of 'INR' regardless of their real country.
// This script finds and corrects those specific rows.
//
// Usage:
//   node scripts/backfill-currency.js           # dry run — reports what WOULD change, writes nothing
//   node scripts/backfill-currency.js --apply   # actually applies the fix
//
// Targeting rule, and why it's deliberately narrow: a row is only
// touched if currency = 'INR' AND country_code IS NOT NULL AND
// country_code != 'IN'. That combination is the specific fingerprint
// of "still on the old buggy default" — an Indian teacher with
// currency='INR' is already correct and is left alone either way.
//
// Known limitation, stated plainly rather than hidden: this can't
// perfectly distinguish "never touched the field, still the old bug's
// default" from "deliberately chose INR while being in a non-Indian
// country" — those look identical in the data. Given INR is not a
// common deliberate cross-border pricing choice, the far more likely
// explanation for any matched row is the bug, not genuine intent — but
// every change is logged below specifically so it can be reviewed and
// manually reverted if a real exception turns up.

require('dotenv').config();
const { query } = require('../src/db/pool');
const { currencyForCountry } = require('../src/utils/currency');

const APPLY = process.argv.includes('--apply');

async function backfillTable(table, idLabel) {
  const affected = await query(
    `SELECT user_id, country_code, currency FROM ${table}
     WHERE currency = 'INR' AND country_code IS NOT NULL AND upper(country_code) != 'IN'`
  );

  if (!affected.rows.length) {
    console.log(`${table}: no affected rows.`);
    return { checked: 0, updated: 0 };
  }

  console.log(`${table}: ${affected.rows.length} row(s) match the old-default fingerprint.`);
  let updated = 0;
  for (const row of affected.rows) {
    const correct = currencyForCountry(row.country_code);
    if (!correct || correct === row.currency) continue; // nothing to change, or no mapping for this country

    console.log(`  ${idLabel} ${row.user_id}: ${row.country_code} — INR -> ${correct}${APPLY ? '' : '  (dry run, not applied)'}`);
    if (APPLY) {
      await query(`UPDATE ${table} SET currency = $1, updated_at = now() WHERE user_id = $2`, [correct, row.user_id]);
    }
    updated++;
  }
  return { checked: affected.rows.length, updated };
}

async function main() {
  console.log(APPLY ? 'Running backfill (APPLYING changes)...' : 'Running backfill (DRY RUN — pass --apply to write changes)...');
  console.log();

  const teacherResult = await backfillTable('teacher_profiles', 'teacher');
  const instituteResult = await backfillTable('institute_profiles', 'institute');

  console.log();
  console.log('Summary:');
  console.log(`  Teachers:   ${teacherResult.checked} matched, ${teacherResult.updated} ${APPLY ? 'updated' : 'would update'}`);
  console.log(`  Institutes: ${instituteResult.checked} matched, ${instituteResult.updated} ${APPLY ? 'updated' : 'would update'}`);
  if (!APPLY && (teacherResult.updated || instituteResult.updated)) {
    console.log();
    console.log('This was a dry run — nothing was written. Re-run with --apply to make these changes.');
  }
  process.exit(0);
}

main().catch((err) => {
  console.error('Backfill failed:', err);
  process.exit(1);
});
