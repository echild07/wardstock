# WardStock ↔ Home Assistant Sync Architecture

**Status:** Exploratory / not started — planning document, separate from `PROJECT_PLAN.md` which tracks the live GoDaddy app.

---

## 0. Quick reference — confirmed deployment details

- **HA instance address:** `https://homeassistant.local`
- **InfluxDB add-on(s) tried:** `hassio-addons/addon-influxdb` (1.8.10 — confirmed via `/health`, uninstalled since — no orgs/buckets/tokens, username+password only, pure-alphabetic requirement). Moving to a 2.x add-on instead — **not yet confirmed installed/working as of this note.**
- **InfluxDB 2.x add-on repository (correct one, confirmed from the maintainer's own docs):** `https://github.com/Jays-Home-Assistant-Add-ons/repository` — NOT `.../j-addon-influxdb2` (that's the add-on's code repo, not the Supervisor-recognized catalog repo; a real mistake made and corrected live — HA add-on authors commonly split these into two separate repos, worth remembering for any future add-on repository lookup in this project). Add via: `https://my.home-assistant.io/redirect/supervisor_add_addon_repository/?repository_url=https%3A%2F%2Fgithub.com%2FJays-Home-Assistant-Add-ons%2Frepository`. Expected UI once installed: `http://homeassistant.local:8086/` (SSL off by default per the add-on's own changelog).
- **GoDaddy site:** `https://emperorschildren.net/Wardstock`

## 1. The architecture (confirmed)

**A functionality split, not a migration:**

- **GoDaddy/WardStock stays exactly as it is** — the app Ward actually opens: incident entry, Daily Log, Medications, Therapy. System of record for everything hand-entered, reachable from anywhere, unchanged. This is what keeps "incidents happen away from home" a non-problem.
- **Home Assistant (HAOS, already running with solar data in it) becomes the background sync + analytics engine and the processing/correlation engine**, running **two independent scheduled flows, not one**:
  - **Oura sync — every 4 hours, anchored to 10am** (10am, 2pm, 6pm, 10pm, 2am, 6am): pulls high-fidelity raw Oura data into InfluxDB (data WardStock's own integration deliberately never captured), then extracts and pushes the same summary fields WardStock already understands up to GoDaddy. Oura's own data doesn't change fast enough to justify more frequent polling — sleep/readiness only really updates once a day (when the app syncs after waking), so a 15-minute cadence was pure overhead for no real freshness gain. 10am as the anchor lines up with Ward's usual wake/sync time, so the first pull of the day catches the previous night's sleep promptly rather than on some arbitrary offset.
  - **GoDaddy manual-data pull — every 15 minutes**, unchanged: pulls incidents/Daily Log/Therapy back down for correlation *and* as a disaster-recovery backup (see §4). This one benefits from staying frequent, since an incident can be logged at any moment and there's real value in HA's copy staying near-real-time.

These are genuinely decoupled — different schedules, different Node-RED flows/triggers, no dependency between them.

**Why HA never needs to be reachable from outside:** every sync direction is HA-initiated (HA calls Oura's API, HA calls GoDaddy's API) — nothing needs to reach *into* the home network. Confirmed this also means the dynamic home IP (§8) isn't a problem for the sync mechanism itself, only for viewing the new status panel remotely.

## 2. Sync engine: Node-RED (confirmed)

Ward's choice, partly because it's new territory worth learning — reasonable given it's genuinely well-suited to this "fetch → transform → push on a schedule" pattern and is a first-class citizen in the HA add-on ecosystem.

**One thing worth knowing going in:** the fiddly parts of this job — Oura's OAuth token refresh, and especially the `date-1` to `date+1` query window + exact-day filtering that took real debugging to find on the WardStock side — don't have to be fought into Node-RED's visual nodes. Node-RED's **`function` node accepts real JavaScript**, so the tricky logic can live as actual code inside an otherwise visual flow (HTTP Request nodes for the calls, a function node for the token refresh and date-window logic, another for field mapping). This is a very standard real-world Node-RED pattern — visual structure, code where code is actually clearer — not a compromise.

Install via the **Node-RED add-on** (official Home Assistant add-on), plus the `node-red-contrib-home-assistant-websocket` palette, which gives Node-RED flows direct access to set HA entity states — needed for the status panel (§9).

**Standing development convention, adopted after the officedocs package investigation (§14): every live Node-RED flow gets a companion test/validation flow, built and kept alongside it, not deleted after use.** Unlike the earlier delete-after-use PHP diagnostics (auth header debugging, etc. — genuinely one-off, thrown away once answered), these test flows are permanent fixtures in `nodered/`, since Node-RED's palette ecosystem is full of packages with uncertain APIs, inconsistent documentation, and no way to verify behavior without actually running them. A test flow's job: exercise a new node/package/API in isolation, surface as much diagnostic information as possible in one run (ground-truth package inspection via `exec` where genuinely uncertain, not just a best-guess attempt), so real behavior gets confirmed *before* it's built into a live flow, and so the same tool exists to re-verify later if a package updates or starts behaving differently. `body_comp_xlsx_test.json` (§14) is the first of these.

## 3. Auth tokens: config file (confirmed)

- The new GoDaddy `API_SYNC_TOKEN` (§5) and HA's own Oura OAuth tokens (access + refresh) live in a **local config file on the HA side**, not in InfluxDB and not hardcoded inside Node-RED flow nodes. Practically: `/share/lucius_secrets.json` — **`/share`, not `/config`** (see the real gotcha documented further down: several HA add-ons, Node-RED included, have their own internal working directory that's also confusingly called `/config`, unrelated to HA's actual config folder) — read by a `file in` node at the start of the flow.
- **Keep secrets out of the flow JSON itself** — Node-RED flows can get exported/shared/backed-up as a unit, and anything hardcoded into a node ships with that export. Referencing an external config file keeps the actual secrets out of anything that might get copied around.

**Clarifying a real point of confusion (Ward asked "there was supposed to be one" config):** there are genuinely **two separate files** — `godaddy/config/config.php` and `homeassistant/lucius_secrets.json` — not one shared config. This was the actual design from the start (this section always described "a local config file on the HA side," implicitly separate from GoDaddy's own), but it was never explicitly flagged as a two-vs-one decision point, which is a fair thing to be confused by. Breaking it down:

| | Lives in `config.php` | Lives in `lucius_secrets.json` | Overlap? |
|---|---|---|---|
| DB credentials, `APP_SECRET` | ✅ | — | GoDaddy-only |
| `API_SYNC_TOKEN` | ✅ (defines it) | ✅ (must match exactly) | **Duplicated, must stay in sync manually** |
| `OURA_CLIENT_ID`/`SECRET` | ✅ | ✅ | **Duplicated** (same registered Oura app, same values) |
| Oura access/refresh tokens | ✅ (GoDaddy's own session) | ✅ (HA's own, independent session) | Not duplicates — legitimately different values, each side's own OAuth session |
| InfluxDB credentials | — | ✅ | HA-only |

**Why two files, not one, is the actual recommendation — this is a real security-boundary choice, not an accident:** GoDaddy and HA are different trust domains (a shared PHP host vs. a home Pi). Merging them into one shared config would mean either side could read the other's full credential set, which is a larger blast radius if either one is ever compromised — and there's no clean way to share a live config between them without one side fetching secrets from the other over the network, which creates its own chicken-and-egg problem (fetching credentials requires a credential to authenticate that fetch). **This needs an explicit decision from Ward, not an assumption:** keep the current two-file design (my recommendation, for the security-separation reason above — the only real cost is manually keeping `API_SYNC_TOKEN` and the Oura client credentials in sync between the two files when either changes), or consolidate to one shared source despite that tradeoff.

## 4. What data moves, and the disaster-recovery decision

**HA → GoDaddy (push, every 4 hours, same run as the Oura pull):** same summary fields WardStock already maps from Oura (sleep duration/efficiency, resting HR, HRV, steps). No change in shape on the GoDaddy/MySQL side. Rides the Oura schedule rather than having its own timer, since there's nothing new to push until a fresh Oura pull has happened anyway.

**GoDaddy → HA (pull, every 15 min):** **incidents, Daily Log, and Therapy sessions — full records, not a trimmed structured-only subset.**

This is a change from the first draft of this plan, driven by Ward's stated goal: **"we want Home Assistant to be the source of truth in case of issues"** — meaning if GoDaddy's database were ever lost or corrupted, HA's copy should be complete enough to actually restore from, not just a lightweight shadow for correlation.

**How to get both disaster-recovery completeness AND correlation-friendly data, cleanly, reusing what already exists:**
- The pull endpoint (§5) returns full records — same shape as the existing human-facing `export.php` (including free-text fields: `trigger_context`, `thoughts_before`, `what_helped_recovery`, `free_notes`, therapy `summary`/`insights`/`homework`, etc.).
- Node-RED does two things with that same response:
  1. **Extracts the structured/numeric fields** (category, severities, intensity, timestamps, mood/state-of-mind, caffeine/alcohol amounts) and writes them into InfluxDB — this is the correlation-friendly copy. InfluxDB isn't built to be a good home for large free-text fields, so it doesn't need to hold them.
  2. **Archives the full raw JSON response** to local storage on the HA box (a rolling set of dated files, or overwritten each run — worth deciding a retention approach) — this is the actual disaster-recovery artifact. If GoDaddy's database is ever lost, this file is directly restorable through WardStock's **existing, already-debugged `import.php`** (same merge-safe logic already built for exactly this purpose) — no new restore mechanism needs to be built at all, just reuse of what's already there.

This gets Ward's stated goal without inventing a second, untested backup/restore system — the restore path is the same Import feature that already exists and works.

**Real gap found and fixed:** the pull originally only requested `incidents`, `daily_logs`, `therapy_sessions` — **medications were never included**, meaning HA had no backup of dosage history at all despite this section's stated goal. Only medication *names* embedded inside daily_log records came along. Found when Ward asked directly where medications were being stored on the HA side. Fixed:
- `build_export_records()` (`db.php`) gained a `medications` type — deliberately kept out of what `export.php` itself ever requests (the human-facing export still intentionally excludes medication history, a much older decision — see `PROJECT_PLAN.md`), but `api/pull_manual_data.php` now requests it specifically, since disaster-recovery completeness and a casual human export have different requirements. Medications have no `updated_at` column, so unlike the other types this one ignores the "since" scope and always pulls the complete table — small table, and a since-filter based on `start_date`/`end_date` would silently miss real changes like a dosage typo correction that doesn't move either date.
- `import.php` had **no handler at all** for a `medication` record type — would have silently dropped them on any actual restore attempt, defeating the entire point. Added, matched by `(name, start_date)` as the natural key for one dosage era of one medication (unlike incidents, which have no reliable natural key and always insert as new).
- `godaddy_pull_flow.json`'s InfluxDB-write function updated to also write a structured `medications` measurement (timestamped at `start_date`, tagged by name and `med_type`).

## 5. New API surface on GoDaddy

Token-authenticated (`Authorization: Bearer <API_SYNC_TOKEN>`, new constant in `config.php`, separate from the human login):

- **`api/oura_push.php`** — HA POSTs summary fields per date; server reuses the *same* merge-safe upsert `oura_sync.php`'s manual pull already uses (one shared function, not a second implementation).
- **`api/pull_manual_data.php`** — returns full incidents + Daily Log + Therapy Sessions, "all" or "since last HA pull" (own bookkeeping key, `app_settings.last_ha_pull_at`, kept separate from the human Export page's `last_export_at`).
- **`api/status.php`** — lightweight health check for the new HA panel (§9): app version, db version sync status (reuses `debug.php`'s existing logic), current server time. Gives the panel something concrete to show beyond "did my last sync succeed."

Every one of these three endpoints also writes to the new `ha_sync_log` table on every call (§6) — logging isn't a separate feature bolted on, it's part of what each endpoint does on every invocation, success or failure.

GoDaddy's existing manual Oura pull (`oura_connect.php`/`oura.php`/`oura_sync.php`) stays as a fallback — no conflict, since both paths write through the same merge-safe upsert.

## 6. Observability: job-run logging on both sides

Two independent logs, deliberately redundant — this is what makes an *asymmetric* failure (one side thinks it worked, the other doesn't) actually diagnosable instead of a mystery. Two concrete scenarios this catches that a single-sided log wouldn't: Node-RED believes it successfully pushed data, but GoDaddy's log shows the request never arrived (dropped after HA thought it was sent) — only the GoDaddy side reveals that. Or the reverse: GoDaddy's log shows a clean, successful, fully-processed request — but HA's own job log shows an error reading the *response*, so HA might retry or alert on something that actually landed fine. Neither log alone tells the whole story.

### HA side: InfluxDB job-run log

Every run of either flow (Oura sync, GoDaddy pull) writes to its own InfluxDB measurement, separate from the actual synced data — e.g. `sync_job_runs`. **Two points per run, not one** — Ward asked for "it ran, it finished, or it failed" as distinct states, which needs a start marker as well as a completion marker:
- A `started` point when the flow begins.
- A `success` or `failed` point when it ends.

This makes "started but never finished" (a hang, a mid-flow crash) visible as its own distinct pattern — a start with no matching completion is itself a meaningful signal, not just "we don't know."

**Schema:** tags `job_name` (`oura_sync` | `godaddy_pull`), `status` (`started` | `success` | `failed`); fields `failure_code` (only on `failed` points), `detail` (optional message), `duration_ms` (on completion points).

**Failure code taxonomy — HA side** (what can go wrong calling *out*):
- `oura_auth_failed` — token refresh/auth rejected
- `oura_api_error` — Oura responded, but with an error or unexpected shape
- `oura_network_error` — couldn't reach Oura at all
- `godaddy_auth_failed` — GoDaddy rejected the `API_SYNC_TOKEN`
- `godaddy_unreachable` — network error reaching GoDaddy
- `godaddy_error_response` — GoDaddy responded with an error
- `unknown_error` — catch-all; still logged with whatever detail is available, never silently swallowed

### GoDaddy side: a request log for every incoming HA call

New MySQL table, `ha_sync_log` — every one of the three endpoints in §5 writes a row on **every** call it receives, success or failure, no exceptions:

```
ha_sync_log — id, endpoint (oura_push / pull_manual_data / status),
              called_at, status_code, detail (nullable text)
```

**Status code taxonomy — GoDaddy side** (what can go wrong receiving a call):
- `success`
- `auth_invalid` — missing/wrong `API_SYNC_TOKEN`
- `malformed_request` — bad JSON or missing required fields
- `validation_error` — well-formed but semantically wrong (bad date, references a medication name that doesn't exist, etc.)
- `db_error` — the database operation itself failed
- `unknown_error` — catch-all

## 7. Where this shows up on GoDaddy — checkable from anywhere

The actual point of building the GoDaddy-side log, not just the HA one: **GoDaddy is always reachable; HA's panel isn't** (§8). `ha_sync_log` becomes a way to check "is HA actually syncing me" from wherever Ward happens to be, not just from home.

- **`oura_sync.php`** (the everyday page) — a compact "HA Sync Status" box: most recent `ha_sync_log` row per endpoint, timestamp + success/fail + status code if it failed. At-a-glance answer to "is this working."
- **`oura_test.php`** (the deep-diagnostic page) — a fuller history, last ~50 `ha_sync_log` rows across all three endpoints, for spotting a pattern rather than just the latest result.

Deliberately the GoDaddy-side mirror of the HA status panel (§9) — not a replacement for it, a second, always-reachable place to check the same underlying question.

## 8. Dynamic IP — addressed

Ward's home IP (and HA's effective address from the internet's perspective) is dynamic. Two genuinely different situations:

- **The sync itself: not a problem.** Every call in this design is outbound from HA (to Oura, to GoDaddy). Outbound connections don't care what the local public IP is — that only matters for something trying to reach *in*. This confirms the earlier decision to skip IP-restricting the new GoDaddy endpoints in favor of token-only auth — IP allowlisting would require Dynamic DNS to even be practical, and isn't worth the added complexity for what token auth already covers.
- **Viewing the new HA status panel (§9) from away from home: a real, separate problem** — mitigated, not solved, by §7's GoDaddy-side mirror. HA's own dashboards live on the home network by default. If Ward wants the *HA-native* panel specifically while out, that needs **Nabu Casa remote access** (HA's own official paid service — simplest, handles the dynamic-IP problem for you), **Tailscale** on his phone (free, more setup, same zero-inbound-exposure property already good for this design), or accepting that panel specifically is home-network-only (fine, now that §7 gives an always-reachable alternative view of the same information).

## 9. New HA panel: GoDaddy connection status (confirmed, for debugging)

After each sync attempt, the Node-RED flow should update a small set of HA helper entities (via the HA WebSocket nodes) — mirroring the same pattern already built into WardStock's own Oura integration (`last_success_at`/`last_attempt_at`/`last_attempt_ok`), since that pattern already proved useful for exactly this kind of "silently broken vs. just hasn't run" question. Now tracked **separately per flow**, since they run on different schedules (§1) and either can fail independently of the other:

- **Oura flow (every 4h):** last successful Oura pull, last successful push to GoDaddy, last attempt of each (success/fail), last error message.
- **GoDaddy pull flow (every 15min):** last successful pull, last attempt (success/fail), last error message.
- **GoDaddy reachability** (from polling `api/status.php`) — shared by both, no need to duplicate.

**Known gap in the actual built flows (`homeassistant/nodered/`), partially addressed:** neither *scheduled* flow calls `api/status.php` — reachability during a real sync is still only implicitly inferred from whether `oura_push.php`/`pull_manual_data.php` succeeded. The new **`system_test_flow.json`** (manual diagnostic, not scheduled) does call `status.php` and checks version-sync explicitly — but that's an on-demand health check, not something that runs automatically during the 4-hour/15-minute sync cycles. Still worth adding a dedicated status poll to one of the scheduled flows as a follow-up if catching version-drift *without* having to manually run the test flow matters.

**Fixed after Ward's first real test run:** both flows originally set helper entities via a generic `ha-entity` node, which turned out to be deprecated in the installed `node-red-contrib-home-assistant-websocket` version (Node-RED flagged it directly). Setting a helper entity's value is actually a *service call* (`input_boolean.turn_on`/`turn_off`, `input_text.set_value`, `input_datetime.set_datetime`), not a direct state write — the original design modeled this wrong. Fixed by building proper `{domain, service, target, data}` objects and feeding them into `api-call-service`, the palette's core (non-deprecated) service-calling node, rather than guessing at whatever the newer per-domain node names are in a given installed version. Same fix applied identically to both flows.

**Second real bug found on the first test run, in `system_test_flow.json` (§10) — `ReferenceError: require is not defined`.** All three flows originally read/wrote the secrets file and the disaster-recovery archive using `require('fs')` inside Function-node code. This isn't version drift like the `ha-entity` issue — a stock Node-RED Function node runs in a sandboxed context that has never exposed `require()` by default, in any version, without deliberate extra configuration. Fixed by removing all filesystem access from function-node code entirely and using Node-RED's core `file`/`file in` nodes instead (zero special configuration needed — this is the standard, portable way to do file I/O in Node-RED): a `file in` node reads the secrets file at the start of each flow and hands the raw text to a function node that just does `JSON.parse()`; the Oura flow's token-refresh save and the GoDaddy flow's archive write both now go through a `file` (write) node the same way. The archive-write node's `createDir` option replaces the old `fs.mkdirSync` call for creating the archive folder on first run. Applied identically across all three flows, since all three had the same bug.

**Third real bug, found immediately after fixing the second one, on the very next test run: `ENOENT`, even though Ward could see the file right there via Studio Code Server.** Root cause, confirmed step by step through Ward running `exec`-node directory listings rather than guessed at: every HA add-on runs in its own isolated container, and the **Node-RED add-on has its own internal working directory that's also called `/config`** — completely different from Home Assistant's real config folder that Studio Code Server edits. `ls -la /config` from inside Node-RED showed only Node-RED's own files (`flows.json`, `node_modules`, etc.) — no trace of the secrets file. `ls -la /` from the same container revealed `/homeassistant` (HA's real config — confirmed by finding `configuration.yaml` and the secrets file there) and `/share` (HA's official mechanism for sharing files between otherwise-isolated add-ons). **Settled on `/share` over `/homeassistant`** — the file genuinely was reachable at `/homeassistant/lucius_secrets.json` and that would have worked, but `/share` is the architecturally cleaner choice: `/homeassistant` is HA's own live, actively-managed configuration directory, and dropping app-specific secrets/archive data into it conflates "Home Assistant's own config" with "this project's data," where `/share` exists specifically for this purpose. Every `file`/`file in` node across all three flows now points at `/share/lucius_secrets.json` / `/share/lucius_archive/latest_manual_data.json`. Whether HA's own automatic backups cover `/share` the same way they typically cover `/config` was never confirmed — still an open question (§12).

**Fourth real bug — the longest investigation of the four, and the one with the most valuable process lesson. CONFIRMED RESOLVED.** The GoDaddy leg of the system test kept failing with `401 token rejected` across many rounds, even after two genuine server-side fixes (the `.htaccess` Authorization-header re-injection and `check_api_token()` hardening — see `../godaddy/PROJECT_PLAN.md` item 56). The investigation proceeded in disciplined layers rather than guessing at server config a third time:
1. Ward uploaded his actual live `config.php` — confirmed the server-side token was correct, ruling out a config mismatch on that end.
2. A temporary diagnostic script (`debug_auth_header.php`, uploaded and deleted after use, same pattern as `setup.php`) confirmed via direct `curl` that the `Authorization` header **was** reaching PHP correctly.
3. A second round of that same script, testing the exact `check_api_token()` logic step-by-step, confirmed the regex match and `hash_equals()` comparison both succeeded — via curl.
4. A location-isolation test (identical diagnostic placed inside `api/` specifically) ruled out a subfolder-specific `.htaccess` inheritance issue.
5. A third diagnostic, byte-identical to the real `status.php` except for its failure-reporting, confirmed the *real* `check_api_token()` — not a reimplementation — passed under curl.
6. At this point every curl-based test had passed. The one remaining untested variable was Node-RED's own HTTP client specifically. A minimal, fully isolated flow (four nodes, everything hardcoded, nothing shared with the real System Test flow) was built to remove all ambiguity about *which* node's configuration was actually in play — and it succeeded (`200 OK`, real app data back), proving conclusively that the server, the fix, and Node-RED's HTTP client were all fine.
7. That left exactly one variable: the token as actually read from `/share/lucius_secrets.json`, versus a hardcoded known-good value. A follow-up minimal flow read the real file and compared its token value directly.
8. **Root cause:** `godaddy_api_sync_token` in the secrets file was still the literal placeholder sentence from `lucius_secrets.json.example` — *"PASTE THE VALUE OF API_SYNC_TOKEN FROM godaddy/config/config.php HERE - MUST MATCH EXACTLY"* — never actually replaced with the real token. The system test's own config check had reported this key as present the entire time, because it only verified non-emptiness, and placeholder text is technically non-empty.

**Fixed:** `system_test_flow.json`'s config check now also rejects known placeholder language (`PASTE`, `YOUR `, ` HERE`, `EXAMPLE`, etc.) in any required field, and specifically validates `godaddy_api_sync_token` against the real expected format (exactly 64 hex characters) rather than just checking it's non-empty. This is the kind of validation that should have caught this on the very first run — worth remembering for any future secrets/config field added to this project: validate *shape*, not just *presence*.

**The methodology, not just the fix, is worth keeping in mind for next time:** every step used a real, disposable, delete-after-use diagnostic to answer one specific question, narrowing the search systematically rather than re-guessing at server configuration repeatedly. All temporary diagnostic files (three PHP scripts, two throwaway Node-RED flows) were deleted once the root cause was confirmed — none of them are part of the permanent project.

**Confirmed by Ward: all five system test checks pass.** Config file, generic internet, InfluxDB, Oura reachability, and GoDaddy reachability + auth + version-sync all green. This is a real milestone, not just "the bug is fixed" — every external dependency this project relies on (the secrets file, InfluxDB, Oura's API, and GoDaddy's new API surface with a correctly-authenticating token) is now proven reachable and correctly configured, end to end, from the actual HA box. Nothing about the two real scheduled sync flows has been tested yet, though — that's genuinely the next step (§13), not something this result covers.

**Documentation gap found and fixed:** the setup steps in `README.md` have Node-RED flow import (step 6) before HA helper-entity creation (step 7) — meaning both `api-call-service` nodes will show a Node-RED warning (targeting entities that don't exist yet) for anyone following the steps in order, exactly as intended. This is harmless and self-resolves once step 7 is done, but was never called out, so it read like something had gone wrong. Added an explicit "expect this warning, here's why, it's fine" note right at step 6 rather than reordering the steps — reordering would mean creating HA helper entities before the flows that reference them exist either, which isn't obviously better and wasn't worth restructuring over.

**`influxdb_url` confirmed via direct test, not guessed — two options tested, one chosen deliberately.** `lucius_secrets.json.example`'s original placeholder was a speculative container-hostname pattern, explicitly guessed since it couldn't be verified from the build environment. Given the `/share` vs `/config` lesson earlier — some HA paths/hosts genuinely are isolated per-container, others aren't — this was tested rather than assumed. Two candidates both returned `200` via `curl .../health` from Node-RED's `exec` node: `http://localhost:8086` (works because the InfluxDB add-on happens to run in host-networking mode) and `http://a0d7b954-influxdb:8086` (the actual Supervisor-managed Docker hostname for Ward's specific InfluxDB add-on instance). **Chose the hostname over `localhost`** despite both working — the hostname is the standard, documented mechanism for inter-add-on communication and doesn't depend on host-networking mode staying enabled, where `localhost` would silently break if that ever changed. Updated the example file accordingly, with both values noted in its comment for reference.

Worth building the dashboard card with the two different cadences in mind — a 3-hour-old "last Oura sync" timestamp is normal and expected, not a warning sign, the way a 3-hour-old "last GoDaddy pull" would be.

Displayed via a simple Lovelace entities/glance card — no need for anything fancier than what WardStock's own `debug.php`/`oura_test.php` already do for the same purpose on the GoDaddy side.

## 10. System test flow (new — manual diagnostic)

`system_test_flow.json` — a third Node-RED flow, separate from the two scheduled sync flows, triggered manually (click to run, not scheduled). Checks in sequence:
1. The secrets file (§3) loads and every required key is present and non-empty.
2. Generic internet reachability (a lightweight captive-portal-check endpoint, not tied to any real content).
3. InfluxDB reachability (`/health` endpoint).
4. Oura's API reachability — deliberately sent with **no** Authorization header, proving the network/DNS/TLS path without needing a real token. Originally expected exactly HTTP 401; **real testing found Oura actually returns 400**, so the check no longer guesses at a specific status code — any real numeric response now counts as reachable, only a genuine connection failure fails this check.
5. GoDaddy's `api/status.php` — with the real token, so this checks reachability, auth, and version-sync all in one call. **Real bug found running this live:** a provably-correct token (confirmed by comparing Ward's actual live `config.php` against the generated value) was still rejected — root cause was the `Authorization` header never reaching PHP at all, a well-documented Apache/FastCGI shared-hosting quirk unrelated to WardStock's own code. Fixed on the GoDaddy side (`.htaccess` header re-injection + `check_api_token()` hardened with multiple fallback sources) — see `../godaddy/PROJECT_PLAN.md` item 56 for the full writeup.

Outputs one readable pass/fail summary to the debug sidebar, each failure with a specific, actionable reason (e.g. "token rejected — check `godaddy_api_sync_token` matches `config.php`'s `API_SYNC_TOKEN` exactly") rather than a bare failed/passed. Built after Ward asked for exactly this — "validate the config file, the ability to reach InfluxDB, generically reach the web and Oura and the GoDaddy" — matches that request directly, check for check.

## 11. Incident correlation: confirmed to live in HA

Ward confirmed HA/InfluxDB is "our processing engine" for this. **Cross-reference note for `PROJECT_PLAN.md`:** its Future Features section currently lists caffeine/alcohol/incident correlation as a possible WardStock/PHP feature — that entry should be updated to point here instead, so the two planning documents don't end up tracking the same feature in two places with two different assumed implementations.

## 12. Remaining open questions

- Local archive retention for the disaster-recovery JSON dumps (§4) — keep every pull, or just the most recent N?
- Retention for `ha_sync_log` (§6/§7) and the InfluxDB `sync_job_runs` measurement — logs like this grow forever if nothing ever prunes them; worth a deliberate cap (e.g. keep 90 days, or last N rows) rather than letting either grow unbounded.
- Whether HA's own automatic backups cover `/share` (where the secrets file and disaster-recovery archive now live) the same way they typically cover `/config` — not confirmed. Matters most for the archive file specifically, since extra backup coverage is a real feature for a disaster-recovery artifact, not just incidental.
- Nabu Casa vs. Tailscale vs. home-only, for viewing the *HA-native* status panel remotely (§8) — no wrong answer, just needs picking. Lower stakes now that §7 gives an always-reachable alternative.
- Exact HA helper entities/naming for the status panel (§9) — small detail, easy to settle when actually building it.

## 13. Suggested build order

1. Build the three new GoDaddy API endpoints (`oura_push.php`, `pull_manual_data.php`, `status.php`) + `API_SYNC_TOKEN` config **+ the `ha_sync_log` table and logging call in each endpoint from the start**, not bolted on after. Test with curl before Node-RED is involved at all — including deliberately wrong tokens/malformed bodies, to confirm the failure-code logging actually produces the right codes.
2. Add the `ha_sync_log` display to `oura_sync.php` and `oura_test.php` (§7) — can be verified entirely with the curl tests from step 1, before any HA-side code exists.
3. Set up the InfluxDB add-on and Node-RED add-on (with the HA WebSocket palette) on HAOS. Import and run the **System Test flow** (§10) before building anything else on the HA side — it confirms config, InfluxDB, Oura, and GoDaddy are all reachable before there's a real sync flow to debug on top of that.
4. Build the **Oura flow** first (its own 4-hour trigger, anchored 10am): Oura pull → InfluxDB high-fidelity write → GoDaddy summary push → `sync_job_runs` start/completion points. Confirm it's working end-to-end, including a deliberately-forced failure (e.g. temporarily wrong token) to confirm the failure-code logging actually fires correctly, before touching the second flow.
5. Build the **GoDaddy pull flow** separately (its own 15-minute trigger): pull manual data (now including medications — see §4) → InfluxDB structured write + local JSON archive → `sync_job_runs` logging.
6. Add the HA-side status-panel entities and Lovelace card (§9) once both flows have real data flowing — remember they're two independent timestamps, not one.
7. Grafana/correlation analytics come after data has had a few days to accumulate — not before.

## 14. Body Composition Import (built — `nodered/body_comp_import_flow.json` + `godaddy/app/api/weight_push.php` — not yet run against a live stack, see `homeassistant/README.md`'s "What's verified vs. not")

Ward's smart scale exports a `.xlsx` with far more than weight — 17 columns per reading, multiple readings possible per day, one specific device. This section covers pulling that data in, decided over several rounds of Q&A with Ward rather than assumed.

### What the scale actually exports (confirmed from a real sample file)

| Column | Kept? | Destination |
|---|---|---|
| Measure Time | yes | becomes the point timestamp |
| Weight(lb) | yes | InfluxDB **and** GoDaddy (`daily_logs.weight`) |
| Body Fat(%), BMI, Skeletal Muscle(%), Muscle Mass(lb), Protein(%), BMR(kcal), Fat-free Body Weight(lb), Subcutaneous Fat(%), Visceral Fat, Body Water(%), Bone Mass(lb), Metabolic Age | yes | InfluxDB only |
| Body Type | **dropped entirely** | nowhere — Ward's call: "their analytics, not what we will build" |
| Device MAC Address, Device Name | yes | InfluxDB tags |

### The core architectural decision: almost nothing touches GoDaddy

Ward was explicit: the rich metrics are for his own analysis, not part of what the app is for. **No new SQL table, no schema/version bump on the GoDaddy side at all.** The only GoDaddy-side change is a new way to fill in the *existing* `daily_logs.weight` field. Everything else — all 13 numeric metrics, every reading — lives in InfluxDB only.

### Two merge rules, both apply before anything gets written anywhere

1. **Collapse to one reading per calendar day, keeping the latest.** The 40-row sample file had two readings 22 seconds apart on one day — confirmed by Ward as testing artifacts, not real double-measurements. Group by date, discard all but the latest-timestamped row per day. This single step feeds both destinations.
2. **GoDaddy's weight push never overwrites an existing value.** Only sets `daily_logs.weight` for a date if it's currently `NULL`. This does three things at once: respects a manual entry made before the scale data arrives, respects a manual correction made after, and makes re-importing the same historical data safe by default (exports will routinely contain rows Ward already has, given the scale app's poor date-range picker) — a day that already has a weight, from any source, is always a no-op on reimport.

### New GoDaddy endpoint: `api/weight_push.php`

Token-authenticated, same pattern as the existing three endpoints. Accepts `{date, weight_lb}`, applies Rule 2, logs to `ha_sync_log` (endpoint name `weight_push`, same status-code taxonomy as the others). No SQL migration needed — `daily_logs.weight` already exists.

### New InfluxDB measurement: `body_composition`

One point per day (after Rule 1), same write mechanism already proven working for the other two flows (`/api/v2/write`, `Authorization: Token`). Tags: `device_mac`, `device_name`. Fields: `weight_lb`, `body_fat_pct`, `bmi`, `skeletal_muscle_pct`, `muscle_mass_lb`, `protein_pct`, `bmr_kcal`, `fat_free_body_weight_lb`, `subcutaneous_fat_pct`, `visceral_fat`, `body_water_pct`, `bone_mass_lb`, `metabolic_age`.

### Import mechanism: Option B, HA-watched directory (decided over A/B/C — see conversation)

- Ward exports manually from the scale's app (no scheduled-email support confirmed — checked directly, it doesn't exist) — roughly weekly, sometimes daily.
- Drop location: `/share/lucius_body_comp_import/` (same `/share` already proven correct for everything else in this project).
- **New Node-RED flow, "Lucius - Body Composition Import"** — its own schedule, daily around **noon** (gives that day's weigh-in time to happen first; matches Ward's own export cadence).
- Since core Node-RED has no built-in directory-listing node, use the already-proven `exec` node (`ls /share/lucius_body_comp_import/*.xlsx`) to find files present, rather than add yet another dependency for something this simple.
- Parse each file with **`node-red-contrib-officedocs`** (installed by Ward) — chosen over two older, read-capable alternatives (`node-red-contrib-xlsx-to-json`, `@martip/node-red-xlsx`) specifically because of maintenance recency (3 months vs. ~4 years untouched on both alternatives) and because it's built on ExcelJS rather than the older, less actively-maintained "xlsx"/SheetJS library several competing packages wrap. Exact node wiring/config for this package not yet worked out — first real thing to figure out when actually building this flow, likely by examining its own in-palette documentation/examples directly.
- Apply both merge rules, write to InfluxDB, push each day's weight to `api/weight_push.php`.
- **Move the processed file into a `processed/` subfolder afterward** (Ward's request) — keeps the watched directory from accumulating already-handled files, even though reprocessing would be harmless given both merge rules are idempotent.
- **Full historical backfill on first run, into both destinations** — not future-only. The very first run should process the entire sample file's history (back to 7/6) into InfluxDB, and push weight for every one of those days to GoDaddy.
- Same observability pattern as the other two flows: `sync_job_runs` start/success/failure points, plus new HA status entities (`lucius_bodycomp_last_success`, `lucius_bodycomp_last_attempt`, `lucius_bodycomp_last_attempt_ok`, `lucius_bodycomp_last_error`) and a corresponding `ha_sync_log`-visible row from the new endpoint, same as everything else already built.

### Deliberately not addressed by this design

- **Not added to `export.php`'s selectable types, not part of the human-facing Export page.** Consistent with Ward's own framing (analysis data, not app-purpose data) and the existing precedent set by `medications` (pulled by HA, never human-exportable).
- **Not part of the GoDaddy disaster-recovery pull (`pull_manual_data.php`).** There's nothing new for it to pull — the rich data never lands on GoDaddy in the first place, so InfluxDB's own backup story (whatever that ends up being — see §12's open question about `/share` backup coverage) is what actually protects this data, not the existing GoDaddy-pull mechanism.

### Officedocs read API — confirmed working, resolved

`nodered/body_comp_xlsx_test.json` confirmed the real API over several precise rounds (ground-truth package inspection, then real errors correcting each wrong guess — not broad re-guessing):

- Node type: `exceljs` (the only Excel-related type this package registers; `docx`/`pptx` are separate, unused types from the same package).
- Load a workbook: operation `read`, `msg.params = { source: '<file path string>' }` — a plain path works, no need to read the file into a buffer first.
- Read a range: operation `readRange`, `msg.params = { range: 'A1:Q41', sheet: 'Sheet1' }` — **field is `sheet`, not `sheetName`** (the initial guess, corrected by a real error). Needs `msg._doc` (the loaded workbook, carried forward automatically from the `read` node's output) present and untouched.
- **Output shape:** array of arrays — first row is the header labels, each following row is one data row, values in the same left-to-right column order as the source file.
- **Every value comes back as a string**, including numeric columns (`"233"`, `"36.9"`) — the real import flow needs explicit `parseFloat`/`parseInt`, nothing arrives pre-typed.
- **One debugging gotcha worth remembering for any future flow that touches `msg._doc`:** never wire a debug node (or anything else) to display the *whole* message once `msg._doc` is present — the live ExcelJS workbook object has circular references by design (cells reference their parent worksheet, which references the workbook), which crashes Node-RED's own `JSON.stringify`-based debug display. Always narrow display to `msg.payload` specifically, never the full message, once `msg._doc` exists.

This fully unblocks building the real Body Composition Import flow — no remaining unknowns about the parsing library itself.

### Built — design choices made that weren't already decided above

- **Row count is unknown ahead of time**, and `readRange` needs an explicit range — the real flow requests an oversized one (`A1:Q5000`) and filters out trailing empty rows itself, rather than reading the file twice (once to find the row count, once for the real range). Not verified against a real oversized-range read — worth confirming on first real test whether officedocs errors on an out-of-bounds range or just returns blank cells past the actual data.
- **Per-day weight pushes to `api/weight_push.php` use Node-RED's core `split`/`join` nodes**, one HTTP call per collapsed day rather than one batch call (no batch endpoint exists — `weight_push.php` deliberately mirrors the existing single-item endpoints). The secrets object and job-start timestamp are carried across that loop via flow context (`flow.set`/`flow.get`), not msg passthrough — `join`'s handling of non-payload message properties across a group isn't reliably documented, so this sidesteps trusting it.
- **`api/weight_push.php` reuses the existing endpoint pattern exactly** (token auth, `ha_sync_log` logging, same status-code taxonomy) — no new taxonomy needed. `push_weight_if_unset()` in `db.php` is the one place Rule 2 (never overwrite) is actually enforced.
- **No SQL migration** — `daily_logs.weight` already existed, confirming §14's original "almost nothing touches GoDaddy" framing held all the way through the real build.

## 15. GoDaddy status page — HA / Node-RED / Analytics (built — `godaddy/app/status.php` + `api/status_push.php` + `nodered/status_heartbeat_flow.json` — not yet run against a live stack)

Since GoDaddy is the only always-reachable, always-open UI in this whole project (established back in §7/§8's reasoning), Ward wants a single status page there showing enough to know whether he needs to log into HA/Node-RED at all — not full diagnostics, just "is something wrong, and where."

### Layout: three categories, matching how the system is actually organized

- **HA** — is the Home Assistant instance itself alive and reachable. Since Node-RED runs *inside* HA, there's no separate way to check "is HA up" independent of Node-RED being able to report in at all — a missing/stale push under this category **is** the signal, the same way a stale `last_attempt` already works elsewhere in this project. Worth also having the reporting flow (below) query HA's own `/api/config` for version/uptime, giving this category something more than just a heartbeat.
- **Node-RED** — one row per flow (Oura Sync, GoDaddy Pull, Body Composition Import, System Test): last run time, succeeded/failed, and — new, not something any current flow computes — **whether it's overdue**, i.e. hasn't run when it should have (Oura Sync is overdue past ~4.5 hours since last run, GoDaddy Pull past ~20 minutes, etc.). A failed run and a *missing* run are different problems and should look different on this page.
- **Analytics** — placeholder only for now. Nothing to report until the Grafana/correlation-analysis phase (§11) actually exists; the category exists in the design so adding it later doesn't mean restructuring the page.

### How the data actually gets there: a new, fifth Node-RED flow — "Lucius - Status Heartbeat"

Deliberately **not** each sync flow pushing its own status inline — a dedicated flow on its own schedule (every 15–30 min, exact interval TBD) that:
1. Reads the existing HA helper entities each sync flow already maintains (`lucius_oura_last_success`, `lucius_godaddy_last_attempt_ok`, etc. — already built, §9) plus whatever Body Composition Import's own equivalents end up being (§14).
2. Computes overdue status per flow, comparing each one's last-run time against its own known schedule.
3. Queries HA's own `/api/config` for the HA-category data.
4. Pushes one consolidated snapshot to a new GoDaddy endpoint.
5. **Also writes the same snapshot to InfluxDB** (Ward's explicit request — "kept in the influxdb so we can look for trends") — a new measurement, distinct from `sync_job_runs` (§6), since this tracks periodic whole-system health snapshots, not individual job outcomes. Enables later analysis like "how often has Oura Sync actually been failing this month," something `sync_job_runs` alone doesn't answer cleanly since it's an event log, not a status-over-time view.

Reusing the existing HA helper entities rather than having every flow separately learn to push status keeps this cleanly separated: sync flows do sync work and update their own entities (unchanged); exactly one flow's job is aggregating and reporting.

### New GoDaddy pieces

- **New table**, e.g. `system_status_reports` — one row per (category, component), upserted on every push: `last_run_at`, `last_status`, `last_error`, `expected_frequency_minutes` (used to compute overdue), `reported_at`.
- **New endpoint**, `api/status_push.php` — token-authenticated, same pattern as everything else, logs to `ha_sync_log` like the others.
- **New page**, e.g. `status.php` — the actual three-category display, reading `system_status_reports`, computing "overdue" at render time by comparing `last_run_at` + `expected_frequency_minutes` against now.

### Built — design choices made that weren't already decided above

- **Heartbeat interval set to 15 minutes** (this section's own draft said "15–30 min, TBD") — matches the tightest existing schedule (GoDaddy Pull), no strong reason to go slower.
- **The "HA" category's `/api/config` enrichment (mentioned above as worth adding) is deliberately NOT implemented.** It would need either a Supervisor API token or a manually-managed long-lived HA access token — neither otherwise needed by this project — and guessing at that auth mechanism without a live instance to confirm against felt like exactly the kind of unverified assumption this project has been burned by before (§2's `require('fs')` and `/config` lessons). Instead, `ha_core`'s signal is simply the heartbeat flow's own successful execution — real signal, since HA/Node-RED going down means this flow stops running and `ha_core` goes overdue on `status.php`, which **is** the answer to "is HA up." Flagged as an open follow-up, not a silent gap — needs Ward's call on which auth approach he'd rather set up, same pattern as §3's two-config-files and §8's Nabu Casa/Tailscale decisions.
- **System Test isn't reported on `status.php`.** It's manual-only and (unlike the three scheduled flows) never wrote any HA helper entities to read in the first place — nothing persists for a heartbeat to pick up. Not treated as a gap to fix silently; if per-run System Test history matters on the status page later, it would need its own helper entities added first.
- **Overdue formula:** `elapsed > expected_frequency_minutes + max(15, expected * 0.25)` — this section's draft only gave illustrative examples ("~4.5h" for a 4h schedule, "~20min" for a 15min one) without a formula; this is a reasonable approximation that lands close to both examples, not a value Ward specified exactly.
- **`system_status_snapshot`'s InfluxDB fields:** just `status_ok` (1/0) per component per run, tagged by `category`/`component` — enough for "how often has X actually been failing" trend queries (Ward's stated reason for wanting this in InfluxDB at all) without duplicating everything `system_status_reports` already holds on the GoDaddy side.

## 16. GoDaddy weight chart page (built — `godaddy/app/weight_deviation.php`)

A new page — column/bar chart of weight over a selectable time range, with a specific, deliberate framing Ward asked for: **bars represent deviation from the *range's own average*, not absolute weight** — zero baseline is that average, bars extend up above it or down below it. Recalculated per range, not a fixed global average, so switching the date range changes what "average" (and therefore what counts as above/below) means.

- **Range selector** — last 7/30/90 days, or similar, plus whatever else feels natural once actually built.
- **Server-side**: query `daily_logs.weight` for the selected range, compute the average across exactly those rows, compute each day's deviation from it.
- **Charting**: Chart.js via CDN — lightweight, no build step, fits how this project already loads fonts/libraries elsewhere (the LeeWard flyer's Google Fonts pattern). Tooltip on each bar should show both the deviation *and* the actual absolute weight — deviation alone, with no anchor, would be hard to read meaningfully at a glance.
- Worth surfacing the actual average value as plain text somewhere on the page too (e.g. "Average: 233.4 lb over the last 30 days"), not just implied by the zero line.

**Built exactly as designed above** — no HA/Node-RED dependency, so unlike §14/§15 this one has no "reviewed but never run against a live stack" caveat; it's plain PHP reading `daily_logs.weight`, same kind of code as the rest of WardStock that's already live and working. Chart.js loaded via CDN (`cdn.jsdelivr.net`), matching the "lightweight, no build step" call already made in this section. Colors pulled from the app's existing CSS custom properties (`--accent`/`--muted`/`--text`/`--border`) at render time rather than hardcoded, so the chart stays visually consistent with the rest of the app without duplicating the palette.
