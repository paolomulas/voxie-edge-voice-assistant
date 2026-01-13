from __future__ import annotations

import os
import socket
import json
from typing import Any, Dict

# Default UNIX socket path used by the audio daemon.
# Can be overridden via environment variable for portability.
DEFAULT_SOCK = os.environ.get("VOXIE_AUDIO_SOCK", "/tmp/bitvox_audio.sock")

__all__ = ["AudioClient", "DEFAULT_SOCK"]


class AudioClient:
    """
    Minimal client for communicating with the audio daemon
    via UNIX domain socket.

    Protocol assumptions (must not change):
    - send: JSON payload followed by newline
    - receive: single JSON reply
    """

    def __init__(self, sock_path: str = DEFAULT_SOCK):
        self.sock_path = sock_path

    def _send(self, payload: Dict[str, Any]) -> Dict[str, Any]:
        msg = json.dumps(payload, ensure_ascii=False)
        s = socket.socket(socket.AF_UNIX, socket.SOCK_STREAM)
        try:
            s.connect(self.sock_path)
            # Protocol framing: JSON + newline
            s.sendall((msg + "\n").encode("utf-8"))

            data = s.recv(65536).decode("utf-8", errors="replace").strip()
            if not data:
                return {"ok": False, "err": "EMPTY_REPLY"}

            try:
                return json.loads(data)
            except Exception:
                return {"ok": False, "err": "BAD_JSON_REPLY", "raw": data}
        finally:
            try:
                s.close()
            except Exception:
                pass

    def ping(self) -> bool:
        r = self._send({"cmd": "PING"})
        return bool(r.get("ok") and r.get("pong") is True)

    def stop(self) -> Dict[str, Any]:
        return self._send({"cmd": "STOP"})

    def status(self) -> Dict[str, Any]:
        return self._send({"cmd": "STATUS"})

    def is_playing(self) -> bool:
        """
        Returns True if audio playback is currently active.

        Expected daemon reply:
        {"ok": true, "playing": true|false}
        """
        r = self.status()
        if not r.get("ok"):
            return False

        if "playing" in r:
            return bool(r.get("playing"))
        if "is_playing" in r:
            return bool(r.get("is_playing"))
        return False
