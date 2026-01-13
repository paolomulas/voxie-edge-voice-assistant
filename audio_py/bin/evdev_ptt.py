#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
evdev_ptt.py
Push-To-Talk via Linux input event device (/dev/input/eventX).

Reads EV_KEY events from the chosen device and writes VOXIE_PTT_TOKEN into VOXIE_PTT_FIFO.

Environment:
- VOXIE_PTT_EVDEV=/dev/input/event2
- VOXIE_PTT_FIFO=/tmp/bitvox_ptt.fifo
- VOXIE_PTT_TOKEN=PTT
- VOXIE_PTT_DEBOUNCE_SEC=0.35
- VOXIE_PTT_KEYCODES=200,201,164   (default includes PLAYCD+PAUSECD+PLAYPAUSE)

Notes:
- No external deps; parses evdev events in binary format (struct input_event).
- Works on older Raspberry Pi (ARMv6) with Python 3.x.
"""

import os
import sys
import time
import struct
import argparse
from pathlib import Path
from typing import List, Optional


DEFAULT_FIFO = os.environ.get("VOXIE_PTT_FIFO", "/tmp/bitvox_ptt.fifo")
DEFAULT_TOKEN = os.environ.get("VOXIE_PTT_TOKEN", "PTT")
DEFAULT_DEV = os.environ.get("VOXIE_PTT_EVDEV", "")  # empty = require explicit
DEFAULT_DEBOUNCE = float(os.environ.get("VOXIE_PTT_DEBOUNCE_SEC", "0.35"))
DEFAULT_KEYCODES = os.environ.get("VOXIE_PTT_KEYCODES", "200,201,164")

# Linux input_event on 32-bit: struct timeval (2x long) + type (H) + code (H) + value (I)
# On Pi OS armv6 (32-bit), long = 4 bytes => 16 bytes total.
# We'll also accept 24 bytes (64-bit) just in case someone runs it elsewhere.
FMT_32 = "llHHI"
FMT_64 = "qqHHI"
SIZE_32 = struct.calcsize(FMT_32)  # 16
SIZE_64 = struct.calcsize(FMT_64)  # 24

EV_KEY = 0x01


def log(msg: str) -> None:
    print(msg, flush=True)


def ensure_fifo(path: str) -> None:
    p = Path(path)
    if p.exists():
        return
    try:
        os.mkfifo(path, 0o666)
    except Exception:
        os.system(f"mkfifo {path} && chmod 666 {path}")


def fifo_trigger(path: str, token: str) -> bool:
    try:
        fd = os.open(path, os.O_WRONLY | os.O_NONBLOCK)
        try:
            os.write(fd, (token + "\n").encode("utf-8"))
        finally:
            os.close(fd)
        return True
    except OSError:
        return False


def parse_int_list(csv: str) -> List[int]:
    out: List[int] = []
    for part in (csv or "").split(","):
        part = part.strip()
        if not part:
            continue
        try:
            out.append(int(part, 10))
        except ValueError:
            pass
    return out


def list_devices() -> int:
    base = Path("/dev/input")
    if not base.exists():
        log("[EVDEV_PTT] /dev/input not found.")
        return 1

    evs = sorted(base.glob("event*"))
    if not evs:
        log("[EVDEV_PTT] No /dev/input/event* devices found.")
        return 1

    log("[EVDEV_PTT] Available input devices:")
    for dev in evs:
        name = "unknown"
        try:
            with open(dev, "rb", buffering=0) as f:
                # Try to read device name via sysfs (more reliable)
                sys_name = Path("/sys/class/input") / dev.name / "device" / "name"
                if sys_name.exists():
                    name = sys_name.read_text(errors="ignore").strip()
        except Exception:
            pass
        log(f"  - {dev} :: {name}")
    return 0


def read_device_name(dev: str) -> str:
    try:
        sys_name = Path("/sys/class/input") / Path(dev).name / "device" / "name"
        if sys_name.exists():
            return sys_name.read_text(errors="ignore").strip()
    except Exception:
        pass
    return "unknown"


def main() -> int:
    ap = argparse.ArgumentParser(description="Voxie PTT via evdev (/dev/input/eventX)")
    ap.add_argument("--list", action="store_true", help="List available /dev/input/event* devices")
    ap.add_argument("--dev", default=DEFAULT_DEV, help="Input device path (e.g. /dev/input/event2)")
    ap.add_argument("--fifo", default=DEFAULT_FIFO, help="PTT FIFO path")
    ap.add_argument("--token", default=DEFAULT_TOKEN, help="Token written to FIFO")
    ap.add_argument("--keycodes", default=DEFAULT_KEYCODES,
                    help="Comma-separated key codes that trigger PTT (default: 200,201,164)")
    ap.add_argument("--debounce", type=float, default=DEFAULT_DEBOUNCE, help="Debounce seconds")
    args = ap.parse_args()

    if args.list:
        return list_devices()

    dev = (args.dev or "").strip()
    if not dev:
        log("[EVDEV_PTT][ERR] Missing --dev (or VOXIE_PTT_EVDEV).")
        log("[EVDEV_PTT] Run: python3 audio_py/bin/evdev_ptt.py --list")
        return 2

    keycodes = parse_int_list(args.keycodes)
    if not keycodes:
        log("[EVDEV_PTT][ERR] No keycodes configured.")
        return 2

    ensure_fifo(args.fifo)

    log(f"[EVDEV_PTT] fifo={args.fifo} token={args.token}")
    log(f"[EVDEV_PTT] dev={dev}")
    log(f"[EVDEV_PTT] keycodes={keycodes} (Tip: EBS-313 is usually 200/201)")
    log("[EVDEV_PTT] waiting for key events…")
    log(f"[EVDEV_PTT] device name: {read_device_name(dev)}")

    last_ts = 0.0

    try:
        with open(dev, "rb", buffering=0) as f:
            while True:
                data = f.read(SIZE_32)
                if not data:
                    time.sleep(0.01)
                    continue

                if len(data) == SIZE_32:
                    sec, usec, etype, code, value = struct.unpack(FMT_32, data)
                else:
                    # Try reading remaining bytes for 64-bit struct if partial read
                    rest = f.read(SIZE_64 - len(data))
                    blob = data + rest
                    if len(blob) != SIZE_64:
                        continue
                    sec, usec, etype, code, value = struct.unpack(FMT_64, blob)

                # value: 1 = key press, 0 = release, 2 = autorepeat
                if etype != EV_KEY or value != 1:
                    continue
                if code not in keycodes:
                    continue

                now = time.time()
                if now - last_ts < args.debounce:
                    continue
                last_ts = now

                ok = fifo_trigger(args.fifo, args.token)
                if ok:
                    log(f"[EVDEV_PTT] PTT (code={code})")
                else:
                    log(f"[EVDEV_PTT] code={code} but FIFO has no reader")

    except PermissionError:
        log(f"[EVDEV_PTT][FATAL] Permission denied opening {dev}.")
        log("Try: sudo or add user to input group (or udev rule).")
        return 3
    except KeyboardInterrupt:
        log("\n[EVDEV_PTT] exit")
        return 0


if __name__ == "__main__":
    raise SystemExit(main())
