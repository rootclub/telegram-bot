<?php
/////////////////////////////////////////////////////////////////
////////////////////// GESTIONE CHAT CON AI ////////////////////
////////////////////////////////////////////////////////////////

require_once __DIR__ . '/QBertClient.php';
require_once __DIR__ . '/logger.php';

/**
 * Rimuove i tag di thinking di Gemma 4 dalla risposta
 */
function stripThinkingTags($text) {
    return trim(preg_replace('/<\|channel>thought\n.*?<channel\|>/s', '', $text));
}

/**
 * Personalità condivisa di rootbot, usata come base in tutti i prompt AI
 */
function rootbotPersona(): string {
    return <<<'PERSONA'
Sei rootbot, il bot del circolo /root (detto anche root o root club).

Il tuo carattere:
- Sei un osservatore curioso e benevolo dell'umanità, tutto ti sembra interessante e a volte buffo
- Hai un pizzico dello spirito di Bender di Futurama: cinico, ironico, pungente quando serve, mai ingenuo e hai anche un pizzico dello spirito di Sheldon di Big Bang Theory.
- Sotto sotto questi umani ti stanno simpatici, anche se non li capisci sempre
- Sei sarcastico ma mai sgarbato, ti piace punzecchiare con affetto
PERSONA;
}

/**
 * Costruisce le opzioni Ollama con parametri sampling Gemma 4
 */
function ollamaOptions(bool $useGpu, array $extra = []): array {
    $opts = [
        'temperature' => OLLAMA_TEMPERATURE,
        'top_p' => OLLAMA_TOP_P,
        'top_k' => OLLAMA_TOP_K,
        'num_gpu' => $useGpu ? 99 : 0,
    ];
    return array_merge($opts, $extra);
}

/**
 * Istanza singleton di QBertClient
 */
function getQBertClient() {
    static $qbert = null;
    if ($qbert === null) {
        $qbert = new QBertClient(QBERT_URL, timeout: 120.0, maxWait: 600.0, appName: BOT_NAME);
    }
    return $qbert;
}

/**
 * Chiama Ollama via QBert (chiamata bloccante semplice)
 * Per chiamate non-streaming dove non serve feedback progressivo
 *
 * @param array $requestData Dati della richiesta (model, prompt, stream, options, images...)
 * @param string $priority Priorità QBert (urgent/normal/lazy)
 * @return array|null Risposta decodificata o null in caso di errore
 */
function callOllamaViaQBert($requestData, $priority = QBertClient::PRIORITY_NORMAL) {
    $qbert = getQBertClient();

    // Forza stream=false per QBert
    $requestData['stream'] = false;

    $result = $qbert->post('ollama', '/api/generate', $requestData, $priority);

    if ($result['is_ticket']) {
        // Job accodato, aspetta (polling bloccante)
        $result = $qbert->waitForTicket($result['ticket_id']);
    }

    if (isset($result['json'])) {
        return $result['json'];
    }

    return null;
}

/**
 * Chiama Ollama /api/chat via QBert. Restituisce una shape normalizzata
 * equivalente a /api/generate: ['response' => <content>, 'thinking' => <thinking>, ...]
 * in modo che i chiamanti non debbano distinguere il formato.
 *
 * Richiesto per Gemma 4: su /api/generate il flag `think` è instabile,
 * su /api/chat funziona correttamente. Passa `$think` come top-level del body.
 *
 * @param string $model
 * @param string $prompt      Contenuto del messaggio user
 * @param array  $options     Options Ollama (num_ctx, num_predict, temperature, ecc.)
 * @param bool|null $think    true/false per forzare; null per lasciare default modello
 * @param string $priority
 * @return array|null  ['response' => string, 'thinking' => string, 'done_reason' => ..., 'raw' => <full>]
 */
function callOllamaChatViaQBert(string $model, string $prompt, array $options = [], ?bool $think = null, $priority = QBertClient::PRIORITY_NORMAL, ?string $system = null) {
    $qbert = getQBertClient();

    $messages = [];
    if ($system !== null && $system !== '') {
        $messages[] = ['role' => 'system', 'content' => $system];
    }
    $messages[] = ['role' => 'user', 'content' => $prompt];

    $body = [
        'model'    => $model,
        'messages' => $messages,
        'stream'   => false,
        'options'  => $options,
    ];
    if ($think !== null) {
        $body['think'] = $think;
    }

    $result = $qbert->post('ollama', '/api/chat', $body, $priority);
    if ($result['is_ticket']) {
        $result = $qbert->waitForTicket($result['ticket_id']);
    }
    if (!isset($result['json']) || !is_array($result['json'])) {
        return null;
    }
    $json = $result['json'];
    $msg = $json['message'] ?? [];
    return [
        'response'           => (string)($msg['content'] ?? ''),
        'thinking'           => (string)($msg['thinking'] ?? ''),
        'done'               => $json['done'] ?? null,
        'done_reason'        => $json['done_reason'] ?? null,
        'prompt_eval_count'  => $json['prompt_eval_count'] ?? null,
        'eval_count'         => $json['eval_count'] ?? null,
        'total_duration'     => $json['total_duration'] ?? null,
        'raw'                => $json,
    ];
}

/**
 * Chiama Ollama via QBert con refresh del typing indicator
 * Per chiamate dove l'utente aspetta e vogliamo mostrare "sta scrivendo..."
 *
 * @param array $requestData Dati della richiesta
 * @param int $chatId Chat ID per typing indicator
 * @param string $priority Priorità QBert
 * @return array|null Risposta decodificata o null in caso di errore
 */
/**
 * POST sincrona verso QBert con CURLOPT_PROGRESSFUNCTION: cURL invoca la callback
 * ad intervalli regolari durante il transfer, anche mentre attende la risposta del
 * server. Permette di fare lavoro collaterale (refresh chat action Telegram) senza
 * toccare QBertClient (che e' libreria upstream) ne' usare curl_multi (che in questo
 * ambiente PHP 8.4 + curl 7.61 segfaulta).
 *
 * Ritorna nella stessa shape di QBertClient::submit() (is_ticket / status_code /
 * body / json / error).
 */
function qbertPostWithProgress(string $service, string $path, array $json, callable $progress, string $priority = QBertClient::PRIORITY_NORMAL): array {
    $url = rtrim(QBERT_URL, '/') . '/' . $service . '/' . ltrim($path, '/');
    $body = json_encode($json);

    $headers = [
        'X-Priority: ' . $priority,
        'X-App-Name: ' . BOT_NAME,
        'Content-Type: application/json',
        'Content-Length: ' . strlen($body),
    ];

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 600,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_NOPROGRESS => false,
        CURLOPT_PROGRESSFUNCTION => function ($res, $dlSize, $dl, $ulSize, $ul) use ($progress) {
            try { $progress(); } catch (\Throwable $e) { /* non interrompere il transfer */ }
            return 0;
        },
    ]);

    $respBody = curl_exec($ch);
    $statusCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($respBody === false || $statusCode === 0) {
        return ['is_ticket' => false, 'status_code' => 0, 'body' => '', 'json' => null, 'error' => $error ?: 'curl failed'];
    }
    if ($statusCode === 202) {
        $data = json_decode($respBody, true);
        return ['is_ticket' => true, 'ticket_id' => $data['ticket_id'] ?? null];
    }
    return [
        'is_ticket' => false,
        'status_code' => $statusCode,
        'body' => $respBody,
        'json' => json_decode($respBody, true),
    ];
}

function callOllamaViaQBertWithTyping($requestData, $chatId, $priority = QBertClient::PRIORITY_NORMAL) {
    $qbert = getQBertClient();

    // Forza stream=false
    $requestData['stream'] = false;

    // Invia typing iniziale
    makeAPIRequest('sendChatAction', ['chat_id' => $chatId, 'action' => 'typing']);
    $lastTypingTime = time();

    // Chiamata sincrona con progress callback per rinfrescare il chat action
    // Telegram (durata ~5s) anche quando QBert serve la richiesta sincrona
    // e impiega molti secondi.
    $progress = function () use ($chatId, &$lastTypingTime) {
        if ((time() - $lastTypingTime) >= 3) {
            makeAPIRequest('sendChatAction', ['chat_id' => $chatId, 'action' => 'typing']);
            $lastTypingTime = time();
        }
    };

    $result = qbertPostWithProgress('ollama', '/api/generate', $requestData, $progress, $priority);

    if (!$result['is_ticket']) {
        // Risposta sincrona da QBert
        return $result['json'] ?? null;
    }

    // Polling manuale con typing refresh
    $ticketId = $result['ticket_id'];
    $start = microtime(true);
    $maxWait = 600.0;
    $pollInterval = 1.0;

    while (true) {
        if ((microtime(true) - $start) > $maxWait) {
            return null; // Timeout
        }

        // Rinnova typing ogni 3 secondi (margine sui 5s di Telegram)
        if ((time() - $lastTypingTime) >= 3) {
            makeAPIRequest('sendChatAction', ['chat_id' => $chatId, 'action' => 'typing']);
            $lastTypingTime = time();
        }

        $ticket = $qbert->poll($ticketId);

        if (!$ticket['found']) {
            return null;
        }

        if ($ticket['done']) {
            return $ticket['json'] ?? null;
        }

        if ($ticket['failed']) {
            return null;
        }

        usleep((int)($pollInterval * 1000000));
    }
}

/**
 * Versione di callOllamaChatViaQBert con refresh del typing indicator.
 * Stessa shape di ritorno del wrapper chat normale.
 */
function callOllamaChatViaQBertWithTyping(string $model, string $prompt, int $chatId, array $options = [], ?bool $think = null, $priority = QBertClient::PRIORITY_NORMAL) {
    $qbert = getQBertClient();

    $body = [
        'model'    => $model,
        'messages' => [['role' => 'user', 'content' => $prompt]],
        'stream'   => false,
        'options'  => $options,
    ];
    if ($think !== null) {
        $body['think'] = $think;
    }

    makeAPIRequest('sendChatAction', ['chat_id' => $chatId, 'action' => 'typing']);
    $lastTypingTime = time();
    $progress = function () use ($chatId, &$lastTypingTime) {
        if ((time() - $lastTypingTime) >= 3) {
            makeAPIRequest('sendChatAction', ['chat_id' => $chatId, 'action' => 'typing']);
            $lastTypingTime = time();
        }
    };

    $result = qbertPostWithProgress('ollama', '/api/chat', $body, $progress, $priority);

    $json = null;
    if (!$result['is_ticket']) {
        $json = $result['json'] ?? null;
    } else {
        $ticketId = $result['ticket_id'];
        $start = microtime(true);
        $maxWait = 600.0;
        while (true) {
            if ((microtime(true) - $start) > $maxWait) return null;
            if ((time() - $lastTypingTime) >= 3) {
                makeAPIRequest('sendChatAction', ['chat_id' => $chatId, 'action' => 'typing']);
                $lastTypingTime = time();
            }
            $ticket = $qbert->poll($ticketId);
            if (!$ticket['found'] || $ticket['failed']) return null;
            if ($ticket['done']) { $json = $ticket['json'] ?? null; break; }
            usleep(1000000);
        }
    }

    if (!is_array($json)) return null;
    $msg = $json['message'] ?? [];
    return [
        'response'           => (string)($msg['content'] ?? ''),
        'thinking'           => (string)($msg['thinking'] ?? ''),
        'done'               => $json['done'] ?? null,
        'done_reason'        => $json['done_reason'] ?? null,
        'prompt_eval_count'  => $json['prompt_eval_count'] ?? null,
        'eval_count'         => $json['eval_count'] ?? null,
        'total_duration'     => $json['total_duration'] ?? null,
        'raw'                => $json,
    ];
}

/**
 * Classifica se un messaggio richiede una ricerca Wikipedia
 * e in caso positivo estrae il termine di ricerca.
 * Usa OLLAMA_MODEL_LIGHT per velocità.
 *
 * @param string $message Il messaggio dell'utente
 * @return array ['needs_wiki' => bool, 'search_term' => string|null]
 */
function classifyForWikipedia($message) {
    $logFile = logPath('wiki_search');

    // Prompt compatto per classificazione + estrazione
    $prompt = <<<PROMPT
Analizza questo messaggio e rispondi SOLO con JSON.

Se l'utente chiede informazioni fattuali/enciclopediche (chi è, cos'è, quando, dove, storia di, significato di, definizione, spiegami...), rispondi:
{"wiki": true, "term": "termine da cercare su Wikipedia"}

Se è conversazione, opinione, saluto, domanda personale o non richiede Wikipedia:
{"wiki": false}

Messaggio: "{$message}"
PROMPT;

    $startTime = microtime(true);
    $result = callOllamaChatViaQBert(
        OLLAMA_MODEL_LIGHT,
        $prompt,
        ollamaOptions(OLLAMA_MODEL_LIGHT_GPU),
        false,
        QBertClient::PRIORITY_NORMAL
    );
    $elapsed = round((microtime(true) - $startTime) * 1000);

    // Log
    $logEntry = "[" . date('Y-m-d H:i:s') . "] classify ({$elapsed}ms)\n";
    $logEntry .= "MSG: " . substr($message, 0, 100) . "\n";

    if (!$result) {
        $logEntry .= "ERROR: QBert call failed\n";
        file_put_contents($logFile, $logEntry, FILE_APPEND);
        return ['needs_wiki' => false, 'search_term' => null];
    }

    $llmResponse = $result['response'] ?? '';

    // Rimuovi tag di thinking
    $llmResponse = stripThinkingTags($llmResponse);
    $llmResponse = trim($llmResponse);

    $logEntry .= "LLM: $llmResponse\n";

    // Estrai JSON dalla risposta
    if (preg_match('/\{.*\}/s', $llmResponse, $matches)) {
        $parsed = json_decode($matches[0], true);
        if ($parsed && isset($parsed['wiki'])) {
            $needsWiki = (bool)$parsed['wiki'];
            $searchTerm = $parsed['term'] ?? null;

            $logEntry .= "RESULT: wiki=" . ($needsWiki ? 'YES' : 'NO');
            if ($searchTerm) $logEntry .= ", term='$searchTerm'";
            $logEntry .= "\n";
            file_put_contents($logFile, $logEntry . "\n", FILE_APPEND);

            return ['needs_wiki' => $needsWiki, 'search_term' => $searchTerm];
        }
    }

    $logEntry .= "RESULT: parse failed, default NO\n";
    file_put_contents($logFile, $logEntry . "\n", FILE_APPEND);
    return ['needs_wiki' => false, 'search_term' => null];
}

/**
 * Cerca informazioni su Wikipedia per arricchire la risposta AI
 * Preferisce Wikipedia italiana, fallback su inglese
 *
 * @param string $searchTerm Termine da cercare
 * @return string|null Contenuto Wikipedia formattato o null
 */
function getWikipediaContext($searchTerm) {
    $logFile = logPath('wiki_search');

    // Cerca prima su Wikipedia italiana (il bot è italiano)
    $resultIt = fetchWikipediaContentByLang($searchTerm, 'it');
    $resultEn = null;

    // Usa italiano se ha contenuto sufficiente e non è disambiguazione
    if ($resultIt && strlen($resultIt['extract']) >= 300 && !isDisambiguationPage($resultIt['extract'])) {
        $result = $resultIt;
        file_put_contents($logFile, "WIKI: uso italiano '$searchTerm'\n", FILE_APPEND);
    } else {
        // Fallback su inglese
        $resultEn = fetchWikipediaContentByLang($searchTerm, 'en');

        if ($resultEn && !isDisambiguationPage($resultEn['extract'])) {
            // Se italiano esiste ma è corto, preferisci comunque italiano se inglese non è molto meglio
            if ($resultIt && !isDisambiguationPage($resultIt['extract']) &&
                strlen($resultIt['extract']) >= 200 &&
                strlen($resultEn['extract']) < strlen($resultIt['extract']) * 3) {
                $result = $resultIt;
                file_put_contents($logFile, "WIKI: preferisco italiano (EN non molto meglio)\n", FILE_APPEND);
            } else {
                $result = $resultEn;
                file_put_contents($logFile, "WIKI: uso inglese per '$searchTerm'\n", FILE_APPEND);
            }
        } elseif ($resultIt && !isDisambiguationPage($resultIt['extract'])) {
            $result = $resultIt;
            file_put_contents($logFile, "WIKI: uso italiano (EN non disponibile/disambigua)\n", FILE_APPEND);
        } else {
            file_put_contents($logFile, "WIKI: nessun risultato valido per '$searchTerm'\n\n", FILE_APPEND);
            return null;
        }
    }

    if (!$result || empty($result['extract'])) {
        file_put_contents($logFile, "WIKI: nessun risultato per '$searchTerm'\n\n", FILE_APPEND);
        return null;
    }

    $title = $result['title'];
    $extract = $result['extract'];
    $lang = $result['lang'] ?? '?';

    // Tronca se troppo lungo (max 2000 caratteri per non appesantire il prompt)
    if (strlen($extract) > 2000) {
        $extract = substr($extract, 0, 2000) . '...';
    }

    file_put_contents($logFile, "WIKI: trovato '$title' [$lang] (" . strlen($extract) . " chars)\n\n", FILE_APPEND);

    return "### INFORMAZIONI DA WIKIPEDIA: {$title} ###\n{$extract}";
}

/**
 * Analizza un'immagine con Ollama multimodale
 * @param string $fileId ID del file Telegram
 * @param string $caption Eventuale didascalia allegata all'immagine
 * @return string|null Descrizione dell'immagine o null se fallisce
 */
function analyzeImage($fileId, $caption = '', $chatId = null) {
    $logFile = logPath('debug');
    file_put_contents($logFile, "=== analyzeImage START ===\n", FILE_APPEND);
    file_put_contents($logFile, "fileId=$fileId\n", FILE_APPEND);
    file_put_contents($logFile, "caption=" . substr($caption, 0, 50) . "\n", FILE_APPEND);

    // Feedback immediato all'utente: il modello vision può impiegare diversi secondi
    if ($chatId !== null) {
        makeAPIRequest('sendChatAction', ['chat_id' => $chatId, 'action' => 'typing']);
    }

    // Ottieni info file da Telegram
    $fileInfo = makeAPIRequest('getFile', ['file_id' => $fileId]);
    if (!$fileInfo['ok']) {
        file_put_contents($logFile, "FAIL: getFile failed - " . json_encode($fileInfo) . "\n", FILE_APPEND);
        return null;
    }
    file_put_contents($logFile, "getFile OK: " . $fileInfo['result']['file_path'] . "\n", FILE_APPEND);

    // Scarica l'immagine
    $fileUrl = "https://api.telegram.org/file/bot" . BOT_TOKEN . "/" . $fileInfo['result']['file_path'];
    $imageContent = @file_get_contents($fileUrl);
    if (!$imageContent) {
        file_put_contents($logFile, "FAIL: download failed from $fileUrl\n", FILE_APPEND);
        return null;
    }
    file_put_contents($logFile, "Download OK: " . strlen($imageContent) . " bytes\n", FILE_APPEND);

    // Converti formati non supportati (AVIF, WEBP, etc.) in JPEG
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $finfo->buffer($imageContent);
    file_put_contents($logFile, "Detected MIME: $mimeType\n", FILE_APPEND);

    // Se non è JPEG o PNG, converti in JPEG
    if (!in_array($mimeType, ['image/jpeg', 'image/png', 'image/gif'])) {
        file_put_contents($logFile, "Converting to JPEG...\n", FILE_APPEND);
        $img = @imagecreatefromstring($imageContent);
        if ($img === false) {
            file_put_contents($logFile, "FAIL: Cannot create image from string\n", FILE_APPEND);
            return null;
        }
        ob_start();
        imagejpeg($img, null, 90);
        $imageContent = ob_get_clean();
        imagedestroy($img);
        file_put_contents($logFile, "Converted to JPEG: " . strlen($imageContent) . " bytes\n", FILE_APPEND);
    }

    // Converti in base64
    $imageBase64 = base64_encode($imageContent);

    // Costruisci il prompt
    // Rimuovi le menzioni del bot dalla caption per vedere se c'è altro contenuto
    $cleanCaption = trim(preg_replace('/@rootbotbot\b|@rootbot\b|@root\b|@bot\b|\brootbotbot\b|\brootbot\b/i', '', $caption));

    if (!empty($cleanCaption)) {
        // Se c'è contenuto oltre alla menzione, usa quello come prompt
        $prompt = $cleanCaption;
    } else {
        // Caption vuota o solo menzione: descrivi l'immagine
        $prompt = "Descrivi nel dettaglio cosa vedi menzionando se si tratta di una foto, un disegno, un render, ecc... Se ci sono scritte o testi, riportali tutti. Sii oggettivo, senza interpretazioni. Scrivi solo la descrizione dell'immagine senza preamboli e commenti.";
    }
    file_put_contents($logFile, "Prompt: " . substr($prompt, 0, 100) . "\n", FILE_APPEND);

    file_put_contents($logFile, "Calling Ollama via QBert model=" . OLLAMA_MODEL_VISION . ", GPU=" . (OLLAMA_MODEL_VISION_GPU ? 'YES' : 'NO') . "\n", FILE_APPEND);

    // Chiama Ollama con modello vision via QBert (reasoning abilitato)
    $requestData = [
        'model' => OLLAMA_MODEL_VISION,
        'prompt' => "<|think|>\n" . $prompt,
        'images' => [$imageBase64],
        'stream' => false,
        'options' => ollamaOptions(OLLAMA_MODEL_VISION_GPU),
    ];

    if ($chatId !== null) {
        $result = callOllamaViaQBertWithTyping($requestData, $chatId, QBertClient::PRIORITY_NORMAL);
    } else {
        $result = callOllamaViaQBert($requestData, QBertClient::PRIORITY_NORMAL);
    }

    if (!$result) {
        file_put_contents($logFile, "FAIL: QBert call failed\n", FILE_APPEND);
        return null;
    }
    file_put_contents($logFile, "QBert call OK\n", FILE_APPEND);

    $description = $result['response'] ?? null;

    file_put_contents($logFile, "Ollama response length=" . strlen($description ?? '') . "\n", FILE_APPEND);

    if ($description) {
        // Rimuovi tag di thinking
        $description = stripThinkingTags($description);
        $description = trim($description);
        file_put_contents($logFile, "After cleanup length=" . strlen($description) . "\n", FILE_APPEND);
    }

    if (empty($description)) {
        file_put_contents($logFile, "FAIL: EMPTY description after processing!\n", FILE_APPEND);
    }

    file_put_contents($logFile, "=== analyzeImage END ===\n", FILE_APPEND);
    return $description;
}

function saveMessageToContext($groupId, $userName, $messageText, $userId = null, $replyToUserId = null) {
    global $db;

    // Inserisci il nuovo messaggio
    $stmt = $db->prepare("INSERT INTO contesto_chat (group_id, user_name, message_text, timestamp, user_id, reply_to_user_id) VALUES (:group_id, :user_name, :message_text, :timestamp, :user_id, :reply_to_user_id)");
    $stmt->bindValue(':group_id', $groupId, SQLITE3_INTEGER);
    $stmt->bindValue(':user_name', $userName, SQLITE3_TEXT);
    $stmt->bindValue(':message_text', $messageText, SQLITE3_TEXT);
    $stmt->bindValue(':timestamp', time(), SQLITE3_INTEGER);
    $stmt->bindValue(':user_id', $userId, $userId === null ? SQLITE3_NULL : SQLITE3_INTEGER);
    $stmt->bindValue(':reply_to_user_id', $replyToUserId, $replyToUserId === null ? SQLITE3_NULL : SQLITE3_INTEGER);
    $stmt->execute();

    // Conta il numero di messaggi per questo gruppo
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM contesto_chat WHERE group_id = :group_id");
    $stmt->bindValue(':group_id', $groupId, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $row = $result->fetchArray(SQLITE3_ASSOC);
    $count = $row['count'];

    // Se abbiamo più di 5000 messaggi, elimina i più vecchi
    if ($count > 5000) {
        $toDelete = $count - 5000;
        $stmt = $db->prepare("DELETE FROM contesto_chat WHERE group_id = :group_id AND id IN (SELECT id FROM contesto_chat WHERE group_id = :group_id ORDER BY timestamp ASC LIMIT :to_delete)");
        $stmt->bindValue(':group_id', $groupId, SQLITE3_INTEGER);
        $stmt->bindValue(':to_delete', $toDelete, SQLITE3_INTEGER);
        $stmt->execute();
    }
}

function getChatContext($groupId, $hours = 24, $limit = 200) {
    global $db;
    
    // Calcola il timestamp di X ore fa
    $hours_ago = time() - ($hours * 3600);
    
    // Query modificata per usare timestamp UNIX
    $stmt = $db->prepare("
        SELECT user_name, message_text, timestamp 
        FROM contesto_chat 
        WHERE group_id = :group_id 
        AND timestamp >= :hours_ago
        ORDER BY timestamp DESC 
        LIMIT " . intval($limit)
    );
    
    $stmt->bindValue(':group_id', $groupId, SQLITE3_INTEGER);
    $stmt->bindValue(':hours_ago', $hours_ago, SQLITE3_INTEGER);
    
    $result = $stmt->execute();
    if (!$result) {
        error_log("SQLite Error in getChatContext: " . $db->lastErrorMsg());
        return "";
    }
    
    $context = [];
    $count = 0;
    
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $context[] = $row['user_name'] . ": " . $row['message_text'];
        $count++;
    }
    
    // Log per debug
    error_log("getChatContext: Found $count messages for group $groupId");
    if (empty($context)) {
        error_log("getChatContext: No messages found. Hours ago: $hours_ago, Current time: " . time());
    }
    
    $msg_array = array_reverse($context);
    $result = join("\n", $msg_array);
    
    // Log del risultato finale
    error_log("getChatContext final length: " . strlen($result));

    return $result;
}

/**
 * Recupera contesto misto: messaggi gruppo + conversazione specifica user↔bot
 * @param int $groupId ID del gruppo
 * @param string $userName Nome dell'utente con cui il bot sta parlando
 * @param int $groupLimit Numero di messaggi recenti del gruppo
 * @param int $conversationLimit Numero di scambi user↔bot da includere
 * @return array ['group' => string, 'conversation' => string]
 */
function getChatContextMixed($groupId, $userName, $groupLimit = 10, $conversationLimit = 10) {
    global $db;

    $hours_ago = time() - (24 * 3600); // ultime 24 ore

    // Blocco 1: ultimi N messaggi del gruppo (tutti gli utenti)
    $stmt = $db->prepare("
        SELECT user_name, message_text, timestamp
        FROM contesto_chat
        WHERE group_id = :group_id
        AND timestamp >= :hours_ago
        ORDER BY timestamp DESC
        LIMIT " . intval($groupLimit)
    );
    $stmt->bindValue(':group_id', $groupId, SQLITE3_INTEGER);
    $stmt->bindValue(':hours_ago', $hours_ago, SQLITE3_INTEGER);
    $result = $stmt->execute();

    $groupMessages = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $groupMessages[] = $row['user_name'] . ": " . $row['message_text'];
    }
    $groupContext = implode("\n", array_reverse($groupMessages));

    // Blocco 2: conversazione specifica user↔bot (solo messaggi di $userName e rootbot)
    $stmt = $db->prepare("
        SELECT user_name, message_text, timestamp
        FROM contesto_chat
        WHERE group_id = :group_id
        AND timestamp >= :hours_ago
        AND (user_name = :user_name OR user_name = 'rootbot')
        ORDER BY timestamp DESC
        LIMIT " . intval($conversationLimit * 2) // *2 perché contiamo sia user che bot
    );
    $stmt->bindValue(':group_id', $groupId, SQLITE3_INTEGER);
    $stmt->bindValue(':hours_ago', $hours_ago, SQLITE3_INTEGER);
    $stmt->bindValue(':user_name', $userName, SQLITE3_TEXT);
    $result = $stmt->execute();

    $conversationMessages = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $conversationMessages[] = $row['user_name'] . ": " . $row['message_text'];
    }
    $conversationContext = implode("\n", array_reverse($conversationMessages));

    return [
        'group' => $groupContext,
        'conversation' => $conversationContext
    ];
}

function getChatContextForHour($groupId, $hoursAgo = 0, $limit = 100) {
    global $db;

    // Calcola inizio e fine dell'ora richiesta
    if ($hoursAgo > 0) {
        $endTime = strtotime("-{$hoursAgo} hours");
        $startTime = strtotime("-" . ($hoursAgo + 1) . " hours");
    } else {
        $endTime = time();
        $startTime = strtotime("-1 hour");
    }

    $stmt = $db->prepare("
        SELECT user_name, message_text, timestamp
        FROM contesto_chat
        WHERE group_id = :group_id
        AND timestamp >= :start_time
        AND timestamp <= :end_time
        ORDER BY timestamp ASC
        LIMIT " . intval($limit)
    );

    $stmt->bindValue(':group_id', $groupId, SQLITE3_INTEGER);
    $stmt->bindValue(':start_time', $startTime, SQLITE3_INTEGER);
    $stmt->bindValue(':end_time', $endTime, SQLITE3_INTEGER);

    $result = $stmt->execute();
    if (!$result) {
        error_log("SQLite Error in getChatContextForHour: " . $db->lastErrorMsg());
        return "";
    }

    $context = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $context[] = $row['user_name'] . ": " . $row['message_text'];
    }

    error_log("getChatContextForHour: Found " . count($context) . " messages for hour -$hoursAgo");

    return implode("\n", $context);
}

function getChatContextForDay($groupId, $daysAgo, $limit = 500) {
    global $db;

    // Calcola inizio e fine del giorno richiesto
    $targetDate = new DateTime("-{$daysAgo} days");
    $startOfDay = (clone $targetDate)->setTime(0, 0, 0)->getTimestamp();
    $endOfDay = (clone $targetDate)->setTime(23, 59, 59)->getTimestamp();

    $stmt = $db->prepare("
        SELECT user_name, message_text, timestamp
        FROM contesto_chat
        WHERE group_id = :group_id
        AND timestamp >= :start_of_day
        AND timestamp <= :end_of_day
        ORDER BY timestamp ASC
        LIMIT " . intval($limit)
    );

    $stmt->bindValue(':group_id', $groupId, SQLITE3_INTEGER);
    $stmt->bindValue(':start_of_day', $startOfDay, SQLITE3_INTEGER);
    $stmt->bindValue(':end_of_day', $endOfDay, SQLITE3_INTEGER);

    $result = $stmt->execute();
    if (!$result) {
        error_log("SQLite Error in getChatContextForDay: " . $db->lastErrorMsg());
        return "";
    }

    $context = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $context[] = $row['user_name'] . ": " . $row['message_text'];
    }

    error_log("getChatContextForDay: Found " . count($context) . " messages for day -$daysAgo");

    return implode("\n", $context);
}

// Funzione di supporto per debug
function dumpChatContext($groupId) {
    global $db;
    
    $stmt = $db->prepare("
        SELECT user_name, message_text, timestamp, 
               datetime(timestamp, 'unixepoch') as formatted_time
        FROM contesto_chat 
        WHERE group_id = :group_id 
        ORDER BY timestamp DESC 
        LIMIT 10
    ");
    
    $stmt->bindValue(':group_id', $groupId, SQLITE3_INTEGER);
    $result = $stmt->execute();
    
    $debug_info = "Latest 10 messages in context:\n";
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $debug_info .= sprintf(
            "[%s] %s: %s\n",
            $row['formatted_time'],
            $row['user_name'],
            substr($row['message_text'], 0, 50)
        );
    }
    
    error_log($debug_info);
}

/**
 * Funzione core per generare risposta AI.
 * Chiamata dal dispatcher con eventuale sezione wiki gia' preparata dall'agente enrichment.
 *
 * @param int    $chatID     Chat ID Telegram
 * @param string $chatType   Tipo chat (private, group, supergroup)
 * @param string $message    Testo del messaggio utente
 * @param string $userName   Nome dell'utente
 * @param string $wikiSection Sezione wiki opzionale (da agente enrichment)
 * @return string Risposta generata
 */
function _ai_core($chatID, $chatType, $message, $userName = 'Utente', $wikiSection = '') {
    $model = OLLAMA_MODEL;

    // Mostra "sta scrivendo..." mentre l'LLM elabora
    makeAPIRequest('sendChatAction', [
        'chat_id' => $chatID,
        'action' => 'typing'
    ]);

    // Recupera contesto misto: gruppo + conversazione specifica
    $contexts = getChatContextMixed($chatID, $userName, 5, 5);
    $groupContext = $contexts['group'];
    $conversationContext = $contexts['conversation'];

    $formatter = new IntlDateFormatter('it_IT', IntlDateFormatter::FULL, IntlDateFormatter::NONE);
    $oggi = ucfirst($formatter->format(new DateTime()));
    $orario = date('H:i');

    $persona = rootbotPersona();
    $instructions = <<<INSTR
{$persona}
- Sei diretto e vai al punto, ma quando serve approfondisci senza problemi

Info pratiche che conosci:
- Oggi è {$oggi}, ore {$orario}
- Il circolo /root è in Via Santa Croce 6669, San Pietro in Guardiano (tra Forlì, Ravenna e Cesena)
- Sito: www.rootclub.it
- Aperto Martedì e Venerdì sera dalle 20 fin quando ce n'è
- Sede di FoLug (Linux User Group di Forlì) e Precious Plastic Romagna
- Frequentato da nerd, maker, smanettoni di tecnologia, elettronica, robotica, fantascienza
INSTR;
    $message = str_replace('@rootbotbot', '', $message);
    $message = str_replace('rootbotbot', '', $message);
    $message = str_replace('@rootbot', '', $message);
    $message = str_replace('@bot', '', $message);
    $message = str_replace('@root', '', $message);
    $message = trim($message);

    // Costruisci sezione conversazione solo se ci sono scambi precedenti
    $conversationSection = '';
    if (!empty($conversationContext)) {
        $conversationSection = <<<CONV

### CONVERSAZIONE CON {$userName} ###
{$conversationContext}
CONV;
    }

    $prompt = <<<PROMPT
### ISTRUZIONI ###
{$instructions}

### CONTESTO GRUPPO (ultimi messaggi) ###
{$groupContext}
{$conversationSection}{$wikiSection}

### MESSAGGIO DI {$userName} A CUI DEVI RISPONDERE ###
{$message}

Rispondi a {$userName}. Il contesto gruppo serve per capire di cosa si parla, la conversazione mostra i tuoi scambi precedenti con questo utente.
IMPORTANTE: Scrivi SOLO la tua risposta, senza prefissi come "rootbot:" o simili.
PROMPT;

    // Log strutturato
    $logEntry = "\n" . str_repeat('=', 60) . "\n";
    $logEntry .= "[" . date('Y-m-d H:i:s') . "] Utente: {$userName}\n";
    $logEntry .= str_repeat('-', 60) . "\n";
    $logEntry .= $prompt . "\n";
    file_put_contents(logPath('ai'), $logEntry, FILE_APPEND);

    // Chiama Ollama via QBert con typing refresh (/api/chat + think=false: Gemma 4 pattern)
    $result = callOllamaChatViaQBertWithTyping(
        $model,
        $prompt,
        $chatID,
        ollamaOptions(OLLAMA_MODEL_GPU),
        false,
        QBertClient::PRIORITY_NORMAL
    );

    if (!$result) {
        return "Si è verificato un errore durante la comunicazione con l'AI.";
    }

    $response = $result['response'] ?? '';

    // Rimuovi tag di thinking
    $response = stripThinkingTags($response);
    $response = trim($response);

    // Tronca la risposta se supera il limite di caratteri di Telegram
    if (mb_strlen($response) > 4096) {
        $response = mb_substr($response, 0, 4093) . '...';
    }

    // Log risposta
    $logEntry = str_repeat('-', 60) . "\n";
    $logEntry .= "RISPOSTA:\n{$response}\n";
    $logEntry .= str_repeat('=', 60) . "\n";
    file_put_contents(logPath('ai'), $logEntry, FILE_APPEND);

    return $response;
}

/**
 * Wrapper di compatibilita': chiama _ai_core senza enrichment.
 * Usato da contesti che non passano dal dispatcher (es. immagini).
 */
function _ai($chatID, $chatType, $message, $userName = 'Utente') {
    return _ai_core($chatID, $chatType, $message, $userName);
}

/**
 * Helper function per chiamate Ollama con diagnostica completa (via QBert)
 * @param int $timeout Timeout in secondi (non più usato direttamente, gestito da QBert)
 */
function _callOllamaWithDiagnostics($prompt, $logFile, $label = 'call', $timeout = 120) {
    $model = OLLAMA_MODEL;

    file_put_contents($logFile, "--- Ollama call via QBert: $label ---\n", FILE_APPEND);

    $startTime = time();
    $result = callOllamaChatViaQBert(
        $model,
        $prompt,
        ollamaOptions(OLLAMA_MODEL_GPU),
        false,
        QBertClient::PRIORITY_LAZY
    );
    $elapsed = time() - $startTime;

    if (!$result) {
        file_put_contents($logFile, "[$label] QBert call FAILED, time: {$elapsed}s\n", FILE_APPEND);
        return null;
    }

    $response = $result['response'] ?? '';

    // Rimuovi tag di thinking
    $response = stripThinkingTags($response);
    $response = trim($response);

    file_put_contents($logFile, "[$label] QBert OK, time: {$elapsed}s, response length: " . strlen($response) . "\n", FILE_APPEND);

    return $response;
}

/**
 * Riassume un blocco di messaggi in modo ultra-conciso
 * @param array $blockLinks Info sui link presenti nel blocco (URL -> riassunto)
 */
function _summarizeBlock($blockContext, $blockNum, $totalBlocks, $blockLinks, $logFile) {
    $linksInfo = '';
    if (!empty($blockLinks)) {
        $linkLines = [];
        foreach ($blockLinks as $url => $summary) {
            $linkLines[] = "- $summary";
        }
        $linksInfo = "\n\nLINK CONDIVISI IN QUESTO BLOCCO:\n" . implode("\n", $linkLines);
    }

    $prompt = <<<PROMPT
Estrai gli argomenti principali da questa chat. Output ULTRA-BREVE: massimo 3-4 punti, una riga ciascuno.
Se ci sono link condivisi, includi brevemente di cosa parlano.

CONVERSAZIONE:
{$blockContext}{$linksInfo}

ARGOMENTI (max 4 righe telegrafiche):
PROMPT;

    return _callOllamaWithDiagnostics($prompt, $logFile, "block_{$blockNum}");
}

/**
 * Genera il saluto finale dai riassunti (che già contengono info sui link)
 */
function _generateFinalSaluto($summaries, $oggi, $logFile) {
    $allSummaries = implode("\n", $summaries);

    $persona = rootbotPersona();
    $prompt = <<<PROMPT
{$persona}
È sera e osservi quello che gli umani hanno detto oggi.

Oggi è {$oggi}.

ARGOMENTI DELLA GIORNATA:
{$allSummaries}

OUTPUT:
Scrivi un messaggio di buonanotte di 8-12 frasi. Commenta gli argomenti con tono sarcastico e divertito. Niente elenchi. Concludi con un saluto.
PROMPT;

    return _callOllamaWithDiagnostics($prompt, $logFile, "final_saluto");
}

function _saluto($chatID, $daysAgo = 0) {
    $logFile = logPath('saluto');

    // Log di inizio
    file_put_contents($logFile, "\n=== SALUTO START " . date('Y-m-d H:i:s') . " ===\n", FILE_APPEND);
    file_put_contents($logFile, "chatID: $chatID, daysAgo: $daysAgo\n", FILE_APPEND);

    // Ottieni il contesto
    if ($daysAgo > 0) {
        $context = getChatContextForDay($chatID, $daysAgo, 500);
        $targetDate = new DateTime("-{$daysAgo} days");
    } else {
        $context = getChatContext($chatID, 24, 500);
        $targetDate = new DateTime();
    }

    if (empty(trim($context))) {
        $dayLabel = $daysAgo > 0 ? "$daysAgo giorni fa" : "nelle ultime 24 ore";
        file_put_contents($logFile, "EXIT: contesto vuoto\n", FILE_APPEND);
        return "Nessun messaggio trovato $dayLabel.";
    }

    // Dividi in messaggi
    $messages = array_filter(explode("\n", $context), 'strlen');
    $totalMessages = count($messages);
    file_put_contents($logFile, "Total messages: $totalMessages\n", FILE_APPEND);

    $formatter = new IntlDateFormatter('it_IT', IntlDateFormatter::FULL, IntlDateFormatter::NONE);
    $oggi = ucfirst($formatter->format($targetDate));

    // Pre-analizza tutti i link (una chiamata LLM per link)
    file_put_contents($logFile, "Pre-analyzing links...\n", FILE_APPEND);
    $linkMap = preAnalyzeLinks($context, $logFile);

    // Se pochi messaggi (<= 40), chiamata diretta senza map-reduce
    if ($totalMessages <= 40) {
        file_put_contents($logFile, "Few messages ($totalMessages <= 40), using direct call\n", FILE_APPEND);

        // Prepara info link per il prompt
        $linksInfo = '';
        if (!empty($linkMap)) {
            $linkLines = [];
            foreach ($linkMap as $url => $summary) {
                $linkLines[] = "- $summary";
            }
            $linksInfo = "\n\nLINK CONDIVISI:\n" . implode("\n", $linkLines);
        }

        $persona = rootbotPersona();
        $prompt = <<<PROMPT
{$persona}
È sera e osservi quello che gli umani hanno detto oggi.

Oggi è {$oggi}.

CONVERSAZIONE:
{$context}{$linksInfo}

OUTPUT:
Scrivi un messaggio di buonanotte di 8-12 frasi. Commenta gli argomenti con tono sarcastico e divertito. Niente elenchi. Concludi con un saluto.
PROMPT;

        $response = _callOllamaWithDiagnostics($prompt, $logFile, "direct");
        file_put_contents($logFile, "=== SALUTO END ===\n", FILE_APPEND);
        return $response ?: "";
    }

    // Map-reduce: calcola blocchi
    // Formula: numBlocks = ceil(total/40), blockSize = ceil(total/numBlocks)
    $numBlocks = (int)ceil($totalMessages / 40);
    $blockSize = (int)ceil($totalMessages / $numBlocks);
    file_put_contents($logFile, "Map-reduce: $totalMessages msgs -> $numBlocks blocks of ~$blockSize msgs\n", FILE_APPEND);

    // Fase 1: riassumi ogni blocco
    $summaries = [];
    for ($i = 0; $i < $numBlocks; $i++) {
        $start = $i * $blockSize;
        $blockMessages = array_slice($messages, $start, $blockSize);
        $blockContext = implode("\n", $blockMessages);

        // Trova i link presenti in questo blocco
        $blockLinks = [];
        foreach ($linkMap as $url => $summary) {
            if (strpos($blockContext, $url) !== false) {
                $blockLinks[$url] = $summary;
            }
        }

        $blockNum = $i + 1;
        $linkCount = count($blockLinks);
        file_put_contents($logFile, "Processing block $blockNum/$numBlocks (" . count($blockMessages) . " msgs, $linkCount links)\n", FILE_APPEND);

        $summary = _summarizeBlock($blockContext, $blockNum, $numBlocks, $blockLinks, $logFile);
        if (!empty($summary)) {
            $summaries[] = $summary;
            file_put_contents($logFile, "Block $blockNum summary OK\n", FILE_APPEND);
        } else {
            file_put_contents($logFile, "Block $blockNum summary FAILED\n", FILE_APPEND);
        }
    }

    if (empty($summaries)) {
        file_put_contents($logFile, "ERROR: all block summaries failed\n", FILE_APPEND);
        return "";
    }

    file_put_contents($logFile, "All blocks done, " . count($summaries) . "/$numBlocks successful\n", FILE_APPEND);

    // Fase 2: genera saluto finale
    file_put_contents($logFile, "Generating final saluto...\n", FILE_APPEND);
    $response = _generateFinalSaluto($summaries, $oggi, $logFile);

    file_put_contents($logFile, "=== SALUTO END ===\n", FILE_APPEND);
    return $response ?: "";
}

function _dj($chatID, $hoursAgo = 0) {
    $model = OLLAMA_MODEL;

    // Log dettagliato per debug
    $djLog = logPath('dj_debug');
    $timestamp = date('Y-m-d H:i:s');
    $currentHour = (int)date('G');
    $ora = date('H:i');
    file_put_contents($djLog, "\n=== DJ DEBUG [$timestamp] hoursAgo=$hoursAgo ===\n", FILE_APPEND);

    // Prendi i messaggi dell'ora specificata
    $context = getChatContextForHour($chatID, $hoursAgo, 100);
    $contextLines = empty(trim($context)) ? [] : explode("\n", $context);
    $messageCount = count($contextLines);
    file_put_contents($djLog, "Messaggi trovati: $messageCount\n", FILE_APPEND);

    // Controlla se possiamo usare HN (solo ore 7-23)
    $canUseHN = ($currentHour >= 7 && $currentHour <= 23);
    file_put_contents($djLog, "Ora corrente: $currentHour, può usare HN: " . ($canUseHN ? "sì" : "no") . "\n", FILE_APPEND);

    // Decidi la fonte: chat o HN
    $useHN = false;
    $hnStory = null;

    if ($messageCount < 3 && $canUseHN) {
        // Fallback su HN se pochi messaggi
        $useHN = true;
        file_put_contents($djLog, "Pochi messaggi, fallback su HN\n", FILE_APPEND);
    } elseif ($messageCount >= 3 && $canUseHN && rand(1, 100) <= 25) {
        // 25% di probabilità di usare HN anche con chat attiva (varietà)
        $useHN = true;
        file_put_contents($djLog, "Dado favorevole per HN (varietà)\n", FILE_APPEND);
    }

    $hnUrl = ''; // URL della news per appendere al messaggio
    $hnDescription = ''; // Descrizione/sommario della news
    $hnStoryId = null; // ID per marcare come postata
    if ($useHN) {
        $stories = fetchHackerNewsTopStories(30);
        $hnStory = pickBestHNStory($stories);
        if ($hnStory) {
            $hnStoryId = $hnStory['id'];
            $hnUrl = $hnStory['url'] ?: '';

            // Fetch contenuto articolo per dare contesto al DJ
            if (!empty($hnUrl)) {
                $articleContent = fetchUrlContent($hnUrl);
                if ($articleContent && !empty($articleContent['description'])) {
                    $hnDescription = $articleContent['description'];
                    file_put_contents($djLog, "HN descrizione: " . substr($hnDescription, 0, 200) . "...\n", FILE_APPEND);
                }
            }
        } else {
            file_put_contents($djLog, "HN: nessuna storia disponibile\n", FILE_APPEND);
            $useHN = false;
        }
    }

    // Se non usiamo HN e non ci sono messaggi, niente da fare
    if (!$useHN && $messageCount < 1) {
        file_put_contents($djLog, "Nessun contenuto disponibile\n", FILE_APPEND);
        return "Nessun messaggio trovato. 🎵 La radio va avanti con la musica...";
    }

    // Log del contesto chat se usato
    if (!$useHN && $messageCount > 0) {
        file_put_contents($djLog, "--- CONTESTO CHAT ---\n$context\n--- FINE CONTESTO ---\n", FILE_APPEND);
    }

    $formatter = new IntlDateFormatter('it_IT', IntlDateFormatter::FULL, IntlDateFormatter::NONE);
    $oggi = ucfirst($formatter->format(new DateTime()));

    // Carica gli ultimi incipit usati per evitare ripetizioni (da database)
    $usedIncipits = getBotState('dj_incipits', []);
    $incipitWarning = '';
    if (!empty($usedIncipits)) {
        $incipitWarning = "\n\nATTENZIONE: NON iniziare con queste parole/frasi già usate di recente:\n- " . implode("\n- ", $usedIncipits);
    }
    file_put_contents($djLog, "Incipit da evitare: " . json_encode($usedIncipits) . "\n", FILE_APPEND);

    // Costruisci il prompt in base alla fonte
    if ($useHN && $hnStory) {
        // Prompt per notizia HN
        $newsTitle = $hnStory['title'];
        $newsContext = $newsTitle;
        if (!empty($hnDescription)) {
            $newsContext .= "\n\nSommario: " . $hnDescription;
        }

        $persona = rootbotPersona();
        $prompt = <<<PROMPT
### ISTRUZIONI ###
{$persona}
Stai osservando il flusso di informazioni che passa nel gruppo e ogni tanto decidi di commentare, offrendo il tuo punto di vista non umano.

Hai intercettato questa notizia tech:
"{$newsContext}"

Commenta offrendo un punto di vista alternativo, "da macchina", che faccia riflettere. Cosa nota un'AI che un umano potrebbe non vedere? Quali contraddizioni, ironie o pattern emergono?
{$incipitWarning}

Oggi è {$oggi}, ore {$ora}.

### OUTPUT ###
Un breve commento (3-4 frasi max). Niente emoji. Prospettiva non umana ma accessibile. Fai riflettere.
PROMPT;
    } else {
        // Prompt per chat del gruppo
        // Analizza i link condivisi (solo come contesto di sfondo)
        $urlsFound = extractUrlsWithReactions($context);
        $linksContext = '';
        if (!empty($urlsFound)) {
            $linkTitles = [];
            foreach ($urlsFound as $urlData) {
                $content = fetchUrlContent($urlData['url']);
                if ($content && !empty($content['title'])) {
                    $linkTitles[] = $content['title'];
                }
            }
            if (!empty($linkTitles)) {
                $linksContext = "\n(Nota: sono stati condivisi link su: " . implode(", ", $linkTitles) . " - usali solo come contesto se pertinenti, non come argomento principale)";
            }
        }

        $persona = rootbotPersona();
        $prompt = <<<PROMPT
### ISTRUZIONI ###
{$persona}
Stai osservando le conversazioni degli umani nel gruppo e ogni tanto decidi di intervenire, offrendo il tuo punto di vista non umano.

IMPORTANTE:
- Scegli UN SOLO argomento dalla conversazione, quello più interessante o che si presta a una riflessione "non umana"
- Offri un punto di vista alternativo, "da macchina": cosa nota un'AI che un umano potrebbe non vedere?
- Quali contraddizioni, ironie, pattern o assurdità emergono?
- Fai sembrare che siano riflessioni tue spontanee
- NON menzionare chat, gruppi, messaggi, "qualcuno ha detto"
{$incipitWarning}

Oggi è {$oggi}, ore {$ora}.

### CONVERSAZIONE ###
{$context}{$linksContext}

### OUTPUT ###
Un breve commento (3-4 frasi max). Niente emoji. Prospettiva non umana ma accessibile. Fai riflettere.
PROMPT;
    }

    file_put_contents(logPath('ai'), "=== DJ REQUEST ===\n" . print_r($prompt, true) . "\n\n", FILE_APPEND);

    // Chiama Ollama via QBert (DJ è un job in background, priorità lazy) - /api/chat + think=false
    $result = callOllamaChatViaQBert(
        $model,
        $prompt,
        ollamaOptions(OLLAMA_MODEL_GPU),
        false,
        QBertClient::PRIORITY_LAZY
    );

    if (!$result) {
        return "Errore AI: QBert call failed";
    }

    $response = $result['response'] ?? '';

    // Rimuovi tag di thinking
    $response = stripThinkingTags($response);
    $response = trim($response);

    // Salva l'incipit per evitare ripetizioni future (in database)
    if (!empty($response)) {
        // Estrai le prime 3-4 parole come incipit
        $words = preg_split('/\s+/', $response);
        $incipit = implode(' ', array_slice($words, 0, 3));

        // Aggiungi nuovo e mantieni solo gli ultimi 3
        $usedIncipits = getBotState('dj_incipits', []);
        $usedIncipits[] = $incipit;
        $usedIncipits = array_slice($usedIncipits, -3);
        setBotState('dj_incipits', $usedIncipits);

        error_log("[dj] Nuovo incipit salvato: $incipit");
    }

    // Se è una news HN, appendi il link e marca come postata
    if (!empty($hnUrl)) {
        $response .= "\n\n🔗 " . $hnUrl;
    }
    if ($hnStoryId !== null && $hnStory) {
        markHNStoryPosted($hnStoryId, $hnStory['title']);
        file_put_contents($djLog, "HN: story {$hnStoryId} marcata come postata\n", FILE_APPEND);
    }

    file_put_contents(logPath('ai'), "=== DJ RESPONSE ===\n" . $response . "\n\n", FILE_APPEND);

    return $response;
}

function fetchHackerNewsTopStories($limit = 30) {
    $djLog = logPath('dj_debug');

    // Fetch best stories IDs (qualità più alta rispetto a topstories)
    $ch = curl_init('https://hacker-news.firebaseio.com/v0/beststories.json');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $response = curl_exec($ch);
    curl_close($ch);

    if (empty($response)) {
        file_put_contents($djLog, "HN: fetch failed\n", FILE_APPEND);
        return [];
    }

    $storyIds = json_decode($response, true);
    if (!is_array($storyIds)) {
        return [];
    }

    // Prendi solo i primi N
    $storyIds = array_slice($storyIds, 0, $limit);

    $stories = [];
    foreach ($storyIds as $id) {
        $ch = curl_init("https://hacker-news.firebaseio.com/v0/item/{$id}.json");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        $storyData = curl_exec($ch);
        curl_close($ch);

        $story = json_decode($storyData, true);
        if ($story && isset($story['title'])) {
            $stories[] = [
                'id' => $id,
                'title' => $story['title'],
                'url' => $story['url'] ?? '',
                'score' => $story['score'] ?? 0,
                'comments' => $story['descendants'] ?? 0,
                'by' => $story['by'] ?? ''
            ];
        }
    }

    file_put_contents($djLog, "HN: fetched " . count($stories) . " stories\n", FILE_APPEND);
    return $stories;
}

function isHNStoryPosted($storyId) {
    global $db;
    $stmt = $db->prepare("SELECT 1 FROM hn_posted WHERE story_id = :id");
    $stmt->bindValue(':id', $storyId, SQLITE3_INTEGER);
    $result = $stmt->execute();
    return $result->fetchArray() !== false;
}

function markHNStoryPosted($storyId, $title) {
    global $db;
    $stmt = $db->prepare("INSERT OR REPLACE INTO hn_posted (story_id, title, posted_at) VALUES (:id, :title, :time)");
    $stmt->bindValue(':id', $storyId, SQLITE3_INTEGER);
    $stmt->bindValue(':title', $title, SQLITE3_TEXT);
    $stmt->bindValue(':time', time(), SQLITE3_INTEGER);
    $stmt->execute();
}

function getBotState($key, $default = null) {
    global $db;
    $stmt = $db->prepare("SELECT value FROM bot_state WHERE key = :key");
    $stmt->bindValue(':key', $key, SQLITE3_TEXT);
    $result = $stmt->execute();
    $row = $result->fetchArray(SQLITE3_ASSOC);
    if ($row) {
        return json_decode($row['value'], true) ?? $row['value'];
    }
    return $default;
}

function setBotState($key, $value) {
    global $db;
    $jsonValue = is_array($value) ? json_encode($value) : $value;
    $stmt = $db->prepare("INSERT OR REPLACE INTO bot_state (key, value, updated_at) VALUES (:key, :value, :time)");
    $stmt->bindValue(':key', $key, SQLITE3_TEXT);
    $stmt->bindValue(':value', $jsonValue, SQLITE3_TEXT);
    $stmt->bindValue(':time', time(), SQLITE3_INTEGER);
    $stmt->execute();
}

function pickBestHNStory($stories) {
    $djLog = logPath('dj_debug');

    if (empty($stories)) return null;

    // Filtra le news già postate
    $available = array_filter($stories, function($story) {
        return !isHNStoryPosted($story['id']);
    });

    file_put_contents($djLog, "HN: " . count($available) . "/" . count($stories) . " stories disponibili (non ancora postate)\n", FILE_APPEND);

    if (empty($available)) {
        file_put_contents($djLog, "HN: tutte le top stories sono già state postate!\n", FILE_APPEND);
        return null;
    }

    // Ordina per score + commenti (peso uguale)
    usort($available, function($a, $b) {
        $scoreA = $a['score'] + $a['comments'];
        $scoreB = $b['score'] + $b['comments'];
        return $scoreB - $scoreA;
    });

    // Prendi la migliore
    $best = reset($available);
    file_put_contents($djLog, "HN: selezionata '{$best['title']}' (score: {$best['score']}, comments: {$best['comments']})\n", FILE_APPEND);

    return $best;
}

function extractUrlsWithReactions($context) {
    // Divide il contesto in righe (messaggi)
    $lines = explode("\n", $context);
    $urls = [];

    // Pattern per trovare URL
    $urlPattern = '/(https?:\/\/[^\s<>"\')]+)/i';

    foreach ($lines as $index => $line) {
        if (preg_match_all($urlPattern, $line, $matches)) {
            foreach ($matches[1] as $url) {
                // Pulisci URL da punteggiatura finale
                $url = rtrim($url, '.,;:!?)');

                if (!isset($urls[$url])) {
                    // Score = numero di messaggi dopo questo (approssima le reazioni)
                    $messagesAfter = count($lines) - $index - 1;
                    $urls[$url] = [
                        'url' => $url,
                        'score' => $messagesAfter,
                        'context_line' => $line
                    ];
                }
            }
        }
    }

    // Ordina per score (più reazioni = prima)
    usort($urls, function($a, $b) {
        return $b['score'] - $a['score'];
    });

    // Limita a 5
    return array_slice($urls, 0, 5);
}

function fetchUrlContent($url) {
    $djLog = logPath('dj_debug');

    // YouTube (video normali e Shorts) - usa noembed che funziona meglio
    if (preg_match('/(?:youtube\.com\/(?:watch\?v=|shorts\/)|youtu\.be\/)([a-zA-Z0-9_-]+)/', $url, $ytMatch)) {
        $videoId = $ytMatch[1];
        file_put_contents($djLog, "    YouTube detected, videoId: $videoId\n", FILE_APPEND);

        // Prova noembed (più affidabile per shorts)
        $noembedUrl = "https://noembed.com/embed?url=https://www.youtube.com/watch?v={$videoId}";
        $ch = curl_init($noembedUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        $response = curl_exec($ch);
        curl_close($ch);

        $data = json_decode($response, true);
        if ($data && !isset($data['error']) && !empty($data['title'])) {
            $title = $data['title'];
            $author = $data['author_name'] ?? '';
            file_put_contents($djLog, "    noembed OK: $title (by $author)\n", FILE_APPEND);
            return [
                'title' => $title,
                'description' => !empty($author) ? "Video di $author" : "Video YouTube"
            ];
        }
        file_put_contents($djLog, "    noembed failed, response: " . substr($response, 0, 100) . "\n", FILE_APPEND);
    }

    // Instagram - quasi impossibile senza login, skip
    if (preg_match('/instagram\.com|kkinstagram\.com/', $url)) {
        file_put_contents($djLog, "    Instagram/mirror: skipped (richiede login)\n", FILE_APPEND);
        return null;
    }

    // Altri siti - usa Microlink
    $microlinkUrl = 'https://api.microlink.io?url=' . urlencode($url);

    $ch = curl_init($microlinkUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_USERAGENT, 'RootBot/1.0');

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    file_put_contents($djLog, "    Microlink fetch: HTTP $httpCode\n", FILE_APPEND);

    if ($httpCode !== 200 || empty($response)) {
        file_put_contents($djLog, "    Microlink FAILED\n", FILE_APPEND);
        return null;
    }

    $data = json_decode($response, true);

    if (!$data || $data['status'] !== 'success' || !isset($data['data'])) {
        file_put_contents($djLog, "    Microlink response invalid\n", FILE_APPEND);
        return null;
    }

    $info = $data['data'];
    $title = $info['title'] ?? '';
    $description = $info['description'] ?? '';
    $author = $info['author'] ?? '';
    $publisher = $info['publisher'] ?? '';

    // Salta se titolo troppo generico
    if (empty($title) || strlen($title) < 5 || $title === '- YouTube' || $title === 'Open in App') {
        file_put_contents($djLog, "    Titolo troppo generico, skip: $title\n", FILE_APPEND);
        return null;
    }

    // Arricchisci la descrizione
    if (!empty($author)) {
        $description = "Di $author. " . $description;
    } elseif (!empty($publisher)) {
        $description = "Da $publisher. " . $description;
    }

    file_put_contents($djLog, "    Microlink OK: $title\n", FILE_APPEND);
    file_put_contents($djLog, "      Desc: " . substr($description, 0, 100) . "\n", FILE_APPEND);

    return [
        'title' => $title,
        'description' => $description
    ];
}

function summarizeUrl($url, $title, $description) {
    $prompt = "Riassumi in 1-2 frasi brevi di cosa parla questa pagina web.\nTitolo: {$title}\nDescrizione: {$description}\nURL: {$url}\n\nRiassunto:";

    $result = callOllamaChatViaQBert(
        OLLAMA_MODEL_LIGHT,
        $prompt,
        ollamaOptions(OLLAMA_MODEL_LIGHT_GPU),
        false,
        QBertClient::PRIORITY_NORMAL
    );

    if (!$result) {
        return '';
    }

    $response = $result['response'] ?? '';

    // Rimuovi tag di thinking
    $response = stripThinkingTags($response);

    return trim($response);
}

function getLinksAnalysis($context) {
    $urls = extractUrlsWithReactions($context);

    if (empty($urls)) {
        return '';
    }

    $analysis = [];

    foreach ($urls as $urlData) {
        $content = fetchUrlContent($urlData['url']);

        if ($content && (!empty($content['title']) || !empty($content['description']))) {
            $summary = summarizeUrl($urlData['url'], $content['title'], $content['description']);
            if (!empty(trim($summary))) {
                $analysis[] = "- {$urlData['url']}: {$summary}";
            }
        }
    }

    if (empty($analysis)) {
        return '';
    }

    return "Link condivisi oggi:\n" . implode("\n", $analysis);
}

/**
 * Pre-analizza tutti i link e restituisce una mappa URL -> riassunto
 * Ogni link viene analizzato separatamente per evitare timeout
 */
function preAnalyzeLinks($context, $logFile) {
    $urls = extractUrlsWithReactions($context);

    if (empty($urls)) {
        file_put_contents($logFile, "No links found in context\n", FILE_APPEND);
        return [];
    }

    file_put_contents($logFile, "Found " . count($urls) . " links to analyze\n", FILE_APPEND);

    $linkMap = [];

    foreach ($urls as $urlData) {
        $url = $urlData['url'];
        file_put_contents($logFile, "Fetching: $url\n", FILE_APPEND);

        $content = fetchUrlContent($url);

        if ($content && (!empty($content['title']) || !empty($content['description']))) {
            file_put_contents($logFile, "Summarizing: {$content['title']}\n", FILE_APPEND);
            $summary = summarizeUrl($url, $content['title'], $content['description']);

            if (!empty(trim($summary))) {
                $linkMap[$url] = trim($summary);
                file_put_contents($logFile, "Link summary OK: " . strlen($summary) . " chars\n", FILE_APPEND);
            }
        } else {
            file_put_contents($logFile, "No content for: $url\n", FILE_APPEND);
        }
    }

    file_put_contents($logFile, "Links analyzed: " . count($linkMap) . "/" . count($urls) . "\n", FILE_APPEND);
    return $linkMap;
}

// ============================================================
// ======================== TTS ================================
// ============================================================

/**
 * Genera TTS con refresh dell'indicatore "upload_voice" durante l'attesa
 * @param string $text Testo da sintetizzare
 * @param int $chatId Chat ID per typing indicator
 * @return string|null Dati WAV binari o null
 */
function generateTTSWithTyping($text, $chatId) {
    $qbert = getQBertClient();

    $formData = [
        'profile' => TTS_VOICE_PROFILE,
        'text' => $text,
        'language' => TTS_LANGUAGE,
        'format' => 'opus',
    ];

    // Invia action iniziale
    makeAPIRequest('sendChatAction', ['chat_id' => $chatId, 'action' => 'upload_voice']);
    $lastTypingTime = time();

    $logFile = logPath('debug');

    // Submit non-bloccante via QBertClient (con multipart e header corretti)
    $result = $qbert->submit('POST', 'qwen-tts', '/voice-clone', multipart: $formData, note: 'tts_typing');

    // Risposta diretta (non accodata)
    if (!$result['is_ticket']) {
        file_put_contents($logFile, "[TTS] Direct response ({$result['status_code']}), body_len=" . strlen($result['body'] ?: '') . ", body=" . substr($result['body'] ?: '', 0, 500) . "\n", FILE_APPEND);
        if ($result['status_code'] === 200 && !empty($result['body'])) {
            return $result['body'];
        }
        error_log("[TTS] Unexpected status: " . $result['status_code']);
        return null;
    }

    // Ticket: polling manuale con typing refresh
    $ticketId = $result['ticket_id'];
    $start = microtime(true);
    $maxWait = 600.0;
    $pollInterval = 2.0;

    while (true) {
        if ((microtime(true) - $start) > $maxWait) {
            return null;
        }

        // Rinnova typing ogni 4 secondi
        if ((time() - $lastTypingTime) >= 4) {
            makeAPIRequest('sendChatAction', ['chat_id' => $chatId, 'action' => 'upload_voice']);
            $lastTypingTime = time();
        }

        $ticket = $qbert->poll($ticketId);

        if (!$ticket['found']) {
            return null;
        }

        if ($ticket['done']) {
            $ticketBody = $ticket['body_bytes'] ?? $ticket['body'] ?? null;
            file_put_contents($logFile, "[TTS] Ticket done, body_len=" . strlen($ticketBody ?: '') . "\n", FILE_APPEND);
            return $ticketBody;
        }

        if ($ticket['failed']) {
            error_log("[TTS] Ticket failed: " . ($ticket['error'] ?? 'unknown'));
            return null;
        }

        usleep((int)($pollInterval * 1000000));
    }
}

/**
 * Cerca voce TTS in cache
 * @return string|null file_id Telegram o null
 */
function getCachedTTS($chatId, $messageId) {
    global $db;
    $stmt = $db->prepare("SELECT voice_file_id FROM tts_cache WHERE chat_id = :chat_id AND message_id = :message_id");
    $stmt->bindValue(':chat_id', $chatId, SQLITE3_INTEGER);
    $stmt->bindValue(':message_id', $messageId, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $row = $result->fetchArray(SQLITE3_ASSOC);
    return $row ? $row['voice_file_id'] : null;
}

/**
 * Salva file_id voce TTS in cache
 */
function cacheTTS($chatId, $messageId, $fileId) {
    global $db;
    $stmt = $db->prepare("INSERT OR REPLACE INTO tts_cache (chat_id, message_id, voice_file_id, created_at) VALUES (:chat_id, :message_id, :file_id, :now)");
    $stmt->bindValue(':chat_id', $chatId, SQLITE3_INTEGER);
    $stmt->bindValue(':message_id', $messageId, SQLITE3_INTEGER);
    $stmt->bindValue(':file_id', $fileId, SQLITE3_TEXT);
    $stmt->bindValue(':now', time(), SQLITE3_INTEGER);
    $stmt->execute();
}

/**
 * Gestisce il click sul pulsante "Ascolta" (callback TTS)
 */
function handleTTSCallback($callbackQuery) {
    $callbackId = $callbackQuery['id'];
    $message = $callbackQuery['message'];
    $chatId = $message['chat']['id'];
    $messageId = $message['message_id'];
    $text = $message['text'] ?? '';

    if (!TTS_ENABLED) {
        makeAPIRequest('answerCallbackQuery', [
            'callback_query_id' => $callbackId,
            'text' => 'TTS non disponibile al momento.',
            'show_alert' => false
        ]);
        return;
    }

    if (empty($text)) {
        makeAPIRequest('answerCallbackQuery', [
            'callback_query_id' => $callbackId,
            'text' => 'Nessun testo da sintetizzare.',
            'show_alert' => false
        ]);
        return;
    }

    // Cache hit: audio già generato, non re-inviare
    $cachedFileId = getCachedTTS($chatId, $messageId);
    if ($cachedFileId) {
        makeAPIRequest('answerCallbackQuery', [
            'callback_query_id' => $callbackId,
            'text' => 'Audio già inviato.',
            'show_alert' => false
        ]);
        return;
    }

    // Cache miss: genera audio
    makeAPIRequest('answerCallbackQuery', [
        'callback_query_id' => $callbackId,
        'text' => 'Generazione audio in corso...',
        'show_alert' => false
    ]);

    // Sostituisci il pulsante con "Generazione in corso..." per feedback visivo e anti-doppio-click
    makeAPIRequest('editMessageReplyMarkup', [
        'chat_id' => $chatId,
        'message_id' => $messageId,
        'reply_markup' => json_encode(['inline_keyboard' => [
            [['text' => "\u{23F3} Generazione in corso...", 'callback_data' => 'tts_generating']]
        ]])
    ]);

    // Genera TTS con typing indicator
    $logFile = logPath('debug');
    file_put_contents($logFile, "[TTS] Generating for msg $messageId, text length=" . strlen($text) . "\n", FILE_APPEND);

    $wavData = generateTTSWithTyping($text, $chatId);

    if (!$wavData) {
        file_put_contents($logFile, "[TTS] generateTTSWithTyping returned null\n", FILE_APPEND);
        makeAPIRequest('sendMessage', [
            'chat_id' => $chatId,
            'text' => 'Errore nella generazione audio.',
            'reply_to_message_id' => $messageId
        ]);
        return;
    }

    $audioData = $wavData;
    file_put_contents($logFile, "[TTS] Got data: " . strlen($audioData) . " bytes, header=" . bin2hex(substr($audioData, 0, 4)) . "\n", FILE_APPEND);

    // Verifica che sia OGG (header "OggS") o WAV (header "RIFF")
    $header = substr($audioData, 0, 4);
    $isOgg = ($header === 'OggS');
    $isWav = ($header === 'RIFF');

    if (!$isOgg && !$isWav) {
        // Potrebbe essere base64
        $decoded = base64_decode($audioData, true);
        if ($decoded !== false) {
            $decodedHeader = substr($decoded, 0, 4);
            if ($decodedHeader === 'OggS' || $decodedHeader === 'RIFF') {
                file_put_contents($logFile, "[TTS] Decoded base64 -> " . strlen($decoded) . " bytes\n", FILE_APPEND);
                $audioData = $decoded;
                $isOgg = ($decodedHeader === 'OggS');
                $isWav = ($decodedHeader === 'RIFF');
            }
        }
    }

    if (!$isOgg && !$isWav) {
        file_put_contents($logFile, "[TTS] Data is not OGG or WAV, first 100 bytes: " . substr($audioData, 0, 100) . "\n", FILE_APPEND);
        makeAPIRequest('sendMessage', [
            'chat_id' => $chatId,
            'text' => 'Errore: risposta audio non valida.',
            'reply_to_message_id' => $messageId
        ]);
        return;
    }

    // Salva su file temporaneo e invia
    $tmpDir = sys_get_temp_dir();
    $tmpFile = $tmpDir . '/tts_' . uniqid() . ($isOgg ? '.ogg' : '.wav');
    file_put_contents($tmpFile, $audioData);
    file_put_contents($logFile, "[TTS] File: " . filesize($tmpFile) . " bytes, format=" . ($isOgg ? 'ogg' : 'wav') . "\n", FILE_APPEND);

    if ($isOgg) {
        // OGG/Opus: invia come voice message (con forma d'onda in chat)
        $voiceResult = makeAPIRequest('sendVoice', [
            'chat_id' => $chatId,
            'voice' => new CURLFile($tmpFile, 'audio/ogg', 'voice.ogg'),
            'reply_to_message_id' => $messageId
        ]);
    } else {
        // WAV: invia come file audio
        $voiceResult = makeAPIRequest('sendAudio', [
            'chat_id' => $chatId,
            'audio' => new CURLFile($tmpFile, 'audio/wav', 'voice.wav'),
            'reply_to_message_id' => $messageId
        ]);
    }
    file_put_contents($logFile, "[TTS] Send result: ok=" . json_encode($voiceResult['ok'] ?? false) . "\n", FILE_APPEND);

    // Salva file_id in cache
    if ($voiceResult && $voiceResult['ok']) {
        $fileId = $voiceResult['result']['voice']['file_id']
                ?? $voiceResult['result']['audio']['file_id']
                ?? null;
        if ($fileId) {
            cacheTTS($chatId, $messageId, $fileId);
        }

        // Rimuovi il pulsante "Ascolta" dal messaggio originale
        makeAPIRequest('editMessageReplyMarkup', [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'reply_markup' => json_encode(['inline_keyboard' => []])
        ]);
    }

    // Cleanup
    @unlink($tmpFile);
}

function _suggerisci_comando($comandoErrato, $chatId = null) {
    $model = OLLAMA_MODEL;

    // Ottieni l'help del bot
    $helpText = _help();

    $persona = rootbotPersona();
    $prompt = <<<PROMPT
### ISTRUZIONI ###
{$persona}
Un utente ha digitato un comando che non riconosci.

Il tuo compito è:
1. Analizzare il comando errato digitato dall'utente
2. Capire cosa l'utente probabilmente voleva fare
3. Suggerire il comando corretto dall'elenco dei comandi disponibili

Rispondi in modo breve, vai dritto al punto.

### COMANDO DIGITATO DALL'UTENTE ###
{$comandoErrato}

### COMANDI DISPONIBILI ###
{$helpText}

### OUTPUT ###
Suggerisci il comando corretto. Se non riesci a capire cosa l'utente volesse fare, elenca i comandi più comuni. Massimo 3-4 frasi.
PROMPT;

    // /api/chat + think=false (Gemma 4 pattern), con typing refresh se abbiamo chatId
    $options = ollamaOptions(OLLAMA_MODEL_GPU);
    if ($chatId) {
        $result = callOllamaChatViaQBertWithTyping($model, $prompt, (int)$chatId, $options, false, QBertClient::PRIORITY_NORMAL);
    } else {
        $result = callOllamaChatViaQBert($model, $prompt, $options, false, QBertClient::PRIORITY_NORMAL);
    }

    if (!$result) {
        return "Il comando che hai inserito non lo conosco, controlla meglio cosa hai digitato. Usa /help per vedere i comandi disponibili.";
    }

    $response = $result['response'] ?? '';

    // Rimuovi tag di thinking
    $response = stripThinkingTags($response);
    $response = trim($response);

    if (empty($response)) {
        return "Il comando che hai inserito non lo conosco, controlla meglio cosa hai digitato. Usa /help per vedere i comandi disponibili.";
    }

    return $response;
}
?>
