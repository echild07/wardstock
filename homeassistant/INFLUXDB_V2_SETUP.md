# InfluxDB v2 — setup reference

Confirmed working steps and configuration for the Lucius project's InfluxDB instance. Written up after actually going through this live — not a generic guide, this reflects what specifically worked (and what specifically went wrong first) for this project.

## Two separate YAMLs exist — only one is relevant here

It's easy to conflate these, and doing so caused real confusion earlier in this project:

1. **The add-on's own config YAML** (Settings → Add-ons → InfluxDB v2 → Configuration tab) — controls the InfluxDB *server* add-on itself. **This is the one Lucius needs**, covered below.
2. **Home Assistant Core's "InfluxDB" *integration*** (a `configuration.yaml` entry, or the Settings → Devices & Services screen) — exports *HA's own sensor history* into InfluxDB. A separate, optional feature. **Lucius does not use this at all** — Node-RED talks to InfluxDB directly over HTTP. Don't configure this unless you specifically want HA's own sensors flowing in too, as an unrelated bonus.

## Why this add-on specifically, not the default one

Home Assistant's default/official Community Add-on ("InfluxDB") is version **1.8.x** — a different data model entirely (databases + username/password, no orgs/buckets/API tokens). Confirmed directly via `/health`'s version field, not assumed. InfluxDB 1.x is legacy; this project uses a real 2.x instance instead.

**One real caveat worth knowing before you rely on this add-on long-term:** its own Info tab (screenshot on file — `commit activity: 0/year`, `maintained: no! (as of 2022)`) shows it hasn't been actively maintained since 2022. It's still the correct, working choice today (production-ready, MIT-licensed, does exactly what Lucius needs), but there's no guarantee of future updates if HA itself changes in a way that breaks it. Worth a periodic sanity check (does it still start, does `/health` still return 200) rather than assuming it'll keep working forever unattended.

## Install steps (in order)

1. If the 1.x add-on is installed, uninstall it first (nothing of value is lost — Lucius had only ever hit its `/health` endpoint for testing at that point).
2. Add the add-on's **catalog** repository — **not** its code repository (a real mistake made and corrected here: `.../j-addon-influxdb2` is the code repo; Supervisor needs the separate catalog repo below):
   ```
   https://github.com/Jays-Home-Assistant-Add-ons/repository
   ```
   Settings → Add-ons → Add-on Store → ⋮ (top-right) → Repositories → paste the URL → Add.
3. Install **"InfluxDB v2"** from the store (now visible after adding that repository).
4. Start the add-on.
5. **Open its web UI directly by URL** — this add-on does **not** support Home Assistant's ingress/sidebar embedding (confirmed from the add-on's own FAQ: it requires a base-URL rewrite InfluxDB2 doesn't support). Typically `http://homeassistant.local:8086` (or `https://` if SSL is left on — see below).
6. Go through InfluxDB's own first-run setup wizard: primary username/password, **organization**, **bucket** name. (This project used organization `wardstock`, bucket `metrics` — adjust to your own choices, both are referenced in `lucius_secrets.json` afterward.)
7. Generate a scoped API token: left sidebar → Data → API Tokens → Generate API Token → **Custom API Token** (not "All Access") → grant Read + Write on your bucket specifically → Generate → **copy immediately** (InfluxDB only shows the full token once, at creation).
8. **Confirm the real reachable URL/hostname by testing directly, don't assume it.** From a Node-RED `exec` node (or any shell with access to the HA network):
   ```
   curl -s -o /dev/null -w "%{http_code}" http://<hostname>:8086/health
   ```
   A `200` confirms it. This project's actual add-on hostname turned out to be `a0d7b954-influxdb` — Supervisor-assigned, will very likely be different for a different install. `localhost:8086` may also work depending on whether the add-on happens to be running in host-networking mode, but the Supervisor-managed hostname is the more robust choice long-term (doesn't depend on that networking mode staying enabled).

## The add-on's own config YAML (step 2's Configuration tab)

This add-on is deliberately minimal compared to the 1.x add-on — InfluxDB 2.x configures itself through its own web onboarding wizard (step 6) rather than through add-on options. Confirmed real content, this project's actual working config:

```yaml
reporting: true
ssl: false
certfile: fullchain.pem
keyfile: privkey.pem
envvars: []
```

- **`reporting`** — InfluxDB's own anonymous usage reporting, unrelated to Lucius either way.
- **`ssl`** — default is **on**; if left on, the add-on expects real certificate files, and a plain `http://` URL (like step 8's test) wouldn't match the add-on's intended protocol. Setting `ssl: false` is what makes a plain `http://` URL correct — this project runs with SSL off. Worth confirming this matches whatever URL scheme you're actually using before troubleshooting a connection issue that's really just an SSL mismatch.
- **`certfile`/`keyfile`** — only relevant if `ssl: true`; left at their defaults here since SSL is off.
- **`envvars`** — empty; not needed for this setup.

## What actually goes where, once this is all done

Nothing from this setup goes into the add-on's own config beyond the YAML above. The four real connection values live in `/share/lucius_secrets.json` on the Node-RED side (see `lucius_secrets.json.example` in this folder):

```json
"influxdb_url": "http://<your-confirmed-hostname>:8086",
"influxdb_org": "<your organization name from step 6>",
"influxdb_bucket": "<your bucket name from step 6>",
"influxdb_token": "<the token generated in step 7>"
```
