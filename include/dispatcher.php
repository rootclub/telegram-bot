<?php
/////////////////////////////////////////////////////////////////
///////////////// DISPATCHER MODULARE SUB-AGENTI ////////////////
/////////////////////////////////////////////////////////////////

/**
 * Carica il registry degli agenti da include/agents/*.php
 * Ogni file ritorna un array con: id, description, parameters, handler, ecc.
 */
function loadAgentRegistry(): array {
    static $registry = null;
    if ($registry !== null) return $registry;

    $registry = ['agents' => [], 'default' => null, 'enrichments' => []];
    $agentDir = __DIR__ . '/agents/';

    foreach (glob($agentDir . '*.php') as $file) {
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
 * Costruisce dinamicamente il prompt del classificatore dalle dichiarazioni degli agenti
 */
function buildClassifierPrompt(array $registry, string $message, string $recentContext = ''): string {
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

    return <<<PROMPT
Analizza questo messaggio e classifica l'intento dell'utente. Rispondi SOLO con JSON valido, nient'altro.

INTENTI POSSIBILI:
{$intentList}
{$paramInstructions}
{$contextSection}Formato risposta:
{"intent": "<uno tra {$validIntents}>", "params": {<parametri estratti o oggetto vuoto>}}

Messaggio: "{$message}"
PROMPT;
}

/**
 * Classifica l'intento del messaggio tramite LLM leggero
 * Ritorna ['intent' => string, 'params' => array]
 */
function classifyIntent(string $message, int $chatID = 0): array {
    $registry = loadAgentRegistry();
    $defaultIntent = $registry['default'] ?? 'chat';
    $logFile = dirname(__DIR__) . '/dispatcher.log';

    // Recupera ultimi 3 messaggi per dare contesto al classificatore
    $recentContext = '';
    if ($chatID) {
        $recentContext = getChatContext($chatID, 1, 3);
    }

    $prompt = buildClassifierPrompt($registry, $message, $recentContext);

    $requestData = [
        'model' => OLLAMA_MODEL_LIGHT,
        'prompt' => $prompt,
        'stream' => false,
        'options' => ollamaOptions(OLLAMA_MODEL_LIGHT_GPU, ['num_ctx' => 2048]),
    ];

    $startTime = microtime(true);
    $result = callOllamaViaQBert($requestData, QBertClient::PRIORITY_NORMAL);
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
            $logEntry .= "RESULT: intent={$parsed['intent']}";
            if (!empty($parsed['params'])) {
                $logEntry .= ", params=" . json_encode($parsed['params'], JSON_UNESCAPED_UNICODE);
            }
            $logEntry .= "\n";
            file_put_contents($logFile, $logEntry . "\n", FILE_APPEND);

            return [
                'intent' => $parsed['intent'],
                'params' => $parsed['params'] ?? [],
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
    $classification = classifyIntent($message, $ctx['chatID']);

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
                $statusResult = makeAPIRequest('sendMessage', [
                    'chat_id' => $ctx['chatID'],
                    'text' => $enrichmentResult['status_message'],
                ]);
                if ($statusResult && $statusResult['ok']) {
                    $statusMessageId = $statusResult['result']['message_id'];
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
        makeAPIRequest('sendMessage', [
            'chat_id' => $ctx['chatID'],
            'text' => "Si è verificato un errore durante l'elaborazione.",
        ]);
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

    $messageParams = [
        'chat_id' => $ctx['chatID'],
        'text' => $response,
        'parse_mode' => 'HTML',
    ];

    // Pulsante TTS
    $isError = ($response === "Si è verificato un errore durante la comunicazione con l'AI.");
    if (TTS_ENABLED && !$isError) {
        $messageParams['reply_markup'] = json_encode([
            'inline_keyboard' => [[
                ['text' => "\xF0\x9F\x94\x8A Ascolta", 'callback_data' => 'tts']
            ]]
        ]);
    }

    // Reply in gruppo
    if ($ctx['chatType'] !== 'private') {
        $messageParams['reply_to_message_id'] = $ctx['messageId'];
    }

    $sendResult = makeAPIRequest('sendMessage', $messageParams);

    // Retry: messaggio originale eliminato
    if (!$sendResult || !$sendResult['ok']) {
        $errCode = $sendResult['error_code'] ?? 0;
        $errDesc = $sendResult['description'] ?? '';

        if ($errCode == 400 && strpos($errDesc, 'message to be replied not found') !== false) {
            unset($messageParams['reply_to_message_id']);
            $sendResult = makeAPIRequest('sendMessage', $messageParams);
        }

        // Retry: errore parse HTML
        if (!$sendResult || !$sendResult['ok']) {
            unset($messageParams['parse_mode']);
            makeAPIRequest('sendMessage', $messageParams);
        }
    }

    // Salva risposta nel contesto
    if (!$isError) {
        saveMessageToContext($ctx['chatID'], 'rootbot', $response);
    }
}
