# Retrospective: WardStock → Lucius

A phase-by-phase account of how this project actually evolved — what was assumed at each stage, what got built, what turned out to be wrong or missing, and how each finding changed the next phase's starting assumptions. This is a companion to `godaddy/PROJECT_PLAN.md` and `homeassistant/PLAN.md`, not a replacement — those two are the detailed decision logs (why a specific line of code exists); this is the narrative of how the whole thing got here. Individual debugging back-and-forth is deliberately compressed out — what's kept is what was assumed, what turned out to be true, and what to remember.

---

## Phase 0 — Context: therapeutic journal work (parallel, not part of the system)

Before any code existed, a separate work stream reviewed Ward's CBT/therapy journals for gap analysis alongside his therapist. Unrelated to the software described below, but it's the reason WardStock's Incidents feature is shaped the way it is — anxiety and cardiac episode tracking, not generic health logging.

## Phase 1 — WardStock: a single-user PHP app on GoDaddy

**Starting assumptions:** single user (Ward only), GoDaddy shared hosting, PHP + MySQL, deliberately no framework and minimal dependencies — a choice that held for the entire project and shaped several later decisions (e.g. no composer/PHP library for anything, preferring vanilla code even when a library would have been faster to reach for).

**Built:** Incidents (anxiety/cardiac episode logging), Daily Log (sleep/exercise/caffeine/alcohol/medication/mood), Medications (dosage history, not just current state), Therapy (sessions + recurring schedule + since-last-session report), a 7-day dashboard, JSON Export/Import.

**Conventions established here, still in force:**
- Versioning: `Major.SQL.Code` — SQL number is the only one the database itself needs to track (`db_version` stores just `Major.SQL`), so a code-only release never requires a database step.
- Zip packaging: four folders (`app/`, `config/`, `sql/`, `setup-delete-after-use/`), with `config/` deliberately excluded from routine re-uploads so a careless "upload everything" can never overwrite real credentials with a blank template.

**Oura Ring integration — the first real hard-won lessons of the project:**
- Requesting the wrong OAuth scope produces a silent 200 OK with empty data, not an error — easy to mistake for "no data exists."
- A same-day query window misses sessions that started the evening before crossing into UTC; the fix (query `date-1` to `date+1`, then filter results to the exact day) took real debugging to find and has been reused as-is in every later Oura-touching flow.
- Oura's actual OAuth scope string format doesn't match its own documentation.
- Generic "something went wrong" error messages were replaced with rich diagnostic pages (`oura_test.php`) showing raw HTTP codes and response bodies — this diagnostic-first instinct became a recurring pattern for the rest of the project.

## Phase 2 — Planning a Home Assistant migration (before it had a name)

**Starting assumption, stated directly and never revisited:** WardStock stays exactly as-is — always-reachable, simple, the thing Ward actually opens day to day — because incidents happen away from home, not just when near a home server.

**Decided:** Home Assistant becomes a background sync/analytics engine, not a replacement. Node-RED chosen as the sync engine specifically because it was new territory worth learning, not because it was the obviously easiest choice. Auth tokens live in a config file on the HA side. HA is meant to be usable as a disaster-recovery source of truth if GoDaddy's database is ever lost — a goal that later forced pulling *full* records (including free text) rather than just structured summary fields, and eventually forced including medications after that gap was found (see Phase 4).

## Phase 3 — Renamed to Lucius, restructured as two pieces

Ward asked to fold the HA plan into the same project rather than treat it separately, and to mark the change with a major version bump and a new codename. Restructured into `godaddy/` and `homeassistant/` as two clearly separated pieces of one project, each with its own README and plan document, with a short top-level README explaining why the split exists (same "always-reachable vs. background processor" reasoning from Phase 2, now made explicit as an architectural principle rather than just a planning note).

## Phase 4 — Building the GoDaddy-side API surface for HA to talk to

**Built:** three new token-authenticated endpoints (`oura_push.php`, `pull_manual_data.php`, `status.php`), a new `ha_sync_log` table logging every call with a real status-code taxonomy (not just success/fail), and — deliberately — refactored existing logic into shared functions rather than duplicating it (the merge-safe upsert, the export-record builder), so the new endpoints and the existing human-facing pages stay in sync by construction.

**Found and fixed here:** Ward asked directly where medications were being stored on the HA side, and the honest answer was "nowhere" — the disaster-recovery pull never included them. Worse, `import.php` had no handler at all for a medication record type, meaning even fixing the pull side wouldn't have been enough; a real restore attempt would have silently dropped every medication. Both fixed together. **Lesson carried forward:** "add a feature" and "confirm the whole path actually works end to end" are not the same check — this pattern (a plausible-looking feature with a silent gap somewhere in its actual round trip) recurred more than once later.

## Phase 5 — Building the Node-RED flows: a long run of real bugs found only by actually running things

This phase is the largest single source of findings in the whole project, precisely because none of it could be verified without a live HA/Node-RED instance. Each item below was a genuine, confirmed bug — not a style choice — found on a first real test, not anticipated in advance.

**Bug 1 — the `ha-entity` node was deprecated.** The original design used a generic node to set HA helper entities. Node-RED itself flagged it as deprecated on first real use. Root cause: setting a helper entity is actually a *service call* (`turn_on`, `set_value`, `set_datetime`), not a direct state write — the original design modeled the underlying mechanism wrong, not just picked an old node. Fixed with `api-call-service`, chosen specifically because it's the palette's core mechanism, not a convenience wrapper likely to be deprecated again.

**Bug 2 — `require('fs')` doesn't work in Node-RED Function nodes.** Not a version issue — Function nodes have never exposed Node's module system in a stock configuration. All file I/O (secrets, disaster-recovery archive) was rebuilt around core `file`/`file in` nodes instead, which need no special configuration at all.

**Bug 3 — `/config` is not `/config`.** The single most confusing bug of the project. Node-RED's own add-on has its own internal working directory that's also called `/config`, entirely unrelated to Home Assistant's real configuration folder (which Studio Code Server edits under the same name). Found by direct `exec`-node filesystem inspection rather than guessing — `ls -la /` from inside Node-RED's own container revealed `/share`, HA's actual purpose-built mechanism for sharing files between otherwise-isolated add-ons, which is what all secrets/archive paths were pointed at instead. **Lesson explicitly written down at the time and worth repeating here:** never trust a path or hostname across two different HA add-ons without verifying it from inside the actual container that needs it — "it works from Studio Code Server" says nothing about whether it works from Node-RED.

**Bug 4 — GoDaddy rejected a token that was actually correct.** A real, separate server-side bug: many Apache/FastCGI shared-hosting configurations strip the `Authorization` header before PHP ever sees it. Fixed with the standard `.htaccess` re-injection rule, plus defensive fallback header-reading in `check_api_token()`. This fix was necessary — but not sufficient.

**Bug 5 — the token was never actually in the secrets file.** After Bug 4's fix, the same symptom (401, "token rejected") persisted. Rather than guess at server configuration a third time, the investigation shifted to a disciplined sequence of small, disposable, delete-after-use diagnostic scripts — each answering exactly one question (does the header arrive? does the real `check_api_token()` logic pass when called directly? does Node-RED's own HTTP client succeed with a hardcoded known-good token?) — narrowing the search systematically rather than re-guessing broadly. Root cause: `lucius_secrets.json` still had the literal placeholder sentence from its own `.example` template. The config-file check had reported this field as "present" the entire time, because it only verified non-emptiness, not correctness. Fixed by tightening that check to reject known placeholder language and validate the token's exact expected shape. **This is the clearest single lesson of the whole project:** a config check that only asks "is this non-empty" will not catch "is this actually the right value" — worth remembering for any config field added later.

**Bug 6 (parallel investigation) — InfluxDB 1.x vs 2.x.** The first InfluxDB add-on installed turned out to be version 1.8.10, which uses an entirely different data model (databases + username/password, no orgs/buckets/API tokens) than the 2.x design the whole integration was built around. Confirmed directly via `/health`'s own version field rather than assumed. Rather than rebuild the integration around the older API, Ward chose to move to a real 2.x add-on instead — which took two more rounds of correction (an add-on's *code* repository is not the same as its *catalog* repository that Supervisor actually needs; a `my.home-assistant.io` redirect link doesn't work without that separate service being linked, and isn't necessary at all — the plain manual "Add-on Store → Repositories" path works directly). Real 2.x now installed and confirmed reachable.

## Phase 6 — New default methodology, adopted directly because of Phase 5

After enough real bugs were found only by running things live, Ward and Claude adopted a standing rule: **every live Node-RED flow gets a companion test/validation flow, built and kept alongside it** — not deleted after use like the earlier one-off PHP diagnostics, since Node-RED's package ecosystem has repeatedly proven unpredictable enough (an undocumented read API, a deprecated core node, a module system that was never available) that re-verifying is a recurring need, not a one-time cost.

**First real application of this rule:** `node-red-contrib-officedocs`, the library chosen to parse Ward's smart-scale `.xlsx` exports for the not-yet-built Body Composition feature. Package chosen specifically for being actively maintained (3 months old vs. ~4 years untouched for two alternatives) after that recency gap was checked directly rather than assumed. Its actual read API (`read` then `readRange`, exact field names `params.source`/`params.range`/`params.sheet`) was fully unknown going in and confirmed entirely through a real test flow: ground-truth package inspection first, then real errors correcting each wrong guess one precise field at a time, rather than broad re-guessing. Fully resolved and documented for reuse.

## Where this leaves things

- **GoDaddy side:** stable, versioned, fully built for everything decided so far (2.1.2 as of this writing).
- **HA side:** system-test-confirmed working end to end (config, InfluxDB, Oura reachability, GoDaddy reachability+auth+version-sync all green) with the real 2.x InfluxDB add-on. The two real scheduled sync flows (Oura Sync, GoDaddy Pull) are built but not yet run for real — the next real milestone.
- **Body Composition feature:** built (`homeassistant/nodered/body_comp_import_flow.json` + `godaddy/app/api/weight_push.php`, packaged as 2.1.3) — same "reviewed carefully, never run against a live stack" status as the other two sync flows, plus one open design gap: a real export's row count isn't known ahead of time, and the flow's workaround (an oversized `readRange` request) hasn't been checked against a real oversized read.
- **GoDaddy status page (§15):** built (`status.php` + `api/status_push.php` + `nodered/status_heartbeat_flow.json`, packaged as 2.2.0) — same unverified-until-run status as the other flows, plus one deliberate scope decision: the "HA" category's signal is the heartbeat flow's own execution, not a real HA `/api/config` call (would need an auth mechanism — Supervisor token or long-lived access token — this project doesn't otherwise use; flagged as an open follow-up needing Ward's choice, not silently skipped).
- **Weight trend chart (§16):** planned, not started.
