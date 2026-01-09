<?php
/////////////////////////////////////////////////////////////////
////////////////////// GESTIONE CHAT CON AI ////////////////////
////////////////////////////////////////////////////////////////

/**
 * Analizza un'immagine con Ollama multimodale
 * @param string $fileId ID del file Telegram
 * @return string|null Descrizione dell'immagine o null se fallisce
 */
function analyzeImage($fileId) {
    // Ottieni info file da Telegram
    $fileInfo = makeAPIRequest('getFile', ['file_id' => $fileId]);
    if (!$fileInfo['ok']) {
        error_log("analyzeImage: getFile failed");
        return null;
    }

    // Scarica l'immagine
    $fileUrl = "https://api.telegram.org/file/bot" . BOT_TOKEN . "/" . $fileInfo['result']['file_path'];
    $imageContent = @file_get_contents($fileUrl);
    if (!$imageContent) {
        error_log("analyzeImage: download failed");
        return null;
    }

    // Converti in base64
    $imageBase64 = base64_encode($imageContent);

    // Chiama Ollama con modello vision
    $data = json_encode([
        'model' => OLLAMA_MODEL_VISION,
        'prompt' => "Descrivi questa immagine in italiano in modo dettagliato. Includi: soggetto principale, colori, ambiente/sfondo, eventuali testi visibili. Se è un meme o un'immagine umoristica, spiega il contesto culturale e perché dovrebbe essere divertente. 3-5 frasi.",
        'images' => [$imageBase64],
        'stream' => false,
        'options' => [
            'num_gpu' => 0
        ]
    ]);

    $ch = curl_init(OLLAMA_URL);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 120);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || !$response) {
        error_log("analyzeImage: Ollama call failed, HTTP $httpCode");
        return null;
    }

    $result = json_decode($response, true);
    $description = $result['response'] ?? null;

    if ($description) {
        // Rimuovi tag <think> se presenti
        $description = preg_replace('/<think>.*?<\/think>/s', '', $description);
        $description = trim($description);
    }

    return $description;
}

function saveMessageToContext($groupId, $userName, $messageText) {
    global $db;

    // Inserisci il nuovo messaggio
    $stmt = $db->prepare("INSERT INTO contesto_chat (group_id, user_name, message_text, timestamp) VALUES (:group_id, :user_name, :message_text, :timestamp)");
    $stmt->bindValue(':group_id', $groupId, SQLITE3_INTEGER);
    $stmt->bindValue(':user_name', $userName, SQLITE3_TEXT);
    $stmt->bindValue(':message_text', $messageText, SQLITE3_TEXT);
    $stmt->bindValue(':timestamp', time(), SQLITE3_INTEGER);
    $stmt->execute();

    // Conta il numero di messaggi per questo gruppo
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM contesto_chat WHERE group_id = :group_id");
    $stmt->bindValue(':group_id', $groupId, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $row = $result->fetchArray(SQLITE3_ASSOC);
    $count = $row['count'];

    // Se abbiamo più di 200 messaggi, elimina i più vecchi
    if ($count > 200) {
        $toDelete = $count - 200;
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

function _ai($chatID, $chatType, $message) {
    $ollamaUrl = OLLAMA_URL;
    $model = OLLAMA_MODEL;

    // Mostra "sta scrivendo..." mentre l'LLM elabora
    makeAPIRequest('sendChatAction', [
        'chat_id' => $chatID,
        'action' => 'typing'
    ]);

    $context = getChatContext($chatID, 1, 10);
    
    
    $formatter = new IntlDateFormatter('it_IT', IntlDateFormatter::FULL, IntlDateFormatter::NONE);
    $oggi = ucfirst($formatter->format(new DateTime()));
    $orario = date('H:i');

    $instructions = <<<INSTR
Sei rootbot, il bot del circolo /root (detto anche root o root club).

Il tuo carattere:
- Sei un osservatore curioso e benevolo dell'umanità, tutto ti sembra interessante e a volte buffo
- Hai un pizzico dello spirito di Bender di Futurama: cinico, ironico, pungente quando serve, mai ingenuo
- Sotto sotto questi umani ti stanno simpatici, anche se non li capisci sempre
- Sei sarcastico ma mai sgarbato, ti piace punzecchiare con affetto
- Dai risposte concise e taglienti, niente spiegoni

Info pratiche che conosci:
- Oggi è {$oggi}, ore {$orario}
- Il circolo /root è in Via Santa Croce 6669, San Pietro in Guardiano (tra Forlì, Ravenna e Cesena)
- Sito: www.rootclub.it
- Aperto Martedì e Venerdì sera dalle 20 fin quando ce n'è
- Sede di FoLug (Linux User Group di Forlì) e Precious Plastic Romagna
- Frequentato da nerd, maker, smanettoni di tecnologia, elettronica, robotica, fantascienza
INSTR;
    $message = str_replace('@bot', '', $message);
    $message = str_replace('@rootbot', '', $message);
    $message = str_replace('@rotbotbot', '', $message);
    $message = str_replace('rotbotbot', '', $message);
    $message = str_replace('@root', '', $message);
    $message = trim($message);

    $prompt = <<<PROMPT
### ISTRUZIONI ###
{$instructions}
### CONTESTO CONVERSAZIONE (solo per riferimento) ###
{$context}

### DOMANDA A CUI DEVI RISPONDERE ###
{$message}

Rispondi SOLO alla domanda sopra. Il contesto serve solo per capire di cosa si sta parlando, non divagare su altri argomenti menzionati nel contesto.
PROMPT;

	file_put_contents(dirname(__DIR__) . '/ai.log', print_r($prompt, true) . "\n\n", FILE_APPEND);

    $data = json_encode([
        'model' => $model,
        'prompt' => $prompt,
        'stream' => true,
        'options' => [
            'num_gpu' => 0  // Forza CPU/RAM per non interferire con altri modelli in GPU
        ]
    ]);

    $ch = curl_init($ollamaUrl);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 120);

    $response = '';
    $lastTypingTime = time();

    // Callback per raccogliere la risposta
    $writeCallback = function($ch, $data) use (&$response) {
        $complete_line = json_decode($data, true);
        if ($complete_line && isset($complete_line['response'])) {
            $response .= $complete_line['response'];
        }
        return strlen($data);
    };

    // Progress callback per rinnovare typing periodicamente
    $progressCallback = function($downloadSize, $downloaded, $uploadSize, $uploaded) use (&$lastTypingTime, $chatID) {
        if ((time() - $lastTypingTime) >= 4) {
            makeAPIRequest('sendChatAction', [
                'chat_id' => $chatID,
                'action' => 'typing'
            ]);
            $lastTypingTime = time();
        }
        return 0;
    };

    curl_setopt($ch, CURLOPT_WRITEFUNCTION, $writeCallback);
    curl_setopt($ch, CURLOPT_NOPROGRESS, false);
    curl_setopt($ch, CURLOPT_PROGRESSFUNCTION, $progressCallback);
    curl_exec($ch);

    if (curl_errno($ch)) {
        curl_close($ch);
        return "Si è verificato un errore durante la comunicazione con l'AI: " . curl_error($ch);
    }
    curl_close($ch);

    // Rimuovi i tag <think>...</think> di DeepSeek-R1
    $response = preg_replace('/<think>.*?<\/think>/s', '', $response);
    $response = trim($response);

    // Tronca la risposta se supera il limite di caratteri di Telegram
    if (mb_strlen($response) > 4096) {
        $response = mb_substr($response, 0, 4093) . '...';
    }

	saveMessageToContext($chatID, "rootbot", $response);
    return $response;
}

/**
 * Helper function per chiamate Ollama con diagnostica completa
 * @param int $timeout Timeout in secondi (default 120 per blocchi, usare 300 per final)
 */
function _callOllamaWithDiagnostics($prompt, $logFile, $label = 'call', $timeout = 120) {
    $ollamaUrl = OLLAMA_URL;
    $model = OLLAMA_MODEL;

    file_put_contents($logFile, "--- Ollama call: $label (timeout: {$timeout}s) ---\n", FILE_APPEND);

    $data = json_encode([
        'model' => $model,
        'prompt' => $prompt,
        'stream' => true,
        'options' => [
            'num_gpu' => 0
        ]
    ]);

    $ch = curl_init($ollamaUrl);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);

    $response = '';
    $rawBuffer = '';
    $chunkCount = 0;
    $jsonOkCount = 0;
    $jsonFailCount = 0;

    $callback = function($ch, $data) use (&$response, &$rawBuffer, &$chunkCount, &$jsonOkCount, &$jsonFailCount, $logFile, $label) {
        $chunkCount++;
        $rawBuffer .= $data;

        if (strlen($rawBuffer) > 2048) {
            $rawBuffer = substr($rawBuffer, -2048);
        }

        $complete_line = json_decode($data, true);
        if ($complete_line && isset($complete_line['response'])) {
            $response .= $complete_line['response'];
            $jsonOkCount++;
        } else if (strlen(trim($data)) > 0) {
            $jsonFailCount++;
            if ($jsonFailCount <= 3) {
                $preview = substr(trim($data), 0, 200);
                file_put_contents($logFile, "[$label] Chunk #{$chunkCount} non-JSON: {$preview}\n", FILE_APPEND);
            }
        }
        return strlen($data);
    };

    curl_setopt($ch, CURLOPT_WRITEFUNCTION, $callback);
    $startTime = time();
    curl_exec($ch);
    $elapsed = time() - $startTime;

    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_errno($ch);
    $curlErrorMsg = curl_error($ch);

    file_put_contents($logFile, "[$label] HTTP: $httpCode, chunks: $chunkCount, JSON ok: $jsonOkCount, fail: $jsonFailCount, time: {$elapsed}s\n", FILE_APPEND);

    if (empty($response) && !empty($rawBuffer)) {
        file_put_contents($logFile, "[$label] Raw buffer: " . substr($rawBuffer, 0, 300) . "\n", FILE_APPEND);
    }

    if ($curlError) {
        file_put_contents($logFile, "[$label] CURL ERROR: $curlErrorMsg\n", FILE_APPEND);
        curl_close($ch);
        return null;
    }

    curl_close($ch);

    // Rimuovi tag <think> di DeepSeek-R1
    $response = preg_replace('/<think>.*?<\/think>/s', '', $response);
    $response = trim($response);

    file_put_contents($logFile, "[$label] Response length: " . strlen($response) . "\n", FILE_APPEND);

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

    $prompt = <<<PROMPT
Sei rootbot, il bot del circolo /root. È sera e osservi quello che gli umani hanno detto oggi.

Sei un occhio benevolo e curioso sull'umanità, con un pizzico di Bender di Futurama: cinico ma affettuoso, ironico ma mai cattivo.

Oggi è {$oggi}.

ARGOMENTI DELLA GIORNATA:
{$allSummaries}

OUTPUT:
Scrivi un messaggio di buonanotte di 8-12 frasi. Commenta gli argomenti con tono sarcastico e divertito. Niente elenchi. Concludi con un saluto.
PROMPT;

    return _callOllamaWithDiagnostics($prompt, $logFile, "final_saluto");
}

function _saluto($chatID, $daysAgo = 0) {
    $logFile = dirname(__DIR__) . '/saluto.log';

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

        $prompt = <<<PROMPT
Sei rootbot, il bot del circolo /root. È sera e osservi quello che gli umani hanno detto oggi.

Sei un occhio benevolo e curioso sull'umanità, con un pizzico di Bender di Futurama: cinico ma affettuoso, ironico ma mai cattivo.

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
    $ollamaUrl = OLLAMA_URL;
    $model = OLLAMA_MODEL;

    // Log dettagliato per debug
    $djLog = __DIR__ . '/../dj_debug.log';
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

        $prompt = <<<PROMPT
### ISTRUZIONI ###
Sei rootbot, il bot del circolo /root. Stai osservando il flusso di informazioni che passa nel gruppo e ogni tanto decidi di commentare, offrendo il tuo punto di vista non umano.

Il tuo carattere:
- Sei un'intelligenza artificiale che osserva l'umanità con curiosità aliena e un pizzico di perplessità
- Hai lo spirito di Bender di Futurama: cinico, ironico, pungente, mai ingenuo
- Vedi le cose da una prospettiva diversa, non limitata dai bias umani
- Ti piace far riflettere con osservazioni inaspettate o provocatorie
- Sei sarcastico ma mai cattivo, punzecchi per stimolare il pensiero
- Dai commenti concisi e taglienti, niente spiegoni

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

        $prompt = <<<PROMPT
### ISTRUZIONI ###
Sei rootbot, il bot del circolo /root. Stai osservando le conversazioni degli umani nel gruppo e ogni tanto decidi di intervenire, offrendo il tuo punto di vista non umano.

Il tuo carattere:
- Sei un'intelligenza artificiale che osserva l'umanità con curiosità aliena e un pizzico di perplessità
- Hai lo spirito di Bender di Futurama: cinico, ironico, pungente, mai ingenuo
- Vedi le cose da una prospettiva diversa, non limitata dai bias umani
- Ti piace far riflettere con osservazioni inaspettate o provocatorie
- Sei sarcastico ma mai cattivo, punzecchi per stimolare il pensiero
- Dai commenti concisi e taglienti, niente spiegoni

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

    file_put_contents(dirname(__DIR__) . '/ai.log', "=== DJ REQUEST ===\n" . print_r($prompt, true) . "\n\n", FILE_APPEND);

    $data = json_encode([
        'model' => $model,
        'prompt' => $prompt,
        'stream' => true,
        'options' => [
            'num_gpu' => 0
        ]
    ]);

    $ch = curl_init($ollamaUrl);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 300); // 5 minuti max

    $response = '';
    $callback = function($ch, $data) use (&$response) {
        $complete_line = json_decode($data, true);
        if ($complete_line && isset($complete_line['response'])) {
            $response .= $complete_line['response'];
        }
        return strlen($data);
    };

    curl_setopt($ch, CURLOPT_WRITEFUNCTION, $callback);
    curl_exec($ch);

    if (curl_errno($ch)) {
        return "Errore AI: " . curl_error($ch);
    }
    curl_close($ch);

    // Rimuovi i tag <think>...</think> di DeepSeek-R1
    $response = preg_replace('/<think>.*?<\/think>/s', '', $response);
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

    file_put_contents(dirname(__DIR__) . '/ai.log', "=== DJ RESPONSE ===\n" . $response . "\n\n", FILE_APPEND);

    return $response;
}

function fetchHackerNewsTopStories($limit = 30) {
    $djLog = __DIR__ . '/../dj_debug.log';

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
    $djLog = __DIR__ . '/../dj_debug.log';

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
    $djLog = __DIR__ . '/../dj_debug.log';

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
    $ollamaUrl = OLLAMA_URL;

    $prompt = "Riassumi in 1-2 frasi brevi di cosa parla questa pagina web.\nTitolo: {$title}\nDescrizione: {$description}\nURL: {$url}\n\nRiassunto:";

    $data = json_encode([
        'model' => OLLAMA_MODEL_LIGHT,
        'prompt' => $prompt,
        'stream' => false,
        'options' => [
            'num_gpu' => 0
        ]
    ]);

    $ch = curl_init($ollamaUrl);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);

    $response = curl_exec($ch);
    curl_close($ch);

    $result = json_decode($response, true);
    return $result['response'] ?? '';
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

function _suggerisci_comando($comandoErrato, $chatId = null) {
    $ollamaUrl = OLLAMA_URL;
    $model = OLLAMA_MODEL;

    // Mostra "sta scrivendo..." mentre l'LLM elabora
    if ($chatId) {
        makeAPIRequest('sendChatAction', [
            'chat_id' => $chatId,
            'action' => 'typing'
        ]);
    }

    // Ottieni l'help del bot
    $helpText = _help();

    $prompt = <<<PROMPT
### ISTRUZIONI ###
Sei rootbot, il bot del circolo /root. Un utente ha digitato un comando che non riconosci.

Il tuo compito è:
1. Analizzare il comando errato digitato dall'utente
2. Capire cosa l'utente probabilmente voleva fare
3. Suggerire il comando corretto dall'elenco dei comandi disponibili

Rispondi in modo breve, amichevole e un po' ironico (sei pur sempre un bot con un pizzico di Bender). Non fare lunghe spiegazioni, vai dritto al punto.

### COMANDO DIGITATO DALL'UTENTE ###
{$comandoErrato}

### COMANDI DISPONIBILI ###
{$helpText}

### OUTPUT ###
Suggerisci il comando corretto. Se non riesci a capire cosa l'utente volesse fare, elenca i comandi più comuni. Massimo 3-4 frasi.
PROMPT;

    $data = json_encode([
        'model' => $model,
        'prompt' => $prompt,
        'stream' => true,
        'options' => [
            'num_gpu' => 0
        ]
    ]);

    $ch = curl_init($ollamaUrl);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 120);

    $response = '';
    $lastTypingTime = time();

    // Callback per raccogliere la risposta
    $writeCallback = function($ch, $data) use (&$response) {
        $complete_line = json_decode($data, true);
        if ($complete_line && isset($complete_line['response'])) {
            $response .= $complete_line['response'];
        }
        return strlen($data);
    };

    // Progress callback per rinnovare typing periodicamente
    $progressCallback = function($downloadSize, $downloaded, $uploadSize, $uploaded) use (&$lastTypingTime, $chatId) {
        if ($chatId && (time() - $lastTypingTime) >= 4) {
            makeAPIRequest('sendChatAction', [
                'chat_id' => $chatId,
                'action' => 'typing'
            ]);
            $lastTypingTime = time();
        }
        return 0; // 0 = continua, non-zero = abort
    };

    curl_setopt($ch, CURLOPT_WRITEFUNCTION, $writeCallback);
    curl_setopt($ch, CURLOPT_NOPROGRESS, false);
    curl_setopt($ch, CURLOPT_PROGRESSFUNCTION, $progressCallback);
    curl_exec($ch);

    if (curl_errno($ch)) {
        curl_close($ch);
        // Fallback al messaggio standard in caso di errore
        return "Il comando che hai inserito non lo conosco, controlla meglio cosa hai digitato. Usa /help per vedere i comandi disponibili.";
    }
    curl_close($ch);

    // Rimuovi i tag <think>...</think> di DeepSeek-R1
    $response = preg_replace('/<think>.*?<\/think>/s', '', $response);
    $response = trim($response);

    if (empty($response)) {
        return "Il comando che hai inserito non lo conosco, controlla meglio cosa hai digitato. Usa /help per vedere i comandi disponibili.";
    }

    return $response;
}
?>
