# Fast Whisper API — Guida client

Servizio REST per speech-to-text basato su [faster-whisper](https://github.com/SYSTRAN/faster-whisper) (CTranslate2), con supporto multi-modello dinamico.

## Base URL

| Ambiente              | URL                                              |
|-----------------------|--------------------------------------------------|
| Pubblico (via QBert)  | `https://qbert.neocerebrum.work/fast-whisper/`   |
| Locale (sulla stessa macchina del servizio) | `http://127.0.0.1:8788/`            |

Tutti gli esempi `curl` qui sotto usano l'endpoint locale; per il client di produzione sostituisci la base con quella pubblica.

## Endpoint

### `GET /health`

Stato del servizio. All'avvio nessun modello e caricato: il primo modello viene caricato pigramente alla prima `/transcribe`.

```bash
curl http://127.0.0.1:8788/health
```

```json
{
  "status": "ok",
  "model": "large-v3-turbo",
  "model_status": "loaded",
  "available_models": ["tiny", "base", "small", "medium", "large-v3", "large-v3-turbo", "..."],
  "vram": {"allocated_mb": 6017.6, "reserved_mb": 9374.0},
  "model_loaded_since": 120.5
}
```

Prima del primo caricamento `model` e `null` e `model_status` e `"unloaded"`. `model_loaded_since` (secondi) e presente solo se un modello e caricato.

### `POST /transcribe`

Trascrivi un file audio. Supporta wav, mp3, flac, ogg, m4a e tutti i formati gestiti da ffmpeg.

**Parametri** (file in `multipart/form-data`, gli altri in query string):

| Parametro    | Tipo   | Default       | Descrizione                                |
|--------------|--------|---------------|--------------------------------------------|
| `file`       | file   | *obbligatorio*| File audio da trascrivere                  |
| `language`   | string | auto-detect   | Codice lingua (`it`, `en`, `de`, ...)      |
| `task`       | string | `transcribe`  | `transcribe` o `translate` (traduce in EN) |
| `model_name` | string | `large-v3-turbo` (al primo caricamento), poi l'ultimo caricato | Modello Whisper da usare |

Se `model_name` non e tra quelli disponibili la richiesta risponde **400** con `{"error": "...", "available": [...]}`. Se il modello richiesto e diverso da quello in memoria, il vecchio viene scaricato e il nuovo caricato automaticamente (puo aggiungere alcuni secondi alla prima chiamata).

Internamente la trascrizione usa il VAD filter di faster-whisper per rimuovere il silenzio.

**Esempi:**

```bash
# Trascrizione base (usa il modello corrente)
curl -F "file=@audio.wav" http://127.0.0.1:8788/transcribe

# Specifica lingua
curl -F "file=@audio.wav" "http://127.0.0.1:8788/transcribe?language=it"

# Usa un modello diverso (viene caricato al volo)
curl -F "file=@audio.wav" "http://127.0.0.1:8788/transcribe?model_name=base"

# Traduci in inglese
curl -F "file=@audio.wav" "http://127.0.0.1:8788/transcribe?task=translate"

# Via QBert
curl -F "file=@audio.wav" "https://qbert.neocerebrum.work/fast-whisper/transcribe?language=it"
```

**Risposta:**

```json
{
  "text": "Testo trascritto completo",
  "segments": [
    {"id": 0, "start": 0.0, "end": 2.32, "text": "Primo segmento"},
    {"id": 1, "start": 3.12, "end": 4.82, "text": "Secondo segmento"}
  ],
  "language": "it",
  "model": "large-v3-turbo"
}
```

I tempi `start`/`end` sono in secondi, arrotondati a due decimali. Il campo `language` riflette la lingua rilevata (o quella forzata via parametro). Il campo `model` riflette il modello effettivamente usato.

### `POST /unload`

Scarica il modello dalla VRAM. Tipicamente usato dall'orchestratore (QBert) per liberare la GPU; un client applicativo non dovrebbe averne bisogno. Il modello viene ricaricato automaticamente alla prossima `/transcribe`.

```bash
curl -X POST http://127.0.0.1:8788/unload
```

```json
{"status": "unloaded", "vram_freed": true, "vram": {"allocated_mb": 9.1, "reserved_mb": 16.0}}
```

Se nessun modello e caricato la risposta e `{"status": "already_unloaded", "vram_freed": false}`.

## Modelli disponibili

Il modello si cambia al volo passando `model_name` a `/transcribe`.

- `tiny`, `tiny.en`
- `base`, `base.en`
- `small`, `small.en`
- `medium`, `medium.en`
- `large-v1`, `large-v2`, `large-v3`
- `large-v3-turbo` *(default)*
- `distil-large-v2`, `distil-large-v3`

Le varianti `.en` sono solo inglese (piu veloci/accurate sull'inglese). I `distil-*` sono distillati di Whisper, piu leggeri a parita di qualita ragionevole. `large-v3-turbo` e una versione accelerata di `large-v3`.

Per la lista live aggiornata controlla `/health`.
