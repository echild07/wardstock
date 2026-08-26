# WardStock / Lucius backlog

The one running list of open items — things genuinely still undone, not yet-to-be-written history. `CLAUDE.md` points here instead of keeping its own "Outstanding work" list, specifically so there's one place to keep current instead of two that drift against each other. Compiled Aug 22 2026 by reading every `.md` file in this repo for "not built"/"still open"/"known gap"/"TODO" markers, then verifying each one against the actual code or live `job_status` (via `HA-share`) rather than trusting the doc's own claim — several things listed as "still open" in older sections turned out to already be fixed; those aren't repeated here.

When something here gets done, delete it (or move it to the relevant `RETROSPECTIVE.md`/`PLAN.md` as a real "built" entry) rather than leaving a stale checked box — same discipline the rest of this project's docs already follow.

---

## Priority 1 — real, verified, worth doing soon

1. **`godaddy_backup_flow.json` has never actually run.** Confirmed via `job_status` on the HA share — no row exists for it at all, meaning `/share/lucius_godaddy_backup.db` doesn't exist yet either. The disaster-recovery mirror this flow exists to maintain isn't actually established. Just needs the manual trigger clicked once (or the 3am schedule to fire), not a code change.
2. **`blood_pressure_readings` has no export/backup path at all.** Not in `build_export_records()`, not in `pull_manual_data.php`'s types, not in the human Export page. Real, silent gap — a BP reading logged in WardStock is currently unrecoverable if the database is ever lost, unlike every other tracked field.
3. **Confirm the new Oura endpoints are actually flowing.** `oura_sync_flow.json` was extended to pull `heartrate`/`spo2`/`stress`/`resilience`/`workout`/`session`/`enhanced_tag` in addition to the original three, but that needs a fresh OAuth authorization with the wider scope string — never confirmed whether Ward actually did that reconnect, or whether the sync is still quietly running on the old scopes only.

## Priority 2 — real design decisions, never actually made

4. **No retention/pruning policy exists anywhere**, and this same undecided question has surfaced independently in at least five places without ever being resolved: the disaster-recovery JSON archive (`/share/lucius_archive/`), `ha_sync_log` (MySQL), InfluxDB's `sync_job_runs` measurement, superseded `analysis_results` rows (old `analysis_version`s), and wherewhen Data Export's timestamped backup folders. Worth deciding once, as one policy, rather than five separate small decisions.
5. **Whether Home Assistant's own automatic backups actually cover `/share`** has never been confirmed — matters most for the disaster-recovery archive and the new `lucius_godaddy_backup.db`, since "we have a backup" isn't true if the thing holding it isn't itself backed up.
6. **`therapy_schedules` has no real end date** — only `active` (an on/off flag) and a "Paused" label for `active=0`, no actual date recording *when* a schedule stopped. Ward wants the same `start_date`/`end_date` shape `medications` already has. Deferred, not yet scoped in detail.
7. **Medical History Import's `related_medications[0]`-only simplification** — the flow's own code comment calls this "best-effort," not a considered final design, for medical-history entries that name more than one related medication.
8. **Two ambiguous medical-history entries were left as unstructured reference text** rather than real rows, for lack of a date to anchor them: the post-heart-attack panic-like episodes (no year at all) and 2026 individual/couples therapy content (arguably belongs in `therapy_sessions`, but no per-session dates exist to create real rows). Needs a real decision if this ever gets revisited.

## Priority 3 — bigger, deliberately deferred features

9. **KardiaPro EKG API sync — the actual primary path, per `EKG_DESIGN.md`/`PLAN.md` §21 — was never built.** This session's EKG work was explicitly scoped to the GoDaddy-side manual-entry placeholder only (screens, MySQL tables, the API endpoint); the real "auto-sync from a Kardia device" flow and the PDF-upload+AI-extraction fallback are both still just design docs.
10. **wherewhen's Analysis tab shows empty in demo mode** — `analysis_results` is normally populated by the HA/Node-RED side, which the demo dataset doesn't simulate. Only matters if a demo walkthrough specifically needs to show populated charts.
11. **Body Composition Import's oversized-`readRange` workaround has never been tested against a real oversized scale export** — a real row-count-unknown-ahead-of-time edge case, reasoned through but not confirmed.

## Priority 4 — soft, Ward's own call, not urgent

12. **Favicon legibility.** Even after the Aug 2026 logo redesign (rod-and-staff removed), the 32×32 favicon is still a hard-to-read blob — a property of how detailed the source art is at that size, not something a resize fixes. Would need dedicated, simplified favicon-only artwork.
13. **Lisa's bio on `about.php`** still reads "coming soon" — deliberately deferred, her own call on when/whether to add it.
14. **Demo mode isn't set up on `aileeward.com`** — `DEMO_DB_NAME` is blank in that domain's config. A working demo instance already exists on the old `emperorschildren.net` domain, unaffected by this.
15. **No scheduled flow polls `api/status.php` for version-drift automatically** — only the manual `system_test_flow.json` checks this today. Worth adding to one of the scheduled flows if catching drift without a manual run ever matters.

16. **Evening incident digest is in-repo, not live.** `api/incident_digest.php` + `HA/share/incident_digest.py` + Node-RED `incident_digest_flow.json`. Needs SFTP of the PHP, copy of the script to `/share/wardstock_incident_digest.py`, SMTP app password in `wardstock_secrets.json`, import of the two tabs, `python3` on the Node-RED add-on.

## Resolved this session, listed here only so nobody re-flags them

- DB credentials on `aileeward.com` — Ward fixed and confirmed working (site live, `login.php`'s health check green).
- HTTPS on `aileeward.com` — confirmed working (this whole session's `WebFetch` checks succeeded over `https://`, and `godaddy_pull`/`oura_sync` both show recent live successes in `job_status`, which needs HTTPS to even reach GoDaddy).
- Oura OAuth re-authorization for the original three endpoints — `job_status` shows `oura_sync` running successfully with a current checkpoint.
- Marketing PDF's blank page 4 — fixed and verified by regenerating and reading the actual PDF output.
- Duplicate medications (Repatha/Wegovy) — root-caused to a bad hardcoded seed date, seed removed entirely, live duplicates deleted by Ward.
- `sql/reset_clean.sql` was missing 7 tables (`analysis_results`, `attention_snoozes`, `blood_pressure_readings`, `ecg_artifacts`, `ecg_recordings`, `medication_dosage_history`, `proposed_events`) and had a stale pre-medical-category `incidents` table — rebuilt directly from `schema.sql`'s current table definitions; the two files' table lists are now verified identical.
