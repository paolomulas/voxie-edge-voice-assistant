# Voxie Feed Schema v1

Schema unico per news, timeout, meteo.
Serve come input standard per il generatore audio (ElevenLabs / OpenAI Nova).

## Struttura

{
  "schema": "voxie.feed.v1",
  "skill": "news|timeout|weather",
  "generated_at": "ISO-8601",
  "locale": {
    "country": "IT",
    "region": "Sardegna",
    "city": "Cagliari"
  },
  "ttl_sec": 1800,
  "source": {
    "backend": "local|sonar",
    "provider": "sonar|rss|manual",
    "query": ""
  },
  "items": [
    {
      "id": "string",
      "type": "news|event|weather|timeout|tip",
      "title": "string",
      "summary": "string",
      "created_at": "ISO-8601",
      "url": "string|null",
      "audio": {
        "script": "Testo pronto per TTS",
        "voice": "default",
        "model": "elevenlabs|openai-nova",
        "local_path": "data/cache/feed/xxx.mp3"
      }
    }
  ]
}

## Regola d’oro
Il generatore audio legge SOLO:
items[*].audio.script
