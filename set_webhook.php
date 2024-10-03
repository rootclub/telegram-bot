<?php
define('BOT_TOKEN', 'MY_TELEGRAM_TOKEN');

define('WEBHOOK_URL', 'https://www.yourdomain.it/telegram/bot.php');

function setWebhook($url) {
    $api_url = 'https://api.telegram.org/bot' . BOT_TOKEN . '/setWebhook';
    
    $ch = curl_init($api_url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, ['url' => $url]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    
    $response = curl_exec($ch);
    
    if ($response === false) {
        return [
            'ok' => false,
            'description' => 'Curl error: ' . curl_error($ch)
        ];
    }
    
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    $result = json_decode($response, true);
    
    if ($result === null) {
        return [
            'ok' => false,
            'description' => 'Invalid JSON response. HTTP code: ' . $http_code . '. Response: ' . $response
        ];
    }
    
    return $result;
}

$result = setWebhook(WEBHOOK_URL);

if ($result['ok']) {
    echo "Webhook impostato con successo a " . WEBHOOK_URL;
} else {
    echo "Errore nell'impostazione del webhook: " . $result['description'] . "\n";
    echo "API URL usato: https://api.telegram.org/bot" . substr(BOT_TOKEN, 0, 5) . "...{RESTO_DEL_TOKEN}/setWebhook\n";
    echo "Webhook URL: " . WEBHOOK_URL . "\n";
}
?>
