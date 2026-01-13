#!/usr/bin/env python3
"""
voxie_listen.py
PTT -> record wav -> ASR (php) -> AGENT (php)

Goals:
- portable defaults (no hardcoded /home/paolo/...)
- robust FIFO creation
- sane logging + minimal safety checks
- best-effort audio STOP via unix socket (barge-in)
"""

import os
import re
import shlex
import json
import time
import socket
import shutil
import subprocess
from pathlib import Path
from difflib import SequenceMatcher


# -----------------------------
# Defaults (portable)
# -----------------------------
SCRIPT_DIR = Path(__file__).resolve().parent
DEFAULT_ROOT = os.environ.get("VOXIE_ROOT") or str(SCRIPT_DIR.parent)  # repo/python -> repo/

ROOT = os.environ.get("VOXIE_ROOT", DEFAULT_ROOT)
FIFO = os.environ.get("VOXIE_PTT_FIFO", "/tmp/bitvox_ptt.fifo")
DEV  = os.environ.get("VOXIE_MIC_DEV", "default")
DUR  = int(os.environ.get("VOXIE_REC_SEC", "4"))
WAV  = os.environ.get("VOXIE_WAV", "/tmp/bitvox_mic/ptt.wav")
LANG = os.environ.get("VOXIE_LANG", "it")

ASR_PHP   = os.environ.get("VOXIE_ASR_PHP",   f"{ROOT}/php/bin/asr.php")
AGENT_PHP = os.environ.get("VOXIE_AGENT_PHP", f"{ROOT}/php/bin/agent.php")

AUDIO_SOCK = os.environ.get("VOXIE_AUDIO_SOCK", "/tmp/bitvox_audio.sock")

# Debounce + barge-in calm time
PTT_DEBOUNCE_SEC = float(os.environ.get("VOXIE_PTT_DEBOUNCE_SEC", "0.35"))
AUDIO_CALM_SEC   = float(os.environ.get("VOXIE_AUDIO_CALM_SEC", "0.12"))

# Anti-echo heuristic
ECHO_MAX_WORDS    = int(os.environ.get("VOXIE_ECHO_MAX_WORDS", "7"))
ECHO_SIM_THRESH   = float(os.environ.get("VOXIE_ECHO_SIM_THRESH", "0.92"))

# Anti-garbage patterns (ASR boilerplate / prompt leak)
BAD_PHRASES = (
    "trascrivi fedelmente",
    "domande tipiche",
    "riassumi",
    "come assistente",
    "come chatbot",
    "scrivi un testo",
)


def log(s: str) -> None:
    print(s, flush=True)


def _which(cmd: str) -> bool:
    return shutil.which(cmd) is not None


def ensure_fifo() -> None:
    """Create FIFO if missing; make it world-writable for demo convenience."""
    p = Path(FIFO)
    if p.exists():
        return
    try:
        # Prefer Python-native
        os.mkfifo(FIFO, 0o666)
    except Exception:
        # Fallback to mkfifo binary
        subprocess.run(["mkfifo", FIFO], check=False)
        subprocess.run(["chmod", "666", FIFO], check=False)


def normalize(s: str) -> str:
    s = (s or "").lower().strip()
    s = re.sub(r"[^a-zàèéìòù0-9\s]", " ", s)
    s = re.sub(r"\s+", " ", s).strip()
    return s


def is_garbage(text: str) -> bool:
    low = (text or "").lower()
    return any(b in low for b in BAD_PHRASES)


def asr_repair(text: str) -> str:
    """
    Small cleanup for common ASR artifacts (keep conservative).
    """
    t = (text or "").strip()

    # Drop surrounding quotes
    if len(t) >= 2 and t[0] == t[-1] and t[0] in ("'", '"'):
        t = t[1:-1].strip()

    # Remove repeated whitespace/newlines
    t = re.sub(r"\s+", " ", t).strip()
    return t


def similarity(a: str, b: str) -> float:
    if not a or not b:
        return 0.0
    return SequenceMatcher(None, a, b).ratio()


# -----------------------------
# Audio daemon IPC (best effort)
# -----------------------------
def audio_send(payload: dict) -> dict:
    """
    Send JSON to unix socket and parse reply (if any).
    Best-effort: returns {} on failure.
    """
    if not AUDIO_SOCK:
        return {}

    s = socket.socket(socket.AF_UNIX, socket.SOCK_STREAM)
    try:
        s.settimeout(0.35)
        s.connect(AUDIO_SOCK)
        s.sendall((json.dumps(payload) + "\n").encode("utf-8"))

        # try read reply (optional)
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


# -----------------------------
# Recording + ASR + Agent
# -----------------------------
def record_wav() -> bool:
    wav_path = Path(WAV)
    wav_path.parent.mkdir(parents=True, exist_ok=True)

    if not _which("arecord"):
        log("[ERR] arecord not found. Install alsa-utils.")
        return False

    # 16kHz mono PCM wav, fixed duration
    cmd = [
        "arecord",
        "-D", DEV,
        "-f", "S16_LE",
        "-r", "16000",
        "-c", "1",
        "-d", str(DUR),
        str(wav_path),
    ]

    log(f"[REC] {DUR}s @ {DEV}")
    r = subprocess.run(cmd, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    return r.returncode == 0 and wav_path.exists() and wav_path.stat().st_size > 1000


def asr_transcribe() -> str:
    if not Path(ASR_PHP).exists():
        log(f"[ERR] ASR script not found: {ASR_PHP}")
        return ""

    if not _which("php"):
        log("[ERR] php not found.")
        return ""

    cmd = ["php", ASR_PHP, WAV, LANG]
    log("[ASR] transcribing…")
    p = subprocess.run(cmd, capture_output=True, text=True)

    out = (p.stdout or "").strip()
    err = (p.stderr or "").strip()
    if err:
        log(f"[ASR][stderr] {err}")
    return out


def call_agent(text: str) -> None:
    if not Path(AGENT_PHP).exists():
        log(f"[ERR] AGENT script not found: {AGENT_PHP}")
        return

    if not _which("php"):
        log("[ERR] php not found.")
        return

    log("[AGENT] routing…")
    # pass as a single argv token to avoid shell quoting issues
    subprocess.run(["php", AGENT_PHP, text])


def main() -> None:
    ensure_fifo()

    log("[VOXIE] PTT → REC → ASR → AGENT  (demo listener)")
    log(f"[SYS] root={ROOT}")
    log(f"[SYS] mic={DEV} dur={DUR}s  fifo={FIFO}")
    log("[READY] press PLAY/PAUSE (or: echo PTT > fifo)")

    last_ptt_ts = 0.0
    last_spoken_norm = ""  # proxy of last line sent to agent (better than nothing)
    last_user_norm = ""    # last accepted user input

    # Blocking read on FIFO: each line triggers one interaction
    with open(FIFO, "r", encoding="utf-8", errors="ignore") as f:
        while True:
            line = f.readline()
            if not line:
                time.sleep(0.05)
                continue

            now = time.time()
            if now - last_ptt_ts < PTT_DEBOUNCE_SEC:
                continue
            last_ptt_ts = now

            log("[PTT] received")

            # Barge-in: stop audio before recording
            audio_stop()
            time.sleep(AUDIO_CALM_SEC)

            log("[PTT] speak now…")
            if not record_wav():
                log("[REC] failed/empty wav")
                continue

            text = asr_transcribe()
            if not text:
                log("[ASR] empty")
                continue

            log(f'[ASR][RAW] "{text}"')

            # 1) ignore boilerplate / garbage
            if is_garbage(text):
                log("[ASR] ignored boilerplate")
                continue

            fixed = asr_repair(text)
            if fixed != text:
                log(f'[ASR][FIX] "{fixed}"')

            clean = normalize(fixed)
            if not clean:
                log("[ASR] empty(after clean)")
                continue

            # 2) anti-echo: discard short repeated phrases
            words = clean.split()
            if len(words) <= ECHO_MAX_WORDS:
                sim_prev_user = similarity(clean, last_user_norm)
                sim_spoken = similarity(clean, last_spoken_norm)
                if sim_prev_user >= ECHO_SIM_THRESH or sim_spoken >= ECHO_SIM_THRESH:
                    log("[ASR] ignored (echo/repeat)")
                    continue

            log(f'[ASR][OK] "{fixed}"')

            # update anti-echo memory
            last_user_norm = clean
            last_spoken_norm = clean

            call_agent(fixed)
            log("[DONE] waiting next PTT…")


if __name__ == "__main__":
    try:
        main()
    except KeyboardInterrupt:
        print("\n[EXIT] bye", flush=True)
