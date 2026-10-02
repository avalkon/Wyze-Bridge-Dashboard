import json
import os
import re
import signal
import subprocess
import threading
import time
from datetime import datetime
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path

SETTINGS_FILE = Path(os.getenv("SETTINGS_FILE", "/config/dashboard-settings.json"))
EVENT_FILE = Path(os.getenv("EVENT_FILE", "/state/events.json"))
RECORD_ROOT = Path(os.getenv("RECORD_ROOT", "/recordings"))
RTSP_BASE = os.getenv("RTSP_BASE", "rtsp://wyze-bridge:8554").rstrip("/")
PROTECTED_FILE = Path(os.getenv("PROTECTED_FILE", "/state/protected.json"))
WEBHOOK_PORT = int(os.getenv("WEBHOOK_PORT", "8080"))
CHECK_SECONDS = 10
CLEANUP_SECONDS = 60

lock = threading.RLock()
processes = {}
process_segment_lengths = {}

def safe_camera(name):
    return re.sub(r"[^A-Za-z0-9_-]", "-", name).strip("-").lower()

def load_settings():
    with SETTINGS_FILE.open() as f:
        return json.load(f)

def atomic_write(path, data):
    path.parent.mkdir(parents=True, exist_ok=True)
    tmp = path.with_suffix(path.suffix + ".tmp")
    tmp.write_text(json.dumps(data, indent=2) + "\n")
    tmp.replace(path)

def load_protected():
    if not PROTECTED_FILE.exists():
        return set()
    try:
        data = json.loads(PROTECTED_FILE.read_text())
        return set(str(x) for x in data.get("files", []))
    except Exception:
        return set()

def video_probe_ok(path):
    try:
        r = subprocess.run([
            "ffprobe", "-v", "error",
            "-select_streams", "v:0",
            "-show_entries", "stream=codec_name",
            "-of", "default=nw=1:nk=1",
            str(path),
        ], capture_output=True, text=True, timeout=10, check=False)
        return r.returncode == 0 and bool(r.stdout.strip())
    except Exception:
        return False

def make_thumbnail(path):
    jpg = path.with_suffix(".jpg")
    if jpg.exists():
        return
    if not video_probe_ok(path):
        print(f"SKIP thumbnail; clip not readable yet: {path}", flush=True)
        return
    try:
        subprocess.run(
            ["ffmpeg", "-hide_banner", "-loglevel", "error",
             "-ss", "2", "-i", str(path), "-frames:v", "1",
             "-vf", "scale='min(640,iw)':-2", "-q:v", "5", str(jpg)],
            timeout=20, check=False
        )
    except Exception as e:
        print(f"thumbnail failed {path}: {e}", flush=True)

def load_events():
    if not EVENT_FILE.exists():
        return {"events":[]}
    try:
        data = json.loads(EVENT_FILE.read_text())
        if isinstance(data, dict) and isinstance(data.get("events"), list):
            return data
    except Exception:
        pass
    return {"events":[]}

def add_motion(camera):
    now = int(time.time())
    with lock:
        data = load_events()
        data["events"].append({"ts":now, "camera":camera, "type":"motion"})
        # Keep event metadata bounded to roughly one year.
        cutoff = now - 366 * 86400
        data["events"] = [e for e in data["events"] if int(e.get("ts",0)) >= cutoff][-50000:]
        atomic_write(EVENT_FILE, data)
    print(f"MOTION {camera} {datetime.fromtimestamp(now).isoformat()}", flush=True)

def parse_hhmm(s):
    h, m = map(int, s.split(":"))
    return h * 60 + m

def schedule_active(cam_cfg, now=None):
    if not cam_cfg.get("enabled", True):
        return False
    schedules = cam_cfg.get("schedule") or []
    if not schedules:
        return True

    now = now or datetime.now()
    day = now.weekday()
    minute = now.hour * 60 + now.minute

    for sched in schedules:
        days = sched.get("days", [])
        start = parse_hhmm(sched.get("start","00:00"))
        end = parse_hhmm(sched.get("end","23:59"))

        if start <= end:
            if day in days and start <= minute <= end:
                return True
        else:
            # Overnight: e.g. Mon 18:00 -> Tue 06:00
            prev_day = (day - 1) % 7
            if (day in days and minute >= start) or (prev_day in days and minute <= end):
                return True
    return False

def start_recorder(camera, segment_seconds):
    camdir = RECORD_ROOT / camera
    camdir.mkdir(parents=True, exist_ok=True)
    output = str(camdir / "%Y-%m-%d_%H-%M-%S.mp4")
    url = f"{RTSP_BASE}/{camera}"

    cmd = [
        "ffmpeg",
        "-hide_banner", "-loglevel", "warning",
        "-rtsp_transport", "tcp",
        "-i", url,
        "-map", "0",
        "-c", "copy",
        "-f", "segment",
        "-segment_time", str(segment_seconds),
        "-reset_timestamps", "1",
        "-strftime", "1",
        output,
    ]
    print(f"START recorder {camera}: {url}, {segment_seconds}s segments", flush=True)
    p = subprocess.Popen(cmd)
    processes[camera] = p
    process_segment_lengths[camera] = segment_seconds

def stop_recorder(camera):
    p = processes.pop(camera, None)
    process_segment_lengths.pop(camera, None)
    if not p:
        return
    print(f"STOP recorder {camera}", flush=True)
    try:
        p.send_signal(signal.SIGINT)
        p.wait(timeout=10)
    except Exception:
        p.kill()

def recording_loop():
    while True:
        try:
            settings = load_settings()
            wanted_cameras = set(settings.get("cameras", {}).keys())
            seg = int(settings.get("segment_seconds", 300))

            for camera, camcfg in settings.get("cameras", {}).items():
                camera = safe_camera(camera)
                active = schedule_active(camcfg)
                p = processes.get(camera)

                if p and p.poll() is not None:
                    print(f"Recorder exited for {camera} rc={p.returncode}", flush=True)
                    processes.pop(camera, None)
                    process_segment_lengths.pop(camera, None)
                    p = None

                if active:
                    if p is None:
                        start_recorder(camera, seg)
                    elif process_segment_lengths.get(camera) != seg:
                        stop_recorder(camera)
                        start_recorder(camera, seg)
                elif p is not None:
                    stop_recorder(camera)

            for camera in list(processes):
                if camera not in wanted_cameras:
                    stop_recorder(camera)

        except Exception as e:
            print(f"recording loop error: {e}", flush=True)

        time.sleep(CHECK_SECONDS)

def event_overlaps(camera, start_ts, end_ts, before, after, events):
    low = start_ts - before
    high = end_ts + after
    for e in events:
        if e.get("camera") != camera or e.get("type","motion") != "motion":
            continue
        ts = int(e.get("ts",0))
        if low <= ts <= high:
            return True
    return False

def cleanup_loop():
    while True:
        try:
            settings = load_settings()
            now = time.time()
            delete_after = int(settings.get("delete_unprotected_after_minutes",30)) * 60
            keep_motion = int(settings.get("keep_motion_days",30)) * 86400
            before = int(settings.get("motion_before_seconds",300))
            after = int(settings.get("motion_after_seconds",300))
            segment = int(settings.get("segment_seconds",300))

            with lock:
                events = load_events().get("events", [])

            for path in RECORD_ROOT.rglob("*.mp4"):
                try:
                    stat = path.stat()
                except OSError:
                    continue

                # A segment that is still open changes mtime; leave recent files alone.
                age = now - stat.st_mtime
                if age < max(segment + 30, 120):
                    continue

                make_thumbnail(path)

                relative = str(path.relative_to(RECORD_ROOT)).replace(os.sep, "/")
                if relative in load_protected():
                    continue

                camera = safe_camera(path.parent.name)
                end_ts = stat.st_mtime
                start_ts = end_ts - segment
                motion = event_overlaps(camera, start_ts, end_ts, before, after, events)

                threshold = keep_motion if motion else delete_after
                if age >= threshold:
                    try:
                        path.unlink()
                        jpg = path.with_suffix(".jpg")
                        if jpg.exists():
                            try: jpg.unlink()
                            except OSError: pass
                        print(f"DELETE {'motion-expired' if motion else 'rolling'} {path}", flush=True)
                    except OSError as e:
                        print(f"delete failed {path}: {e}", flush=True)

        except Exception as e:
            print(f"cleanup loop error: {e}", flush=True)

        time.sleep(CLEANUP_SECONDS)

class Handler(BaseHTTPRequestHandler):
    def do_GET(self):
        if self.path == "/health":
            body = b"ok\n"
            self.send_response(200)
            self.send_header("Content-Type", "text/plain")
            self.send_header("Content-Length", str(len(body)))
            self.end_headers()
            self.wfile.write(body)
            return
        self.send_response(404)
        self.end_headers()

    def do_POST(self):
        if not self.path.startswith("/motion"):
            self.send_response(404); self.end_headers(); return
        camera = self.headers.get("X-Camera")
        if not camera:
            self.send_response(400); self.end_headers(); return
        add_motion(safe_camera(camera))
        self.send_response(200)
        self.end_headers()
        self.wfile.write(b"ok\n")

    def log_message(self, fmt, *args):
        return

if __name__ == "__main__":
    RECORD_ROOT.mkdir(parents=True, exist_ok=True)
    EVENT_FILE.parent.mkdir(parents=True, exist_ok=True)
    threading.Thread(target=recording_loop, daemon=True).start()
    threading.Thread(target=cleanup_loop, daemon=True).start()
    print(f"motion-recorder webhook listening on :{WEBHOOK_PORT}", flush=True)
    ThreadingHTTPServer(("0.0.0.0", WEBHOOK_PORT), Handler).serve_forever()
