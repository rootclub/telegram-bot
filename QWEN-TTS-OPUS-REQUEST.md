# Richiesta: supporto output OGG/Opus in qwen-tts-api

## Contesto

Il bot Telegram rootbot usa qwen-tts-api (via QBert) per generare audio TTS con il profilo voce "bender" tramite l'endpoint `/voice-clone`.

Attualmente `/voice-clone` restituisce sempre WAV. Per inviare voice message su Telegram serve il formato OGG/Opus. Il web server che ospita il bot non ha ffmpeg disponibile (e `exec()` e' disabilitata nel PHP), quindi la conversione non puo' avvenire lato bot.

## Cosa serve

Aggiungere un parametro opzionale `format` all'endpoint `POST /voice-clone` (e volendo anche a `/tts` e `/voice-design` per coerenza).

### Comportamento

| `format` | Content-Type | Output |
|----------|-------------|--------|
| assente o `wav` | `audio/wav` | WAV 16-bit PCM mono (comportamento attuale, invariato) |
| `opus` | `audio/ogg` | OGG/Opus, convertito con ffmpeg server-side |

### Specifiche conversione

Comando ffmpeg equivalente:

```bash
ffmpeg -i input.wav -acodec libopus -b:a 64k output.ogg
```

- Codec: libopus
- Bitrate: 64k (sufficiente per parlato, file compatti)
- Il file risultante deve essere compatibile con Telegram `sendVoice`

### Parametro nella richiesta

Dato che `/voice-clone` usa `multipart/form-data`, il parametro `format` va aggiunto come campo form:

```bash
# Output WAV (default, comportamento invariato)
curl -F "profile=bender" \
  -F "text=Ciao mondo" \
  -F "language=italian" \
  http://127.0.0.1:5100/voice-clone --output test.wav

# Output OGG/Opus
curl -F "profile=bender" \
  -F "text=Ciao mondo" \
  -F "language=italian" \
  -F "format=opus" \
  http://127.0.0.1:5100/voice-clone --output test.ogg
```

### Gestione errori

- Se `format=opus` ma ffmpeg non e' installato sul server qwen-tts-api: restituire errore 500 con JSON `{"error": "ffmpeg not available for opus conversion"}`
- Se ffmpeg fallisce la conversione: restituire errore 500 con JSON `{"error": "audio conversion failed"}`
- Valori non riconosciuti di `format`: errore 400 con JSON `{"error": "unsupported format, use 'wav' or 'opus'"}`

## Dipendenze

- ffmpeg deve essere installato sul server dove gira qwen-tts-api
- Pacchetto `libopus` (generalmente incluso con ffmpeg)

## Note

- La conversione puo' avvenire in memoria con subprocess/pipe oppure tramite file temporaneo, a discrezione dell'implementazione
- Il WAV generato dal modello non serve piu' dopo la conversione, non va mantenuto
- Retrocompatibilita': senza il parametro `format`, il comportamento e' identico a prima
