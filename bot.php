<?php
include "config.php";
include "include/database.php";
include "include/api.php";
include "include/image.php";
include "include/help.php";
include "include/message.php";
include "include/ai.php";
include "include/moderation.php";
include "include/orders.php";

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
    //saveMessageToContext($chatID, $firstName, $text);
    
    $groupId = $message['chat']['id'];
    $messageText = $message['text'] ?? '';
    $userName = $message['from']['first_name'] ?? 'Utente';
    $messageText = str_replace('@bot', '', $messageText);
    $messageText = str_replace('@rootbot', '', $messageText);
    $messageText = str_replace('@root', '', $messageText);
    saveMessageToContext($groupId, $userName, $messageText);
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
    } elseif (strpos($data, 'show_pappatoia_images:') === 0) {
        handle_show_pappatoia_images($callbackQuery);
    } elseif (strpos($data, 'select_pappatoia_for_menu:') === 0) {
        handle_select_pappatoia_for_menu($callbackQuery);
    } elseif (strpos($data, 'menu_action:') === 0) {
        handle_menu_action($callbackQuery);
    }
}

http_response_code(200);


?>
