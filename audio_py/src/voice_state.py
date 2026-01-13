from __future__ import annotations

"""
Voice state definitions.

This Enum is shared across multiple modules.
State names are part of the runtime contract.
"""


from enum import Enum, auto

__all__ = ["VoiceState"]


class VoiceState(Enum):
    # Stato neutro: nessuna cattura audio in corso, nessuna riproduzione in corso.
    IDLE = auto()

    # Modalità ascolto/attesa input (es. pronto a PTT / buffer).
    LISTENING = auto()

    # Fase di elaborazione/agent routing.
    THINKING = auto()

    # Fase di output audio / TTS playback.
    SPEAKING = auto()
