<?php
/////////////////////////////////////////////////////////////////
///////////////// DISPATCHER MODULARE SUB-AGENTI ////////////////
/////////////////////////////////////////////////////////////////

require_once __DIR__ . '/logger.php';

/**
 * Carica il registry degli agenti da include/agents/{id}/agent.php
 * Ogni file ritorna un array con: id, description, parameters, handler, ecc.
 * Ogni agente è una directory autocontenuta (declaration, schema, workflow, helper).
 */
function loadAgentRegistry(): array {
    static $registry = null;
    if ($registry !== null) return $registry;

    $registry = ['agents' => [], 'default' => null, 'enrichments' => []];
    $agentDir = __DIR__ . '/agents/';

    foreach (glob($agentDir . '*/agent.php') as $file) {
        $agent = require $file;
        if (!is_array($agent) || empty($agent['id'])) continue;

        $registry['agents'][$agent['id']] = $agent;

        if (!empty($agent['default'])) {
            $registry['default'] = $agent['id'];
        }
        if (!empty($agent['enriches'])) {
            $registry['enrichments'][$agent['id']] = $agent['enriches'];
        }
    }

    return $registry;
}

/**
 * Inizializza gli schemi DB di tutti gli agenti che dichiarano 'schema' => callable.
 * Invocata dopo initDatabase() sulle tabelle core. Idempotente: i callable devono
 * usare CREATE TABLE IF NOT EXISTS, ALTER guardate da PRAGMA table_info, ecc.
 */
function initAgentSchemas(SQLite3 $db): void {
    $registry = loadAgentRegistry();
    foreach ($registry['agents'] as $id => $agent) {
        if (empty($agent['schema']) || !is_callable($agent['schema'])) continue;
        try {
            ($agent['schema'])($db);
        } catch (\Throwable $e) {
            error_log("[dispatcher] Schema init failed for agent '{$id}': " . $e->getMessage());
        }
    }
}

/**
 * Scorre i comandi slash dichiarati dagli agenti e, se uno matcha, esegue l'handler.
 *
 * Ogni agente può dichiarare un campo opzionale 'commands' come array di
 * ['pattern' => regex, 'handler' => callable]. L'handler riceve ($ctx, $matches)
 * e ritorna null, ['handled' => true] o ['response' => '...'].
 *
 * @return array|null  Il risultato dell'handler se un comando ha matchato, null altrimenti.
 */
function dispatchAgentCommand(string $text, array $ctx): ?array {
    $registry = loadAgentRegistry();
    $logFile = logPath('dispatcher');

    foreach ($registry['agents'] as $id => $agent) {
        if (empty($agent['commands']) || !is_array($agent['commands'])) continue;
        foreach ($agent['commands'] as $cmd) {
            $pattern = $cmd['pattern'] ?? null;
            $handler = $cmd['handler'] ?? null;
            if (!$pattern || !is_callable($handler)) continue;

            if (preg_match($pattern, $text, $matches)) {
                file_put_contents(
                    $logFile,
                    '[' . date('Y-m-d H:i:s') . '] slash cmd matched: agent=' . $id .
                    ' pattern=' . $pattern . ' text=' . substr($text, 0, 100) . "\n\n",
                    FILE_APPEND
                );
                try {
                    return $handler($ctx, $matches) ?? ['handled' => true];
                } catch (\Throwable $e) {
                    error_log("[dispatcher] Slash handler error (agent={$id}): " . $e->getMessage());
                    return ['response' => "Si è verificato un errore durante l'elaborazione del comando."];
                }
            }
        }
    }
    return null;
}

/**
 * Costruisce dinamicamente il prompt del classificatore dalle dichiarazioni degli agenti
 */
function buildClassifierPrompt(array $registry, string $message, string $recentContext = '', string $situationHint = ''): string {
    $intentList = '';
    $paramInstructions = '';
    $intentIds = [];

    foreach ($registry['agents'] as $id => $agent) {
        $intentIds[] = $id;
        $intentList .= "- \"{$id}\": {$agent['description']}\n";

        if (!empty($agent['parameters'])) {
            $paramInstructions .= "Se intent=\"{$id}\", estrai anche:\n";
            foreach ($agent['parameters'] as $paramName => $paramDesc) {
                $paramInstructions .= "  - \"{$paramName}\": {$paramDesc}\n";
            }
        }
    }

    $validIntents = implode(', ', array_map(fn($id) => "\"$id\"", $intentIds));

    $contextSection = '';
    if (!empty($recentContext)) {
        $contextSection = "ULTIMI MESSAGGI IN CHAT (per contesto):\n{$recentContext}\n\n";
    }

    $situationSection = '';
    if (!empty($situationHint)) {
        $situationSection = "SITUAZIONE: {$situationHint}\n\n";
    }

    return <<<PROMPT
Analizza questo messaggio e classifica l'intento dell'utente. Rispondi SOLO con JSON valido, nient'altro.

INTENTI POSSIBILI:
{$intentList}
{$paramInstructions}
{$situationSection}{$contextSection}Formato risposta:
{"intent": "<uno tra {$validIntents}>", "params": {<parametri estratti o oggetto vuoto>}}

Messaggio: "{$message}"
PROMPT;
}

/**
 * Classifica l'intento del messaggio tramite LLM leggero
 * Ritorna ['intent' => string, 'params' => array]
 */
function classifyIntent(string $message, int $chatID = 0, string $situationHint = ''): array {
    $registry = loadAgentRegistry();
    $defaultIntent = $registry['default'] ?? 'chat';
    $logFile = logPath('dispatcher');

    // Recupera ultimi 3 messaggi per dare contesto al classificatore
    $recentContext = '';
    if ($chatID) {
        $recentContext = getChatContext($chatID, 1, 3);
    }

    $prompt = buildClassifierPrompt($registry, $message, $recentContext, $situationHint);

    $startTime = microtime(true);
    $result = callOllamaChatViaQBert(
        OLLAMA_MODEL_LIGHT,
        $prompt,
        ollamaOptions(OLLAMA_MODEL_LIGHT_GPU, ['num_ctx' => 2048]),
        false,
        QBertClient::PRIORITY_NORMAL
    );
    $elapsed = round((microtime(true) - $startTime) * 1000);

    $logEntry = "[" . date('Y-m-d H:i:s') . "] classify ({$elapsed}ms)\n";
    $logEntry .= "MSG: " . substr($message, 0, 100) . "\n";

    if (!$result) {
        $logEntry .= "ERROR: QBert call failed, fallback -> {$defaultIntent}\n";
        file_put_contents($logFile, $logEntry . "\n", FILE_APPEND);
        return ['intent' => $defaultIntent, 'params' => []];
    }

    $llmResponse = stripThinkingTags($result['response'] ?? '');
    $llmResponse = trim($llmResponse);
    $logEntry .= "LLM: {$llmResponse}\n";

    if (preg_match('/\{.*\}/s', $llmResponse, $matches)) {
        $parsed = json_decode($matches[0], true);
        if ($parsed && isset($parsed['intent']) && isset($registry['agents'][$parsed['intent']])) {
            $params = $parsed['params'] ?? [];
            if (!is_array($params)) {
                $params = [];
            }

            // Il modello ogni tanto appiattisce i parametri al primo livello invece
            // di annidarli sotto "params": {"intent":"x","action":"edit","detail":"..."}
            // al posto di {"intent":"x","params":{...}}. Senza questo recupero quei
            // campi finiscono nel nulla e l'agente si comporta come se non avesse
            // ricevuto niente — e' successo davvero a group_memory, che ha mostrato
            // gli appunti invece di correggerli. Il campo `format` di Ollama
            // vincolerebbe la forma, ma QBert non lo inoltra.
            $appiattiti = false;
            if ($params === []) {
                $flat = $parsed;
                unset($flat['intent'], $flat['params']);
                if ($flat !== []) {
                    $params = $flat;
                    $appiattiti = true;
                }
            }

            $logEntry .= "RESULT: intent={$parsed['intent']}";
            if (!empty($params)) {
                $logEntry .= ", params=" . json_encode($params, JSON_UNESCAPED_UNICODE);
            }
            if ($appiattiti) {
                $logEntry .= " (appiattiti al primo livello, recuperati)";
            }
            $logEntry .= "\n";
            file_put_contents($logFile, $logEntry . "\n", FILE_APPEND);

            return [
                'intent' => $parsed['intent'],
                'params' => $params,
            ];
        }
    }

    $logEntry .= "RESULT: parse failed, fallback -> {$defaultIntent}\n";
    file_put_contents($logFile, $logEntry . "\n", FILE_APPEND);
    return ['intent' => $defaultIntent, 'params' => []];
}

/**
 * Dispatcher principale: classifica e instrada al sub-agente corretto
 *
 * @param string $message  Testo del messaggio
 * @param array  $ctx      Contesto: chatID, chatType, message, userName, fromId, firstName, messageId
 */
function dispatchIntent(string $message, array $ctx): void {
    $registry = loadAgentRegistry();

    // Hint per il classifier: se il messaggio è una reply a un'immagine, glielo
    // diciamo esplicitamente così "che colore?" / "trasformala in 3D" diventano
    // riconoscibili anche senza che il testo nomini l'immagine.
    $situationHint = '';
    $raw = $ctx['raw'] ?? null;
    if ($raw && isset($raw['reply_to_message'])) {
        $replyTo = $raw['reply_to_message'];
        if (isset($replyTo['photo'])
            || (isset($replyTo['document']) && function_exists('isImageDocument') && isImageDocument($replyTo['document']))) {
            $situationHint = "L'utente sta rispondendo a un'immagine inviata in precedenza.";
        }
    }

    $classification = classifyIntent($message, $ctx['chatID'], $situationHint);

    $intentId = $classification['intent'];
    $params = $classification['params'];
    $agent = $registry['agents'][$intentId];

    try {
        // CASO 1: Agente enrichment (es. wikipedia arricchisce chat)
        if (isset($registry['enrichments'][$intentId])) {
            $targetId = $registry['enrichments'][$intentId];
            $targetAgent = $registry['agents'][$targetId];

            // Esegui handler enrichment
            $enrichmentResult = ($agent['handler'])($ctx, $params);

            // Messaggio di stato (es. "Sto cercando informazioni su X...")
            $statusMessageId = null;
            if ($enrichmentResult && !empty($enrichmentResult['status_message'])) {
                $statusResult = sendTelegramMessage($ctx['chatID'], $enrichmentResult['status_message']);
                if ($statusResult['ok']) {
                    $statusMessageId = $statusResult['message_id'];
                }
            }

            // Esegui agente target con i dati di arricchimento
            $enrichments = $enrichmentResult ? [$enrichmentResult] : [];
            $result = ($targetAgent['handler'])($ctx, [], $enrichments);

            // Rimuovi messaggio di stato
            if ($statusMessageId) {
                makeAPIRequest('deleteMessage', [
                    'chat_id' => $ctx['chatID'],
                    'message_id' => $statusMessageId,
                ]);
            }

            sendAgentResponse($result, $ctx);
            return;
        }

        // CASO 2: Agente standalone (es. quiz, chat)
        $result = ($agent['handler'])($ctx, $params);
        sendAgentResponse($result, $ctx);

    } catch (\Throwable $e) {
        error_log("[dispatcher] Handler error for intent '{$intentId}': " . $e->getMessage());
        sendTelegramMessage($ctx['chatID'], "Si è verificato un errore durante l'elaborazione.");
    }
}

/**
 * Invia la risposta dell'agente con pulsante TTS, reply e retry logic
 */
function sendAgentResponse(?array $result, array $ctx): void {
    if (!$result) return;
    if (!empty($result['handled'])) return;
    if (empty($result['response'])) return;

    $response = $result['response'];

    // Sentinel legacy: quando _ai_core() fallisce ritorna questa stringa esatta.
    // Serve per skippare il pulsante TTS e non salvare nel contesto.
    // TODO Step G: sostituire con flag esplicito $result['is_error'] per evitare
    // il coupling string-literal tra ai.php e dispatcher.php.
    $isError = ($response === "Si è verificato un errore durante la comunicazione con l'AI.");

    $options = ['parse_mode' => 'HTML'];

    if (TTS_ENABLED && !$isError) {
        $options['reply_markup'] = json_encode([
            'inline_keyboard' => [[
                ['text' => "\xF0\x9F\x94\x8A Ascolta", 'callback_data' => 'tts']
            ]]
        ]);
    }

    if ($ctx['chatType'] !== 'private') {
        $options['reply_to_message_id'] = $ctx['messageId'];
    }

    // Il wrapper gestisce automaticamente retry su reply_to_not_found e
    // can't_parse_entities (plain fallback) + escape del testo LLM raw.
    sendTelegramMessage($ctx['chatID'], $response, $options);

    // Salva risposta nel contesto
    if (!$isError) {
        saveMessageToContext($ctx['chatID'], 'rootbot', $response);
    }
}
