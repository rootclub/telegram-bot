<?php
/////////////////////////////////////////////////////////////////
//////////////////// MEMORIA PER UTENTE /////////////////////////
////////////////////////////////////////////////////////////////

require_once __DIR__ . '/ai.php';

// Soglia minima di messaggi "utili" prima di generare un profilo
define('USER_MEMORY_MIN_MESSAGES', 30);
// Lunghezza minima di un messaggio per essere considerato "utile" (esclude "ahah", "ok", emoji)
define('USER_MEMORY_MIN_MSG_LENGTH', 8);
// Numero massimo di messaggi da inviare a Ollama in una singola estrazione
define('USER_MEMORY_BATCH_SIZE', 80);
// Lunghezza massima del profilo (caratteri)
define('USER_MEMORY_MAX_PROFILE_LENGTH', 1500);

/**
 * Legge il profilo memoria di un utente.
 * @return array|null ['profilo', 'message_count', 'last_processed_msg_id', 'user_name', 'last_updated'] o null
 */
function getUserMemoryProfile($userId) {
    global $db;
    $stmt = $db->prepare("SELECT user_name, profilo, message_count, last_processed_msg_id, last_updated FROM memorie_utenti WHERE user_id = :user_id");
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
 * Verifica se un batch di messaggi contiene riferimenti al bot.
 * Usato per decidere a livello di prompt se includere o meno la sezione "relazione col bot".
 */
function messagesContainBotReference($messages) {
    $patterns = ['@rootbotbot', '@rootbot', '@root', '@bot', 'rootbotbot', 'rootbot'];
    foreach ($messages as $m) {
        $lower = mb_strtolower($m['message_text']);
        foreach ($patterns as $p) {
            if (strpos($lower, $p) !== false) return true;
        }
    }
    return false;
}

/**
 * Costruisce il prompt per Ollama: chiede di aggiornare un profilo utente
 * integrando le info dei nuovi messaggi nel profilo esistente.
 *
 * Il profilo è costruito su DUE assi:
 *  - tratti stabili (passioni, competenze, modo di esprimersi, relazione col bot)
 *  - NON include dati temporali (frequenza, ultimo accesso) che vengono calcolati runtime
 *
 * La sezione "relazione col bot" è inclusa nel prompt SOLO se nel batch ci sono effettivamente
 * riferimenti al bot. Altrimenti viene esclusa programmaticamente — il modello non vede nemmeno
 * la richiesta — per evitare allucinazioni su batch di periodi in cui il bot non esisteva.
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

    $hasBotRefs = messagesContainBotReference($messages);

    // Sezione bot: inclusa solo se ci sono riferimenti reali nel batch
    $botSection = '';
    $botRule = '';
    if ($hasBotRefs) {
        $botSection = "\n3. **Relazione con il bot (rootbot)**: come si comporta quando interagisce con lui? Lo stuzzica, lo prende in giro, gli fa domande serie, lo ignora, lo provoca con supercazzole? Come dovrebbe il bot rispondergli per essere efficace?";
    } else {
        $botRule = "\n- I messaggi di questo batch NON contengono alcun riferimento al bot rootbot. NON scrivere nulla sulla relazione tra l'utente e il bot. Se il profilo attuale già contiene info sulla relazione col bot, mantienile invariate ma NON modificarle né aggiungerne di nuove.";
    }

    return <<<PROMPT
Sei un assistente che costruisce schede personaggio di utenti di un gruppo Telegram. L'obiettivo è permettere al bot del gruppo (rootbot) di adattare il proprio stile comunicativo a ogni utente. Ti basi SOLO sui loro messaggi reali.

{$existingBlock}NUOVI MESSAGGI di $userName da analizzare:
$msgBlock

COSA PUOI INCLUDERE NEL PROFILO (solo se trovi evidenze concrete):
1. **Passioni e interessi**: hobby, competenze tecniche, professione, ambiti di expertise, argomenti su cui si accende.
2. **Personalità e modo di esprimersi**: tratti caratteriali ricorrenti, ironia, sarcasmo, registro linguistico, tic verbali, modi di dire tipici.{$botSection}
4. **Relazioni con altri membri del gruppo**: dinamiche ricorrenti, amicizie, rivalità scherzose, ruoli sociali.
5. **Posizioni e abitudini**: opinioni ricorrenti, abitudini quotidiane, città, lavoro — solo se esplicitamente menzionati.

REGOLE FERREE (anti-allucinazione):
- Aggiorna il profilo INTEGRANDO le info nuove con quelle esistenti. NON cancellare info pregresse salvo contraddizione esplicita.
- Riporta SOLO fatti che emergono chiaramente dai messaggi forniti. Se una sezione non ha evidenze nei messaggi, OMETTILA completamente: meglio un profilo corto e vero di uno lungo e inventato.{$botRule}
- NON inventare. NON estrapolare. NON usare frasi vaghe tipo "sembra essere", "potrebbe", "tende a" per riempire spazio.
- NON includere dati temporali specifici (date, "scrive da X", "è da Y che non si vede") — questi verranno aggiunti runtime dal bot.
- Scrivi in italiano, in terza persona, in 1-2 paragrafi discorsivi densi (NO elenchi puntati).
- Massimo $maxLen caratteri totali. Sii denso, evita ripetizioni e frasi di contorno. È OK essere molto sotto il limite.
- NON includere preamboli tipo "Ecco il profilo aggiornato:". Rispondi direttamente con il testo del profilo, niente altro.

PROFILO AGGIORNATO di $userName:
PROMPT;
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

    $model = $useGpu ? OLLAMA_MODEL : OLLAMA_MODEL_LIGHT;
    $modelGpu = $useGpu ? OLLAMA_MODEL_GPU : OLLAMA_MODEL_LIGHT_GPU;

    $requestData = [
        'model'   => $model,
        'prompt'  => $prompt,
        'options' => ollamaOptions($modelGpu, ['temperature' => 0.3]),
    ];

    $log("[updateUserMemory] chiamata Ollama (model=$model)...");
    $response = callOllamaViaQBert($requestData);

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
