#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
wake_poll.py
Wake word listener (voxie-only) → writes "PTT" to FIFO

Design goals:
- Offline-friendly, always-on, low overhead
- Streaming arecord (no respawn per chunk)
- Simple amplitude-based VAD + cooldown
- No changes to main PTT pipeline: only triggers FIFO

Repo-hardening:
- No hardcoded /home/paolo paths
- Python 3.7+ compatible typing (no list[str])
- Better logs + backoff if arecord keeps failing
"""

from __future__ import annotations

import os
import re
import sys
import json
import time
import math
import socket
import struct
import shutil
import signal
import subprocess
from pathlib import Path
from typing import List, Optional

# -----------------------------
# Portable defaults
# -----------------------------
SCRIPT_DIR = Path(__file__).resolve().parent
DEFAULT_ROOT = os.environ.get("VOXIE_ROOT") or str(SCRIPT_DIR.parent)

ROOT = os.environ.get("VOXIE_ROOT", DEFAULT_ROOT)

FIFO = os.environ.get("VOXIE_PTT_FIFO", "/tmp/bitvox_ptt.fifo")
MIC_DEV = os.environ.get("VOXIE_MIC_DEV", "default")

# temp dir for debug artifacts (optional)
TMP_DIR = os.environ.get("VOXIE_TMP_DIR", "/tmp/bitvox_mic")
Path(TMP_DIR).mkdir(parents=True, exist_ok=True)

# Audio socket (optional). Used only to STOP playback before listening (best effort).
AUDIO_SOCK = os.environ.get("VOXIE_AUDIO_SOCK", "/tmp/bitvox_audio.sock")

# Wake word + ASR
WAKE_WORD = os.environ.get("VOXIE_WAKE_WORD", "voxie").lower().strip()
ASR_PHP = os.environ.get("VOXIE_WAKE_ASR_PHP", f"{ROOT}/php/bin/asr.php")
ASR_LANG = os.environ.get("VOXIE_WAKE_LANG", os.environ.get("VOXIE_LANG", "it"))

# Audio capture parameters
SR = int(os.environ.get("VOXIE_WAKE_SR", "16000"))
CH = int(os.environ.get("VOXIE_WAKE_CH", "1"))
FMT = os.environ.get("VOXIE_WAKE_FMT", "S16_LE")

# VAD parameters (tuned for “good enough”)
CHUNK_MS = int(os.environ.get("VOXIE_WAKE_CHUNK_MS", "20"))
VAD_MIN_SEC = float(os.environ.get("VOXIE_WAKE_MIN_SEC", "0.25"))
VAD_MAX_SEC = float(os.environ.get("VOXIE_WAKE_MAX_SEC", "2.0"))
VAD_THRESH = int(os.environ.get("VOXIE_WAKE_THRESH", "800"))  # amplitude threshold
SILENCE_TAIL_MS = int(os.environ.get("VOXIE_WAKE_SILENCE_TAIL_MS", "250"))

# Cooldown / rate limit
COOLDOWN_SEC = float(os.environ.get("VOXIE_WAKE_COOLDOWN", "2.5"))

# If arecord keeps dying, increase sleep to avoid tight loop
RESTART_BACKOFF_BASE = float(os.environ.get("VOXIE_WAKE_BACKOFF_BASE", "0.6"))
RESTART_BACKOFF_MAX = float(os.environ.get("VOXIE_WAKE_BACKOFF_MAX", "6.0"))

# Option: stop speaker playback when armed to reduce echo
STOP_AUDIO_ON_ARM = int(os.environ.get("VOXIE_WAKE_STOP_AUDIO_ON_ARM", "1")) == 1

# Debug logging
DEBUG = int(os.environ.get("DEBUG", "0"))


def log(msg: str) -> None:
    print(msg, flush=True)


def dlog(msg: str) -> None:
    if DEBUG:
        print(msg, flush=True)


def _which(cmd: str) -> bool:
    return shutil.which(cmd) is not None


def ensure_fifo() -> None:
    p = Path(FIFO)
    if p.exists():
        return
    try:
        os.mkfifo(FIFO, 0o666)
    except Exception:
        subprocess.run(["mkfifo", FIFO], check=False)
        subprocess.run(["chmod", "666", FIFO], check=False)


def fifo_trigger() -> bool:
    """
    Write trigger token to FIFO. Non-blocking best effort.
    """
    try:
        # Opening FIFO for writing can block if no reader.
        # Use os.open with O_NONBLOCK so we don't hang.
        fd = os.open(FIFO, os.O_WRONLY | os.O_NONBLOCK)
        try:
            os.write(fd, b"PTT\n")
        finally:
            os.close(fd)
        return True
    except OSError:
        # No listener yet (no reader) or FIFO missing
        return False


def audio_send(payload: dict) -> dict:
    """
    Best-effort unix socket JSON. Returns {} on failure.
    """
    if not AUDIO_SOCK:
        return {}

    s = socket.socket(socket.AF_UNIX, socket.SOCK_STREAM)
    try:
        s.settimeout(0.35)
        s.connect(AUDIO_SOCK)
        s.sendall((json.dumps(payload) + "\n").encode("utf-8"))
        try:
            data = s.recv(4096)
            if not data:
                return {}
            return json.loads(data.decode("utf-8", errors="ignore").strip() or "{}")
        except Exception:
            return {}
    except Exception:
        return {}
    finally:
        try:
            s.close()
        except Exception:
            pass


def audio_stop() -> None:
    audio_send({"cmd": "STOP"})


def normalize(s: str) -> str:
    s = (s or "").lower().strip()
    s = re.sub(r"[^a-zàèéìòù0-9\s]", " ", s)
    s = re.sub(r"\s+", " ", s).strip()
    return s


def rms_amp(pcm16: bytes) -> float:
    """
    RMS amplitude on 16-bit little-endian PCM.
    """
    if not pcm16:
        return 0.0
    n = len(pcm16) // 2
    if n <= 0:
        return 0.0
    # unpack as signed shorts
    samples = struct.unpack("<" + ("h" * n), pcm16)
    acc = 0.0
    for x in samples:
        acc += float(x) * float(x)
    return math.sqrt(acc / n)


def write_wav(path: str, pcm: bytes, sr: int, ch: int) -> None:
    """
    Minimal WAV writer (16-bit PCM).
    """
    import wave

    with wave.open(path, "wb") as wf:
        wf.setnchannels(ch)
        wf.setsampwidth(2)
        wf.setframerate(sr)
        wf.writeframes(pcm)


def run_asr_on_wav(wav_path: str) -> str:
    if not Path(ASR_PHP).exists():
        dlog(f"[WAKE][ERR] ASR script not found: {ASR_PHP}")
        return ""
    if not _which("php"):
        dlog("[WAKE][ERR] php not found")
        return ""

    cmd = ["php", ASR_PHP, wav_path, ASR_LANG]
    p = subprocess.run(cmd, capture_output=True, text=True)
    out = (p.stdout or "").strip()
    return out


def arecord_cmd() -> List[str]:
    # raw PCM stream to stdout
    return [
        "arecord",
        "-D", MIC_DEV,
        "-f", FMT,
        "-r", str(SR),
        "-c", str(CH),
        "-t", "raw",
        "-q",
    ]


def main() -> None:
    ensure_fifo()

    if not _which("arecord"):
        log("[WAKE][ERR] arecord not found. Install alsa-utils.")
        sys.exit(1)

    log("[WAKE] voxie-only wake listener (streaming arecord)")
    log(f"[WAKE] root={ROOT}")
    log(f"[WAKE] mic={MIC_DEV} sr={SR} ch={CH} chunk={CHUNK_MS}ms thresh={VAD_THRESH}")
    log(f"[WAKE] word='{WAKE_WORD}' fifo={FIFO}")
    if STOP_AUDIO_ON_ARM:
        log("[WAKE] will stop playback when speech starts (best effort)")

    bytes_per_frame = 2 * CH  # S16_LE
    chunk_frames = int(SR * (CHUNK_MS / 1000.0))
    chunk_bytes = chunk_frames * bytes_per_frame

    min_bytes = int(SR * VAD_MIN_SEC) * bytes_per_frame
    max_bytes = int(SR * VAD_MAX_SEC) * bytes_per_frame
    tail_bytes = int(SR * (SILENCE_TAIL_MS / 1000.0)) * bytes_per_frame

    last_fire = 0.0
    backoff = RESTART_BACKOFF_BASE

    while True:
        dlog(f"[WAKE] spawn: {' '.join(arecord_cmd())}")
        try:
            proc = subprocess.Popen(
                arecord_cmd(),
                stdout=subprocess.PIPE,
                stderr=subprocess.DEVNULL,
                bufsize=0,
                preexec_fn=os.setsid,  # kill group on restart
            )
        except Exception as e:
            log(f"[WAKE][ERR] cannot start arecord: {e}")
            time.sleep(min(backoff, RESTART_BACKOFF_MAX))
            backoff = min(backoff * 1.6, RESTART_BACKOFF_MAX)
            continue

        backoff = RESTART_BACKOFF_BASE  # reset if spawn worked

        buf = bytearray()
        in_speech = False
        silent_tail = bytearray()

        try:
            assert proc.stdout is not None
            while True:
                data = proc.stdout.read(chunk_bytes)
                if not data:
                    # arecord ended
                    raise RuntimeError("arecord stream ended")

                amp = rms_amp(data)

                if not in_speech:
                    if amp >= VAD_THRESH:
                        in_speech = True
                        buf.clear()
                        silent_tail.clear()
                        if STOP_AUDIO_ON_ARM:
                            audio_stop()
                        buf.extend(data)
                        dlog(f"[WAKE] speech start amp={amp:.0f}")
                    else:
                        # idle
                        continue
                else:
                    # in speech: collect
                    buf.extend(data)

                    if amp < VAD_THRESH:
                        silent_tail.extend(data)
                        # keep tail bounded
                        if len(silent_tail) > tail_bytes:
                            silent_tail = silent_tail[-tail_bytes:]
                    else:
                        silent_tail.clear()

                    # if too long, cut and evaluate anyway
                    if len(buf) >= max_bytes:
                        dlog("[WAKE] max speech reached")
                        # trim any tail to avoid long silences
                        if silent_tail:
                            buf = buf[:-len(silent_tail)]
                        break

                    # end speech if silence tail long enough AND min length reached
                    if len(buf) >= min_bytes and len(silent_tail) >= tail_bytes:
                        dlog("[WAKE] speech end by silence")
                        buf = buf[:-len(silent_tail)]
                        break

            # speech segment ready
            pcm = bytes(buf)
            if len(pcm) < min_bytes:
                in_speech = False
                continue

            # Cooldown
            now = time.time()
            if now - last_fire < COOLDOWN_SEC:
                dlog("[WAKE] cooldown skip")
                in_speech = False
                continue

            wav_path = str(Path(TMP_DIR) / "wake_last.wav")
            write_wav(wav_path, pcm, SR, CH)

            text = run_asr_on_wav(wav_path)
            norm = normalize(text)
            dlog(f'[WAKE][ASR] "{text}"')

            if WAKE_WORD and WAKE_WORD in norm.split():
                ok = fifo_trigger()
                last_fire = time.time()
                if ok:
                    log("[WAKE] detected → PTT")
                else:
                    log("[WAKE] detected but FIFO has no reader (listener not running)")
            else:
                dlog("[WAKE] no match")

            in_speech = False

        except KeyboardInterrupt:
            log("\n[WAKE] exit")
            try:
                os.killpg(os.getpgid(proc.pid), signal.SIGTERM)
            except Exception:
                pass
            break

        except Exception as e:
            dlog(f"[WAKE] restart: {e}")
            try:
                os.killpg(os.getpgid(proc.pid), signal.SIGTERM)
            except Exception:
                pass
            time.sleep(min(backoff, RESTART_BACKOFF_MAX))
            backoff = min(backoff * 1.4, RESTART_BACKOFF_MAX)
            continue


if __name__ == "__main__":
    main()
