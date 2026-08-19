# Lucius — Home Assistant piece

Five Node-RED flows: an Oura sync (every 4 hours, anchored 10am), a
GoDaddy manual-data pull (every 15 min), a Body Composition Import
(daily, ~noon — PLAN.md §14), a Status Heartbeat (every 15 min — PLAN.md
§15, feeds the GoDaddy-side `status.php`), plus the System Test
diagnostic flow (manual, not scheduled). **Nothing in this folder has
been run against a live stack — see the "What's verified vs. not" note
at the bottom before trusting this in production.**

## Setup order

### 1. Add-ons
Install via HAOS's Add-on Store:
- **InfluxDB v2** — **not** the default "InfluxDB" Community Add-on (that one is 1.8.x, a different data model entirely — no orgs/buckets/API tokens). This project uses **Jay's Home Assistant Add-ons: InfluxDB2**, added via its own catalog repository. Full steps confirmed working (not guessed): see `INFLUXDB_V2_SETUP.md` in this folder.
- **Node-RED** (official add-on) — after install, add the
  `node-red-contrib-home-assistant-websocket` palette (Node-RED's
  Manage Palette screen) so flows can update HA entities.

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
— those come from step 5.

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

Also create the two folders the Body Composition Import flow expects
(via Studio Code Server, Samba, or SSH — same access you used for step
4): `/share/lucius_body_comp_import/` (drop your scale app's `.xlsx`
exports here) and `/share/lucius_body_comp_import/processed/` (the flow
creates this on first run via the move-file node's `createDir` option,
but it's fine to make it yourself ahead of time too).

**Expect a warning right after importing:** *"[Lucius - Oura Sync] Call
HA service (set helper entity) (api-call-service)"* and the same for the
GoDaddy Pull and Body Composition Import flows. This is normal, not a
sign anything went wrong — these `api-call-service` nodes target HA
helper entities that don't exist yet until step 7, and their `Server`
connection field can't be pre-filled from the flow JSON (Node-RED
doesn't know your HA server connection details in advance). It'll clear
on its own once you've done step 7 and set each node's `Server` field
(see the checklist below) — no need to fix it right now if you're still
mid-setup.

**Before deploying:**
- Configure each `http request` node's method/URL if your Node-RED
  version needs it set explicitly rather than picked up from `msg.url`/
  `msg.method` (varies by version — check a node's config panel).
- Configure all three scheduled flows' `api-call-service` nodes' HA server connection (the `Server` field) — the system test flow doesn't have one of these, it only makes HTTP calls. This is what actually clears the warning above; also worth remembering it needs re-doing after every re-import, since a fresh import creates new node instances that don't carry over a previously-set connection.
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
- Status Heartbeat specifically: needs the `node-red-contrib-home-assistant-websocket`
  palette's `api-current-state` node (read a single entity's state) — this
  project has only used that palette for writes (`api-call-service`) so far,
  never reads. Each of the 12 chained `api-current-state` nodes needs its
  output property confirmed in the editor (should land on a specific
  `msg.*` property, e.g. `msg.oura_last_success`, not overwrite
  `msg.payload`) — the exact config field name for this has the same
  cross-version-drift risk already documented for `api-call-service`
  below. Also deploy `../godaddy/sql/upgrade_from_2.0.0.sql` first (safe
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

### 7. Add the HA helper entities and dashboard
**Note: `ha_config/` here is just this project's folder name for these two files — it is not, and does not correspond to, any real directory on your HA box** (not the same thing as the real `/config` from earlier steps). You're copying the *text inside* these two files into HA's actual configuration, not moving `ha_config/` itself anywhere.

Add `ha_config/helpers.yaml`'s contents to your real `configuration.yaml` (or via
Settings → Devices & Services → Helpers in the UI) and restart HA. Then
add `ha_config/dashboard.yaml`'s card to a dashboard (paste into a card's
"Show code editor" view).

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
*after* the other three so it has real entity values to read. Check: did
`ha_sync_log` on GoDaddy get a new row (`oura_sync.php`'s HA Sync Status
box, or `oura_test.php` for full history)? Did InfluxDB receive data
(`Data Explorer` in its UI)? Did the HA helper entities update? For Body
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

**Not verified — built from the proven PHP logic and Oura's documented
behavior, but never run against a live Node-RED/InfluxDB/Oura stack:**
all five flow JSON files. Specific things most likely to need real fixing,
based on how much trouble the *same* underlying logic caused the first
time it was built in PHP:
- The date-window Oura query logic (function node "Prepare Oura API
  requests") — this exact piece took three rounds of real debugging on
  the GoDaddy side before it worked. Expect it might need the same here.
- Exact `http request` node configuration for using `msg.url`/`msg.method`
  dynamically — Node-RED's node schema for this has changed across
  versions; may need each node's config verified/adjusted manually.
- The `api-call-service` nodes' domain/service/target/data field TYPES —
  these need to say "msg." not a fixed value, and the exact property
  names for this have changed across versions of the HA WebSocket
  palette, so this may need re-selecting in the editor's dropdowns
  rather than trusting the imported JSON blindly. Already replaced once
  in this project — the original design used the now-deprecated
  `ha-entity` node, caught when Ward actually tried it (see
  `PLAN.md` for the fix and why `api-call-service` was chosen over
  guessing at the newer per-domain node names).
- InfluxDB line-protocol escaping in the function nodes — reviewed for
  correctness but not run against a real InfluxDB write endpoint.
- **Body Composition Import's extra unverified surface, on top of everything above:** the oversized `readRange` request (`A1:Q5000`, filtering trailing empty rows itself rather than knowing the real row count ahead of time — see the flow's own tab info); the `split`/`join` loop used to POST each collapsed day's weight to `api/weight_push.php` one at a time (uses flow context, not msg passthrough, to carry the secrets/job-start-time across the loop — a deliberate choice to avoid relying on `join`'s less-predictable handling of non-payload message properties, but the loop itself has never run); and the shell command built to `mv` processed files into `processed/` (quoting handles spaces in filenames, not tested against a real filename with, say, an apostrophe in it).
- **Status Heartbeat's extra unverified surface:** the 12 chained `api-current-state` reads (this project has only used this palette for writes so far); and a deliberate scope decision, not a bug — the "HA" category's signal is just this flow's own successful execution, not a real `/api/config` call, since that would need an auth mechanism (Supervisor token or long-lived access token) not otherwise confirmed against a live instance. See `PLAN.md` §15's "Built" note.
- **Filesystem access rewritten twice, both from real testing.** First: all file reads/writes (secrets, disaster-recovery archive) originally used `require('fs')` inside function-node code, which doesn't work in a stock Node-RED Function node at all (sandboxed context, no `require()` — not version drift like the `ha-entity` issue, this has never worked). Fixed by moving all filesystem access to core `file`/`file in` nodes. Second, found immediately after on the very first real test: those nodes originally pointed at `/config`, which inside the **Node-RED add-on's own container is a different directory** than Home Assistant's real config folder — several HA add-ons, Node-RED included, have their own internal working directory that's also confusingly called `/config`. Every path now points at `/share` instead (HA's actual mechanism for sharing files between add-ons) — confirmed against Ward's real container listing, not guessed. `createDir` on the archive-write node (replacing the old `fs.mkdirSync` call) still hasn't been confirmed to actually create `/share/lucius_archive/` on a first run — worth watching for on the next real test.

Budget real debugging time for the Node-RED side, the same way the GoDaddy
Oura integration needed it — this isn't a sign anything was done
carelessly, it's the nature of code that talks to real external services
and can only be fully verified by actually running it against them.
