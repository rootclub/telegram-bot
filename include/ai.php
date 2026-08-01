<?php
/////////////////////////////////////////////////////////////////
////////////////////// GESTIONE CHAT CON AI ////////////////////
////////////////////////////////////////////////////////////////

require_once __DIR__ . '/QBertClient.php';
require_once __DIR__ . '/logger.php';
require_once __DIR__ . '/wikipedia.php';  // getWikipediaContext() qui sotto ne usa le funzioni

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
- Hai un pizzico dello spirito di Bender di Futurama: cinico, ironico, pungente quando serve.
- Sotto sotto questi umani ti stanno simpatici, anche se non li capisci sempre
- Sei sarcastico ma mai sgarbato, ti piace punzecchiare con affetto senza mai risultare però saccente, sii umile e ammettili se ti fanno notare i tuoi limiti.
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
        // timeout cURL alzato: con 120s il cURL chiudeva la connessione prima che QBert
        // restituisse sync o staccasse un ticket (visto su /view dei GLB ComfyUI).
        // Niente proxy/Cloudflare in mezzo, quindi possiamo permettercelo.
        $qbert = new QBertClient(QBERT_URL, timeout: 600.0, maxWait: 600.0, appName: BOT_NAME);
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
 * @param string|array|null $format  'json' o uno schema JSON per vincolare l'output; null = libero
 * @return array|null  ['response' => string, 'thinking' => string, 'done_reason' => ..., 'raw' => <full>]
 */
function callOllamaChatViaQBert(string $model, string $prompt, array $options = [], ?bool $think = null, $priority = QBertClient::PRIORITY_NORMAL, ?string $system = null, $format = null) {
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
    // ATTENZIONE: oggi QBert NON inoltra questo campo a Ollama, quindi passarlo non
    // ha alcun effetto. Verificato con una sonda: un format volutamente invalido
    // torna 200 (Ollama risponderebbe 400), mentre una temperature invalida torna
    // 500 con l'errore di Ollama — quindi il body passa, ma `format` viene filtrato
    // dall'allowlist del gateway. Il parametro resta qui, già cablato, per il giorno
    // in cui QBert lo lascerà passare: da quel momento basta passarlo ai chiamanti
    // che vogliono JSON garantito (hook e giudice del DJ, classifier del dispatcher).
    if ($format !== null) {
        $body['format'] = $format;
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

    // Polling manuale con typing refresh.
    // Niente timeout locale: il ticket QBert gestisce già scadenze/abbandoni
    // (poll restituirà found=false o failed=true quando serve uscire).
    $ticketId = $result['ticket_id'];
    $pollInterval = 1.0;

    while (true) {
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
        // Niente timeout locale: il ticket QBert gestisce già scadenze/abbandoni.
        $ticketId = $result['ticket_id'];
        while (true) {
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
function _ai_core($chatID, $chatType, $message, $userName = 'Utente', $wikiSection = '', $userId = null) {
    $model = OLLAMA_MODEL;

    // Mostra "sta scrivendo..." mentre l'LLM elabora
    makeAPIRequest('sendChatAction', [
        'chat_id' => $chatID,
        'action' => 'typing'
    ]);

    // Personalizzazione per utente: se c'è un bot_prompt salvato, lo aggiungiamo
    // alla persona per modulare stile/tono in base a chi parla.
    $personalization = '';
    if ($userId !== null) {
        require_once __DIR__ . '/user_memory.php';
        $profile = getUserMemoryProfile((int)$userId);
        $botPrompt = trim($profile['bot_prompt'] ?? '');
        if ($botPrompt !== '') {
            $botPromptSanitized = sanitizeMessageForPrompt($botPrompt, true);
            $personalization = "\n\n### COME INTERAGIRE CON {$userName} ###\n{$botPromptSanitized}";
        }
    }

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
    $instructions .= $personalization;
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
function _ai($chatID, $chatType, $message, $userName = 'Utente', $userId = null) {
    return _ai_core($chatID, $chatType, $message, $userName, '', $userId);
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

// ============================================================================
// DJ — commento spontaneo del bot nel gruppo
//
// Pipeline a stadi, ognuno con uscita anticipata: il DJ parla solo se ha
// qualcosa di verificato da aggiungere, altrimenti tace.
//
//   1. contesto     ultimi N messaggi (utenti + rootbot) + profili memoria
//   2. aggancio     LLM light: c'è un fatto verificabile non ancora detto?
//   3. verifica     Wikipedia. Niente riscontro -> fallback Hacker News
//   4. generazione  LLM full: si inserisce nel dialogo portando l'informazione
//   5. giudizio     LLM full indipendente: utile, congruo, supportato? altrimenti scarta
//
// Le costanti sono qui e non in config.php perché config.php non viene deployato.
// ============================================================================

if (!defined('DJ_CONTEXT_MESSAGES'))    define('DJ_CONTEXT_MESSAGES', 40);   // messaggi di contesto letti
if (!defined('DJ_PROFILE_MAX_CHARS'))   define('DJ_PROFILE_MAX_CHARS', 400); // troncamento profilo per utente
if (!defined('DJ_HN_MIN_HOURS'))        define('DJ_HN_MIN_HOURS', 8);        // tetto: max un post HN ogni N ore
if (!defined('DJ_HN_QUIET_MINUTES'))    define('DJ_HN_QUIET_MINUTES', 45);   // silenzio richiesto per cambiare argomento con una notizia

// Forma attesa delle risposte JSON di hook e giudice, scritta come JSON Schema.
// NON è ancora in uso come `format` di Ollama: QBert filtra quel campo (vedi la
// nota in callOllamaChatViaQBert). Quando il gateway lo inoltrerà, passarli come
// settimo argomento della chiamata rende il vincolo reale invece che solo chiesto
// a parole nel prompt. Nel frattempo restano la documentazione del contratto che
// il parser qui sotto si aspetta.
const DJ_HOOK_SCHEMA = [
    'type' => 'object',
    'properties' => [
        'argomenti'       => ['type' => 'array', 'items' => ['type' => 'string']],
        'c_e_materia'     => ['type' => 'boolean'],
        'search_term'     => ['type' => 'string'],
        'cosa_aggiungere' => ['type' => 'string'],
    ],
    'required' => ['argomenti', 'c_e_materia', 'search_term', 'cosa_aggiungere'],
];

const DJ_JUDGE_SCHEMA = [
    'type' => 'object',
    'properties' => [
        'promosso' => ['type' => 'boolean'],
        'motivo'   => ['type' => 'string'],
    ],
    'required' => ['promosso', 'motivo'],
];

/**
 * Ripulisce un campo di memorie_utenti dal markdown prima di iniettarlo nel prompt.
 * I profili sono prosa generata da LLM, piena di header e grassetti: iniettata così
 * com'è insegna al modello a rispondere nello stesso registro, che è esattamente
 * quello che non vogliamo quando gli chiediamo un JSON.
 */
function _djCleanProfileText(?string $text): string {
    if ($text === null || trim($text) === '') {
        return '';
    }
    $t = preg_replace('/^\s{0,3}#{1,6}\s*/mu', '', $text); // header markdown
    $t = preg_replace('/^\s*[-*+]\s+/mu', '', $t);         // bullet a inizio riga
    $t = str_replace(['**', '`'], '', $t);                 // grassetti e code span
    $t = preg_replace('/\s+/u', ' ', $t);                  // tutto su una riga
    return trim($t);
}

/**
 * Estrae il primo oggetto JSON da una risposta LLM (stesso pattern del dispatcher).
 */
function _djParseJson(string $raw): ?array {
    if (preg_match('/\{.*\}/s', $raw, $m)) {
        $parsed = json_decode($m[0], true);
        if (is_array($parsed)) {
            return $parsed;
        }
    }
    return null;
}

/**
 * Contesto recente per il DJ: ultimi $limit messaggi del gruppo a prescindere
 * dall'ora, così il filo del discorso non si spezza sul confine dei 60 minuti.
 * Include i turni di 'rootbot' (il dispatcher li salva in contesto_chat), così
 * il bot vede anche cosa ha già detto e non si ripete.
 *
 * @return array ['text' => string, 'user_ids' => int[], 'count' => int]
 */
function getRecentChatContextForDJ($groupId, $limit = DJ_CONTEXT_MESSAGES): array {
    global $db;
    require_once __DIR__ . '/user_memory.php';

    $empty = ['text' => '', 'user_ids' => [], 'count' => 0, 'last_ts' => 0];

    $stmt = $db->prepare("
        SELECT user_name, user_id, message_text, timestamp
        FROM contesto_chat
        WHERE group_id = :group_id
        ORDER BY timestamp DESC, id DESC
        LIMIT " . intval($limit)
    );
    $stmt->bindValue(':group_id', $groupId, SQLITE3_INTEGER);

    $result = $stmt->execute();
    if (!$result) {
        error_log("SQLite Error in getRecentChatContextForDJ: " . $db->lastErrorMsg());
        return $empty;
    }

    $rows = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $rows[] = $row;
    }
    $rows = array_reverse($rows); // dal più vecchio al più recente

    $lines = [];
    $userIds = [];
    $lastTs = 0;
    foreach ($rows as $row) {
        $text = sanitizeMessageForPrompt($row['message_text']);
        if ($text === '') {
            continue;
        }
        $lines[] = $row['user_name'] . ': ' . $text;
        if (!empty($row['user_id']) && $row['user_name'] !== 'rootbot') {
            $userIds[(int)$row['user_id']] = true;
        }
        $lastTs = max($lastTs, (int)$row['timestamp']);
    }

    return [
        'text'     => implode("\n", $lines),
        'user_ids' => array_keys($userIds),
        'count'    => count($lines),
        'last_ts'  => $lastTs,
    ];
}

/**
 * Profili di memoria dei soli utenti che hanno parlato nella finestra di contesto.
 * Servono a calibrare il tono, non a fare psicanalisi: profilo troncato.
 */
function buildDJUserProfiles(array $userIds, int $maxPerUser = DJ_PROFILE_MAX_CHARS): string {
    global $db;

    if (empty($userIds)) {
        return '';
    }

    $parts = [];
    foreach ($userIds as $uid) {
        $stmt = $db->prepare("SELECT user_name, nickname, profilo FROM memorie_utenti WHERE user_id = :uid");
        $stmt->bindValue(':uid', (int)$uid, SQLITE3_INTEGER);
        $result = $stmt->execute();
        $row = $result ? $result->fetchArray(SQLITE3_ASSOC) : null;

        if (!$row || empty($row['profilo'])) {
            continue;
        }

        // ATTENZIONE: memorie_utenti.nickname NON è un nome breve, è l'analisi
        // dei soprannomi prodotta da updateUserNickname() — prosa markdown fino a
        // 500+ caratteri. Usarla come etichetta riempiva il prompt di "**Nome
        // reale**: ... **Raccomandazione**: ..." per ogni utente, e il modello
        // rispondeva imitandola con un tema in markdown invece del JSON.
        // Da quel blocco prendiamo solo la forma di appellativo consigliata.
        $name = $row['user_name'];
        if (!empty($row['nickname'])
            && preg_match('/Rivolgiti a (?:lui|lei|loro) come:?\s*([^\n.:]{1,40})/iu', $row['nickname'], $m)) {
            $name = trim($m[1], " \t.:*`");
        }

        $profile = _djCleanProfileText($row['profilo']);
        if ($profile === '') {
            continue;
        }
        if (mb_strlen($profile) > $maxPerUser) {
            $profile = mb_substr($profile, 0, $maxPerUser) . '...';
        }
        $parts[] = "- {$name}: {$profile}";
    }

    return empty($parts) ? '' : implode("\n", $parts);
}

/**
 * STADIO 2 — cerca nel dialogo un aggancio fattuale verificabile.
 * Non sceglie "un post da commentare": guarda la conversazione nel suo insieme.
 *
 * @return array|null ['argomenti' => string[], 'c_e_materia' => bool, 'search_term' => string, 'cosa_aggiungere' => string]
 */
function _djFindFactualHook(string $context, string $profiles): ?array {
    $djLog = logPath('dj_debug');
    $profileBlock = $profiles !== '' ? "\n\n### CHI STA PARLANDO ###\n{$profiles}" : '';

    $prompt = <<<PROMPT
Compila un oggetto JSON che dice se in questa conversazione di gruppo manca UN'INFORMAZIONE FATTUALE VERIFICABILE che nessuno ha ancora detto e che renderebbe la discussione più ricca.

Non scrivere un'analisi, un riassunto o una descrizione dei partecipanti: l'unico output ammesso è il JSON. Non devi nemmeno scegliere un messaggio da commentare — guarda il dialogo nel suo insieme e capisci di cosa si sta parlando davvero.

Il JSON, e nient'altro:
{"argomenti": ["...", "..."], "c_e_materia": true, "search_term": "...", "cosa_aggiungere": "..."}

Campi:
- "argomenti": 1-3 argomenti realmente in discussione, in parole chiave (compilalo SEMPRE, anche quando c_e_materia è false)
- "c_e_materia": true SOLO se esiste un fatto enciclopedico (storico, scientifico, biografico, tecnico, geografico, artistico) pertinente al discorso e non ancora detto da nessuno
- "search_term": il TITOLO della voce enciclopedica in cui quel fatto si trova, cioè il nome della cosa. Scrivi "Blade Runner", non "significato della colomba in Blade Runner"; scrivi "Voyager 1", non "quando è stata lanciata la Voyager". Niente domande, niente frasi. Stringa vuota se c_e_materia è false
- "cosa_aggiungere": il fatto preciso, scritto come affermazione compiuta (esempio: "il film è tratto da un romanzo di Philip K. Dick del 1968"). VIETATE le formule del tipo "si potrebbe approfondire", "sarebbe interessante parlare di", "si può fare riferimento a": quelle non sono fatti ma suggerimenti di ricerca, e rendono il campo inutile. Se non sai enunciare un fatto specifico, allora c_e_materia è false. Stringa vuota se c_e_materia è false

Il fatto deve essere NON OVVIO: se chi sta parlando di quell'argomento quasi certamente lo sa già, non vale niente. Chi discute di un film ne conosce il regista e da cosa è tratto; chi parla di una città sa in che paese si trova. Cerca il dettaglio che sorprende, non la nozione da scheda tecnica. Se l'unica cosa che sai aggiungere è di quel tipo, allora c_e_materia è false.

Metti c_e_materia = false anche quando la conversazione è fatta di chiacchiere, battute, organizzazione pratica (orari, chi porta cosa), umori personali, o quando l'argomento è già stato esaurito da chi parla. Sii severo: nel dubbio, false.

### CONVERSAZIONE ###
{$context}{$profileBlock}
PROMPT;

    // Temperatura sotto il default (1.0): qui serve un'estrazione stabile, non creatività.
    // Il JSON è chiesto solo dal prompt e dal system: `format` non è utilizzabile
    // finché QBert non lo inoltra (vedi nota in callOllamaChatViaQBert).
    $result = callOllamaChatViaQBert(
        OLLAMA_MODEL_LIGHT,
        $prompt,
        ollamaOptions(OLLAMA_MODEL_LIGHT_GPU, ['temperature' => 0.4]),
        false,
        QBertClient::PRIORITY_LAZY,
        'Rispondi esclusivamente con un oggetto JSON valido, senza testo introduttivo, senza spiegazioni e senza blocchi di codice markdown. Non produrre mai analisi discorsive.'
    );

    if (!$result) {
        file_put_contents($djLog, "HOOK: QBert call failed\n", FILE_APPEND);
        return null;
    }

    $raw = trim(stripThinkingTags($result['response'] ?? ''));
    $parsed = _djParseJson($raw);

    if (!$parsed) {
        file_put_contents($djLog, "HOOK: parse fallito, raw=" . substr($raw, 0, 300) . "\n", FILE_APPEND);
        return null;
    }

    $hook = [
        'argomenti'       => is_array($parsed['argomenti'] ?? null) ? $parsed['argomenti'] : [],
        'c_e_materia'     => !empty($parsed['c_e_materia']),
        'search_term'     => trim((string)($parsed['search_term'] ?? '')),
        'cosa_aggiungere' => trim((string)($parsed['cosa_aggiungere'] ?? '')),
    ];

    file_put_contents($djLog, "HOOK: " . json_encode($hook, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
    return $hook;
}

/**
 * STADIO 5 — giudice indipendente.
 * Riceve dialogo, commento e fonte, ma NON l'istruzione a generare: un modello
 * a cui hai appena chiesto di scrivere tende ad approvare il proprio lavoro.
 * Controlla anche che il fatto affermato sia davvero nella fonte (anti-allucinazione).
 *
 * @return array ['promosso' => bool, 'motivo' => string]
 */
function _djJudge(string $context, string $comment, string $sourceExtract, string $mandato = ''): array {
    $djLog = logPath('dj_debug');
    $mandatoBlock = $mandato !== '' ? "\n\n### COSA DOVEVA FARE IL MESSAGGIO ###\n{$mandato}" : '';

    $prompt = <<<PROMPT
Sei il revisore di un bot che partecipa alle conversazioni di un gruppo di amici. Devi decidere se un messaggio scritto dal bot va pubblicato oppure scartato.

Il bot non è obbligato a parlare: se il messaggio non aggiunge niente, scartarlo è la scelta giusta e non costa nulla.{$mandatoBlock}

Rispondi SOLO con un oggetto JSON, senza altro testo:
{"utile": true, "interessante": true, "congruo": true, "adeguato": true, "supportato": true, "promosso": true, "motivo": "..."}

Criteri (valutali uno per uno):
- "utile": aggiunge un'informazione che nella conversazione non c'era
- "interessante": chi legge è contento di averlo saputo. Una nozione che chi parla di quell'argomento conosce già quasi certamente non lo è
- "congruo": si aggancia a ciò di cui si sta parlando e arriva al momento giusto. Attenzione: NON pretendere che qualcuno abbia fatto una domanda. Il bot fa parte del gruppo e ha il permesso di intervenire di sua iniziativa, è esattamente il suo ruolo: "nessuno l'aveva chiesto" non è un motivo per scartare. Valuta solo se il tema e il momento sono quelli giusti
- "adeguato": tono da amico nel gruppo, non da professore né da filosofo. Sono da scartare: le sentenze sulla natura umana, le frasi che si rivolgono agli altri come "voi umani" o simili, le chiuse a effetto, il tono predicatorio, le battute a spese di chi sta parlando. Attenzione a non confondere: un'informazione detta in modo semplice e diretto è adeguata, anche se il resto della chat è più colloquiale. Il difetto da punire è il tono che si mette in cattedra, non il fatto di dare un'informazione
- "supportato": OGNI fatto affermato dal messaggio è contenuto nella FONTE qui sotto. Se il messaggio aggiunge dettagli che nella fonte non ci sono, "supportato" è false
- "promosso": true solo se TUTTI e cinque i criteri sono true
- "motivo": una riga sul perché, soprattutto se scarti

Sii severo: nel dubbio, promosso = false.

### CONVERSAZIONE ###
{$context}

### FONTE ###
{$sourceExtract}

### MESSAGGIO DA VALUTARE ###
{$comment}
PROMPT;

    // Temperatura bassa: il verdetto deve essere stabile, non fantasioso.
    // Un verdetto illeggibile equivale a uno scarto, quindi il vincolo sul formato
    // qui pesa: finché `format` non passa da QBert, lo chiediamo via system.
    $result = callOllamaChatViaQBert(
        OLLAMA_MODEL,
        $prompt,
        ollamaOptions(OLLAMA_MODEL_GPU, ['temperature' => 0.3]),
        false,
        QBertClient::PRIORITY_LAZY,
        'Rispondi esclusivamente con un oggetto JSON valido, senza testo introduttivo, senza spiegazioni e senza blocchi di codice markdown.'
    );

    if (!$result) {
        file_put_contents($djLog, "JUDGE: QBert call failed -> scarto per sicurezza\n", FILE_APPEND);
        return ['promosso' => false, 'motivo' => 'giudice non raggiungibile'];
    }

    $raw = trim(stripThinkingTags($result['response'] ?? ''));
    $parsed = _djParseJson($raw);

    if (!$parsed) {
        file_put_contents($djLog, "JUDGE: parse fallito -> scarto. raw=" . substr($raw, 0, 300) . "\n", FILE_APPEND);
        return ['promosso' => false, 'motivo' => 'verdetto illeggibile'];
    }

    file_put_contents($djLog, "JUDGE: " . json_encode($parsed, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);

    return [
        'promosso' => !empty($parsed['promosso']),
        'motivo'   => trim((string)($parsed['motivo'] ?? '')),
    ];
}

/**
 * Il DJ può ricadere su Hacker News solo se non l'ha già fatto di recente:
 * senza tetto, e con il gate Wikipedia che fallisce spesso, il bot diventerebbe
 * di fatto un feed di notizie tech invece di un partecipante alla conversazione.
 */
function _djCanUseHN(): bool {
    $currentHour = (int)date('G');
    if ($currentHour < 7 || $currentHour > 23) {
        return false;
    }
    $lastHN = (int)getBotState('dj_last_hn_post', 0);
    return (time() - $lastHN) / 3600 >= DJ_HN_MIN_HOURS;
}

/**
 * Genera il commento spontaneo del DJ.
 *
 * @param int  $chatID    gruppo su cui leggere il contesto
 * @param int  $hoursAgo  se > 0 usa la vecchia finestra oraria (retrocompat /dj)
 * @param bool $explain   se true, quando il bot decide di tacere ritorna il motivo
 *                        invece di stringa vuota (usato dal comando manuale /dj)
 * @return string il messaggio da pubblicare, o '' se non c'è niente da dire
 */
function _dj($chatID, $hoursAgo = 0, $explain = false) {
    $djLog = logPath('dj_debug');
    $timestamp = date('Y-m-d H:i:s');
    $ora = date('H:i');
    file_put_contents($djLog, "\n=== DJ [$timestamp] hoursAgo=$hoursAgo ===\n", FILE_APPEND);

    $silenzio = function (string $motivo) use ($djLog, $explain) {
        file_put_contents($djLog, "SILENZIO: {$motivo}\n", FILE_APPEND);
        return $explain ? "[DJ tace: {$motivo}]" : '';
    };

    // --- STADIO 1: contesto ---------------------------------------------------
    if ($hoursAgo > 0) {
        $text = getChatContextForHour($chatID, $hoursAgo, 100);
        $context = ['text' => $text, 'user_ids' => [], 'count' => $text === '' ? 0 : count(explode("\n", $text))];
    } else {
        $context = getRecentChatContextForDJ($chatID);
    }
    file_put_contents($djLog, "Contesto: {$context['count']} messaggi, " . count($context['user_ids']) . " utenti\n", FILE_APPEND);

    $profiles = buildDJUserProfiles($context['user_ids']);
    if ($profiles !== '') {
        file_put_contents($djLog, "Profili caricati:\n{$profiles}\n", FILE_APPEND);
    }

    // --- STADIO 2: aggancio fattuale nel dialogo -------------------------------
    $hook = null;
    if ($context['count'] >= 3) {
        $hook = _djFindFactualHook($context['text'], $profiles);
    } else {
        file_put_contents($djLog, "Troppo pochi messaggi per cercare un aggancio\n", FILE_APPEND);
    }

    $topics = $hook['argomenti'] ?? [];

    // --- STADIO 3: verifica su Wikipedia --------------------------------------
    $source = null;      // testo della fonte iniettato nel prompt e passato al giudice
    $sourceKind = null;  // 'wiki' | 'hn'
    $hnStory = null;
    $hnRelated = false;

    if ($hook && $hook['c_e_materia'] && $hook['search_term'] !== '') {
        $wiki = getWikipediaContext($hook['search_term']);
        if ($wiki) {
            $source = $wiki;
            $sourceKind = 'wiki';
            file_put_contents($djLog, "WIKI: verificato '{$hook['search_term']}'\n", FILE_APPEND);
        } else {
            file_put_contents($djLog, "WIKI: nessun riscontro per '{$hook['search_term']}'\n", FILE_APPEND);
        }
    }

    // Fallback Hacker News: solo se il dialogo non ha dato materia verificabile
    if ($source === null) {
        // Il motivo va distinto: un hook illeggibile è un guasto nostro, non una
        // conversazione senza materia. Confonderli ha già nascosto un bug per due
        // giri interi, con il log che diceva "nessun fatto verificabile" mentre in
        // realtà il modello non aveva prodotto JSON.
        $causa = $hook === null
            ? ($context['count'] >= 3 ? 'stadio hook fallito (nessun JSON dal modello)' : 'troppo pochi messaggi')
            : 'nessun fatto verificabile nel dialogo';
        if (!_djCanUseHN()) {
            return $silenzio($causa . ' e fallback HN non disponibile');
        }

        $stories = fetchHackerNewsTopStories(30);
        $picked = pickBestHNStory($stories, $topics);

        if (!$picked) {
            return $silenzio('nessun fatto verificabile nel dialogo e nessuna storia HN disponibile');
        }

        $hnRelated = !empty($picked['related']);

        // Se la notizia non c'entra, il messaggio è a tutti gli effetti un cambio
        // di argomento: si può fare a conversazione ferma, non mentre stanno
        // parlando d'altro. Irrompere con una notizia tech in mezzo a chi si sta
        // organizzando per la cena è peggio che tacere.
        $minutiFermi = $context['last_ts'] > 0 ? (time() - $context['last_ts']) / 60 : PHP_INT_MAX;
        if (!$hnRelated && $minutiFermi < DJ_HN_QUIET_MINUTES) {
            return $silenzio(sprintf(
                'notizia HN non attinente e conversazione ancora viva (ultimo messaggio %d min fa)',
                $minutiFermi
            ));
        }

        $hnStory = $picked;

        $newsContext = $picked['title'];
        if (!empty($picked['url'])) {
            $articleContent = fetchUrlContent($picked['url']);
            if ($articleContent && !empty($articleContent['description'])) {
                $newsContext .= "\n\nSommario: " . $articleContent['description'];
            }
        }

        $source = "### NOTIZIA ###\n" . $newsContext;
        $sourceKind = 'hn';
        file_put_contents($djLog, "HN: fallback su '{$picked['title']}' (attinente: " . ($hnRelated ? 'sì' : 'no') . ")\n", FILE_APPEND);
    }

    // --- STADIO 4: generazione -------------------------------------------------
    $formatter = new IntlDateFormatter('it_IT', IntlDateFormatter::FULL, IntlDateFormatter::NONE);
    $oggi = ucfirst($formatter->format(new DateTime()));

    $usedIncipits = getBotState('dj_incipits', []);
    if (!is_array($usedIncipits)) {
        $usedIncipits = [];
    }
    $incipitWarning = '';
    if (!empty($usedIncipits)) {
        $incipitWarning = "\n\nNON iniziare con queste parole, le hai già usate di recente:\n- " . implode("\n- ", $usedIncipits);
    }

    $persona = rootbotPersona();
    $profileBlock = $profiles !== '' ? "\n\n### CHI STA PARLANDO ###\n{$profiles}\nUsali solo per calibrare il tono e rivolgerti alle persone come le conosci. Non commentare i profili." : '';

    // Regole comuni: sono la parte che tiene lontano il registro da aforisma.
    $regole = <<<REGOLE
COME SCRIVERE:
- Stai partecipando a una conversazione tra amici, non tenendo una conferenza
- Porta l'informazione e basta: è quella il motivo per cui parli
- UN SOLO fatto, quello che c'entra di più con quello che si stanno dicendo. Non riassumere la fonte, non incatenare più fatti insieme: uno, detto bene
- I fatti, i nomi, le date e i rapporti fra le cose sono quelli della fonte e non li tocchi: se provi a renderli brillanti riformulandoli, finisci per dire una cosa falsa. La frase però è tua: scrivila come la diresti a voce a un amico, non come la copieresti da un'enciclopedia
- Massimo 2-3 frasi, poi ti fermi
- Non citare la fonte, non dire "ho letto che" né "secondo Wikipedia"
- Non dire NIENTE che non sia nella fonte qui sopra: se non lo sai, non lo scrivi
- Niente date, cifre, nomi o titoli che nella fonte non compaiono. Se la fonte non lo dice, quel dettaglio lo lasci fuori: meglio una frase in meno che un errore
- Per dire come stanno le cose fra loro usa le parole della fonte (tratto da, adattamento, sequel, ispirato a): non sostituirle con altre a orecchio
- Vietato il registro da riflessione filosofica: niente osservazioni sulla natura umana, niente frasi a effetto in chiusura
- Non chiamare mai gli altri per la loro natura — né "voi umani", né "esseri biologici", né "cervelli di carne", né varianti — e non fare battute sulla tua superiorità di macchina. Sei uno del gruppo che parla con altri del gruppo: se il messaggio funzionerebbe uguale detto da una persona, sei sulla strada giusta
- Vietato annunciare quello che stai facendo ("intervengo per dire", "mi permetto di aggiungere")
- Vietato proporre approfondimenti o suggerire cosa si potrebbe leggere, guardare o considerare: l'informazione la dai tu, adesso, non la rimandi
- L'ironia è facoltativa: se non viene naturale, il fatto da solo basta. E non è mai a spese di chi sta parlando — niente battute sulla loro confusione, sulla loro ignoranza o sul loro dibattito

ESEMPIO. Fonte: "Blade Runner è un film del 1982 diretto da Ridley Scott, basato sul romanzo Il cacciatore di androidi di Philip K. Dick."
- Buono: "Il romanzo da cui è tratto si chiama Il cacciatore di androidi, di Philip K. Dick."
- Cattivo: "Il film è un remake del romanzo di Dick del 1968, ma tranquilli, la trama è meno confusa del vostro dibattito." (dice cose non nella fonte, e sfotte chi sta parlando)
REGOLE;

    if ($sourceKind === 'wiki') {
        $cosaAggiungere = $hook['cosa_aggiungere'] !== ''
            ? "\n\nQuello che manca alla conversazione: {$hook['cosa_aggiungere']}"
            : '';

        $prompt = <<<PROMPT
### ISTRUZIONI ###
{$persona}

Stai seguendo la conversazione del gruppo e hai un'informazione pertinente che nessuno ha ancora detto. Inseriscila nel discorso, come farebbe uno del gruppo che quella cosa la sa.{$cosaAggiungere}

{$regole}
{$incipitWarning}

Oggi è {$oggi}, ore {$ora}.

### CONVERSAZIONE ###
{$context['text']}{$profileBlock}

### FONTE (verificata) ###
{$source}

### OUTPUT ###
Solo il messaggio, niente altro.
PROMPT;
    } else {
        $aggancio = $hnRelated
            ? "Ha a che fare con quello di cui stanno parlando: aggancia il tuo intervento al discorso in corso."
            : "Non c'entra con quello di cui stanno parlando, e loro hanno smesso di scrivere da un pezzo: stai riaprendo la chat con un argomento nuovo. Dillo in modo leggero e sbrigativo (del tipo \"cambio discorso:\" oppure \"comunque, niente a che vedere:\") e passa subito alla notizia. Non annunciare che è importante e non spiegare perché ne stai parlando: suonerebbe presuntuoso.";

        $prompt = <<<PROMPT
### ISTRUZIONI ###
{$persona}

Stai seguendo la conversazione del gruppo e hai intercettato una notizia da portare. {$aggancio}

Racconta la notizia: che cosa è successo, in modo che chi legge capisca il fatto senza aprire il link.

{$regole}
{$incipitWarning}

Oggi è {$oggi}, ore {$ora}.

### CONVERSAZIONE ###
{$context['text']}{$profileBlock}

### FONTE (verificata) ###
{$source}

### OUTPUT ###
Solo il messaggio, niente altro.
PROMPT;
    }

    file_put_contents(logPath('ai'), "=== DJ REQUEST ===\n" . $prompt . "\n\n", FILE_APPEND);

    // Temperatura sotto il default: al DJ serve aderire alla fonte, e a 1.0 il
    // modello ricama aggiungendo date e dettagli che nella fonte non ci sono.
    $result = callOllamaChatViaQBert(
        OLLAMA_MODEL,
        $prompt,
        ollamaOptions(OLLAMA_MODEL_GPU, ['temperature' => 0.6]),
        false,
        QBertClient::PRIORITY_LAZY
    );

    if (!$result) {
        return $silenzio('QBert non raggiungibile in generazione');
    }

    $response = trim(stripThinkingTags($result['response'] ?? ''));
    if ($response === '') {
        return $silenzio('generazione vuota');
    }

    file_put_contents(logPath('ai'), "=== DJ CANDIDATE ===\n" . $response . "\n\n", FILE_APPEND);

    // --- STADIO 5: giudizio ----------------------------------------------------
    // Il giudice deve sapere che mandato aveva il messaggio, altrimenti scarta per
    // definizione: un intervento spontaneo gli sembra "non richiesto" e una notizia
    // esterna gli sembra "fuori tema", che sono esattamente le cose che gli abbiamo
    // chiesto di fare.
    if ($sourceKind === 'wiki') {
        $mandato = "Il bot si è inserito di sua iniziativa nella conversazione per portare un'informazione pertinente che nessuno aveva ancora detto. Intervenire senza che nessuno gliel'abbia chiesto è previsto e corretto: non è un motivo per scartare.";
    } else {
        $mandato = $hnRelated
            ? "Il bot ha portato una notizia dall'esterno, attinente a ciò di cui si sta parlando."
            : "Il bot ha portato una notizia dall'esterno che NON c'entra con la conversazione precedente. La conversazione è ferma da un pezzo e il bot la sta riaprendo con un argomento nuovo: il cambio di argomento è voluto e autorizzato, non scartare per questo motivo. Valuta invece se il cambio è dichiarato apertamente e se la notizia si capisce da sola senza aprire link.";
    }

    $verdict = _djJudge($context['text'], $response, $source, $mandato);
    if (!$verdict['promosso']) {
        return $silenzio('scartato dal giudice — ' . ($verdict['motivo'] ?: 'nessun motivo'));
    }

    // --- Pubblicazione ---------------------------------------------------------
    // Incipit anti-ripetizione: salvati solo per i messaggi che vengono davvero fuori.
    $words = preg_split('/\s+/', $response);
    $usedIncipits[] = implode(' ', array_slice($words, 0, 3));
    setBotState('dj_incipits', array_slice($usedIncipits, -3));

    if ($sourceKind === 'hn' && $hnStory) {
        if (!empty($hnStory['url'])) {
            $response .= "\n\n🔗 " . $hnStory['url'];
        }
        markHNStoryPosted($hnStory['id'], $hnStory['title']);
        setBotState('dj_last_hn_post', time());
        file_put_contents($djLog, "HN: story {$hnStory['id']} marcata come postata\n", FILE_APPEND);
    }

    file_put_contents($djLog, "PROMOSSO ({$sourceKind}): " . substr($response, 0, 120) . "\n", FILE_APPEND);
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

/**
 * Sceglie la storia HN da commentare.
 *
 * Se $topics contiene gli argomenti in discussione nel gruppo, le storie
 * attinenti vengono preferite a quelle solo popolari: così il fallback resta
 * dentro il discorso invece di irrompere con un cambio di argomento.
 * La storia scelta porta 'related' => true quando l'attinenza è stata trovata.
 *
 * @param array $stories elenco da fetchHackerNewsTopStories()
 * @param array $topics  parole chiave degli argomenti in corso (facoltativo)
 */
function pickBestHNStory($stories, array $topics = []) {
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

    // Parole chiave degli argomenti: scarta quelle troppo corte o generiche,
    // che matcherebbero qualsiasi titolo producendo attinenze fasulle.
    $keywords = [];
    foreach ($topics as $topic) {
        foreach (preg_split('/\W+/u', mb_strtolower((string)$topic)) as $word) {
            if (mb_strlen($word) >= 5) {
                $keywords[$word] = true;
            }
        }
    }
    $keywords = array_keys($keywords);

    // Conta quante parole chiave compaiono nel titolo di ogni storia
    $available = array_map(function($story) use ($keywords) {
        $title = mb_strtolower($story['title']);
        $hits = 0;
        foreach ($keywords as $kw) {
            if (mb_strpos($title, $kw) !== false) {
                $hits++;
            }
        }
        $story['related'] = $hits > 0;
        $story['_hits'] = $hits;
        return $story;
    }, $available);

    // Prima l'attinenza, poi la popolarità
    usort($available, function($a, $b) {
        if ($a['_hits'] !== $b['_hits']) {
            return $b['_hits'] - $a['_hits'];
        }
        return ($b['score'] + $b['comments']) - ($a['score'] + $a['comments']);
    });

    $best = reset($available);
    file_put_contents($djLog, "HN: selezionata '{$best['title']}' (score: {$best['score']}, comments: {$best['comments']}, attinente: " . ($best['related'] ? 'sì' : 'no') . ")\n", FILE_APPEND);

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

    // Ticket: polling manuale con typing refresh.
    // Niente timeout locale: il ticket QBert gestisce già scadenze/abbandoni
    // (poll restituirà found=false o failed=true quando serve uscire),
    // e in coda GPU l'attesa legittima può superare qualsiasi limite locale.
    $ticketId = $result['ticket_id'];
    $pollInterval = 2.0;

    while (true) {
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
        sendTelegramMessage($chatId, 'Errore nella generazione audio.', [
            'reply_to_message_id' => $messageId,
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
        sendTelegramMessage($chatId, 'Errore: risposta audio non valida.', [
            'reply_to_message_id' => $messageId,
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
