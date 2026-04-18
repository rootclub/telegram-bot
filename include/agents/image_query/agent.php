<?php
/**
 * Agente Image Query — domande su immagini condivise in precedenza.
 *
 * Autocontenuto: possiede la tabella image_log (dichiarata in 'schema') e le
 * funzioni globali saveImageLog/getRecentImages, usate anche da bot.php per il
 * flusso vision. Rimuovendo la directory dell'agente sparisce anche il log immagini.
 */

/**
 * Salva un'immagine nel log per ri-analisi futura.
 * Definita a require-time, disponibile globalmente (usata da bot.php).
 */
function saveImageLog($groupId, $userId, $userName, $fileId, $description) {
    global $db;
    $stmt = $db->prepare("INSERT INTO image_log (group_id, user_id, user_name, file_id, description, timestamp)
                          VALUES (:group_id, :user_id, :user_name, :file_id, :description, :timestamp)");
    $stmt->bindValue(':group_id', $groupId, SQLITE3_INTEGER);
    $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
    $stmt->bindValue(':user_name', $userName, SQLITE3_TEXT);
    $stmt->bindValue(':file_id', $fileId, SQLITE3_TEXT);
    $stmt->bindValue(':description', $description, SQLITE3_TEXT);
    $stmt->bindValue(':timestamp', time(), SQLITE3_INTEGER);
    $stmt->execute();
}

/**
 * Recupera le ultime N immagini di un gruppo.
 * @return array Lista di ['file_id', 'description', 'user_name', 'timestamp']
 */
function getRecentImages($groupId, $limit = 10) {
    global $db;
    $stmt = $db->prepare("SELECT file_id, description, user_name, timestamp
                          FROM image_log
                          WHERE group_id = :group_id
                          ORDER BY timestamp DESC
                          LIMIT " . intval($limit));
    $stmt->bindValue(':group_id', $groupId, SQLITE3_INTEGER);
    $result = $stmt->execute();

    $images = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $images[] = $row;
    }
    return $images;
}

return [
    'id' => 'image_query',
    'description' => "L'utente fa una domanda o chiede informazioni su un'immagine: può essere una reply diretta a una foto (es. 'che marca è?', 'quanti ce ne sono?', 'di che colore?') oppure un riferimento a una foto già condivisa (es. 'cosa c'era nell'ultima foto?', 'nella foto dei gattini...', 'dimmi di piu sull'immagine di prima', 'che auto era quella nella foto?')",
    'parameters' => [
        'riferimento' => "Descrizione dell'immagine a cui l'utente si riferisce (es. 'ultima foto', 'foto dei gattini', 'immagine del circuito'). Se l'utente dice 'ultima' o non specifica, scrivi 'ultima'.",
        'domanda' => "La domanda specifica dell'utente sull'immagine",
    ],
    'schema' => function (SQLite3 $db): void {
        $db->exec("CREATE TABLE IF NOT EXISTS image_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            group_id INTEGER,
            user_id INTEGER,
            user_name TEXT,
            file_id TEXT NOT NULL,
            description TEXT,
            timestamp INTEGER
        )");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_image_log_group ON image_log(group_id, timestamp)");
        $monthAgo = time() - (30 * 24 * 3600);
        $db->exec("DELETE FROM image_log WHERE timestamp < {$monthAgo}");
    },
    'handler' => function (array $ctx, array $params): ?array {
        $riferimento = trim($params['riferimento'] ?? 'ultima');
        $domanda = trim($params['domanda'] ?? '');

        // Reply diretta a una foto/documento immagine: usa quella senza passare
        // dal log (è il riferimento più forte e immediato).
        $selectedImage = null;
        $raw = $ctx['raw'] ?? null;
        if ($raw && isset($raw['reply_to_message'])) {
            $replyTo = $raw['reply_to_message'];
            $replyFileId = null;
            if (isset($replyTo['photo'])) {
                $rp = $replyTo['photo'];
                $replyFileId = $rp[count($rp) - 1]['file_id'];
            } elseif (isset($replyTo['document']) && isImageDocument($replyTo['document'])) {
                $replyFileId = $replyTo['document']['file_id'];
            }
            if ($replyFileId !== null) {
                $selectedImage = ['file_id' => $replyFileId];
            }
        }

        if ($selectedImage === null) {
            // Recupera immagini recenti
            $images = getRecentImages($ctx['chatID'], 10);

            if (empty($images)) {
                return ['response' => "Non ho immagini recenti in memoria per questa chat."];
            }

            // Se riferimento generico ("ultima"), prendi la più recente senza chiamare LLM
            $isUltima = preg_match('/\b(ultima|ultime|recente|prima|precedente)\b/i', $riferimento);

            if ($isUltima) {
                $selectedImage = $images[0];
            } else {
                // Usa LLM light per matchare il riferimento con le descrizioni
                $imageList = '';
                foreach ($images as $i => $img) {
                    $num = $i + 1;
                    $desc = mb_substr($img['description'], 0, 150);
                    $time = date('H:i d/m', $img['timestamp']);
                    $imageList .= "{$num}. [{$time} da {$img['user_name']}]: {$desc}\n";
                }

                $matchPrompt = <<<PROMPT
Ho queste immagini recenti:
{$imageList}
L'utente si riferisce a: "{$riferimento}"

Rispondi SOLO con il numero dell'immagine piu' pertinente (es. "3"), o "0" se nessuna corrisponde.
PROMPT;

                $matchResult = callOllamaChatViaQBert(
                    OLLAMA_MODEL_LIGHT,
                    $matchPrompt,
                    ollamaOptions(OLLAMA_MODEL_LIGHT_GPU),
                    false,
                    QBertClient::PRIORITY_NORMAL
                );

                // llmResponseOrError evita l'accesso su null quando QBert fallisce;
                // qui non vogliamo inviare un errore all'utente (proseguiamo con la
                // fallback: "non ho trovato immagine corrispondente"), quindi non passiamo $chatId.
                $matchText = llmResponseOrError($matchResult);
                $matchResponse = $matchText !== null ? stripThinkingTags($matchText) : '';

                // Estrai il numero dalla risposta
                if (preg_match('/(\d+)/', $matchResponse, $m)) {
                    $idx = intval($m[1]) - 1;
                    if ($idx >= 0 && $idx < count($images)) {
                        $selectedImage = $images[$idx];
                    }
                }
            }
        }

        if (!$selectedImage) {
            return ['response' => "Non ho trovato un'immagine corrispondente tra quelle recenti."];
        }

        // Mostra typing mentre analizza
        makeAPIRequest('sendChatAction', [
            'chat_id' => $ctx['chatID'],
            'action' => 'typing',
        ]);

        // Rilancia analyzeImage con la domanda specifica
        $prompt = !empty($domanda) ? $domanda : "Descrivi in dettaglio questa immagine";
        $answer = analyzeImage($selectedImage['file_id'], $prompt, $ctx['chatID']);

        if (!$answer) {
            return ['response' => "Non sono riuscito ad analizzare l'immagine."];
        }

        return ['response' => $answer];
    },
];
