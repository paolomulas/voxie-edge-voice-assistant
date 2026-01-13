#!/usr/bin/env python3
from __future__ import annotations

"""
Audio playback helper (process-based).

This module is intentionally small and dependency-free:
- spawns external players (aplay / mpg123)
- tracks a single active playback process
- provides stop/status helpers used by the audio daemon

Designed to run on constrained hardware (e.g., ARMv6 Raspberry Pi).
"""

import os
import time
import subprocess
from pathlib import Path
from typing import Optional, List

__all__ = [
    "stop",
    "is_playing",
    "play_wav",
    "play_mp3",
    "play_stream",
]

_PROC: Optional[subprocess.Popen] = None


def _log(msg: str) -> None:
    # Enable with: LOG_AUDIO=1
    if os.environ.get("LOG_AUDIO", "0").lower() in ("1", "true", "yes", "on"):
        print("[audio] " + msg, flush=True)


def _alsa_device() -> str:
    # Keep backward-compatible env vars. The first non-empty value wins.
    d = (
        os.environ.get("AUDIO_DEVICE", "")
        or os.environ.get("ALSA_DEVICE", "")
        or os.environ.get("VOXIE_AUDIO_DEVICE", "")
        or "bluealsa"
    ).strip()
    return d or "bluealsa"


def stop() -> bool:
    """Stop current playback process (if any)."""
    global _PROC
    if _PROC is None:
        return True

    if _PROC.poll() is None:
        _log("stop(): terminate proc")
        try:
            _PROC.terminate()
            _PROC.wait(timeout=1.5)
        except Exception:
            try:
                _PROC.kill()
            except Exception:
                pass

    _PROC = None
    return True


def is_playing() -> bool:
    """Return True if a playback process is currently alive."""
    return _PROC is not None and _PROC.poll() is None


def _popen(cmd: List[str]) -> subprocess.Popen:
    # Optional: redirect stdout/stderr to a log file.
    # Backward-compatible env var: AUDIO_LOG
    log_path = (
        os.environ.get("AUDIO_LOG", "").strip()
        or os.environ.get("VOXIE_AUDIO_LOG", "").strip()
    )
    if log_path:
        # Note: keeping original behavior (open file handle per spawn).
        f = open(log_path, "ab", buffering=0)
        return subprocess.Popen(
            cmd,
            stdin=subprocess.DEVNULL,
            stdout=f,
            stderr=f,
            start_new_session=True,
            close_fds=True,
        )

    return subprocess.Popen(
        cmd,
        stdin=subprocess.DEVNULL,
        stdout=subprocess.DEVNULL,
        stderr=subprocess.DEVNULL,
        start_new_session=True,
        close_fds=True,
    )


def _spawn(cmd: List[str], check_alive_ms: int = 250) -> bool:
    """Spawn a process and verify it stays alive for a short grace period."""
    global _PROC
    stop()
    _log("exec: " + " ".join(cmd))
    _PROC = _popen(cmd)

    time.sleep(check_alive_ms / 1000.0)
    if _PROC.poll() is not None:
        code = _PROC.returncode
        _log("spawn failed (exit=%s)" % code)
        _PROC = None
        return False

    return True


def _retry_spawn(cmd: List[str], tries: int = 3) -> bool:
    for i in range(tries):
        if _spawn(cmd):
            return True
        time.sleep(0.15 + 0.15 * i)
    return False


def play_wav(path: str) -> bool:
    """Play a WAV file via aplay."""
    p = Path(path)
    if not p.exists() or p.stat().st_size == 0:
        _log("WAV not found: %s" % p)
        return False

    dev = _alsa_device()
    cmd = ["aplay", "-q", "-D", dev, str(p)]
    return _retry_spawn(cmd, tries=3)


def play_mp3(path: str) -> bool:
    """Play an MP3 file via mpg123."""
    p = Path(path)
    if not p.exists() or p.stat().st_size == 0:
        _log("MP3 not found: %s" % p)
        return False

    dev = _alsa_device()
    cmd = ["mpg123", "--no-control", "-q", "-o", "alsa", "-a", dev, str(p)]
    return _retry_spawn(cmd, tries=3)


def play_stream(url: str) -> bool:
    """Play an HTTP/HTTPS stream via mpg123."""
    u = (url or "").strip()
    if not (u.startswith("http://") or u.startswith("https://")):
        _log("STREAM bad url: %s" % u)
        return False

    dev = _alsa_device()
    cmd = ["mpg123", "--no-control", "-q", "-o", "alsa", "-a", dev, u]
    return _retry_spawn(cmd, tries=3)
