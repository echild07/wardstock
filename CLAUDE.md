# CLAUDE.md — Lucius / WardStock

Read this first. It points to the detailed docs rather than repeating them — check those before assuming something isn't already decided.

## What this is

A two-piece personal health-tracking project for Ward. **`godaddy/`** — WardStock, a PHP/MySQL app on GoDaddy shared hosting (incidents, daily log, medications, therapy, dashboard). **`homeassistant/`** — Node-RED/InfluxDB running on Home Assistant, syncing with WardStock and doing local-only analysis (nothing raw ever leaves the home server). **`marketing/`** — LeeWard-branded flyer for the whole project. Full narrative: **`RETROSPECTIVE.md`**. Current version: **2.2.7 "Lucius"**.

## Where the detail actually lives — read before making changes

- **`RETROSPECTIVE.md`** — phase-by-phase history: what was assumed, what broke, what changed. Best starting point for orientation.
- **`godaddy/PROJECT_PLAN.md`** — GoDaddy piece, numbered build log, why each thing exists.
- **`homeassistant/PLAN.md`** — HA piece design doc, 16 sections, every real bug found and fixed. Also covers planned-but-unbuilt features.
- **`homeassistant/INFLUXDB_V2_SETUP.md`** — confirmed-working InfluxDB v2 add-on setup, written from Ward's real install.
- **`godaddy/README.md`** / **`homeassistant/README.md`** — setup and deployment steps for each piece.
- **`PROMPT.md`** — from-scratch rebuild prompt, concrete current-state facts only, no history.

## Outstanding work, as of this handoff

Everything from the previous handoff (PROMPT.md, .gitignore/credential exposure, Body Composition Import, GoDaddy status page, weight deviation chart, GitHub push) has been resolved — see `RETROSPECTIVE.md` Phases 7–8 for the full account. What's genuinely still open:

1. **None of the five Node-RED flows have been run against Ward's real HA instance.** Every flow (Oura Sync, GoDaddy Pull, Body Composition Import, Status Heartbeat, System Test) is built and reviewed but unexecuted — see each flow's own tab `info` and `homeassistant/README.md`'s "What's verified vs. not" for specifics on what's most likely to need real fixing.
2. **DB password rotation in GoDaddy cPanel — Ward's own action, not something doable remotely.** The repo was briefly public with real credentials committed; they've since been rotated (APP_SECRET, API_SYNC_TOKEN) and scrubbed from git history, but the actual DB password itself still needs changing in cPanel.
3. **Favicon legibility.** Icons now use Ward's own higher-fidelity, purpose-sized versions (no resampling on our end) — crisper than the first pass, but the 32×32 favicon is still hard to read at a glance since the design itself is detailed. A dedicated simplified mark would need new artwork, if that's ever worth doing.
4. **Marketing PDF** — generated on demand only, not committed (gitignored). Regenerate via headless Edge/Chrome (`marketing/README.md` has the exact command) when actually needed.

## Conventions worth knowing before touching anything

- **Versioning:** `Major.SQL.Code` — see `godaddy/README.md`. SQL number is the only one the database tracks; a code-only release needs no database step.
- **Version bumps and git commit/push only happen when Ward explicitly says to push/release — never automatically after a change.** (Aug 2026, standing rule going forward.) Make the actual file edits as normal; leave them as uncommitted local changes otherwise. When Ward does say to push, bump `app_version.php` first (Code, or SQL if `sql/` changed this batch — see Versioning above), then commit and push. Several batches of unrelated work can accumulate locally between pushes; batch them into one coherent version bump rather than one per small change.
- **Database upgrades are one cumulative file per major version line**, not per-release fragments — `sql/upgrade_from_<major>.0.0.sql`, idempotent, safe to run from any point in that line. See `godaddy/PROJECT_PLAN.md`'s Working Conventions for the full rule (adopted directly from Ward's feedback that multiple upgrade files per release wasn't user-friendly).
- **Every live Node-RED flow gets a companion test/validation flow**, kept permanently (not delete-after-use) — adopted specifically because Node-RED's package ecosystem has repeatedly proven unpredictable. See `homeassistant/PLAN.md` §2.
- **`config/` (GoDaddy) is deliberately excluded from routine re-uploads** — never overwrite it blindly when redeploying `app/`.
- **`/share`, not `/config`, for anything Node-RED needs to read/write on the HA side** — `/config` inside the Node-RED add-on's own container is a different directory than Home Assistant's real config, a genuinely confusing bug found the hard way (`homeassistant/PLAN.md`, third real bug in §2's fix log).
- **`ForClaude/` and `ForWard/` are working drop folders, not part of the app — never git-tracked (see `.gitignore`).** Ward drops files for Claude to process into `ForClaude/`; once a file's been processed (its content extracted/used elsewhere in the repo), move it out of `ForClaude/` into `ForWard/` so `ForClaude/` stays clean for new incoming files. `ForWard/` is also where Claude puts files being handed back to Ward. Both live at the project root, sibling to `godaddy/`/`homeassistant/`.
