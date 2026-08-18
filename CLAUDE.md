# CLAUDE.md — Lucius / WardStock

Read this first. It points to the detailed docs rather than repeating them — check those before assuming something isn't already decided.

## What this is

A two-piece personal health-tracking project for Ward. **`godaddy/`** — WardStock, a PHP/MySQL app on GoDaddy shared hosting (incidents, daily log, medications, therapy, dashboard). **`homeassistant/`** — Node-RED/InfluxDB running on Home Assistant, syncing with WardStock and doing local-only analysis (nothing raw ever leaves the home server). **`marketing/`** — LeeWard-branded flyer for the whole project. Full narrative: **`RETROSPECTIVE.md`**.

## Where the detail actually lives — read before making changes

- **`RETROSPECTIVE.md`** — phase-by-phase history: what was assumed, what broke, what changed. Best starting point for orientation.
- **`godaddy/PROJECT_PLAN.md`** — GoDaddy piece, numbered build log, why each thing exists.
- **`homeassistant/PLAN.md`** — HA piece design doc, 16 sections, every real bug found and fixed (the deprecated node, `require()` not working in Function nodes, `/config` vs `/share`, the placeholder-token saga, InfluxDB 1.x vs 2.x). Also covers the planned-but-unbuilt features.
- **`godaddy/README.md`** / **`homeassistant/README.md`** — setup and deployment steps for each piece.

## Outstanding work, as of this handoff (chat → Claude Code)

1. **`PROMPT.md` was requested but never built.** Ward asked for a from-scratch rebuild prompt — all the concrete facts needed to rebuild the app as of the current version, deliberately *without* the history/reasoning (that's what RETROSPECTIVE.md and the PLAN docs are for). Still needs writing.
2. **`.gitignore` — not yet decided.** `godaddy/config/config.php` contains real credentials (DB password, Oura client secret, the `API_SYNC_TOKEN`). If this repo is or becomes public, that file needs excluding before it's ever committed — offered, not yet answered by Ward. Same applies to `homeassistant/lucius_secrets.json` once it exists locally (currently only `.example` is checked in). Check whether `config.php` is already in git history — if it's already been pushed, rotating the credentials matters more than just adding a `.gitignore` going forward.
3. **Marketing PDF — deliberately not pre-built.** `marketing/leeward_wardstock_flyer.html` is current and correct. The PDF should be generated **on demand, not proactively** (real token cost building it last time) — `marketing/README.md` has the exact steps and a known `wkhtmltopdf` rendering bug (`border-radius` on a `<td>` silently breaks the two-column layout) to avoid re-discovering.
4. **The two real Node-RED sync flows have never actually run.** `oura_sync_flow.json` and `godaddy_pull_flow.json` are built and the System Test flow confirms every dependency works (config, InfluxDB, Oura, GoDaddy auth+version-sync) — but neither real flow has been triggered for a genuine end-to-end run yet. This is the next real milestone, likely to surface at least one more real bug given the project's track record so far.
5. **Body Composition Import — fully planned, not built.** `homeassistant/PLAN.md` §14 has the complete design (merge rules, InfluxDB-only for rich data, one weight field pushed to GoDaddy, `node-red-contrib-officedocs` confirmed working via its own test flow). Building the real flow is the next step whenever picked back up.
6. **GoDaddy status page (§15) and weight trend chart (§16)** — both fully planned in `homeassistant/PLAN.md`, neither started.
7. **GitHub push currently failing** (`failed to push some refs`) — actively being debugged when this file was written. Check whether the remote repo was created with a README/license (causing a divergent-history conflict) versus a genuine auth issue before assuming which fix applies.

## Conventions worth knowing before touching anything

- **Versioning:** `Major.SQL.Code` — see `godaddy/README.md`. SQL number is the only one the database tracks; a code-only release needs no database step.
- **Every live Node-RED flow gets a companion test/validation flow**, kept permanently (not delete-after-use) — adopted specifically because Node-RED's package ecosystem has repeatedly proven unpredictable. See `homeassistant/PLAN.md` §2.
- **`config/` (GoDaddy) is deliberately excluded from routine re-uploads** — never overwrite it blindly when redeploying `app/`.
- **`/share`, not `/config`, for anything Node-RED needs to read/write on the HA side** — `/config` inside the Node-RED add-on's own container is a different directory than Home Assistant's real config, a genuinely confusing bug found the hard way (`homeassistant/PLAN.md`, third real bug in §2's fix log).
