#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
avrcp_ptt.py
Push-To-Talk via Bluetooth AVRCP media key events.

Backends:
- DBus (default): listens to BlueZ MediaControl1 KeyPressed via `dbus-monitor`
- btmon (optional): parses raw btmon output (older but sometimes very reliable)

Writes a trigger token into VOXIE_PTT_FIFO (default: /tmp/bitvox_ptt.fifo)

Environment:
- VOXIE_PTT_FIFO=/tmp/bitvox_ptt.fifo
- VOXIE_PTT_TOKEN=PTT
- VOXIE_PTT_DEBOUNCE_SEC=0.35
- VOXIE_AVRCP_BACKEND=dbus|btmon   (default: dbus)
- VOXIE_AVRCP_KEYS=playpause,play,pause  (dbus backend)
"""

import os
import re
import sys
import time
import shutil
import argparse
import subprocess
from pathlib import Path
from typing import List


FIFO = os.environ.get("VOXIE_PTT_FIFO", "/tmp/bitvox_ptt.fifo")
TRIGGER = os.environ.get("VOXIE_PTT_TOKEN", "PTT")
DEFAULT_DEBOUNCE = float(os.environ.get("VOXIE_PTT_DEBOUNCE_SEC", "0.35"))

DEFAULT_BACKEND = (os.environ.get("VOXIE_AVRCP_BACKEND", "dbus") or "dbus").strip().lower()
DEFAULT_KEYS = os.environ.get("VOXIE_AVRCP_KEYS", "playpause,play,pause")

# Old-school btmon patterns observed in some setups (kept optional).
# Example sequences in btmon dumps (press):
# 48 7c 44 00  (play press)
# 48 7c 46 00  (pause press)
BTMON_PRESS_RE = re.compile(r"\b48\s+7c\s+(44|46)\s+00\b", re.IGNORECASE)

# dbus-monitor: we extract any string payload and match keys.
DBUS_KEY_RE = re.compile(r'string\s+"([^"]+)"', re.IGNORECASE)


def log(msg: str) -> None:
    print(msg, flush=True)


def _which(cmd: str) -> bool:
    return shutil.which(cmd) is not None


def ensure_fifo(path: str) -> None:
    p = Path(path)
    if p.exists():
        return
    try:
        os.mkfifo(path, 0o666)
    except Exception:
        # Fallback for environments where mkfifo syscall fails
        os.system(f"mkfifo {path} && chmod 666 {path}")


def fifo_trigger(path: str, token: str) -> bool:
    # Non-blocking: if no reader, we don't hang.
    try:
        fd = os.open(path, os.O_WRONLY | os.O_NONBLOCK)
        try:
            os.write(fd, (token + "\n").encode("utf-8"))
        finally:
            os.close(fd)
        return True
    except OSError:
        return False


def parse_keys(keys_csv: str) -> List[str]:
    out: List[str] = []
    for k in (keys_csv or "").split(","):
        k = k.strip().lower()
        if k:
            out.append(k)
    return out


def dbus_monitor_cmd() -> List[str]:
    # Keep the filter broad across BlueZ versions.
    return ["dbus-monitor", "--system", "type='signal',sender='org.bluez'"]


def run_dbus(keys: List[str], debounce: float) -> int:
    if not _which("dbus-monitor"):
        log("[AVRCP_PTT][FATAL] dbus-monitor not found. Install: sudo apt-get install dbus")
        return 1

    log(f"[AVRCP_PTT] backend=dbus fifo={FIFO} token={TRIGGER} debounce={debounce}")
    log(f"[AVRCP_PTT] keys={keys}")
    log("[AVRCP_PTT] listening for BlueZ key events…")

    last_ts = 0.0

    while True:
        try:
            proc = subprocess.Popen(
                dbus_monitor_cmd(),
                stdout=subprocess.PIPE,
                stderr=subprocess.DEVNULL,
                text=True,
                bufsize=1,
                universal_newlines=True,
            )

            assert proc.stdout is not None
            for line in proc.stdout:
                m = DBUS_KEY_RE.search(line)
                if not m:
                    continue

                key = (m.group(1) or "").strip().lower()
                if key not in keys:
                    continue

                now = time.time()
                if now - last_ts < debounce:
                    continue
                last_ts = now

                ok = fifo_trigger(FIFO, TRIGGER)
                if ok:
                    log(f"[AVRCP_PTT] PTT (key={key})")
                else:
                    log(f"[AVRCP_PTT] detected key={key} but FIFO has no reader")

        except KeyboardInterrupt:
            log("\n[AVRCP_PTT] exit")
            return 0
        except Exception as e:
            log(f"[AVRCP_PTT][WARN] dbus error: {e}")
            log("[AVRCP_PTT] restarting in 1s…")
            time.sleep(1.0)


def run_btmon(debounce: float) -> int:
    if not _which("btmon"):
        log("[AVRCP_PTT][FATAL] btmon not found. Install: sudo apt-get install bluez")
        return 1

    log(f"[AVRCP_PTT] backend=btmon fifo={FIFO} token={TRIGGER} debounce={debounce}")
    log("[AVRCP_PTT] starting btmon… (press PLAY/PAUSE on your device)")

    last_ts = 0.0

    try:
        proc = subprocess.Popen(
            ["btmon"],
            stdout=subprocess.PIPE,
            stderr=subprocess.STDOUT,
            text=True,
            bufsize=1,
            universal_newlines=True,
        )
        assert proc.stdout is not None
        for line in proc.stdout:
            if not BTMON_PRESS_RE.search(line):
                continue

            now = time.time()
            if now - last_ts < debounce:
                continue
            last_ts = now

            ok = fifo_trigger(FIFO, TRIGGER)
            if ok:
                log("[AVRCP_PTT] PTT")
            else:
                log("[AVRCP_PTT] detected press but FIFO has no reader")

    except KeyboardInterrupt:
        log("\n[AVRCP_PTT] exit")
        return 0
    finally:
        try:
            proc.terminate()
        except Exception:
            pass

    return 0


def main() -> int:
    ap = argparse.ArgumentParser(description="Voxie PTT via AVRCP (BlueZ)")
    ap.add_argument("--backend", default=DEFAULT_BACKEND, choices=["dbus", "btmon"],
                    help="Backend to read AVRCP keys (default: dbus)")
    ap.add_argument("--keys", default=DEFAULT_KEYS,
                    help="Comma-separated keys to trigger PTT (dbus backend only)")
    ap.add_argument("--debounce", type=float, default=DEFAULT_DEBOUNCE,
                    help="Debounce seconds")
    args = ap.parse_args()

    ensure_fifo(FIFO)

    backend = (args.backend or "dbus").strip().lower()
    if backend == "btmon":
        return run_btmon(args.debounce)

    keys = parse_keys(args.keys)
    if not keys:
        log("[AVRCP_PTT][ERR] No keys configured.")
        return 2

    return run_dbus(keys, args.debounce)


if __name__ == "__main__":
    raise SystemExit(main())
