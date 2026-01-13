#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "$0")/.." || exit 1

# carica env (shell-style) e le esporta
set -a
[ -f ./.env ] && . ./.env
set +a

# fallback per vecchi nomi env (evita patch oggi)
export BITVOXPTTFIFO="${BITVOXPTTFIFO:-${VOXIEPTTFIFO:-/tmp/bitvoxptt.fifo}}"

# IPC clean
mkdir -p /tmp
rm -f "${VOXIEAUDIOSOCK:-/tmp/bitvoxaudio.sock}"
if [ ! -p "${VOXIEPTTFIFO:-/tmp/bitvoxptt.fifo}" ]; then
  rm -f "${VOXIEPTTFIFO:-/tmp/bitvoxptt.fifo}"
  mkfifo "${VOXIEPTTFIFO:-/tmp/bitvoxptt.fifo}"
fi
chmod 666 "${VOXIEPTTFIFO:-/tmp/bitvoxptt.fifo}" || true

# log dir
mkdir -p logs run

# kill eventuali vecchi processi (best-effort)
pkill -f "audio_daemon.py" 2>/dev/null || true
pkill -f "voxie_listen.py" 2>/dev/null || true
pkill -f "evdev_ptt.py" 2>/dev/null || true
pkill -f "avrcp_ptt.py" 2>/dev/null || true
pkill -f "wake_poll.py" 2>/dev/null || true

# start audio daemon
nohup python3 ./audio_py/bin/audio_daemon.py > logs/audio_daemon.log 2>&1 & echo $! > run/audio_daemon.pid
sleep 0.2

# start PTT source: scegli UNO (evdev consigliato)
if [ -e "${VOXIEEVENTDEV:-/dev/input/event2}" ]; then
  nohup python3 ./audio_py/bin/evdev_ptt.py > logs/ptt.log 2>&1 & echo $! > run/ptt.pid
else
  nohup python3 ./audio_py/bin/avrcp_ptt.py > logs/ptt.log 2>&1 & echo $! > run/ptt.pid
fi
sleep 0.2

# start listener PTT -> REC -> ASR -> AGENT
nohup python3 ./audio_py/bin/voxie_listen.py > logs/listen.log 2>&1 & echo $! > run/listen.pid

# opzionale: VAD trigger (se lo usi davvero)
# nohup python3 ./wake_poll.py > logs/wake.log 2>&1 & echo $! > run/wake.pid

echo "OK - started."
echo "Logs: $(pwd)/logs/*.log"
