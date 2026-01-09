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
include "include/message.php";

$db = new SQLite3(DB_FILE);

initDatabase();

$update = json_decode(file_get_contents('php://input'), true);

file_put_contents('debug.log', print_r($update, true) . "\n\n", FILE_APPEND);










////////////////////////////////////////////////////////////////////////
//////////////////////   Aggiornamento della chat   ///////////////////
////////////////////////////////////////////////////////////////////////


if (isset($update['message'])) {
	$message = $update['message'];
    processMessage($message);

    $groupId = $message['chat']['id'];
    $userName = $message['from']['first_name'] ?? 'Utente';

    // Gestisci testo
    $messageText = $message['text'] ?? '';
    $messageText = str_replace('@bot', '', $messageText);
    $messageText = str_replace('@rootbot', '', $messageText);
    $messageText = str_replace('@root', '', $messageText);

    // Gestisci immagini: analizza e aggiungi descrizione al contesto
    if (isset($message['photo'])) {
        $photos = $message['photo'];
        $fileId = $photos[count($photos) - 1]['file_id']; // Prendi la versione più grande
        $caption = $message['caption'] ?? '';

        // Analizza l'immagine con AI vision
        $imageDescription = analyzeImage($fileId);

        if ($imageDescription) {
            $messageText = "[immagine: $imageDescription]";
            if ($caption) {
                $messageText .= " $caption";
            }
        } elseif ($caption) {
            $messageText = "[immagine] $caption";
        } else {
            $messageText = "[immagine]";
        }
    }

    // Salva nel contesto solo se c'è contenuto
    if (!empty(trim($messageText))) {
        saveMessageToContext($groupId, $userName, $messageText);
    }
} elseif (isset($update['edited_message'])) {
    // Ignora i messaggi editati per evitare risposte duplicate
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
}

http_response_code(200);


?>
