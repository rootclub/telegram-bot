<?php

/**
 * Controlla se un messaggio è un duplicato recente dallo stesso utente.
 * @param int $userId ID utente
 * @param string $text Testo del messaggio
 * @param int $windowSeconds Finestra temporale in secondi (default 60)
 * @return bool true se è un duplicato, false altrimenti
 */
function isDuplicateMessage($userId, $text, $windowSeconds = 60) {
    global $db;

    $messageHash = md5($text);
    $cutoff = time() - $windowSeconds;

    $stmt = $db->prepare("SELECT COUNT(*) as cnt FROM message_dedup
                          WHERE user_id = :user_id
                          AND message_hash = :hash
                          AND timestamp > :cutoff");
    $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
    $stmt->bindValue(':hash', $messageHash, SQLITE3_TEXT);
    $stmt->bindValue(':cutoff', $cutoff, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $row = $result->fetchArray(SQLITE3_ASSOC);

    return ($row['cnt'] > 0);
}

/**
 * Registra un messaggio per il controllo duplicati.
 */
function recordMessage($userId, $text) {
    global $db;

    $messageHash = md5($text);

    $stmt = $db->prepare("INSERT INTO message_dedup (user_id, message_hash, timestamp)
                          VALUES (:user_id, :hash, :timestamp)");
    $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
    $stmt->bindValue(':hash', $messageHash, SQLITE3_TEXT);
    $stmt->bindValue(':timestamp', time(), SQLITE3_INTEGER);
    $stmt->execute();
}

function sendPrivateResponse($userId, $text, $chatId = null) {
    $privateChat = makeAPIRequest('sendMessage', [
        'chat_id' => $userId,
        'text' => $text
    ]);

    if (!$privateChat || !$privateChat['ok']) {
        // Log dell'errore per debug
        error_log("Failed to send private message to user $userId: " . json_encode($privateChat));
        
        // Se l'invio del messaggio privato fallisce e abbiamo l'ID del gruppo, informiamo l'utente nel gruppo
        if ($chatId) {
            $groupMessage = makeAPIRequest('sendMessage', [
                'chat_id' => $chatId,
                'text' => "Non sono riuscito a inviarti un messaggio privato. Per favore, avvia una chat con me cliccando su @rootbotbot e poi su 'Avvia', quindi riprova."
            ]);
            
            if (!$groupMessage || !$groupMessage['ok']) {
                error_log("Failed to send group message to chat $chatId: " . json_encode($groupMessage));
            }
        }
        return false;
    }
    return true;
}

function processMessage($message) {
    $chatID = $message['chat']['id'];
    $text = $message['text'] ?? '';
    $caption = $message['caption'] ?? '';  // Caption per immagini/documenti
    $chatType = $message['chat']['type'];
    $fromId = $message['from']['id'];
    $firstName = $message['from']['first_name'] ?? 'Utente';
    $response = null;

    // Filtro anti-spam: ignora messaggi duplicati dallo stesso utente
    if (!empty($text) && isDuplicateMessage($fromId, $text)) {
        error_log("Messaggio duplicato ignorato da utente $fromId: " . substr($text, 0, 50));
        return;
    }
    // Registra il messaggio per futuri controlli duplicati
    if (!empty($text)) {
        recordMessage($fromId, $text);
    }

    // Pulisci stati utente scaduti (timeout 10 minuti)
    cleanupExpiredUserStates();

    // Controlla se è un reply a un messaggio del bot
    $isReplyToBot = false;
    if (isset($message['reply_to_message']['from']['username']) &&
        $message['reply_to_message']['from']['username'] === 'rootbotbot') {
        $isReplyToBot = true;
    }

    // Gestione input multi-step per eventi
    $eventResponse = handleEventInput($message);
    if ($eventResponse !== null) {
        $result = makeAPIRequest('sendMessage', [
            'chat_id' => $chatID,
            'text' => $eventResponse,
            'parse_mode' => 'HTML',
            'reply_to_message_id' => $message['message_id']
        ]);
        // Se il reply fallisce, riprova senza reply
        if (!$result || !$result['ok']) {
            if (isset($result['error_code']) && $result['error_code'] == 400 &&
                strpos($result['description'], 'message to be replied not found') !== false) {
                makeAPIRequest('sendMessage', [
                    'chat_id' => $chatID,
                    'text' => $eventResponse,
                    'parse_mode' => 'HTML'
                ]);
            }
        }
        return;
    }

    //$profanity_response = handle_profanity($message);
    $profanity_response = null;
    if ($profanity_response !== null) {
        $response = $profanity_response;
        
    } elseif ($text == '/stats' || $text == '/stats@rootbotbot') {
        $response = getProfanityStats();
        
    } elseif (is_porto_al_root($text)) {
        $response = handle_porto_al_root($message);
        
    }elseif ($text == '/start' || $text == '/start@rootbotbot') {
        $response = _start($chatType);
        
    } elseif ($text == '/help' || $text == '/help@rootbotbot' || $text == '/?'  | $text == '/comandi' || $text == '/aiuto') {
        $response = _help();
        
    } elseif ($text == '/info' || $text == '/info@rootbotbot') {
        $response = _info($chatID, $chatType);
        
    } elseif ($text == '/regole' || $text == '/regole@rootbotbot') {
        $response = _regole();
        
    } elseif (strpos($text, '/mangerebbe') === 0) {
        $userName = $message['from']['first_name'] . ' ' . ($message['from']['last_name'] ?? '');
        $response = _mangerebbe($text, $fromId, $userName, $chatID, $message);

    } elseif (strpos($text, '/mangerei') === 0 || strpos($text, '/mangarei') === 0 || strpos($text, '/mangierei') === 0 ) {
        $userName = $message['from']['first_name'] . ' ' . ($message['from']['last_name'] ?? '');
        $response = _mangerei($text, $fromId, $userName, $chatID);
        
    } elseif ($text == '/lista' || $text == '/lista@rootbotbot') {
        $response = _lista();
        
    } elseif ($text == '/ordino' || $text == '/ordino@rootbotbot') {
        $userName = $message['from']['first_name'] . ' ' . ($message['from']['last_name'] ?? '');
        $response = _ordino($fromId, $userName);
        
    } elseif (strpos($text, '/ordina') === 0) {
        $response = _ordina($text, $chatID);
        
    } elseif ($text == '/ritiro' || $text == '/ritiro@rootbotbot') {
        $userName = $message['from']['first_name'] . ' ' . ($message['from']['last_name'] ?? '');
        $response = _ritiro($fromId, $userName);
        
    } elseif (strpos($text, '/ritira') === 0) {
        $response = _ritira($text, $chatID);
        
    }elseif ($text == '/elenco_asporto' || $text == '/elenco_asporto@rootbotbot') {
    	$response = elenco_pappatoie($chatID);
    	
    } elseif ($text == '/asporto' || $text == '/asporto@rootbotbot') {
        $response = _pappatoia($chatID);
        
    } elseif (strpos($text, '/nuovo_asporto') === 0) {
        $response = nuova_pappatoia($chatID, $message['message_id'], $text);
        
    } elseif (strpos($text, '/menu') === 0) {
            $response = menu($text, $chatID, $message['from']['id'], $message['message_id']);
    
    }elseif ($text == '/nuovo_menu' || $text == '/nuovo_menu@rootbotbot') {
        $response = nuovo_menu($chatID, $fromId);
        
    } elseif ($text == '/fine' || $text == '/fine@rootbotbot') {
        $response = finish_adding_images($chatID);

    } elseif ($text == '/evento' || $text == '/evento@rootbotbot') {
        $userName = $message['from']['first_name'] . ' ' . ($message['from']['last_name'] ?? '');
        $response = _evento($chatID, $fromId, $userName, $chatType);

    } elseif ($text == '/partecipo' || $text == '/partecipo@rootbotbot') {
        $userName = $message['from']['first_name'] . ' ' . ($message['from']['last_name'] ?? '');
        $response = _partecipo($chatID, $fromId, $userName);

    } elseif ($text == '/partecipanti' || $text == '/partecipanti@rootbotbot') {
        $response = _partecipanti($chatID);

    } elseif ($text == '/modifica_evento' || $text == '/modifica_evento@rootbotbot') {
        $response = _modifica_evento($chatID, $fromId, $chatType);

    } elseif ($text == '/chiudi_evento' || $text == '/chiudi_evento@rootbotbot') {
        $response = _chiudi_evento($chatID, $fromId, $chatType);

    } elseif (strpos($text, '/ospite') === 0) {
        $userName = $message['from']['first_name'] . ' ' . ($message['from']['last_name'] ?? '');
        $response = _ospite($text, $chatID, $fromId, $userName);

    } elseif ($text == '/annullo_ospite' || $text == '/annullo_ospite@rootbotbot') {
        $userName = $message['from']['first_name'] . ' ' . ($message['from']['last_name'] ?? '');
        $response = _annullo_ospite($chatID, $fromId, $userName);

    } elseif ($text == '/annullo' || $text == '/annullo@rootbotbot') {
        $userName = $message['from']['first_name'] . ' ' . ($message['from']['last_name'] ?? '');
        $response = _annullo_smart($chatID, $fromId, $userName);
        
    } elseif (isset($message['photo'])) {
        // Le immagini con menzione/privato sono già gestite in bot.php (invio descrizione)
        // Qui gestiamo solo il caso normale (es. salvataggio per pappatoie)
        $response = handle_image($message);

    } elseif (isset($message['document']) && isImageDocument($message['document'])) {
        // L'utente ha inviato un'immagine come file (senza compressione)
        // La risposta con descrizione è gestita in bot.php
        $response = handleDocumentImage($message);

    } elseif (isset($message['document']) && !isImageDocument($message['document'])) {
        // L'utente ha inviato un file non-immagine (PDF, ecc.)
        $response = handleNonImageDocument($message);

    } elseif ($text == '/elimina_asporto') {
        $response = elimina_pappatoia($chatID, $fromId);
        
    } elseif (preg_match('/@root\b/', $text) || preg_match('/@bot\b/', $text) || preg_match('/@rootbot\b/', $text) || preg_match('/\brootbot\b/i', $text) || preg_match('/\brotbotbot\b/i', $text) || $isReplyToBot || ($chatType == 'private' && !empty($text) && !preg_match('/^\//', $text))) {
        // In chat privata risponde sempre (tranne comandi), in gruppo solo se menzionato
        $response = _ai($chatID, $chatType, $text);

    } elseif (preg_match('/^\/saluto(?:@rootbotbot)?(?:\s+-(\d+))?$/', $text, $salutoMatches)) {
        // Comando sperimentale - output in chat privata
        // Supporta /saluto, /saluto -1 (ieri), /saluto -2 (altroieri), ecc.
        $daysAgo = isset($salutoMatches[1]) ? (int)$salutoMatches[1] : 0;

        // Lock anti-retry: evita elaborazioni multiple simultanee
        $lockFile = __DIR__ . '/../saluto.lock';
        $lockTimeout = 300; // 5 minuti massimo per elaborazione

        if (file_exists($lockFile)) {
            $lockTime = (int)file_get_contents($lockFile);
            if (time() - $lockTime < $lockTimeout) {
                // Richiesta già in elaborazione, ignora silenziosamente
                return;
            }
        }

        // Crea il lock
        file_put_contents($lockFile, time());

        // Se in chat privata, usa l'ID del gruppo principale per i test
        $targetGroupId = ($chatType == 'private') ? -1001402757977 : $chatID;
        $saluto = _saluto($targetGroupId, $daysAgo);

        // Rilascia il lock
        @unlink($lockFile);

        // Se siamo in privato non serve il fallback, se siamo in gruppo sì
        $fallbackChatId = ($chatType == 'private') ? null : $chatID;
        sendPrivateResponse($fromId, $saluto, $fallbackChatId);
        return; // Nessuna risposta nel gruppo

    } elseif (preg_match('/^\/dj(?:@rootbotbot)?(?:\s+-(\d+))?$/', $text, $djMatches)) {
        // Comando sperimentale DJ - output in chat privata
        // Supporta /dj, /dj -1 (1 ora fa), /dj -2 (2 ore fa), ecc.
        $hoursAgo = isset($djMatches[1]) ? (int)$djMatches[1] : 0;

        // Lock anti-retry
        $lockFile = __DIR__ . '/../dj.lock';
        $lockTimeout = 180; // 3 minuti

        if (file_exists($lockFile)) {
            $lockTime = (int)file_get_contents($lockFile);
            if (time() - $lockTime < $lockTimeout) {
                return;
            }
        }

        file_put_contents($lockFile, time());

        // Se in chat privata, usa l'ID del gruppo principale
        $targetGroupId = ($chatType == 'private') ? -1001402757977 : $chatID;
        $djMessage = _dj($targetGroupId, $hoursAgo);

        @unlink($lockFile);

        $fallbackChatId = ($chatType == 'private') ? null : $chatID;
        sendPrivateResponse($fromId, $djMessage, $fallbackChatId);
        return;

    } elseif (strpos($text, '/') === 0) {
        $response = _suggerisci_comando($text, $chatID);
    }


    // Invia la risposta solo se è stata impostata
    if ($response !== null) {
        $result = makeAPIRequest('sendMessage', [
            'chat_id' => $chatID,
            'text' => $response,
            'parse_mode' => 'HTML',
            'reply_to_message_id' => $message['message_id']
        ]);

        // Se il reply fallisce (messaggio originale eliminato), riprova senza reply
        if (!$result || !$result['ok']) {
            if (isset($result['error_code']) && $result['error_code'] == 400 &&
                strpos($result['description'], 'message to be replied not found') !== false) {
                makeAPIRequest('sendMessage', [
                    'chat_id' => $chatID,
                    'text' => $response,
                    'parse_mode' => 'HTML'
                ]);
            }
        }
    }
}
?>
