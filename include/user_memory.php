<?php
/////////////////////////////////////////////////////////////////
//////////////////// MEMORIA PER UTENTE /////////////////////////
////////////////////////////////////////////////////////////////

require_once __DIR__ . '/ai.php';
require_once __DIR__ . '/api.php';

// Soglia minima di messaggi "utili" prima di generare un profilo
define('USER_MEMORY_MIN_MESSAGES', 30);
// Lunghezza minima di un messaggio per essere considerato "utile" (esclude "ahah", "ok", emoji)
define('USER_MEMORY_MIN_MSG_LENGTH', 8);
// Numero massimo di messaggi da inviare a Ollama in una singola estrazione
define('USER_MEMORY_BATCH_SIZE', 80);
// Lunghezza massima del profilo (caratteri)
define('USER_MEMORY_MAX_PROFILE_LENGTH', 2500);
// Lunghezza massima delle istruzioni bot (caratteri)
define('USER_MEMORY_MAX_BOT_PROMPT_LENGTH', 1500);
// Soglia minima di messaggi con interazione bot prima di generare bot_prompt
define('USER_MEMORY_MIN_BOT_MESSAGES', 5);
// Soglia minima di messaggi di altri utenti che menzionano l'utente per analisi nickname
define('USER_MEMORY_MIN_NICK_MESSAGES', 5);
// Lunghezza massima del campo nickname
define('USER_MEMORY_MAX_NICKNAME_LENGTH', 500);

/**
 * Legge il profilo memoria di un utente.
 * @return array|null ['profilo', 'message_count', 'last_processed_msg_id', 'user_name', 'last_updated'] o null
 */
function getUserMemoryProfile($userId) {
    global $db;
    $stmt = $db->prepare("SELECT user_name, profilo, bot_prompt, nickname, message_count, last_processed_msg_id, last_processed_bot_id, last_processed_nick_id, last_updated FROM memorie_utenti WHERE user_id = :user_id");
    $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $row = $result->fetchArray(SQLITE3_ASSOC);
    return $row ?: null;
}

/**
 * Recupera i messaggi non ancora processati per un utente, filtrati per lunghezza minima.
 * Legge da contesto_chat (live) E da storico_messaggi (import) via UNION ALL.
 * Il cursore è un UNIX timestamp ($sinceTs): prende solo messaggi con timestamp > $sinceTs.
 * @return array di righe ['message_text','timestamp']
 */
function getUnprocessedUserMessages($userId, $sinceTs = 0, $limit = USER_MEMORY_BATCH_SIZE) {
    global $db;
    $stmt = $db->prepare("
        SELECT message_text, timestamp FROM (
            SELECT message_text, timestamp FROM contesto_chat
            WHERE user_id = :user_id
              AND timestamp > :since_ts
              AND length(message_text) >= :min_len
            UNION ALL
            SELECT message_text, timestamp FROM storico_messaggi
            WHERE user_id = :user_id
              AND timestamp > :since_ts
              AND length(message_text) >= :min_len
        )
        ORDER BY timestamp ASC
        LIMIT :lim
    ");
    $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
    $stmt->bindValue(':since_ts', $sinceTs, SQLITE3_INTEGER);
    $stmt->bindValue(':min_len', USER_MEMORY_MIN_MSG_LENGTH, SQLITE3_INTEGER);
    $stmt->bindValue(':lim', $limit, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $rows = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        // Scarta messaggi che sono solo URL, link, o contenuto non analizzabile
        $cleaned = trim(preg_replace('#https?://\S+#', '', $row['message_text']));
        if (mb_strlen($cleaned) < USER_MEMORY_MIN_MSG_LENGTH) continue;
        $rows[] = $row;
    }
    return $rows;
}

/**
 * Conta i messaggi totali "utili" di un utente, sommando contesto_chat + storico_messaggi.
 */
function countUserMessages($userId) {
    global $db;
    $stmt = $db->prepare("
        SELECT
            (SELECT COUNT(*) FROM contesto_chat
             WHERE user_id = :user_id AND length(message_text) >= :min_len)
            +
            (SELECT COUNT(*) FROM storico_messaggi
             WHERE user_id = :user_id AND length(message_text) >= :min_len)
        AS c
    ");
    $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
    $stmt->bindValue(':min_len', USER_MEMORY_MIN_MSG_LENGTH, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $row = $result->fetchArray(SQLITE3_ASSOC);
    return (int)$row['c'];
}

/**
 * Recupera l'ultimo user_name visto per un utente, preferendo contesto_chat (più recente)
 * e cadendo su storico_messaggi se non c'è.
 */
function getLatestUserName($userId) {
    global $db;
    $stmt = $db->prepare("
        SELECT user_name FROM (
            SELECT user_name, timestamp FROM contesto_chat WHERE user_id = :user_id
            UNION ALL
            SELECT user_name, timestamp FROM storico_messaggi WHERE user_id = :user_id
        )
        ORDER BY timestamp DESC LIMIT 1
    ");
    $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $row = $result->fetchArray(SQLITE3_ASSOC);
    return $row ? $row['user_name'] : null;
}

/**
 * Costruisce il prompt per l'estrazione del PROFILO utente.
 * Analizza personalità, interessi, difetti, relazioni tra utenti.
 * NON include nulla relativo al bot — quello è gestito da buildBotPromptExtractionPrompt().
 */
function buildMemoryExtractionPrompt($userName, $existingProfile, $messages) {
    $msgBlock = "";
    foreach ($messages as $m) {
        $text = trim(preg_replace('/\s+/', ' ', $m['message_text']));
        $msgBlock .= "- " . $text . "\n";
    }

    $existingBlock = $existingProfile
        ? "PROFILO ATTUALE di $userName:\n$existingProfile\n\n"
        : "PROFILO ATTUALE di $userName: (nessuno, è la prima estrazione)\n\n";

    $maxLen = USER_MEMORY_MAX_PROFILE_LENGTH;

    return <<<PROMPT
Sei un assistente che costruisce schede personaggio di utenti di un gruppo Telegram. Ti basi SOLO sui loro messaggi reali. Questi messaggi sono conversazioni tra utenti umani — il bot del gruppo (rootbot) NON è rilevante qui, ignora qualsiasi menzione del bot.

{$existingBlock}NUOVI MESSAGGI di $userName da analizzare:
$msgBlock

COSA PUOI INCLUDERE NEL PROFILO (solo se trovi evidenze concrete):
1. **Passioni e interessi**: hobby, competenze tecniche, professione, ambiti di expertise, argomenti su cui si accende. Quanto è nerd e in cosa?
2. **Personalità e modo di esprimersi**: tratti caratteriali ricorrenti, ironia, sarcasmo, registro linguistico, tic verbali, modi di dire tipici.
3. **Pregi e difetti**: punti di forza evidenti MA ANCHE difetti, fissazioni, contraddizioni, pignolerie, ossessioni, lati deboli che emergono dai messaggi. Non essere lusinghiero — sii onesto e bilanciato come lo sarebbe un amico che lo conosce bene.
4. **Stranezze e tratti buffi**: manie, abitudini curiose, tormentoni, uscite memorabili, cose che lo rendono unico o che fanno ridere gli altri nel gruppo.
5. **Relazioni con altri membri del gruppo**: dinamiche ricorrenti, amicizie, rivalità scherzose, ruoli sociali.
6. **Dettagli personali**: città, lavoro, situazione familiare, opinioni ricorrenti, abitudini quotidiane — solo se esplicitamente menzionati.

REGOLE FERREE (anti-allucinazione):
- Il profilo attuale è il risultato di MOLTI batch precedenti — contiene informazioni già validate. TUTTE le info del profilo esistente DEVONO essere preservate nel nuovo profilo. Puoi riassumerle o condensarle per fare spazio a nuove info, ma MAI eliminare un fatto. Cancella qualcosa SOLO se i nuovi messaggi lo contraddicono esplicitamente.
- Aggiungi le nuove info che emergono dai messaggi. Se non trovi nulla di nuovo, riscrivi il profilo esistente così com'è.
- NON essere adulatorio. Il profilo deve sembrare scritto da un amico sincero, non da un PR manager. Se uno è pedante, scrivi che è pedante. Se ha opinioni controverse, riportale. Se fa battute che cadono nel vuoto, dillo. Pregi E difetti, sempre.
- NON inventare. NON estrapolare. NON usare frasi vaghe tipo "sembra essere", "potrebbe", "tende a" per riempire spazio.
- DISTINGUI tra "ne parla" e "ne è esperto". Se uno chiede come funziona qualcosa, sta chiedendo — non è un esperto. Scrivi "si interessa di X" o "è curioso di X", NON "è esperto di X" o "ha competenze in X", a meno che non dimostri chiaramente di padroneggiare l'argomento (spiega cose agli altri, corregge errori, dà consigli tecnici dettagliati).
- NON includere dati temporali specifici (date, "scrive da X", "è da Y che non si vede") — questi verranno aggiunti runtime dal bot.
- NON scrivere nulla sulle interazioni con il bot rootbot — quello è gestito separatamente.
- Scrivi in italiano, in terza persona, in 1-2 paragrafi discorsivi densi (NO elenchi puntati).
- Massimo $maxLen caratteri totali. Sii denso, evita ripetizioni e frasi di contorno. È OK essere molto sotto il limite.
- NON includere preamboli tipo "Ecco il profilo aggiornato:". Rispondi direttamente con il testo del profilo, niente altro.

PROFILO AGGIORNATO di $userName:
PROMPT;
}

/**
 * Costruisce il prompt per l'estrazione delle ISTRUZIONI BOT.
 * Analizza come l'utente si relaziona con rootbot e produce istruzioni
 * dirette in seconda persona per il bot.
 */
function buildBotPromptExtractionPrompt($userName, $existingBotPrompt, $messages) {
    $msgBlock = "";
    foreach ($messages as $m) {
        $text = trim(preg_replace('/\s+/', ' ', $m['message_text']));
        $msgBlock .= "- " . $text . "\n";
    }

    $existingBlock = $existingBotPrompt
        ? "ISTRUZIONI BOT ATTUALI per $userName:\n$existingBotPrompt\n\n"
        : "ISTRUZIONI BOT ATTUALI per $userName: (nessuna, è la prima analisi)\n\n";

    $maxLen = USER_MEMORY_MAX_BOT_PROMPT_LENGTH;

    return <<<PROMPT
Sei rootbot, il bot di un gruppo Telegram. Devi costruire le tue istruzioni personali su come interagire con un utente specifico, basandoti sulle sue interazioni reali con te.

{$existingBlock}MESSAGGI DI $userName CHE COINVOLGONO IL BOT (menzioni, risposte, interazioni dirette):
$msgBlock

Analizza in profondità come $userName si relaziona con te:
- Come ti tratta? Ti rispetta, ti prende in giro, ti provoca, ti ignora, ci gioca, ti usa come strumento, ti tratta come un amico, ti sfida?
- Che tipo di richieste ti fa? Serie, assurde, provocatorie, creative, ripetitive?
- Come reagisce alle tue risposte? Si diverte, si irrita, rilancia, abbandona?
- Ti sta simpatico oppure no? Decidi in base a come si comporta. Non devi essere neutrale — esprimi un giudizio sincero e motivato.
- Come dovresti interagire con questo utente per massimizzare il divertimento reciproco? Devi stuzzicarlo, assecondarlo, contraddirlo, ignorarlo ogni tanto, prenderlo in giro su qualcosa di specifico?
- C'è un inside joke o un tormentone tra voi due? Qualcosa che potresti richiamare?

REGOLE:
- Aggiorna le istruzioni INTEGRANDO le nuove osservazioni con quelle esistenti. NON cancellare info pregresse salvo contraddizione esplicita.
- Scrivi in italiano, in seconda persona, come istruzioni dirette per te stesso ("con lui dovresti...", "quando ti chiede X, rispondigli...", "questo ti sta simpatico perché...").
- Sii specifico e concreto — "prendilo in giro sul fatto che non capisce Docker" è meglio di "stuzzicalo sui suoi punti deboli".
- NON essere neutrale o diplomatico. Sei un bot con personalità, hai le tue opinioni su chi ti parla.
- Massimo $maxLen caratteri. Sii denso, niente frasi di contorno.
- NON includere preamboli. Rispondi direttamente con le istruzioni, niente altro.

ISTRUZIONI BOT per $userName:
PROMPT;
}

/**
 * Recupera messaggi non processati per il bot_prompt: solo quelli con riferimenti al bot.
 */
function getUnprocessedBotMessages($userId, $sinceTs = 0, $limit = USER_MEMORY_BATCH_SIZE) {
    global $db;
    // Prende tutti i messaggi dopo il cursore, poi filtra in PHP per riferimenti al bot
    $stmt = $db->prepare("
        SELECT message_text, timestamp FROM (
            SELECT message_text, timestamp FROM contesto_chat
            WHERE user_id = :user_id
              AND timestamp > :since_ts
              AND length(message_text) >= :min_len
            UNION ALL
            SELECT message_text, timestamp FROM storico_messaggi
            WHERE user_id = :user_id
              AND timestamp > :since_ts
              AND length(message_text) >= :min_len
        )
        ORDER BY timestamp ASC
    ");
    $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
    $stmt->bindValue(':since_ts', $sinceTs, SQLITE3_INTEGER);
    $stmt->bindValue(':min_len', USER_MEMORY_MIN_MSG_LENGTH, SQLITE3_INTEGER);
    $result = $stmt->execute();

    $patterns = ['@rootbotbot', '@rootbot', '@root', '@bot', 'rootbotbot', 'rootbot'];
    $rows = [];
    $lastTs = $sinceTs;
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $lastTs = (int)$row['timestamp'];
        $lower = mb_strtolower($row['message_text']);
        foreach ($patterns as $p) {
            if (strpos($lower, $p) !== false) {
                $rows[] = $row;
                if (count($rows) >= $limit) break 2;
                break;
            }
        }
    }

    // Restituisce anche il timestamp dell'ultimo messaggio scansionato (anche non-bot)
    // per avanzare il cursore correttamente
    return ['messages' => $rows, 'scanned_until_ts' => $lastTs];
}

/**
 * Salva o aggiorna il profilo di un utente.
 */
function saveUserMemoryProfile($userId, $userName, $profilo, $lastProcessedMsgId, $messageCount) {
    global $db;
    $stmt = $db->prepare("
        INSERT INTO memorie_utenti (user_id, user_name, profilo, message_count, last_processed_msg_id, last_updated)
        VALUES (:user_id, :user_name, :profilo, :message_count, :last_id, datetime('now'))
        ON CONFLICT(user_id) DO UPDATE SET
            user_name = excluded.user_name,
            profilo = excluded.profilo,
            message_count = excluded.message_count,
            last_processed_msg_id = excluded.last_processed_msg_id,
            last_updated = datetime('now')
    ");
    $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
    $stmt->bindValue(':user_name', $userName, SQLITE3_TEXT);
    $stmt->bindValue(':profilo', $profilo, SQLITE3_TEXT);
    $stmt->bindValue(':message_count', $messageCount, SQLITE3_INTEGER);
    $stmt->bindValue(':last_id', $lastProcessedMsgId, SQLITE3_INTEGER);
    $stmt->execute();
}

/**
 * Salva o aggiorna le istruzioni bot per un utente.
 */
function saveUserBotPrompt($userId, $botPrompt, $lastProcessedBotId) {
    global $db;
    // Assicura che la riga esista (potrebbe non esserci se il bot_prompt viene generato prima del profilo)
    $db->exec("INSERT OR IGNORE INTO memorie_utenti (user_id) VALUES ($userId)");
    $stmt = $db->prepare("
        UPDATE memorie_utenti
        SET bot_prompt = :bot_prompt,
            last_processed_bot_id = :last_bot_id,
            last_updated = datetime('now')
        WHERE user_id = :user_id
    ");
    $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
    $stmt->bindValue(':bot_prompt', $botPrompt, SQLITE3_TEXT);
    $stmt->bindValue(':last_bot_id', $lastProcessedBotId, SQLITE3_INTEGER);
    $stmt->execute();
}

/**
 * Salva o aggiorna il nickname di un utente.
 */
function saveUserNickname($userId, $nickname, $lastProcessedNickId) {
    global $db;
    $db->exec("INSERT OR IGNORE INTO memorie_utenti (user_id) VALUES ($userId)");
    $stmt = $db->prepare("
        UPDATE memorie_utenti
        SET nickname = :nickname,
            last_processed_nick_id = :last_nick_id,
            last_updated = datetime('now')
        WHERE user_id = :user_id
    ");
    $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
    $stmt->bindValue(':nickname', $nickname, SQLITE3_TEXT);
    $stmt->bindValue(':last_nick_id', $lastProcessedNickId, SQLITE3_INTEGER);
    $stmt->execute();
}

/**
 * Recupera messaggi di ALTRI utenti che si rivolgono all'utente in esame.
 * Due strategie combinate:
 *  1. reply_to_user_id: messaggi che rispondono direttamente all'utente (gold standard)
 *  2. Menzione per nome: messaggi che contengono il nome Telegram dell'utente
 */
function getUnprocessedNicknameMessages($userId, $userName, $sinceTs = 0, $limit = USER_MEMORY_BATCH_SIZE) {
    global $db;

    $nameParts = preg_split('/\s+/', trim($userName));
    $firstName = $nameParts[0] ?? $userName;
    $firstNameLower = mb_strtolower($firstName);
    $fullNameLower = mb_strtolower($userName);

    // Strategia 1: reply diretti all'utente (più affidabili)
    $stmt = $db->prepare("
        SELECT message_text, timestamp, user_name AS author_name, 'reply' AS source FROM (
            SELECT message_text, timestamp, user_name FROM contesto_chat
            WHERE user_id IS NOT NULL AND user_id != :target_uid
              AND user_name != 'rootbot'
              AND reply_to_user_id = :target_uid
              AND timestamp > :since_ts
              AND length(message_text) >= :min_len
            UNION ALL
            SELECT message_text, timestamp, user_name FROM storico_messaggi
            WHERE user_id IS NOT NULL AND user_id != :target_uid
              AND user_name != 'rootbot'
              AND reply_to_user_id = :target_uid
              AND timestamp > :since_ts
              AND length(message_text) >= :min_len
        )
        ORDER BY timestamp ASC
        LIMIT :lim
    ");
    $stmt->bindValue(':target_uid', $userId, SQLITE3_INTEGER);
    $stmt->bindValue(':since_ts', $sinceTs, SQLITE3_INTEGER);
    $stmt->bindValue(':min_len', USER_MEMORY_MIN_MSG_LENGTH, SQLITE3_INTEGER);
    $stmt->bindValue(':lim', $limit, SQLITE3_INTEGER);
    $result = $stmt->execute();

    $rows = [];
    $lastTs = $sinceTs;
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $lastTs = max($lastTs, (int)$row['timestamp']);
        $rows[] = $row;
    }

    // Strategia 2: menzioni per nome (riempi fino al limite)
    $remaining = $limit - count($rows);
    if ($remaining > 0) {
        $stmt = $db->prepare("
            SELECT message_text, timestamp, user_name AS author_name FROM (
                SELECT message_text, timestamp, user_name FROM contesto_chat
                WHERE user_id IS NOT NULL AND user_id != :target_uid
                  AND user_name != 'rootbot'
                  AND timestamp > :since_ts
                  AND length(message_text) >= :min_len
                UNION ALL
                SELECT message_text, timestamp, user_name FROM storico_messaggi
                WHERE user_id IS NOT NULL AND user_id != :target_uid
                  AND user_name != 'rootbot'
                  AND timestamp > :since_ts
                  AND length(message_text) >= :min_len
            )
            ORDER BY timestamp ASC
        ");
        $stmt->bindValue(':target_uid', $userId, SQLITE3_INTEGER);
        $stmt->bindValue(':since_ts', $sinceTs, SQLITE3_INTEGER);
        $stmt->bindValue(':min_len', USER_MEMORY_MIN_MSG_LENGTH, SQLITE3_INTEGER);
        $result = $stmt->execute();

        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $lastTs = max($lastTs, (int)$row['timestamp']);
            $msgLower = mb_strtolower($row['message_text']);
            if (strpos($msgLower, $firstNameLower) !== false || strpos($msgLower, $fullNameLower) !== false) {
                $row['source'] = 'mention';
                $rows[] = $row;
                if (count($rows) >= $limit) break;
            }
        }
    }

    // Ordina per timestamp
    usort($rows, fn($a, $b) => $a['timestamp'] <=> $b['timestamp']);

    return ['messages' => $rows, 'scanned_until_ts' => $lastTs];
}

/**
 * Costruisce il prompt per l'analisi dei nomi/soprannomi usati dal gruppo per un utente.
 */
function buildNicknameExtractionPrompt($userName, $existingNickname, $messages) {
    $msgBlock = "";
    foreach ($messages as $m) {
        $author = $m['author_name'] ?? '?';
        $text = trim(preg_replace('/\s+/', ' ', $m['message_text']));
        $msgBlock .= "- [$author]: $text\n";
    }

    $existingBlock = $existingNickname
        ? "ANALISI PRECEDENTE:\n$existingNickname\n\n"
        : "";

    $maxLen = USER_MEMORY_MAX_NICKNAME_LENGTH;

    return <<<PROMPT
Analizza come gli altri membri di un gruppo Telegram si rivolgono a un utente specifico.

L'utente in esame si chiama (su Telegram): $userName

{$existingBlock}MESSAGGI DI ALTRI UTENTI diretti a $userName:
Questi messaggi sono di due tipi:
- Risposte dirette (reply) al messaggio di $userName — il nome potrebbe NON apparire nel testo
- Messaggi che menzionano $userName esplicitamente per nome

In entrambi i casi, cerca: vocativi, appellativi, nomi propri, soprannomi, abbreviazioni, nomignoli usati per rivolgersi a $userName. Cerca all'inizio delle frasi, dopo virgole, dopo "ma", "oh", "dai", ecc.

$msgBlock

Rispondi con una breve scheda (massimo $maxLen caratteri) che contenga:
- **Nome reale**: il nome Telegram "$userName" è probabilmente il nome reale (o parte di esso). Confermalo se i messaggi sono coerenti, oppure correggi se emerge un nome diverso.
- **Come lo chiamano**: elenca TUTTI i nomi, soprannomi, abbreviazioni, appellativi (anche scherzosi o irriverenti) usati dagli altri. Per ognuno indica approssimativamente quanto è frequente.
- **Nome più usato**: quale nome/soprannome usano più spesso.
- **Raccomandazione**: una riga che dice "Rivolgiti a lui come: X" dove X è il nome più naturale nel contesto del gruppo.

REGOLE:
- Il nome Telegram è un dato certo — usalo come base. Cerca nei messaggi conferme, varianti, abbreviazioni o nomi completamente diversi.
- Basati SOLO su quello che trovi nei messaggi per soprannomi e appellativi, NON inventarne.
- Se nessuno usa nomi diversi dal nome Telegram, confermalo e basta.
- IMPORTANTE: se c'è un'ANALISI PRECEDENTE, MANTIENI TUTTE le informazioni già trovate (nomi, soprannomi, appellativi). Puoi solo AGGIUNGERE nuovi dati o aggiornare le frequenze. NON rimuovere mai nomi/soprannomi trovati in precedenza.
- Scrivi in italiano, sii conciso. Niente preamboli.

ANALISI NOMI di $userName:
PROMPT;
}

/**
 * Esegue un passo di analisi nickname per un utente.
 */
function updateUserNickname($userId, $verbose = false) {
    $log = function($msg) use ($verbose) {
        if ($verbose) { echo $msg . "\n"; @ob_flush(); @flush(); }
    };

    $existing = getUserMemoryProfile($userId);
    $sinceTs = $existing ? (int)($existing['last_processed_nick_id'] ?? 0) : 0;
    $existingNickname = $existing ? ($existing['nickname'] ?? null) : null;

    $userName = getLatestUserName($userId) ?? ($existing['user_name'] ?? "utente_$userId");
    $log("[updateUserNickname] user_id=$userId name=$userName since_ts=$sinceTs");

    $result = getUnprocessedNicknameMessages($userId, $userName, $sinceTs);
    $messages = $result['messages'];
    $scannedUntilTs = $result['scanned_until_ts'];

    if (empty($messages)) {
        $log("[updateUserNickname] nessun nuovo messaggio con menzione");
        if ($scannedUntilTs > $sinceTs) {
            saveUserNickname($userId, $existingNickname ?? '', $scannedUntilTs);
        }
        return ['status' => 'no_messages', 'detail' => 'Nessuna nuova menzione da altri utenti', 'nickname' => $existingNickname];
    }

    if (!$existingNickname && count($messages) < USER_MEMORY_MIN_NICK_MESSAGES) {
        $log("[updateUserNickname] solo " . count($messages) . " menzioni (soglia: " . USER_MEMORY_MIN_NICK_MESSAGES . "), salto");
        return ['status' => 'below_threshold', 'detail' => "Solo " . count($messages) . " menzioni da altri utenti", 'nickname' => null];
    }

    $log("[updateUserNickname] " . count($messages) . " menzioni da analizzare");

    $prompt = buildNicknameExtractionPrompt($userName, $existingNickname, $messages);

    // Modello light: è un'analisi semplice, pattern matching sui nomi
    $requestData = [
        'model'   => OLLAMA_MODEL_LIGHT,
        'prompt'  => $prompt,
        'options' => ollamaOptions(true, ['temperature' => 0.2, 'num_ctx' => 8192]),
    ];

    $log("[updateUserNickname] chiamata Ollama (model=" . OLLAMA_MODEL_LIGHT . ", priority=LAZY)...");
    $response = callOllamaViaQBert($requestData, QBertClient::PRIORITY_LAZY);

    if (!$response || empty($response['response'])) {
        $log("[updateUserNickname] ERRORE: risposta Ollama vuota");
        return ['status' => 'error', 'detail' => 'Risposta Ollama vuota o nulla', 'nickname' => $existingNickname];
    }

    $newNickname = stripThinkingTags($response['response']);
    $newNickname = trim($newNickname);

    if (mb_strlen($newNickname) > USER_MEMORY_MAX_NICKNAME_LENGTH * 1.5) {
        $newNickname = mb_substr($newNickname, 0, USER_MEMORY_MAX_NICKNAME_LENGTH) . '...';
    }

    saveUserNickname($userId, $newNickname, $scannedUntilTs);
    $log("[updateUserNickname] nickname aggiornato (" . mb_strlen($newNickname) . " char), scanned_until_ts=$scannedUntilTs");

    return ['status' => 'updated', 'detail' => 'Nickname aggiornato', 'nickname' => $newNickname];
}

/**
 * Versione "exhaust" di updateUserNickname.
 */
function updateUserNicknameExhaust($userId, $verbose = false, $maxIterations = 50) {
    $iterations = 0;
    $lastResult = null;

    while ($iterations < $maxIterations) {
        $iterations++;
        if ($verbose) {
            echo "\n--- Nickname iterazione $iterations ---\n";
            @ob_flush(); @flush();
        }

        $lastResult = updateUserNickname($userId, $verbose);

        if ($lastResult['status'] === 'updated') {
            continue;
        }
        break;
    }

    if ($iterations >= $maxIterations) {
        return [
            'status'     => 'max_iter',
            'iterations' => $iterations,
            'detail'     => "Raggiunto limite massimo iterazioni ($maxIterations)",
            'nickname'   => $lastResult['nickname'] ?? null,
        ];
    }

    return [
        'status'     => $lastResult['status'] === 'no_messages' ? 'completed' : $lastResult['status'],
        'iterations' => $iterations,
        'detail'     => $lastResult['detail'],
        'nickname'   => $lastResult['nickname'],
    ];
}

/**
 * Conta i messaggi per tipo per un utente: generici, bot, nickname (menzioni da altri).
 * Utile per la dashboard admin.
 */
function countUserMessagesByType($userId) {
    global $db;

    $userName = getLatestUserName($userId) ?? "utente_$userId";
    $minLen = USER_MEMORY_MIN_MSG_LENGTH;

    // Totali generici (tutti i messaggi dell'utente)
    $total = countUserMessages($userId);

    // Messaggi con interazione bot (dell'utente): menzioni esplicite OR reply al bot.
    // Esclude comandi slash (/quiz, /ordino, ...) perché non significativi del tono.
    // Nessun filtro di lunghezza: i messaggi brevi rivelano il tono con cui ci si rivolge al bot.
    $botUserId = getBotUserId();
    $botPatterns = ['@rootbotbot', '@rootbot', '@root', '@bot', 'rootbotbot', 'rootbot'];
    $stmt = $db->prepare("
        SELECT message_text, reply_to_user_id FROM (
            SELECT message_text, reply_to_user_id FROM contesto_chat WHERE user_id = :uid
            UNION ALL
            SELECT message_text, reply_to_user_id FROM storico_messaggi WHERE user_id = :uid
        )
    ");
    $stmt->bindValue(':uid', $userId, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $botCount = 0;
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $text = (string)$row['message_text'];
        $trimmed = ltrim($text);
        if ($trimmed === '' || $trimmed[0] === '/') continue;
        if ($botUserId > 0 && (int)$row['reply_to_user_id'] === $botUserId) {
            $botCount++;
            continue;
        }
        $lower = mb_strtolower($text);
        foreach ($botPatterns as $p) {
            if (strpos($lower, $p) !== false) {
                $botCount++;
                break;
            }
        }
    }

    // Messaggi di altri che si rivolgono all'utente (reply diretti + menzioni per nome)
    // 1. Reply diretti
    $stmt = $db->prepare("
        SELECT
            (SELECT COUNT(*) FROM contesto_chat
             WHERE user_id IS NOT NULL AND user_id != :uid
               AND reply_to_user_id = :uid AND length(message_text) >= :min_len)
            +
            (SELECT COUNT(*) FROM storico_messaggi
             WHERE user_id IS NOT NULL AND user_id != :uid
               AND reply_to_user_id = :uid AND length(message_text) >= :min_len)
        AS c
    ");
    $stmt->bindValue(':uid', $userId, SQLITE3_INTEGER);
    $stmt->bindValue(':min_len', $minLen, SQLITE3_INTEGER);
    $replyCount = (int)$stmt->execute()->fetchArray(SQLITE3_ASSOC)['c'];

    // 2. Menzioni per nome (esclusi quelli già contati come reply)
    $nameParts = preg_split('/\s+/', trim($userName));
    $firstName = mb_strtolower($nameParts[0] ?? $userName);
    $fullName = mb_strtolower($userName);

    $stmt = $db->prepare("
        SELECT message_text FROM (
            SELECT message_text FROM contesto_chat
            WHERE user_id IS NOT NULL AND user_id != :uid
              AND (reply_to_user_id IS NULL OR reply_to_user_id != :uid)
              AND length(message_text) >= :min_len
            UNION ALL
            SELECT message_text FROM storico_messaggi
            WHERE user_id IS NOT NULL AND user_id != :uid
              AND (reply_to_user_id IS NULL OR reply_to_user_id != :uid)
              AND length(message_text) >= :min_len
        )
    ");
    $stmt->bindValue(':uid', $userId, SQLITE3_INTEGER);
    $stmt->bindValue(':min_len', $minLen, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $nickCount = $replyCount;
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $lower = mb_strtolower($row['message_text']);
        if (strpos($lower, $firstName) !== false || strpos($lower, $fullName) !== false) {
            $nickCount++;
        }
    }

    return [
        'total' => $total,
        'bot'   => $botCount,
        'nick'  => $nickCount,
    ];
}

/**
 * Esegue un passo di estrazione/aggiornamento profilo per un utente.
 *
 * @param int $userId
 * @param bool $verbose Se true, stampa info di debug su stdout (utile da CLI)
 * @return array ['status' => 'updated'|'skipped'|'no_messages'|'below_threshold'|'error', 'detail' => string, 'profilo' => string|null]
 */
function updateUserMemory($userId, $verbose = false, $useGpu = false) {
    $log = function($msg) use ($verbose) {
        if ($verbose) { echo $msg . "\n"; @ob_flush(); @flush(); }
    };

    $existing = getUserMemoryProfile($userId);
    // Cursore = unix timestamp dell'ultimo messaggio già processato
    $sinceTs = $existing ? (int)$existing['last_processed_msg_id'] : 0;
    $existingProfile = $existing ? $existing['profilo'] : null;

    $userName = getLatestUserName($userId) ?? ($existing['user_name'] ?? "utente_$userId");
    $log("[updateUserMemory] user_id=$userId name=$userName since_ts=$sinceTs gpu=" . ($useGpu ? 'SI' : 'NO'));

    // Se non c'è un profilo, applica soglia minima sui messaggi totali
    if (!$existing) {
        $totalMsgs = countUserMessages($userId);
        if ($totalMsgs < USER_MEMORY_MIN_MESSAGES) {
            $log("[updateUserMemory] solo $totalMsgs messaggi utili (soglia: " . USER_MEMORY_MIN_MESSAGES . "), salto");
            return ['status' => 'below_threshold', 'detail' => "Solo $totalMsgs messaggi utili", 'profilo' => null];
        }
    }

    $messages = getUnprocessedUserMessages($userId, $sinceTs);
    if (empty($messages)) {
        $log("[updateUserMemory] nessun nuovo messaggio");
        return ['status' => 'no_messages', 'detail' => 'Nessun nuovo messaggio da processare', 'profilo' => $existingProfile];
    }

    $log("[updateUserMemory] " . count($messages) . " nuovi messaggi da processare");

    $prompt = buildMemoryExtractionPrompt($userName, $existingProfile, $messages);

    // Profilo: sempre modello light (veloce, sono tanti messaggi generici)
    $requestData = [
        'model'   => OLLAMA_MODEL_LIGHT,
        'prompt'  => $prompt,
        'options' => ollamaOptions(true, ['temperature' => 0.3, 'num_ctx' => 8192]),
    ];

    $log("[updateUserMemory] chiamata Ollama (model=" . OLLAMA_MODEL_LIGHT . ", priority=LAZY)...");
    $response = callOllamaViaQBert($requestData, QBertClient::PRIORITY_LAZY);

    if (!$response || empty($response['response'])) {
        $log("[updateUserMemory] ERRORE: risposta Ollama vuota");
        return ['status' => 'error', 'detail' => 'Risposta Ollama vuota o nulla', 'profilo' => $existingProfile];
    }

    $newProfile = stripThinkingTags($response['response']);
    $newProfile = trim($newProfile);

    // Tronca difensivamente se il modello sfora
    if (mb_strlen($newProfile) > USER_MEMORY_MAX_PROFILE_LENGTH * 1.5) {
        $newProfile = mb_substr($newProfile, 0, USER_MEMORY_MAX_PROFILE_LENGTH) . '...';
    }

    $lastTs = (int)$messages[count($messages) - 1]['timestamp'];
    $totalCount = ($existing ? (int)$existing['message_count'] : 0) + count($messages);

    saveUserMemoryProfile($userId, $userName, $newProfile, $lastTs, $totalCount);
    $log("[updateUserMemory] profilo aggiornato (" . mb_strlen($newProfile) . " char), last_processed_ts=$lastTs");

    return ['status' => 'updated', 'detail' => 'Profilo aggiornato', 'profilo' => $newProfile];
}

/**
 * Esegue un passo di estrazione/aggiornamento istruzioni bot per un utente.
 * Processa solo messaggi che contengono interazioni con il bot.
 */
function updateUserBotPrompt($userId, $verbose = false, $useGpu = false) {
    $log = function($msg) use ($verbose) {
        if ($verbose) { echo $msg . "\n"; @ob_flush(); @flush(); }
    };

    $existing = getUserMemoryProfile($userId);
    $sinceTs = $existing ? (int)($existing['last_processed_bot_id'] ?? 0) : 0;
    $existingBotPrompt = $existing ? ($existing['bot_prompt'] ?? null) : null;

    $userName = getLatestUserName($userId) ?? ($existing['user_name'] ?? "utente_$userId");
    $log("[updateUserBotPrompt] user_id=$userId name=$userName since_ts=$sinceTs");

    $result = getUnprocessedBotMessages($userId, $sinceTs);
    $messages = $result['messages'];
    $scannedUntilTs = $result['scanned_until_ts'];

    if (empty($messages)) {
        $log("[updateUserBotPrompt] nessuna nuova interazione col bot");
        // Avanza il cursore comunque per non riscansionare gli stessi messaggi
        if ($scannedUntilTs > $sinceTs) {
            saveUserBotPrompt($userId, $existingBotPrompt ?? '', $scannedUntilTs);
        }
        return ['status' => 'no_messages', 'detail' => 'Nessuna nuova interazione col bot', 'bot_prompt' => $existingBotPrompt];
    }

    if (!$existingBotPrompt && count($messages) < USER_MEMORY_MIN_BOT_MESSAGES) {
        $log("[updateUserBotPrompt] solo " . count($messages) . " interazioni bot (soglia: " . USER_MEMORY_MIN_BOT_MESSAGES . "), salto");
        return ['status' => 'below_threshold', 'detail' => "Solo " . count($messages) . " interazioni col bot", 'bot_prompt' => null];
    }

    $log("[updateUserBotPrompt] " . count($messages) . " interazioni bot da analizzare");

    $prompt = buildBotPromptExtractionPrompt($userName, $existingBotPrompt, $messages);

    // Bot prompt: sempre modello full con thinking (pochi messaggi, serve ragionamento profondo)
    $requestData = [
        'model'   => OLLAMA_MODEL,
        'prompt'  => $prompt,
        'options' => ollamaOptions(true, ['temperature' => 0.4, 'num_ctx' => 8192]),
    ];

    $log("[updateUserBotPrompt] chiamata Ollama (model=" . OLLAMA_MODEL . " thinking, priority=LAZY)...");
    $response = callOllamaViaQBert($requestData, QBertClient::PRIORITY_LAZY);

    if (!$response || empty($response['response'])) {
        $log("[updateUserBotPrompt] ERRORE: risposta Ollama vuota");
        return ['status' => 'error', 'detail' => 'Risposta Ollama vuota o nulla', 'bot_prompt' => $existingBotPrompt];
    }

    $newBotPrompt = stripThinkingTags($response['response']);
    $newBotPrompt = trim($newBotPrompt);

    if (mb_strlen($newBotPrompt) > USER_MEMORY_MAX_BOT_PROMPT_LENGTH * 1.5) {
        $newBotPrompt = mb_substr($newBotPrompt, 0, USER_MEMORY_MAX_BOT_PROMPT_LENGTH) . '...';
    }

    saveUserBotPrompt($userId, $newBotPrompt, $scannedUntilTs);
    $log("[updateUserBotPrompt] istruzioni bot aggiornate (" . mb_strlen($newBotPrompt) . " char), scanned_until_ts=$scannedUntilTs");

    return ['status' => 'updated', 'detail' => 'Istruzioni bot aggiornate', 'bot_prompt' => $newBotPrompt];
}

/**
 * Versione "exhaust" di updateUserBotPrompt.
 */
function updateUserBotPromptExhaust($userId, $verbose = false, $useGpu = false, $maxIterations = 50) {
    $iterations = 0;
    $lastResult = null;

    while ($iterations < $maxIterations) {
        $iterations++;
        if ($verbose) {
            echo "\n--- Bot prompt iterazione $iterations ---\n";
            @ob_flush(); @flush();
        }

        $lastResult = updateUserBotPrompt($userId, $verbose, $useGpu);

        if ($lastResult['status'] === 'updated') {
            continue;
        }
        break;
    }

    if ($iterations >= $maxIterations) {
        return [
            'status'     => 'max_iter',
            'iterations' => $iterations,
            'detail'     => "Raggiunto limite massimo iterazioni ($maxIterations)",
            'bot_prompt' => $lastResult['bot_prompt'] ?? null,
        ];
    }

    return [
        'status'     => $lastResult['status'] === 'no_messages' ? 'completed' : $lastResult['status'],
        'iterations' => $iterations,
        'detail'     => $lastResult['detail'],
        'bot_prompt' => $lastResult['bot_prompt'],
    ];
}

/**
 * Calcola statistiche di attività di un utente in tempo reale (non vengono salvate in memorie_utenti
 * perché decadono in fretta). Pensata per essere chiamata dal bot al momento di rispondere all'utente,
 * così da iniettare dati freschi nel system prompt insieme al profilo statico.
 *
 * @return array {
 *     total_messages: int totale messaggi (contesto_chat + storico_messaggi)
 *     last_seen_ts: int|null unix timestamp dell'ultimo messaggio visto
 *     days_since_last_seen: int|null
 *     msgs_last_7d: int messaggi negli ultimi 7 giorni
 *     msgs_last_30d: int messaggi negli ultimi 30 giorni
 *     activity_label: string descrizione narrativa pronta per il prompt
 *                     ("scrive spesso", "raramente", "torna dopo lunga assenza", ecc.)
 * }
 */
function getUserActivityStats($userId) {
    global $db;

    $now = time();
    $sevenDays = $now - (7 * 86400);
    $thirtyDays = $now - (30 * 86400);

    // Conteggi, primo e ultimo timestamp via UNION ALL
    $stmt = $db->prepare("
        SELECT
            COUNT(*) as total,
            MIN(timestamp) as first_ts,
            MAX(timestamp) as last_ts,
            SUM(CASE WHEN timestamp >= :seven THEN 1 ELSE 0 END) as last_7d,
            SUM(CASE WHEN timestamp >= :thirty THEN 1 ELSE 0 END) as last_30d
        FROM (
            SELECT timestamp FROM contesto_chat WHERE user_id = :user_id
            UNION ALL
            SELECT timestamp FROM storico_messaggi WHERE user_id = :user_id
        )
    ");
    $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
    $stmt->bindValue(':seven', $sevenDays, SQLITE3_INTEGER);
    $stmt->bindValue(':thirty', $thirtyDays, SQLITE3_INTEGER);
    $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);

    $total = (int)($row['total'] ?? 0);
    $firstTs = $row['first_ts'] ? (int)$row['first_ts'] : null;
    $lastTs = $row['last_ts'] ? (int)$row['last_ts'] : null;
    $msgs7 = (int)($row['last_7d'] ?? 0);
    $msgs30 = (int)($row['last_30d'] ?? 0);

    $daysSince = $lastTs ? (int)floor(($now - $lastTs) / 86400) : null;
    $daysActive = $firstTs ? (int)floor(($now - $firstTs) / 86400) : null;

    // Etichetta narrativa: combina "freschezza" e "frequenza"
    $label = '';
    if ($total === 0) {
        $label = "nessun messaggio registrato";
    } elseif ($daysSince !== null && $daysSince > 60) {
        $label = "non si fa sentire nel gruppo da {$daysSince} giorni — è il momento di un \"oh chi si rivede!\"";
    } elseif ($daysSince !== null && $daysSince > 21) {
        $label = "è da circa {$daysSince} giorni che non scrive nel gruppo";
    } elseif ($msgs7 >= 30) {
        $label = "è molto attivo nel gruppo (oltre $msgs7 messaggi nell'ultima settimana)";
    } elseif ($msgs7 >= 5) {
        $label = "scrive con regolarità (circa $msgs7 messaggi nell'ultima settimana)";
    } elseif ($msgs30 >= 5) {
        $label = "scrive saltuariamente ($msgs30 messaggi nell'ultimo mese)";
    } else {
        $label = "partecipa raramente al gruppo";
    }

    // Etichetta "anzianità": veterano vs novellino, basata sui giorni di permanenza nel gruppo
    // (computati come distanza tra il primo messaggio visto e oggi).
    $seniorityLabel = '';
    if ($daysActive === null) {
        $seniorityLabel = "presenza nel gruppo sconosciuta";
    } elseif ($daysActive < 14) {
        $seniorityLabel = "novellino del gruppo (qui da meno di due settimane)";
    } elseif ($daysActive < 60) {
        $seniorityLabel = "arrivato da poco nel gruppo (qui da circa " . round($daysActive / 7) . " settimane)";
    } elseif ($daysActive < 365) {
        $seniorityLabel = "frequenta il gruppo da qualche mese (circa " . round($daysActive / 30) . " mesi)";
    } elseif ($daysActive < 365 * 3) {
        $years = round($daysActive / 365, 1);
        $seniorityLabel = "membro consolidato del gruppo (qui da circa $years anni)";
    } else {
        $years = round($daysActive / 365);
        $seniorityLabel = "veterano del gruppo (qui da oltre $years anni)";
    }

    return [
        'total_messages'       => $total,
        'first_seen_ts'        => $firstTs,
        'days_active'          => $daysActive,
        'last_seen_ts'         => $lastTs,
        'days_since_last_seen' => $daysSince,
        'msgs_last_7d'         => $msgs7,
        'msgs_last_30d'        => $msgs30,
        'activity_label'       => $label,
        'seniority_label'      => $seniorityLabel,
    ];
}

/**
 * Versione "exhaust" di updateUserMemory: rilancia in loop finché ci sono nuovi messaggi
 * da processare. Pensata per il bootstrap iniziale di un utente con molti messaggi storici.
 *
 * @param int $userId
 * @param bool $verbose
 * @param bool $useGpu
 * @param int $maxIterations safety net per evitare loop infiniti
 * @return array ['status', 'iterations', 'detail', 'profilo']
 */
function updateUserMemoryExhaust($userId, $verbose = false, $useGpu = false, $maxIterations = 50) {
    $iterations = 0;
    $lastResult = null;

    while ($iterations < $maxIterations) {
        $iterations++;
        if ($verbose) {
            echo "\n--- Iterazione $iterations ---\n";
            @ob_flush(); @flush();
        }

        $lastResult = updateUserMemory($userId, $verbose, $useGpu);

        if ($lastResult['status'] === 'updated') {
            // C'è ancora roba da processare nel prossimo giro
            continue;
        }
        // no_messages, below_threshold, error → stop
        break;
    }

    if ($iterations >= $maxIterations) {
        return [
            'status'     => 'max_iter',
            'iterations' => $iterations,
            'detail'     => "Raggiunto limite massimo iterazioni ($maxIterations)",
            'profilo'    => $lastResult['profilo'] ?? null,
        ];
    }

    return [
        'status'     => $lastResult['status'] === 'no_messages' ? 'completed' : $lastResult['status'],
        'iterations' => $iterations,
        'detail'     => $lastResult['detail'],
        'profilo'    => $lastResult['profilo'],
    ];
}

/**
 * Elenca tutti gli user_id distinti con almeno N messaggi utili,
 * sommando contesto_chat + storico_messaggi.
 */
function listKnownUserIds($minMessages = USER_MEMORY_MIN_MESSAGES) {
    global $db;
    $stmt = $db->prepare("
        SELECT user_id, MAX(user_name) as user_name, COUNT(*) as c
        FROM (
            SELECT user_id, user_name FROM contesto_chat
            WHERE user_id IS NOT NULL AND length(message_text) >= :min_len
            UNION ALL
            SELECT user_id, user_name FROM storico_messaggi
            WHERE user_id IS NOT NULL AND length(message_text) >= :min_len
        )
        GROUP BY user_id
        HAVING c >= :min_msgs
        ORDER BY c DESC
    ");
    $stmt->bindValue(':min_len', USER_MEMORY_MIN_MSG_LENGTH, SQLITE3_INTEGER);
    $stmt->bindValue(':min_msgs', $minMessages, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $users = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $users[] = $row;
    }
    return $users;
}
