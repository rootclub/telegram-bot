<?php
/**
 * Agente 3D Gen — trasforma un'immagine in un modello 3D .glb via ComfyUI (Hunyuan3D v2.1).
 * Autocontenuto: workflow JSON in ./workflows/, tabella threed_gen_usage dichiarata in 'schema'.
 *
 * Risoluzione immagine sorgente:
 *   1) Reply a una foto/document immagine → usa quella (richiede ctx['raw'])
 *   2) Fallback: match su image_log per 'riferimento' (stessa logica di image_query)
 */
require_once dirname(__DIR__, 2) . '/logger.php';

// Tetto all'attesa del branch ComfyUI. Hunyuan3D è pesante e la coda può essere
// lunga, quindi è generoso; serve solo a garantire che il ciclo termini sempre.
if (!defined('THREED_GEN_POLL_TIMEOUT')) define('THREED_GEN_POLL_TIMEOUT', 600);

$gen3dHandler = function (array $ctx, array $params): ?array {
    $logFile = logPath('3d_gen');
    $log = function (string $msg) use ($logFile) {
        file_put_contents($logFile, '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n", FILE_APPEND);
    };

    $log('Handler called, user=' . $ctx['fromId'] . ', params=' . json_encode($params, JSON_UNESCAPED_UNICODE));

    // --- Modalità HQ: attiva TRELLIS.2 al posto di ComfyUI/Hunyuan3D ---
    $raw = $ctx['raw'] ?? null;
    $rawText = $raw['text'] ?? $raw['caption'] ?? '';
    $hqPattern = '/\b(hq|alta\s+qualit[aà]|qualit[aà]\s+(?:superiore|alta|massima)|high\s+quality|massima\s+qualit[aà])\b/iu';
    $useHQ = $rawText !== '' && (bool)preg_match($hqPattern, $rawText);
    if ($useHQ) {
        $log('HQ mode enabled (TRELLIS.2)');
    }

    // --- Step 0: risolvi file_id dell'immagine sorgente ---
    $fileId = null;

    // (1) Reply a foto/documento immagine
    if ($raw && isset($raw['reply_to_message'])) {
        $replyTo = $raw['reply_to_message'];
        if (isset($replyTo['photo'])) {
            $rp = $replyTo['photo'];
            $fileId = $rp[count($rp) - 1]['file_id'];
            $log('Source: reply-to-photo, file_id=' . $fileId);
        } elseif (isset($replyTo['document']) && isImageDocument($replyTo['document'])) {
            $fileId = $replyTo['document']['file_id'];
            $log('Source: reply-to-document, file_id=' . $fileId);
        }
    }

    // (2) Fallback: match su image_log per riferimento
    if ($fileId === null) {
        if (!function_exists('getRecentImages')) {
            return ['response' => "Non trovo il log delle immagini. Riprova rispondendo direttamente a una foto."];
        }
        $images = getRecentImages($ctx['chatID'], 10);
        if (empty($images)) {
            return ['response' => "Non ho immagini recenti in memoria. Inviane una e poi chiedimi di trasformarla."];
        }

        $riferimento = trim($params['riferimento'] ?? 'ultima');
        $isUltima = preg_match('/\b(ultima|ultime|recente|prima|precedente)\b/i', $riferimento);

        if ($isUltima || $riferimento === '') {
            $fileId = $images[0]['file_id'];
            $log('Source: image_log[0] (ultima), file_id=' . $fileId);
        } else {
            // LLM light match su descrizioni
            $imageList = '';
            foreach ($images as $i => $img) {
                $num = $i + 1;
                $desc = mb_substr($img['description'] ?? '', 0, 150);
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
            $matchText = llmResponseOrError($matchResult);
            $matchResponse = $matchText !== null ? stripThinkingTags($matchText) : '';

            if (preg_match('/(\d+)/', $matchResponse, $m)) {
                $idx = intval($m[1]) - 1;
                if ($idx >= 0 && $idx < count($images)) {
                    $fileId = $images[$idx]['file_id'];
                    $log("Source: image_log[{$idx}] (match riferimento='{$riferimento}'), file_id=" . $fileId);
                }
            }

            if ($fileId === null) {
                return ['response' => "Non ho trovato un'immagine corrispondente a \"{$riferimento}\" tra quelle recenti."];
            }
        }
    }

    // --- Rate limit: max 6 modelli/ora per utente ---
    global $db;
    $maxPerHour = 6;
    try {
        $oneHourAgo = time() - 3600;
        $stmt = $db->prepare('SELECT COUNT(*) as cnt FROM threed_gen_usage WHERE user_id = :uid AND timestamp > :since');
        $stmt->bindValue(':uid', $ctx['fromId'], SQLITE3_INTEGER);
        $stmt->bindValue(':since', $oneHourAgo, SQLITE3_INTEGER);
        $count = (int)$stmt->execute()->fetchArray(SQLITE3_ASSOC)['cnt'];
        $log("Rate limit check: count={$count}, max={$maxPerHour}");
        if ($count >= $maxPerHour) {
            $options = [];
            if ($ctx['chatType'] !== 'private') {
                $options['reply_to_message_id'] = $ctx['messageId'];
            }
            sendTelegramMessage(
                $ctx['chatID'],
                "Hai già generato {$count} modelli 3D nell'ultima ora. Il limite è {$maxPerHour}/ora, riprova tra un po'!",
                $options
            );
            return ['handled' => true];
        }
    } catch (\Throwable $e) {
        $log('Rate limit check FAILED: ' . $e->getMessage());
        return ['handled' => true];
    }

    // --- Status message + upload_document action ---
    makeAPIRequest('sendChatAction', [
        'chat_id' => $ctx['chatID'],
        'action' => 'upload_document',
    ]);

    $statusOptions = [];
    if ($ctx['chatType'] !== 'private') {
        $statusOptions['reply_to_message_id'] = $ctx['messageId'];
    }
    $statusText = $useHQ
        ? "Sto generando il modello 3D in alta qualità... ci vuole qualche minuto."
        : "Sto generando il modello 3D... ci vuole un minuto.";
    $statusMsg = sendTelegramMessage($ctx['chatID'], $statusText, $statusOptions);
    $statusMessageId = $statusMsg['ok'] ? $statusMsg['message_id'] : null;

    $cleanup = function () use ($ctx, &$statusMessageId) {
        if ($statusMessageId) {
            makeAPIRequest('deleteMessage', [
                'chat_id' => $ctx['chatID'],
                'message_id' => $statusMessageId,
            ]);
            $statusMessageId = null;
        }
    };

    // --- Step 1: scarica l'immagine da Telegram ---
    $fileInfo = makeAPIRequest('getFile', ['file_id' => $fileId]);
    if (empty($fileInfo['ok'])) {
        $cleanup();
        $log('getFile failed: ' . json_encode($fileInfo));
        return ['response' => "Errore: non ho potuto scaricare l'immagine da Telegram."];
    }
    $fileUrl = 'https://api.telegram.org/file/bot' . BOT_TOKEN . '/' . $fileInfo['result']['file_path'];
    $imageBytes = @file_get_contents($fileUrl);
    if ($imageBytes === false || $imageBytes === '') {
        $cleanup();
        $log('Image download failed from ' . $fileUrl);
        return ['response' => "Errore nel download dell'immagine."];
    }

    $ext = strtolower(pathinfo($fileInfo['result']['file_path'], PATHINFO_EXTENSION) ?: 'jpg');
    $tmpImg = tempnam(sys_get_temp_dir(), 'gen3d_') . '.' . $ext;
    file_put_contents($tmpImg, $imageBytes);

    $qbert = getQBertClient();
    $mime = 'image/' . ($ext === 'jpg' ? 'jpeg' : $ext);
    $glbBytes = null;

    // --- Branch HQ: TRELLIS.2 via QBert (sync, ~60s, ritorna direttamente il GLB) ---
    if ($useHQ) {
        $trellisResult = $qbert->post(
            'trellis',
            '/generate',
            null,
            QBertClient::PRIORITY_NORMAL,
            '',
            [
                'image' => new \CURLFile($tmpImg, $mime, 'input.' . $ext),
                'resolution' => '1024',
                'seed' => (string)random_int(0, 2147483647),
                'decimation_target' => '500000',
                'texture_size' => '2048',
            ]
        );
        @unlink($tmpImg);

        if (($trellisResult['status_code'] ?? 0) !== 200 || empty($trellisResult['body_bytes'])) {
            $cleanup();
            $log('TRELLIS.2 generate failed: ' . json_encode([
                'status' => $trellisResult['status_code'] ?? null,
                'body' => substr($trellisResult['body'] ?? '', 0, 500),
            ]));
            return ['response' => "Errore nella generazione 3D in alta qualità. Riprova più tardi."];
        }
        $glbBytes = $trellisResult['body_bytes'];
        $log('TRELLIS.2 GLB ricevuto, bytes=' . strlen($glbBytes));
    } else {
    // --- Branch standard: upload immagine a ComfyUI (/upload/image, multipart) ---
    $uploadResult = $qbert->post(
        'comfyui',
        '/upload/image',
        null,
        QBertClient::PRIORITY_NORMAL,
        '',
        [
            'image' => new \CURLFile($tmpImg, $mime, 'input.' . $ext),
            'overwrite' => 'true',
        ]
    );
    @unlink($tmpImg);

    $uploadedName = $uploadResult['json']['name'] ?? null;
    if (!$uploadedName) {
        $cleanup();
        $log('ComfyUI upload failed: ' . json_encode([
            'status' => $uploadResult['status_code'] ?? null,
            'body' => substr($uploadResult['body'] ?? '', 0, 500),
        ]));
        return ['response' => "Errore nell'upload dell'immagine a ComfyUI."];
    }
    $log("Uploaded to ComfyUI as: {$uploadedName}");

    // --- Step 3: carica e configura workflow ---
    $workflowPath = __DIR__ . '/workflows/3d_hunyuan3d-v2.1.json';
    $workflow = json_decode(file_get_contents($workflowPath), true);
    if (!$workflow) {
        $cleanup();
        $log('Failed to load workflow JSON');
        return ['response' => "Errore interno: impossibile caricare il workflow 3D."];
    }

    $workflow['2']['inputs']['image'] = $uploadedName;
    $workflow['7']['inputs']['seed'] = random_int(0, 2147483647);

    // --- Step 4: submit a ComfyUI ---
    $submitResult = $qbert->post('comfyui', '/prompt', json: ['prompt' => $workflow], priority: QBertClient::PRIORITY_NORMAL);
    $promptId = $submitResult['json']['prompt_id'] ?? null;
    if (!$promptId) {
        $cleanup();
        $log('ComfyUI /prompt failed: ' . json_encode($submitResult));
        return ['response' => "Errore nell'avvio della generazione 3D. Riprova piu' tardi."];
    }
    $log("Submitted to ComfyUI, prompt_id={$promptId}");

    // --- Step 5: poll /history ---
    // Il timeout del QBertClient (600s) vale sulla SINGOLA richiesta, non su questo
    // ciclo: senza una deadline locale, un prompt che non completa mai (errore ComfyUI,
    // job perso) terrebbe il processo PHP a girare all'infinito. La soglia è larga
    // perché in coda ComfyUI l'attesa legittima può essere lunga.
    $deadline = time() + THREED_GEN_POLL_TIMEOUT;
    $lastActionTime = time();
    $pollInterval = 3.0;
    $outputFilename = null;
    $outputSubfolder = '';

    while (time() < $deadline) {
        if ((time() - $lastActionTime) >= 3) {
            makeAPIRequest('sendChatAction', [
                'chat_id' => $ctx['chatID'],
                'action' => 'upload_document',
            ]);
            $lastActionTime = time();
        }

        usleep((int)($pollInterval * 1000000));

        $historyResult = $qbert->get('comfyui', '/history/' . $promptId);
        if (($historyResult['status_code'] ?? 0) === 200 && !empty($historyResult['json'])) {
            $entry = $historyResult['json'][$promptId] ?? null;
            if ($entry && isset($entry['outputs']['10']) && is_array($entry['outputs']['10'])) {
                // SaveGLB può esporre il file sotto chiavi diverse (es. "3d", "result", "gltf"):
                // scansiona tutti i gruppi e prendi il primo item con filename *.glb
                foreach ($entry['outputs']['10'] as $items) {
                    if (!is_array($items)) continue;
                    foreach ($items as $item) {
                        if (is_array($item) && isset($item['filename'])
                            && str_ends_with(strtolower($item['filename']), '.glb')) {
                            $outputFilename = $item['filename'];
                            $outputSubfolder = $item['subfolder'] ?? '';
                            break 3;
                        }
                    }
                }
            }
        }
    }

    if (!$outputFilename) {
        $cleanup();
        $log('Timeout waiting for ComfyUI 3D output');
        return ['response' => "La generazione del modello 3D ha impiegato troppo tempo. Riprova."];
    }
    $log("Output ready: filename={$outputFilename}, subfolder={$outputSubfolder}");

    // --- Step 6: download GLB ---
    $viewQuery = '/view?filename=' . urlencode($outputFilename)
               . '&type=output&subfolder=' . urlencode($outputSubfolder);
    $viewResult = $qbert->get('comfyui', $viewQuery);

    if (($viewResult['status_code'] ?? 0) !== 200 || empty($viewResult['body_bytes'])) {
        $cleanup();
        $log('Failed to download GLB: ' . json_encode([
            'status' => $viewResult['status_code'] ?? null,
            'filename' => $outputFilename,
        ]));
        return ['response' => "Errore nel recupero del modello 3D."];
    }
    $glbBytes = $viewResult['body_bytes'];
    } // fine branch ComfyUI

    // --- Step 7: salva in /models/{uuid}.glb (servito da viewer.php) e invia ---
    $modelsDir = dirname(__DIR__, 3) . '/models';
    if (!is_dir($modelsDir)) {
        @mkdir($modelsDir, 0755, true);
    }
    // Cleanup lazy: modelli più vecchi di 30 giorni
    $cutoff = time() - (30 * 24 * 3600);
    foreach (glob($modelsDir . '/*.glb') ?: [] as $oldFile) {
        if (@filemtime($oldFile) < $cutoff) {
            @unlink($oldFile);
        }
    }

    $modelId = bin2hex(random_bytes(16));
    $glbPath = $modelsDir . '/' . $modelId . '.glb';
    if (file_put_contents($glbPath, $glbBytes) === false) {
        $cleanup();
        $log('Failed to save GLB to ' . $glbPath);
        return ['response' => "Errore nel salvataggio del modello 3D."];
    }

    $viewerUrl = dirname(WEBHOOK_URL) . '/viewer.php?id=' . $modelId;
    $log("Viewer URL: {$viewerUrl}");

    $sendParams = [
        'chat_id' => $ctx['chatID'],
        'document' => new \CURLFile($glbPath, 'model/gltf-binary', 'model.glb'),
        'caption' => 'Modello 3D generato',
        'reply_markup' => json_encode([
            'inline_keyboard' => [[
                ['text' => "\xF0\x9F\x94\x84 Anteprima 3D", 'url' => $viewerUrl],
            ]],
        ]),
    ];
    if ($ctx['chatType'] !== 'private') {
        $sendParams['reply_to_message_id'] = $ctx['messageId'];
    }

    $docResult = makeAPIRequest('sendDocument', $sendParams);

    // Retry senza reply_to se messaggio originale eliminato
    if ((!$docResult || !$docResult['ok']) && isset($sendParams['reply_to_message_id'])) {
        $errDesc = $docResult['description'] ?? '';
        if (strpos($errDesc, 'message to be replied not found') !== false) {
            unset($sendParams['reply_to_message_id']);
            $sendParams['document'] = new \CURLFile($glbPath, 'model/gltf-binary', 'model.glb');
            $docResult = makeAPIRequest('sendDocument', $sendParams);
        }
    }

    $cleanup();

    if (!$docResult || !$docResult['ok']) {
        $log('sendDocument failed: ' . json_encode($docResult));
        return ['response' => "Non sono riuscito a inviare il modello 3D."];
    }

    saveMessageToContext($ctx['chatID'], 'rootbot', "[modello 3D generato]");

    $stmt = $db->prepare('INSERT INTO threed_gen_usage (user_id, timestamp) VALUES (:uid, :ts)');
    $stmt->bindValue(':uid', $ctx['fromId'], SQLITE3_INTEGER);
    $stmt->bindValue(':ts', time(), SQLITE3_INTEGER);
    $stmt->execute();

    $log('3D model sent successfully');
    return ['handled' => true];
};

return [
    'id' => '3d_gen',
    'description' => "L'utente chiede di trasformare, convertire o rendere un'immagine/foto in un modello 3D, mesh, GLB (es. 'trasforma questa foto in 3D', 'converti in 3D', 'fammela in 3D', 'voglio un modello 3D', 'rendilo tridimensionale', 'crea un glb dall'immagine del gattino'). Richiede un'immagine di riferimento: può essere una reply a una foto, oppure una già condivisa in precedenza.",
    'parameters' => [
        'riferimento' => "Descrizione dell'immagine a cui l'utente si riferisce (es. 'ultima foto', 'immagine con il gattino verde', 'foto del tramonto'). Se l'utente dice 'questa' / 'questa foto' / 'l'ultima' o non specifica, scrivi 'ultima'.",
    ],
    'sends_own_response' => true,
    'help' => "Generazione modelli 3D:
Rispondi a una foto scrivendo 'rootbot trasforma in 3D' (o 'converti in 3D' in chat privata)
Oppure: 'rootbot trasforma in 3D l'immagine con il gattino verde' — cerca tra le foto recenti
Per qualità superiore aggiungi 'HQ' o 'alta qualità' (TRELLIS.2, mesh e texture migliori)
Output: file .glb (apribile con qualsiasi viewer 3D)
Limite: 6 modelli/ora per utente",
    'schema' => function (SQLite3 $db): void {
        $db->exec("CREATE TABLE IF NOT EXISTS threed_gen_usage (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            timestamp INTEGER NOT NULL
        )");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_threed_gen_user ON threed_gen_usage(user_id, timestamp)");
        $oneHourAgo = time() - 3600;
        $db->exec("DELETE FROM threed_gen_usage WHERE timestamp < {$oneHourAgo}");
    },
    'handler' => $gen3dHandler,
];
