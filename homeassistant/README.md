# wherewhen — the Home Assistant piece of Lucius

*wherewhen is this engine's own name — "Lucius" is the umbrella project covering both this and the GoDaddy piece (WardStock), not a name for this engine specifically.*

Five REQUIRED Node-RED flows: an Oura sync (every 4 hours, anchored
10am), a GoDaddy manual-data pull (every 15 min), a Body Composition
Import (daily, ~noon — PLAN.md §14), a Status Heartbeat (every 15 min —
PLAN.md §15, feeds the GoDaddy-side `status.php`), plus the System Test
diagnostic flow (manual, not scheduled). Plus five OPTIONAL flows, built
for the Fulgrim (3.x) line — see "Optional flows" below: Oura Backfill
(manual), Medical History Import (manual — PLAN.md §19), wherewhen Data
Export (manual + weekly — PLAN.md §20), wherewhen Data Restore (manual —
PLAN.md §20), and the Analysis Engine (daily/weekly/monthly + manual —
PLAN.md §11, the ~20-analysis Flux engine). **Nothing in this folder has
been run against a live stack — see the "What's verified vs. not" note
at the bottom before trusting this in production.**

## Setup order

### 1. Add-ons
Install via HAOS's Add-on Store:
- **InfluxDB v2** — **not** the default "InfluxDB" Community Add-on (that one is 1.8.x, a different data model entirely — no orgs/buckets/API tokens). This project uses **Jay's Home Assistant Add-ons: InfluxDB2**, added via its own catalog repository. Full steps confirmed working (not guessed): see `INFLUXDB_V2_SETUP.md` in this folder.
- **Node-RED** (official add-on) — after install, add a SQLite palette
  (Node-RED's Manage Palette screen) — every flow's status tracking reads/
  writes a shared `/share/lucius_status.db` (Aug 2026 SQLite migration,
  see step 6/7 below); confirmed working against `node-red-node-sqlite`
  (registers node types `sqlite`/`sqlitedb`), watch for a native-build
  failure if your Node-RED add-on's container is Alpine-based and that
  package's precompiled binaries don't cover your platform — see
  `nodered/sqlite_test_flow.json`'s own tab info for the full story and a
  way to validate it works before trusting the real flows.
  **`node-red-contrib-home-assistant-websocket` is NOT needed anymore** —
  every flow that used to read/write HA helper entities through it has
  moved to the SQLite table instead (see "SQLite migration" below); this
  project no longer uses that palette for anything.

### 2. InfluxDB
Full walkthrough (add-on repository, first-run org/bucket wizard,
scoped API token generation, confirming the real reachable hostname,
the add-on's own `ssl: false` config) is in **`INFLUXDB_V2_SETUP.md`** —
follow it start to finish before continuing to step 3. End state: a
bucket name, org name, API token, and confirmed URL/hostname — you'll
need all four in step 4.

### 3. Deploy the GoDaddy side (if not already deployed)
Everything in `../godaddy/` needs to be live first — the new
`api/*.php` endpoints and the `ha_sync_log` table specifically. See
`../godaddy/README.md`. Confirm `API_SYNC_TOKEN` in
`../godaddy/config/config.php` — you'll need its exact value in step 4.

### 4. Secrets file
Copy `lucius_secrets.json.example` (in this folder) to `/share/lucius_secrets.json`
on the HA box (via the Studio Code Server add-on, Samba, or SSH). Fill in every
field **except** `oura_access_token`/`oura_refresh_token`/`oura_expires_at`
— those come from step 5 — **and except `oura_client_id`/`oura_client_secret`,
which aren't in the file at all anymore.** GoDaddy's `config.php` is now the
source of truth for those two; the Oura Sync flow fetches them live each run
via `api/get_shared_config.php` (PLAN.md §3).

**Use `/share`, not `/config`, and not whatever `/config` shows up as inside
the Node-RED add-on specifically** — this tripped Ward up on the first real
test run, worth understanding why so it doesn't happen again for anything
else added later. Every HA add-on runs in its own isolated container, and
several of them — including the Node-RED add-on — have their **own internal
working directory that's also called `/config`**, completely unrelated to
Home Assistant's real configuration folder that Studio Code Server edits.
`/share` is HA's actual purpose-built mechanism for sharing files *between*
add-ons that are otherwise isolated like this, and it's not web-exposed,
same as `/config` would have been.

### 5. Bootstrap Oura's initial tokens (one-time, manual)
Oura's OAuth flow requires a redirect URI that's both pre-registered with
Oura *and* a real, publicly-reachable HTTPS endpoint. HA/Node-RED doesn't
have one of its own — only GoDaddy's `oura_callback.php` is registered.
So the initial authorization has to happen through GoDaddy's existing,
already-working flow, then get copied over:

1. On GoDaddy, visit `oura_connect.php` and authorize (same as any
   manual reconnect — make sure `sleep` scope is included, same
   requirement as GoDaddy's own integration).
2. In phpMyAdmin, look at the `oura_tokens` table — copy `access_token`,
   `refresh_token`, and `expires_at` into the matching fields in
   `/share/lucius_secrets.json`.
3. From this point on, HA's Node-RED flow refreshes and manages its own
   copy independently — the two systems' tokens will diverge after the
   first refresh (Oura rotates the refresh token on every use), and
   that's expected, not a problem. GoDaddy's own connection keeps working
   independently as the manual fallback (PLAN.md §5).

### 6. Import the Node-RED flows
In Node-RED: Menu → Import → paste the contents of
`nodered/oura_sync_flow.json`, `nodered/godaddy_pull_flow.json`,
`nodered/body_comp_import_flow.json`, `nodered/status_heartbeat_flow.json`,
and `nodered/system_test_flow.json` (five separate imports).

**Re-importing an updated version of a flow you already have does NOT overwrite it in place — confirmed the hard way (Ward, Aug 2026).** When the incoming JSON's node IDs collide with nodes already on the canvas, Node-RED's default import behavior creates a **second, separate copy** of the tab (new IDs) rather than replacing the original — so after a re-import you can end up with two "wherewhen - Data Export" tabs, the old (still-broken) one and the new (fixed) one, and whichever one's trigger you actually fire is the one that runs. This is exactly what happened when a real fix (`wherewhen_data_export_flow.json`'s file-write nodes) got re-imported and Ward kept hitting the pre-fix error — the old tab was still there, untouched, still wired to the same trigger. **After any re-import, check for a duplicate tab before assuming the update took:** if there are two copies of a flow, open the node you expect to have changed in each and compare — delete the stale one (right-click its tab → Delete), keep the one with the actual change, then Deploy.

**Optional flows, not part of the required setup sequence** — import
any/all whenever you actually want them, no harm leaving any out until
then. See each flow's own tab `info` in the Node-RED editor for full
usage details.

- `nodered/oura_backfill_flow.json` — manual, on-demand tool to backfill
  full historical Oura data straight into InfluxDB (past the curated
  subset a manual GoDaddy pull gives you), for whatever range you set on
  its inject node before clicking. `PLAN.md` §17. No companion test flow
  (deliberate exception, PLAN.md §2 — this flow already is a manual
  diagnostic tool in its own right).
- `nodered/medical_history_import_flow.json` — manual, watches
  `/share/lucius_medical_history_import/` for structured YAML files and
  upserts incidents from them. `PLAN.md` §19. Companion test:
  `medical_history_import_test.json`.
- `nodered/wherewhen_data_export_flow.json` — manual + weekly (Sunday
  3am), backs up everything InfluxDB holds (the high-fidelity Oura
  archive, decomposed series, body composition, analysis results,
  everything else with no other backup) to `/share/lucius_data_backup/`.
  `PLAN.md` §20. No dedicated test flow of its own — see
  `wherewhen_data_restore_test.json`, which exercises the shared CSV
  parsing logic both flows depend on.
- `nodered/wherewhen_data_restore_flow.json` — manual only, restores from
  a wherewhen Data Export backup into a fresh InfluxDB (the
  wipe/migrate-HA scenario). The highest-stakes flow in this project per
  its own tab info — read that before running it for real. `PLAN.md`
  §20. Companion test: `wherewhen_data_restore_test.json`.
- `nodered/analysis_engine_flow.json` — the Fulgrim Flux analysis engine
  (`PLAN.md` §11): computes all ~20 planned analyses and pushes each to
  GoDaddy's `analysis.php`. Daily (~12:15pm), weekly (Sunday 4am), and
  monthly (1st, 5am) triggers are scheduled; a 4th, all-historical-data
  tier is manual-only (same reasoning as Oura Backfill — the heaviest,
  least time-sensitive tier). Companion test:
  `analysis_engine_test.json`.

Also create the two folders each file-drop flow expects, the same way
you did for Body Composition Import below: `/share/lucius_medical_history_import/`
+ its own `processed/` subfolder for Medical History Import.

**`nodered/all_flows_merged.json` — every flow above, concatenated into one importable file (Ward, Aug 2026).** Each flow file is just a JSON array of nodes with its own `tab` entry, so all fifteen (five required + ten optional) can be merged into one array and imported in a single Menu → Import, rather than one at a time — confirmed no node-id collisions across the full set, **except** the shared `cfg_lucius_status_db` SQLite config node, which appears once per flow file *on purpose* (8 identical copies in the merged file) — Node-RED should deduplicate identical-id config nodes on import rather than creating 8 separate database connections; not yet confirmed against a real import of the full merged file. Meant for a from-scratch or delete-and-reload setup; for updating just the flows that actually changed in a given batch, use the individual files instead. **Regenerated by Claude whenever any flow changes** — if a flow file and this one ever look out of sync, the individual file is the source of truth; ask for `all_flows_merged.json` to be rebuilt.
`wherewhen_data_export_flow.json`/`wherewhen_data_restore_flow.json`
create their own `/share/lucius_data_backup/<timestamp>/` directories on
each run (via the `mkdir -p` exec node), nothing to pre-create.

Also create the two folders the Body Composition Import flow expects
(via Studio Code Server, Samba, or SSH — same access you used for step
4): `/share/lucius_body_comp_import/` (drop your scale app's `.xlsx`
exports here) and `/share/lucius_body_comp_import/processed/` (the flow
creates this on first run via the move-file node's `createDir` option,
but it's fine to make it yourself ahead of time too).

**Expect a warning right after importing if you haven't installed a SQLite palette yet (see step 1):** *"The workspace contains some unknown node types: sqlitedb, sqlite. Are you sure you want to deploy?"* — safe to confirm-deploy anyway if you're still mid-setup; only the SQLite nodes themselves stay inert until the palette's installed, everything else in the workspace deploys and runs normally (Node-RED disables unrecognized nodes individually, not the whole tab/workspace).

**Before deploying:**
- Configure each `http request` node's method/URL if your Node-RED
  version needs it set explicitly rather than picked up from `msg.url`/
  `msg.method` (varies by version — check a node's config panel).
- Double-check the Oura flow's inject node's cron expression
  (`0 10,14,18,22,2,6 * * *`) and the Body Composition Import flow's
  (`0 12 * * *`) actually took — inject-node scheduling UI has changed
  across Node-RED versions.
- Confirm the `file`/`file in` nodes' paths (`/share/lucius_secrets.json`,
  `/share/lucius_archive/latest_manual_data.json`) match where you
  actually put the secrets file in step 4 — these are the nodes that
  replaced the `require('fs')` bug from the first version of these
  flows, and their paths are just as important to get right as any
  `http request` node's URL.
- Deploy `../godaddy/sql/upgrade_from_2.0.0.sql` first (safe
  to run even if you've already applied part of it) — `api/status_push.php`
  needs the `system_status_reports` table it creates.
- Body Composition Import specifically: the `exceljs` nodes require the
  `node-red-contrib-officedocs` palette package (Manage Palette) —
  already confirmed installed and working via `nodered/body_comp_xlsx_test.json`,
  but a fresh Node-RED instance won't have it yet. Its read API needs a
  known row range (`readRange`); this flow requests an oversized one
  (`A1:Q5000`) and filters out the trailing empty rows itself, since a
  real export's exact row count isn't known ahead of time — worth
  watching on the first real run (see the flow's own tab info note).

### 7. SQLite status tracking — nothing to configure
**`ha_config/helpers.yaml` and `ha_config/dashboard.yaml` are retired (Aug 2026 SQLite migration) — skip this step entirely if you're setting up fresh.** Every flow's status tracking (last success/attempt/error) now lives in a shared table (`job_status`) in `/share/lucius_status.db`, not HA helper entities — see either file's own header comment for the full story if you're curious, or had an earlier install with these already added to `configuration.yaml` (safe to remove, nothing reads or writes them anymore).

**Nothing needs manually creating or configuring here** — unlike the old HA helper entities (which needed adding to `configuration.yaml` by hand) or the old `api-call-service` nodes' `Server` field (which needed re-setting after every re-import), the `job_status` table creates itself automatically: every status-writing flow runs `CREATE TABLE IF NOT EXISTS` before its first write, so whichever flow happens to run first on a fresh install creates it for everyone else. The SQLite config node's database path (`/share/lucius_status.db`) is baked into the exported flow JSON itself, not a per-install blank field — one less thing to redo on every re-import.

Status visibility going forward: `status.php` on GoDaddy (unchanged — still reads what Status Heartbeat pushes there), and/or browsing `/share/lucius_status.db` directly via the SQLite Web add-on if you want to look at the raw table.

### 8. Run the system test first
Before touching the two sync flows, click the inject node on the
**"Lucius - System Test"** tab and check the debug sidebar. It checks,
in order: the secrets file loads with every required key present,
generic internet reachability, InfluxDB reachability, Oura's API
reachability, and GoDaddy's `api/status.php` (reachability + your
`API_SYNC_TOKEN` + whether the two sides' versions are in sync) — one
readable pass/fail summary, each failure with a specific, actionable
reason rather than a bare "failed." This is a manual health check, not
a scheduled job — re-run it any time something seems off, including
after any future config or version change on either side.

### 9. First real sync test
Once the system test passes, manually trigger each of the four
scheduled flows (click their inject nodes) rather than waiting for the
schedule — for Body Composition Import, drop a real `.xlsx` export into
`/share/lucius_body_comp_import/` first, and trigger Status Heartbeat
*after* the other three so it has real job_status rows to read. Check: did
`ha_sync_log` on GoDaddy get a new row (`oura_sync.php`'s HA Sync Status
box, or `oura_test.php` for full history)? Did InfluxDB receive data
(`Data Explorer` in its UI)? Did `/share/lucius_status.db`'s `job_status`
table pick up a row per flow (browse via the SQLite Web add-on, or query
it directly)? For Body
Composition Import specifically, also check that the file moved into
`/share/lucius_body_comp_import/processed/` and that `daily_logs.weight`
picked up a value only on days that didn't already have one. For Status
Heartbeat, check `status.php` on GoDaddy shows all four components with
real timestamps, not "never reported."

## What's verified vs. not

**Verified / high confidence:** the GoDaddy-side PHP (`api/*.php`,
`ha_sync_log`, the display pages) — built with the same patterns and
checks used throughout the rest of WardStock, brace-balance and logic
reviewed carefully.

**Not verified — built from proven logic and documented behavior, but
never run against a live Node-RED/InfluxDB/Oura/GoDaddy stack:** all
fifteen flow JSON files. Specific things most likely to need real fixing:
- The date-window Oura query logic (function node "Prepare Oura API
  requests") — this exact piece took three rounds of real debugging on
  the GoDaddy side before it worked. Expect it might need the same here.
- Exact `http request` node configuration for using `msg.url`/`msg.method`
  dynamically — Node-RED's node schema for this has changed across
  versions; may need each node's config verified/adjusted manually.
- InfluxDB line-protocol escaping in the function nodes — reviewed for
  correctness but not run against a real InfluxDB write endpoint.
- **Body Composition Import's extra unverified surface, on top of everything above:** the oversized `readRange` request (`A1:Q5000`, filtering trailing empty rows itself rather than knowing the real row count ahead of time — see the flow's own tab info); the `split`/`join` loop used to POST each collapsed day's weight to `api/weight_push.php` one at a time (uses flow context, not msg passthrough, to carry the secrets/job-start-time across the loop — a deliberate choice to avoid relying on `join`'s less-predictable handling of non-payload message properties, but the loop itself has never run); and the shell command built to `mv` processed files into `processed/` (quoting handles spaces in filenames, not tested against a real filename with, say, an apostrophe in it).
- **Filesystem access rewritten twice, both from real testing.** First: all file reads/writes (secrets, disaster-recovery archive) originally used `require('fs')` inside function-node code, which doesn't work in a stock Node-RED Function node at all (sandboxed context, no `require()` — this has never worked). Fixed by moving all filesystem access to core `file`/`file in` nodes. Second, found immediately after on the very first real test: those nodes originally pointed at `/config`, which inside the **Node-RED add-on's own container is a different directory** than Home Assistant's real config folder — every path now points at `/share` instead, confirmed against Ward's real container listing, not guessed.

**SQLite status-tracking migration (Aug 2026) — partially validated, not run for real yet.** `node-red-node-sqlite`'s node type/query/result shape (`msg.topic` = SQL text, `msg.payload` = array of row objects back) IS confirmed against Ward's real instance (`sqlite_test_flow.json` Part A: CREATE/INSERT/SELECT/DROP all passed). What's still unconfirmed:
- The actual UPSERT statements each flow's `job_success`/`job_failed` now runs — every function body was syntax-checked and the generated SQL was executed against a real (if local/offline) SQLite engine with correct results, but never through an actual deployed Node-RED flow against `/share/lucius_status.db`.
- Whether the shared `cfg_lucius_status_db` config node — deliberately duplicated once per flow file, byte-identical each time — actually deduplicates cleanly on import rather than creating multiple connections to the same file. SQLite handles concurrent connections to one file fine either way (that's not the risk), but worth confirming Node-RED's own import behavior here regardless.
- oura_sync's self-heal checkpoint, moved from an HA helper to a `checkpoint` column on the same `job_status` row — the read-then-decide-fetch-window logic downstream (`n_oura_fetch_sleep`) is completely unchanged, only how the value gets into `msg.oura_last_data_date` changed, but that seam is untested.

Budget real debugging time for the Node-RED side, the same way the GoDaddy
Oura integration needed it — this isn't a sign anything was done
carelessly, it's the nature of code that talks to real external services
and can only be fully verified by actually running it against them.
