# Lucius — rebuild prompt

Concrete facts needed to rebuild this project as of its current version.
Deliberately no history or reasoning — see `RETROSPECTIVE.md` for how it
got here, and `godaddy/PROJECT_PLAN.md` / `homeassistant/PLAN.md` for the
decision log behind any specific design choice. This file states what
exists now, not why.

**Current version: 2.2.6 "Lucius"**

---

## What it is

Two independent pieces, one project:

1. **`godaddy/`** — WardStock: a private, single-user, password-protected
   PHP + MySQL web app on GoDaddy shared hosting. No framework, no
   Composer, vanilla PHP/CSS/JS, PDO for MySQL. Always-reachable system of
   record for hand-entered health data.
2. **`homeassistant/`** — a Node-RED + InfluxDB background sync/analytics
   engine on Home Assistant OS. Talks outbound only (to Oura's API, to
   WardStock's API) — never needs to be reachable from outside.

Not multi-user: one shared login, no `user_id` column anywhere in the
schema.

---

## GoDaddy piece — file map

Deployed to `public_html/Wardstock/` (case-sensitive hosting — folder is
`Wardstock`, capital W).

| Path (relative to `godaddy/`) | Contents |
|---|---|
| `app/` | Every file below except `config.php` — flattened directly into `public_html/Wardstock/` |
| `config/config.php` | Real DB/Oura/API credentials — its own subfolder, never flattened, never blindly re-uploaded |
| `config/.htaccess` | `Require all denied` on the config folder |
| `sql/` | `schema.sql` + `reset_clean.sql` (fresh install), `upgrade_from_<major>.0.0.sql` — one cumulative, idempotent, safe-to-re-run script per major version line (see Versioning below) |
| `setup-delete-after-use/setup.php`, `reset_password.php` | Unauthenticated action pages — upload only when needed, delete from the server immediately after |

**`app/` files:**

| File | Purpose |
|---|---|
| `db.php` | PDO connection (`get_db()`); shared helpers: `medication_due_on()`, `fmt_hours_minutes()`, `log_ha_sync()`, `get_setting()`/`set_setting()`, `build_export_records()`, `push_weight_if_unset()` |
| `auth.php` | `require_login()` (session-cookie auth for human pages), `check_api_token()` (bearer-token auth for `api/*.php`) |
| `app_version.php` | Version constants — `APP_VERSION_MAJOR`/`_SQL`/`_CODE`, `APP_VERSION_SCHEMA` (Major.SQL), `APP_VERSION` (full three-part) |
| `login.php` / `logout.php` / `setup.php`* / `reset_password.php`* | Auth flow (*lives in `setup-delete-after-use/`) |
| `index.php` | Dashboard — 7-day summary, today first |
| `partials_nav.php` | Shared nav bar |
| `incidents.php` / `incident_form.php` | Anxiety & Cardiac incidents |
| `daily.php` / `daily_form.php` | Daily Log |
| `weight_trend.php` | 60-day weight line chart (inline SVG, no dependency) |
| `weight_deviation.php` | Bar chart of weight deviation from a selected range's (7/30/90 day) own average — Chart.js via CDN |
| `medications.php` / `medication_form.php` | Medication history |
| `therapy.php` / `therapy_form.php` | Session log + since-last-session report |
| `therapy_schedules.php` / `therapy_schedule_form.php` | Recurring therapy plan |
| `export.php` | JSON export (all-time or since-last) |
| `import.php` | Restore/merge from an export-shaped JSON |
| `debug.php` | App/DB version check + environment info |
| `oura.php` | Shared Oura API/token helpers (not a page) |
| `oura_connect.php` / `oura_callback.php` / `oura_sync.php` | Oura OAuth2 flow + manual pull |
| `oura_test.php` | Oura + HA-sync diagnostics |
| `status.php` | HA/Node-RED/Analytics status view (reads `system_status_reports`) |
| `about.php` | App purpose, version, architecture overview, LeeWard/founders. Public, no login. |
| `marketing.html` | LeeWard/WardStock flyer — plain static HTML (moved from a separate `marketing/` folder so it ships with the app), fully self-contained (fonts via CDN, logo embedded as base64). Public, no login. |
| `privacy.php` / `terms.php` | Public, no-login pages (Oura registration requirement) |
| `leeward-badge.png` | Round LeeWard emblem, cropped from `marketing/leeward_logo_transparent.png` — used on `about.php` for the company and both founder placeholder photos |
| `style.css` | All styling — dark theme, CSS custom properties (`--bg`, `--text`, `--muted`, `--accent`, `--danger`, `--mild`, `--moderate`, `--severe`, `--border`, etc.) |
| `manifest.json` + icon PNGs | PWA home-screen install |
| `api/oura_push.php` | Token-auth. HA POSTs Oura summary fields per date. |
| `api/pull_manual_data.php` | Token-auth. HA GETs full incidents/daily_logs/therapy_sessions/medications. |
| `api/status.php` | Token-auth. Reachability + version-sync check. |
| `api/weight_push.php` | Token-auth. HA POSTs `{date, weight_lb}` — only writes if `daily_logs.weight` is currently NULL. |
| `api/status_push.php` | Token-auth. HA POSTs a `{reports: [...]}` batch — upserts `system_status_reports`. |
| `api/get_shared_config.php` | Token-auth. HA GETs `{oura_client_id, oura_client_secret}` — GoDaddy is the source of truth, HA no longer stores its own copy. |

## GoDaddy piece — database schema

Tables (see `sql/schema.sql` for full column lists/types):

- `incidents` — anxiety/cardiac episodes. `category`, `occurred_at`, `ended_at`, severity fields, `nitroglycerin_taken`, free-text fields, `updated_at`.
- `daily_logs` — one row per day (`log_date`, no DB-level unique constraint but app code treats it as the natural key). Sleep, `weight`, exercise, caffeine/alcohol, `medications_taken_json`, `state_of_mind`, `updated_at`.
- `medications` — `name`, `dosage`, `med_type` (scheduled/as_needed), `frequency_days`, `start_date`, `end_date` (NULL = active). A dosage change is a new row, not an edit.
- `therapy_sessions` — session log. `therapy_schedules` — recurrence plans (`frequency_days`).
- `oura_tokens` — single-row OAuth token store + `last_success_at`/`last_attempt_at`/`last_attempt_ok`.
- `app_settings` — key/value (`db_version`, `last_export_at`, `last_ha_pull_at`).
- `app_user` — single login.
- `ha_sync_log` — every call to any `api/*.php` endpoint. `endpoint`, `status_code` (`success`/`auth_invalid`/`malformed_request`/`validation_error`/`db_error`/`unknown_error`), `detail`, `called_at`.
- `system_status_reports` — one row per `(category, component)` UNIQUE key. `category` (`ha`/`nodered`/`analytics`), `component`, `last_run_at`, `last_status`, `last_error`, `detail`, `expected_frequency_minutes`, `reported_at`.

## GoDaddy piece — versioning

Scheme: `Major.SQL.Code`, plus a fixed codename per major version ("Lucius" for 2.x).

- **Major** — genuinely major overhaul only. Resets SQL and Code to 0.
- **SQL** — bump only when a release changes `sql/`. Resets Code to 0. **Only Major.SQL is stored in the database** (`app_settings.db_version`).
- **Code** — bump for every release that doesn't touch `sql/`.
- `debug.php` compares `APP_VERSION_SCHEMA` (Major.SQL) against the DB's `db_version` — a code-only release is expected to show a different full version than the DB, and that's correct.
- Database upgrades: `sql/upgrade_from_<major>.0.0.sql` — one cumulative, idempotent file per major version line (currently `upgrade_from_1.0.0.sql` and `upgrade_from_2.0.0.sql`), appended to as new releases within that line change `sql/`, safe to run from any point in that line including an already-current database. Crossing a major-version boundary means running the previous major's file first, then the current one — at most two files for any upgrade.

## GoDaddy piece — auth

Two independent mechanisms in `auth.php`:
- `require_login()` — session cookie, for every human-facing page.
- `check_api_token()` — `Authorization: Bearer <API_SYNC_TOKEN>` (from `config.php`), for `api/*.php` only. Reads the header with fallbacks (`HTTP_AUTHORIZATION`, `REDIRECT_HTTP_AUTHORIZATION`, `getallheaders()`, `apache_request_headers()`) plus a `.htaccess` rewrite rule re-injecting it — needed on some Apache/FastCGI shared-hosting configs that otherwise strip it.

---

## Home Assistant piece — file map

| Path (relative to `homeassistant/`) | Contents |
|---|---|
| `README.md` | Setup steps |
| `PLAN.md` | Full design doc, all 16 sections |
| `INFLUXDB_V2_SETUP.md` | Confirmed-working InfluxDB v2 add-on setup — catalog repo, org/bucket wizard, scoped API token, add-on config YAML |
| `lucius_secrets.json.example` | Template for `/share/lucius_secrets.json` on the HA box |
| `ha_config/helpers.yaml` | HA helper entities (`input_datetime`/`input_boolean`/`input_text`) the flows read/write |
| `ha_config/dashboard.yaml` | Lovelace card for the same entities |
| `nodered/oura_sync_flow.json` | Every 4h, anchored 10am. Oura pull → InfluxDB → GoDaddy `oura_push.php` |
| `nodered/godaddy_pull_flow.json` | Every 15min. `pull_manual_data.php` → InfluxDB + local disaster-recovery archive |
| `nodered/body_comp_import_flow.json` | Daily ~noon. Parses `.xlsx` scale exports (`exceljs`/`node-red-contrib-officedocs`) → InfluxDB `body_composition` + `weight_push.php` |
| `nodered/status_heartbeat_flow.json` | Every 15min. Reads the other flows' HA helper entities → `status_push.php` + InfluxDB `system_status_snapshot` |
| `nodered/system_test_flow.json` | Manual diagnostic — config, internet, InfluxDB, Oura, GoDaddy reachability |
| `nodered/body_comp_xlsx_test.json` | Standing test flow confirming the `exceljs` read API (kept permanently, not deleted after use) |
| `sample_data/body_composition_sample.xlsx` | Sample scale export used to confirm the parsing API |

## Home Assistant piece — secrets

`/share/lucius_secrets.json` (never `/config` — inside the Node-RED
add-on's own container that's a different, unrelated directory from HA's
real config; use `/share`). Fields: `godaddy_base_url`,
`godaddy_api_sync_token`, `oura_client_id`/`_secret`, `oura_access_token`/
`_refresh_token`/`_expires_at`, `influxdb_url`/`_org`/`_bucket`/`_token`.
Does **not** include `oura_client_id`/`oura_client_secret` — GoDaddy's
`config.php` is the source of truth for those, fetched live via
`api/get_shared_config.php` each Oura Sync run.

Deliberately a **separate** file from `godaddy/config/config.php`, not a
shared config — different trust domains. `API_SYNC_TOKEN` is the one
value still manually copied between the two (unavoidable — it's the
bootstrap credential that authenticates fetching everything else).

## Home Assistant piece — InfluxDB measurements

- Per-flow raw sync data: incident/daily-log/medication fields written by `godaddy_pull_flow.json`.
- `sync_job_runs` — every flow writes `started` then `success`/`failed` points. Tags: `job_name` (`oura_sync`/`godaddy_pull`/`bodycomp_import`/`status_heartbeat`), `status`. Fields: `failure_code`, `detail`, `duration_ms`.
- `body_composition` — one point per day per reading (after collapsing to latest-per-day). Tags: `device_mac`, `device_name`. Fields: all 13 non-dropped scale metrics.
- `system_status_snapshot` — one point per `(category, component)` per heartbeat run. Field: `status_ok` (1/0).

## Home Assistant piece — HA helper entities

Per scheduled flow (`oura`, `godaddy`, `bodycomp` prefixes): `input_datetime.lucius_<prefix>_last_success`, `input_datetime.lucius_<prefix>_last_attempt`, `input_boolean.lucius_<prefix>_last_attempt_ok`, `input_text.lucius_<prefix>_last_error`. Written via `api-call-service` (never a direct state write — setting a helper entity is a service call: `turn_on`/`turn_off`, `set_value`, `set_datetime`).

---

## Cross-piece API surface (all in `godaddy/app/api/`, token-authenticated)

| Endpoint | Called by | Purpose |
|---|---|---|
| `oura_push.php` | Oura Sync flow (4h) | POST summary fields for a date |
| `pull_manual_data.php` | GoDaddy Pull flow (15min) | GET full incidents/daily_logs/therapy_sessions/medications |
| `status.php` | System Test flow (manual) | Reachability + version-sync |
| `weight_push.php` | Body Composition Import flow (daily) | POST `{date, weight_lb}`, never overwrites |
| `status_push.php` | Status Heartbeat flow (15min) | POST `{reports: [...]}` batch |

Every endpoint logs to `ha_sync_log` on every call, success or failure.
