#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.." || exit 1

for f in run/*.pid; do
  [ -f "$f" ] || continue
  kill "$(cat "$f")" 2>/dev/null || true
done

pkill -f "audio_daemon.py" 2>/dev/null || true
pkill -f "voxie_listen.py" 2>/dev/null || true
pkill -f "evdev_ptt.py" 2>/dev/null || true
pkill -f "avrcp_ptt.py" 2>/dev/null || true
pkill -f "wake_poll.py" 2>/dev/null || true

rm -f run/*.pid 2>/dev/null || true
echo "OK - stopped."
