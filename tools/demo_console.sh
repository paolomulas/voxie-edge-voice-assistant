#!/usr/bin/env bash
set -euo pipefail

# ---------------------------------------
# Resolve repo root (portable default)
# ---------------------------------------
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DEFAULT_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"

ROOT="${VOXIE_ROOT:-$DEFAULT_ROOT}"
MIC_DEV="${VOXIE_MIC_DEV:-plughw:1,0}"
REC_SEC="${VOXIE_REC_SEC:-4}"

# IMPORTANT:
# - Default to evdev (works on old RPis, no DBus quirks).
# - Use VOXIE_PTT_MODE=avrcp only if explicitly requested.
PTT_MODE="${VOXIE_PTT_MODE:-evdev}"

export VOXIE_ROOT="$ROOT"
export VOXIE_MIC_DEV="$MIC_DEV"
export VOXIE_REC_SEC="$REC_SEC"
export VOXIE_PTT_FIFO="${VOXIE_PTT_FIFO:-/tmp/bitvox_ptt.fifo}"

# Demo-friendly env
export TERM="${TERM:-xterm-256color}"
export LANG="${LANG:-C.UTF-8}"
export LC_ALL="${LC_ALL:-C.UTF-8}"

cd "$ROOT"

# ---------------------------------------
# Sanity checks
# ---------------------------------------
if ! command -v python3 >/dev/null 2>&1; then
  echo "[DEMO] ERROR: python3 not found in PATH" >&2
  exit 1
fi

if [ ! -f "audio_py/bin/audio_daemon.py" ] || [ ! -f "audio_py/bin/voxie_listen.py" ]; then
  echo "[DEMO] ERROR: expected audio_py/ scripts not found under ROOT=$ROOT" >&2
  echo "[DEMO]        missing: audio_py/bin/audio_daemon.py or audio_py/bin/voxie_listen.py" >&2
  exit 1
fi

# -----------------------------
# ANSI colors
# -----------------------------
ANSI=0
if [ -t 1 ] && [ "${TERM:-}" != "dumb" ]; then ANSI=1; fi

if [ "$ANSI" -eq 1 ]; then
  C_RESET=$'\033[0m'
  C_SYS=$'\033[38;5;33m'     # blue
  C_AUDIO=$'\033[38;5;40m'   # green
  C_LISTEN=$'\033[38;5;220m' # yellow
  C_PTT=$'\033[38;5;213m'    # pink
  C_ERR=$'\033[38;5;196m'    # red
else
  C_RESET=""; C_SYS=""; C_AUDIO=""; C_LISTEN=""; C_PTT=""; C_ERR=""
fi

say(){
  local color="$1"; shift
  local tag="$1"; shift
  local msg="$*"
  printf "%s[%s] [%s] %s%s\n" "$color" "$(date +'%H:%M:%S.%3N')" "$tag" "$msg" "$C_RESET"
}

# -----------------------------
# Banner
# -----------------------------
banner(){
  local w=58
  local title="VOXIE / BitVox — LOCAL VOICE ASSISTANT DEMO"
  local sub="Push-To-Talk · Offline-first routing · AI when needed"
  local hw="HW: Raspberry Pi Model B (ARMv6 · 512MB)"

  echo
  printf "╔%*s╗\n" "$w" "" | tr ' ' '═'
  printf "║ %-*s ║\n" "$w" "$title"
  printf "║ %-*s ║\n" "$w" "$sub"
  printf "║ %-*s ║\n" "$w" "$hw"
  printf "╚%*s╝\n" "$w" "" | tr ' ' '═'
  echo
}

banner

# -----------------------------
# Ensure PTT FIFO exists
# -----------------------------
PTT_FIFO="$VOXIE_PTT_FIFO"
if [ -e "$PTT_FIFO" ] && [ ! -p "$PTT_FIFO" ]; then
  say "$C_ERR" "DEMO" "PTT FIFO path exists but is not a FIFO: $PTT_FIFO"
  exit 1
fi

if [ ! -p "$PTT_FIFO" ]; then
  rm -f "$PTT_FIFO"
  mkfifo "$PTT_FIFO"
  chmod 666 "$PTT_FIFO"
fi

# -----------------------------
# Unbuffer helper
# -----------------------------
UNBUF=""
if command -v stdbuf >/dev/null 2>&1; then
  UNBUF="stdbuf -oL -eL"
fi

# -----------------------------
# Cleanup
# -----------------------------
CLEANED=0
cleanup(){
  [ "$CLEANED" -eq 1 ] && return
  CLEANED=1

  say "$C_SYS" "DEMO" "Shutting down Voxie…"
  [ -n "${AUDIO_PID:-}" ]  && kill "$AUDIO_PID" 2>/dev/null || true
  [ -n "${LISTEN_PID:-}" ] && kill "$LISTEN_PID" 2>/dev/null || true
  [ -n "${PTT_PID:-}" ]    && kill "$PTT_PID" 2>/dev/null || true
  wait 2>/dev/null || true
  say "$C_SYS" "DEMO" "Bye 👋"
}
trap cleanup INT TERM EXIT

# ---------------------------------------
# Helper: auto-pick a single evdev device
# ---------------------------------------
autopick_evdev(){
  mapfile -t EVDEVS < <(python3 audio_py/bin/evdev_ptt.py --list 2>/dev/null | grep -oE '/dev/input/event[0-9]+' | sort -u)
  if [ "${#EVDEVS[@]}" -eq 1 ]; then
    printf "%s" "${EVDEVS[0]}"
  fi
}

# ---------------------------------------
# Decide PTT mode:
# - If VOXIE_PTT_MODE is set: respect it.
# - Else if VOXIE_PTT_EVDEV is set: force evdev.
# - Else: default evdev.
# ---------------------------------------
decide_ptt_mode(){
  if [ -n "${VOXIE_PTT_MODE:-}" ]; then
    printf "%s" "$PTT_MODE"
    return
  fi
  if [ -n "${VOXIE_PTT_EVDEV:-}" ]; then
    printf "evdev"
    return
  fi
  printf "%s" "$PTT_MODE"
}

# -----------------------------
# AUDIO DAEMON
# -----------------------------
( $UNBUF python3 -u audio_py/bin/audio_daemon.py 2>&1 \
  | while IFS= read -r line; do
      case "$line" in
        *err*|*ERR*|*error*|*ERROR*|*exception*|*Exception*|*Traceback*|*failed*|*FAILED*)
          say "$C_ERR" "AUDIO" "🔊 $line"
          ;;
        *)
          say "$C_AUDIO" "AUDIO" "🔊 $line"
          ;;
      esac
    done ) &
AUDIO_PID=$!

# -----------------------------
# LISTENER
# -----------------------------
VOXIE_JSON="${VOXIE_JSON:-0}"

( $UNBUF python3 -u audio_py/bin/voxie_listen.py 2>&1 \
  | while IFS= read -r line; do
      if [ "$VOXIE_JSON" -eq 0 ] && printf "%s" "$line" | grep -q '^{'; then
        say "$C_LISTEN" "LISTEN" "🎧 $(printf "%s" "$line" | tr -d '\n' | cut -c1-220)"
      else
        say "$C_LISTEN" "LISTEN" "🎧 $line"
      fi
    done ) &
LISTEN_PID=$!

# -----------------------------
# PTT BRIDGE
# -----------------------------
PTT_MODE="$(decide_ptt_mode)"
PTT_PID=""

case "$PTT_MODE" in
  avrcp)
    ( $UNBUF python3 -u audio_py/bin/avrcp_ptt.py 2>&1 \
      | while IFS= read -r line; do
          say "$C_PTT" "PTT/AVRCP" "⏯  $line"
        done ) &
    PTT_PID=$!
    ;;
  evdev|*)
    # Determine evdev device:
    # 1) VOXIE_PTT_EVDEV (preferred)
    # 2) auto-pick if exactly one exists
    PTT_DEV="${VOXIE_PTT_EVDEV:-}"
    if [ -z "$PTT_DEV" ]; then
      PTT_DEV="$(autopick_evdev || true)"
      if [ -n "$PTT_DEV" ]; then
        export VOXIE_PTT_EVDEV="$PTT_DEV"
        say "$C_PTT" "PTT/EVDEV" "Auto-selected device: $PTT_DEV (set VOXIE_PTT_EVDEV to override)"
      else
        say "$C_ERR" "PTT/EVDEV" "Missing VOXIE_PTT_EVDEV and cannot auto-select."
        say "$C_ERR" "PTT/EVDEV" "Run: python3 audio_py/bin/evdev_ptt.py --list"
        say "$C_ERR" "PTT/EVDEV" "Then set: VOXIE_PTT_EVDEV=/dev/input/eventX"
      fi
    fi

    if [ -n "${VOXIE_PTT_EVDEV:-}" ]; then
      ( $UNBUF python3 -u audio_py/bin/evdev_ptt.py --dev "$VOXIE_PTT_EVDEV" 2>&1 \
        | while IFS= read -r line; do
            say "$C_PTT" "PTT/EVDEV" "⏯  $line"
          done ) &
      PTT_PID=$!
    fi
    ;;
esac

echo
say "$C_SYS" "DEMO" "ROOT=$ROOT"
say "$C_SYS" "DEMO" "MIC=$MIC_DEV  REC_SEC=${REC_SEC}s  PTT_MODE=$PTT_MODE"
say "$C_SYS" "DEMO" "PTT FIFO: $PTT_FIFO"
say "$C_SYS" "DEMO" "Processes up:"
say "$C_SYS" "DEMO" " ├─ AUDIO   pid=$AUDIO_PID"
say "$C_SYS" "DEMO" " ├─ LISTEN  pid=$LISTEN_PID"
if [ -n "$PTT_PID" ]; then
  say "$C_SYS" "DEMO" " └─ PTT     pid=$PTT_PID"
else
  say "$C_SYS" "DEMO" " └─ PTT     (not started)"
fi
echo

wait
