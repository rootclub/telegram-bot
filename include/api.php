<?php
function makeAPIRequest($method, $parameters) {
    $url = "https://api.telegram.org/bot" . BOT_TOKEN . "/" . $method;
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    
    // Gestione speciale per l'invio di file (photo, voice, document, ecc.)
    $hasCURLFile = false;
    foreach ($parameters as $value) {
        if ($value instanceof CURLFile) {
            $hasCURLFile = true;
            break;
        }
    }
    if ($hasCURLFile) {
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
        return $result;  // Ritorna l'array completo per gestire errori specifici
    }

    return $result;
}
?>
