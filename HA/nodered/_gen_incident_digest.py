#!/usr/bin/env python3
"""Emit WardStock incident-digest Node-RED tabs. Run from nodered/: python _gen_incident_digest.py"""
from __future__ import annotations

import json
from pathlib import Path

ROOT = Path(__file__).resolve().parent
SCRIPT = "/share/wardstock_incident_digest.py"
SECRETS = "/share/wardstock_secrets.json"


def tab(nid, label, info):
    return {"id": nid, "type": "tab", "label": label, "disabled": False, "info": info}


def inject(nid, z, name, x, y, wires, crontab="", repeat=""):
    return {
        "id": nid,
        "type": "inject",
        "z": z,
        "name": name,
        "props": [{"p": "payload"}],
        "repeat": repeat,
        "crontab": crontab,
        "once": False,
        "onceDelay": 0.1,
        "topic": "",
        "payload": "",
        "payloadType": "date",
        "x": x,
        "y": y,
        "wires": [wires],
    }


def file_in(nid, z, name, filename, x, y, wires):
    return {
        "id": nid,
        "type": "file in",
        "z": z,
        "name": name,
        "filename": filename,
        "filenameType": "str",
        "format": "utf8",
        "chunk": False,
        "sendError": True,
        "encoding": "none",
        "x": x,
        "y": y,
        "wires": [wires],
    }


def fn(nid, z, name, func, x, y, wires):
    return {
        "id": nid,
        "type": "function",
        "z": z,
        "name": name,
        "func": func,
        "outputs": 1,
        "x": x,
        "y": y,
        "wires": [wires],
    }


def execn(nid, z, name, x, y, wires):
    return {
        "id": nid,
        "type": "exec",
        "z": z,
        "name": name,
        "command": "",
        "addpay": True,
        "append": "",
        "useSpawn": "false",
        "timer": "120",
        "winHide": False,
        "oldrc": False,
        "x": x,
        "y": y,
        "wires": [wires, [], []],
    }


def debug(nid, z, name, x, y):
    return {
        "id": nid,
        "type": "debug",
        "z": z,
        "name": name,
        "active": True,
        "tosidebar": True,
        "console": False,
        "tostatus": False,
        "complete": "payload",
        "targetType": "msg",
        "x": x,
        "y": y,
        "wires": [],
    }


PREP = f"""let raw = msg.payload;
try {{ if (typeof raw === 'string') raw = JSON.parse(raw); }} catch (e) {{
    node.error('lucius secrets JSON parse failed: ' + e.message);
    return null;
}}
const py = raw.python3 || 'python3';
const script = raw.incident_digest_script || '{SCRIPT}';
const secrets = '{SECRETS}';
const extra = msg.digestArgs || '';
msg.payload = py + ' ' + script + ' --secrets ' + secrets + extra;
return msg;
"""

CHECK = """if (msg.rc && msg.rc.code !== 0) {
    node.error('incident_digest.py exit ' + msg.rc.code + ' ' + String(msg.payload || '').slice(0, 400));
    return null;
}
return msg;
"""


def live():
    z = "tab_incident_digest"
    return [
        tab(
            z,
            "WardStock - Incident Digest",
            "21:00 local (HA clock) plus manual. Exec wardstock_incident_digest.py: GET api/incident_digest.php, SMTP only if count>0. Copy HA/share/incident_digest.py to /share/wardstock_incident_digest.py. SMTP keys in /share/wardstock_secrets.json. Companion: WardStock - Incident Digest Test. No HA Server field. python3 must exist on the Node-RED add-on (system_packages).",
        ),
        inject("n_id_sched", z, "21:00 daily", 140, 80, ["n_id_secrets"], crontab="0 21 * * *"),
        inject("n_id_manual", z, "manual", 140, 140, ["n_id_secrets"]),
        file_in("n_id_secrets", z, "read secrets", SECRETS, 360, 110, ["n_id_prep"]),
        fn("n_id_prep", z, "prep digest cmd", PREP, 580, 110, ["n_id_exec"]),
        execn("n_id_exec", z, "exec incident_digest.py", 820, 110, ["n_id_chk"]),
        fn("n_id_chk", z, "check rc", CHECK, 1040, 110, ["n_id_dbg"]),
        debug("n_id_dbg", z, "digest stdout", 1260, 110),
    ]


def test():
    z = "tab_incident_digest_test"
    prep_test = PREP.replace("msg.digestArgs || ''", "' --dry-run'")
    return [
        tab(
            z,
            "WardStock - Incident Digest Test",
            "Manual. Same GET as production with --dry-run (never SMTP). Expect JSON count/mailed. Does not write incidents. PLAN.md §2 companion.",
        ),
        inject("n_idt_inj", z, "dry-run digest", 140, 80, ["n_idt_secrets"]),
        file_in("n_idt_secrets", z, "read secrets", SECRETS, 360, 80, ["n_idt_prep"]),
        fn("n_idt_prep", z, "prep dry-run cmd", prep_test, 580, 80, ["n_idt_exec"]),
        execn("n_idt_exec", z, "exec incident_digest.py --dry-run", 820, 80, ["n_idt_dbg"]),
        debug("n_idt_dbg", z, "dry-run stdout", 1100, 80),
    ]


def main() -> None:
    (ROOT / "incident_digest_flow.json").write_text(json.dumps(live(), indent=2), encoding="utf-8")
    (ROOT / "incident_digest_test.json").write_text(json.dumps(test(), indent=2), encoding="utf-8")
    print("wrote", ROOT / "incident_digest_flow.json")
    print("wrote", ROOT / "incident_digest_test.json")


if __name__ == "__main__":
    main()
