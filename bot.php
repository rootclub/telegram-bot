<?php
include "config.php";
$db = new SQLite3(DB_FILE);

function initDatabase() {
    global $db;
    $db->exec("CREATE TABLE IF NOT EXISTS pappatoie (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        pappatoia TEXT NOT NULL UNIQUE
    )");
    $db->exec("CREATE TABLE IF NOT EXISTS immagini_pappatoie (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        pappatoia_id INTEGER,
        immagine TEXT,
        indirizzo TEXT,
        telefono TEXT,
        FOREIGN KEY (pappatoia_id) REFERENCES pappatoie(id)
    )");
    $db->exec("CREATE TABLE IF NOT EXISTS ordini (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        data DATE NOT NULL,
        pappatoia INTEGER,
        ordinante INTEGER,
        ritirante INTEGER,
        FOREIGN KEY (pappatoia) REFERENCES pappatoie(id)
    )");
    $db->exec("CREATE TABLE IF NOT EXISTS elementi_ordini (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        id_ordine INTEGER,
        utente INTEGER,
        descrizione TEXT,
        FOREIGN KEY (id_ordine) REFERENCES ordini(id)
    )");
}

initDatabase();




function makeAPIRequest($method, $parameters) {
    $url = "https://api.telegram.org/bot" . BOT_TOKEN . "/" . $method;
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    
    // Gestione speciale per l'invio di file
    if (isset($parameters['photo']) && $parameters['photo'] instanceof CURLFile) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $parameters);
    } else {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($parameters));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    }
    
    $response = curl_exec($ch);
    
    if (curl_errno($ch)) {
        error_log('Curl error: ' . curl_error($ch));
        return false;
    }
    
    curl_close($ch);
    
    $result = json_decode($response, true);
    
    if (!$result['ok']) {
        error_log("API Error: " . print_r($result, true));
        return false;
    }
    
    return $result;
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



// Assicurati che la directory esista
if (!file_exists(IMAGE_SAVE_PATH)) {
    mkdir(IMAGE_SAVE_PATH, 0755, true);
}

function saveImage($file_id, $pappatoia_name) {
    $file_name = preg_replace('/[^A-Za-z0-9\-]/', '_', $pappatoia_name) . ".jpg";
    $file_path = IMAGE_SAVE_PATH . $file_name;
    $file_info = makeAPIRequest('getFile', ['file_id' => $file_id]);
    if ($file_info['ok']) {
        $file_url = "https://api.telegram.org/file/bot" . BOT_TOKEN . "/" . $file_info['result']['file_path'];
        $image_content = file_get_contents($file_url);
        if (file_put_contents($file_path, $image_content)) {
            return $file_name; // Ritorniamo solo il nome del file, non il percorso completo
        }
    }
    return false;
}

function handle_image($message) {
    global $db;
    
    $chat_id = $message['chat']['id'];
    
    // Verifica lo stato dell'utente
    $stmt = $db->prepare("SELECT state, data FROM user_states WHERE chat_id = :chat_id");
    $stmt->bindValue(':chat_id', $chat_id, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $row = $result->fetchArray(SQLITE3_ASSOC);
    
    if ($row && $row['state'] == 'waiting_images') {
        $pappatoia_id = $row['data'];
        $file_id = $message['photo'][count($message['photo']) - 1]['file_id'];
        $image_file = saveImage($file_id, $pappatoia_id . "_" . uniqid());
        
        if ($image_file) {
            // Aggiungi l'immagine al database
            $stmt = $db->prepare("INSERT INTO immagini_pappatoie (pappatoia_id, immagine) VALUES (:pappatoia_id, :image)");
            $stmt->bindValue(':pappatoia_id', $pappatoia_id, SQLITE3_INTEGER);
            $stmt->bindValue(':image', $image_file, SQLITE3_TEXT);
            $stmt->execute();
            
            return "Immagine del menu salvata con successo! Invia altre immagini o /fine per terminare.";
        } else {
            return "Si è verificato un errore nel salvataggio dell'immagine. Riprova.";
        }
    }
    
    return null; // Non era in attesa di immagini, ignora
}

function finish_adding_images($chat_id) {
    global $db;
    
    $stmt = $db->prepare("SELECT state, data FROM user_states WHERE chat_id = :chat_id");
    $stmt->bindValue(':chat_id', $chat_id, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $row = $result->fetchArray(SQLITE3_ASSOC);
    
    if ($row && $row['state'] == 'waiting_images') {
        // Rimuovi lo stato dell'utente
        $db->exec("DELETE FROM user_states WHERE chat_id = $chat_id");
        return "Hai terminato di aggiungere immagini al menu. Grazie!";
    }
    
    return "Non stavi aggiungendo immagini a nessun menu.";
}


$update = json_decode(file_get_contents('php://input'), true);
file_put_contents('debug.log', print_r($update, true) . "\n\n", FILE_APPEND);

function processMessage($message) {
    $chatID = $message['chat']['id'];
    $text = $message['text'] ?? '';
    $chatType = $message['chat']['type'];
    $fromId = $message['from']['id'];
    $firstName = $message['from']['first_name'] ?? 'Utente';
    $response = null;


	
    if ($text == '/start' || $text == '/start@rootbotbot') {
        $response = _start($chatType);
        
    } elseif ($text == '/help' || $text == '/help@rootbotbot') {
        $response = _help();
        
    } elseif ($text == '/info' || $text == '/info@rootbotbot') {
        $response = _info($chatID, $chatType);
        
    } elseif ($text == '/regole' || $text == '/regole@rootbotbot') {
        $response = _regole();
        
    } elseif (strpos($text, '/mangerei') === 0) {
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
        
    } elseif ($text == '/fine' || $text == '/fine@rootbotbot') {
        $response = finish_adding_images($chatID);
        
    } elseif ($text == '/annullo' || $text == '/annullo@rootbotbot') {
        $userName = $message['from']['first_name'] . ' ' . ($message['from']['last_name'] ?? '');
        $response = _annullo($fromId, $userName);
        
    } elseif (isset($message['photo'])) {
        $response = handle_image($message);
        
    } elseif ($text == '/elimina_asporto') {
        $response = elimina_pappatoia($chatID, $fromId);
        
    } elseif (preg_match('/@root\b/', $text)) {
        $response = _ai($chatID, $chatType, $text);
    
    } elseif (strpos($text, '/') === 0) {
        $response = "Il comando che hai inserito non lo conosco, controlla meglio cosa hai digitato";
    }


    // Invia la risposta solo se è stata impostata
    if ($response !== null) {
        makeAPIRequest('sendMessage', [
            'chat_id' => $chatID,
            'text' => $response,
            'parse_mode' => 'HTML',
            'reply_to_message_id' => $message['message_id']
        ]);
    }
}

if (isset($update['message'])) {
    processMessage($update['message']);
} elseif (isset($update['edited_message'])) {
    processMessage($update['edited_message']);
} elseif (isset($update['callback_query'])) {
    $callbackQuery = $update['callback_query'];
    $data = $callbackQuery['data'];
    
    if (strpos($data, 'seleziona_pappatoia:') === 0) {
        gestisci_selezione_pappatoia($callbackQuery);
    } elseif (strpos($data, 'delete_pappatoia:') === 0) {
        handle_delete_pappatoia($callbackQuery);
    } elseif (strpos($data, 'confirm_delete_pappatoia:') === 0) {
        confirm_delete_pappatoia($callbackQuery);
    } elseif ($data === 'cancel_delete_pappatoia') {
        cancel_delete_pappatoia($callbackQuery);
    }elseif (strpos($data, 'show_pappatoia_images:') === 0) {
    	handle_show_pappatoia_images($callbackQuery);
	}
}

http_response_code(200);

function _start($chatType){
	if ($chatType == 'private') {
		return "Ciao! Sono il bot del root. usa /help per vedere cosa posso fare";
	}else{
		return "Ciao a tutti! Sono il bot di questo gruppo. Usate /help per vedere cosa posso fare.";
	}
}

function _help(){
	return "Ecco cosa posso fare:
/info - Informazioni sul gruppo
/regole - Mostra le regole del gruppo
@root seguito da un messaggio per parlare con me (è implemetanto sommariamente, usa ollama ma per ora gira sul PC di Lamberto per cui quando il PC è spento il bot non risponde)
	
Posso aiutare per raccogliere gli ordini per le cene al root, ecco i comandi:
/mangerei - crea nuovo ordine oppure aggiungi elemento all'ordine odierno
/annullo - elimini la tua pietanza dall'ordine, se tutti annullano l'ordine viene eliminato
/lista - mostra la lista delle pietanza di cui è composto l'ordine
/ordino - ti offri per telefonare e piazzare l'ordine presso l'asporto scelto
/ordina @utente - designi una persona per telefonare e piazzare l'ordine presso l'asporto scelto
/ritiro - ti offri per ritirare le pietanze presso l'asporto scelto
/ritira @utente - designi una persona per ritirare le pietanze presso l'asporto scelto
/elenco_asporto - mostra i locali da asporto predefiniti e permette di consultarne i menù
/asporto - per scegliere da quale locale da asporto si ordinerà il cibo 
/nuovo_asporto - aggiungi un nuovo locale da asporto
/menu - mostra il menù dell'asporto scelto per l'ordine in corso
/elimina_asporto - elimina un locale da asporto dai predefiniti (solo per amministratori)";
}


function _info($chatID, $chatType){
	if ($chatType == 'private') {
		return "Ma che info vuoi che siamo solo io e te?";
	}else{
		$chatInfo = makeAPIRequest('getChat', ['chat_id' => $chatID]);
		$memberCount = makeAPIRequest('getChatMemberCount', ['chat_id' => $chatID]);
		return "Informazioni sul gruppo:\nNome: " . $chatInfo['result']['title'] . "\nMembri: " . $memberCount['result'];
	}
}

function _regole(){
	return "Regole del gruppo:
0. Si incomincia a contare da 0
1. Parlate di quello che vi pare eccetto:
1. Niente pseudoscienze
2. Niente complottismi
3. Non si usano messaggi vocali, i vocali sono il male assoluto. Ogni vocale verrà utilizzato per addestrare una voce artificiale per doppiare i porno LGBT+
4. Usa tab per indentare il codice, non spazi multipli, no scherzo, il codice è il tuo, fai come ti pare, però se usi gli spazi sappi che sei una brutta persona :-D ";
	
}



function _ai($chatID, $chatType, $message) {
    $ollamaUrl = OLLAMA_URL;
    $model = OLLAMA_MODEL;

    // Rimuovi "@root" dal messaggio
    $prompt = trim(str_replace('@root', '', $message));

    $data = json_encode([
        'model' => $model,
        'prompt' => $prompt,
        'stream' => true
    ]);

    $ch = curl_init($ollamaUrl);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

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
        return "Si è verificato un errore durante la comunicazione con l'AI: " . curl_error($ch);
    }
    curl_close($ch);

    // Tronca la risposta se supera il limite di caratteri di Telegram
    if (mb_strlen($response) > 4096) {
        $response = mb_substr($response, 0, 4093) . '...';
    }

    return $response;
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
        $stmt = $db->prepare("INSERT INTO elementi_ordini (id_ordine, utente, descrizione) VALUES (:orderId, :userName, :item)");
        $stmt->bindValue(':orderId', $orderId, SQLITE3_INTEGER);
        $stmt->bindValue(':userName', $userName, SQLITE3_TEXT);
        $stmt->bindValue(':item', $item, SQLITE3_TEXT);
        $stmt->execute();
        
        // Richiedi la selezione della pappatoia
        return richiedi_selezione_pappatoia($orderId, $item, $chatID);
    } else {
        $orderId = $row['id'];
        $pappatoia = $row['pappatoia'];
        
        // Controlla se l'utente ha già un ordine per oggi
        $stmt = $db->prepare("SELECT id FROM elementi_ordini WHERE id_ordine = :orderId AND utente = :userName");
        $stmt->bindValue(':orderId', $orderId, SQLITE3_INTEGER);
        $stmt->bindValue(':userName', $userName, SQLITE3_TEXT);
        $result = $stmt->execute();
        $existingOrder = $result->fetchArray(SQLITE3_ASSOC);
        
        if ($existingOrder) {
            // Aggiorna l'ordine esistente
            $stmt = $db->prepare("UPDATE elementi_ordini SET descrizione = :item WHERE id = :id");
            $stmt->bindValue(':item', $item, SQLITE3_TEXT);
            $stmt->bindValue(':id', $existingOrder['id'], SQLITE3_INTEGER);
            $stmt->execute();
            $message = "Ho aggiornato il tuo ordine a '$item'.";
        } else {
            // Aggiungi il nuovo elemento a elementi_ordini
            $stmt = $db->prepare("INSERT INTO elementi_ordini (id_ordine, utente, descrizione) VALUES (:orderId, :userName, :item)");
            $stmt->bindValue(':orderId', $orderId, SQLITE3_INTEGER);
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
    
    $stmt = $db->prepare("SELECT id, pappatoia FROM pappatoie ORDER BY pappatoia");
    $result = $stmt->execute();
    
    $keyboard = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $keyboard[] = [['text' => $row['pappatoia'], 'callback_data' => "seleziona_pappatoia:{$orderId}:{$row['id']}"]];
    }
    
    $replyMarkup = [
        'inline_keyboard' => $keyboard
    ];
    
    makeAPIRequest('sendMessage', [
        'chat_id' => $chatID,
        'text' => "Ho aggiunto '$item' all'ordine. Per favore, seleziona l'asporto per questo ordine:",
        'reply_markup' => json_encode($replyMarkup)
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
    
    makeAPIRequest('editMessageText', [
        'chat_id' => $callbackQuery['message']['chat']['id'],
        'message_id' => $callbackQuery['message']['message_id'],
        'text' => "Asporto '{$pappatoiaInfo['pappatoia']}' selezionato per l'ordine."
    ]);
}



function _lista() {
    global $db;
    $stmt = $db->prepare("
        SELECT o.id, o.ordinante, o.ritirante, o.pappatoia, 
               e.utente, e.descrizione, 
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
                'ritirante' => $row['ritirante'],
                'pappatoia' => $row['nome_pappatoia'],
                'indirizzo' => $row['indirizzo'],
                'telefono' => $row['telefono']
            ];
        }
        if ($row['utente'] && $row['descrizione']) {
            $items[] = "{$row['utente']}: {$row['descrizione']}";
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
        
        if ($ordine['ordinante']) {
            $response .= "🛒 Ordina: {$ordine['ordinante']}\n";
        } else {
            $response .= "🛒 Nessuno si è ancora offerto di ordinare. Usa /ordino per offrirti!\n";
        }
        
        if ($ordine['ritirante']) {
            $response .= "🚚 Ritira: {$ordine['ritirante']}\n";
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
        $stmt = $db->prepare("INSERT INTO ordini (data, ordinante) VALUES (date('now'), :userName)");
        $stmt->bindValue(':userName', $userName, SQLITE3_TEXT);
        $stmt->execute();
        return "Grazie per esserti offerto per telefonare all'asporto designato e piazzare l'ordine di oggi.";
    } else {
        $orderId = $row['id'];
        $currentOrdinante = $row['ordinante'];
        
        if ($currentOrdinante) {
            $message = "Il compito era precedentemente assegnato a  $currentOrdinante. ";
        } else {
            $message = "";
        }
        
        // Aggiorna l'ordinante
        $stmt = $db->prepare("UPDATE ordini SET ordinante = :userName WHERE id = :orderId");
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
        $stmt = $db->prepare("INSERT INTO ordini (data, ritirante) VALUES (date('now'), :userName)");
        $stmt->bindValue(':userName', $userName, SQLITE3_TEXT);
        $stmt->execute();
        return "Grazie per esserti offerto per ritirare l'ordine di oggi presso l'asporto designato.";
    } else {
        $orderId = $row['id'];
        $currentRitirante = $row['ritirante'];
        
        if ($currentRitirante) {
            $message = "Il compito era precedentemente assegnato a $currentRitirante. ";
        } else {
            $message = "";
        }
        
        // Aggiorna il ritirante
        $stmt = $db->prepare("UPDATE ordini SET ritirante = :userName WHERE id = :orderId");
        $stmt->bindValue(':userName', $userName, SQLITE3_TEXT);
        $stmt->bindValue(':orderId', $orderId, SQLITE3_INTEGER);
        $stmt->execute();
        
        return $message . "Grazie per esserti offerto per ritirare l'ordine di oggi presso l'asporto designato.";
    }
}



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
    
    if (count($parts) < 3) {
        return "Uso corretto: /nuovo_asporto Nome, Indirizzo, Telefono";
    }
    
    // Trimma e normalizza gli spazi nel nome della pappatoia
    $pappatoia_name = preg_replace('/\s+/', ' ', trim($parts[0]));
    $indirizzo = trim($parts[1]);
    $telefono = trim($parts[2]);
    
    if (empty($pappatoia_name) || empty($indirizzo) || empty($telefono)) {
        return "Tutti i campi (nome, indirizzo, telefono) sono obbligatori.";
    }
    
    // Verifica il formato del numero di telefono
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
    $stmt = $db->prepare("INSERT INTO pappatoie (pappatoia, indirizzo, telefono) VALUES (:name, :indirizzo, :telefono)");
    $stmt->bindValue(':name', $pappatoia_name, SQLITE3_TEXT);
    $stmt->bindValue(':indirizzo', $indirizzo, SQLITE3_TEXT);
    $stmt->bindValue(':telefono', $telefono, SQLITE3_TEXT);
    $stmt->execute();
    $pappatoia_id = $db->lastInsertRowID();
    
    // Richiedi l'immagine
    $response = "Asporto '$pappatoia_name' aggiunto con indirizzo: $indirizzo e telefono: $telefono. Ora invia le immagini per il menu. Invia /fine quando hai terminato.";
    makeAPIRequest('sendMessage', [
        'chat_id' => $chat_id,
        'text' => $response,
        'reply_to_message_id' => $message_id
    ]);
    
    // Imposta lo stato dell'utente per aspettare le immagini
    $db->exec("CREATE TABLE IF NOT EXISTS user_states (chat_id INTEGER, state TEXT, data TEXT)");
    $stmt = $db->prepare("INSERT OR REPLACE INTO user_states (chat_id, state, data) VALUES (:chat_id, 'waiting_images', :pappatoia_id)");
    $stmt->bindValue(':chat_id', $chat_id, SQLITE3_INTEGER);
    $stmt->bindValue(':pappatoia_id', $pappatoia_id, SQLITE3_TEXT);
    $stmt->execute();
    
    return null;
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
        makeAPIRequest('sendMessage', [
            'chat_id' => $chatId,
            'text' => "Ho inviato le informazioni del menu in privato.",
            'reply_to_message_id' => $messageId
        ]);
    }
    
    return null; // Ritorniamo null perché abbiamo già gestito tutte le risposte
}


function _pappatoie() {
    global $db;
    
    $stmt = $db->prepare("
        SELECT p.pappatoia, COUNT(ip.id) as num_immagini
        FROM pappatoie p
        LEFT JOIN immagini_pappatoie ip ON p.id = ip.pappatoia_id
        GROUP BY p.id
        ORDER BY p.pappatoia
    ");
    $result = $stmt->execute();
    
    $pappatoie = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $pappatoie[] = $row;
    }
    
    if (empty($pappatoie)) {
        return "Non ci sono ancora asporti disponibili.";
    } else {
        $response = "Ecco l'elenco degli asporti disponibili:\n\n";
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
        $response .= "\nUsa /menu per vedere il menu del'asporto prescelto.";
        return $response;
    }
}


function elenco_pappatoie($chatID) {
    global $db;
    
    $stmt = $db->prepare("SELECT id, pappatoia FROM pappatoie ORDER BY pappatoia");
    $result = $stmt->execute();
    
    $keyboard = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $keyboard[] = [['text' => $row['pappatoia'], 'callback_data' => "show_pappatoia_images:{$row['id']}"]];
    }
    
    if (empty($keyboard)) {
        return "Non ci sono locali da asporto disponibili.";
    }
    
    $replyMarkup = [
        'inline_keyboard' => $keyboard
    ];
    
    makeAPIRequest('sendMessage', [
        'chat_id' => $chatID,
        'text' => "Seleziona un asporto di cui vedere il menu:",
        'reply_markup' => json_encode($replyMarkup)
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
        makeAPIRequest('sendMessage', [
            'chat_id' => $callbackQuery['message']['chat']['id'],
            'text' => "Non ci sono immagini del menu disponibili per $pappatoia."
        ]);
    } else {
        makeAPIRequest('sendMessage', [
            'chat_id' => $callbackQuery['message']['chat']['id'],
            'text' => "Menu di $pappatoia:"
        ]);
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
    
    $stmt = $db->prepare("SELECT id, pappatoia FROM pappatoie ORDER BY pappatoia");
    $result = $stmt->execute();
    
    $keyboard = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $keyboard[] = [['text' => $row['pappatoia'], 'callback_data' => "seleziona_pappatoia:{$orderId}:{$row['id']}"]];
    }
    
    $replyMarkup = [
        'inline_keyboard' => $keyboard
    ];
    
    makeAPIRequest('sendMessage', [
        'chat_id' => $chatID,
        'text' => "Seleziona l'asporto per l'ordine di oggi:",
        'reply_markup' => json_encode($replyMarkup)
    ]);
    
    return null; // Ritorniamo null perché abbiamo già inviato il messaggio
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

    // Ottieni l'ID della pappatoia dell'ordine corrente, se esiste
    $stmt = $db->prepare("SELECT pappatoia FROM ordini WHERE date(data) = date('now') LIMIT 1");
    $result = $stmt->execute();
    $currentOrder = $result->fetchArray(SQLITE3_ASSOC);
    $currentPappatoiaId = $currentOrder ? $currentOrder['pappatoia'] : null;

    // Ottieni tutte le pappatoie tranne quella dell'ordine corrente
    $stmt = $db->prepare("SELECT id, pappatoia FROM pappatoie WHERE id != :currentId ORDER BY pappatoia");
    $stmt->bindValue(':currentId', $currentPappatoiaId, SQLITE3_INTEGER);
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

    makeAPIRequest('sendMessage', [
        'chat_id' => $chatID,
        'text' => "Seleziona l'asporto da eliminare:",
        'reply_markup' => json_encode($replyMarkup)
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

    makeAPIRequest('editMessageText', [
        'chat_id' => $callbackQuery['message']['chat']['id'],
        'message_id' => $callbackQuery['message']['message_id'],
        'text' => "Sei sicuro di voler eliminare l'asporto '$pappatoiaNome'?",
        'reply_markup' => json_encode($replyMarkup)
    ]);
}

function confirm_delete_pappatoia($callbackQuery) {
    global $db;

    $data = explode(':', $callbackQuery['data']);
    $pappatoiaId = $data[1];

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

    makeAPIRequest('editMessageText', [
        'chat_id' => $callbackQuery['message']['chat']['id'],
        'message_id' => $callbackQuery['message']['message_id'],
        'text' => "Asporto eliminato."
    ]);
}

function cancel_delete_pappatoia($callbackQuery) {
    makeAPIRequest('editMessageText', [
        'chat_id' => $callbackQuery['message']['chat']['id'],
        'message_id' => $callbackQuery['message']['message_id'],
        'text' => "Eliminazione asporto annullata."
    ]);
}




?>
