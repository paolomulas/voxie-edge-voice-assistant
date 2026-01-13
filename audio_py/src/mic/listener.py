from __future__ import annotations

import os
import subprocess
import shlex
from typing import Optional

from voice_state import VoiceState
from audio_client import AudioClient

# Project root for resolving PHP script paths (optional).
# If not set, paths are resolved relative to the current working directory (legacy behavior).
VOXIE_ROOT = os.environ.get("VOXIE_ROOT", "").strip()

# ALSA capture device for arecord (defaults preserved).
MIC_DEV = os.environ.get("VOXIE_MIC_DEV", "plughw:2,0").strip()

# Recording duration in seconds (defaults preserved).
REC_SEC = os.environ.get("VOXIE_REC_SEC", "4").strip()

# Output WAV path (defaults preserved).
WAV_PATH = os.environ.get("VOXIE_WAV_PATH", "/tmp/bitvox_mic/ptt.wav").strip()

# ASR language (defaults preserved).
ASR_LANG = os.environ.get("VOXIE_ASR_LANG", "it").strip()


def _resolve(rel_path: str) -> str:
    """
    Resolve a repo-relative path.
    If VOXIE_ROOT is set, returns an absolute path under VOXIE_ROOT.
    Otherwise returns the input unchanged (legacy relative behavior).
    """
    if VOXIE_ROOT:
        return os.path.join(VOXIE_ROOT, rel_path)
    return rel_path


# arecord command (kept as shell string; defaults preserved).
ARECORD = (
    f"arecord -D {shlex.quote(MIC_DEV)} -f S16_LE -r 16000 -c 1 -d {shlex.quote(str(REC_SEC))}"
)


def _run_capture(cmd: str) -> None:
    subprocess.run(cmd, shell=True, check=False)


def _run_stdout(cmd: str) -> str:
    p = subprocess.run(cmd, shell=True, capture_output=True, text=True)
    return (p.stdout or "").strip()


def transcribe(wav_path: str) -> str:
    """
    Transcribe a WAV file by calling the existing PHP ASR bridge script.
    Expected PHP function: asr_transcribe_wav($wavPath, $lang='it') -> ['ok'=>bool, 'text'=>string] ...
    """
    asr_php = _resolve("php/bin/asr.php")

    # Run a small PHP snippet that requires the bridge and echoes only the text on success.
    php_code = (
        "require " + repr(asr_php) + "; "
        "$r=asr_transcribe_wav(" + repr(wav_path) + "," + repr(ASR_LANG) + "); "
        "if(empty($r['ok'])){fwrite(STDERR, json_encode($r).\"\\n\"); exit(2);} "
        "echo $r['text'];"
    )
    cmd = "php -r " + shlex.quote(php_code)
    return _run_stdout(cmd)


def call_agent(text: str) -> None:
    """
    Call the PHP agent/router entrypoint.
    This is intentionally fire-and-forget (check=False) to preserve runtime behavior.
    """
    agent_php = _resolve("php/bin/agent.php")
    cmd = f"php {shlex.quote(agent_php)} {shlex.quote(text)}"
    subprocess.run(cmd, shell=True, check=False)


def on_ptt(state: VoiceState, audio: Optional[AudioClient] = None) -> VoiceState:
    """
    PTT handler:
    - If currently speaking: stop playback and return to LISTENING
    - Otherwise: record -> transcribe -> call agent -> return to IDLE/LISTENING
    """
    if audio is None:
        audio = AudioClient()

    if state == VoiceState.SPEAKING:
        audio.stop()
        return VoiceState.LISTENING

    os.makedirs(os.path.dirname(WAV_PATH) or "/tmp", exist_ok=True)

    # Record audio
    _run_capture(f"{ARECORD} {shlex.quote(WAV_PATH)}")

    # ASR
    text = transcribe(WAV_PATH)
    if not text:
        return VoiceState.LISTENING

    # Agent routing
    call_agent(text)
    return VoiceState.IDLE
