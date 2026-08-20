# WardStock ↔ Home Assistant Sync Architecture

*This engine is named **wherewhen** — see `README.md`'s naming note. "Lucius" refers to the whole two-piece project, not this engine specifically.*

**Status:** Exploratory / not started — planning document, separate from `PROJECT_PLAN.md` which tracks the live GoDaddy app.

---

## 0. Quick reference — confirmed deployment details

- **HA instance address:** `https://homeassistant.local`
- **InfluxDB add-on(s) tried:** `hassio-addons/addon-influxdb` (1.8.10 — confirmed via `/health`, uninstalled since — no orgs/buckets/tokens, username+password only, pure-alphabetic requirement). Moved to a 2.x add-on instead — **CONFIRMED installed, configured, and working end to end** (org, bucket, scoped API token all created; real hostname confirmed via `curl .../health`). Full step-by-step, including the add-on's own config YAML, is in `INFLUXDB_V2_SETUP.md`.
- **InfluxDB 2.x add-on repository (correct one, confirmed from the maintainer's own docs):** `https://github.com/Jays-Home-Assistant-Add-ons/repository` — NOT `.../j-addon-influxdb2` (that's the add-on's code repo, not the Supervisor-recognized catalog repo; a real mistake made and corrected live — HA add-on authors commonly split these into two separate repos, worth remembering for any future add-on repository lookup in this project). Add via Settings → Add-ons → Add-on Store → ⋮ → Repositories → paste the URL. Confirmed UI once installed: `http://homeassistant.local:8086/` (SSL off — `ssl: false` set explicitly in the add-on's Configuration tab, confirmed, not defaulted). **Real confirmed values for this install: organization `wardstock`, bucket `metrics`, hostname `a0d7b954-influxdb`** — the hostname especially is Supervisor-assigned and will differ on any other install, but the org/bucket names are just this project's own choice, free to reuse or rename.
- **This add-on's maintenance status, worth knowing:** its own Info tab reports `commit activity: 0/year`, `maintained: no! (as of 2022)`. Still the correct, working, production-ready choice today — just not something to assume will keep receiving updates unattended.
- **GoDaddy site:** `https://emperorschildren.net/Wardstock`

## 1. The architecture (confirmed)

**A functionality split, not a migration:**

- **GoDaddy/WardStock stays exactly as it is** — the app Ward actually opens: incident entry, Daily Log, Medications, Therapy. System of record for everything hand-entered, reachable from anywhere, unchanged. This is what keeps "incidents happen away from home" a non-problem.
- **Home Assistant (HAOS, already running with solar data in it) becomes the background sync + analytics engine and the processing/correlation engine**, running **two independent scheduled flows, not one**:
  - **Oura sync — every 4 hours, anchored to 10am** (10am, 2pm, 6pm, 10pm, 2am, 6am): pulls high-fidelity raw Oura data into InfluxDB (data WardStock's own integration deliberately never captured), then extracts and pushes the same summary fields WardStock already understands up to GoDaddy. Oura's own data doesn't change fast enough to justify more frequent polling — sleep/readiness only really updates once a day (when the app syncs after waking), so a 15-minute cadence was pure overhead for no real freshness gain. 10am as the anchor lines up with Ward's usual wake/sync time, so the first pull of the day catches the previous night's sleep promptly rather than on some arbitrary offset.
  - **GoDaddy manual-data pull — every 15 minutes**, unchanged: pulls incidents/Daily Log/Therapy back down for correlation *and* as a disaster-recovery backup (see §4). This one benefits from staying frequent, since an incident can be logged at any moment and there's real value in HA's copy staying near-real-time.

These are genuinely decoupled — different schedules, different Node-RED flows/triggers, no dependency between them.

**Known gap, found live Aug 2026 — BUILT for this version (3.1.0/Fulgrim):** the Oura Sync flow's `date-1`/`date+1` window (§2) only ever looked around "today," so if a run was missed or Oura simply had no data for a given day yet, that day never got picked up later — confirmed in practice: today's reading synced fine, yesterday's stayed missing, nothing caught it up. The manual backfill flow (§17) fixes this by hand; the *live* flow (`oura_sync_flow.json`) now self-heals too:
- **State tracked via a new HA helper**, `input_datetime.lucius_oura_last_data_date` (`ha_config/helpers.yaml`) — not a state file or InfluxDB marker as originally floated, to reuse the exact same read/write pattern (`api-current-state` / `set_datetime` service call) already proven working for every other status helper in this project, rather than a new mechanism.
- **Fetch window start** is now `min(yesterday, that helper's value)` — normal runs behave identically to before (the helper trails one day behind, same as the old hardcoded "yesterday"); a stale helper (missed runs) extends the window back automatically to close the gap, never narrows it.
- **The GoDaddy summary push** (previously always exactly one day, "today") now happens once per day actually present in the fetched range — a Node-RED `split`/`join` pair around the existing `api/oura_push.php` call, since that endpoint only ever accepts one date per POST (kept as-is deliberately, not changed into a batch endpoint, for what's normally a one-or-zero-gap-day case). Without this half of the fix, a catch-up run would have backfilled InfluxDB but *not* the GoDaddy-visible Daily Log gap Ward actually noticed.

**Known gap, found live Aug 2026 — being fixed this version: only pulling 3 of Oura's real endpoints.** Ward noticed `oura_heart_rate` in InfluxDB only ever has points during sleep hours, and asked whether that's really all Oura tracks. It isn't — Oura rings monitor heart rate continuously all day via PPG, not just during sleep, and Oura's API has a dedicated endpoint for that continuous feed. Confirmed by checking both the Node-RED flows and `godaddy/app/oura.php`: this project has only ever called `/v2/usercollection/sleep`, `/daily_activity`, and `/daily_readiness` — never `/v2/usercollection/heartrate` (the all-day feed) or several other Oura endpoints that exist. **Ward's call: pull everything reasonably available, not just what a specific analysis needs today** — store it in InfluxDB regardless of current use, so it's there for later analytics rather than needing a second historical backfill whenever a new analysis wants it.

**New endpoints being added, live sync AND backfill both** (Ward: "add this to all times we pull Oura data"), confidence noted per endpoint since several of these have never been verified against a real account from this build environment — same "verify against real behavior" discipline as everywhere else uncertain in this project (§10's Oura-400-vs-401 finding, the sleep-stage digit mapping in §11 #20):
- **`/v2/usercollection/heartrate`** (HIGH confidence) — the actual ask: continuous all-day heart rate, not sleep-embedded. Written to its own new measurement, `oura_heartrate_all_day`, kept separate from the existing sleep-embedded `oura_heart_rate` (different sampling characteristics/source, conflating them under one measurement would make "is this a sleep sample or an all-day sample" ambiguous for later queries). Scope: `heartrate` — **already requested** by `oura_connect.php`'s existing scope string, so this one may not even need Ward to reconnect, unlike the others below.
- **`/v2/usercollection/daily_spo2`** (moderate-high confidence) — daily blood oxygen average.
- **`/v2/usercollection/daily_stress`** (moderate-high confidence) — Oura's own "Daytime Stress" metric. Arguably one of the single most relevant new data points this project could add, given the whole point of `wherewhen` — Oura is already trying to detect the same thing (elevated stress) this project's own incident tracking cares about.
- **`/v2/usercollection/daily_resilience`** (moderate confidence — newer Oura feature, may be gated behind an Oura Membership subscription Ward may or may not have).
- **`/v2/usercollection/workout`** (moderate-high confidence) — logged workouts, richer than `daily_activity`'s aggregate steps/calories.
- **`/v2/usercollection/session`** (moderate confidence) — Oura's own guided sessions/moments (breathing, meditation, rest) — worth watching for overlap with Ward's self-directed therapy work (§19's `therapy_history_reference` `self_therapy` entry).
- **`/v2/usercollection/enhanced_tag`** (moderate confidence on current endpoint name — Oura has changed tag-endpoint naming over API versions) — user-entered tags/notes logged directly in the Oura app.

**OAuth scope must expand, and Ward needs to reconnect.** `oura_connect.php`'s current scope request is `daily heartrate sleep` — covers the three existing endpoints plus (already) `heartrate`, but not `workout`/`session`/`tag`/`spo2Daily`-equivalent scopes the new endpoints need. Oura's OAuth doesn't let an already-issued token gain new scopes after the fact — a fresh authorize/consent round-trip is required. **Exact scope string needs confirming against Oura's actual accepted values on first real reconnect** — requested broadly (`email personal daily heartrate workout session spo2Daily tag`) based on Oura's documented scope list, not verified live.

**Resilience design, given how many of these are unverified:** each new endpoint's response gets stored and defaulted with the same `(x && x.data) || []` pattern already used throughout `oura_sync_flow.json`'s line-building function — a 403 (missing scope) or 404 (deprecated/renamed endpoint) on any ONE of these degrades that endpoint's own data to "nothing written this run," not a failure of the whole sync. Worth checking Node-RED's debug output after first real run for which of the moderate-confidence endpoints actually worked as named.
- Checkpoint only advances to `target_date` (today) **after** a successful run, using the window that was computed from its old value — so a failed run leaves it exactly where it was, and the next run tries the same gap again rather than silently skipping it.

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
| `OURA_CLIENT_ID`/`SECRET` | ✅ (defines it) | — (fetched live) | **No longer duplicated** — HA fetches these from GoDaddy via `api/get_shared_config.php` each Oura Sync run, doesn't store its own copy |
| Oura access/refresh tokens | ✅ (GoDaddy's own session) | ✅ (HA's own, independent session) | Not duplicates — legitimately different values, each side's own OAuth session |
| InfluxDB credentials | — | ✅ | HA-only |

**Why two files, not one, was the original recommendation — a real security-boundary choice, not an accident:** GoDaddy and HA are different trust domains (a shared PHP host vs. a home Pi). Fully merging them would mean either side could read the other's full credential set, a larger blast radius if either is ever compromised — and there's no way to literally share *one file* between two separate machines without one side fetching from the other over the network, which is its own chicken-and-egg problem (fetching credentials requires a credential to authenticate that fetch).

**DECIDED (Aug 2026):** GoDaddy becomes the source of truth for the two genuinely-duplicated values, `OURA_CLIENT_ID`/`OURA_CLIENT_SECRET` — not a full merge. New token-authenticated endpoint, `api/get_shared_config.php`, returns just those two values; the Oura Sync flow fetches them fresh each run instead of storing a local copy. `API_SYNC_TOKEN` itself remains the one unavoidable manually-copied bootstrap secret — it can't fetch itself. This shrinks the "must stay in sync manually" row from two categories to one, without merging DB credentials/`APP_SECRET` (GoDaddy-only) or InfluxDB credentials (HA-only) into either file — those still fully justify staying separate for the reason above. Updated table:

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

**This version adds more to sync, following the exact same pattern as that medications gap.** §11's new schema (`medication_dosage_history`, `incidents`' new `stomach_sensation`/`flu_symptoms_sensation`/`lethargy_sensation`/`related_medication_id` columns and `medical` category value) needs to actually reach InfluxDB — §11's whole analysis engine depends on this data existing on the HA side, not just living in GoDaddy's database.

**Built (Aug 2026):**
- `incidents`' new columns needed no `build_export_records()` change — it already does `SELECT * FROM incidents`, so they rode along in the API response for free. `godaddy_pull_flow.json`'s InfluxDB-write function updated to extract the new fields explicitly (they'd otherwise only exist in the raw JSON disaster-recovery archive, invisible to Flux) — `related_medication_id` added to the numeric-fields list, `stomach_sensation`/`flu_symptoms_sensation`/`lethargy_sensation` added to the quoted-string list.
- `medication_dosage_history` got the same explicit type-registration `medications` itself needed: a new type in `build_export_records()` (`db.php`), requested by `pull_manual_data.php`, a new `import.php` handler, and a new structured `medication_dosage_history` InfluxDB measurement in the pull flow's write function. One real wrinkle solved here: `medication_id` isn't portable across a reimport (medications are re-matched/re-inserted by `(name, start_date)`, which can assign a different id on the target database) — the export denormalizes `medication_name` via a join, and both the Node-RED write and `import.php`'s restore path resolve the target medication by `medication_name` + `changed_at` (which always equals the new dosage era's own `start_date`) rather than trusting the exported id directly. Unlike `medications`, this table is insert-only (no update path), so a `since`-filter on `created_at` is safe here — used instead of always pulling the full table.

**Still to build:**
- **Night-waking capture (§11 #16)** needs the same treatment once its own shape (new field vs. new table) is decided.
- **Results flow the other direction too** — §11's ~20 analyses need pushing back to GoDaddy (§11 "Where results go") via the already-planned new push endpoint; that endpoint's payload needs to actually carry all of them, not just the headline correlation matrix.

## 5. New API surface on GoDaddy

Token-authenticated (`Authorization: Bearer <API_SYNC_TOKEN>`, new constant in `config.php`, separate from the human login):

- **`api/oura_push.php`** — HA POSTs summary fields per date; server reuses the *same* merge-safe upsert `oura_sync.php`'s manual pull already uses (one shared function, not a second implementation).
- **`api/pull_manual_data.php`** — returns full incidents + Daily Log + Therapy Sessions, "all" or "since last HA pull" (own bookkeeping key, `app_settings.last_ha_pull_at`, kept separate from the human Export page's `last_export_at`).
- **`api/status.php`** — lightweight health check for the new HA panel (§9): app version, db version sync status (reuses `debug.php`'s existing logic), current server time. Gives the panel something concrete to show beyond "did my last sync succeed."
- **`api/incident_push.php`** (Fulgrim, built Aug 2026) — HA's Medical History Import flow (§19) POSTs one `cardiac`/`medical`-category incident per YAML `encounters[]` entry. Upserts by `external_ref` (new `incidents` column — the YAML entry's stable `id` slug), since incidents otherwise have no reliable natural key. Resolves `related_medication_name` to `related_medication_id` server-side, same reasoning as `import.php`'s `medication_dosage_history` handling — the import flow shouldn't need to know GoDaddy's internal medication ids.
- **`api/analysis_push.php`** (Fulgrim, built Aug 2026) — the Flux analysis engine (§11) POSTs one computed period's result per call. Upserts on `analysis_results`' own unique key (`analysis_key`, `period_type`, `period_start`, `period_end`, `analysis_version`) — different `analysis_version` values are kept as separate rows on purpose (§11 "Schedule & caching"), only a rerun of the *same* version+period overwrites.

Every one of these five endpoints also writes to the new `ha_sync_log` table on every call (§6) — logging isn't a separate feature bolted on, it's part of what each endpoint does on every invocation, success or failure.

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
- **Viewing the new HA status panel (§9) from away from home: a real, separate problem** — mitigated, not solved, by §7's GoDaddy-side mirror. HA's own dashboards live on the home network by default. **DECIDED (Aug 2026): Nabu Casa** — HA's own official paid remote-access service. Ward's own setup action (Settings → Home Assistant Cloud → subscribe, enable Remote UI) — nothing on the sync/flow side changes either way, this only affects how the HA-native Lovelace dashboard itself is reached while away from home; `status.php` on GoDaddy remains the always-reachable alternative view regardless.

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

**Since superseded: switched to Dattel's `InfluxDB2` add-on (Aug 2026)** after finding Jay's had been unmaintained since 2022 — see `INFLUXDB_V2_SETUP.md`. Re-tested the same way: both `http://homeassistant.local:8086` and the new install's Supervisor slug (`http://ec9cbdb7-influxdb2:8086`) returned `200` from a Node-RED exec node; the slug was chosen again, same reasoning as above. The `a0d7b954-influxdb` hostname referenced in the paragraph above is specific to Jay's now-retired add-on and no longer applies.

**Fifth real bug — found running the actual Oura Sync flow for the first time (Aug 2026), past the system-test milestone in §10.** Node-RED's debug panel showed `"No url specified"` at the `InfluxDB: write start point` node. Traced by walking the wiring backward rather than guessing at node config: `n_oura_load_secrets` → `n_oura_fetch_shared_config_prep` (sets `msg.url` to GoDaddy's `get_shared_config.php`) → `n_oura_fetch_shared_config_http` (makes that GET call) → `n_oura_merge_shared_config` → straight into the InfluxDB write node. **Root cause:** the GoDaddy shared-config fetch — needed to pull `oura_client_id`/`oura_client_secret` fresh each run (§3) — sits between the secrets load and the InfluxDB start-point write, and consumes `msg.url` for its own GET request. Nothing rebuilt `msg.url` for InfluxDB afterward. This is specific to Oura Sync: the other three flows (`godaddy_pull_flow.json`, `body_comp_import_flow.json`, `status_heartbeat_flow.json`) go straight from loading secrets to their InfluxDB start-point write with no GoDaddy call in between, so their `Load secrets + start job log` function already builds `msg.url` directly and none of them have this bug. **Fixed** by adding a new function node, `Prepare InfluxDB start-point write`, between `n_oura_merge_shared_config` and the write node — same `msg.url`/`msg.method`/`msg.headers`/`msg.payload`-from-`msg.secrets` pattern the batch and finish-point writes already used correctly. Worth remembering for any future flow that inserts an extra HTTP call between "load secrets" and an InfluxDB write: that call will consume `msg.url`, and the next write needs its own explicit rebuild step, not just a shared upstream one.

**Sixth real bug, found immediately after fixing the fifth: `get_shared_config.php` 404'd live.** Node-RED reported a JSON parse error on the GoDaddy shared-config fetch; the actual body was GoDaddy's generic "File Not Found" HTML page, not JSON — confirmed directly with `curl -o /dev/null -w "%{http_code}"` against the live URL (404, versus 401 for `status.php`/`oura_push.php` in the same folder, which do exist). Not a code bug — `get_shared_config.php` was added to the repo in the "packaged 2.2.5" commit, and Ward's live site's `app/` folder hadn't been re-uploaded since before that. **Fixed** by re-uploading `app/` (the whole folder, not just the one file, on the theory that other 2.2.5–2.2.8 additions were likely stale too) — re-confirmed via the same curl check, now `401` like its siblings.

**Seventh real bug, found once the sixth was fixed and the flow ran further: Oura data push to GoDaddy failed with `validation_error` — "no recognized fields in body."** Confirmed via GoDaddy's own HA Sync Status box (`oura_sync.php`) rather than guessed at — the shared-config fetch now shows `success`, so the flow is reaching much further than before, but the actual sleep/activity/readiness data never made it into the summary sent to GoDaddy. Root cause, traced through `n_oura_check_token`'s expiry logic: `new Date(secrets.oura_expires_at || 0).getTime()` returns `NaN` for anything unparseable — and `oura_expires_at` was still holding its literal placeholder text from `lucius_secrets.json.example` (the real manual OAuth authorization step, README setup step 3, was never actually run — only `oura_access_token` had a real-looking value manually present, not the matching `refresh_token`/`expires_at` triplet that step produces together). Any comparison against `NaN` is always `false` in JavaScript, so `needsRefresh` silently evaluated to `false` instead of `true` — the flow treated a missing/placeholder expiry as "token still valid" and pushed ahead with whatever stale `oura_access_token` was sitting in the secrets file, rather than refreshing (or clearly failing) up front. Oura's API then presumably rejected that token, but the sleep/activity/readiness fetch nodes don't distinguish an error response from a real empty result (both end up read as `(msg.X_response && msg.X_response.data) || []`), so the failure degraded silently into "zero records" instead of a hard, visible error — explaining why the debug panel showed nothing at all for that step. **Fixed the silent-fallback part:** `needsRefresh` now also treats a non-finite `expiresAt` as needing refresh (`!Number.isFinite(expiresAt) || ...`), so an invalid/placeholder timestamp fails safe into a refresh attempt (which will then either succeed, or fail loudly through the existing `oura_auth_failed` path in `n_oura_save_refreshed_token` — see its `node.warn()` call — rather than failing silently). **Not yet fixed, and the real remaining blocker:** Ward still needs to actually complete the manual Oura OAuth authorization step (README setup step 3) so `oura_access_token`/`oura_refresh_token`/`oura_expires_at` all get set together from a real exchange, rather than a stale/manually-entered `access_token` on its own — the code fix here makes a bad token fail *loudly* instead of *silently*, it doesn't supply a valid one.

Resolved properly: Ward reconnected via `oura_connect.php` (confirmed working — GoDaddy's own Oura pull got real data) and manually copied `access_token`/`refresh_token`/`expires_at` from phpMyAdmin's `oura_tokens` table into `/share/lucius_secrets.json`, per README step 5's documented one-time bootstrap (the two systems' Oura tokens are deliberately separate stores, not auto-synced — worth remembering if this comes up again).

**Eighth real bug — found immediately after, once the flow reached the HA helper-entity write step for the first time: a Node-RED warning triangle on `Call HA service (set helper entity)`, and its config panel showing Action/Targets/Data as genuinely empty (confirmed via Ward's screenshot of the open node), not merely unset.** Root cause: the three `api-call-service` nodes across `oura_sync_flow.json`, `godaddy_pull_flow.json`, and `body_comp_import_flow.json` (added when the deprecated `ha-entity` node was originally replaced, §9) were built against an **older schema** of `node-red-contrib-home-assistant-websocket`'s service-call node — separate top-level `domain`/`service`/`target`/`payload` message properties, driven by `domainType: 'msg.domain'` etc. Ward's installed version is the newer **"action" node** redesign (matching Home Assistant's own "services" → "actions" rename) — confirmed via its actual docs page (`zachowj.github.io/.../node/action.html`): it reads everything from a single `msg.payload = { action: 'domain.service', target: {...}, data: {...} }` object instead, and doesn't recognize the old separate properties at all, leaving the UI looking blank rather than erroring. **Fixed** by changing each flow's `Split entities for HA WebSocket node` function to emit `{ payload: { action, target, data } }` per message instead of the old `{ domain, service, target, payload }` shape — confirmed from the docs that `payload.action`/`target`/`data` override the node's own UI config even when that config is left blank, so nothing else about the node needs changing. Updated each node's stale inline `comment` to match. **Still required per install, same as before, unrelated to this fix:** the `Server` field on each of these three nodes has to be set manually in the editor — it can't be pre-filled from JSON. Worth remembering generally: this palette's underlying schema has now changed at least once during this project's build-out, so don't trust an old exported flow's node properties blindly after a package update — check the node's own current docs if a config panel looks emptier than expected after import.

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

**Why this section exists — see `ClaudeMemory/Claude_private.md` (never git-tracked, private).** Worth knowing this analysis engine traces back to an observational framework Ward had already independently drafted for tracking anxiety episodes, well before this schema was designed — it maps onto `incidents` almost field-for-field, which is a real signal the table's already capturing the right things, not a coincidence.

### Engine, phase 1: Flux queries — built Aug 2026 (Fulgrim Flux-engine pass), untested against live infra

Ward's eventual target is real multi-variate/regression-style analysis (several factors weighed together, not just pairwise), which is what first pointed at a Python engine (AppDaemon — see "Phase 2" below). But there isn't enough data accumulated yet to make a full regression meaningful, so **Ward is good with Flux queries for this initial pass** — build the framework now with what InfluxDB's Flux language can do directly (its `join()` + `covariance`/`stats` packages), and let it mature toward Phase 2 as more data accumulates. No new add-on needed for this phase — **Node-RED orchestrates** (schedules the Flux queries, shapes results), matching what's already running.

### Concrete v1 analyses — wide net, decided Aug 2026

Ward's call: go wide now, cover everything the data actually supports, refine later once real data volume shows what's actually useful — not a narrow v1. Organized the way Ward framed it: **Automatic/measured** (Oura, smart scale, medication schedule — arrives without Ward writing anything down beyond what WardStock/Oura already capture) vs. **Reported** (incidents plus Daily Log's self-reported fields, plus therapy). **Each analysis gets its own separate chart — not merged into one combined view** (see "UI" below).

#### Automatic / measured

1. **Sleep duration trend by day** — plain day-by-day time series.
2. **Sleep efficiency trend by day** — kept separate from duration, deliberately: a full-but-fragmented night and a short-but-efficient one are different problems and would wash each other out if combined into one "sleep" number.
3. **HRV trend by day** — watched specifically for *upward* movement (higher HRV generally reads as better recovery/lower stress), not just plotted flat — its own standalone chart, separate from HRV's role in the headline correlation (#17).
4. **Resting heart rate trend by day** — the other core autonomic marker alongside HRV, same standalone-trend treatment.
5. **Body composition trend** — weight plus the smart-scale fields already landing in InfluxDB with no chart anywhere yet (body fat%, muscle mass, visceral fat%, etc., per §14's field list). Extends `weight_deviation.php`'s existing pattern (§16) rather than replacing it.
6. **Exercise/activity trend** — `steps`, `exercise_minutes`, `standing_minutes`.
7. **Weight vs. medication cadence** — some of Ward's medications are weekly/bi-weekly; look for weight shifts tied to dosing, using medication events already synced via GoDaddy Pull (§4).
8. **Medication dosage-change correlation** — weight/HRV/sleep/mood/incidents (now including `medical`-category incidents, see below) before vs. after a dosage change, **same medicine, different dosage**. Motivated directly by Ward's own plan to raise some dosages soon, where side effects are a real concern he wants visible, not just weight drift. **No longer blocked (Aug 2026)** — `medication_dosage_history` exists and `medication_form.php` writes to it automatically on the existing "start a new dosage" flow; only the actual Flux query computing this analysis is still outstanding.
9. **Medication adherence vs. incidents** — missed-dose days (`medications_all_taken`/`medications_taken_json`) vs. incident occurrence/severity. Straddles both categories (the checkbox is self-reported, but it rides on the automatic scheduling engine); grouped here since it's about the medication system specifically.

#### Reported (incidents + Daily Log self-report + therapy)

10. **Alcohol & caffeine vs. sleep** — pairwise correlation.
11. **Mood / state-of-mind trend**, plus its correlation against sleep/alcohol/caffeine/incidents — `mood_rating` and `state_of_mind` (1–5) exist on every Daily Log entry and weren't in the original four-analysis list.
12. **Symptom & category clustering** — anxiety vs. cardiac split over time, and whether the co-occurring symptom fields (chest/arm/shoulder/headache sensation, shaking) cluster into recognizable patterns.
13. **Day-of-week / time-of-day incident clustering** — do incidents themselves cluster on particular days or times, independent of any input factor.
14. **Incident intensity/duration trend over time** — is `anxiety_intensity` or `duration_minutes` trending up or down, the most direct "getting better or worse" view.
15. **Therapy session effects** — `mood_before`/`mood_after` per session, and whether incident frequency shifts around therapy cadence.
16. **Night-waking context** — Ward wants to capture *why* he woke and what he was thinking during nighttime wakings, not just Oura's raw sleep-stage/awakening data. **No longer blocked (Aug 2026)** — `daily_logs.night_waking_notes` (free text, the "simplest shape" option) exists and `daily_form.php` captures it; only the actual Flux query is still outstanding. The richer per-waking/per-Oura-awakening version remains a possible future refinement, not built.

#### Headline / cross-cutting

17. **Full correlation matrix across all tracked factors** — incidents, alcohol, caffeine, sleep, weight, mood, HRV, exercise. Expanded from the original "incidents × alcohol × weight (food proxy) × caffeine × sleep" ask now that mood/HRV/exercise are in scope too — this is the "big picture" chart, everything else above is its own focused view of one piece of it. Ward's explicit expectation carries over: not enough data yet for this to surface statistically valid findings — build it now anyway, expect it to mature as data collection does.
18. **Unreported-anxiety detection** — cross-reference physiological markers (HRV dips, resting-HR spikes, sleep disruption) against days with *no* logged incident, looking for days that pattern-match known incident days without one being recorded ("anxiety, or exercise, that wasn't reported," Ward's framing). Genuinely different technique from everything above — pattern-matching against a baseline "incident signature," not a correlation coefficient or a trend line — and needs enough labeled incident history to build that baseline before it means anything. Being planned now along with everything else, but realistically the one most likely to want to wait for data to mature before it's trustworthy.
19. **Bedtime / wake-time trend** — day-by-day, when Ward actually went to bed and woke up, plotted as two series over time (not a duration number — the clock times themselves). Companion to #1/#2 rather than a replacement: duration/efficiency say how the night went, this says when it happened. Uses `oura_sleep`'s `bedtime_start` (already captured) — need to confirm at build time whether a usable wake-time/`bedtime_end` value is already in the record or needs deriving from `bedtime_start` + `period`/`total_sleep_duration`.
20. **Sleep-stage timeline ("hypnogram")** — for each night, plot sleep stage (asleep/REM/other sleep types/awake) in **15-minute increments**, x-axis bounded to **the earliest bedtime and latest wake time actually seen in the data — not a fixed 24-hour axis.** **Decomposition side built (Aug 2026), the follow-up `oura_sync_flow.json`'s own code comment flagged when that flow was first built** — both it and `oura_backfill_flow.json` now decode `sleep_phase_30_sec` into real `oura_sleep_stage` InfluxDB points (`sleep_id`/`stage` tags), bucketed to 15 minutes by majority vote per bucket (so a brief awakening inside an otherwise-asleep window doesn't just vanish under a naive stride-sample). **The digit-to-stage mapping itself is ASSUMED, not confirmed** — `1`=deep/`2`=light/`3`=rem/`4`=awake, carried over from Oura's documented convention for their 5-minute hypnogram field, not verified against a real `sleep_phase_30_sec` string from Ward's own data. Same "verify against real behavior" requirement as everywhere else uncertain in this project (§10's Oura-400-vs-401 finding is the precedent) — spot-check a few decoded nights against what Ward actually remembers on first real run, fix `SLEEP_STAGE_MAP` in both flows if wrong. The chart-rendering side (actually plotting this, bounded-axis logic) is still outstanding, part of §11's Flux engine work.

### UI: one chart per analysis, not one combined view — built Aug 2026 (shells; no real chart rendering yet)

Ward's call: each of the analyses above gets its **own separate chart**, not folded into a single dashboard tile. Lives under the new "Where When" tab's Analysis sub-tab (§18), `analysis.php` — page shells grouped Headline/Automatic/Reported, extending `weight_deviation.php`'s existing single-chart pattern (§16) rather than replacing it, reading real `analysis_results` rows and showing "no data yet" until §6's Flux engine pushes something.

**Display order: Headline first (Ward, Aug 2026) — "the headline is the core function," make it the first thing people see**, not Automatic/Reported/Headline as originally drafted. The four headline analyses (#17–20: full correlation matrix, unreported-anxiety detection, bedtime/wake-time trend, sleep-stage hypnogram) render at the top of the page; Automatic and Reported follow below as supporting detail. **Actual per-chart rendering is the one piece still genuinely outstanding as of the Fulgrim Flux-engine pass (Aug 2026)** — the engine itself is built and will push real `result_json` once run, but `analysis.php` still only shows a byte count for whatever landed in `analysis_results`, not an actual chart. Real UI work (20 different result shapes: trend lines, correlation scatter/coefficients, clustering bars, the hypnogram timeline) — next GoDaddy-side piece, not started this pass.

### New GoDaddy incident category: medical (renamed from "side effects," broadened — Aug 2026)

New third `incidents.category` value, alongside existing `anxiety`/`cardiac` — **named `medical`, not `side_effect`**, and broader in scope than side effects alone. Ward's correction: WardStock already tracks whether a medical evaluation happened (`medical_evaluation`/`medical_evaluation_notes`, existing columns), so `medical` becomes the category for the actual medical-review record itself — doctor's visits and the notes from them, medication side effects, and any future medical issues generally — not a narrowly-scoped side-effects-only type. Still motivated directly by #8 above (dosage changes correlated against the side effects they cause), just not limited to that one case.

- **`category` fits as-is** — `medical` is 7 characters, within the existing `VARCHAR(10)`. No width change strictly required by this value (unlike the earlier `side_effect` draft, which didn't fit); still cheap, low-risk headroom to widen to `VARCHAR(20)` alongside this migration if more categories seem likely later, but no longer a forced change.
- **Doctor-visit content reuses existing fields, not new ones** — `medical_evaluation`/`medical_evaluation_notes` already exist on `incidents` and are a natural fit for "did you see a doctor" + their notes/findings, now doing double duty: still usable as follow-up-evaluation flags on anxiety/cardiac entries, and as the primary content fields on a `medical`-category entry itself.
- **New symptom columns**, same `none`/`mild`/`moderate`/`severe` convention as the existing `chest_sensation`/`arm_sensation`/etc.: `stomach_sensation`, `flu_symptoms_sensation`, `lethargy_sensation` — cover the side-effects case specifically (headache already covered by the existing `headache_sensation` column). Nullable/optional the same way `nitroglycerin_taken` already is cardiac-only; a general medical entry (e.g. logging an unrelated doctor's visit) may leave all three unset and just use `free_notes`/`medical_evaluation_notes`.
- **Proposed, not yet confirmed:** an optional `related_medication_id` (nullable FK to `medications.id`) on `incidents`, settable when `category = medical`, so a medical entry can explicitly name a suspected medication rather than relying purely on time-proximity to a dosage change for #8's correlation. Strongly implied by Ward's stated goal but not something he asked for in these exact words — flagging for confirmation rather than assuming silently.

### New SQL this version implies

- **`medication_dosage_history`** — needed for #8 (medicine name, old dosage, new dosage, changed_at).
- **`incidents.stomach_sensation`/`flu_symptoms_sensation`/`lethargy_sensation`, plus (proposed) `related_medication_id`** — needed for the new `medical` category above. `category`'s existing `VARCHAR(10)` already fits `medical`; widening to `VARCHAR(20)` is optional headroom, not required.
- **Night-waking capture field/table** — needed for #16, exact shape TBD.
- New table(s) to hold pushed chart/correlation-result data on the GoDaddy side (already noted under "Where results go" below).

All land in this version's `sql/upgrade_from_3.0.0.sql` (per the one-cumulative-file-per-major-line convention), consistent with Ward's expectation that this version changes the SQL database.

### Schedule & caching — decided Aug 2026

Four analysis tiers, each its own scheduled Flux query run + Node-RED trigger, all reading raw data from InfluxDB and writing their result back to InfluxDB:

- **Daily — ~12:00 noon.** Anchored at noon specifically so the day's own data is reliably loaded first (well past Oura's ~10am anchor and GoDaddy Pull's 15-minute cadence) before that day gets analyzed. Covers the day-by-day trend analyses (#1–6, 11, 14 below) and feeds the series the other tiers build on.
- **Weekly** — looks for day-of-week / weekly trends.
- **Monthly** — re-examines the same analyses (trend-by-day, correlations) over a month-wide window — a wider recompute, not a new analysis type.
- **All-data** — the largest analysis, full historical range.

**Caching, not reprocessing — the actual point of storing this in InfluxDB at all:** each tier's run writes its result as its own row/point, tagged by `period_type` (daily/weekly/monthly/all), `period_start`/`period_end`, and an `analysis_version` tag (see below). Once a period's result is written, later reads (the GoDaddy chart, Grafana, a future push) pull the cached result instead of re-running Flux against raw data every time — this is what makes "weekly" not mean "reprocess all history every week."

**Full recalculation — required, two triggers:**
1. **The analysis logic itself changes** (a Flux query edited, a new metric added, a different correlation formula).
2. **Backfilled data arrives after the fact** — the existing Oura backfill flow (§17) is the concrete case already in this repo; any future historical GoDaddy import would be another.

Either case makes previously-cached periods stale or incomplete. Handling this cleanly means: every cached result carries the `analysis_version` tag mentioned above, bumped whenever the methodology changes; all reads (chart, push) only ever consult the current version's rows; and a **manual, on-demand "full recompute" job** (its own Node-RED flow, triggered by hand like System Test — not on a schedule) walks every historical period at every tier and rewrites results at the current version. InfluxDB being naturally append-only means old-version rows don't need deleting, just stop being read — cheap to keep for comparison if a methodology change is ever worth double-checking against the old output.

### Where results go — confirmed

1. **InfluxDB** — derived `correlation_results` measurement (or similar), available for Grafana later and to avoid recomputing on every read.
2. **GoDaddy app** — pushed and rendered as an actual chart, not just a `status.php` placeholder line. New page, same lineage as `weight_deviation.php` (§16); needs a new token-authenticated push endpoint following the `status_push.php` pattern (logs to `ha_sync_log`).

### Phase 2 (future, not scheduled): AppDaemon (Python)

Once data volume/maturity actually calls for real multi-variate regression, the identified escalation path is still **AppDaemon** — the standard HAOS-native add-on for persistent Python apps, with `pandas`/`numpy`/`scipy`/`statsmodels` installed via its own `python_packages:` config, isolated from HA core's Python environment (unlike `pyscript`, where installing heavy stats libs risks breaking HA itself on update). Would run as a separate scheduled tier downstream of the same InfluxDB data, not a replacement for Node-RED. Revisit once Flux proves limiting, not before.

### Versioning implication — flagged by Ward, not yet executed

Ward expects this to need a **Major version bump** when it actually ships (next major line: 3.0.0) — new SQL table(s) to hold pushed correlation results for the chart, and scope Ward considers "functionally different" from the 2.x line. Per `godaddy/README.md`'s rule, a Major bump also means a new codename — **DECIDED: "Fulgrim"** for the 3.x line (replacing "Lucius"). Not yet executed — no version files touched, no codename applied anywhere in code — since version bumps only happen when Ward explicitly says to push (CLAUDE.md standing rule); recorded here so it's ready when that happens. Will need `sql/upgrade_from_3.0.0.sql` once the schema is final, per the one-cumulative-file-per-major-line convention.

**Built (Aug 2026, Fulgrim Flux-engine pass) — `nodered/analysis_engine_flow.json` + `nodered/analysis_engine_test.json`.** Resolves everything the "Still open" list below used to say, with the calls actually made recorded here since PLAN.md left them open:

- **All 20 analyses' Flux queries/fields are real**, read directly from the actual InfluxDB-write code in `oura_sync_flow.json`/`godaddy_pull_flow.json`/`body_comp_import_flow.json` (measurement/field/tag names), not guessed from this document's own prose. Two real GoDaddy Pull gaps found and fixed while doing this: `daily_log_manual` never carried `medications_all_taken` (needed for #9) or `night_waking_notes` (needed for #16) — both added.
- **Flux's own job is deliberately narrow**: `from|range|filter|sort` only, the same primitives already proven working in `wherewhen_data_export/restore_flow.json`. All statistics (Pearson correlation, linear trend slope, clustering counts) are computed in plain JS after the CSV response comes back, not via Flux's own covariance/stats packages — those have never been confirmed against this project's real InfluxDB install, whereas a hand-written correlation function is something confirmable by inspection and a synthetic-data test (built: `analysis_engine_test.json`, 20/20 analyses + a CSV-parser regression test, all passing against fixtures).
- **Every analysis runs at every tier that fires** (daily/weekly/monthly/all), rather than hand-assigning which analyses belong to which tier — simpler one-path design, PLAN.md left this open and this is the call made. Tier windows: daily=trailing 90 days, weekly=trailing 180 days, monthly=trailing 400 days, all=since 2025-08-01 (a safe early anchor, not precisely researched — matches every real timestamp in the schema/seed data). Daily/weekly/monthly are scheduled (12:15pm / Sunday 4am / 1st 5am); all-data is manual-only, same treatment `oura_backfill_flow.json` already gave its own heaviest operation.
- **Sparse/missing days**: handled implicitly — `toDaySeries()` only ever produces a point for a day that actually has data, every trend/correlation function works off however many paired points exist (Pearson correlation explicitly returns `null` below 3 points rather than a misleading coefficient).
- **Where results go, confirmed working**: pushed via the already-built `api/analysis_push.php` to `analysis_results`, upsert-by-`(analysis_key, period_type, period_start, period_end, analysis_version)`, `analysis_version: 1` for this first build.
- **A real bug found and fixed while building this**, not specific to the Flux engine itself: `wherewhen_data_restore_flow.json`'s CSV parser (which `analysis_engine_flow.json`'s own parser was adapted from) misparsed a **repeated identical header row** across two consecutive Flux table blocks as a data row — a case that occurs routinely in real Flux output (same tag set, different tag values). Low practical impact in the restore flow itself (the phantom row's `_time` value fails a downstream NaN check and gets silently dropped) but fixed at the source in both flows rather than relying on that incidental self-correction.
- **Not resolved, deliberately deferred**: retention policy for old-`analysis_version` cached rows — still echoes the same open question sitting in §12 for `sync_job_runs`/archive data, worth deciding together rather than piecemeal, not blocking anything today since InfluxDB is append-only and nothing currently prunes.
- **UNTESTED against live infra, same as everything else on this project's HA side** — validated by: extracting every embedded function body and syntax-checking it with Node, running a full mocked pipeline simulation (period-window → secrets → all 20 queries → mock CSV responses → shape → push payloads → job success/HA entities) end-to-end, and the companion test flow's own fixture-based checks. None of that is a substitute for a real InfluxDB response — Flux's exact CSV output shape for THIS project's real data volume/cardinality has never been observed.

## 12. Remaining open questions

- Local archive retention for the disaster-recovery JSON dumps (§4) — keep every pull, or just the most recent N?
- Retention for `ha_sync_log` (§6/§7) and the InfluxDB `sync_job_runs` measurement — logs like this grow forever if nothing ever prunes them; worth a deliberate cap (e.g. keep 90 days, or last N rows) rather than letting either grow unbounded.
- Whether HA's own automatic backups cover `/share` (where the secrets file and disaster-recovery archive now live) the same way they typically cover `/config` — not confirmed. Matters most for the archive file specifically, since extra backup coverage is a real feature for a disaster-recovery artifact, not just incidental.
- ~~Nabu Casa vs. Tailscale vs. home-only~~ — **DECIDED (§8): Nabu Casa.**
- ~~Exact HA helper entities/naming for the status panel~~ — **resolved**, built into `ha_config/helpers.yaml`.

## 13. Suggested build order

1. Build the three new GoDaddy API endpoints (`oura_push.php`, `pull_manual_data.php`, `status.php`) + `API_SYNC_TOKEN` config **+ the `ha_sync_log` table and logging call in each endpoint from the start**, not bolted on after. Test with curl before Node-RED is involved at all — including deliberately wrong tokens/malformed bodies, to confirm the failure-code logging actually produces the right codes.
2. Add the `ha_sync_log` display to `oura_sync.php` and `oura_test.php` (§7) — can be verified entirely with the curl tests from step 1, before any HA-side code exists.
3. Set up the InfluxDB add-on and Node-RED add-on (with the HA WebSocket palette) on HAOS. Import and run the **System Test flow** (§10) before building anything else on the HA side — it confirms config, InfluxDB, Oura, and GoDaddy are all reachable before there's a real sync flow to debug on top of that.
4. Build the **Oura flow** first (its own 4-hour trigger, anchored 10am): Oura pull → InfluxDB high-fidelity write → GoDaddy summary push → `sync_job_runs` start/completion points. Confirm it's working end-to-end, including a deliberately-forced failure (e.g. temporarily wrong token) to confirm the failure-code logging actually fires correctly, before touching the second flow.
5. Build the **GoDaddy pull flow** separately (its own 15-minute trigger): pull manual data (now including medications — see §4) → InfluxDB structured write + local JSON archive → `sync_job_runs` logging.
6. Add the HA-side status-panel entities and Lovelace card (§9) once both flows have real data flowing — remember they're two independent timestamps, not one.
7. Correlation analytics (§11 — Flux queries, phase 1) come after data has had a few days to accumulate — not before.

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
- **Analytics** — placeholder only for now. Nothing to report until the correlation-analysis phase (§11 — phase 1 engine: Flux queries, orchestrated by Node-RED) actually exists and its results get pushed across; the category exists in the design so adding it later doesn't mean restructuring the page.

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
- **Extended (Aug 2026, Fulgrim validation pass) to cover 6 flows, not just 3** — Medical History Import, wherewhen Data Export, and wherewhen Data Restore joined Oura Sync/GoDaddy Pull/Body Comp Import once their `job_failed` gap was fixed (see §20's own note). Same read-chain-then-`pushComponent()` pattern, just longer. The two manual-only ones (Medical History Import, wherewhen Data Restore) report with `expected_frequency_minutes: null` — no real cadence to be overdue against, same treatment System Test would get if it ever gained HA helper entities to read. wherewhen Data Export gets a real value (10080 = 7 days) since it has a genuine weekly schedule alongside its manual trigger.
- **The "HA" category's `/api/config` enrichment (mentioned above as worth adding) is deliberately NOT implemented — DECIDED (Aug 2026): staying this way, not a placeholder pending a future decision.** It would need either a Supervisor API token or a manually-managed long-lived HA access token — neither otherwise needed by this project — and Ward confirmed the heartbeat-execution signal is sufficient: `ha_core`'s signal is the heartbeat flow's own successful execution — real signal, since HA/Node-RED going down means this flow stops running and `ha_core` goes overdue on `status.php`, which **is** the answer to "is HA up." No further work planned here unless something changes.
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

## 17. Oura backfill flow (new — `nodered/oura_backfill_flow.json`, manual/optional, not run yet)

A sixth Node-RED flow, deliberately separate from the five in `homeassistant/README.md`'s required setup sequence — manual/on-demand only, not scheduled, not imported by default. Built because a manual pull through GoDaddy's own Oura integration only ever gives the curated WardStock summary subset (`sleep_duration_hrs`/`sleep_efficiency`/`resting_hr`/`hrv`/`steps`) — Ward wants the same full-fidelity archive `oura_sync_flow.json` already writes to InfluxDB for live data (every scalar field per record, plus decomposed 5-minute `heart_rate`/`hrv` time series), but for a whole historical range at once, not just "today."

- **Trigger:** a manual `inject` node whose JSON payload — `{"start_date": "...", "end_date": "..."}` — Ward edits directly in the editor before each click. No dashboard/form UI added for this (would mean pulling in `node-red-dashboard`, a new dependency this project doesn't otherwise need, for a flow that's run rarely and by the one person who already has the Node-RED editor open).
- **Answers Ward's actual question ("does it know where the data is, or do I have to set it")**: neither — no discovery step exists or is needed. Oura's API just returns whatever real records exist inside whatever range you ask for; a range wider than the real data (even absurdly wide) simply comes back empty for the parts with nothing recorded, not an error. So the practical answer is: pick a range as wide as you're comfortable with and let Oura tell you what's actually there.
- **Pagination, genuinely new territory for this project:** the live sync flow's 3-day window never needed more than one page from Oura per endpoint. A real historical range can. Each of the three endpoints (`sleep`, `daily_activity`, `daily_readiness`) loops on Oura's `next_token` cursor until it stops appearing, accumulating into one array per endpoint before moving to the next — with a 500-page safety cap per endpoint (warns and stops cleanly with whatever was collected if that's ever actually hit, rather than looping forever on something unexpected).
- **Chunked InfluxDB writes**, 500 lines per POST with a short pause between chunks — a multi-year backfill with decomposed heart_rate/hrv series can produce tens of thousands of lines, and one request that size risks a body-size limit somewhere in the chain for no benefit over several hundred smaller ones. Each chunk logs a `node.warn` progress line (`writing chunk N/M`), plus a final summary (`node.warn`) with total records/lines/duration — deliberately more debug-panel chatter than the scheduled flows, since this is an interactive tool Ward is watching run, and "no debug messages, is it doing anything" was a real point of confusion earlier in this project (§ fix log, Aug 2026).
- **Reuses, rather than reimplements**, the exact InfluxDB line-building logic from `oura_sync_flow.json`'s `Build InfluxDB lines + GoDaddy summary` function (`sleepRecordToLine`/`decomposeTimeSeries`/the activity and readiness line-builders), so backfilled and live-synced data share one schema — just without the GoDaddy-summary half, which doesn't apply here (this flow never pushes to GoDaddy or touches HA helper entities, InfluxDB only). Also reuses the shared-config fetch and the now-fixed (Aug 2026) token-expiry-check/refresh logic verbatim, including both of that flow's real bugs already fixed at the source rather than re-introduced here: the NaN-unsafe expiry check, and the InfluxDB-write-node `msg.url` getting consumed by the intervening GoDaddy call.
- Also logs a start/finish point to InfluxDB's `sync_job_runs` measurement (`job_name=oura_backfill`), same convention as the other flows, so a run is confirmable there too even without watching the debug panel live.

**No separate companion test/validation flow for this one** — a deliberate exception to the "every live flow gets one" rule (§2), on the reasoning that this flow already *is* a manual, on-demand diagnostic tool in its own right, same as `system_test_flow.json` not having one either.

**UNTESTED — built from `oura_sync_flow.json`'s proven-live logic but never run end-to-end itself.** Whether Oura's pagination param is really `next_token` passed alongside the original `start_date`/`end_date` (rather than needing them dropped on subsequent pages), and whether the 500-line chunk size is actually a sensible InfluxDB/add-on body-size margin, are both reasoned from documentation and the existing flow's patterns, not confirmed against a real multi-page response yet.

**Relationship to the live-flow self-heal TODO (§1):** this manual flow remains the tool for a deliberate wide historical pull; it doesn't replace the fix planned for `oura_sync_flow.json` itself, which is about the live flow automatically catching up small day-or-two gaps (missed runs, data not yet available) without Ward having to notice and trigger this flow by hand.

## 18. GoDaddy navigation: new "Where When" tab — built Aug 2026 (page shells; Analysis has no real data yet)

Ward's call: the main nav (`partials_nav.php`) is getting crowded now that §11's ~20 analysis charts need somewhere to live, so **consolidate under one new top-level tab, displayed as "Where When"** — a deliberate callback to the HA-side analytic/processing engine's own name. **Naming note, easy to get wrong: the engine's actual name is `wherewhen` — all lowercase, no space, no capitalization** (decided in the Aug 2026 "Name the HA engine 'wherewhen'" release; also stated plainly at the top of this document). The nav tab's human-readable display text ("Where When," title case with a space) is just UI label styling and a distinct thing from the engine's own name — don't let the two spellings drift into each other in code or docs.

- **New top-level nav item**: "Where When," alongside the existing Home / Incidents / Daily Log / Medications (those four stay put — this is a consolidation of the *other* pages, not a full nav rebuild).
- **Sub-tabs under it**:
  - **Analysis** — new; houses all ~20 charts from §11 (grouped Automatic/Reported per that section's "UI" note).
  - **Status** — moved from top-level (`status.php`, §15).
  - **Export** — moved from top-level (`export.php`).
  - **Therapy** — moved from top-level (`therapy.php` + its form/schedule pages), on Ward's own reasoning that therapy is itself a form of analysis, not a logging page like Incidents/Daily Log.
- **Resulting top nav**: Home, Incidents, Daily Log, Medications, Where When, Log out — down from the current 7 links to 5 plus logout.
- **Implementation shape (built as predicted):** `wherewhen.php` landing/router page plus `partials_wherewhen_nav.php`, a second sub-nav partial mirroring `partials_nav.php`'s own pattern, for the four sub-tabs — `status.php`/`export.php`/`therapy.php`/`therapy_form.php`/`therapy_schedules.php`/`therapy_schedule_form.php`/`import.php`/`debug.php`/`oura_test.php` all kept their filenames and logic unchanged, just get reached through the new sub-nav and dropped out of the main one. Each of those pages now sets `$active = 'wherewhen'` (top nav) plus `$subActive` (sub-nav) — settling the "one variable or two" question below in favor of two, since the two navs highlight independently.
- **New `analysis.php`** — the Analysis sub-tab, `.analysis-grid`/`.analysis-card` (new CSS, matching the dashboard's existing `.hub-grid`/`.hub-card` pattern) — one shell per analysis from §11's numbered list, grouped Headline (first)/Automatic/Reported (see §11's own "UI" note for the display-order call), reading real `analysis_results` rows and showing "No data yet" until the Flux engine (§11, still outstanding) actually pushes something via the new `api/analysis_push.php` (§5).
- **`wherewhen.php` is also now home for non-direct-functional content, not just the four sub-tabs (Ward, Aug 2026)** — now that this page exists, it's the natural place for anything that isn't core incident/daily-log/medication tracking. Moved here: the LeeWard/marketing-flyer card (from `about.php`, at the *top* of the page — Ward's explicit placement) and the "About WardStock" / "App / database version" footer links (from `export.php`, at the bottom). `about.php` itself still exists separately, unlinked from `wherewhen.php` on purpose — it's reachable pre-login (`login.php`'s footer), which this `require_login()`'d page can't be.

**Resolved:** sub-nav is its own row of tabs (not a dropdown), and `$active`/`$subActive` are two separate variables.

## 19. File-drop ingestion: one directory + one flow per file type — built Aug 2026

Ward dropped a personal medical-history document (`ward_medical_visits_chronology.md`) into `ForClaude/` and asked how it should be ingested, and whether the file-drop mechanism should generalize to handle multiple file types or stay split by directory.

**Decided: split by directory, one dedicated Node-RED flow per file type — not a generalized multi-format ingester.** Extends the precedent §14 already established (`/share/lucius_body_comp_import/`, its own single-purpose flow) rather than introducing a new pattern:
- Matches this project's existing convention of narrow, single-purpose flows (Oura Sync / GoDaddy Pull / Body Comp Import each stand alone) rather than one flow branching internally on file type — keeps unrelated parsing concerns (binary `.xlsx` via ExcelJS vs. structured-text YAML) separate instead of concentrated into one flow.
- Keeps the companion-test-flow convention (§2) meaningful — one flow, one test flow, one real parsing concern each, rather than one bloated test flow covering multiple unrelated formats.
- No file-type sniffing needed — which directory a file lands in *is* the declaration of what it is.

**New drop directory: `/share/lucius_medical_history_import/`** (same `/share` location already proven correct project-wide). **Built: `nodered/medical_history_import_flow.json`** — manual/on-demand trigger, same shape as Body Comp Import (§14): `exec`-based directory listing, process each file found, move to a `processed/` subfolder afterward.

- **Two sequential `split`/`join` pairs**, not nested — one over the file list (reading + YAML-parsing each file), one over the flattened `encounters[]` list (one `api/incident_push.php` POST each). Each pair fully resolves before the next begins, so this is the simpler sequential case, not the trickier nested-parts one — same pattern already proven in `oura_sync_flow.json`'s self-heal fix (§1).
- **Parses with Node-RED's CORE `yaml` node** (bundled, not a new dependency) — first use of it in this project's flows, confirmed via the companion test flow (`nodered/medical_history_import_test.json`) against an embedded YAML fixture rather than a real file, so the test needs nothing set up on the target HA instance to run.
- **`related_medications[0]`** (first-listed only) is what actually gets sent as `related_medication_name` — a real simplification from the schema's full list, flagged in the flow's own code comment as "best-effort" per this section's "Still open" below, not a considered final design.

**UNTESTED — built from the documented schema and proven patterns elsewhere in this project, never run against a live file or a live GoDaddy endpoint.**

### Ingestion format: structured YAML, schema documented here

The dropped file's original shape — a narrative markdown document mixing a summary table, prose "detailed encounter notes," an undated symptom list, and a research-gaps list, with per-entry evidence provenance (`clinical_record` / `ward_report` / `journal_synthesis`) and genuinely uncertain dates — isn't reliably machine-parseable as prose. Rather than have the Node-RED flow try to parse natural-language table cells, the import format is a **structured YAML file** that preserves everything the original captured, just shaped for deterministic parsing:

```yaml
schema_version: 1
subject: ward_bowman
compiled_date: 2026-08-20

encounters:
  - id: cabg-2013                # stable slug — the flow's idempotency key on reimport, since
                                  # incidents (unlike medications) have no natural key otherwise
    date: 2013-05-01             # most specific known date; padded to the 1st of the month/year
                                  # when only that much precision is known — see date_precision
    date_precision: year         # exact | month | year | unknown
    date_note: "free text explaining any conflict/uncertainty the padded date can't express"
    title: "Heart attack and CABG"
    category: cardiac            # cardiac | medical — WardStock's own incident categories;
                                  # assigned per-encounter by clinical nature, see mapping below
    encounter_type: hospitalization   # hospitalization | procedure | diagnostic | therapy_session
                                       # | prescriber_contact | symptom_context
    procedure: "..."
    reason: "..."
    outcome: "..."
    evidence: mixed              # clinical_record | ward_report | journal_synthesis | mixed
    evidence_note: "..."
    related_medications: []      # free-text medication names; flow attempts a name match against
                                  # `medications.name` to fill the proposed `related_medication_id`
                                  # (§11) when unambiguous, else stores the name as text only
    notes: "..."                 # from the original's "Detailed encounter notes" section

symptom_context:      # NOT tied to a specific dated encounter — kept for reference,
                       # never becomes an incidents row (no occurred_at to give it)
  items: ["hand shaking", "chest tightness or pressure", "..."]
  note: "..."

open_research_items:  # a to-do list for filling historical gaps later — not medical data,
                       # never ingested into incidents/InfluxDB at all
  - "..."

source_documents: ["..."]
```

**Mapping to `incidents`, corrected per Ward (Aug 2026) — category is per-encounter, not a blanket rule:**
- Each `encounters` entry carries its own explicit `category` field (`cardiac` | `medical`), assigned by clinical nature, not lumped uniformly into `medical`. **Cardiac events** — the 2013 heart attack/CABG, the January 2020 PCI/stents, the 2024 stress test, and the 2024 carotid/subclavian imaging (ordered specifically to check the CABG's LIMA graft) — go in as `category: cardiac`, tying this historical record into the same category WardStock's live cardiac-incident tracking already uses. **Everything else non-cardiac** — the 2013 GI bleed/colonoscopy, the October 2020 eye exam, the May 2025 brain MRI, the August 2025 pre-op assessment/colonoscopy, and the August 2026 prescriber contacts — goes in as `category: medical`.
- `occurred_at` — the padded `date`, at `00:00:00` (no time-of-day exists for historical records; real precision lives in `date_precision`/`date_note`, not implied by the stored timestamp).
- `medical_evaluation_notes` — `procedure` + `outcome` + `evidence_note`, concatenated with labels so the source of each claim stays visible even inside one text field.
- `free_notes` — `notes` + `date_note` when present.
- `medical_evaluation` — `yes` for every ingested entry (all of these are, definitionally, medical evaluations).
- `stomach_sensation`/`flu_symptoms_sensation`/`lethargy_sensation` — left unset for historical imports; nothing in this dataset maps to them cleanly.
- `symptom_context` and `open_research_items` are **not ingested as incidents** — no date to hang them on, and the latter is meta/research tracking, not health data. Both stay in the YAML file as documentation, simply not read by the incidents-writing part of the flow.

**Resolved:** idempotency on reimport is upsert-by-`external_ref` (`incidents.external_ref`, new column, unique — `api/incident_push.php`), not skip-if-exists — a rerun updates the row in place rather than leaving it stale. `related_medications` name-matching is built, but only the **first-listed** medication per encounter (`related_medications[0]`) — flagged in the flow itself as best-effort, not the full list PLAN.md originally described; worth a real look if any encounter ever needs more than one.

**Still open:** two genuinely ambiguous items from the source document, not forced into `encounters` without a decision: the post-heart-attack panic-like episodes (no anchoring date at all, not even a year — kept in `symptom_context` rather than fabricating a date) and the 2026 individual/couples therapy content (arguably belongs in the existing `therapy_sessions` table rather than `incidents`, but the source has no per-session dates to create real rows with — kept as reference text for now rather than forced into either table).

**Why the panic episodes and the therapy content are kept together, not just filed separately (Ward, Aug 2026):** the post-heart-attack panic-like episodes aren't incidental background history — they're the actual thing this whole project exists to help address: capture the pattern, then give Ward something proactive to work on. The individual therapy, couples therapy, and Ward's own self-directed therapy work are the concrete actions already underway toward that, not unrelated encounters that happen to share a document. Worth keeping that link visible rather than letting the schema flatten it into disconnected reference entries — see the `self_therapy` addition to `therapy_history_reference` below.

## 20. wherewhen data export/backup and restore — built Aug 2026 (success path only — see "Known gap" below)

Ward's ask: a way to back up everything HA/`wherewhen` holds — InfluxDB plus what's already been pulled from GoDaddy — to file, so a wiped or migrated HA install can be brought back to where it was rather than starting over. **This is a different backup direction from §4's existing one**, worth being explicit about so the two don't get conflated:
- **§4 (already built)** protects **GoDaddy's MySQL database** — HA pulls incidents/Daily Log/Therapy/medications every 15 minutes and archives the full raw JSON response to `/share`, restorable through WardStock's own `import.php` if GoDaddy's DB is ever lost. Direction: GoDaddy → file → GoDaddy.
- **§20 (this section, new)** protects **everything that only lives in InfluxDB** — the high-fidelity Oura archive, decomposed heart_rate/hrv/sleep-stage series, body composition, `correlation_results` (§11), `sync_job_runs`, `system_status_snapshot`, and now the medical-history import (§19) — none of which has any backup at all today. If HA itself is wiped or moved to new hardware, this data is gone unless it's been exported. Direction: InfluxDB → file → a fresh InfluxDB.

**Two new flows, built.** `wherewhen_data_export_flow.json`: manual trigger + weekly schedule (Sunday 3am), both wired to the same start. `wherewhen_data_restore_flow.json`: manual trigger only (deliberately — this is explicitly for the wipe/migrate scenario against a freshly bootstrapped InfluxDB, not routine).
- **Export** enumerates InfluxDB measurements **dynamically** via Flux's `schema.measurements()` — the first place any of this project's flows has ever *queried* InfluxDB, every other flow only ever writes. For each measurement, runs a full-range Flux query and writes InfluxDB's own CSV response body directly to `/share/lucius_data_backup/<timestamp>/<measurement>.csv` — no manual CSV conversion needed, InfluxDB's query API already returns annotated CSV natively. Also writes `manifest.json` (measurement list + rough row counts) for Restore's verify mode.
- **Restore** reads the exported CSVs back and rewrites InfluxDB via `/api/v2/write` line-protocol. GoDaddy-side restore is **not reinvented here** — stays `import.php` reading the existing §4 JSON archive; this flow is strictly the InfluxDB-only half.

**Format: CSV, one file per measurement — decided as recommended, no `.xlsx` built.** No new Node-RED dependency — InfluxDB's query API already returns CSV natively, so this ended up even simpler than "plain string-building in a function node." `.xlsx` stays a possible future option, not built.

**Companion test flow — resolved as BOTH, not either/or.** Built `wherewhen_data_restore_test.json` (exercises the CSV→line-protocol parser against a hand-built two-table-block fixture, PLAN.md §2's standing rule) **and** the verify-mode discussed below — belt and suspenders, given this section's own assessment that disaster-recovery tooling is the highest-stakes flow in the project.

**Verify mode — resolved:** after restoring, Restore runs a Flux `count()` per measurement and compares against the manifest's row_count — a **ballpark sanity check** (manifest counts include CSV header/annotation lines, so exact equality was never the goal), flags large discrepancies (zero when something was expected) via `node.warn` and the HA `last_attempt_ok` helper, not silent trust that the write succeeded.

**The riskiest part, flagged prominently in the flow's own tab info, not glossed over:** reconstructing line-protocol from InfluxDB's annotated CSV — handling multiple table/header blocks per response (Flux groups different tag-combinations of one measurement into separate blocks) — is **reasoned from documented Flux CSV format, not confirmed against a real InfluxDB response**, since no live instance was available during development. The companion test flow confirms the parser does what it's designed to do against a fixture built from documentation; that is explicitly *not* the same as proof against real output. Also: writes one field per line-protocol point rather than recombining a series' multiple fields into one line, relying on InfluxDB's own documented same-timestamp/same-tags write-merge behavior rather than doing that merging itself — and does a naive comma-split per CSV row, **not** RFC4180-quote-aware, so a field value containing a literal comma would currently break (none of this project's real field values are expected to contain one today, but flagged as a known simplification).

**Gap fixed (Aug 2026, validation pass) — all three new flows this session (Medical History Import, wherewhen Data Export, wherewhen Data Restore) now have a `job_failed` path.** Unlike Oura Sync/GoDaddy Pull/Body Comp Import (which wire from a node with a genuine native error output, e.g. `exceljs`'s second output), none of the nodes in these three flows have one — every function/file/exec node here is single-output on its main path. So each flow got a `catch` node (scope `null`, catches any node on that tab — Node-RED core, no new dependency) wired to a new `job_failed` function node that mirrors the existing `job_success` node's shape: writes a `sync_job_runs` failure point (`failure_code=flow_error`, with the caught error's message and source-node name as `detail`) and flips the relevant HA helpers (`lucius_mhi_*`/`lucius_wherewhen_export_*`/`lucius_wherewhen_restore_*`) to their failure state. **Still UNTESTED like the rest of these flows** — a `catch` node's exact caught-message shape (`msg.error.message`/`msg.error.source.name`) is standard modern Node-RED behavior but hasn't been confirmed against a real thrown error on Ward's own instance yet.

**Status page integration — done.** All three are now `nodered`-category rows in `system_status_reports`/`status.php` (§15), same as every other flow.

**Still open:** exact export-file retention (keep every timestamped run, or just the last N — echoes the same undecided retention question already sitting in §12 for other archives).
