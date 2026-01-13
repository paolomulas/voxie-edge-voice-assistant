#!/usr/bin/env python3
from __future__ import annotations

import json
from typing import Any, Dict

__all__ = ["parse_line", "reply"]


def parse_line(line: str) -> Dict[str, Any]:
    """
    Parse a single newline-delimited JSON message.

    Behavior is intentionally conservative:
    - empty/blank input -> {}
    - invalid JSON -> {}
    """
    line = (line or "").strip()
    if not line:
        return {}
    try:
        obj = json.loads(line)
        # Keep the original contract: always return a dict-like object.
        return obj if isinstance(obj, dict) else {}
    except Exception:
        return {}


def reply(obj: Dict[str, Any]) -> bytes:
    """
    Encode a JSON reply as UTF-8 bytes, newline-terminated.
    """
    return (json.dumps(obj, ensure_ascii=False) + "\n").encode("utf-8")
