# Project conventions

## Reporting architecture

The project owner expects future features to remain reportable. Treat reporting
and the data needed for historical questions as part of feature design.

- Register reports in `config/reports.php`. Use the shared `app/Reporting`
  registry, query/filter services, CSV exporter and admin access logger.
- Declare output, search, participant and timestamp columns explicitly. Never
  infer exposed columns from the live schema or accept SQL/table/column names
  from HTTP input. Keep page and export filters consistent.
- For new business state changes, decide which history is needed. Add a
  versioned, allowlisted event to `BusinessActivityRecorder` and record it in
  the same transaction as its source mutation. Keep externally delivered
  events after commit. Document event coverage and gaps.
- Never snapshot raw requests, complete user objects, passwords, OTPs, bearer
  tokens, consent tokens, or service credentials into reporting history.
- Keep business history append-only through application code. Do not silently
  cascade-delete event history with source records. Do not invent historical
  data or equate booking prices with payments/revenue.
- Protect report reads and exports with the admin session and access logging.
  Use bounded pages and streamed exports. Large aggregates or queued exports
  can be introduced when measured volume requires them.
- Verify affected queries, filters, exports, access control and transaction
  rollback with meaningful tests. Integration databases must be disposable
  and end in `_test`; never migrate or clear application data to run tests.

See `docs/REPORTING_ARCHITECTURE.md` for contracts, extension examples and
historical coverage.
