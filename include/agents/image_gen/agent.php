<?php
/**
 * Agente Image Gen — genera immagini via ComfyUI (z-image turbo).
 * Autocontenuto: workflow JSON in ./workflows/, tabella image_gen_usage dichiarata in 'schema'.
 */
require_once dirname(__DIR__, 2) . '/logger.php';

// Tetto all'attesa su ComfyUI: garantisce che il ciclo di poll termini sempre.
if (!defined('IMAGE_GEN_POLL_TIMEOUT')) define('IMAGE_GEN_POLL_TIMEOUT', 300);

$imageGenHandler = function (array $ctx, array $params): ?array {
        $logFile = logPath('image_gen');
        $log = function (string $msg) use ($logFile) {
            file_put_contents($logFile, '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n", FILE_APPEND);
        };

        $log('Handler called, user=' . $ctx['fromId'] . ', prompt=' . ($params['prompt'] ?? '(empty)'));

        // Rate limit: max 12 immagini/ora per utente
        global $db;
        $maxPerHour = 6;
        try {
            $oneHourAgo = time() - 3600;
            $stmt = $db->prepare('SELECT COUNT(*) as cnt FROM image_gen_usage WHERE user_id = :uid AND timestamp > :since');
            $stmt->bindValue(':uid', $ctx['fromId'], SQLITE3_INTEGER);
            $stmt->bindValue(':since', $oneHourAgo, SQLITE3_INTEGER);
            $count = (int)$stmt->execute()->fetchArray(SQLITE3_ASSOC)['cnt'];
            $log("Rate limit check: count={$count}, max={$maxPerHour}");
            if ($count >= $maxPerHour) {
                $options = [];
                if ($ctx['chatType'] !== 'private') {
                    $options['reply_to_message_id'] = $ctx['messageId'];
                }
                $sendResult = sendTelegramMessage(
                    $ctx['chatID'],
                    "Hai già generato {$count} immagini nell'ultima ora. Il limite è {$maxPerHour}/ora, riprova tra un po'!",
                    $options
                );
                $log('Rate limit message sent: ok=' . ($sendResult['ok'] ? '1' : '0'));
                return ['handled' => true];
            }
        } catch (\Throwable $e) {
            $log('Rate limit check FAILED: ' . $e->getMessage());
            // Se il check fallisce, blocca per sicurezza
            return ['handled' => true];
        }

        $italianPrompt = trim($params['prompt'] ?? '');
        if ($italianPrompt === '') {
            $options = [];
            if ($ctx['chatType'] !== 'private') {
                $options['reply_to_message_id'] = $ctx['messageId'];
            }
            sendTelegramMessage($ctx['chatID'], "Non ho capito cosa vuoi che disegni. Dimmi cosa vuoi vedere!", $options);
            return ['handled' => true];
        }

        $formato = strtolower(trim($params['formato'] ?? 'square'));
        [$width, $height] = match ($formato) {
            'landscape' => [1280, 720],
            'portrait'  => [720, 1280],
            default     => [1024, 1024],
        };

        // Modalità HQ: parole chiave che attivano il workflow ernie (più lento ma migliore)
        $hqPattern = '/\b(hq|alta\s+qualit[aà]|qualit[aà]\s+(?:superiore|alta|massima)|high\s+quality|massima\s+qualit[aà])\b/iu';
        $useHQ = (bool)preg_match($hqPattern, $italianPrompt);
        $promptForLLM = $italianPrompt;
        if ($useHQ) {
            $stripped = trim(preg_replace($hqPattern, '', $italianPrompt));
            if ($stripped !== '') {
                $promptForLLM = $stripped;
            }
            $log('HQ mode enabled (ernie workflow)');
        }

        // Status message + upload_photo action
        makeAPIRequest('sendChatAction', [
            'chat_id' => $ctx['chatID'],
            'action' => 'upload_photo',
        ]);

        $statusOptions = [];
        if ($ctx['chatType'] !== 'private') {
            $statusOptions['reply_to_message_id'] = $ctx['messageId'];
        }
        $statusText = $useHQ
            ? "Sto generando l'immagine in alta qualità (può richiedere un paio di minuti)..."
            : "Sto generando l'immagine...";
        $statusMsg = sendTelegramMessage($ctx['chatID'], $statusText, $statusOptions);
        $statusMessageId = $statusMsg['ok'] ? $statusMsg['message_id'] : null;

        // Helper per cleanup su errore
        $cleanup = function () use ($ctx, &$statusMessageId) {
            if ($statusMessageId) {
                makeAPIRequest('deleteMessage', [
                    'chat_id' => $ctx['chatID'],
                    'message_id' => $statusMessageId,
                ]);
                $statusMessageId = null;
            }
        };

        // --- Step 1: Traduci e migliora il prompt in inglese via LLM ---
        // Recupera contesto chat recente per gestire riferimenti a richieste precedenti
        // (es. "fanne un'altra ma con coniglietti al posto dei gattini")
        $recentContext = getChatContext($ctx['chatID'], 1, 10);
        $contextSection = '';
        if (!empty($recentContext)) {
            $contextSection = "\n\nRECENT CHAT CONTEXT (use this to resolve references like 'make another one', 'change X to Y', etc.):\n" . $recentContext;
        }

        // Inietta persona solo se l'utente chiede di rappresentare il bot
        $personaSection = '';
        if (preg_match('/\b(rootbot|il bot|te stesso|di te|autoritratto|self.?portrait)\b/i', $italianPrompt)) {
            $personaSection = "\n\nThe user is asking for a depiction of \"rootbot\". Here is rootbot's personality:\n" . rootbotPersona() . "\nTranslate this personality into visual elements for the image prompt.";
        }

        $systemPrompt = <<<SYS
You are an expert image prompt engineer. Your task:
1. Read the user's Italian image request and the recent chat context
2. If the request references a previous image (e.g. "make another one", "same but with...", "change X to Y"), reconstruct the FULL description by combining the original request with the modifications
3. Translate the complete description to English
4. Enhance it into a detailed, vivid prompt suitable for an AI image generator
5. Add artistic details: lighting, style, mood, composition, colors
6. Keep it concise (max 100 words)
7. Output ONLY the final English prompt, nothing else{$personaSection}
SYS;

        $enhanceResult = callOllamaChatViaQBert(
            OLLAMA_MODEL_LIGHT,
            $promptForLLM . $contextSection,
            ollamaOptions(OLLAMA_MODEL_LIGHT_GPU),
            false,
            QBertClient::PRIORITY_NORMAL,
            $systemPrompt
        );

        $englishPrompt = trim(stripThinkingTags($enhanceResult['response'] ?? ''));
        if ($englishPrompt === '') {
            $englishPrompt = $promptForLLM; // fallback
        }

        // --- Step 2: Carica e configura il workflow ---
        $workflowFile = $useHQ ? 'ernie_image_turbo.json' : 'z_image_turbo.json';
        $workflowPath = __DIR__ . '/workflows/' . $workflowFile;
        $workflow = json_decode(file_get_contents($workflowPath), true);
        if (!$workflow) {
            $cleanup();
            error_log('[image_gen] Failed to load workflow JSON: ' . $workflowFile);
            return ['response' => "Errore interno: impossibile caricare il workflow di generazione."];
        }

        if ($useHQ) {
            // Ernie Image Turbo: prompt node 88:94, size node 88:71 (+ mirror su 88:99/88:100
            // usati dal prompt-enhancer interno), seed su 88:70, output SaveImage su node 73.
            $workflow['88:94']['inputs']['value']  = $englishPrompt;
            $workflow['88:71']['inputs']['width']  = $width;
            $workflow['88:71']['inputs']['height'] = $height;
            $workflow['88:99']['inputs']['source']  = $width;
            $workflow['88:100']['inputs']['source'] = $height;
            $workflow['88:70']['inputs']['seed']   = random_int(0, 2147483647);
            $workflow['88:95']['inputs']['sampling_mode.seed'] = random_int(0, 2147483647);
            $outputNodeKey = '73';
        } else {
            $workflow['58']['inputs']['value'] = $englishPrompt;
            $workflow['57:13']['inputs']['width'] = $width;
            $workflow['57:13']['inputs']['height'] = $height;
            $workflow['57:3']['inputs']['seed'] = random_int(0, 2147483647);
            $outputNodeKey = '9';
        }

        // --- Step 3: Submit a ComfyUI via QBert ---
        $qbert = getQBertClient();
        $submitResult = $qbert->post('comfyui', '/prompt', json: ['prompt' => $workflow], priority: QBertClient::PRIORITY_NORMAL);

        $promptId = $submitResult['json']['prompt_id'] ?? null;
        if (!$promptId) {
            $cleanup();
            error_log('[image_gen] ComfyUI /prompt failed: ' . json_encode($submitResult));
            return ['response' => "Errore nella generazione dell'immagine. Riprova piu' tardi."];
        }

        // --- Step 4: Poll /history fino a output pronto ---
        // Il timeout del QBertClient (600s) vale sulla SINGOLA richiesta, non su questo
        // ciclo: senza una deadline locale, un prompt che non completa mai terrebbe il
        // processo PHP a girare all'infinito.
        $deadline = time() + IMAGE_GEN_POLL_TIMEOUT;
        $lastActionTime = time();
        $pollInterval = 2.0;
        $outputFilename = null;

        while (time() < $deadline) {
            // Refresh upload_photo ogni 3s
            if ((time() - $lastActionTime) >= 3) {
                makeAPIRequest('sendChatAction', [
                    'chat_id' => $ctx['chatID'],
                    'action' => 'upload_photo',
                ]);
                $lastActionTime = time();
            }

            usleep((int)($pollInterval * 1000000));

            $historyResult = $qbert->get('comfyui', '/history/' . $promptId);

            if (($historyResult['status_code'] ?? 0) === 200 && !empty($historyResult['json'])) {
                $entry = $historyResult['json'][$promptId] ?? null;
                if ($entry && isset($entry['outputs'][$outputNodeKey]['images'][0]['filename'])) {
                    $outputFilename = $entry['outputs'][$outputNodeKey]['images'][0]['filename'];
                    break;
                }
            }
        }

        if (!$outputFilename) {
            $cleanup();
            return ['response' => "La generazione dell'immagine ha impiegato troppo tempo. Riprova."];
        }

        // --- Step 5: Download immagine ---
        $viewResult = $qbert->get('comfyui', '/view?filename=' . urlencode($outputFilename));

        if (($viewResult['status_code'] ?? 0) !== 200 || empty($viewResult['body_bytes'])) {
            $cleanup();
            error_log('[image_gen] Failed to download image: ' . $outputFilename);
            return ['response' => "Errore nel recupero dell'immagine generata."];
        }

        // --- Step 6: Salva in temp e invia ---
        $tmpFile = tempnam(sys_get_temp_dir(), 'imggen_') . '.png';
        file_put_contents($tmpFile, $viewResult['body_bytes']);

        $caption = mb_substr($italianPrompt, 0, 200);

        $sendPhotoParams = [
            'chat_id' => $ctx['chatID'],
            'photo' => new \CURLFile($tmpFile, 'image/png', 'generated.png'),
            'caption' => $caption,
        ];
        if ($ctx['chatType'] !== 'private') {
            $sendPhotoParams['reply_to_message_id'] = $ctx['messageId'];
        }

        $photoResult = makeAPIRequest('sendPhoto', $sendPhotoParams);

        // Retry senza reply_to se messaggio originale eliminato
        if ((!$photoResult || !$photoResult['ok']) && isset($sendPhotoParams['reply_to_message_id'])) {
            $errDesc = $photoResult['description'] ?? '';
            if (strpos($errDesc, 'message to be replied not found') !== false) {
                unset($sendPhotoParams['reply_to_message_id']);
                $sendPhotoParams['photo'] = new \CURLFile($tmpFile, 'image/png', 'generated.png');
                $photoResult = makeAPIRequest('sendPhoto', $sendPhotoParams);
            }
        }

        // Cleanup
        @unlink($tmpFile);
        $cleanup();

        if (!$photoResult || !$photoResult['ok']) {
            return ['response' => "Non sono riuscito a inviare l'immagine generata."];
        }

        // Salva nel contesto chat per riferimenti futuri
        // (es. "fanne un'altra ma con coniglietti")
        saveMessageToContext($ctx['chatID'], 'rootbot', "[immagine generata: {$italianPrompt}]");

        // Registra utilizzo per rate limit
        $stmt = $db->prepare('INSERT INTO image_gen_usage (user_id, timestamp) VALUES (:uid, :ts)');
        $stmt->bindValue(':uid', $ctx['fromId'], SQLITE3_INTEGER);
        $stmt->bindValue(':ts', time(), SQLITE3_INTEGER);
        $stmt->execute();

        return ['handled' => true];
};

return [
    'id' => 'image_gen',
    'description' => "L'utente chiede di generare, creare, disegnare o immaginare un'immagine, una foto, un disegno, un'illustrazione (es. 'genera un'immagine di...', 'disegna un gatto', 'fammi vedere un tramonto', 'crea un'illustrazione', 'immagina...')",
    'parameters' => [
        'prompt' => "Descrizione dettagliata dell'immagine da generare, in italiano, come richiesto dall'utente. Preserva eventuali indicatori di qualità come 'HQ', 'alta qualità', 'qualità superiore' se presenti nel testo originale",
        'formato' => "Formato dell'immagine SE esplicitamente richiesto: 'landscape' (orizzontale/panorama), 'portrait' (verticale/ritratto) o 'square' (quadrato). Se l'utente non specifica il formato, usa 'square'",
    ],
    'sends_own_response' => true,
    'help' => "Generazione immagini:
/genera [descrizione] - genera un'immagine dalla descrizione (es: /genera un gatto astronauta)
Puoi aggiungere 'landscape' o 'portrait' per il formato (default: quadrato)
Per qualità superiore aggiungi 'HQ' o 'alta qualità' (più lenta ma migliore)
Puoi anche chiedere: 'rootbot disegna un tramonto sul mare'
Limite: 6 immagini/ora per utente",
    'schema' => function (SQLite3 $db): void {
        $db->exec("CREATE TABLE IF NOT EXISTS image_gen_usage (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            timestamp INTEGER NOT NULL
        )");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_image_gen_user ON image_gen_usage(user_id, timestamp)");
        $oneHourAgo = time() - 3600;
        $db->exec("DELETE FROM image_gen_usage WHERE timestamp < {$oneHourAgo}");
    },
    'handler' => $imageGenHandler,
    'commands' => [
        [
            // /genera [descrizione] [landscape|portrait|orizzontale|verticale]
            'pattern' => '/^\/genera(?:@rootbotbot)?(?:\s+(.*))?$/ui',
            'handler' => function (array $ctx, array $matches) use ($imageGenHandler): ?array {
                $prompt = trim($matches[1] ?? '');
                if ($prompt === '') {
                    return ['response' => "Uso: /genera [descrizione immagine]\nEs: /genera un gatto astronauta nello spazio"];
                }
                $formato = 'square';
                if (preg_match('/\b(landscape|portrait|orizzontale|verticale)\b/i', $prompt, $fm)) {
                    $formato = match (strtolower($fm[1])) {
                        'landscape', 'orizzontale' => 'landscape',
                        'portrait',  'verticale'   => 'portrait',
                        default                    => 'square',
                    };
                    $prompt = trim(preg_replace('/\b' . preg_quote($fm[0], '/') . '\b/i', '', $prompt));
                }
                return $imageGenHandler($ctx, ['prompt' => $prompt, 'formato' => $formato]);
            },
        ],
    ],
];
