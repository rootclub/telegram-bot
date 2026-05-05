# TRELLIS.2 API — guida per client

FastAPI minimale che espone la pipeline `Trellis2ImageTo3DPipeline` dietro il gateway **QBert**.
QBert si occupa di coda, evict VRAM, priorità e ticketing — il client chiama un unico endpoint.

## Base URL

```
http://<host>:1999/trellis/...
```

Il backend TRELLIS gira su `127.0.0.1:9000` ma **non va mai chiamato direttamente**: passando sempre
da QBert ottieni scheduling, eviction automatica degli altri servizi GPU (Ollama, ComfyUI, TTS, …)
e gestione delle richieste lunghe.

## Endpoint

### `POST /trellis/generate`

Genera una mesh 3D texturata partendo da un'immagine e restituisce un file **GLB binario**
(glTF 2.0 con texture WebP embedded).

**Content-Type:** `multipart/form-data`

| Campo | Tipo | Default | Note |
|---|---|---|---|
| `image` | file | — | **required.** PNG / JPEG / WebP. Idealmente con sfondo già mascherato; altrimenti attiva `preprocess=true`. |
| `resolution` | string | `"1024"` | `"512"`, `"1024"` (cascade), `"1536"` (cascade — più VRAM). |
| `seed` | int | `0` | Riproducibilità. |
| `preprocess` | bool | `true` | Rimuove lo sfondo con RMBG-2.0 se l'immagine non ha alpha mascherato. |
| `decimation_target` | int | `500000` | Numero target di triangoli nella mesh finale (`100_000`–`1_000_000`). |
| `texture_size` | int | `2048` | Risoluzione texture PBR finale (`1024`, `2048`, `4096`). |
| `ss_steps` | int | `12` | **Sparse Structure** — passi sampler. |
| `ss_guidance` | float | `7.5` | guidance strength. |
| `ss_rescale` | float | `0.7` | guidance rescale. |
| `ss_rescale_t` | float | `5.0` | rescale t. |
| `shape_steps` | int | `12` | **Shape SLat** — passi sampler. |
| `shape_guidance` | float | `7.5` | guidance strength. |
| `shape_rescale` | float | `0.5` | guidance rescale. |
| `shape_rescale_t` | float | `3.0` | rescale t. |
| `tex_steps` | int | `12` | **Texture SLat** — passi sampler. |
| `tex_guidance` | float | `1.0` | guidance strength. |
| `tex_rescale` | float | `0.0` | guidance rescale. |
| `tex_rescale_t` | float | `3.0` | rescale t. |

**Risposta (successo):**

```
HTTP/1.1 200 OK
Content-Type: model/gltf-binary
Content-Disposition: attachment; filename="trellis.glb"
Content-Length: <bytes>

<raw GLB bytes — magic "glTF" 0x67 0x6C 0x54 0x46>
```

**Errori:**

| Codice | Causa |
|---|---|
| `400` | immagine non valida, `resolution` fuori da `{"512","1024","1536"}`. |
| `429` | generazione già in corso (un job alla volta per istanza TRELLIS). |
| `503` | pipeline non ancora caricata in memoria (primo boot ~45 s). |

### `GET /trellis/health`

Controllo di vita. Ritorna `200 {"status":"ok","on_gpu":<bool>}` quando la pipeline è pronta.
Utile per readiness probe.

### `POST /trellis/free`

Sposta la pipeline in CPU e libera la VRAM. Non serve chiamarlo manualmente: QBert lo
invoca automaticamente prima di instradare un job a un altro servizio GPU
(`vram_mode: exclusive`). Esporlo qui è solo per diagnostica.

## Header QBert opzionali

Tutte le chiamate possono includere header per influenzare la coda:

| Header | Valori | Effetto |
|---|---|---|
| `X-Priority` | `urgent` \| `normal` \| `lazy` | Coda GPU: `urgent` passa davanti; `lazy` può essere interrotto da altri job. |
| `X-App-Name` | string | Tag del chiamante, appare nei log QBert. |
| `X-Note` | string | Etichetta libera per il job. |
| `X-Callback-Url` | URL | Se presente, QBert ritorna subito `202 {ticket_id}` e fa POST al callback a job completato (`body_base64` contiene il GLB). |

## Timing tipici (A5000, 1024 cascade)

- Prima richiesta dopo boot TRELLIS: ~60 s (carica pesi in VRAM alla prima inferenza).
- Richieste successive: ~60 s.
- Costo `/free`: <1 s. Il ciclo `cpu() → cuda()` non aggiunge overhead alla richiesta successiva.
- Peak VRAM a `resolution=1024`: ~5.8 GB.
- `resolution=1536` richiede margine extra (consigliato ≥ 12 GB liberi).

Il tempo tipico resta **sotto il limite sync di QBert (80 s)** → il client riceve il GLB direttamente
nella risposta HTTP, senza ticket. Se combini `texture_size=4096` + `decimation_target=1_000_000`
il tempo può superare 80 s e QBert passerà automaticamente alla modalità ticket (`202` + polling).

## Esempi

### curl

```bash
curl -X POST http://127.0.0.1:1999/trellis/generate \
  -H "X-App-Name: my-app" \
  -F "image=@gatto.png" \
  -F "resolution=1024" \
  -F "seed=42" \
  -F "decimation_target=500000" \
  -F "texture_size=2048" \
  -o gatto.glb
```

### Python — `requests`

```python
import requests

with open("gatto.png", "rb") as f:
    r = requests.post(
        "http://127.0.0.1:1999/trellis/generate",
        headers={"X-App-Name": "my-app"},
        files={"image": ("gatto.png", f, "image/png")},
        data={
            "resolution": "1024",
            "seed": 42,
            "decimation_target": 500_000,
            "texture_size": 2048,
        },
        timeout=600,
    )
r.raise_for_status()
with open("gatto.glb", "wb") as out:
    out.write(r.content)
```

### Python — pattern asincrono con callback

Per job > 80 s (texture 4K, 1536 cascade) oppure per non bloccare il client:

```python
import requests

r = requests.post(
    "http://127.0.0.1:1999/trellis/generate",
    headers={
        "X-App-Name": "my-app",
        "X-Callback-Url": "https://my-service.example/webhook/trellis",
    },
    files={"image": open("input.png", "rb")},
    data={"resolution": "1536"},
    timeout=10,
)
# risposta immediata: 202 con ticket_id
ticket = r.json()["ticket_id"]

# Quando pronto, QBert POSTerà al callback:
# {
#   "ticket_id": "...",
#   "status": "done",
#   "success": true,
#   "response": {
#     "status_code": 200,
#     "headers": {"content-type": "model/gltf-binary"},
#     "body_base64": "<base64 del GLB>"
#   }
# }
```

In alternativa al callback, il client può fare polling:

```python
import time
while True:
    s = requests.get(f"http://127.0.0.1:1999/ticket/{ticket}").json()
    if s["status"] == "done":
        break
    time.sleep(2)
```

## Note operative

- **Un job alla volta** per istanza TRELLIS. Chiamate parallele ricevono `429 busy`: QBert serializza già, ma il lock a livello backend è una seconda barriera.
- **Dopo un restart di `trellis.service`** la prima richiesta paga ~45 s di caricamento pesi.
  Fino ad allora `/trellis/health` risponde `503`.
- **VRAM condivisa:** quando arriva una richiesta TRELLIS, QBert scarica Ollama, ComfyUI e TTS
  prima di instradarla. Tornando a quei servizi si pagherà il loro tempo di reload.
- **Log:** `journalctl -u trellis.service -f` (backend) e `journalctl -u qbert.service -f` (gateway).
