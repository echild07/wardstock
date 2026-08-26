# InfluxDB v2 — setup reference

Confirmed working steps and configuration for the Lucius project's InfluxDB instance. Written up after actually going through this live — not a generic guide, this reflects what specifically worked (and what specifically went wrong first) for this project.

## Two separate YAMLs exist — only one is relevant here

It's easy to conflate these, and doing so caused real confusion earlier in this project:

1. **The add-on's own config YAML** (Settings → Add-ons → InfluxDB2 → Configuration tab) — controls the InfluxDB *server* add-on itself. **This is the one Lucius needs**, covered below.
2. **Home Assistant Core's "InfluxDB" *integration*** (a `configuration.yaml` entry, or the Settings → Devices & Services screen) — exports *HA's own sensor history* into InfluxDB. A separate, optional feature. **Lucius does not use this at all** — Node-RED talks to InfluxDB directly over HTTP. Don't configure this unless you specifically want HA's own sensors flowing in too, as an unrelated bonus.

## Why this add-on specifically, not the default one

Home Assistant's default/official Community Add-on ("InfluxDB") is version **1.8.x** — a different data model entirely (databases + username/password, no orgs/buckets/API tokens). Confirmed directly via `/health`'s version field, not assumed. InfluxDB 1.x is legacy; this project uses a real 2.x instance instead. As of this writing there is still no *official* v2 add-on — [confirmed via the official add-on repo's own discussion thread](https://github.com/hassio-addons/addon-influxdb/discussions/113), a v2 add-on has been floated for years with no committed plan to ship one. Third-party add-ons are the only way to get InfluxDB 2.x under Supervisor.

**Switched from Jay's Home Assistant Add-ons to Dattel's (Aug 2026), before any real data had flowed through.** Jay's `InfluxDB v2` add-on worked correctly but its Info tab (screenshot on file — `commit activity: 0/year`, `maintained: no! (as of 2022)`) showed it had had no updates since 2022, with no guarantee of surviving a future Supervisor change. [Dattel/homeassistant-influxdb2](https://github.com/Dattel/homeassistant-influxdb2) is the actively maintained alternative — releases through June 2026 (add-on v0.2.9, running InfluxDB 2.7.12), same InfluxDB 2.x web-onboarding model (org/bucket/token), same install pattern. The maintainer states they intend to keep it going only until an official add-on ships, so treat *this* as the current-best choice, not a permanent one — same periodic sanity check applies (does it still start, does `/health` still return 200) rather than assuming any third-party add-on keeps working unattended forever.

## Install steps (in order)

1. If a 1.x add-on (or Jay's v2 add-on) is installed, uninstall it first. Note this is a fresh InfluxDB instance either way — org/bucket/token don't carry over from a prior add-on, so nothing of value is lost by switching before real data has flowed through.
2. Add Dattel's repository to Supervisor:
   ```
   https://github.com/Dattel/homeassistant-influxdb2
   ```
   Settings → Add-ons → Add-on Store → ⋮ (top-right) → Repositories → paste the URL → Add.
3. Install **"InfluxDB2"** from the store (now visible after adding that repository — the add-on's internal folder is named `influxdb2`).
4. Start the add-on.
5. **Open its web UI directly by URL** unless/until confirmed otherwise — Jay's add-on explicitly didn't support Home Assistant's ingress/sidebar embedding (its own FAQ: requires a base-URL rewrite InfluxDB2 doesn't support). Dattel's docs reference an ingress port setting, which may mean this is no longer true here — worth checking for a "InfluxDB2" entry in the HA sidebar after install, but don't rely on it until you've actually seen it. Fallback is the same as before: `http://homeassistant.local:8086` (or `https://` if SSL is left on — see below).
6. Go through InfluxDB's own first-run setup wizard: primary username/password, **organization**, **bucket** name. (This project used organization `wardstock`, bucket `metrics` — adjust to your own choices, both are referenced in `wardstock_secrets.json` afterward.)
7. Generate a scoped API token: left sidebar → Data → API Tokens → Generate API Token → **Custom API Token** (not "All Access") → grant Read + Write on your bucket specifically → Generate → **copy immediately** (InfluxDB only shows the full token once, at creation).
8. **Confirm the real reachable URL/hostname by testing directly, don't assume it.** Specifically from a Node-RED `exec` node — that's the container the actual sync flows run from, and a hostname reachable from elsewhere (an SSH terminal, your own PC) isn't guaranteed to be reachable from there too:
   ```
   curl -s -o /dev/null -w "%{http_code}" http://<hostname>:8086/health
   ```
   A `200` confirms it. **Confirmed for Dattel's add-on (Aug 2026), tested from a Node-RED exec node: both `ec9cbdb7-influxdb2:8086` (the Supervisor-managed slug, this install's specific value) and `homeassistant.local:8086` return 200.** The slug is the one actually used in `influxdb_url`, on the same reasoning as Jay's add-on originally — it doesn't depend on the add-on staying in host-networking mode (which is what makes `homeassistant.local`/`localhost` work here today, and could change on an update). Worth noting this is a partial reversal from the note in an earlier revision of this doc, which assumed (before testing the slug) that Dattel's add-on had flipped away from slug-based reachability entirely — it hadn't; both forms work, the slug was just untested at the time. If you switch InfluxDB add-ons again in the future, re-run this exact test on both forms rather than assuming either carries over.

## The add-on's own config YAML (step 2's Configuration tab)

**Confirmed from the real Configuration tab (screenshot on file, Aug 2026).** Dattel's add-on has a superset of Jay's options — same `reporting`/`ssl`/`certfile`/`keyfile`/`envvars`, plus two new log-level controls:

```yaml
log_level: info
influxd_log_level: warn
reporting: true
ssl: false
certfile: fullchain.pem
keyfile: privkey.pem
envvars: []
```

- **`log_level`** — the add-on wrapper's own logging (its supervisord/startup script), separate from InfluxDB itself. Left at default (`info`).
- **`influxd_log_level`** — logging for the actual `influxd` server process. Defaulted to `warn` here (a step quieter than Jay's add-on, which didn't expose this separately at all).
- **`reporting`** — InfluxDB's own anonymous usage reporting, unrelated to Lucius either way. Same as Jay's, on by default.
- **`ssl`** — **off, confirmed**, same as Jay's add-on. This is what makes the plain `http://` URLs used everywhere else in this doc correct (step 8's test, `wardstock_secrets.json`'s `influxdb_url`). If this ever shows on/true instead, that's the first thing to check before treating a connection failure as something deeper — it's almost certainly a scheme mismatch, not a real outage.
- **`certfile`/`keyfile`** — only relevant if `ssl: true`; left at their defaults since SSL is off.
- **`envvars`** — empty; not needed for this setup.

Network section (below Options, not fully captured in the screenshot on file) is expected to map `8086/tcp` the same way Jay's did — worth a quick glance to confirm next time that tab's open, but not blocking anything so far since step 8's `/health` check already confirms the port is reachable end to end.

## What actually goes where, once this is all done

Nothing from this setup goes into the add-on's own config beyond whatever the real Configuration tab turns out to need. The four real connection values live in `/share/wardstock_secrets.json` on the Node-RED side (see `wardstock_secrets.json.example` in this folder):

```json
"influxdb_url": "http://ec9cbdb7-influxdb2:8086",
"influxdb_org": "<your organization name from step 6>",
"influxdb_bucket": "<your bucket name from step 6>",
"influxdb_token": "<the token generated in step 7>"
```

(`influxdb_url` shown above is this project's real confirmed value under Dattel's add-on — see step 8. It's this install's specific Supervisor-assigned slug, so it will very likely be different on a different install; re-confirm rather than assuming this exact value carries over. `http://homeassistant.local:8086` also tested reachable here as a fallback, but the slug was chosen deliberately per step 8's reasoning.)
