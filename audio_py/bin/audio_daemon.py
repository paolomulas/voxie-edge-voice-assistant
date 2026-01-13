#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
audio_daemon.py
Unix socket audio daemon for Voxie / bitVox

Listens on VOXIE_AUDIO_SOCK (default: /tmp/bitvox_audio.sock)
Receives newline-delimited messages (JSON or your protocol parser)
and executes simple audio commands via src/audio/*.

Goals:
- No hardcoded user paths
- Clean socket lifecycle (unlink stale, chmod, graceful shutdown)
- Safe, minimal command surface
- Works on constrained hardware
"""

import os
import sys
import socket
import signal
from pathlib import Path
from typing import Dict, Any, Optional

# ------------------------------------------------------------
# Ensure local src/ is on PYTHONPATH (repo-relative)
# ------------------------------------------------------------
BASE_DIR = Path(__file__).resolve().parent.parent
SRC_DIR = BASE_DIR / "src"
if str(SRC_DIR) not in sys.path:
    sys.path.insert(0, str(SRC_DIR))

# ------------------------------------------------------------
# Imports (keep as you designed, but fail gracefully)
# ------------------------------------------------------------
try:
    from audio import play_wav, play_mp3, play_stream, stop, is_playing
    from audio.protocol import parse_line, reply
except Exception as e:
    print(f"[AUDIO_DAEMON][FATAL] Import error: {e}", flush=True)
    print("Expected: src/audio.py and src/audio/protocol.py (or package).", flush=True)
    sys.exit(1)

# ------------------------------------------------------------
# Config
# ------------------------------------------------------------
SOCK_PATH = os.environ.get("VOXIE_AUDIO_SOCK") or os.environ.get("AUDIO_SOCK") or "/tmp/bitvox_audio.sock"
SOCK_PATH = str(SOCK_PATH)

DEBUG = int(os.environ.get("DEBUG", "0"))


def log(msg: str) -> None:
    print(msg, flush=True)


def dlog(msg: str) -> None:
    if DEBUG:
        print(msg, flush=True)


def _safe_str(x: Any) -> str:
    return "" if x is None else str(x)


def handle(cmd: Dict[str, Any]) -> Dict[str, Any]:
    """
    Execute an audio command.
    Expected cmd format (examples):
      {"cmd":"STOP"}
      {"cmd":"STATUS"}
      {"cmd":"PLAY_WAV","path":"/path/file.wav"}
      {"cmd":"PLAY_MP3","path":"/path/file.mp3"}
      {"cmd":"PLAY_STREAM","url":"http://..."}  (or "src")
    """
    c = _safe_str(cmd.get("cmd")).strip()
    c_up = c.upper()

    if not c_up:
        return {"ok": False, "err": "BAD_REQUEST", "msg": "Missing cmd"}

    if c_up in ("PING", "HELLO"):
        return {"ok": True, "pong": True}

    if c_up == "STOP":
        stop()
        return {"ok": True}

    if c_up == "STATUS":
        return {"ok": True, "playing": bool(is_playing())}

    if c_up == "PLAY_WAV":
        path = _safe_str(cmd.get("path"))
        if not path:
            return {"ok": False, "err": "BAD_REQUEST", "msg": "Missing path"}
        play_wav(path)
        return {"ok": True}

    if c_up == "PLAY_MP3":
        path = _safe_str(cmd.get("path"))
        if not path:
            return {"ok": False, "err": "BAD_REQUEST", "msg": "Missing path"}
        play_mp3(path)
        return {"ok": True}

    if c_up == "PLAY_STREAM":
        url = _safe_str(cmd.get("url") or cmd.get("src"))
        if not url:
            return {"ok": False, "err": "BAD_REQUEST", "msg": "Missing url/src"}
        play_stream(url)
        return {"ok": True}

    return {"ok": False, "err": "UNKNOWN_CMD", "cmd": c}


def _cleanup_socket(path: str) -> None:
    try:
        p = Path(path)
        if p.exists():
            p.unlink()
    except Exception:
        pass


def main() -> None:
    sock_file = Path(SOCK_PATH)

    _cleanup_socket(SOCK_PATH)

    srv = socket.socket(socket.AF_UNIX, socket.SOCK_STREAM)
    srv.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)

    try:
        srv.bind(SOCK_PATH)
    except Exception as e:
        log(f"[AUDIO_DAEMON][FATAL] bind failed: {SOCK_PATH} ({e})")
        sys.exit(2)

    # Make it easy for PHP to connect (local machine only anyway)
    try:
        os.chmod(SOCK_PATH, 0o666)
    except Exception:
        pass

    srv.listen(16)
    log(f"[AUDIO_DAEMON] listening on {SOCK_PATH}")

    running = True

    def _sig(_signum, _frame):
        nonlocal running
        running = False
        try:
            srv.close()
        except Exception:
            pass
        _cleanup_socket(SOCK_PATH)
        log("[AUDIO_DAEMON] shutdown")

    signal.signal(signal.SIGINT, _sig)
    signal.signal(signal.SIGTERM, _sig)

    while running:
        try:
            conn, _addr = srv.accept()
        except Exception:
            # socket closed during shutdown
            if running:
                continue
            break

        try:
            conn.settimeout(2.0)
            # Read newline-delimited messages; parse_line should handle framing
            buf = b""
            while True:
                chunk = conn.recv(4096)
                if not chunk:
                    break
                buf += chunk
                # Process line by line
                while b"\n" in buf:
                    line, buf = buf.split(b"\n", 1)
                    line_str = line.decode("utf-8", errors="ignore").strip()
                    if not line_str:
                        continue

                    dlog(f"[AUDIO_DAEMON] << {line_str}")

                    try:
                        cmd = parse_line(line_str)
                        res = handle(cmd)
                        payload = reply(res)
                    except Exception as e:
                        payload = reply({"ok": False, "err": "EXC", "msg": str(e)})

                    try:
                        conn.sendall(payload)
                    except Exception:
                        break

        except Exception as e:
            try:
                conn.sendall(reply({"ok": False, "err": "EXC", "msg": str(e)}))
            except Exception:
                pass
        finally:
            try:
                conn.close()
            except Exception:
                pass

    try:
        srv.close()
    except Exception:
        pass
    _cleanup_socket(SOCK_PATH)


if __name__ == "__main__":
    main()
