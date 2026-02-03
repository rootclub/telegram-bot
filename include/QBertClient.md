# QBert PHP Client

Client PHP per il gateway QBert.

## Installazione

Copia `QBertClient.php` nella tua applicazione:

```bash
cp QBertClient.php /var/www/myapp/lib/
```

---

## Quale modalità usare?

QBert supporta diverse modalità. **Scegli in base al contesto:**

| Contesto | Modalità consigliata |
|----------|---------------------|
| **Bot Telegram/Discord/Slack** | Webhook |
| **Web app PHP** (utente aspetta) | Webhook |
| **Web app con frontend JS** (vuoi mostrare progresso) | Polling da JavaScript |
| **Script CLI / Cron job** | Polling bloccante |
| **Architettura event-driven** | Webhook |

### Usa WEBHOOK se:
- Stai sviluppando un **bot Telegram/Discord/Slack**
- Hai una **web app PHP** che non può bloccare
- Il job può durare **più di qualche secondo**
- Vuoi un'architettura **fire-and-forget**

### Usa POLLING DA JAVASCRIPT se:
- Vuoi mostrare **progresso in tempo reale** (posizione in coda, stato)
- L'utente deve poter **annullare** la richiesta
- Hai un **frontend SPA/React/Vue** che gestisce lo stato

### Usa POLLING BLOCCANTE solo se:
- È uno **script CLI** o **cron job**
- **Non c'è un utente** che aspetta
- Puoi permetterti di **bloccare il processo PHP**

⚠️ **MAI usare polling bloccante in una web app** - blocca il processo PHP e causa timeout Apache/nginx.

---

## Identificazione Client (appName)

Ogni istanza QBertClient deve dichiarare il nome dell'applicazione chiamante, per il tracciamento nei log:

```php
// Consigliato: identifica il client
$qbert = new QBertClient('https://qbert.example.com', appName: 'telegram_bot');

// Se non specifichi appName, viene usato "anonymous-{PID}"
// e PHP emette un E_USER_WARNING:
//   QBertClient: appName non specificato, registrato come 'anonymous-12345'.
//   Usa new QBertClient(appName: 'my_app') per identificare il client nei log.
$qbert = new QBertClient('https://qbert.example.com');
```

## Note per il Log

Ogni richiesta può includere una nota libera per contestualizzare la chiamata nei log:

```php
// Nota sulla singola richiesta
$response = $qbert->post('ollama', '/api/generate',
    ['model' => 'llama3', 'prompt' => '...'],
    note: 'user_chat_handler'
);

// Funziona su tutti i metodi: get, post, submit, request
$response = $qbert->get('ollama', '/api/tags', note: 'model_list_check');
```

---

## Modalità 1: Webhook Callback

PHP invia la richiesta e torna **subito**. QBert chiama il tuo endpoint quando il job è completato.

```php
<?php
require_once 'lib/QBertClient.php';

$qbert = new QBertClient('https://qbert.example.com', appName: 'my_webapp');

// Submit con callback URL - PHP ritorna IMMEDIATAMENTE
$result = $qbert->submit(
    method: 'POST',
    service: 'ollama',
    path: '/api/generate',
    json: ['model' => 'llama3', 'prompt' => 'Ciao!'],
    callbackUrl: 'https://myapp.com/qbert-callback'
);

if ($result['is_ticket']) {
    // Job accodato, QBert chiamerà il callback quando pronto
    // Rispondi all'utente "Elaborazione in corso..."
} else {
    // Job veloce (<30s), risposta già disponibile
    $response = $result['json'];
}
```

**Endpoint callback** (riceve il risultato):

```php
<?php
// /qbert-callback.php

$data = json_decode(file_get_contents('php://input'), true);

if ($data['success']) {
    $result = $data['response']['body_json'];
    // Salva in DB, manda notifica, ecc.
} else {
    $error = $data['error'];
    // Gestisci errore
}

http_response_code(200);
echo json_encode(['ok' => true]);
```

---

## Modalità 2: Polling da JavaScript

PHP fa da proxy, il **frontend JS** gestisce il polling e mostra lo stato.

**PHP (proxy):**
```php
<?php
// /api/generate.php
$qbert = new QBertClient('https://qbert.example.com', appName: 'my_frontend');

$result = $qbert->submit('POST', 'ollama', '/api/generate',
    json: $_POST['data']
);

header('Content-Type: application/json');
echo json_encode($result);  // Ritorna ticket_id al frontend
```

**JavaScript (frontend):**
```javascript
async function generate(prompt) {
    // 1. Invia richiesta
    const res = await fetch('/api/generate.php', {
        method: 'POST',
        body: JSON.stringify({ data: { model: 'llama3', prompt } })
    });
    const data = await res.json();

    if (!data.is_ticket) {
        return data.json;  // Risposta immediata
    }

    // 2. Polling sul ticket
    while (true) {
        const poll = await fetch(`https://qbert.example.com/ticket/${data.ticket_id}`);
        const status = await poll.json();

        if (status.status === 'done') return status;
        if (status.status === 'failed') throw new Error(status.error);

        // Mostra progresso
        showProgress(`In coda: posizione ${status.queue_position || '?'}`);
        await new Promise(r => setTimeout(r, 2000));
    }
}
```

---

## Modalità 3: Polling Bloccante (solo CLI/cron)

⚠️ **NON usare in web app** - blocca il processo PHP.

```php
<?php
// script_cli.php - da terminale o cron

$qbert = new QBertClient('https://qbert.example.com', appName: 'cli_script');

// BLOCCA finché non arriva risposta (anche minuti)
$response = $qbert->post('ollama', '/api/generate', [
    'model' => 'llama3',
    'prompt' => 'Analizza questo documento...'
]);

echo $response['body'];
```

---

## Esempio completo: Bot Telegram

**webhook.php** - riceve messaggi:
```php
<?php
require_once 'lib/QBertClient.php';

$qbert = new QBertClient('https://qbert.example.com', appName: 'telegram_bot');
$input = json_decode(file_get_contents('php://input'), true);
$chatId = $input['message']['chat']['id'];
$text = $input['message']['text'];

$result = $qbert->submit(
    method: 'POST',
    service: 'ollama',
    path: '/api/generate',
    json: ['model' => 'llama3', 'prompt' => $text],
    callbackUrl: 'https://mybot.example.com/qbert-callback?chat_id=' . $chatId
);

if (!$result['is_ticket']) {
    sendTelegramMessage($chatId, $result['json']['response']);
}

http_response_code(200);
```

**qbert-callback.php** - riceve risultati:
```php
<?php
$data = json_decode(file_get_contents('php://input'), true);
$chatId = $_GET['chat_id'];

if ($data['success']) {
    sendTelegramMessage($chatId, $data['response']['body_json']['response']);
} else {
    sendTelegramMessage($chatId, '❌ ' . $data['error']);
}

http_response_code(200);
```

---

## Formato payload callback

**Job completato:**
```json
{
    "ticket_id": "abc123",
    "status": "done",
    "success": true,
    "response": {
        "status_code": 200,
        "headers": {"content-type": "application/json"},
        "body_base64": "7b22...",
        "body_json": {"response": "Ciao!"},
        "body_text": null
    },
    "error": null
}
```

**Job fallito:**
```json
{
    "ticket_id": "abc123",
    "status": "failed",
    "success": false,
    "response": null,
    "error": "Backend service unavailable"
}
```

---

## Richieste Multipart/Form-Data

Per servizi che richiedono `multipart/form-data` (es. upload file, voice clone), usa il parametro `multipart` al posto di `json`:

```php
// Upload file con campi form
$response = $qbert->post('qwen-tts', '/voice_clone', multipart: [
    'text' => 'Ciao mondo',
    'language' => 'it',
    'audio' => new CURLFile('/path/to/sample.wav', 'audio/wav'),
]);

// Submit con callback + multipart
$result = $qbert->submit('POST', 'qwen-tts', '/voice_clone',
    multipart: [
        'text' => 'Ciao mondo',
        'audio' => new CURLFile('/path/to/sample.wav', 'audio/wav'),
    ],
    callbackUrl: 'https://myapp.com/qbert-callback'
);

// CURLFile supporta anche contenuto da stringa
$response = $qbert->post('qwen-tts', '/voice_clone', multipart: [
    'text' => 'Ciao mondo',
    'audio' => new CURLStringFile($audioData, 'sample.wav', 'audio/wav'),  // PHP 8.1+
]);
```

> **Nota:** `json` e `multipart` sono mutuamente esclusivi. Passarli entrambi lancia una `QBertException`.

---

## Priorità

```php
QBertClient::PRIORITY_URGENT  // Salta la coda
QBertClient::PRIORITY_NORMAL  // Default
QBertClient::PRIORITY_LAZY    // Bassa priorità, interrompibile
```

---

## Configurazione

```php
$qbert = new QBertClient(
    baseUrl: 'https://qbert.example.com',
    timeout: 35.0,      // Timeout HTTP singola richiesta
    pollInterval: 2.0,  // Secondi tra polling (modalità bloccante)
    maxWait: 600.0,     // Timeout massimo (modalità bloccante)
    appName: 'my_app'   // Nome app per tracciamento nei log
);
```
