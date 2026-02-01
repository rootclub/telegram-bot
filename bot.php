<?php
include "config.php";
include "include/database.php";
include "include/api.php";
include "include/image.php";
include "include/help.php";
include "include/ai.php";
include "include/moderation.php";
include "include/orders.php";
include "include/events.php";
include "include/quiz.php";
include "include/message.php";

$db = new SQLite3(DB_FILE);

initDatabase();

$update = json_decode(file_get_contents('php://input'), true);

file_put_contents('debug.log', print_r($update, true) . "\n\n", FILE_APPEND);

// Rispondi subito 200 a Telegram per evitare timeout e retry
http_response_code(200);
header('Connection: close');
header('Content-Length: 0');
ob_end_flush();
flush();

// Se disponibile, chiudi la connessione FastCGI e continua in background
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
}










////////////////////////////////////////////////////////////////////////
//////////////////////   Aggiornamento della chat   ///////////////////
////////////////////////////////////////////////////////////////////////


if (isset($update['message'])) {
    $message = $update['message'];
    $groupId = $message['chat']['id'];
    $userName = $message['from']['first_name'] ?? 'Utente';
    $chatType = $message['chat']['type'];

    // Gestisci testo
    $messageText = $message['text'] ?? '';
    $messageText = str_replace('@bot', '', $messageText);
    $messageText = str_replace('@rootbot', '', $messageText);
    $messageText = str_replace('@root', '', $messageText);

    // PRIMA: Gestisci immagini - analizza e salva nel contesto PRIMA di processMessage
    // Così l'AI avrà il contesto dell'immagine quando risponde
    $botImageAnalysis = null; // Analisi del bot da salvare separatamente
    if (isset($message['photo'])) {
        $photos = $message['photo'];
        $fileId = $photos[count($photos) - 1]['file_id']; // Prendi la versione più grande
        $caption = $message['caption'] ?? '';

        // Analizza l'immagine con AI vision (passa anche la caption per contesto)
        $imageDescription = analyzeImage($fileId, $caption);

        // Messaggio utente: ha condiviso un'immagine (+ eventuale caption)
        if ($caption) {
            $messageText = "[ha condiviso un'immagine] $caption";
        } else {
            $messageText = "[ha condiviso un'immagine]";
        }

        if ($imageDescription) {
            // Salva l'analisi come messaggio separato del bot
            $botImageAnalysis = "[analisi immagine: $imageDescription]";

            // Controlla se la caption menziona il bot
            $captionMentionsBot = !empty($caption) && (
                preg_match('/@root\b/', $caption) ||
                preg_match('/@bot\b/', $caption) ||
                preg_match('/@rootbot\b/', $caption) ||
                preg_match('/\brootbot\b/i', $caption) ||
                preg_match('/\brotbotbot\b/i', $caption)
            );

            // Rispondi con l'analisi in chat privata o quando menzionato nel gruppo
            if ($chatType == 'private' || $captionMentionsBot) {
                makeAPIRequest('sendMessage', [
                    'chat_id' => $groupId,
                    'text' => $imageDescription,
                    'reply_to_message_id' => $message['message_id']
                ]);
            }
        }
    }

    // Gestisci documenti immagine (inviati senza compressione)
    if (isset($message['document'])) {
        file_put_contents('debug.log', "=== DOCUMENT DETECTED ===\n", FILE_APPEND);
        file_put_contents('debug.log', "mime=" . ($message['document']['mime_type'] ?? 'none') . "\n", FILE_APPEND);
        file_put_contents('debug.log', "isImageDocument=" . (isImageDocument($message['document']) ? 'YES' : 'NO') . "\n", FILE_APPEND);
    }
    if (isset($message['document']) && isImageDocument($message['document'])) {
        $fileId = $message['document']['file_id'];
        $caption = $message['caption'] ?? '';
        file_put_contents('debug.log', "Processing document image, caption=" . substr($caption, 0, 50) . "\n", FILE_APPEND);

        $imageDescription = analyzeImage($fileId, $caption);
        file_put_contents('debug.log', "analyzeImage returned: " . ($imageDescription ? "OK (" . strlen($imageDescription) . " chars)" : "NULL") . "\n", FILE_APPEND);

        // Messaggio utente: ha condiviso un'immagine (+ eventuale caption)
        if ($caption) {
            $messageText = "[ha condiviso un'immagine] $caption";
        } else {
            $messageText = "[ha condiviso un'immagine]";
        }

        if ($imageDescription) {
            // Salva l'analisi come messaggio separato del bot
            $botImageAnalysis = "[analisi immagine: $imageDescription]";

            $captionMentionsBot = !empty($caption) && (
                preg_match('/@root\b/', $caption) ||
                preg_match('/@bot\b/', $caption) ||
                preg_match('/@rootbot\b/', $caption) ||
                preg_match('/\brootbot\b/i', $caption) ||
                preg_match('/\brotbotbot\b/i', $caption)
            );

            if ($chatType == 'private' || $captionMentionsBot) {
                makeAPIRequest('sendMessage', [
                    'chat_id' => $groupId,
                    'text' => $imageDescription,
                    'reply_to_message_id' => $message['message_id']
                ]);
            }
        }
    }

    // Salva nel contesto PRIMA di processMessage (così l'AI ha il contesto aggiornato)
    if (!empty(trim($messageText))) {
        saveMessageToContext($groupId, $userName, $messageText);
    }
    // Salva l'analisi immagine come messaggio separato del bot
    if (!empty($botImageAnalysis)) {
        saveMessageToContext($groupId, 'rootbot', $botImageAnalysis);
    }

    // Prima di processare, marca come "in elaborazione" se il messaggio invoca il bot
    // (per evitare doppie risposte se il messaggio viene editato durante l'elaborazione)
    $textToCheck = $message['text'] ?? $message['caption'] ?? '';
    $mentionsBot = preg_match('/@root\b/', $textToCheck) ||
                   preg_match('/@bot\b/', $textToCheck) ||
                   preg_match('/@rootbot\b/', $textToCheck) ||
                   preg_match('/\brootbot\b/i', $textToCheck) ||
                   preg_match('/\brotbotbot\b/i', $textToCheck) ||
                   $chatType == 'private';

    if ($mentionsBot) {
        markAsReplied($groupId, $message['message_id']);
    }

    // POI: Processa il messaggio (comandi, AI, ecc.)
    processMessage($message);
} elseif (isset($update['edited_message'])) {
    // Gestisce i messaggi editati solo se menzionano il bot (o chat privata) e non abbiamo già risposto
    $editedMessage = $update['edited_message'];
    $chatId = $editedMessage['chat']['id'];
    $messageId = $editedMessage['message_id'];
    $chatType = $editedMessage['chat']['type'];
    $text = $editedMessage['text'] ?? $editedMessage['caption'] ?? '';

    // Controlla se il messaggio menziona il bot
    $mentionsBot = preg_match('/@root\b/', $text) ||
                   preg_match('/@bot\b/', $text) ||
                   preg_match('/@rootbot\b/', $text) ||
                   preg_match('/\brootbot\b/i', $text) ||
                   preg_match('/\brotbotbot\b/i', $text);

    // Processa solo se: (menziona il bot O è chat privata) E non abbiamo già risposto
    if (($mentionsBot || $chatType == 'private') && !hasAlreadyReplied($chatId, $messageId)) {
        // Marca subito come "in elaborazione" per evitare race condition
        if (markAsReplied($chatId, $messageId)) {
            // Salva nel contesto e processa come un messaggio normale
            $userName = $editedMessage['from']['first_name'] ?? 'Utente';
            $messageText = str_replace('@bot', '', $text);
            $messageText = str_replace('@rootbot', '', $messageText);
            $messageText = str_replace('@root', '', $messageText);

            if (!empty(trim($messageText))) {
                saveMessageToContext($chatId, $userName, $messageText);
            }

            processMessage($editedMessage);
        }
    }
} elseif (isset($update['callback_query'])) {
    $callbackQuery = $update['callback_query'];
    $data = $callbackQuery['data'];

    // Callback ordini/pappatoie
    if (strpos($data, 'seleziona_pappatoia:') === 0) {
        gestisci_selezione_pappatoia($callbackQuery);
    } elseif (strpos($data, 'delete_pappatoia:') === 0) {
        handle_delete_pappatoia($callbackQuery);
    } elseif (strpos($data, 'confirm_delete_pappatoia:') === 0) {
        confirm_delete_pappatoia($callbackQuery);
    } elseif ($data === 'cancel_delete_pappatoia') {
        cancel_delete_pappatoia($callbackQuery);
    } elseif (strpos($data, 'show_pappatoia_images:') === 0) {
        handle_show_pappatoia_images($callbackQuery);
    } elseif (strpos($data, 'select_pappatoia_for_menu:') === 0) {
        handle_select_pappatoia_for_menu($callbackQuery);
    } elseif (strpos($data, 'menu_action:') === 0) {
        handle_menu_action($callbackQuery);
    }
    // Callback eventi
    elseif (strpos($data, 'partecipo_evento:') === 0) {
        handlePartecipoEvento($callbackQuery);
    } elseif (strpos($data, 'lista_partecipanti:') === 0) {
        handleListaPartecipanti($callbackQuery);
    } elseif (strpos($data, 'annullo_tipo:') === 0) {
        handleAnnulloTipo($callbackQuery);
    } elseif (strpos($data, 'modifica_evento_select:') === 0) {
        handleModificaEventoSelect($callbackQuery);
    } elseif (strpos($data, 'modifica_campo:') === 0) {
        handleModificaCampo($callbackQuery);
    } elseif (strpos($data, 'chiudi_evento_select:') === 0) {
        handleChiudiEventoSelect($callbackQuery);
    } elseif (strpos($data, 'conferma_chiudi_evento:') === 0) {
        handleConfermaChiudiEvento($callbackQuery);
    } elseif ($data === 'annulla_chiudi_evento') {
        handleAnnullaChiudiEvento($callbackQuery);
    } elseif (strpos($data, 'ospite_evento:') === 0) {
        handleOspiteEvento($callbackQuery);
    } elseif (strpos($data, 'annullo_ospite:') === 0) {
        handleAnnulloOspiteCallback($callbackQuery);
    }
    // Callback TTS
    elseif ($data === 'tts') {
        handleTTSCallback($callbackQuery);
    }
} elseif (isset($update['poll_answer'])) {
    // Gestione risposte ai quiz
    handlePollAnswer($update['poll_answer']);
}

?>
