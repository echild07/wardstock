# Lucius

A two-piece personal health-tracking project, built for Ward:

- **`godaddy/`** — **WardStock**, the actual app you use day-to-day. A private, single-user, password-protected PHP + MySQL web app hosted on GoDaddy shared hosting: incident logging (anxiety & cardiac), daily health tracking, medications, therapy sessions, a dashboard, and data export/import. This is the always-reachable system of record for anything you hand-enter — see `godaddy/README.md` for setup, and `godaddy/PROJECT_PLAN.md` for its full build history.

- **`homeassistant/`** — a background sync and analytics engine running on Home Assistant OS (Node-RED + InfluxDB). Pulls high-fidelity Oura data WardStock itself never captured, pushes summary data up to WardStock, pulls manually-entered data back down for disaster-recovery backup, imports smart-scale body composition data, and reports sync status back to WardStock. System Test flow confirmed working end to end against the real stack; the four scheduled sync flows (Oura Sync, GoDaddy Pull, Body Composition Import, Status Heartbeat) are built but not yet run for real — see `homeassistant/PLAN.md` for the full design and `homeassistant/README.md` for setup.

## Why two pieces, not one

WardStock stays exactly where it is — reachable from anywhere, no dependency on home internet or power — because incidents happen away from home, not just when you're near the Pi. Home Assistant never needs to be reachable *from* anywhere; it only ever reaches *out*, to Oura's API and to WardStock's API, on a schedule. That split is deliberate: the thing you need to always be able to open stays simple and always-on; the thing doing the heavier data work runs in the background and only needs to be right, not reachable.

## Versioning

**Current version: 2.2.5 "Lucius"** — project-wide, covering both pieces. Major version bumped from 1.x ("Sidroh") specifically to mark this expansion from a single GoDaddy app into a two-piece project. See `godaddy/README.md`'s Versioning section for the full `Major.SQL.Code` scheme (currently only tracked/applied on the GoDaddy piece, since the HA piece doesn't have a database of its own to version yet).

## Where to start

- **Rebuilding from scratch** (concrete current-state facts, no history) → `PROMPT.md`
- Setting up or updating the GoDaddy piece → `godaddy/README.md`
- Setting up or updating the Home Assistant piece → `homeassistant/README.md`
- Understanding what's planned/built for the Home Assistant piece → `homeassistant/PLAN.md`
- Full history of everything built so far, GoDaddy piece → `godaddy/PROJECT_PLAN.md`
- **How this project actually got here — assumptions, real bugs found, and what changed as a result, from initial concept through the current state** → `RETROSPECTIVE.md`
