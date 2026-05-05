# Integrare Gemma 4 via Ollama — guida pratica

Appunti operativi raccolti integrando Gemma 4 in un bot PHP in produzione. Pensato per altre istanze Claude (o sviluppatori umani) che devono aggiungere chiamate a Gemma 4 senza ripetere i nostri errori.

## TL;DR

1. Usa **`/api/chat`**, non `/api/generate`.
2. Metti **`"think": false`** al **top level** del body (non dentro `options`).
3. Leggi la risposta da **`message.content`**.
4. Sampling: `temperature=1.0`, `top_p=0.95`, `top_k=64`, `repeat_penalty=1.0`.
5. Se ti serve structured output (`format: "json"` o JSON schema), **non puoi** usare `think=false` — vedi sezione dedicata.

## Modelli

| Modello | Architettura | Contesto | Uso tipico |
|---|---|---|---|
| `gemma4:26b` | MoE | 256K | Risposte principali, generazione contenuti, quiz |
| `gemma4:e4b` | Edge | 128K | Classifier, vision, reviewer, task leggeri |

## Il bug che ti morderà: thinking attivo di default

Gemma 4 ha il **thinking integrato e abilitato di default**. Se non lo disabiliti esplicitamente, tutto il testo generato finisce nel campo `thinking` (o `message.thinking` in chat mode) e il campo visibile resta vuoto.

### Sintomi tipici

```json
{
  "response": "",
  "done": true,
  "done_reason": "length",
  "eval_count": 2048,
  "prompt_eval_count": 1234
}
```

- `response` (o `message.content`) vuoto
- `done_reason: "length"`
- `eval_count` uguale a `num_predict` → il modello ha consumato tutto il budget a pensare senza emettere la risposta finale
- Il campo `context` contiene i token generati, ma sono interni

Può funzionare per qualche chiamata con prompt corti e poi iniziare a fallire sistematicamente su prompt più lunghi: il budget si esaurisce prima di passare dal thinking alla produzione.

## Ricetta corretta

### Endpoint

Usa `/api/chat`. **Non** usare `/api/generate`: il parametro `think` è instabile lì (issue ollama/ollama #14793) e viene spesso ignorato in silenzio.

### Body della richiesta

```json
{
  "model": "gemma4:26b",
  "messages": [
    {"role": "system", "content": "Sei un assistente conciso."},
    {"role": "user", "content": "Ciao"}
  ],
  "think": false,
  "stream": false,
  "options": {
    "temperature": 1.0,
    "top_p": 0.95,
    "top_k": 64,
    "repeat_penalty": 1.0,
    "num_predict": 512
  }
}
```

Punti critici:

- `"think": false` è **al top level**, non dentro `options`. Metterlo in `options` viene ignorato.
- `stream: false` se non stai streammando; se streami, i chunk arrivano in `message.content` una volta che il thinking è chiuso.
- `num_predict` va scelto per la sola risposta finale: senza thinking non serve budget extra.

### Parsing della risposta

```php
$data = json_decode($resp, true);
$text = $data['message']['content'] ?? '';
```

Se il testo è vuoto e `done_reason` è `length`, quasi certamente `think=false` non è stato applicato.

## Sampling raccomandato

Google raccomanda per Gemma 4:

- `temperature = 1.0`
- `top_p = 0.95`
- `top_k = 64`
- `repeat_penalty = 1.0`

**Nota:** `repeat_penalty=1.2` (default di molti setup Ollama) **degrada visibilmente la qualità** su output lunghi: il modello inizia a sostituire parole comuni con sinonimi improbabili per evitare ripetizioni. Portalo a 1.0.

Per task deterministici (classificazione, estrazione), puoi scendere a `temperature=0.3`–`0.5`, ma mantieni `top_p` e `top_k` come sopra.

## Structured output: la trappola nascosta

Se usi `"format": "json"` o JSON schema di Ollama:

> **Con `think=false`, Ollama ignora silenziosamente il parametro `format`.** (issue ollama/ollama #15260)

Non ricevi errore. Ricevi testo libero invece del JSON che hai chiesto. Opzioni:

1. **Lascia il thinking attivo** e fai parsing di entrambi i campi (`message.thinking` + `message.content`). Leggi `message.content` come JSON.
2. **Parsing tollerante lato client**: chiedi JSON nel system prompt, estrai il primo blocco `{...}` o ```json ... ``` dalla risposta con regex.
3. **Usa un modello diverso** per quel task se hai bisogno di structured output rigoroso.

Nel nostro bot abbiamo scelto (2) per i classificatori: prompt chiede JSON, parser tollerante estrae il primo oggetto valido.

## Quando invece lasciare il thinking attivo

Il thinking **aiuta** su task di ragionamento complesso:

- Estrazione di profili utente da storici lunghi
- Review di output generati (quiz reviewer, fact-checking)
- Decomposizione di richieste multi-step

In questi casi:

- Ometti `think` (default true) o metti `"think": true`
- Aumenta `num_predict` (il thinking consuma token: mettere 4096+ è ragionevole)
- Nel wrapper, logga `message.thinking` per debug ma usa solo `message.content` come output
- Aspettati latenza maggiore (2-5x rispetto a `think=false`)

## Esempio completo (PHP + cURL)

```php
function gemmaChat(array $messages, bool $think = false, array $opts = []): string {
    $body = [
        'model' => 'gemma4:26b',
        'messages' => $messages,
        'think' => $think,
        'stream' => false,
        'options' => array_merge([
            'temperature' => 1.0,
            'top_p' => 0.95,
            'top_k' => 64,
            'repeat_penalty' => 1.0,
            'num_predict' => 512,
        ], $opts),
    ];

    $ch = curl_init('http://ollama:11434/api/chat');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($body),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 120,
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($resp, true);

    if (($data['done_reason'] ?? '') === 'length' && empty($data['message']['content'])) {
        error_log("Gemma4: response vuota con done_reason=length — think non disabilitato?");
    }

    return $data['message']['content'] ?? '';
}
```

## Checklist di debug quando qualcosa va storto

- [ ] Stai chiamando `/api/chat` e non `/api/generate`?
- [ ] `think: false` è al **top level** del body?
- [ ] Stai leggendo `message.content` e non `response`?
- [ ] `done_reason` è `stop` o `length`? Se `length` con content vuoto → `think` non applicato.
- [ ] Se usi `format: "json"`, hai disabilitato `think`? Se sì, il format viene ignorato.
- [ ] `repeat_penalty` è 1.0? Valori più alti degradano output lunghi.
- [ ] `num_predict` è sufficiente per la risposta che ti aspetti?

## Fonti

- ollama.com/library/gemma4
- ai.google.dev/gemma/docs/core/prompt-formatting-gemma4
- Issue ollama/ollama: #15288, #15428, #14793 (think su /api/generate), #15260 (format ignorato con think=false)

## Storia

Diagnosticato 2026-04-15 su estrattore memorie utenti: dopo qualche batch buono, chiamate successive fallivano tutte con `response: ""` e `done_reason: length`. La fix è stata aggiungere `think: false` top-level nel wrapper QBert.
