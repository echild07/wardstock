# Demo mode — kiosk-style walkthrough, no real data

Lets someone click through the whole app with ~3 months of realistic-looking sample data, no login required, and nothing real ever in play. Same codebase as the live app — this isn't a second copy of any screen, just a separate database the app connects to when a "demo session" flag is set.

Documentation only — like `README.md`/`PROJECT_PLAN.md` one level up, this file itself never gets uploaded to the server.

## How it works

- `demo/index.php` is the one new file that lives in its own subfolder on the server. Visiting it sets `$_SESSION['demo_mode'] = true` and redirects into the real app (`index.php`, `incidents.php`, etc.) — same URLs you already use.
- `db.php`'s `get_db()` checks that session flag on every page load and connects to a **separate demo database** instead of the live one whenever it's set. Every screen is the literal same PHP file either way — there's no duplicated UI to keep in sync.
- Real writes work in demo mode (it's a real, isolated database) — someone can actually add an incident or fill out the Daily Log and see it work. `generate_demo_data.php` is how you reset it back to a clean slate whenever you want, not a write-blocker at the connection layer.
- A small banner appears on every page in demo mode so nobody mistakes sample data for anything real.

## One-time setup

1. **cPanel → MySQL Databases**: create a second database + user with All Privileges, same as the real app's original setup (`godaddy/README.md`'s "Setup (fresh install)" section) — just a different name, e.g. `wardstock_demo`.
2. **phpMyAdmin**: run `sql/reset_clean.sql` against the **new demo database** (not the live one). This creates the schema and seeds `medications` with WardStock's real default set — that's fine, `generate_demo_data.php` (next step) replaces it with fictional data anyway.
3. **Edit `config/config.php`** (the real one, already deployed) and fill in:
   ```php
   define('DEMO_DB_HOST', 'localhost');
   define('DEMO_DB_NAME', 'wardstock_demo');   // whatever you named it
   define('DEMO_DB_USER', '...');
   define('DEMO_DB_PASS', '...');
   define('DEMO_RESET_KEY', '...');            // any random string — gates the reset script
   ```
   Re-upload just `config/config.php` (same "never blindly re-upload the whole `config/` folder as part of a routine update, but this is a deliberate one-off edit" caveat as always).
4. **Upload `demo/index.php`** into `public_html/Wardstock/demo/` (its own subfolder, same pattern as `config/` — not flattened into the app root).
5. **Upload `demo/generate_demo_data.php`** alongside it, then visit it once in your browser with your reset key:
   `https://YOURDOMAIN.com/Wardstock/demo/generate_demo_data.php?key=YOUR_DEMO_RESET_KEY`
   This clears and re-seeds the demo database with ~3 months of fictional sample data (incidents, daily logs, medications, therapy sessions, blood pressure readings — a made-up persona, not Ward's real health data).
6. Visit `https://YOURDOMAIN.com/Wardstock/demo/` to confirm it works — should drop you straight into the dashboard with sample data, no login prompt. `login.php` also gets a "🎭 View interactive demo" link automatically once `DEMO_DB_NAME` is configured.

## Resetting later

Just re-visit the same `generate_demo_data.php?key=...` URL any time — before a presentation, or whenever visitor edits have piled up. It's fully idempotent (clears then regenerates), safe to run as often as you like.

## Known limitations

- **wherewhen's Analysis tab is empty in demo mode.** `analysis_results` is normally populated by the HA/Node-RED side (PLAN.md §11), which this demo doesn't simulate. Worth a follow-up if the Analysis tab specifically needs to look populated for a presentation — not built in this first pass.
- **Same browser session, both modes**: if you (Ward) are logged into the real app in a browser tab and then also visit `/demo/` in the *same* browser, the demo flag briefly applies to that whole browser session. Logging in for real (`login.php`) or logging out both clear it — but simplest is just to use a different browser/incognito window for demo walkthroughs.
- **Every visitor to `/demo/` shares the one demo database** — someone else's test edits can be visible until you reset. Fine for a supervised presentation; worth knowing if the link is shared more broadly.
