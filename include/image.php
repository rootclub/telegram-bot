<?php
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
    
    if ($row && ($row['state'] == 'waiting_images' || $row['state'] == 'waiting_menu_images')) {
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
    
    if ($row && ($row['state'] == 'waiting_images' || $row['state'] == 'waiting_menu_images')) {
        // Rimuovi lo stato dell'utente
        $db->exec("DELETE FROM user_states WHERE chat_id = $chat_id");
        return "Hai terminato di aggiungere immagini al menu. Grazie!";
    }
    
    return "Non stavi aggiungendo immagini a nessun menu.";
}

?>
