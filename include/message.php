<?php
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
    $chatType = $message['chat']['type'];
    $fromId = $message['from']['id'];
    $firstName = $message['from']['first_name'] ?? 'Utente';
    $response = null;


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
        
    } elseif ($text == '/annullo' || $text == '/annullo@rootbotbot') {
        $userName = $message['from']['first_name'] . ' ' . ($message['from']['last_name'] ?? '');
        $response = _annullo($fromId, $userName);
        
    } elseif (isset($message['photo'])) {
        $response = handle_image($message);
        
    } elseif ($text == '/elimina_asporto') {
        $response = elimina_pappatoia($chatID, $fromId);
        
    } elseif (preg_match('/@root\b/', $text) || preg_match('/@bot\b/', $text) || preg_match('/@rootbot\b/', $text)) {
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
?>
