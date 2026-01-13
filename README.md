# 🎙️ bitVox / Voxie

**Educational, local-first voice assistant on constrained hardware**  
Raspberry Pi Model B (2011, 512MB RAM) • PHP + Python • Push-To-Talk

bitVox (aka *Voxie*) is an **educational experiment**, not production software.

The project demonstrates how a fully working voice assistant can run on **very old hardware**, using a simple, resilient architecture and a strict constraint-driven design.

This repository is shared to document architectural patterns, design trade-offs, and real-world solutions to common voice assistant problems.

---

## 🎯 Project goals

- Show that voice assistants **do not require modern hardware**
- Validate **datapizza-ai-php** in a real use case
- Explore **local-first design** with optional cloud enhancement
- Document real-world edge cases (PTT, ASR, echo, caching, debouncing)

---

## 🧠 Architecture (high level)

```
[ Physical Button / PTT ]
            ↓
         evdev
            ↓
          FIFO
            ↓
     Python Listener
   (record + ASR)
            ↓
        PHP Agent
   (intent + skill)
            ↓
      Audio Daemon
   (TTS / stream)
```

- **Python** handles hardware, audio I/O, and PTT
- **PHP** handles AI logic, intent routing, and skills
- Processes communicate via FIFO and Unix sockets
- No heavy frameworks, no resident services

---


## Semantic intent & vector store

bitVox uses a lightweight semantic intent system based on precomputed embeddings.

Intent definitions live in `data/vec/intents_source.json` and are transformed into
a vector store (`intents_vectors.json`) using an offline build step.

At runtime:
- user input is embedded
- cosine similarity is computed locally
- the closest intent is selected if above threshold
- LLM calls are used only as fallback

This approach drastically reduces latency, cost, and dependency on cloud models,
while preserving agent-like behavior and flexibility.



### Why the vector store matters (agentic core)

The semantic vector store is one of the few components that gives bitVox
true **agent-like behavior**, even on extremely constrained hardware.

Instead of relying on the LLM for every decision, bitVox:
- precomputes intent embeddings offline
- performs fast local similarity matching at runtime
- uses the LLM only when semantic confidence is low

This design:
- dramatically reduces latency
- minimizes token usage and cost
- keeps the system responsive even on ARMv6 CPUs
- allows deterministic behavior under load or network issues

The vector store is intentionally **simple, inspectable, and hackable**.
It is not a black box, but a teaching tool that shows how modern agent
architectures can be decomposed into understandable parts.

In this project, the vector store is not an optimization.
It is a **foundational architectural choice**.



## 🐍 Python layer (runtime & hardware control)

The Python layer is intentionally small and focused.

Its responsibilities are limited to:
- hardware interaction (PTT, audio devices)
- audio recording and playback
- process coordination (FIFO and Unix sockets)

It does **not** handle:
- intent classification
- business logic
- content generation
- skill orchestration

All higher-level logic lives in the PHP layer.

This strict separation keeps the runtime predictable, avoids long-lived state on constrained hardware, and makes the system easier to reason about and debug.

---

## 🧩 Skill categories

bitVox intentionally separates skills into **two distinct families**.

### 1️⃣ Feed skills (spoken content)

These skills generate **spoken content** and follow a unified JSON contract:

- news
- weather
- timeout / weekend suggestions

Schema: `voxie.feed.v1`  
Flow: **JSON → TTS → MP3 (cached)**

All feed-based skills produce the **same output format**, designed to be consumed by ElevenLabs or OpenAI TTS.

---

### 2️⃣ Action skills (immediate actions)

These skills do **not** generate TTS content:

- web radio (audio streaming)
- Vox Romana (local audio assets / quotes)
- mentor (online conversational mode)

They are intentionally **custom**, procedural, and outside the feed standard.

---

## 📂 Project structure (essential)

```
bitvox/
├── python/          # PTT, recording, audio daemon
├── php/
│   ├── bin/         # agent, ASR
│   ├── core/        # router, TTS, LLM wrappers
│   ├── feed/        # feed generator (voxie.feed.v1)
│   └── skills/      # PHP skills
├── scripts/         # generators / adapters
├── data/
│   ├── cache/       # feed JSON + MP3 cache
│   └── logs/
├── docs/            # schemas and notes
└── .env.example
```

---

## 🚀 Quick start (minimal)

```bash
git clone <repo>
cd bitvox

cp .env.example .env
# set OPENAI_API_KEY if required

mkfifo /tmp/bitvox_ptt.fifo
chmod 666 /tmp/bitvox_ptt.fifo

# Terminal 1
python3 python/audio_daemon.py

# Terminal 2
python3 python/evdev_ptt.py

# Terminal 3
python3 python/voxie_listen.py
```

Press the PTT button → speak → hear the response.

---

## 🛠️ Demo console (development tool)

This repository includes a **demo console script** used during development to run the core components in separate terminals.

The script is intentionally **explicit and verbose**:
- no auto-detection or hidden logic
- clear separation of processes
- designed for learning and debugging

It is **not** the official or required entrypoint, but a practical tool to understand how the pipeline is wired together.

---

## 🔌 Hardware design notes (why this setup works on 2011 hardware)

bitVox is designed around a simple but deliberate hardware principle:
**separate input and output audio paths at the hardware level**.

- **Input**: 2.4 GHz wireless microphone with USB receiver
  - the receiver includes its own ADC
  - audio capture happens outside the CPU

- **Output**: Bluetooth 5.3 USB dongle + BT speaker
  - audio encoding handled by the BT chipset
  - no PCM encoding on the ARM CPU

This setup allows the two available USB ports on the Raspberry Pi Model B to be used as **independent I/O channels**, avoiding contention and reducing CPU load.

By offloading audio capture and playback to dedicated chips, the ARMv6 CPU is left free to handle:
- ASR requests
- intent routing
- skill execution

This design is one of the key reasons the system runs reliably on a single-core 700 MHz CPU with 512 MB RAM.

---

## ⚠️ Important notes

- This repository **does not include personal demo audio files**
- Some skills require **cloud APIs** (optional and configurable)
- Weather via LLM is descriptive, not authoritative data
- The project prioritizes clarity and robustness over features

---

## 📜 License

Educational / experimental project.

A final license (MIT or Apache 2.0) will be chosen before the first public release.

---

> **bitVox**  
> Constraint-driven design.  
> Because old hardware still has something to teach.


## Feed-based skills (news, weather, timeout)

Some skills do not fetch live data.
They consume pre-generated JSON feeds and play cached audio files.

This is intentional and keeps the project:
- local-first
- deterministic
- educational

Feeds are generated externally (scripts/, adapters, or custom pipelines).
Example feed files are provided as *.example.json.

## Custom and demo-oriented skills

Some skills are intentionally custom and demo-oriented:
- vox (static content / philosophy quotes)
- radio (web streams)
- mentor (LLM-backed conversation)

They are included as examples of advanced patterns
and are not meant to be universal or production-ready.

### Example data files

The repository includes example files to help you get started:

- data/cache/news/feed_data.example.json
- data/cache/weather/weather.example.json
- data/cache/timeout/timeout.example.json
- data/stations/stations.example.json

## Model-agnostic, API-first architecture

bitVox is intentionally **API-first and model-agnostic**.
The system does not depend on a single AI vendor or model.

All intelligence is accessed through clean, replaceable API boundaries:

- ASR: OpenAI Whisper (default)
- LLM reasoning: OpenAI (gpt-4o-mini by default)
- TTS (runtime): OpenAI TTS (fast, low-latency)
- TTS (build-time / expressive): ElevenLabs

This design allows easy experimentation with alternative providers such as:
- Mistral
- DeepSeek
- Local or self-hosted models

No architectural changes are required — only configuration changes.

## Voice quality and expressive audio

For expressive or "wow-effect" content, bitVox supports **ElevenLabs** at build time.
This enables:
- theatrical and emotional voices
- multilingual narration
- character-driven content (e.g. Vox Romana)

Runtime interactions prioritize speed and reliability using OpenAI TTS,
while premium voices are used selectively where they add real value.

This hybrid approach balances:
- latency
- cost
- audio quality

## Configuration-driven experimentation

Most behavior is controlled via environment variables.
This makes bitVox ideal for:
- testing new AI providers
- comparing voice models
- benchmarking latency and cost

The architecture encourages experimentation without code changes,
which is a key goal of this educational project.

