#!/usr/bin/env python3
import json
import os
import subprocess
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path

TOKEN = os.environ["DASHBOARD_TOKEN"]
COMPOSE_DIR = Path(os.environ["COMPOSE_DIR"]).resolve()
SETTINGS_FILE = Path(os.environ["SETTINGS_FILE"]).resolve()
HOST = os.getenv("HOST", "127.0.0.1")
PORT = int(os.getenv("PORT", "8765"))

ALLOWED_CONTAINERS = {"wyze-bridge", "motion-recorder"}
RECORD_ROOT = Path(os.environ.get("RECORD_ROOT", str(COMPOSE_DIR / "recordings"))).resolve()
PROTECTED_FILE = Path(os.environ.get("PROTECTED_FILE", str(COMPOSE_DIR / "motion-state" / "protected.json"))).resolve()

DEFAULT_SETTINGS = {
    "segment_seconds": 300,
    "delete_unprotected_after_minutes": 30,
    "motion_before_seconds": 300,
    "motion_after_seconds": 300,
    "keep_motion_days": 30,
    "cameras": {
        "shop-cam": {
            "enabled": True,
            "schedule": [{"days": [0,1,2,3,4,5,6], "start":"00:00", "end":"23:59"}]
        }
    }
}

def atomic_json_write(path, data):
    path.parent.mkdir(parents=True, exist_ok=True)
    tmp = path.with_suffix(path.suffix + ".tmp")
    tmp.write_text(json.dumps(data, indent=2) + "\n")
    os.chmod(tmp, 0o640)
    tmp.replace(path)


def safe_recording_path(relative):
    if not isinstance(relative, str) or not relative or "\x00" in relative:
        raise ValueError("Invalid recording path")
    candidate = (RECORD_ROOT / relative).resolve()
    try:
        candidate.relative_to(RECORD_ROOT)
    except ValueError:
        raise ValueError("Path outside recording root")
    if candidate.suffix.lower() != ".mp4":
        raise ValueError("Only MP4 recordings are supported")
    return candidate

def load_protected():
    if not PROTECTED_FILE.exists():
        return {"files":[]}
    try:
        data = json.loads(PROTECTED_FILE.read_text())
        if isinstance(data, dict) and isinstance(data.get("files"), list):
            return {"files":[str(x) for x in data["files"]]}
    except Exception:
        pass
    return {"files":[]}

def save_protected(data):
    atomic_json_write(PROTECTED_FILE, {"files": sorted(set(data.get("files", [])))})

def disk_status():
    st = os.statvfs(RECORD_ROOT)
    total = st.f_frsize * st.f_blocks
    free = st.f_frsize * st.f_bavail
    used = total - free
    pct = round((used / total * 100.0), 1) if total else 0
    return {"total": total, "used": used, "free": free, "percent_used": pct}

def load_settings():
    if not SETTINGS_FILE.exists():
        atomic_json_write(SETTINGS_FILE, DEFAULT_SETTINGS)
    return json.loads(SETTINGS_FILE.read_text())

def validate_settings(data):
    if not isinstance(data, dict):
        raise ValueError("Settings must be an object")

    def intval(key, lo, hi, default):
        v = int(data.get(key, default))
        if not lo <= v <= hi:
            raise ValueError(f"{key} outside allowed range")
        return v

    out = {
        "segment_seconds": intval("segment_seconds", 30, 3600, 300),
        "delete_unprotected_after_minutes": intval("delete_unprotected_after_minutes", 1, 10080, 30),
        "motion_before_seconds": intval("motion_before_seconds", 0, 3600, 300),
        "motion_after_seconds": intval("motion_after_seconds", 0, 3600, 300),
        "keep_motion_days": intval("keep_motion_days", 1, 3650, 30),
        "emergency_cleanup_enabled": bool(data.get("emergency_cleanup_enabled", True)),
        "emergency_cleanup_percent": intval("emergency_cleanup_percent", 80, 99, 92),
        "emergency_cleanup_target_percent": intval("emergency_cleanup_target_percent", 50, 98, 85),
        "cameras": {}
    }

    if out["emergency_cleanup_target_percent"] >= out["emergency_cleanup_percent"]:
        raise ValueError("Emergency cleanup target must be lower than trigger")

    cams = data.get("cameras", {})
    if not isinstance(cams, dict) or not cams:
        raise ValueError("At least one camera is required")

    for name, cam in cams.items():
        if not isinstance(name, str) or not name or any(c not in "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_-" for c in name):
            raise ValueError("Invalid camera name")
        if not isinstance(cam, dict):
            raise ValueError("Invalid camera config")

        schedules = []
        for sched in cam.get("schedule", []):
            days = sorted(set(int(x) for x in sched.get("days", [])))
            if any(x < 0 or x > 6 for x in days):
                raise ValueError("Invalid schedule day")
            start = str(sched.get("start", "00:00"))
            end = str(sched.get("end", "23:59"))
            for t in (start, end):
                parts = t.split(":")
                if len(parts) != 2 or not all(p.isdigit() for p in parts):
                    raise ValueError("Invalid schedule time")
                h, m = map(int, parts)
                if not (0 <= h <= 23 and 0 <= m <= 59):
                    raise ValueError("Invalid schedule time")
            schedules.append({"days": days, "start": start, "end": end})

        out["cameras"][name] = {
            "enabled": bool(cam.get("enabled", True)),
            "schedule": schedules
        }
    return out

def compose(*args):
    return subprocess.run(
        ["docker", "compose", *args],
        cwd=COMPOSE_DIR,
        text=True,
        capture_output=True,
        timeout=30,
        check=False,
    )

class Handler(BaseHTTPRequestHandler):
    def authorized(self):
        return self.headers.get("Authorization") == f"Bearer {TOKEN}"

    def send_json(self, status, obj):
        body = json.dumps(obj).encode()
        self.send_response(status)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def do_GET(self):
        if not self.authorized():
            self.send_json(401, {"error":"unauthorized"}); return

        if self.path == "/settings":
            try:
                self.send_json(200, load_settings())
            except Exception as e:
                self.send_json(500, {"error":str(e)})
            return

        if self.path == "/status":
            result = compose("ps", "--format", "json")
            containers = {}
            if result.returncode == 0:
                for line in result.stdout.splitlines():
                    try:
                        item = json.loads(line)
                    except json.JSONDecodeError:
                        continue
                    name = item.get("Service") or item.get("Name")
                    if name in ALLOWED_CONTAINERS:
                        state = str(item.get("State", "unknown")).lower()
                        containers[name] = {"state": state}
            for name in ALLOWED_CONTAINERS:
                containers.setdefault(name, {"state":"unknown"})
            try:
                disk = disk_status()
            except Exception:
                disk = {"total":0,"used":0,"free":0,"percent_used":0}
            self.send_json(200, {"containers":containers, "disk":disk})
            return

        if self.path == "/protected":
            self.send_json(200, load_protected())
            return

        self.send_json(404, {"error":"not found"})

    def do_POST(self):
        if not self.authorized():
            self.send_json(401, {"error":"unauthorized"}); return

        length = int(self.headers.get("Content-Length", "0"))
        if length > 1024 * 64:
            self.send_json(413, {"error":"request too large"}); return
        raw = self.rfile.read(length) if length else b"{}"
        try:
            data = json.loads(raw)
        except json.JSONDecodeError:
            self.send_json(400, {"error":"invalid json"}); return

        if self.path == "/settings":
            try:
                clean = validate_settings(data)
                atomic_json_write(SETTINGS_FILE, clean)
                self.send_json(200, {"ok":True})
            except Exception as e:
                self.send_json(400, {"error":str(e)})
            return

        if self.path == "/restart":
            target = data.get("target")
            if target not in ALLOWED_CONTAINERS:
                self.send_json(400, {"error":"target not allowed"}); return
            result = compose("restart", target)
            if result.returncode != 0:
                self.send_json(500, {"error": result.stderr[-1000:]})
                return
            self.send_json(200, {"ok":True, "target":target})
            return

        if self.path in ("/protect", "/unprotect", "/delete"):
            rel = data.get("file")
            try:
                path = safe_recording_path(rel)
            except Exception as e:
                self.send_json(400, {"error":str(e)}); return

            protected = load_protected()
            files = set(protected["files"])

            if self.path == "/protect":
                if not path.exists():
                    self.send_json(404, {"error":"recording not found"}); return
                files.add(rel)
                save_protected({"files":list(files)})
                self.send_json(200, {"ok":True, "protected":True})
                return

            if self.path == "/unprotect":
                files.discard(rel)
                save_protected({"files":list(files)})
                self.send_json(200, {"ok":True, "protected":False})
                return

            if rel in files:
                self.send_json(409, {"error":"Protected recordings must be unprotected before deletion"}); return
            if not path.exists():
                self.send_json(404, {"error":"recording not found"}); return
            path.unlink()
            thumb = path.with_suffix(".jpg")
            if thumb.exists():
                try: thumb.unlink()
                except OSError: pass
            self.send_json(200, {"ok":True})
            return

        self.send_json(404, {"error":"not found"})

    def log_message(self, fmt, *args):
        return

if __name__ == "__main__":
    if HOST not in ("127.0.0.1", "::1", "localhost"):
        raise SystemExit("Refusing to bind helper to non-loopback address")
    print(f"Wyze dashboard helper listening on {HOST}:{PORT}", flush=True)
    ThreadingHTTPServer((HOST, PORT), Handler).serve_forever()
