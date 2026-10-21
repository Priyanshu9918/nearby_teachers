const { Pool } = require('pg');

// Single shared connection pool for the whole app.
// Every query file below imports { query } from here rather than
// creating its own client, so connections are reused and capped.
const pool = new Pool({
  connectionString: process.env.DATABASE_URL,
});

async function query(text, params) {
  return pool.query(text, params);
}

module.exports = { pool, query };
