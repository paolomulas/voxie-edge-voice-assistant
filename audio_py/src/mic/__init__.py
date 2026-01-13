"""
Mic package public surface.

This file only re-exports the PTT entrypoint used by orchestrators.
No runtime logic should live here.
"""

from .listener import on_ptt

__all__ = ["on_ptt"]
