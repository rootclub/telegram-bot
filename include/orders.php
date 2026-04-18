<?php
////////////////////////////////////////////////////////////////////
////////////////////// GESTIONE ORDINI /////////////////////////////
////////////////////////////////////////////////////////////////////

/**
 * /mangerebbe - Inserisce un ordine per conto di un altro utente (solo admin, in reply)
 */
function _mangerebbe($text, $adminId, $adminName, $chatID, $message) {
    global $db;

    // Verifica che sia un admin
    if (!isAdmin($chatID, $adminId)) {
        return "Solo gli amministratori possono inserire ordini per altri utenti.";
    }

    // Verifica che sia in reply a un messaggio
    if (!isset($message['reply_to_message'])) {
        return "Per usare /mangerebbe devi rispondere a un messaggio dell'utente per cui vuoi ordinare.";
    }

    $replyTo = $message['reply_to_message'];
    $targetUserId = $replyTo['from']['id'];
    $targetUserName = $replyTo['from']['first_name'] . ' ' . ($replyTo['from']['last_name'] ?? '');
    $targetUserName = trim($targetUserName);

    // Estrai la pietanza dal comando
    $parts = explode(' ', $text, 2);
    if (count($parts) < 2 || trim($parts[1]) === '') {
        return "Uso: rispondi a un messaggio con /mangerebbe seguito dalla pietanza.\nEsempio: /mangerebbe pizza margherita";
    }
    $item = trim($parts[1]);

    // Verifica se esiste un ordine attivo per oggi
    $stmt = $db->prepare("SELECT id, pappatoia FROM ordini WHERE date(data) = date('now') LIMIT 1");
    $result = $stmt->execute();
    $row = $result->fetchArray(SQLITE3_ASSOC);

    if (!$row) {
        // Crea un nuovo ordine per oggi
        $stmt = $db->prepare("INSERT INTO ordini (data) VALUES (date('now'))");
        $stmt->execute();
        $orderId = $db->lastInsertRowID();

        // Aggiungi l'elemento all'ordine
        $stmt = $db->prepare("INSERT INTO elementi_ordini (id_ordine, utente, user_name, descrizione, delegato_da) VALUES (:orderId, :userId, :userName, :item, :delegatoDa)");
        $stmt->bindValue(':orderId', $orderId, SQLITE3_INTEGER);
        $stmt->bindValue(':userId', $targetUserId, SQLITE3_INTEGER);
        $stmt->bindValue(':userName', $targetUserName, SQLITE3_TEXT);
        $stmt->bindValue(':item', $item, SQLITE3_TEXT);
        $stmt->bindValue(':delegatoDa', $adminId, SQLITE3_INTEGER);
        $stmt->execute();

        return "Ho inserito l'ordine per <b>$targetUserName</b>: $item\n(inserito da $adminName)";
    } else {
        $orderId = $row['id'];
        $pappatoia = $row['pappatoia'];

        // Controlla se l'utente target ha già un ordine per oggi
        $stmt = $db->prepare("SELECT id FROM elementi_ordini WHERE id_ordine = :orderId AND utente = :userId");
        $stmt->bindValue(':orderId', $orderId, SQLITE3_INTEGER);
        $stmt->bindValue(':userId', $targetUserId, SQLITE3_INTEGER);
        $result = $stmt->execute();
        $existingOrder = $result->fetchArray(SQLITE3_ASSOC);

        if ($existingOrder) {
            // Aggiorna l'ordine esistente
            $stmt = $db->prepare("UPDATE elementi_ordini SET descrizione = :item, delegato_da = :delegatoDa WHERE id = :id");
            $stmt->bindValue(':item', $item, SQLITE3_TEXT);
            $stmt->bindValue(':delegatoDa', $adminId, SQLITE3_INTEGER);
            $stmt->bindValue(':id', $existingOrder['id'], SQLITE3_INTEGER);
            $stmt->execute();
            $message = "Ho sostituito l'ordine di <b>$targetUserName</b> con '$item'\n(modificato da $adminName)";
        } else {
            // Aggiungi il nuovo elemento
            $stmt = $db->prepare("INSERT INTO elementi_ordini (id_ordine, utente, user_name, descrizione, delegato_da) VALUES (:orderId, :userId, :userName, :item, :delegatoDa)");
            $stmt->bindValue(':orderId', $orderId, SQLITE3_INTEGER);
            $stmt->bindValue(':userId', $targetUserId, SQLITE3_INTEGER);
            $stmt->bindValue(':userName', $targetUserName, SQLITE3_TEXT);
            $stmt->bindValue(':item', $item, SQLITE3_TEXT);
            $stmt->bindValue(':delegatoDa', $adminId, SQLITE3_INTEGER);
            $stmt->execute();
            $message = "Ho inserito l'ordine per <b>$targetUserName</b>: $item\n(inserito da $adminName)";
        }

        // Aggiungi informazioni sulla pappatoia selezionata
        if ($pappatoia) {
            $stmt = $db->prepare("SELECT pappatoia FROM pappatoie WHERE id = :pappatoiaId");
            $stmt->bindValue(':pappatoiaId', $pappatoia, SQLITE3_INTEGER);
            $result = $stmt->execute();
            $pappatoiaInfo = $result->fetchArray(SQLITE3_ASSOC);
            $message .= "\nAsporto selezionato: " . $pappatoiaInfo['pappatoia'];
        }

        return $message;
    }
}

function _mangerei($text, $userId, $userName, $chatID) {
    global $db;
    $parts = explode(' ', $text, 2);
    if (count($parts) < 2) {
        return "Per favore, usa /mangerei seguito da quello che vorresti mangiare.";
    }
    $item = $parts[1];
    
    // Verifica se esiste un ordine attivo per oggi
    $stmt = $db->prepare("SELECT id, ordinante, ritirante, pappatoia FROM ordini WHERE date(data) = date('now') LIMIT 1");
    $result = $stmt->execute();
    $row = $result->fetchArray(SQLITE3_ASSOC);
    
    if (!$row) {
        // Crea un nuovo ordine per oggi
        $stmt = $db->prepare("INSERT INTO ordini (data) VALUES (date('now'))");
        $stmt->execute();
        $orderId = $db->lastInsertRowID();
        
        // Aggiungi l'elemento all'ordine
        $stmt = $db->prepare("INSERT INTO elementi_ordini (id_ordine, utente, user_name, descrizione) VALUES (:orderId, :userId, :userName, :item)");
        $stmt->bindValue(':orderId', $orderId, SQLITE3_INTEGER);
        $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
        $stmt->bindValue(':userName', $userName, SQLITE3_TEXT);
        $stmt->bindValue(':item', $item, SQLITE3_TEXT);
        $stmt->execute();
        
        // Richiedi la selezione della pappatoia
        return richiedi_selezione_pappatoia($orderId, $item, $chatID);
    } else {
        $orderId = $row['id'];
        $pappatoia = $row['pappatoia'];
        
        // Controlla se l'utente ha già un ordine per oggi
        $stmt = $db->prepare("SELECT id FROM elementi_ordini WHERE id_ordine = :orderId AND utente = :userId");
        $stmt->bindValue(':orderId', $orderId, SQLITE3_INTEGER);
        $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
        $result = $stmt->execute();
        $existingOrder = $result->fetchArray(SQLITE3_ASSOC);
        
        if ($existingOrder) {
            // Aggiorna l'ordine esistente
            $stmt = $db->prepare("UPDATE elementi_ordini SET descrizione = :item WHERE id = :id");
            $stmt->bindValue(':item', $item, SQLITE3_TEXT);
            $stmt->bindValue(':id', $existingOrder['id'], SQLITE3_INTEGER);
            $stmt->execute();
            $message = "Ho sostituito il tuo precedente ordine con '$item'.";
        } else {
            // Aggiungi il nuovo elemento a elementi_ordini
            $stmt = $db->prepare("INSERT INTO elementi_ordini (id_ordine, utente, user_name, descrizione) VALUES (:orderId, :userId, :userName, :item)");
            $stmt->bindValue(':orderId', $orderId, SQLITE3_INTEGER);
            $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
            $stmt->bindValue(':userName', $userName, SQLITE3_TEXT);
            $stmt->bindValue(':item', $item, SQLITE3_TEXT);
            $stmt->execute();
            $message = "Ho aggiunto '$item' all'ordine di oggi per te.";
        }
        
        // Aggiungi informazioni sulla pappatoia selezionata
        if ($pappatoia) {
            $stmt = $db->prepare("SELECT pappatoia FROM pappatoie WHERE id = :pappatoiaId");
            $stmt->bindValue(':pappatoiaId', $pappatoia, SQLITE3_INTEGER);
            $result = $stmt->execute();
            $pappatoiaInfo = $result->fetchArray(SQLITE3_ASSOC);
            $message .= "\nAsporto selezionato: " . $pappatoiaInfo['pappatoia'];
        } else {
            $message .= "\nNessun asporto selezionato. Usa /asporto per selezionarne uno.";
        }
        
        return $message;
    }
}


function richiedi_selezione_pappatoia($orderId, $item, $chatID) {
    global $db;
    
    $stmt = $db->prepare("SELECT id, pappatoia, giorni_chiusura FROM pappatoie ORDER BY pappatoia");
    $result = $stmt->execute();

    $keyboard = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        if (is_asporto_aperto($row['giorni_chiusura'])) {
            $keyboard[] = [['text' => $row['pappatoia'], 'callback_data' => "seleziona_pappatoia:{$orderId}:{$row['id']}"]];
        }
    }
    
    $replyMarkup = [
        'inline_keyboard' => $keyboard
    ];
    
    sendTelegramMessage($chatID, "Ho aggiunto '$item' all'ordine. Per favore, seleziona l'asporto per questo ordine:", [
        'reply_markup' => json_encode($replyMarkup),
    ]);
    
    return null; // Ritorniamo null perché abbiamo già inviato il messaggio
}

function gestisci_selezione_pappatoia($callbackQuery) {
    global $db;
    
    $data = explode(':', $callbackQuery['data']);
    $orderId = $data[1];
    $pappatoiaId = $data[2];
    
    $stmt = $db->prepare("UPDATE ordini SET pappatoia = :pappatoiaId WHERE id = :orderId");
    $stmt->bindValue(':pappatoiaId', $pappatoiaId, SQLITE3_INTEGER);
    $stmt->bindValue(':orderId', $orderId, SQLITE3_INTEGER);
    $stmt->execute();
    
    $stmt = $db->prepare("SELECT pappatoia FROM pappatoie WHERE id = :pappatoiaId");
    $stmt->bindValue(':pappatoiaId', $pappatoiaId, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $pappatoiaInfo = $result->fetchArray(SQLITE3_ASSOC);
    
    makeAPIRequest('answerCallbackQuery', [
        'callback_query_id' => $callbackQuery['id'],
        'text' => "Asporto '{$pappatoiaInfo['pappatoia']}' selezionato per l'ordine."
    ]);
    
    editTelegramMessage(
        $callbackQuery['message']['chat']['id'],
        $callbackQuery['message']['message_id'],
        "Asporto '{$pappatoiaInfo['pappatoia']}' selezionato per l'ordine."
    );
}



function _lista() {
    global $db;
    $stmt = $db->prepare("
        SELECT o.id, o.ordinante, o.ordinante_name, o.ritirante, o.ritirante_name, o.pappatoia,
               e.utente, e.user_name, e.descrizione,
               p.pappatoia as nome_pappatoia, p.indirizzo, p.telefono
        FROM ordini o
        LEFT JOIN elementi_ordini e ON o.id = e.id_ordine
        LEFT JOIN pappatoie p ON o.pappatoia = p.id
        WHERE date(o.data) = date('now')
    ");
    $result = $stmt->execute();

    $ordine = null;
    $items = [];

    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        if ($ordine === null) {
            $ordine = [
                'id' => $row['id'],
                'ordinante' => $row['ordinante'],
                'ordinante_name' => $row['ordinante_name'],
                'ritirante' => $row['ritirante'],
                'ritirante_name' => $row['ritirante_name'],
                'pappatoia' => $row['nome_pappatoia'],
                'indirizzo' => $row['indirizzo'],
                'telefono' => $row['telefono']
            ];
        }
        if ($row['user_name'] && $row['descrizione']) {
            $items[] = "{$row['user_name']}: {$row['descrizione']}";
        }
    }
    
    if ($ordine === null) {
        return "Non c'è un ordine in corso al momento, creane uno con il comando /mangerei seguito dalla pietanza desiderata.";
    } else {
        $response = "Ordine di oggi:\n\n";
        
        if ($ordine['pappatoia']) {
            $response .= "🍽 Asporto: {$ordine['pappatoia']}\n";
            if ($ordine['indirizzo']) {
                $response .= "🏠 Indirizzo: {$ordine['indirizzo']}\n";
            }
            if ($ordine['telefono']) {
                $response .= "📞 Telefono: {$ordine['telefono']}\n";
            }
            $response .= "\n";
        } else {
            $response .= "🍽 Nessun asporto selezionato. Usa /asporto per selezionarne uno.\n\n";
        }
        
        if ($ordine['ordinante_name']) {
            $response .= "🛒 Ordina: {$ordine['ordinante_name']}\n";
        } else {
            $response .= "🛒 Nessuno si è ancora offerto di ordinare. Usa /ordino per offrirti!\n";
        }

        if ($ordine['ritirante_name']) {
            $response .= "🚚 Ritira: {$ordine['ritirante_name']}\n";
        } else {
            $response .= "🚚 Nessuno si è ancora offerto di ritirare. Usa /ritiro per offrirti!\n";
        }
        
        $response .= "\nPietanze ordinate:\n";
        if (empty($items)) {
            $response .= "Nessuna pietanza ordinata finora.";
        } else {
            $response .= implode("\n", $items);
        }
        
        return $response;
    }
}


function _ordino($userId, $userName) {
    global $db;
    
    // Verifica se esiste un ordine attivo per oggi
    $stmt = $db->prepare("SELECT id, ordinante FROM ordini WHERE date(data) = date('now') LIMIT 1");
    $result = $stmt->execute();
    $row = $result->fetchArray(SQLITE3_ASSOC);
    
    if (!$row) {
        // Crea un nuovo ordine per oggi
        $stmt = $db->prepare("INSERT INTO ordini (data, ordinante, ordinante_name) VALUES (date('now'), :userId, :userName)");
        $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
        $stmt->bindValue(':userName', $userName, SQLITE3_TEXT);
        $stmt->execute();
        return "Grazie per esserti offerto per telefonare all'asporto designato e piazzare l'ordine di oggi.";
    } else {
        $orderId = $row['id'];

        // Recupera il nome dell'ordinante precedente
        $stmt = $db->prepare("SELECT ordinante_name FROM ordini WHERE id = :orderId");
        $stmt->bindValue(':orderId', $orderId, SQLITE3_INTEGER);
        $result = $stmt->execute();
        $orderRow = $result->fetchArray(SQLITE3_ASSOC);
        $currentOrdinante = $orderRow['ordinante_name'];

        if ($currentOrdinante) {
            $message = "Il compito era precedentemente assegnato a $currentOrdinante. ";
        } else {
            $message = "";
        }
        
        // Aggiorna l'ordinante
        $stmt = $db->prepare("UPDATE ordini SET ordinante = :userId, ordinante_name = :userName WHERE id = :orderId");
        $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
        $stmt->bindValue(':userName', $userName, SQLITE3_TEXT);
        $stmt->bindValue(':orderId', $orderId, SQLITE3_INTEGER);
        $stmt->execute();
        
        return $message . "Grazie per esserti offerto per telefonare all'asporto designato e piazzare l'ordine di oggi.";
    }
}

function _ritiro($userId, $userName) {
    global $db;
    
    // Verifica se esiste un ordine attivo per oggi
    $stmt = $db->prepare("SELECT id, ritirante FROM ordini WHERE date(data) = date('now') LIMIT 1");
    $result = $stmt->execute();
    $row = $result->fetchArray(SQLITE3_ASSOC);
    
    if (!$row) {
        // Crea un nuovo ordine per oggi
        $stmt = $db->prepare("INSERT INTO ordini (data, ritirante, ritirante_name) VALUES (date('now'), :userId, :userName)");
        $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
        $stmt->bindValue(':userName', $userName, SQLITE3_TEXT);
        $stmt->execute();
        return "Grazie per esserti offerto per ritirare l'ordine di oggi presso l'asporto designato.";
    } else {
        $orderId = $row['id'];

        // Recupera il nome del ritirante precedente
        $stmt = $db->prepare("SELECT ritirante_name FROM ordini WHERE id = :orderId");
        $stmt->bindValue(':orderId', $orderId, SQLITE3_INTEGER);
        $result = $stmt->execute();
        $orderRow = $result->fetchArray(SQLITE3_ASSOC);
        $currentRitirante = $orderRow['ritirante_name'];

        if ($currentRitirante) {
            $message = "Il compito era precedentemente assegnato a $currentRitirante. ";
        } else {
            $message = "";
        }
        
        // Aggiorna il ritirante
        $stmt = $db->prepare("UPDATE ordini SET ritirante = :userId, ritirante_name = :userName WHERE id = :orderId");
        $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
        $stmt->bindValue(':userName', $userName, SQLITE3_TEXT);
        $stmt->bindValue(':orderId', $orderId, SQLITE3_INTEGER);
        $stmt->execute();
        
        return $message . "Grazie per esserti offerto per ritirare l'ordine di oggi presso l'asporto designato.";
    }
}


/////////////////////////////////////////////////////////////////////////////////
//////////////////////////// GESTIONE LOCALI ASPORTO ////////////////////////////
/////////////////////////////////////////////////////////////////////////////////

function is_valid_phone_number($phone) {
    // Rimuovi tutti i caratteri non ammessi
    $cleaned_phone = preg_replace('/[^\d\s+\-\/]/', '', $phone);
    
    // Verifica che il numero contenga almeno una cifra
    if (!preg_match('/\d/', $cleaned_phone)) {
        return false;
    }
    
    // Verifica che il numero non sia composto solo da caratteri speciali
    if (strlen(preg_replace('/[\s+\-\/]/', '', $cleaned_phone)) == 0) {
        return false;
    }
    
    return true;
}

function nuova_pappatoia($chat_id, $message_id, $text) {
    global $db;
    
    // Rimuove il comando iniziale
    $text = preg_replace('/^\/nuovo_asporto(@\w+)?\s+/', '', $text);
    
    // Divide il testo in parti usando la virgola come separatore
    $parts = array_map('trim', explode(',', $text));
    
    if (count($parts) < 4) {
        return "Uso corretto: /nuovo_asporto Nome, Indirizzo, Telefono, Giorni di chiusura (separati da spazio)";
    }
    
    $pappatoia_name = preg_replace('/\s+/', ' ', trim($parts[0]));
    $indirizzo = trim($parts[1]);
    $telefono = trim($parts[2]);
    $giorni_chiusura = normalizza_giorni_chiusura(trim($parts[3]));
    
    if (empty($pappatoia_name) || empty($indirizzo) || empty($telefono) || empty($giorni_chiusura)) {
        return "Tutti i campi (nome, indirizzo, telefono, giorni di chiusura) sono obbligatori.";
    }
    
    if (!is_valid_phone_number($telefono)) {
        return "Il numero di telefono non è valido. Deve contenere numeri e può includere spazi, '+', '-' e '/'.";
    }
    
    // Verifica se la pappatoia esiste già (case-insensitive e normalizzato)
    $stmt = $db->prepare("SELECT id FROM pappatoie WHERE LOWER(REPLACE(pappatoia, ' ', '')) = LOWER(REPLACE(:name, ' ', ''))");
    $stmt->bindValue(':name', $pappatoia_name, SQLITE3_TEXT);
    $result = $stmt->execute();
    if ($result->fetchArray()) {
        return "Questo asporto esiste già. Scegli un nome diverso.";
    }
    
    // Inserisci la nuova pappatoia nel database
    $stmt = $db->prepare("INSERT INTO pappatoie (pappatoia, indirizzo, telefono, giorni_chiusura) VALUES (:name, :indirizzo, :telefono, :giorni_chiusura)");
    $stmt->bindValue(':name', $pappatoia_name, SQLITE3_TEXT);
    $stmt->bindValue(':indirizzo', $indirizzo, SQLITE3_TEXT);
    $stmt->bindValue(':telefono', $telefono, SQLITE3_TEXT);
    $stmt->bindValue(':giorni_chiusura', $giorni_chiusura, SQLITE3_TEXT);
    $stmt->execute();
    $pappatoia_id = $db->lastInsertRowID();
    
    // Richiedi l'immagine
    $response = "Asporto '$pappatoia_name' aggiunto con indirizzo: $indirizzo, telefono: $telefono e giorni di chiusura: $giorni_chiusura. Ora invia le immagini per il menu UNA alla volta. Invia /fine quando hai terminato.";
    sendTelegramMessage($chat_id, $response, [
        'reply_to_message_id' => $message_id,
    ]);
    
    // Imposta lo stato dell'utente per aspettare le immagini
    $db->exec("CREATE TABLE IF NOT EXISTS user_states (chat_id INTEGER, state TEXT, data TEXT)");
    $stmt = $db->prepare("INSERT OR REPLACE INTO user_states (chat_id, state, data, created_at) VALUES (:chat_id, 'waiting_images', :pappatoia_id, :created_at)");
    $stmt->bindValue(':chat_id', $chat_id, SQLITE3_INTEGER);
    $stmt->bindValue(':pappatoia_id', $pappatoia_id, SQLITE3_TEXT);
    $stmt->bindValue(':created_at', time(), SQLITE3_INTEGER);
    $stmt->execute();
    
    return null;
}

function normalizza_giorni_chiusura($giorni) {
    $giorni_mappatura = [
        'lu' => 'Lunedì', 'lun' => 'Lunedì', 'lune' => 'Lunedì', 'luned' => 'Lunedì', 'lunedì' => 'Lunedì', 'lunedi' => 'Lunedì',
        'ma' => 'Martedì', 'mar' => 'Martedì', 'mart' => 'Martedì', 'marte' => 'Martedì',  'marted' => 'Martedì', 'martedì' => 'Martedì', 'martedi' => 'Martedì',
        'me' => 'Mercoledì', 'mer' => 'Mercoledì', 'merc' => 'Mercoledì', 'merco' => 'Mercoledì', 'mercol' => 'Mercoledì', 'mercold' => 'Mercoledì', 'mercoldì' => 'Mercoledì', 'mercoldi' => 'Mercoledì', 'mercoledì' => 'Mercoledì', 'mercoledi' => 'Mercoledì',
        'gi' => 'Giovedì', 'gio' => 'Giovedì', 'giov' => 'Giovedì', 'giove' => 'Giovedì', 'gioved' => 'Giovedì', 'giovedì' => 'Giovedì', 'giovedi' => 'Giovedì',
        've' => 'Venerdì', 'ven' => 'Venerdì', 'vene' => 'Venerdì', 'vener' => 'Venerdì', 'venerd' => 'Venerdì', 'venerdì' => 'Venerdì', 'venerdi' => 'Venerdì',
        'sa' => 'Sabato', 'sab' => 'Sabato', 'saba' => 'Sabato', 'sabat' => 'Sabato', 'sabato' => 'Sabato',
        'do' => 'Domenica', 'dom' => 'Domenica', 'dome' => 'Domenica', 'domen' => 'Domenica', 'domeni' => 'Domenica', 'domenic' => 'Domenica', 'domenica' => 'Domenica',
        'n' => 'Nessuno', 'nessuno' => 'Nessuno', 'niente' => 'Nessuno', 'nulla' => 'Nessuno', '-' => 'Nessuno', 'no' => 'Nessuno',    
    ];

    $giorni_array = array_map('trim', explode(' ', strtolower($giorni)));
    $giorni_normalizzati = [];

    foreach ($giorni_array as $giorno) {
        if (isset($giorni_mappatura[$giorno])) {
            $giorni_normalizzati[] = $giorni_mappatura[$giorno];
        }
    }

    return implode(' ', array_unique($giorni_normalizzati));
}

function is_asporto_aperto($giorni_chiusura) {
    $oggi = date('l'); // Restituisce il nome del giorno in inglese
    $giorni_tradotti = [
        'Monday' => 'Lunedì',
        'Tuesday' => 'Martedì',
        'Wednesday' => 'Mercoledì',
        'Thursday' => 'Giovedì',
        'Friday' => 'Venerdì',
        'Saturday' => 'Sabato',
        'Sunday' => 'Domenica'
    ];
    $oggi_ita = $giorni_tradotti[$oggi];
    
    $giorni_chiusura_array = explode(' ', $giorni_chiusura);
    return !in_array($oggi_ita, $giorni_chiusura_array);
}


function nuovo_menu($chatID, $fromId) {
    global $db;

    // Verifica se l'utente è un amministratore
    $chatMember = makeAPIRequest('getChatMember', [
        'chat_id' => $chatID,
        'user_id' => $fromId
    ]);

    if (!in_array($chatMember['result']['status'], ['creator', 'administrator'])) {
        return "Mi dispiace, solo gli amministratori possono modificare i menu dei locali da asporto.";
    }

    // Ottieni tutte le pappatoie
    $stmt = $db->prepare("SELECT id, pappatoia FROM pappatoie ORDER BY pappatoia");
    $result = $stmt->execute();

    $keyboard = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $keyboard[] = [['text' => $row['pappatoia'], 'callback_data' => "select_pappatoia_for_menu:{$row['id']}"]];
    }

    if (empty($keyboard)) {
        return "Non ci sono locali da asporto disponibili.";
    }

    $replyMarkup = [
        'inline_keyboard' => $keyboard
    ];

    sendTelegramMessage($chatID, "Seleziona l'asporto di cui vuoi aggiornare il menu:", [
        'reply_markup' => json_encode($replyMarkup),
    ]);

    return null;
}

function handle_select_pappatoia_for_menu($callbackQuery) {
    global $db;

    $data = explode(':', $callbackQuery['data']);
    $pappatoiaId = $data[1];

    // Ottieni il nome della pappatoia
    $stmt = $db->prepare("SELECT pappatoia FROM pappatoie WHERE id = :id");
    $stmt->bindValue(':id', $pappatoiaId, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $pappatoia = $result->fetchArray(SQLITE3_ASSOC);

    if (!$pappatoia) {
        makeAPIRequest('answerCallbackQuery', [
            'callback_query_id' => $callbackQuery['id'],
            'text' => "Errore: Asporto non trovato."
        ]);
        return;
    }

    $pappatoiaNome = $pappatoia['pappatoia'];

    // Chiedi se cancellare le vecchie foto o aggiungerne di nuove
    $keyboard = [
        [
            ['text' => "Cancella vecchie foto", 'callback_data' => "menu_action:delete:{$pappatoiaId}"],
            ['text' => "Aggiungi alle esistenti", 'callback_data' => "menu_action:add:{$pappatoiaId}"]
        ]
    ];

    $replyMarkup = [
        'inline_keyboard' => $keyboard
    ];

    editTelegramMessage(
        $callbackQuery['message']['chat']['id'],
        $callbackQuery['message']['message_id'],
        "Per l'asporto '$pappatoiaNome', vuoi cancellare le vecchie foto o aggiungerne di nuove?",
        ['reply_markup' => json_encode($replyMarkup)]
    );
}

function handle_menu_action($callbackQuery) {
    global $db;

    $data = explode(':', $callbackQuery['data']);
    $action = $data[1];
    $pappatoiaId = $data[2];

    if ($action === 'delete') {
        // Elimina le vecchie foto
        delete_old_photos($pappatoiaId);
    }

    // Imposta lo stato dell'utente per aspettare le nuove immagini
    $chatId = $callbackQuery['message']['chat']['id'];
    $stmt = $db->prepare("INSERT OR REPLACE INTO user_states (chat_id, state, data, created_at) VALUES (:chat_id, 'waiting_menu_images', :pappatoia_id, :created_at)");
    $stmt->bindValue(':chat_id', $chatId, SQLITE3_INTEGER);
    $stmt->bindValue(':pappatoia_id', $pappatoiaId, SQLITE3_TEXT);
    $stmt->bindValue(':created_at', time(), SQLITE3_INTEGER);
    $stmt->execute();

    editTelegramMessage(
        $callbackQuery['message']['chat']['id'],
        $callbackQuery['message']['message_id'],
        "Ora puoi inviare le nuove immagini per il menu. Invia /fine quando hai terminato."
    );
}

function delete_old_photos($pappatoiaId) {
    global $db;

    // Elimina le immagini dal filesystem
    $stmt = $db->prepare("SELECT immagine FROM immagini_pappatoie WHERE pappatoia_id = :id");
    $stmt->bindValue(':id', $pappatoiaId, SQLITE3_INTEGER);
    $result = $stmt->execute();

    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $imagePath = IMAGE_SAVE_PATH . $row['immagine'];
        if (file_exists($imagePath)) {
            unlink($imagePath);
        }
    }

    // Elimina i record dal database
    $stmt = $db->prepare("DELETE FROM immagini_pappatoie WHERE pappatoia_id = :id");
    $stmt->bindValue(':id', $pappatoiaId, SQLITE3_INTEGER);
    $stmt->execute();
}






function menu($text, $chatId, $userId, $messageId) {
    global $db;
    
    // Verifica se esiste un ordine attivo per oggi
    $stmt = $db->prepare("SELECT id, pappatoia FROM ordini WHERE date(data) = date('now') LIMIT 1");
    $result = $stmt->execute();
    $ordine = $result->fetchArray(SQLITE3_ASSOC);
    
    if (!$ordine) {
        sendPrivateResponse($userId, "Non c'è un ordine attivo per oggi. Usa /mangerei per creare un nuovo ordine.", $chatId);
        return null;
    }
    
    if (!$ordine['pappatoia']) {
        sendPrivateResponse($userId, "Nessun asporto selezionato per l'ordine di oggi. Usa /asporto per selezionarne uno.", $chatId);
        return null;
    }
    
    $pappatoia_id = $ordine['pappatoia'];
    
    // Ottieni le informazioni della pappatoia
    $stmt = $db->prepare("
        SELECT p.id, p.pappatoia, p.indirizzo, p.telefono, ip.immagine 
        FROM pappatoie p 
        LEFT JOIN immagini_pappatoie ip ON p.id = ip.pappatoia_id 
        WHERE p.id = :pappatoia_id
    ");
    $stmt->bindValue(':pappatoia_id', $pappatoia_id, SQLITE3_INTEGER);
    $result = $stmt->execute();
    
    $pappatoia = null;
    $immagini = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        if (!$pappatoia) {
            $pappatoia = [
                'nome' => $row['pappatoia'],
                'indirizzo' => $row['indirizzo'],
                'telefono' => $row['telefono']
            ];
        }
        if ($row['immagine']) {
            $immagini[] = $row['immagine'];
        }
    }
    
    if (!$pappatoia) {
        sendPrivateResponse($userId, "Errore: impossibile trovare le informazioni dell'asporto selezionato.", $chatId);
        return null;
    }
    
    // Invia le informazioni della pappatoia in privato
    $info_message = "📍 Pappatoia: {$pappatoia['nome']}\n";
    $info_message .= "🏠 Indirizzo: {$pappatoia['indirizzo']}\n";
    $info_message .= "📞 Telefono: {$pappatoia['telefono']}";
    
    $sent = sendPrivateResponse($userId, $info_message, $chatId);
    
    if ($sent) {
        // Invia le immagini del menu in privato
        if (empty($immagini)) {
            sendPrivateResponse($userId, "Non ci sono immagini del menu disponibili per questo asporto.");
        } else {
            foreach ($immagini as $image) {
                $image_path = IMAGE_SAVE_PATH . $image;
                if (file_exists($image_path)) {
                    makeAPIRequest('sendPhoto', [
                        'chat_id' => $userId,
                        'photo' => new CURLFile($image_path),
                        'caption' => "Menu di {$pappatoia['nome']}"
                    ]);
                }
            }
        }
        
        // Invia un messaggio di conferma nel gruppo
        sendTelegramMessage($chatId, "Ho inviato le informazioni del menu in privato.", [
            'reply_to_message_id' => $messageId,
        ]);
    }
    
    return null; // Ritorniamo null perché abbiamo già gestito tutte le risposte
}


function _pappatoie() {
    global $db;
    
    $stmt = $db->prepare("
        SELECT p.pappatoia, p.giorni_chiusura, COUNT(ip.id) as num_immagini
        FROM pappatoie p
        LEFT JOIN immagini_pappatoie ip ON p.id = ip.pappatoia_id
        GROUP BY p.id
        ORDER BY p.pappatoia
    ");
    $result = $stmt->execute();
    
    $pappatoie = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        if (is_asporto_aperto($row['giorni_chiusura'])) {
            $pappatoie[] = $row;
        }
    }
    
    if (empty($pappatoie)) {
        return "Non ci sono asporti disponibili aperti oggi.";
    } else {
        $response = "Ecco l'elenco degli asporti disponibili aperti oggi:\n\n";
        foreach ($pappatoie as $pappatoia) {
            $nome = $pappatoia['pappatoia'];
            $num_immagini = $pappatoia['num_immagini'];
            $emoji = $num_immagini > 0 ? "🖼" : "📝";
            $response .= "$emoji $nome";
            if ($num_immagini > 0) {
                $response .= " ($num_immagini " . ($num_immagini == 1 ? "immagine" : "immagini") . ")";
            }
            $response .= "\n";
        }
        $response .= "\nUsa /menu per vedere il menu dell'asporto prescelto.";
        return date('l');
    }
}


function elenco_pappatoie($chatID) {
    global $db;
    
    $stmt = $db->prepare("SELECT id, pappatoia, giorni_chiusura FROM pappatoie ORDER BY pappatoia");
    $result = $stmt->execute();
    
    $keyboard = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        if (is_asporto_aperto($row['giorni_chiusura'])) {
            $keyboard[] = [['text' => $row['pappatoia'], 'callback_data' => "show_pappatoia_images:{$row['id']}"]];
        }
    }
    
    if (empty($keyboard)) {
        return "Non ci sono locali da asporto disponibili aperti oggi.";
    }
    
    $replyMarkup = [
        'inline_keyboard' => $keyboard
    ];
    
    sendTelegramMessage($chatID, "Seleziona un asporto aperto oggi di cui vedere il menu:", [
        'reply_markup' => json_encode($replyMarkup),
    ]);
    
    return null;
}

function handle_show_pappatoia_images($callbackQuery) {
    global $db;
    
    $data = explode(':', $callbackQuery['data']);
    $pappatoiaId = $data[1];
    
    $stmt = $db->prepare("
        SELECT p.pappatoia, ip.immagine 
        FROM pappatoie p 
        LEFT JOIN immagini_pappatoie ip ON p.id = ip.pappatoia_id 
        WHERE p.id = :pappatoia_id
    ");
    $stmt->bindValue(':pappatoia_id', $pappatoiaId, SQLITE3_INTEGER);
    $result = $stmt->execute();
    
    $pappatoia = null;
    $immagini = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        if (!$pappatoia) {
            $pappatoia = $row['pappatoia'];
        }
        if ($row['immagine']) {
            $immagini[] = $row['immagine'];
        }
    }
    
    if (!$pappatoia) {
        makeAPIRequest('answerCallbackQuery', [
            'callback_query_id' => $callbackQuery['id'],
            'text' => "Errore: Asporto non trovato."
        ]);
        return;
    }
    
    makeAPIRequest('answerCallbackQuery', [
        'callback_query_id' => $callbackQuery['id']
    ]);
    
    if (empty($immagini)) {
        sendTelegramMessage($callbackQuery['message']['chat']['id'], "Non ci sono immagini del menu disponibili per $pappatoia.");
    } else {
        sendTelegramMessage($callbackQuery['message']['chat']['id'], "Menu di $pappatoia:");
        foreach ($immagini as $image) {
            $image_path = IMAGE_SAVE_PATH . $image;
            if (file_exists($image_path)) {
                makeAPIRequest('sendPhoto', [
                    'chat_id' => $callbackQuery['message']['chat']['id'],
                    'photo' => new CURLFile($image_path)
                ]);
            }
        }
    }
}





function _pappatoia($chatID) {
    global $db;
    
    $stmt = $db->prepare("SELECT id FROM ordini WHERE date(data) = date('now') LIMIT 1");
    $result = $stmt->execute();
    $row = $result->fetchArray(SQLITE3_ASSOC);
    
    if (!$row) {
        return "Non c'è un ordine attivo per oggi. Usa /mangerei per creare un nuovo ordine.";
    }
    
    $orderId = $row['id'];
    
    $stmt = $db->prepare("SELECT id, pappatoia, giorni_chiusura FROM pappatoie ORDER BY pappatoia");
    $result = $stmt->execute();
    
    $keyboard = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        if (is_asporto_aperto($row['giorni_chiusura'])) {
            $keyboard[] = [['text' => $row['pappatoia'], 'callback_data' => "seleziona_pappatoia:{$orderId}:{$row['id']}"]];
        }
    }
    
    if (empty($keyboard)) {
        return "Non ci sono locali da asporto disponibili aperti oggi.";
    }
    
    $replyMarkup = [
        'inline_keyboard' => $keyboard
    ];
    
    sendTelegramMessage($chatID, "Seleziona l'asporto aperto oggi per l'ordine di oggi:", [
        'reply_markup' => json_encode($replyMarkup),
    ]);
    
    return null;
}


function getUserInfo($username) {
    // Rimuovi il simbolo @ se presente
    $username = ltrim($username, '@');
    
    $chatInfo = makeAPIRequest('getChat', [
        'chat_id' => "@$username"
    ]);
    
    // Log della risposta completa per debug
    error_log("getUserInfo response for @$username: " . print_r($chatInfo, true));
    
    if ($chatInfo && isset($chatInfo['result'])) {
        $firstName = $chatInfo['result']['first_name'] ?? '';
        $lastName = $chatInfo['result']['last_name'] ?? '';
        $fullName = trim("$firstName $lastName");
        
        if ($fullName) {
            error_log("getUserInfo: Found full name for @$username: $fullName");
            return $fullName;
        } else {
            error_log("getUserInfo: No full name found for @$username, returning username");
            return "@$username";
        }
    } else {
        if (isset($chatInfo['error_code'])) {
            error_log("getUserInfo: Error for @$username - Code: {$chatInfo['error_code']}, Description: {$chatInfo['description']}");
        } else {
            error_log("getUserInfo: Unknown error for @$username");
        }
        return "@$username";
    }
}

// Funzione di supporto per ottenere informazioni sui membri del gruppo
function getGroupMemberInfo($chatId, $userId) {
    $memberInfo = makeAPIRequest('getChatMember', [
        'chat_id' => $chatId,
        'user_id' => $userId
    ]);
    
    error_log("getGroupMemberInfo response: " . print_r($memberInfo, true));
    
    if ($memberInfo && isset($memberInfo['result'])) {
        $user = $memberInfo['result']['user'];
        $firstName = $user['first_name'] ?? '';
        $lastName = $user['last_name'] ?? '';
        $fullName = trim("$firstName $lastName");
        
        return $fullName ?: "@{$user['username']}";
    }
    
    return null;
}

// Modifica le funzioni _ordina e _ritira per utilizzare getGroupMemberInfo
function _ordina($text, $chatID) {
    global $db;
    
    if (preg_match('/@(\w+)/', $text, $matches)) {
        $username = $matches[1];
        $designatedUser = getUserInfo("@$username");
        
        // Se getUserInfo non ha trovato un nome completo, proviamo con getGroupMemberInfo
        if ($designatedUser === "@$username") {
            $groupInfo = makeAPIRequest('getChat', ['chat_id' => $chatID]);
            if ($groupInfo && isset($groupInfo['result']['type']) && $groupInfo['result']['type'] === 'group') {
                $chatMembers = makeAPIRequest('getChatAdministrators', ['chat_id' => $chatID]);
                if ($chatMembers && isset($chatMembers['result'])) {
                    foreach ($chatMembers['result'] as $member) {
                        if ($member['user']['username'] === $username) {
                            $designatedUser = getGroupMemberInfo($chatID, $member['user']['id']);
                            break;
                        }
                    }
                }
            }
        }
    } else {
        return "Per favore, specifica /ordina seguito da @username.";
    }
    
    $stmt = $db->prepare("SELECT id, ordinante FROM ordini WHERE date(data) = date('now') LIMIT 1");
    $result = $stmt->execute();
    $row = $result->fetchArray(SQLITE3_ASSOC);
    
    if (!$row) {
        return "Non c'è un ordine attivo per oggi. Usa /mangerei per creare un nuovo ordine.";
    }
    
    $orderId = $row['id'];
    $currentOrdinante = $row['ordinante'];
    
    $stmt = $db->prepare("UPDATE ordini SET ordinante = :userName WHERE id = :orderId");
    $stmt->bindValue(':userName', $designatedUser, SQLITE3_TEXT);
    $stmt->bindValue(':orderId', $orderId, SQLITE3_INTEGER);
    $stmt->execute();
    
    if ($currentOrdinante) {
        $message = "$designatedUser chiamerà l'asporto prescelto per piazzare l'ordine odierno al posto di $currentOrdinante.";
    } else {
        $message = "$designatedUser chiamerà l'asporto prescelto per piazzare l'ordine odierno.";
    }
    
    return $message;
}

function _ritira($text, $chatID) {
    global $db;
    
    if (preg_match('/@(\w+)/', $text, $matches)) {
        $username = $matches[1];
        $designatedUser = getUserInfo("@$username");
        
        // Se getUserInfo non ha trovato un nome completo, proviamo con getGroupMemberInfo
        if ($designatedUser === "@$username") {
            $groupInfo = makeAPIRequest('getChat', ['chat_id' => $chatID]);
            if ($groupInfo && isset($groupInfo['result']['type']) && $groupInfo['result']['type'] === 'group') {
                $chatMembers = makeAPIRequest('getChatAdministrators', ['chat_id' => $chatID]);
                if ($chatMembers && isset($chatMembers['result'])) {
                    foreach ($chatMembers['result'] as $member) {
                        if ($member['user']['username'] === $username) {
                            $designatedUser = getGroupMemberInfo($chatID, $member['user']['id']);
                            break;
                        }
                    }
                }
            }
        }
    } else {
        return "Per favore, specifica /ritira seguito da @username.";
    }
    
    $stmt = $db->prepare("SELECT id, ritirante FROM ordini WHERE date(data) = date('now') LIMIT 1");
    $result = $stmt->execute();
    $row = $result->fetchArray(SQLITE3_ASSOC);
    
    if (!$row) {
        return "Non c'è un ordine attivo per oggi. Usa /mangerei per creare un nuovo ordine.";
    }
    
    $orderId = $row['id'];
    $currentRitirante = $row['ritirante'];
    
    $stmt = $db->prepare("UPDATE ordini SET ritirante = :userName WHERE id = :orderId");
    $stmt->bindValue(':userName', $designatedUser, SQLITE3_TEXT);
    $stmt->bindValue(':orderId', $orderId, SQLITE3_INTEGER);
    $stmt->execute();
    
    if ($currentRitirante) {
        $message = "$designatedUser ritirerà l'ordine odierno presso l'asporto prescelto al posto di $currentRitirante.";
    } else {
        $message = "$designatedUser ritirerà l'ordine odierno presso l'asporto prescelto.";
    }
    
    return $message;
}


function _annullo($userId, $userName) {
    global $db;
    
    // Verifica se esiste un ordine attivo per oggi
    $stmt = $db->prepare("SELECT id FROM ordini WHERE date(data) = date('now') LIMIT 1");
    $result = $stmt->execute();
    $row = $result->fetchArray(SQLITE3_ASSOC);
    
    if (!$row) {
        return "Non c'è un ordine attivo per oggi.";
    }
    
    $orderId = $row['id'];
    
    // Cerca l'elemento dell'utente nell'ordine corrente
    $stmt = $db->prepare("SELECT id FROM elementi_ordini WHERE id_ordine = :orderId AND utente = :userName");
    $stmt->bindValue(':orderId', $orderId, SQLITE3_INTEGER);
    $stmt->bindValue(':userName', $userName, SQLITE3_TEXT);
    $result = $stmt->execute();
    $elementoOrdine = $result->fetchArray(SQLITE3_ASSOC);
    
    if (!$elementoOrdine) {
        return "Non hai ancora aggiunto nessuna pietanza all'ordine di oggi.";
    }
    
    // Rimuovi l'elemento dell'utente
    $stmt = $db->prepare("DELETE FROM elementi_ordini WHERE id = :id");
    $stmt->bindValue(':id', $elementoOrdine['id'], SQLITE3_INTEGER);
    $stmt->execute();
    
    // Verifica se ci sono ancora elementi nell'ordine
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM elementi_ordini WHERE id_ordine = :orderId");
    $stmt->bindValue(':orderId', $orderId, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $countRow = $result->fetchArray(SQLITE3_ASSOC);
    
    if ($countRow['count'] == 0) {
        // Se non ci sono più elementi, rimuovi l'intero ordine
        $stmt = $db->prepare("DELETE FROM ordini WHERE id = :orderId");
        $stmt->bindValue(':orderId', $orderId, SQLITE3_INTEGER);
        $stmt->execute();
        return "La tua pietanza è stata rimossa dall'ordine. Poiché era l'ultima pietanza, l'ordine è stato cancellato.";
    }
    
    return "La tua pietanza è stata rimossa dall'ordine di oggi.";
}

//////////////////////
// Elimina pappatoia
/////////////////////

function elimina_pappatoia($chatID, $fromId) {
    global $db;

    // Verifica se l'utente è un amministratore
    $chatMember = makeAPIRequest('getChatMember', [
        'chat_id' => $chatID,
        'user_id' => $fromId
    ]);

    if (!in_array($chatMember['result']['status'], ['creator', 'administrator'])) {
        return "Mi dispiace, solo gli amministratori possono eliminare i locali da asporto.";
    }

    // Ottieni tutte le pappatoie
    $stmt = $db->prepare("SELECT id, pappatoia FROM pappatoie ORDER BY pappatoia");
    $result = $stmt->execute();

    $keyboard = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $keyboard[] = [['text' => $row['pappatoia'], 'callback_data' => "delete_pappatoia:{$row['id']}"]];
    }

    if (empty($keyboard)) {
        return "Non ci sono locali da asporto disponibili per l'eliminazione.";
    }

    $replyMarkup = [
        'inline_keyboard' => $keyboard
    ];

    sendTelegramMessage($chatID, "Seleziona l'asporto da eliminare:", [
        'reply_markup' => json_encode($replyMarkup),
    ]);

    return null;
}

function handle_delete_pappatoia($callbackQuery) {
    global $db;

    $data = explode(':', $callbackQuery['data']);
    $pappatoiaId = $data[1];

    // Ottieni il nome della pappatoia
    $stmt = $db->prepare("SELECT pappatoia FROM pappatoie WHERE id = :id");
    $stmt->bindValue(':id', $pappatoiaId, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $pappatoia = $result->fetchArray(SQLITE3_ASSOC);

    if (!$pappatoia) {
        makeAPIRequest('answerCallbackQuery', [
            'callback_query_id' => $callbackQuery['id'],
            'text' => "Errore: Asporto non trovato."
        ]);
        return;
    }

    $pappatoiaNome = $pappatoia['pappatoia'];

    // Chiedi conferma
    $keyboard = [
        [
            ['text' => "Sì, elimina", 'callback_data' => "confirm_delete_pappatoia:{$pappatoiaId}"],
            ['text' => "No, annulla", 'callback_data' => "cancel_delete_pappatoia"]
        ]
    ];

    $replyMarkup = [
        'inline_keyboard' => $keyboard
    ];

    editTelegramMessage(
        $callbackQuery['message']['chat']['id'],
        $callbackQuery['message']['message_id'],
        "Sei sicuro di voler eliminare l'asporto '$pappatoiaNome'?",
        ['reply_markup' => json_encode($replyMarkup)]
    );
}

function confirm_delete_pappatoia($callbackQuery) {
    global $db;

    $data = explode(':', $callbackQuery['data']);
    $pappatoiaId = $data[1];

    // Verifica se la pappatoia è in uso in un ordine attivo
    $stmt = $db->prepare("SELECT id FROM ordini WHERE pappatoia = :id AND date(data) = date('now')");
    $stmt->bindValue(':id', $pappatoiaId, SQLITE3_INTEGER);
    $result = $stmt->execute();
    if ($result->fetchArray()) {
        makeAPIRequest('answerCallbackQuery', [
            'callback_query_id' => $callbackQuery['id'],
            'text' => "Non puoi eliminare questo asporto perché è in uso in un ordine attivo.",
            'show_alert' => true
        ]);
        return;
    }

    // Ottieni il nome della pappatoia
    $stmt = $db->prepare("SELECT pappatoia FROM pappatoie WHERE id = :id");
    $stmt->bindValue(':id', $pappatoiaId, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $pappatoia = $result->fetchArray(SQLITE3_ASSOC);

    if (!$pappatoia) {
        makeAPIRequest('answerCallbackQuery', [
            'callback_query_id' => $callbackQuery['id'],
            'text' => "Errore: Asporto non trovato."
        ]);
        return;
    }

    $pappatoiaNome = $pappatoia['pappatoia'];

    // Elimina le immagini associate
    $stmt = $db->prepare("SELECT immagine FROM immagini_pappatoie WHERE pappatoia_id = :id");
    $stmt->bindValue(':id', $pappatoiaId, SQLITE3_INTEGER);
    $result = $stmt->execute();

    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $imagePath = IMAGE_SAVE_PATH . $row['immagine'];
        if (file_exists($imagePath)) {
            unlink($imagePath);
        }
    }

    // Elimina i record dal database
    $db->exec("BEGIN TRANSACTION");
    
    $stmt = $db->prepare("DELETE FROM immagini_pappatoie WHERE pappatoia_id = :id");
    $stmt->bindValue(':id', $pappatoiaId, SQLITE3_INTEGER);
    $stmt->execute();

    $stmt = $db->prepare("DELETE FROM pappatoie WHERE id = :id");
    $stmt->bindValue(':id', $pappatoiaId, SQLITE3_INTEGER);
    $stmt->execute();

    $db->exec("COMMIT");

    editTelegramMessage(
        $callbackQuery['message']['chat']['id'],
        $callbackQuery['message']['message_id'],
        "Asporto '$pappatoiaNome' eliminato con successo."
    );
}

function cancel_delete_pappatoia($callbackQuery) {
    editTelegramMessage(
        $callbackQuery['message']['chat']['id'],
        $callbackQuery['message']['message_id'],
        "Eliminazione asporto annullata."
    );
}

?>
