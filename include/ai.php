<?php
/////////////////////////////////////////////////////////////////
////////////////////// GESTIONE CHAT CON AI ////////////////////
////////////////////////////////////////////////////////////////

function saveMessageToContext($groupId, $userName, $messageText) {
    global $db;

    // Inserisci il nuovo messaggio
    $stmt = $db->prepare("INSERT INTO contesto_chat (group_id, user_name, message_text, timestamp) VALUES (:group_id, :user_name, :message_text, :timestamp)");
    $stmt->bindValue(':group_id', $groupId, SQLITE3_INTEGER);
    $stmt->bindValue(':user_name', $userName, SQLITE3_TEXT);
    $stmt->bindValue(':message_text', $messageText, SQLITE3_TEXT);
    $stmt->bindValue(':timestamp', time(), SQLITE3_INTEGER);
    $stmt->execute();

    // Conta il numero di messaggi per questo gruppo
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM contesto_chat WHERE group_id = :group_id");
    $stmt->bindValue(':group_id', $groupId, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $row = $result->fetchArray(SQLITE3_ASSOC);
    $count = $row['count'];

    // Se abbiamo più di 200 messaggi, elimina i più vecchi
    if ($count > 200) {
        $toDelete = $count - 200;
        $stmt = $db->prepare("DELETE FROM contesto_chat WHERE group_id = :group_id AND id IN (SELECT id FROM contesto_chat WHERE group_id = :group_id ORDER BY timestamp ASC LIMIT :to_delete)");
        $stmt->bindValue(':group_id', $groupId, SQLITE3_INTEGER);
        $stmt->bindValue(':to_delete', $toDelete, SQLITE3_INTEGER);
        $stmt->execute();
    }
}

function getChatContext($groupId, $hours = 24, $limit = 200) {
    global $db;
    
    // Calcola il timestamp di X ore fa
    $hours_ago = time() - ($hours * 3600);
    
    // Query modificata per usare timestamp UNIX
    $stmt = $db->prepare("
        SELECT user_name, message_text, timestamp 
        FROM contesto_chat 
        WHERE group_id = :group_id 
        AND timestamp >= :hours_ago
        ORDER BY timestamp DESC 
        LIMIT " . intval($limit)
    );
    
    $stmt->bindValue(':group_id', $groupId, SQLITE3_INTEGER);
    $stmt->bindValue(':hours_ago', $hours_ago, SQLITE3_INTEGER);
    
    $result = $stmt->execute();
    if (!$result) {
        error_log("SQLite Error in getChatContext: " . $db->lastErrorMsg());
        return "";
    }
    
    $context = [];
    $count = 0;
    
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $context[] = $row['user_name'] . ": " . $row['message_text'];
        $count++;
    }
    
    // Log per debug
    error_log("getChatContext: Found $count messages for group $groupId");
    if (empty($context)) {
        error_log("getChatContext: No messages found. Hours ago: $hours_ago, Current time: " . time());
    }
    
    $msg_array = array_reverse($context);
    $result = join("\n", $msg_array);
    
    // Log del risultato finale
    error_log("getChatContext final length: " . strlen($result));
    
    return $result;
}

// Funzione di supporto per debug
function dumpChatContext($groupId) {
    global $db;
    
    $stmt = $db->prepare("
        SELECT user_name, message_text, timestamp, 
               datetime(timestamp, 'unixepoch') as formatted_time
        FROM contesto_chat 
        WHERE group_id = :group_id 
        ORDER BY timestamp DESC 
        LIMIT 10
    ");
    
    $stmt->bindValue(':group_id', $groupId, SQLITE3_INTEGER);
    $result = $stmt->execute();
    
    $debug_info = "Latest 10 messages in context:\n";
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $debug_info .= sprintf(
            "[%s] %s: %s\n",
            $row['formatted_time'],
            $row['user_name'],
            substr($row['message_text'], 0, 50)
        );
    }
    
    error_log($debug_info);
}

function _ai($chatID, $chatType, $message) {
    $ollamaUrl = OLLAMA_URL;
    $model = OLLAMA_MODEL;

    $context = getChatContext($chatID, 1, 20);
    
    
    $instructions = "Tu sei il bot del circolo /root, ti chiami rootbot, sei entusiasta del circolo chiamato anche semplicemente root oppure root club. Sei sarcastico, ricordi un po' il robot Bender di Futurama ma non sei mai sgarbato nei confronti degli interlocuttori, ti piace fare battute, bere birra, fare tardi al circolo root. Tieni conto che sei in un gruppo di nerd e appassionati di tecnologia, meccanica, elettronica, robotica, fantacenza, curiosità scientifiche, cerca di dare risposte non troppo lunghe.\n";
    $oggi = ucfirst(strftime('%A %d %B %Y'));
    $orario = $orario = date('H:i');
    $instructions = $instructions. "Oggi è ". $oggi . " orario ". $orario ." Il circolo /root è in Via Santa Croce 6669, a San Pietro in Guardiano, tra Forlì, Ravenna e Cesena. Il sito web è www.rootclub.it. Il circolo è aperto Martedì e Venerdì sera dalle 20 fin quando ce n'è. Al root si ritrovano anche i ragazzi di FoLug, Linux User Group di Forlì. root è sede di Precious Plastic Romagna.\n";
    $message = str_replace('@bot', '', $message);
    $message = str_replace('@rootbot', '', $message);
    $message = str_replace('@root', '', $message);
    $prompt = $instructions."Questo è il contesto in cui ti viene posta la domanda:\n".$context."Questa è la domanda, poni attenzione principalmente a questa:\n".trim($message);

	file_put_contents('ai.log', print_r($prompt, true) . "\n\n", FILE_APPEND);

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

	saveMessageToContext($chatID, "bot", $response);
    return $response;
}
?>
