# HA/sqlite — gap, not an oversight

No schema file lives here yet. WardStock's `/share` SQLite databases
(`wardstock_status.db`... actually the shared job/status table lives in
`lucius_status.db`, owned by `standwhy/HA/sqlite/` — see that repo) and
WardStock's own local databases (`wardstock_godaddy_backup.db`,
`wardstock_sqlite_test.db`) are created inline by Node-RED flow code via
`CREATE TABLE IF NOT EXISTS`, not from a checked-in `.sql` file.

Extracting a real schema file here (matching standwhy's
`HA/sqlite/lucius_status_schema.sql` pattern) is optional future work,
not part of the HA/hosted directory restructure that created this
folder (26 Aug 2026, `RESTRUCTURE.md`).
