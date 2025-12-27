<?php
////////////////////////////////////////////////////////////////////
////////////////////// GESTIONE EVENTI /////////////////////////////
////////////////////////////////////////////////////////////////////

/**
 * Verifica se l'utente è admin del gruppo
 */
function isAdmin($chatId, $userId) {
    $result = makeAPIRequest('getChatMember', [
        'chat_id' => $chatId,
        'user_id' => $userId
    ]);

    if ($result && isset($result['ok']) && $result['ok']) {
        $status = $result['result']['status'];
        return in_array($status, ['creator', 'administrator']);
    }
    return false;
}

/**
 * Ottiene gli eventi attivi (data >= oggi)
 */
function getEventiAttivi($chatId = null) {
    global $db;

    $sql = "SELECT * FROM eventi WHERE datetime(data_ora) >= datetime('now', 'localtime')";
    if ($chatId) {
        $sql .= " AND chat_id = :chatId";
    }
    $sql .= " ORDER BY data_ora ASC";

    $stmt = $db->prepare($sql);
    if ($chatId) {
        $stmt->bindValue(':chatId', $chatId, SQLITE3_INTEGER);
    }
    $result = $stmt->execute();

    $eventi = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $eventi[] = $row;
    }
    return $eventi;
}

/**
 * Salva lo stato utente per flussi multi-step
 */
function setUserState($chatId, $userId, $state, $data = null) {
    global $db;

    $stmt = $db->prepare("DELETE FROM user_states WHERE chat_id = :chatId AND user_id = :userId");
    $stmt->bindValue(':chatId', $chatId, SQLITE3_INTEGER);
    $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
    $stmt->execute();

    if ($state) {
        $stmt = $db->prepare("INSERT INTO user_states (chat_id, user_id, state, data) VALUES (:chatId, :userId, :state, :data)");
        $stmt->bindValue(':chatId', $chatId, SQLITE3_INTEGER);
        $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
        $stmt->bindValue(':state', $state, SQLITE3_TEXT);
        $stmt->bindValue(':data', $data ? json_encode($data) : null, SQLITE3_TEXT);
        $stmt->execute();
    }
}

/**
 * Ottiene lo stato utente
 */
function getUserState($chatId, $userId) {
    global $db;

    $stmt = $db->prepare("SELECT state, data FROM user_states WHERE chat_id = :chatId AND user_id = :userId");
    $stmt->bindValue(':chatId', $chatId, SQLITE3_INTEGER);
    $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $row = $result->fetchArray(SQLITE3_ASSOC);

    if ($row) {
        return [
            'state' => $row['state'],
            'data' => $row['data'] ? json_decode($row['data'], true) : null
        ];
    }
    return null;
}

/**
 * Cancella lo stato utente
 */
function clearUserState($chatId, $userId) {
    setUserState($chatId, $userId, null);
}

////////////////////////////////////////////////////////////////////
////////////////////// CREAZIONE EVENTO ////////////////////////////
////////////////////////////////////////////////////////////////////

/**
 * /evento - Inizia la creazione di un nuovo evento (solo admin)
 */
function _evento($chatID, $userId, $userName, $chatType) {
    if ($chatType === 'private') {
        return "Questo comando funziona solo nei gruppi.";
    }

    if (!isAdmin($chatID, $userId)) {
        return "Solo gli amministratori possono creare eventi.";
    }

    setUserState($chatID, $userId, 'waiting_event_description', ['creatore_name' => $userName]);

    return "Stai creando un nuovo evento.\n\nInserisci la <b>descrizione</b> dell'evento (es: Cena di Natale, Corso Arduino, Talk su Linux):";
}

/**
 * Gestisce l'input durante la creazione evento
 */
function handleEventCreation($message) {
    global $db;

    $chatId = $message['chat']['id'];
    $userId = $message['from']['id'];
    $text = $message['text'] ?? '';

    $userState = getUserState($chatId, $userId);
    if (!$userState) {
        return null;
    }

    $state = $userState['state'];
    $data = $userState['data'] ?? [];

    switch ($state) {
        case 'waiting_event_description':
            $data['descrizione'] = $text;
            setUserState($chatId, $userId, 'waiting_event_datetime', $data);
            return "Descrizione salvata: <b>{$text}</b>\n\nOra inserisci <b>data e ora</b> dell'evento nel formato:\n<code>GG/MM/AAAA HH:MM</code>\n\nEsempio: <code>25/12/2024 20:00</code>";

        case 'waiting_event_datetime':
            // Parsing data/ora
            $parsed = DateTime::createFromFormat('d/m/Y H:i', $text);
            if (!$parsed) {
                return "Formato data non valido. Usa il formato <code>GG/MM/AAAA HH:MM</code>\nEsempio: <code>25/12/2024 20:00</code>";
            }

            $data['data_ora'] = $parsed->format('Y-m-d H:i:s');
            $data['data_ora_display'] = $parsed->format('d/m/Y H:i');
            setUserState($chatId, $userId, 'waiting_event_cost', $data);
            return "Data salvata: <b>{$data['data_ora_display']}</b>\n\nOra inserisci il <b>costo di partecipazione</b> in euro (es: 15 oppure 0 se gratuito):";

        case 'waiting_event_cost':
            $costo = str_replace(',', '.', $text);
            if (!is_numeric($costo) || $costo < 0) {
                return "Inserisci un valore numerico valido (es: 15 oppure 0 se gratuito):";
            }

            $data['costo'] = floatval($costo);

            // Salva l'evento nel database
            $stmt = $db->prepare("INSERT INTO eventi (descrizione, data_ora, costo, creatore_id, creatore_name, chat_id) VALUES (:descrizione, :data_ora, :costo, :creatore_id, :creatore_name, :chat_id)");
            $stmt->bindValue(':descrizione', $data['descrizione'], SQLITE3_TEXT);
            $stmt->bindValue(':data_ora', $data['data_ora'], SQLITE3_TEXT);
            $stmt->bindValue(':costo', $data['costo'], SQLITE3_FLOAT);
            $stmt->bindValue(':creatore_id', $userId, SQLITE3_INTEGER);
            $stmt->bindValue(':creatore_name', $data['creatore_name'], SQLITE3_TEXT);
            $stmt->bindValue(':chat_id', $chatId, SQLITE3_INTEGER);
            $stmt->execute();

            clearUserState($chatId, $userId);

            $costoStr = $data['costo'] > 0 ? number_format($data['costo'], 2, ',', '.') . " euro" : "Gratuito";

            return "Evento creato con successo!\n\n" .
                   "<b>{$data['descrizione']}</b>\n" .
                   "Data: {$data['data_ora_display']}\n" .
                   "Costo: {$costoStr}\n\n" .
                   "Gli utenti possono iscriversi con /partecipo";
    }

    return null;
}

////////////////////////////////////////////////////////////////////
////////////////////// PARTECIPAZIONE //////////////////////////////
////////////////////////////////////////////////////////////////////

/**
 * /partecipo - Iscrizione a un evento
 */
function _partecipo($chatID, $userId, $userName) {
    global $db;

    $eventi = getEventiAttivi($chatID);

    if (empty($eventi)) {
        return "Non ci sono eventi in programma al momento.";
    }

    if (count($eventi) === 1) {
        return iscriviPartecipante($eventi[0]['id'], $userId, $userName, $chatID);
    }

    // Più eventi: mostra keyboard per selezione
    $keyboard = [];
    foreach ($eventi as $evento) {
        $dataOra = DateTime::createFromFormat('Y-m-d H:i:s', $evento['data_ora']);
        $label = $evento['descrizione'] . ' (' . $dataOra->format('d/m H:i') . ')';
        $keyboard[] = [['text' => $label, 'callback_data' => "partecipo_evento:{$evento['id']}"]];
    }

    makeAPIRequest('sendMessage', [
        'chat_id' => $chatID,
        'text' => "A quale evento vuoi partecipare?",
        'reply_markup' => json_encode(['inline_keyboard' => $keyboard])
    ]);

    return null;
}

/**
 * Callback per selezione evento partecipazione
 */
function handlePartecipoEvento($callbackQuery) {
    $data = explode(':', $callbackQuery['data']);
    $eventoId = $data[1];
    $userId = $callbackQuery['from']['id'];
    $userName = $callbackQuery['from']['first_name'] . ' ' . ($callbackQuery['from']['last_name'] ?? '');
    $chatId = $callbackQuery['message']['chat']['id'];

    $response = iscriviPartecipante($eventoId, $userId, $userName, $chatId);

    makeAPIRequest('answerCallbackQuery', [
        'callback_query_id' => $callbackQuery['id']
    ]);

    makeAPIRequest('editMessageText', [
        'chat_id' => $chatId,
        'message_id' => $callbackQuery['message']['message_id'],
        'text' => $response,
        'parse_mode' => 'HTML'
    ]);
}

/**
 * Iscrive un partecipante a un evento
 */
function iscriviPartecipante($eventoId, $userId, $userName, $chatId) {
    global $db;

    // Verifica che l'evento esista
    $stmt = $db->prepare("SELECT * FROM eventi WHERE id = :id");
    $stmt->bindValue(':id', $eventoId, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $evento = $result->fetchArray(SQLITE3_ASSOC);

    if (!$evento) {
        return "Evento non trovato.";
    }

    // Verifica se già iscritto
    $stmt = $db->prepare("SELECT id FROM partecipanti_eventi WHERE evento_id = :eventoId AND user_id = :userId");
    $stmt->bindValue(':eventoId', $eventoId, SQLITE3_INTEGER);
    $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
    $result = $stmt->execute();

    if ($result->fetchArray()) {
        return "Sei già iscritto a questo evento!";
    }

    // Iscrivi il partecipante
    $stmt = $db->prepare("INSERT INTO partecipanti_eventi (evento_id, user_id, user_name) VALUES (:eventoId, :userId, :userName)");
    $stmt->bindValue(':eventoId', $eventoId, SQLITE3_INTEGER);
    $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
    $stmt->bindValue(':userName', trim($userName), SQLITE3_TEXT);
    $stmt->execute();

    $dataOra = DateTime::createFromFormat('Y-m-d H:i:s', $evento['data_ora']);

    return "Ti sei iscritto a <b>{$evento['descrizione']}</b>!\n" .
           "Data: " . $dataOra->format('d/m/Y H:i');
}

////////////////////////////////////////////////////////////////////
////////////////////// ANNULLAMENTO ////////////////////////////////
////////////////////////////////////////////////////////////////////

/**
 * Verifica se l'utente ha partecipazioni attive
 */
function getPartecipazioniUtente($chatId, $userId) {
    global $db;

    $stmt = $db->prepare("
        SELECT p.id, p.evento_id, e.descrizione, e.data_ora
        FROM partecipanti_eventi p
        JOIN eventi e ON p.evento_id = e.id
        WHERE e.chat_id = :chatId AND p.user_id = :userId AND datetime(e.data_ora) >= datetime('now', 'localtime')
    ");
    $stmt->bindValue(':chatId', $chatId, SQLITE3_INTEGER);
    $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
    $result = $stmt->execute();

    $partecipazioni = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $partecipazioni[] = $row;
    }
    return $partecipazioni;
}

/**
 * Verifica se l'utente ha un ordine attivo oggi
 */
function hasOrdineAttivo($userId) {
    global $db;

    $stmt = $db->prepare("
        SELECT e.id FROM elementi_ordini e
        JOIN ordini o ON e.id_ordine = o.id
        WHERE e.utente = :userId AND date(o.data) = date('now')
    ");
    $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
    $result = $stmt->execute();

    return $result->fetchArray() !== false;
}

/**
 * Ottiene gli ordini delegati inseriti da un admin
 */
function getOrdiniDelegati($adminId) {
    global $db;

    $stmt = $db->prepare("
        SELECT e.id, e.user_name, e.descrizione
        FROM elementi_ordini e
        JOIN ordini o ON e.id_ordine = o.id
        WHERE e.delegato_da = :adminId AND date(o.data) = date('now')
    ");
    $stmt->bindValue(':adminId', $adminId, SQLITE3_INTEGER);
    $result = $stmt->execute();

    $ordini = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $ordini[] = $row;
    }
    return $ordini;
}

/**
 * /annullo - Gestisce annullamento ordini E partecipazioni
 */
function _annullo_smart($chatID, $userId, $userName) {
    $partecipazioni = getPartecipazioniUtente($chatID, $userId);
    $hasOrdine = hasOrdineAttivo($userId);
    $ospiti = getOspitiUtente($chatID, $userId);
    $ordiniDelegati = getOrdiniDelegati($userId); // Ordini inseriti per altri (solo admin)

    $opzioni = [];

    if ($hasOrdine) {
        $opzioni[] = ['type' => 'ordine', 'label' => 'Il mio ordine di oggi', 'id' => 0];
    }

    // Aggiungi ordini delegati (inseriti con /mangerebbe)
    foreach ($ordiniDelegati as $od) {
        $opzioni[] = [
            'type' => 'ordine_delegato',
            'id' => $od['id'],
            'label' => "Ordine di {$od['user_name']}: {$od['descrizione']}"
        ];
    }

    foreach ($partecipazioni as $p) {
        $dataOra = DateTime::createFromFormat('Y-m-d H:i:s', $p['data_ora']);
        $opzioni[] = [
            'type' => 'partecipazione',
            'id' => $p['evento_id'],
            'label' => "Partecipazione: {$p['descrizione']} ({$dataOra->format('d/m')})"
        ];
    }

    foreach ($ospiti as $o) {
        $opzioni[] = [
            'type' => 'ospite',
            'id' => $o['id'],
            'label' => "Ospite: {$o['nome_ospite']} ({$o['descrizione']})"
        ];
    }

    if (empty($opzioni)) {
        return "Non hai nulla da annullare.";
    }

    if (count($opzioni) === 1) {
        // Solo un'opzione: esegui direttamente
        $opt = $opzioni[0];
        if ($opt['type'] === 'ordine') {
            return _annullo_ordine($userId, $userName);
        } elseif ($opt['type'] === 'ordine_delegato') {
            return annullaOrdineDelegato($opt['id']);
        } elseif ($opt['type'] === 'partecipazione') {
            return annullaPartecipazione($opt['id'], $userId);
        } elseif ($opt['type'] === 'ospite') {
            return annullaOspite($opt['id'], $userId);
        }
    }

    // Più opzioni: mostra keyboard
    $keyboard = [];
    foreach ($opzioni as $opt) {
        if ($opt['type'] === 'ordine') {
            $keyboard[] = [['text' => $opt['label'], 'callback_data' => "annullo_tipo:ordine:0"]];
        } elseif ($opt['type'] === 'ordine_delegato') {
            $keyboard[] = [['text' => $opt['label'], 'callback_data' => "annullo_tipo:ordine_delegato:{$opt['id']}"]];
        } elseif ($opt['type'] === 'partecipazione') {
            $keyboard[] = [['text' => $opt['label'], 'callback_data' => "annullo_tipo:partecipazione:{$opt['id']}"]];
        } elseif ($opt['type'] === 'ospite') {
            $keyboard[] = [['text' => $opt['label'], 'callback_data' => "annullo_tipo:ospite:{$opt['id']}"]];
        }
    }

    makeAPIRequest('sendMessage', [
        'chat_id' => $chatID,
        'text' => "Cosa vuoi annullare?",
        'reply_markup' => json_encode(['inline_keyboard' => $keyboard])
    ]);

    return null;
}

/**
 * Annulla un ordine delegato (inserito con /mangerebbe)
 */
function annullaOrdineDelegato($elementoId) {
    global $db;

    // Ottieni info sull'ordine prima di eliminarlo
    $stmt = $db->prepare("SELECT e.user_name, e.descrizione, e.id_ordine FROM elementi_ordini e WHERE e.id = :id");
    $stmt->bindValue(':id', $elementoId, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $elemento = $result->fetchArray(SQLITE3_ASSOC);

    if (!$elemento) {
        return "Ordine non trovato.";
    }

    $userName = $elemento['user_name'];
    $descrizione = $elemento['descrizione'];
    $ordineId = $elemento['id_ordine'];

    // Elimina l'elemento
    $stmt = $db->prepare("DELETE FROM elementi_ordini WHERE id = :id");
    $stmt->bindValue(':id', $elementoId, SQLITE3_INTEGER);
    $stmt->execute();

    // Controlla se l'ordine è vuoto
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM elementi_ordini WHERE id_ordine = :ordineId");
    $stmt->bindValue(':ordineId', $ordineId, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $count = $result->fetchArray(SQLITE3_ASSOC)['count'];

    if ($count == 0) {
        $stmt = $db->prepare("DELETE FROM ordini WHERE id = :id");
        $stmt->bindValue(':id', $ordineId, SQLITE3_INTEGER);
        $stmt->execute();
        return "Ho annullato l'ordine di <b>$userName</b> ($descrizione). L'ordine di oggi è stato eliminato perché era vuoto.";
    }

    return "Ho annullato l'ordine di <b>$userName</b> ($descrizione).";
}

/**
 * Callback per selezione tipo annullamento
 */
function handleAnnulloTipo($callbackQuery) {
    $data = explode(':', $callbackQuery['data']);
    $tipo = $data[1];
    $id = $data[2];
    $userId = $callbackQuery['from']['id'];
    $userName = $callbackQuery['from']['first_name'] . ' ' . ($callbackQuery['from']['last_name'] ?? '');
    $chatId = $callbackQuery['message']['chat']['id'];

    if ($tipo === 'ordine') {
        $response = _annullo_ordine($userId, $userName);
    } elseif ($tipo === 'ordine_delegato') {
        $response = annullaOrdineDelegato($id);
    } elseif ($tipo === 'partecipazione') {
        $response = annullaPartecipazione($id, $userId);
    } elseif ($tipo === 'ospite') {
        $response = annullaOspite($id, $userId);
    } else {
        $response = "Opzione non valida.";
    }

    makeAPIRequest('answerCallbackQuery', [
        'callback_query_id' => $callbackQuery['id']
    ]);

    makeAPIRequest('editMessageText', [
        'chat_id' => $chatId,
        'message_id' => $callbackQuery['message']['message_id'],
        'text' => $response,
        'parse_mode' => 'HTML'
    ]);
}

/**
 * Annulla ordine (funzione originale rinominata)
 */
function _annullo_ordine($userId, $userName) {
    global $db;

    $stmt = $db->prepare("
        SELECT e.id, o.id as ordine_id
        FROM elementi_ordini e
        JOIN ordini o ON e.id_ordine = o.id
        WHERE e.utente = :userId AND date(o.data) = date('now')
    ");
    $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $row = $result->fetchArray(SQLITE3_ASSOC);

    if (!$row) {
        return "Non hai ordini attivi per oggi.";
    }

    // Elimina l'elemento dell'ordine
    $stmt = $db->prepare("DELETE FROM elementi_ordini WHERE id = :id");
    $stmt->bindValue(':id', $row['id'], SQLITE3_INTEGER);
    $stmt->execute();

    // Controlla se l'ordine è vuoto
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM elementi_ordini WHERE id_ordine = :ordineId");
    $stmt->bindValue(':ordineId', $row['ordine_id'], SQLITE3_INTEGER);
    $result = $stmt->execute();
    $count = $result->fetchArray(SQLITE3_ASSOC)['count'];

    if ($count == 0) {
        $stmt = $db->prepare("DELETE FROM ordini WHERE id = :id");
        $stmt->bindValue(':id', $row['ordine_id'], SQLITE3_INTEGER);
        $stmt->execute();
        return "Il tuo ordine è stato annullato. L'ordine di oggi è stato eliminato perché era vuoto.";
    }

    return "Il tuo ordine è stato annullato.";
}

/**
 * Annulla partecipazione a un evento
 */
function annullaPartecipazione($eventoId, $userId) {
    global $db;

    // Verifica che l'evento esista
    $stmt = $db->prepare("SELECT descrizione FROM eventi WHERE id = :id");
    $stmt->bindValue(':id', $eventoId, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $evento = $result->fetchArray(SQLITE3_ASSOC);

    if (!$evento) {
        return "Evento non trovato.";
    }

    // Elimina anche gli ospiti dell'utente per questo evento
    $stmt = $db->prepare("DELETE FROM ospiti_eventi WHERE evento_id = :eventoId AND invitante_id = :userId");
    $stmt->bindValue(':eventoId', $eventoId, SQLITE3_INTEGER);
    $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
    $stmt->execute();

    // Elimina la partecipazione
    $stmt = $db->prepare("DELETE FROM partecipanti_eventi WHERE evento_id = :eventoId AND user_id = :userId");
    $stmt->bindValue(':eventoId', $eventoId, SQLITE3_INTEGER);
    $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
    $stmt->execute();

    return "La tua partecipazione a <b>{$evento['descrizione']}</b> è stata annullata.";
}

////////////////////////////////////////////////////////////////////
////////////////////// LISTA PARTECIPANTI //////////////////////////
////////////////////////////////////////////////////////////////////

/**
 * /partecipanti - Mostra lista partecipanti
 */
function _partecipanti($chatID) {
    global $db;

    $eventi = getEventiAttivi($chatID);

    if (empty($eventi)) {
        return "Non ci sono eventi in programma al momento.";
    }

    if (count($eventi) === 1) {
        return formattaListaPartecipanti($eventi[0]['id']);
    }

    // Più eventi: mostra keyboard per selezione
    $keyboard = [];
    foreach ($eventi as $evento) {
        $dataOra = DateTime::createFromFormat('Y-m-d H:i:s', $evento['data_ora']);
        $label = $evento['descrizione'] . ' (' . $dataOra->format('d/m H:i') . ')';
        $keyboard[] = [['text' => $label, 'callback_data' => "lista_partecipanti:{$evento['id']}"]];
    }

    makeAPIRequest('sendMessage', [
        'chat_id' => $chatID,
        'text' => "Di quale evento vuoi vedere i partecipanti?",
        'reply_markup' => json_encode(['inline_keyboard' => $keyboard])
    ]);

    return null;
}

/**
 * Callback per selezione evento lista partecipanti
 */
function handleListaPartecipanti($callbackQuery) {
    $data = explode(':', $callbackQuery['data']);
    $eventoId = $data[1];
    $chatId = $callbackQuery['message']['chat']['id'];

    $response = formattaListaPartecipanti($eventoId);

    makeAPIRequest('answerCallbackQuery', [
        'callback_query_id' => $callbackQuery['id']
    ]);

    makeAPIRequest('editMessageText', [
        'chat_id' => $chatId,
        'message_id' => $callbackQuery['message']['message_id'],
        'text' => $response,
        'parse_mode' => 'HTML'
    ]);
}

/**
 * Formatta la lista partecipanti di un evento
 */
function formattaListaPartecipanti($eventoId) {
    global $db;

    // Info evento
    $stmt = $db->prepare("SELECT * FROM eventi WHERE id = :id");
    $stmt->bindValue(':id', $eventoId, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $evento = $result->fetchArray(SQLITE3_ASSOC);

    if (!$evento) {
        return "Evento non trovato.";
    }

    $dataOra = DateTime::createFromFormat('Y-m-d H:i:s', $evento['data_ora']);
    $costoStr = $evento['costo'] > 0 ? number_format($evento['costo'], 2, ',', '.') . " euro" : "Gratuito";

    $output = "<b>{$evento['descrizione']}</b>\n";
    $output .= "Data: " . $dataOra->format('d/m/Y H:i') . "\n";
    $output .= "Costo: {$costoStr}\n\n";

    // Partecipanti
    $stmt = $db->prepare("SELECT user_name FROM partecipanti_eventi WHERE evento_id = :eventoId ORDER BY created_at");
    $stmt->bindValue(':eventoId', $eventoId, SQLITE3_INTEGER);
    $result = $stmt->execute();

    $partecipanti = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $partecipanti[] = $row['user_name'];
    }

    // Ospiti
    $stmt = $db->prepare("SELECT nome_ospite, invitante_name FROM ospiti_eventi WHERE evento_id = :eventoId ORDER BY created_at");
    $stmt->bindValue(':eventoId', $eventoId, SQLITE3_INTEGER);
    $result = $stmt->execute();

    $ospiti = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $ospiti[] = "{$row['nome_ospite']} (ospite di {$row['invitante_name']})";
    }

    $totale = count($partecipanti) + count($ospiti);

    if ($totale === 0) {
        $output .= "Nessun partecipante iscritto.";
    } else {
        $output .= "<b>Partecipanti ({$totale}):</b>\n";
        foreach ($partecipanti as $p) {
            $output .= "- {$p}\n";
        }
        foreach ($ospiti as $o) {
            $output .= "- {$o}\n";
        }
    }

    return $output;
}

////////////////////////////////////////////////////////////////////
////////////////////// MODIFICA EVENTO /////////////////////////////
////////////////////////////////////////////////////////////////////

/**
 * /modifica_evento - Modifica un evento esistente (solo admin)
 */
function _modifica_evento($chatID, $userId, $chatType) {
    if ($chatType === 'private') {
        return "Questo comando funziona solo nei gruppi.";
    }

    if (!isAdmin($chatID, $userId)) {
        return "Solo gli amministratori possono modificare eventi.";
    }

    $eventi = getEventiAttivi($chatID);

    if (empty($eventi)) {
        return "Non ci sono eventi da modificare.";
    }

    if (count($eventi) === 1) {
        return mostraOpzioniModifica($eventi[0]['id'], $chatID);
    }

    // Più eventi: mostra keyboard per selezione
    $keyboard = [];
    foreach ($eventi as $evento) {
        $dataOra = DateTime::createFromFormat('Y-m-d H:i:s', $evento['data_ora']);
        $label = $evento['descrizione'] . ' (' . $dataOra->format('d/m H:i') . ')';
        $keyboard[] = [['text' => $label, 'callback_data' => "modifica_evento_select:{$evento['id']}"]];
    }

    makeAPIRequest('sendMessage', [
        'chat_id' => $chatID,
        'text' => "Quale evento vuoi modificare?",
        'reply_markup' => json_encode(['inline_keyboard' => $keyboard])
    ]);

    return null;
}

/**
 * Mostra opzioni di modifica per un evento
 */
function mostraOpzioniModifica($eventoId, $chatId) {
    $keyboard = [
        [['text' => 'Descrizione', 'callback_data' => "modifica_campo:descrizione:{$eventoId}"]],
        [['text' => 'Data e ora', 'callback_data' => "modifica_campo:data_ora:{$eventoId}"]],
        [['text' => 'Costo', 'callback_data' => "modifica_campo:costo:{$eventoId}"]]
    ];

    makeAPIRequest('sendMessage', [
        'chat_id' => $chatId,
        'text' => "Cosa vuoi modificare?",
        'reply_markup' => json_encode(['inline_keyboard' => $keyboard])
    ]);

    return null;
}

/**
 * Callback per selezione evento da modificare
 */
function handleModificaEventoSelect($callbackQuery) {
    $data = explode(':', $callbackQuery['data']);
    $eventoId = $data[1];
    $chatId = $callbackQuery['message']['chat']['id'];

    makeAPIRequest('answerCallbackQuery', [
        'callback_query_id' => $callbackQuery['id']
    ]);

    makeAPIRequest('deleteMessage', [
        'chat_id' => $chatId,
        'message_id' => $callbackQuery['message']['message_id']
    ]);

    mostraOpzioniModifica($eventoId, $chatId);
}

/**
 * Callback per selezione campo da modificare
 */
function handleModificaCampo($callbackQuery) {
    $data = explode(':', $callbackQuery['data']);
    $campo = $data[1];
    $eventoId = $data[2];
    $chatId = $callbackQuery['message']['chat']['id'];
    $userId = $callbackQuery['from']['id'];

    $prompts = [
        'descrizione' => "Inserisci la nuova descrizione:",
        'data_ora' => "Inserisci la nuova data e ora nel formato <code>GG/MM/AAAA HH:MM</code>:",
        'costo' => "Inserisci il nuovo costo in euro (es: 15 oppure 0 se gratuito):"
    ];

    setUserState($chatId, $userId, "modifica_evento_{$campo}", ['evento_id' => $eventoId]);

    makeAPIRequest('answerCallbackQuery', [
        'callback_query_id' => $callbackQuery['id']
    ]);

    makeAPIRequest('editMessageText', [
        'chat_id' => $chatId,
        'message_id' => $callbackQuery['message']['message_id'],
        'text' => $prompts[$campo],
        'parse_mode' => 'HTML'
    ]);
}

/**
 * Gestisce l'input durante la modifica evento
 */
function handleEventModification($message) {
    global $db;

    $chatId = $message['chat']['id'];
    $userId = $message['from']['id'];
    $text = $message['text'] ?? '';

    $userState = getUserState($chatId, $userId);
    if (!$userState) {
        return null;
    }

    $state = $userState['state'];
    $data = $userState['data'] ?? [];
    $eventoId = $data['evento_id'] ?? null;

    if (!$eventoId) {
        clearUserState($chatId, $userId);
        return null;
    }

    switch ($state) {
        case 'modifica_evento_descrizione':
            $stmt = $db->prepare("UPDATE eventi SET descrizione = :val WHERE id = :id");
            $stmt->bindValue(':val', $text, SQLITE3_TEXT);
            $stmt->bindValue(':id', $eventoId, SQLITE3_INTEGER);
            $stmt->execute();
            clearUserState($chatId, $userId);
            return "Descrizione aggiornata: <b>{$text}</b>";

        case 'modifica_evento_data_ora':
            $parsed = DateTime::createFromFormat('d/m/Y H:i', $text);
            if (!$parsed) {
                return "Formato data non valido. Usa il formato <code>GG/MM/AAAA HH:MM</code>";
            }
            $stmt = $db->prepare("UPDATE eventi SET data_ora = :val WHERE id = :id");
            $stmt->bindValue(':val', $parsed->format('Y-m-d H:i:s'), SQLITE3_TEXT);
            $stmt->bindValue(':id', $eventoId, SQLITE3_INTEGER);
            $stmt->execute();
            clearUserState($chatId, $userId);
            return "Data aggiornata: <b>" . $parsed->format('d/m/Y H:i') . "</b>";

        case 'modifica_evento_costo':
            $costo = str_replace(',', '.', $text);
            if (!is_numeric($costo) || $costo < 0) {
                return "Inserisci un valore numerico valido:";
            }
            $stmt = $db->prepare("UPDATE eventi SET costo = :val WHERE id = :id");
            $stmt->bindValue(':val', floatval($costo), SQLITE3_FLOAT);
            $stmt->bindValue(':id', $eventoId, SQLITE3_INTEGER);
            $stmt->execute();
            clearUserState($chatId, $userId);
            $costoStr = floatval($costo) > 0 ? number_format(floatval($costo), 2, ',', '.') . " euro" : "Gratuito";
            return "Costo aggiornato: <b>{$costoStr}</b>";
    }

    return null;
}

////////////////////////////////////////////////////////////////////
////////////////////// CHIUSURA EVENTO /////////////////////////////
////////////////////////////////////////////////////////////////////

/**
 * /chiudi_evento - Elimina un evento (solo admin)
 */
function _chiudi_evento($chatID, $userId, $chatType) {
    if ($chatType === 'private') {
        return "Questo comando funziona solo nei gruppi.";
    }

    if (!isAdmin($chatID, $userId)) {
        return "Solo gli amministratori possono chiudere eventi.";
    }

    $eventi = getEventiAttivi($chatID);

    if (empty($eventi)) {
        return "Non ci sono eventi da chiudere.";
    }

    if (count($eventi) === 1) {
        return chiediConfermaChiusura($eventi[0], $chatID);
    }

    // Più eventi: mostra keyboard per selezione
    $keyboard = [];
    foreach ($eventi as $evento) {
        $dataOra = DateTime::createFromFormat('Y-m-d H:i:s', $evento['data_ora']);
        $label = $evento['descrizione'] . ' (' . $dataOra->format('d/m H:i') . ')';
        $keyboard[] = [['text' => $label, 'callback_data' => "chiudi_evento_select:{$evento['id']}"]];
    }

    makeAPIRequest('sendMessage', [
        'chat_id' => $chatID,
        'text' => "Quale evento vuoi chiudere?",
        'reply_markup' => json_encode(['inline_keyboard' => $keyboard])
    ]);

    return null;
}

/**
 * Chiede conferma prima di chiudere evento
 */
function chiediConfermaChiusura($evento, $chatId) {
    $keyboard = [
        [
            ['text' => 'Conferma', 'callback_data' => "conferma_chiudi_evento:{$evento['id']}"],
            ['text' => 'Annulla', 'callback_data' => "annulla_chiudi_evento"]
        ]
    ];

    makeAPIRequest('sendMessage', [
        'chat_id' => $chatId,
        'text' => "Sei sicuro di voler chiudere l'evento <b>{$evento['descrizione']}</b>?\n\nTutti i partecipanti e gli ospiti verranno rimossi.",
        'parse_mode' => 'HTML',
        'reply_markup' => json_encode(['inline_keyboard' => $keyboard])
    ]);

    return null;
}

/**
 * Callback per selezione evento da chiudere
 */
function handleChiudiEventoSelect($callbackQuery) {
    global $db;

    $data = explode(':', $callbackQuery['data']);
    $eventoId = $data[1];
    $chatId = $callbackQuery['message']['chat']['id'];

    $stmt = $db->prepare("SELECT * FROM eventi WHERE id = :id");
    $stmt->bindValue(':id', $eventoId, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $evento = $result->fetchArray(SQLITE3_ASSOC);

    makeAPIRequest('answerCallbackQuery', [
        'callback_query_id' => $callbackQuery['id']
    ]);

    makeAPIRequest('deleteMessage', [
        'chat_id' => $chatId,
        'message_id' => $callbackQuery['message']['message_id']
    ]);

    chiediConfermaChiusura($evento, $chatId);
}

/**
 * Callback conferma chiusura evento
 */
function handleConfermaChiudiEvento($callbackQuery) {
    global $db;

    $data = explode(':', $callbackQuery['data']);
    $eventoId = $data[1];
    $chatId = $callbackQuery['message']['chat']['id'];

    // Ottieni info evento prima di eliminare
    $stmt = $db->prepare("SELECT descrizione FROM eventi WHERE id = :id");
    $stmt->bindValue(':id', $eventoId, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $evento = $result->fetchArray(SQLITE3_ASSOC);

    // Elimina ospiti
    $stmt = $db->prepare("DELETE FROM ospiti_eventi WHERE evento_id = :id");
    $stmt->bindValue(':id', $eventoId, SQLITE3_INTEGER);
    $stmt->execute();

    // Elimina partecipanti
    $stmt = $db->prepare("DELETE FROM partecipanti_eventi WHERE evento_id = :id");
    $stmt->bindValue(':id', $eventoId, SQLITE3_INTEGER);
    $stmt->execute();

    // Elimina evento
    $stmt = $db->prepare("DELETE FROM eventi WHERE id = :id");
    $stmt->bindValue(':id', $eventoId, SQLITE3_INTEGER);
    $stmt->execute();

    makeAPIRequest('answerCallbackQuery', [
        'callback_query_id' => $callbackQuery['id'],
        'text' => 'Evento chiuso'
    ]);

    makeAPIRequest('editMessageText', [
        'chat_id' => $chatId,
        'message_id' => $callbackQuery['message']['message_id'],
        'text' => "L'evento <b>{$evento['descrizione']}</b> è stato chiuso.",
        'parse_mode' => 'HTML'
    ]);
}

/**
 * Callback annulla chiusura evento
 */
function handleAnnullaChiudiEvento($callbackQuery) {
    $chatId = $callbackQuery['message']['chat']['id'];

    makeAPIRequest('answerCallbackQuery', [
        'callback_query_id' => $callbackQuery['id']
    ]);

    makeAPIRequest('editMessageText', [
        'chat_id' => $chatId,
        'message_id' => $callbackQuery['message']['message_id'],
        'text' => "Operazione annullata."
    ]);
}

////////////////////////////////////////////////////////////////////
////////////////////// GESTIONE OSPITI /////////////////////////////
////////////////////////////////////////////////////////////////////

/**
 * Ottiene gli ospiti inseriti da un utente
 */
function getOspitiUtente($chatId, $userId) {
    global $db;

    $stmt = $db->prepare("
        SELECT o.id, o.nome_ospite, o.evento_id, e.descrizione
        FROM ospiti_eventi o
        JOIN eventi e ON o.evento_id = e.id
        WHERE e.chat_id = :chatId AND o.invitante_id = :userId AND datetime(e.data_ora) >= datetime('now', 'localtime')
    ");
    $stmt->bindValue(':chatId', $chatId, SQLITE3_INTEGER);
    $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
    $result = $stmt->execute();

    $ospiti = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $ospiti[] = $row;
    }
    return $ospiti;
}

/**
 * /ospite [nome] - Aggiunge un ospite a un evento
 */
function _ospite($text, $chatID, $userId, $userName) {
    global $db;

    $parts = explode(' ', $text, 2);
    if (count($parts) < 2 || trim($parts[1]) === '') {
        return "Usa /ospite seguito dal nome dell'ospite.\nEsempio: <code>/ospite Mario Rossi</code>";
    }

    $nomeOspite = trim($parts[1]);

    // Verifica che l'utente sia iscritto ad almeno un evento
    $partecipazioni = getPartecipazioniUtente($chatID, $userId);

    if (empty($partecipazioni)) {
        return "Devi prima iscriverti a un evento con /partecipo prima di poter aggiungere ospiti.";
    }

    if (count($partecipazioni) === 1) {
        return aggiungiOspite($partecipazioni[0]['evento_id'], $userId, $userName, $nomeOspite);
    }

    // Più eventi: mostra keyboard per selezione
    $keyboard = [];
    foreach ($partecipazioni as $p) {
        $dataOra = DateTime::createFromFormat('Y-m-d H:i:s', $p['data_ora']);
        $label = $p['descrizione'] . ' (' . $dataOra->format('d/m') . ')';
        // Codifichiamo il nome ospite nel callback_data
        $keyboard[] = [['text' => $label, 'callback_data' => "ospite_evento:{$p['evento_id']}"]];
    }

    // Salva il nome ospite nello stato per recuperarlo dopo
    setUserState($chatID, $userId, 'waiting_ospite_evento', ['nome_ospite' => $nomeOspite, 'invitante_name' => $userName]);

    makeAPIRequest('sendMessage', [
        'chat_id' => $chatID,
        'text' => "A quale evento vuoi aggiungere <b>{$nomeOspite}</b>?",
        'parse_mode' => 'HTML',
        'reply_markup' => json_encode(['inline_keyboard' => $keyboard])
    ]);

    return null;
}

/**
 * Callback per selezione evento per ospite
 */
function handleOspiteEvento($callbackQuery) {
    global $db;

    $data = explode(':', $callbackQuery['data']);
    $eventoId = $data[1];
    $userId = $callbackQuery['from']['id'];
    $chatId = $callbackQuery['message']['chat']['id'];

    // Recupera il nome ospite dallo stato
    $userState = getUserState($chatId, $userId);
    if (!$userState || !isset($userState['data']['nome_ospite'])) {
        makeAPIRequest('answerCallbackQuery', [
            'callback_query_id' => $callbackQuery['id'],
            'text' => 'Sessione scaduta, riprova'
        ]);
        return;
    }

    $nomeOspite = $userState['data']['nome_ospite'];
    $invitanteName = $userState['data']['invitante_name'];
    clearUserState($chatId, $userId);

    $response = aggiungiOspite($eventoId, $userId, $invitanteName, $nomeOspite);

    makeAPIRequest('answerCallbackQuery', [
        'callback_query_id' => $callbackQuery['id']
    ]);

    makeAPIRequest('editMessageText', [
        'chat_id' => $chatId,
        'message_id' => $callbackQuery['message']['message_id'],
        'text' => $response,
        'parse_mode' => 'HTML'
    ]);
}

/**
 * Aggiunge un ospite a un evento
 */
function aggiungiOspite($eventoId, $userId, $userName, $nomeOspite) {
    global $db;

    // Verifica che l'evento esista
    $stmt = $db->prepare("SELECT descrizione FROM eventi WHERE id = :id");
    $stmt->bindValue(':id', $eventoId, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $evento = $result->fetchArray(SQLITE3_ASSOC);

    if (!$evento) {
        return "Evento non trovato.";
    }

    // Verifica che l'utente sia iscritto
    $stmt = $db->prepare("SELECT id FROM partecipanti_eventi WHERE evento_id = :eventoId AND user_id = :userId");
    $stmt->bindValue(':eventoId', $eventoId, SQLITE3_INTEGER);
    $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
    $result = $stmt->execute();

    if (!$result->fetchArray()) {
        return "Devi essere iscritto all'evento per aggiungere ospiti.";
    }

    // Aggiungi l'ospite
    $stmt = $db->prepare("INSERT INTO ospiti_eventi (evento_id, invitante_id, invitante_name, nome_ospite) VALUES (:eventoId, :invitanteId, :invitanteName, :nomeOspite)");
    $stmt->bindValue(':eventoId', $eventoId, SQLITE3_INTEGER);
    $stmt->bindValue(':invitanteId', $userId, SQLITE3_INTEGER);
    $stmt->bindValue(':invitanteName', trim($userName), SQLITE3_TEXT);
    $stmt->bindValue(':nomeOspite', $nomeOspite, SQLITE3_TEXT);
    $stmt->execute();

    return "Ho aggiunto <b>{$nomeOspite}</b> come tuo ospite per <b>{$evento['descrizione']}</b>.";
}

/**
 * /annullo_ospite - Rimuove un ospite
 */
function _annullo_ospite($chatID, $userId, $userName) {
    $ospiti = getOspitiUtente($chatID, $userId);

    if (empty($ospiti)) {
        return "Non hai ospiti da rimuovere.";
    }

    if (count($ospiti) === 1) {
        return annullaOspite($ospiti[0]['id'], $userId);
    }

    // Più ospiti: mostra keyboard per selezione
    $keyboard = [];
    foreach ($ospiti as $o) {
        $label = "{$o['nome_ospite']} ({$o['descrizione']})";
        $keyboard[] = [['text' => $label, 'callback_data' => "annullo_ospite:{$o['id']}"]];
    }

    makeAPIRequest('sendMessage', [
        'chat_id' => $chatID,
        'text' => "Quale ospite vuoi rimuovere?",
        'reply_markup' => json_encode(['inline_keyboard' => $keyboard])
    ]);

    return null;
}

/**
 * Callback per annullamento ospite
 */
function handleAnnulloOspiteCallback($callbackQuery) {
    $data = explode(':', $callbackQuery['data']);
    $ospiteId = $data[1];
    $userId = $callbackQuery['from']['id'];
    $chatId = $callbackQuery['message']['chat']['id'];

    $response = annullaOspite($ospiteId, $userId);

    makeAPIRequest('answerCallbackQuery', [
        'callback_query_id' => $callbackQuery['id']
    ]);

    makeAPIRequest('editMessageText', [
        'chat_id' => $chatId,
        'message_id' => $callbackQuery['message']['message_id'],
        'text' => $response,
        'parse_mode' => 'HTML'
    ]);
}

/**
 * Annulla un ospite
 */
function annullaOspite($ospiteId, $userId) {
    global $db;

    // Verifica che l'ospite appartenga all'utente
    $stmt = $db->prepare("SELECT nome_ospite FROM ospiti_eventi WHERE id = :id AND invitante_id = :userId");
    $stmt->bindValue(':id', $ospiteId, SQLITE3_INTEGER);
    $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $ospite = $result->fetchArray(SQLITE3_ASSOC);

    if (!$ospite) {
        return "Ospite non trovato o non sei autorizzato a rimuoverlo.";
    }

    $stmt = $db->prepare("DELETE FROM ospiti_eventi WHERE id = :id");
    $stmt->bindValue(':id', $ospiteId, SQLITE3_INTEGER);
    $stmt->execute();

    return "Ho rimosso <b>{$ospite['nome_ospite']}</b> dalla lista ospiti.";
}

/**
 * Gestisce tutti gli input relativi agli eventi
 */
function handleEventInput($message) {
    $chatId = $message['chat']['id'];
    $userId = $message['from']['id'];

    $userState = getUserState($chatId, $userId);
    if (!$userState) {
        return null;
    }

    $state = $userState['state'];

    // Creazione evento
    if (in_array($state, ['waiting_event_description', 'waiting_event_datetime', 'waiting_event_cost'])) {
        return handleEventCreation($message);
    }

    // Modifica evento
    if (strpos($state, 'modifica_evento_') === 0) {
        return handleEventModification($message);
    }

    return null;
}
?>
