#!/usr/bin/env python3
"""Evening incident digest: GET GoDaddy api/incident_digest.php, email if count>0.

Usage:
  python3 /share/lucius_incident_digest.py --secrets /share/lucius_secrets.json
  python3 incident_digest.py --dry-run --day 2026-08-26
"""
from __future__ import annotations

import argparse
import json
import smtplib
import sqlite3
import sys
import urllib.error
import urllib.parse
import urllib.request
from datetime import datetime, timezone
from email.message import EmailMessage
from pathlib import Path


def load_secrets(path: Path) -> dict:
    return json.loads(path.read_text(encoding="utf-8"))


def http_get_json(url: str, token: str) -> dict:
    req = urllib.request.Request(
        url,
        method="GET",
        headers={"Authorization": "Bearer " + token, "Accept": "application/json"},
    )
    try:
        with urllib.request.urlopen(req, timeout=60) as resp:
            return json.loads(resp.read().decode("utf-8"))
    except urllib.error.HTTPError as e:
        detail = e.read().decode("utf-8", errors="replace")[:500]
        raise RuntimeError(f"HTTP {e.code} {url}: {detail}") from e


def upsert_job(path: Path | None, name: str, ok: bool, err: str | None, checkpoint: str) -> None:
    if not path or not path.is_file():
        return
    now = datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")
    con = sqlite3.connect(str(path))
    try:
        con.execute(
            """CREATE TABLE IF NOT EXISTS job_status (
                job_name TEXT PRIMARY KEY, last_success TEXT, last_attempt TEXT,
                last_attempt_ok INTEGER, last_error TEXT, checkpoint TEXT, updated_at TEXT)"""
        )
        con.execute(
            """INSERT INTO job_status (job_name, last_success, last_attempt, last_attempt_ok, last_error, checkpoint, updated_at)
               VALUES (?, ?, ?, ?, ?, ?, ?)
               ON CONFLICT(job_name) DO UPDATE SET
                 last_attempt=excluded.last_attempt,
                 last_attempt_ok=excluded.last_attempt_ok,
                 last_error=excluded.last_error,
                 checkpoint=excluded.checkpoint,
                 updated_at=excluded.updated_at""",
            (name, now if ok else None, now, 1 if ok else 0, err, checkpoint, now),
        )
        if ok:
            con.execute("UPDATE job_status SET last_success=? WHERE job_name=?", (now, name))
        con.commit()
    finally:
        con.close()


def format_body(digest: dict, base: str) -> str:
    day = digest.get("day")
    tz = digest.get("timezone") or ""
    n = int(digest.get("count") or 0)
    url = base.rstrip("/") + "/" + (digest.get("incidents_path") or "incidents.php")
    lines = [
        f"WardStock incident digest for {day} ({tz}).",
        f"{n} incident(s) with occurred_at on that calendar day.",
        f"Open: {url}",
        "",
    ]
    for inc in digest.get("incidents") or []:
        cat = inc.get("category") or "?"
        when = inc.get("occurred_at") or ""
        bits = [f"- {when}  {cat}"]
        if inc.get("anxiety_intensity") is not None:
            bits.append(f"  intensity {inc['anxiety_intensity']}/10")
        if inc.get("duration_minutes") is not None:
            bits.append(f"  {inc['duration_minutes']} min")
        if inc.get("nitroglycerin_taken"):
            bits.append("  nitroglycerin taken")
        if inc.get("trigger_context"):
            bits.append(f"  trigger: {inc['trigger_context']}")
        if inc.get("free_notes"):
            bits.append(f"  notes: {inc['free_notes']}")
        lines.append("\n".join(bits))
        lines.append("")
    lines.append("Sent only because count > 0. No mail on a quiet day.")
    return "\n".join(lines).rstrip() + "\n"


def send_mail(secrets: dict, subject: str, body: str) -> None:
    host = secrets.get("incident_digest_smtp_host") or "smtp.gmail.com"
    port = int(secrets.get("incident_digest_smtp_port") or 587)
    user = secrets.get("incident_digest_smtp_user") or secrets.get("gmail_imap_user") or ""
    password = secrets.get("incident_digest_smtp_app_password") or secrets.get("gmail_imap_app_password") or ""
    to_addr = secrets.get("incident_digest_to") or user
    from_addr = secrets.get("incident_digest_from") or user
    if not user or not password or not to_addr:
        raise SystemExit("secrets need incident_digest_smtp_user, incident_digest_smtp_app_password, incident_digest_to")
    msg = EmailMessage()
    msg["Subject"] = subject
    msg["From"] = from_addr
    msg["To"] = to_addr
    msg.set_content(body)
    with smtplib.SMTP(host, port, timeout=60) as smtp:
        smtp.starttls()
        smtp.login(user, password)
        smtp.send_message(msg)


def run(args: argparse.Namespace) -> int:
    secrets = load_secrets(Path(args.secrets))
    base = (secrets.get("godaddy_base_url") or "").rstrip("/")
    token = secrets.get("godaddy_api_sync_token") or ""
    if not base or not token:
        print("godaddy_base_url and godaddy_api_sync_token required", file=sys.stderr)
        return 1
    day = args.day or "today"
    url = base + "/api/incident_digest.php?day=" + urllib.parse.quote(str(day))
    job_db = Path(secrets.get("job_status_sqlite") or "/share/lucius_status.db")
    try:
        digest = http_get_json(url, token)
    except Exception as e:
        upsert_job(job_db if job_db.exists() else None, "incident_digest", False, str(e)[:500], "{}")
        print("FAIL", e, file=sys.stderr)
        return 1
    if digest.get("error"):
        err = str(digest.get("error"))
        upsert_job(job_db if job_db.exists() else None, "incident_digest", False, err, json.dumps(digest)[:400])
        print("FAIL", digest, file=sys.stderr)
        return 1
    n = int(digest.get("count") or 0)
    ck = json.dumps({"day": digest.get("day"), "count": n, "mailed": False})
    if n == 0:
        upsert_job(job_db if job_db.exists() else None, "incident_digest", True, None, ck)
        print(json.dumps({"day": digest.get("day"), "count": 0, "mailed": False}))
        return 0
    subject = f"WardStock: {n} incident(s) on {digest.get('day')}"
    body = format_body(digest, base)
    if args.dry_run:
        print("DRY-RUN would send:")
        print(subject)
        print(body)
        print(json.dumps({"day": digest.get("day"), "count": n, "mailed": False, "dry_run": True}))
        return 0
    try:
        send_mail(secrets, subject, body)
    except Exception as e:
        upsert_job(job_db if job_db.exists() else None, "incident_digest", False, str(e)[:500], ck)
        print("FAIL send", e, file=sys.stderr)
        return 1
    ck = json.dumps({"day": digest.get("day"), "count": n, "mailed": True})
    upsert_job(job_db if job_db.exists() else None, "incident_digest", True, None, ck)
    print(json.dumps({"day": digest.get("day"), "count": n, "mailed": True}))
    return 0


def main() -> int:
    p = argparse.ArgumentParser(description="Email WardStock incidents for one local calendar day if any")
    p.add_argument("--secrets", default="/share/lucius_secrets.json")
    p.add_argument("--day", default=None, help="YYYY-MM-DD or omit for today")
    p.add_argument("--dry-run", action="store_true")
    return run(p.parse_args())


if __name__ == "__main__":
    raise SystemExit(main())
