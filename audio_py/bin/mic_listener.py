#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
mic_listener.py
Thin CLI wrapper around src/mic/listener.py

Purpose:
- Start a microphone listener that can trigger the PHP agent
- Optionally send STOP to audio daemon (barge-in)
- Optionally integrate with PTT FIFO

Repo-hardening:
- No hardcoded /home/* paths
- Repo-relative import of src/
- Portable defaults via env vars
- Clear error messages if modules are missing
"""

from __future__ import annotations

import os
import sys
import argparse
from pathlib import Path


# ------------------------------------------------------------
# Repo-relative PYTHONPATH injection for src/
# ------------------------------------------------------------
SCRIPT_DIR = Path(__file__).resolve().parent
BASE_DIR = SCRIPT_DIR.parent  # repo/python -> repo/
SRC_DIR = BASE_DIR / "src"

if str(SRC_DIR) not in sys.path:
    sys.path.insert(0, str(SRC_DIR))


def die(msg: str, code: int = 2) -> None:
    print(f"[MIC_LISTENER][ERR] {msg}", flush=True)
    sys.exit(code)


# ------------------------------------------------------------
# Imports (fail gracefully for community)
# ------------------------------------------------------------
try:
    from mic.listener import MicListener, MicListenerConfig
except Exception as e:
    die(f"Cannot import mic.listener from {SRC_DIR}. Missing/invalid src layout? ({e})")

try:
    from audio_client import AudioClient
except Exception as e:
    die(f"Cannot import AudioClient. Expected src/audio_client.py (or module). ({e})")


def default_root() -> str:
    return os.environ.get("VOXIE_ROOT") or str(BASE_DIR)


def default_agent_cmd(root: str) -> str:
    # Keep it consistent with your other scripts
    return os.environ.get("VOXIE_AGENT_PHP") or f"{root}/php/bin/agent.php"


def main() -> None:
    root = default_root()

    ap = argparse.ArgumentParser(description="Voxie mic listener (wrapper)")
    ap.add_argument("--sock", default=os.environ.get("VOXIE_AUDIO_SOCK", "/tmp/bitvox_audio.sock"),
                    help="Unix socket path for audio daemon")
    ap.add_argument("--ptt-fifo", default=os.environ.get("VOXIE_PTT_FIFO", "/tmp/bitvox_ptt.fifo"),
                    help="PTT FIFO path (optional)")
    ap.add_argument("--php-agent-cmd", default=default_agent_cmd(root),
                    help="Path to PHP agent entrypoint (agent.php)")
    ap.add_argument("--barge-in", action="store_true",
                    help="Send STOP to audio daemon when recording starts")
    ap.add_argument("--debug", action="store_true",
                    help="Enable verbose logging (if supported by MicListener)")
    args = ap.parse_args()

    # Basic sanity checks (do not be too strict, but helpful)
    agent_path = Path(args.php_agent_cmd)
    if not agent_path.exists():
        print(f"[MIC_LISTENER][WARN] agent not found: {agent_path}", flush=True)
        print("[MIC_LISTENER][WARN] Set VOXIE_AGENT_PHP or use --php-agent-cmd", flush=True)

    # Audio client (best effort; AudioClient should handle connect errors)
    audio = AudioClient(sock_path=args.sock)

    # Build config expected by your MicListener implementation
    # Keep field names aligned to your snippet.
    try:
        cfg = MicListenerConfig(
            php_agent_cmd=args.php_agent_cmd,
            barge_in=bool(args.barge_in),
            ptt_fifo=args.ptt_fifo,
        )
    except TypeError as e:
        die(f"MicListenerConfig signature mismatch. Update wrapper to match src/mic/listener.py ({e})")

    # Optional debug flag: only set if the config supports it
    if args.debug:
        for field in ("debug", "verbose", "log_debug"):
            if hasattr(cfg, field):
                setattr(cfg, field, True)
                break

    print("[MIC_LISTENER] starting…", flush=True)
    print(f"[MIC_LISTENER] root={root}", flush=True)
    print(f"[MIC_LISTENER] audio_sock={args.sock}", flush=True)
    print(f"[MIC_LISTENER] ptt_fifo={args.ptt_fifo}", flush=True)
    print(f"[MIC_LISTENER] agent={args.php_agent_cmd}", flush=True)
    print(f"[MIC_LISTENER] barge_in={bool(args.barge_in)}", flush=True)

    ml = MicListener(cfg, audio=audio)
    ml.listen()


if __name__ == "__main__":
    main()
