# WardStock — GoDaddy piece setup

*Part of the **Lucius** project — see the top-level `README.md` for how this fits with the Home Assistant piece.*

"Taking stock of Ward." A private, single-user, password-protected health tracker: **Incidents** (anxiety & cardiac episodes), **Daily Log** (sleep/exercise/caffeine/alcohol/medication), **Medications** (start/end dates, dosage history), **Therapy** (sessions + recurring schedule + since-last-session report), a 7-day dashboard, and a JSON **Export**.

**Current version: 2.2.8 "Lucius"**

Single-user by design — one shared login (`app_user`), no per-person data separation. See "Is this multi-user?" at the bottom if you ever need to change that.

## Versioning

Every release has a version number, `Major.SQL.Code` (e.g. `2.0.0`), plus a fixed codename ("Lucius" for the whole 2.x line — a new codename only comes with a major version bump; the project was "Sidroh" for the 1.x line, before the Home Assistant piece existed). What each number means:

- **Major** — bumped only for a genuinely major overhaul. Resets SQL and Code to 0.
- **SQL** — bumped only when a release actually changes something in `sql/` (a new `alter_*.sql`, or a `schema.sql` change). Resets Code to 0.
- **Code** — bumped for every release that does *not* touch `sql/`. A SQL-changing release bumps SQL instead of this.

**Only the Major.SQL part is tracked in the database** — the `app_settings` table's `db_version` key stores just `"2.0"`, never the full three-part version, since a code-only release has nothing for the database to catch up on. A code-only release changes the app's displayed version number but requires no database action at all.

**Database upgrades are one file per major version line, not one file per release.** `sql/upgrade_from_<major>.0.0.sql` (e.g. `upgrade_from_2.0.0.sql`) is a living, cumulative script covering every SQL change made anywhere in that major version's line — safe to run regardless of which specific release your database is actually on, including a release that's already fully current (every step checks whether it's already applied before doing anything, so re-running is always a safe no-op for whatever's already there). See "Upgrading an existing install" below.

`debug.php` (linked at the bottom of the Export page) shows the full app version, the Major.SQL "schema revision" that's actually compared, and the database's recorded schema revision — flagging a mismatch only when Major.SQL disagrees. The Code number is expected to differ from whatever's in the database at any given moment; that's normal, not a mismatch.

## Zip layout (this piece)

This folder (`godaddy/`) is one of two pieces in the overall **Lucius** project — see the top-level `README.md`. Everything below is relative to *this* folder; this README and `PROJECT_PLAN.md` live here too (documentation only — neither gets uploaded to the server):

- **`app/`** — every file that actually runs the site *except* `config.php`. Upload the *contents* of this folder directly into `public_html/Wardstock/` (not the `app` folder itself — its files belong flat in your site folder, same level as everything else). **Safe to blindly re-upload every file in here on every update** — it never contains your real credentials.
- **`config/`** — just `config.php` (your real DB/Oura credentials) and a `.htaccess` blocking direct web access to it. Upload this **once**, as its own subfolder — `public_html/Wardstock/config/` (keep the folder, don't flatten it like `app/`). **Never re-upload this folder as part of a routine update** — that's the entire point of splitting it out: a blanket "upload everything" can't accidentally overwrite your real settings with the blank template, since it lives somewhere that kind of update never touches.
- **`sql/`** — every `.sql` file. Never uploaded via FTP — paste these into phpMyAdmin's SQL tab instead. `schema.sql`/`reset_clean.sql` are for a fresh install. `upgrade_from_<major>.0.0.sql` is for an existing install with real data — one cumulative, safe-to-re-run file per major version line (see "Upgrading" below), not a growing pile of per-release fragments.
- **`setup-delete-after-use/`** — `setup.php` and `reset_password.php`. Upload these into `public_html/Wardstock/` alongside `app/`'s contents *only when you're about to use one of them*, then **delete it from the server immediately after** — leaving either reachable is a real security hole, since neither requires knowing the current password to act.
- **`demo/`** — optional kiosk-style walkthrough with synthetic sample data, no real login needed. Its own subfolder — `public_html/Wardstock/demo/` — same "not flattened" pattern as `config/`. Fully inert until you set it up; see `demo/README.md`.

## Setup (fresh install — no existing data)

1. In cPanel, go to **MySQL Databases**, create a database + user with **All Privileges**.
2. In phpMyAdmin, run `sql/reset_clean.sql` (drops-if-exists and recreates every table, seeds the `medications` table from your medical journal, and records the current `db_version`).
3. Upload everything from `app/` into `public_html/Wardstock/` (match your existing folder casing — Linux hosting is case-sensitive).
4. Upload `config/` as its own subfolder — `public_html/Wardstock/config/` — keeping the folder structure intact (this is the one exception to "everything goes in flat"). Edit `config/config.php` with your `DB_HOST`/`DB_NAME`/`DB_USER`/`DB_PASS`, and set `APP_SECRET` to a random string.
5. Upload `setup-delete-after-use/setup.php` into `public_html/Wardstock/`, visit it to create your login, then **delete it from the server**.
6. Log in at `login.php`.

## Upgrading an existing install (you have real data)

At most **two** files to run in phpMyAdmin, no matter how many releases you're behind:

- **Already on some 2.x.x release, upgrading to a newer 2.x.x** → run `sql/upgrade_from_2.0.0.sql`. Safe even if you're already fully current (every step checks first, does nothing if already applied).
- **Still on a 1.x.x release, upgrading to 2.x.x** → run `sql/upgrade_from_1.0.0.sql` first (brings you to the end of the 1.x line), then `sql/upgrade_from_2.0.0.sql`.
- **A future 3.x.x line** would add its own `sql/upgrade_from_3.0.0.sql`, run after the 2.x.x one, same pattern.

All additive only, never touching existing incidents/daily logs/medications. Then re-upload the changed files from `app/` — safe to upload the whole folder blindly, since `config.php` isn't in there anymore. **Never re-upload the `config/` folder** as part of a routine update; it's only touched during initial setup or if you're deliberately changing a credential. Check `debug.php` afterward to confirm the app's schema revision (Major.SQL, not the full version) agrees with the database's.

**After running the medication-frequency migration specifically**, go to **Medications** and check Wegovy and Repatha: their due-date calculation anchors to `start_date`, and the seed date may not land on your actual dose day. Edit each one and set `start_date` to a real date you took a dose so the weekly/biweekly cycle lines up correctly.

## Pages

| Page | Purpose |
|---|---|
| `config/config.php` | DB/Oura credentials — lives in its own folder, not `app/`, see Zip Layout above |
| `index.php` | Dashboard — 7-day summary (today first, going back 6 days), section cards, recent activity |
| `incidents.php` / `incident_form.php` | Anxiety & Cardiac incidents |
| `daily.php` / `daily_form.php` | Daily Log — sleep, weight, exercise, caffeine, alcohol, medication |
| `weight_trend.php` | 60-day weight trend chart (SVG, no external dependency) |
| `weight_deviation.php` | Weight bar chart, deviation from the selected range's own average (7/30/90 days, Chart.js) |
| `status.php` | Lucius project — HA/Node-RED/Analytics status view, fed by the Home Assistant piece's Status Heartbeat flow |
| `about.php` | App purpose, version, what's tracked, LeeWard/founders, links to Privacy/Terms/Debug. Public, no login — linked from the login screen footer. |
| `marketing.html` | LeeWard/WardStock flyer, plain static HTML. Public, no login — linked from the login screen footer. |
| `medications.php` / `medication_form.php` | Medication list — start/end dates, dosage history, actual due-date recurrence |
| `therapy.php` / `therapy_form.php` | Session log + since-last-session report |
| `therapy_schedules.php` / `therapy_schedule_form.php` | Recurring therapy plan (drives dashboard reminders) |
| `export.php` | JSON export, all-time or since-last-export |
| `debug.php` | App/database version check + basic environment info (linked at bottom of Export) |
| `app_version.php` | Version constants (not a page — included by debug.php) |
| `import.php` | Restore/merge data from a WardStock export JSON |
| `oura_connect.php` / `oura_callback.php` | Oura OAuth2 connection flow |
| `oura_sync.php` | Pull a date's data from Oura into the Daily Log |
| `oura_test.php` | Diagnostic page — config checks, connectivity test, live token/API tests with raw responses |
| `oura.php` | Shared Oura API/token helpers (not a page — included by the three above) |

## The 7-day dashboard

Each pill is icon-first (native emoji, no external library) so it reads at a glance on a phone: ℞ Incidents, 🏋️ Exercise, ☕ Caffeine, 🍷 Alcohol, 💊 Medication, ⚖️ Weight, 🧠 State of Mind. Text is kept to just the value where there is one — color carries most of the meaning.

Each day shows, most recent first:
- **🧠 State of mind** — color-graded Unpleasant→Enjoyed.
- **℞ Incidents** — green icon only if nothing happened that day, amber with a count if something did. Always clickable to add an incident for that date.
- **🏋️ Exercise** — green if steps, exercise minutes, or standing minutes were entered that day; red if none were. Not expected every day, but worth logging so a genuine zero is distinguishable from "forgot to check."
- **☕ Caffeine and 🍷 Alcohol** — same unified scale for both: **black** below 1 unit (covers both "not entered" and a genuinely low/zero amount — a made-but-unfinished cup counts as 0.5), **green** 1–2, **yellow** above 2 up to 4, **red** above 4.
- **💊 Medication** — three states: green if nothing was due that day, **red if nothing at all was entered** (icon only, no "missing" text), **yellow if some but not all due medications were taken**, green once everything due is checked off. Checks real recurrence (daily/weekly/biweekly via `frequency_days`), not just whether the prescription is generally active — see Medications below. Nitroglycerin and other as-needed medications never appear here — they're logged from a Cardiac incident instead.
- **⚖️ Weight** — red if not entered (icon only, no "not entered" text), green with the value if logged. A "View trend" link near the top of the dashboard (and again on the Daily Log's Weight section) opens a 60-day trend chart with latest/change/average/entry-count stats.
- **Therapy (due)** — only appears on days a recurring schedule says a session was due; green once logged, red as a reminder until you do.

Every non-green pill (and the state-of-mind pill) links straight to that day's Daily Log entry, jumping to the relevant section instead of the top of the form.

## Incidents

Two categories — **Anxiety** and **Cardiac** — chosen via the "+ Anxiety" / "+ Cardiac" buttons on the incidents list, or a toggle at the top of the form (switching it shows/hides the relevant fields via JavaScript). Cardiac incidents get a **Nitroglycerin taken** checkbox instead of the anxiety-specific trigger/thoughts/intensity fields; both share the same chest/arm/shoulder/headache/shaking severity selectors, duration, recovery, and medical-evaluation fields.

Each incident has a **start time and an optional end time** — entering one auto-calculates the other (and duration), so you only need to fill in whichever two you actually know. For a brand-new incident with no date pre-filled, the start time defaults to your browser's actual local time via JavaScript — not the server's clock, which may be set to a different timezone (GMT/UTC on most shared hosting); PHP also seeds a safe fallback so the field is never truly empty even if that script can't run for some reason.

Incidents are events, not daily entries — there can be zero, one, or several on a given day. The form for any given day shows the entry form on top, an always-visible **"+ Anxiety / + Cardiac"** row for adding another one for that same day (important: once you're viewing/editing a saved incident, re-filling the form and hitting Save *edits that same incident* rather than creating a new one — use these buttons to start fresh instead), and a table of that day's *other* incidents below, each linking back into the form to edit. A green "✓ Saved" banner confirms every successful save.

The **Medication changes** section on the incident form is read-only — it automatically lists anything started, dosage-changed, or stopped in the `medications` table within the 7 days before the incident, pulled from Medications rather than typed by hand.

## Daily Log details

**Sleep** is entered as separate Hours and Minutes fields (e.g. 7 and 23 for "7 hours 23 minutes") rather than one decimal-hours field — it's converted to decimal internally for storage, but every display (the Daily Log list, the Therapy report, the dashboard's recent activity) shows it back as "7h 23m," not a raw decimal.

**Caffeine servings** — 1 unit ≈ 1 teabag (~45mg). A small coffee or a single espresso shot ≈ 2 units (espresso is more concentrated per ounce, but a brewed coffee's larger serving generally lands in the same range). A medium coffee or a double espresso ≈ 3 units. Large coffee or energy drink ≈ 4+ units. Approximate, for spotting personal patterns — not a precise caffeine-content calculator.

## Medications

Each row is a specific dosage of a specific medication, with a start date and (once discontinued) an end date. **A dosage change is a new row**, not an edit to the old one — from an active medication's edit page, "Start a new dosage" pre-fills a new entry and automatically closes out the old one the day before the new one starts, so the full history stays intact.

Each medication is flagged **Scheduled** or **As needed**, and Scheduled medications also have a **"due every N days"** value (1 = daily, 7 = weekly, 14 = biweekly, with quick-pick shortcuts on the edit form) that actually drives the Daily Log checklist and dashboard — not just the descriptive cadence label. Only medications genuinely due on a specific date appear in that day's checklist (start date passed, end date not yet reached, *and* the day lines up with the recurrence), so backfilling a past day or checking today correctly reflects what was actually — or will actually be — taken, not a blanket "every scheduled med, every day." **Because this math anchors to `start_date`, that date needs to be an actual dose date for non-daily medications**, not an arbitrary prescription-start date — otherwise the weekly/biweekly cycle will be offset from your real schedule. As-needed medications like Nitroglycerin don't appear in the Daily Log at all; they're logged via a Cardiac incident instead.

## Therapy

**Sessions** (`therapy.php`/`therapy_form.php`) are what actually happened — individual, couples, or other, with mood before/after, summary, insights, homework.

**Schedule** (`therapy_schedules.php`) is separate: a recurring plan — type, start date, and a repeat interval in days. The dashboard checks each active schedule against the last 7 days; if a due date has passed with no matching session logged, it shows as a red reminder pill; once logged, it turns green.

The top of `therapy.php` shows a **report**: everything since your last logged session — incident count (broken out by anxiety/cardiac, each clickable straight through to that incident), days logged vs. days elapsed, and averages/totals for sleep, resting HR, HRV, state of mind, steps, exercise minutes, caffeine, and alcohol. No need to go back to the dashboard to drill into any of it.

## Exporting your data

`export.php` — choose **all records** or **since your last export**, and which sections to include. Produces one JSON file, tagged by `record_type`, meant to hand to an AI assistant (or anything else that reads JSON) for analysis. Medication history and the recurring therapy schedule are intentionally left out of the export — but each Daily Log record's `medications_taken` field lists the actual medication *names* taken that day (resolved from the IDs internally, not shipped as a separate reference table), so scheduled-medication adherence is still fully captured. Nitroglycerin and other as-needed medications are captured separately, via the `nitroglycerin_taken` field on Cardiac incidents. Also includes a `state_of_mind_scale` legend so coded values can be decoded downstream.

## Oura Ring integration (optional)

Pulls sleep duration, sleep efficiency, resting heart rate, HRV, and steps directly from Oura instead of typing them in. **Oura requires OAuth2** — they retired simple API-key access in December 2025 — so this needs a one-time setup step on Oura's side that only you can do:

1. Go to [cloud.ouraring.com/oauth/applications](https://cloud.ouraring.com/oauth/applications) and create a free developer application.
2. Set its redirect URI to **exactly** match `OURA_REDIRECT_URI` in `config/config.php` — by default that's `https://YOURDOMAIN.com/Wardstock/oura_callback.php`; edit `config/config.php` first if your real domain differs, then register that exact value with Oura.
3. If the registration form asks for a Privacy Policy or Terms of Service URL, use `privacy.php` and `terms.php` (see below) — both are public pages, reachable without logging in, since Oura's review process needs to see them.
4. Copy the Client ID and Client Secret Oura gives you into `config/config.php`'s `OURA_CLIENT_ID` / `OURA_CLIENT_SECRET`.
5. Re-upload `config/config.php`, then visit `oura_connect.php` (or the "Pull from Oura" link on the Daily Log page) to authorize.

Once connected: **"⬇ Pull from Oura"** (on the Daily Log list, and on each entry's form) fetches that date's data, saves it, and lands you on the Daily Log form with those fields filled in — you then add Weight, Caffeine, Alcohol, and Medications (none of which Oura tracks) and save as normal. Like the Import feature, this **merges rather than overwrites** — pulling Oura data for a day that already has other fields filled in won't touch them.

**What's pulled and how it's mapped** (documented here because it couldn't be tested against a live Oura account during development — verify against your real data once connected, and flag anything that looks off):
- `sleep_duration_hrs` ← total sleep time from `/sleep`
- `sleep_efficiency` ← efficiency % from `/sleep`
- `resting_hr` ← lowest overnight heart rate from `/sleep` (Oura's daily readiness score only exposes a normalized 1–100 contributor value for resting HR, not an actual bpm, so this uses the sleep endpoint instead)
- `hrv` ← average HRV from `/sleep`
- `steps` ← from `/daily_activity`

**Not pulled:** Weight (Oura doesn't track it — that's what the Apple Health import is for) and Exercise/Standing minutes (Oura has no direct equivalent to Apple's Move/Exercise/Stand rings, and the closest available fields weren't confirmed against real data, so they're left for manual entry rather than risking a silently-wrong mapping).

**Ring info:** whenever connected, `oura_sync.php` shows your ring's model/generation, color, size, firmware, and setup date (from Oura's `ring_configuration` endpoint). If your account ever has more than one ring registered, all of them are listed — only the one currently set "active" in the Oura app actually syncs data.

**If a date pulls back "no data found,"** the page now shows exactly what happened per endpoint (Sleep / Daily Activity / Daily Readiness) instead of one generic message — whether each call failed outright (with the HTTP status and Oura's raw error response) or succeeded but genuinely had nothing for that date. A failed call points to a token/scope/config problem worth checking on `oura_test.php`; a successful-but-empty call usually just means the ring hasn't synced that data yet (sleep/readiness need the Oura app opened; activity syncs in the background on its own schedule).

`privacy.php` and `terms.php` — public pages (no login required, unlike everything else in the app) stating this is a single-user personal application: no data collected from anyone but the owner, nothing shared with third parties, and no other person authorized to use it. Exist mainly because Oura's developer application registration asks for these URLs; linked from the login screen footer too.

Access tokens expire roughly every 24 hours and refresh automatically and transparently on the next pull; if the refresh token itself has expired or been revoked (e.g. you disconnected the app from Oura's side), you'll see a "reconnect" prompt.

Both `oura_sync.php` and `oura_test.php` show **last successful connection** and **last attempt** (succeeded or failed, with timestamp) — tracked automatically every time the app actually talks to Oura's servers (a data pull, an automatic token refresh, or a manual test on the diagnostic page), so you can tell "it's been silently failing" from "it just hasn't run in a while" at a glance.

**If something isn't working**, `oura_test.php` (linked from the setup instructions and the reconnect prompt) runs through: whether curl/HTTPS are available at all, whether your config values are set (secrets shown masked, never in full — safe to share the page's output for help troubleshooting), a raw connectivity check to Oura's servers (catches shared-hosting outbound-HTTPS restrictions, which would otherwise look identical to a credentials problem), a live token-refresh test, and a live test API call — each showing the actual HTTP status code and raw response body from Oura, not just "it failed."

## Importing data

`import.php` (linked from the Export page) — restores data from a WardStock export JSON, or any hand-built JSON file following the same shape (`{"records": [{"record_type": "incident"|"daily_log"|"therapy_session", ...fields}]}`). Meant for rebuilding after a database reset, catching up a fresh install from an old backup, or bringing in external data (e.g. an Apple Health weight history formatted into daily_log records).

- **Daily Logs and Therapy sessions** are matched by date (and type, for therapy). If a matching entry already exists, it's **merged, not overwritten** — only fields actually present and non-null in the import file replace what's there; anything the import file doesn't mention keeps its existing value. This makes it safe to import a partial file (like a weight-only history) without wiping out sleep/caffeine/medication data already logged for those days. Medications specifically are only touched if the import record includes a `medications_taken` key at all — an absent key preserves the existing checklist rather than treating "not mentioned" as "confirmed nothing taken."
- **Incidents have no reliable natural key and are always inserted as new** — there's no safe way to detect "this is the same incident as before," so importing the same file twice will create duplicate incidents. Only import a given file once, or clean up duplicates manually afterward.
- The whole import runs as one database transaction — if anything fails partway through, nothing is saved, rather than leaving a half-imported mess.
- Medication names in the import that don't match anything in your current Medications list are skipped (with a warning shown after import) rather than silently guessed at — most likely means a medication was renamed since the file was made.

## Add it to your home screen — iPhone, Android, or Kindle Fire

Not a native App Store/Google Play app (that needs Xcode/Android Studio and a developer account per store) — but it installs as a home-screen web app on all three:

- **iPhone/iPad (Safari):** Share icon → Add to Home Screen.
- **Android (Chrome):** ⋮ menu → Add to Home screen / Install app.
- **Kindle Fire (Silk):** menu → Add to Home Screen (Fire OS is Android-based, same mechanism).

## Security notes

- `.htaccess` forces HTTPS and blocks direct `.sql`/`.md` downloads.
- Single hashed-password login (PHP `password_hash`/`password_verify`) — fine for personal use, not built for separate accounts. See "Is this multi-user?" below.
- Consider cPanel Directory Privacy on the whole folder for defense in depth, given the sensitivity of what's stored here.
- The exported JSON file is unencrypted wherever you save it — treat it with the same care as the app itself.

## Is this multi-user?

No. One login, no `user_id` column anywhere, so anyone with the password sees and edits the same data as everyone else. That's fine for how it's used today, but if that ever needs to change — e.g. giving Lisa her own separate login with her own data — that requires adding a `users` table, a `user_id` column on every table, and filtering every query by the logged-in user. Worth raising early if this becomes relevant, since it touches nearly every file.
