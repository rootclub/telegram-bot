<?php
require_once __DIR__ . '/logger.php';

// Assicurati che la directory esista
if (!file_exists(IMAGE_SAVE_PATH)) {
    mkdir(IMAGE_SAVE_PATH, 0755, true);
}

// TODO: Rimuovere in futuro quando il sistema sarà stabile
// Funzione di logging dedicata per debug immagini
function image_log($message) {
    $logFile = logPath('image_debug');
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($logFile, "[$timestamp] $message\n", FILE_APPEND);
}

/**
 * Pulisce gli stati utente scaduti (timeout 10 minuti)
 * Da chiamare ad ogni messaggio ricevuto
 */
function cleanupExpiredUserStates() {
    global $db;

    $timeout = 10 * 60; // 10 minuti in secondi
    $expiry_time = time() - $timeout;

    // Prima trova gli stati scaduti per notificare gli utenti
    $stmt = $db->prepare("SELECT chat_id, state FROM user_states WHERE created_at IS NULL OR created_at < :expiry_time");
    $stmt->bindValue(':expiry_time', $expiry_time, SQLITE3_INTEGER);
    $result = $stmt->execute();

    $expiredStates = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $expiredStates[] = $row;
    }

    // Elimina gli stati scaduti
    if (!empty($expiredStates)) {
        $stmt = $db->prepare("DELETE FROM user_states WHERE created_at IS NULL OR created_at < :expiry_time");
        $stmt->bindValue(':expiry_time', $expiry_time, SQLITE3_INTEGER);
        $stmt->execute();

        // Notifica gli utenti
        foreach ($expiredStates as $state) {
            image_log("cleanupExpiredUserStates: processing expired state chat_id={$state['chat_id']}, state={$state['state']}");
            if ($state['state'] == 'waiting_images' || $state['state'] == 'waiting_menu_images') {
                $result = sendTelegramMessage($state['chat_id'], "Timeout: inserimento immagini menu annullato per inattività (10 minuti).");
                image_log("cleanupExpiredUserStates: notified chat_id={$state['chat_id']}, result=" . json_encode($result));
            }
        }

        image_log("cleanupExpiredUserStates: deleted " . count($expiredStates) . " expired states");
    }
}

/**
 * Controlla se abbiamo già risposto a questo media_group
 * Ritorna true se è la prima immagine del gruppo (dobbiamo rispondere)
 * Ritorna false se abbiamo già risposto a questo gruppo
 */
function shouldRespondToMediaGroup($media_group_id) {
    $trackFile = __DIR__ . '/../media_group_track.json';
    $now = time();
    $expiry = 30; // Considera i media_group degli ultimi 30 secondi

    // Leggi il file di tracking
    $tracked = [];
    if (file_exists($trackFile)) {
        $content = file_get_contents($trackFile);
        $tracked = json_decode($content, true) ?: [];
    }

    // Pulisci i vecchi media_group (più vecchi di 30 secondi)
    $tracked = array_filter($tracked, function($timestamp) use ($now, $expiry) {
        return ($now - $timestamp) < $expiry;
    });

    // Controlla se questo media_group è già stato visto
    if (isset($tracked[$media_group_id])) {
        // Già risposto a questo gruppo
        file_put_contents($trackFile, json_encode($tracked));
        return false;
    }

    // Prima immagine di questo gruppo - segna come visto
    $tracked[$media_group_id] = $now;
    file_put_contents($trackFile, json_encode($tracked));
    return true;
}

/**
 * Verifica se un documento è un'immagine basandosi sul mime_type
 */
function isImageDocument($document) {
    if (!isset($document['mime_type'])) {
        return false;
    }
    $mime = $document['mime_type'];
    return strpos($mime, 'image/') === 0;
}

/**
 * Gestisce un'immagine inviata come documento (senza compressione)
 * La salva come una normale immagine
 */
function handleDocumentImage($message) {
    global $db;

    $chat_id = $message['chat']['id'];
    $is_media_group = isset($message['media_group_id']);
    $media_group_id = $message['media_group_id'] ?? 'single';

    image_log("handleDocumentImage: chat_id=$chat_id, is_media_group=$is_media_group");

    // Verifica se l'utente è in stato di attesa immagini
    $stmt = $db->prepare("SELECT state, data FROM user_states WHERE chat_id = :chat_id");
    $stmt->bindValue(':chat_id', $chat_id, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $row = $result->fetchArray(SQLITE3_ASSOC);

    if ($row && ($row['state'] == 'waiting_images' || $row['state'] == 'waiting_menu_images')) {
        $pappatoia_id = $row['data'];
        $file_id = $message['document']['file_id'];

        image_log("handleDocumentImage: saving document image for pappatoia_id=$pappatoia_id");

        $image_file = saveImage($file_id, $pappatoia_id . "_" . uniqid());

        if ($image_file) {
            // Aggiungi l'immagine al database
            $stmt = $db->prepare("INSERT INTO immagini_pappatoie (pappatoia_id, immagine) VALUES (:pappatoia_id, :image)");
            $stmt->bindValue(':pappatoia_id', $pappatoia_id, SQLITE3_INTEGER);
            $stmt->bindValue(':image', $image_file, SQLITE3_TEXT);
            $stmt->execute();

            image_log("handleDocumentImage: image saved successfully: $image_file");

            // Se fa parte di un media_group, rispondi solo una volta
            if ($is_media_group) {
                if (shouldRespondToMediaGroup($media_group_id)) {
                    return "Immagini ricevute e salvate! Invia altre immagini o /fine per terminare.";
                }
                return null;
            }

            return "Immagine del menu salvata con successo! Invia altre immagini o /fine per terminare.";
        } else {
            image_log("handleDocumentImage: ERROR saving image");
            return "Si è verificato un errore nel salvataggio dell'immagine. Riprova.";
        }
    }

    return null; // Non era in attesa di immagini, ignora
}

/**
 * Gestisce un documento non-immagine quando l'utente è in attesa di immagini
 */
function handleNonImageDocument($message) {
    global $db;

    $chat_id = $message['chat']['id'];

    // Verifica se l'utente è in stato di attesa immagini
    $stmt = $db->prepare("SELECT state FROM user_states WHERE chat_id = :chat_id");
    $stmt->bindValue(':chat_id', $chat_id, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $row = $result->fetchArray(SQLITE3_ASSOC);

    if ($row && ($row['state'] == 'waiting_images' || $row['state'] == 'waiting_menu_images')) {
        $fileName = $message['document']['file_name'] ?? 'file';
        return "Il file '$fileName' non è un'immagine. Per favore invia solo immagini (JPG, PNG, ecc.) o /fine per terminare.";
    }

    return null; // Non era in attesa di immagini, ignora
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
    $is_media_group = isset($message['media_group_id']);
    $media_group_id = $message['media_group_id'] ?? 'single';

    // Log per debug
    image_log("handle_image: chat_id=$chat_id, is_media_group=$is_media_group, media_group_id=$media_group_id");

    // Verifica lo stato dell'utente
    $stmt = $db->prepare("SELECT state, data FROM user_states WHERE chat_id = :chat_id");
    $stmt->bindValue(':chat_id', $chat_id, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $row = $result->fetchArray(SQLITE3_ASSOC);

    image_log("handle_image: user_state=" . ($row ? $row['state'] : 'none') . ", pappatoia_id=" . ($row ? $row['data'] : 'none'));

    if ($row && ($row['state'] == 'waiting_images' || $row['state'] == 'waiting_menu_images')) {
        $pappatoia_id = $row['data'];
        $file_id = $message['photo'][count($message['photo']) - 1]['file_id'];

        image_log("handle_image: saving image for pappatoia_id=$pappatoia_id, file_id=$file_id");

        $image_file = saveImage($file_id, $pappatoia_id . "_" . uniqid());

        if ($image_file) {
            // Aggiungi l'immagine al database
            $stmt = $db->prepare("INSERT INTO immagini_pappatoie (pappatoia_id, immagine) VALUES (:pappatoia_id, :image)");
            $stmt->bindValue(':pappatoia_id', $pappatoia_id, SQLITE3_INTEGER);
            $stmt->bindValue(':image', $image_file, SQLITE3_TEXT);
            $stmt->execute();

            image_log("handle_image: image saved successfully: $image_file");

            // Se fa parte di un media_group, rispondi solo una volta per tutto il gruppo
            if ($is_media_group) {
                if (shouldRespondToMediaGroup($media_group_id)) {
                    return "Immagini ricevute e salvate! Invia altre immagini o /fine per terminare.";
                }
                return null; // Già risposto per questo gruppo
            }

            return "Immagine del menu salvata con successo! Invia altre immagini o /fine per terminare.";
        } else {
            image_log("handle_image: ERROR saving image");
            // Errore: rispondi sempre per informare l'utente
            return "Si è verificato un errore nel salvataggio dell'immagine. Riprova.";
        }
    } elseif ($is_media_group) {
        // Se è un media_group ma non siamo in stato di attesa immagini,
        // potrebbe essere che l'utente sta inviando più foto senza aver avviato il flusso
        // Ignora silenziosamente per non spammare
        image_log("handle_image: media_group but not waiting for images, ignoring");
        return null;
    }

    image_log("handle_image: not waiting for images, ignoring");
    return null; // Non era in attesa di immagini, ignora
}

function finish_adding_images($chat_id) {
    global $db;

    $stmt = $db->prepare("SELECT state, data FROM user_states WHERE chat_id = :chat_id");
    $stmt->bindValue(':chat_id', $chat_id, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $row = $result->fetchArray(SQLITE3_ASSOC);

    if ($row && ($row['state'] == 'waiting_images' || $row['state'] == 'waiting_menu_images')) {
        $pappatoia_id = $row['data'];

        // Conta quante immagini sono state aggiunte per questa pappatoia
        $stmt = $db->prepare("SELECT COUNT(*) as count FROM immagini_pappatoie WHERE pappatoia_id = :pappatoia_id");
        $stmt->bindValue(':pappatoia_id', $pappatoia_id, SQLITE3_INTEGER);
        $result = $stmt->execute();
        $countRow = $result->fetchArray(SQLITE3_ASSOC);
        $imageCount = $countRow['count'];

        // Rimuovi lo stato dell'utente
        $stmt = $db->prepare("DELETE FROM user_states WHERE chat_id = :chat_id");
        $stmt->bindValue(':chat_id', $chat_id, SQLITE3_INTEGER);
        $stmt->execute();

        if ($imageCount > 0) {
            $plural = $imageCount == 1 ? "immagine" : "immagini";
            return "Hai terminato di aggiungere immagini al menu. Totale: $imageCount $plural salvate. Grazie!";
        } else {
            return "Hai terminato senza aggiungere immagini al menu.";
        }
    }

    return "Non stavi aggiungendo immagini a nessun menu.";
}

?>
