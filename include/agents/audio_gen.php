<?php
/**
 * Agente Audio Gen — genera brani musicali via ComfyUI (ACE-Step 1.5 XL Turbo)
 *
 * Flow: prompt utente → LLM (guida ACE-Step) → CAPTION/LYRICS/BPM/KEYSCALE/LANGUAGE/DURATION
 *       → workflow ComfyUI → MP3 → sendAudio.
 */
return [
    'id' => 'audio_gen',
    'description' => "L'utente chiede di generare, comporre, creare o scrivere un brano musicale, una canzone, un pezzo, una musica, una sigla, una ballata (es. 'crea un brano rock', 'componi una canzone su...', 'fammi un pezzo lo-fi', 'scrivi una ballata triste', 'genera una sigla strumentale')",
    'parameters' => [
        'prompt' => "Descrizione del brano richiesto in italiano: genere, mood, tema del testo, strumenti, voce, ecc. — tutto ciò che l'utente specifica",
    ],
    'sends_own_response' => true,
    'handler' => function (array $ctx, array $params): ?array {
        $logFile = dirname(__DIR__, 2) . '/audio_gen.log';
        $log = function (string $msg) use ($logFile) {
            file_put_contents($logFile, '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n", FILE_APPEND);
        };

        // Parser output LLM: estrae CAPTION, LYRICS, BPM, KEYSCALE, LANGUAGE, DURATION
        $parseAceStepOutput = function (string $raw): array {
            $text = trim($raw);
            // Rimuovi eventuali fence markdown
            $text = preg_replace('/^```[a-zA-Z]*\s*/m', '', $text);
            $text = preg_replace('/```\s*$/m', '', $text);

            $markers = ['##CAPTION:', '##LYRICS:', '#BPM:', '#KEYSCALE:', '#LANGUAGE:', '#DURATION:'];
            $extract = function (string $marker) use ($text, $markers): ?string {
                $pattern = '/' . preg_quote($marker, '/') . '\s*(.*?)(?=(?:' .
                    implode('|', array_map(fn($m) => preg_quote($m, '/'), $markers)) . ')|\z)/si';
                if (preg_match($pattern, $text, $m)) {
                    return trim($m[1]);
                }
                return null;
            };

            $caption = $extract('##CAPTION:');
            $lyrics  = $extract('##LYRICS:');
            $bpmRaw  = $extract('#BPM:');
            $key     = $extract('#KEYSCALE:');
            $lang    = $extract('#LANGUAGE:');
            $durRaw  = $extract('#DURATION:');

            $bpm = 110;
            if ($bpmRaw !== null && preg_match('/\d+/', $bpmRaw, $m)) {
                $bpm = max(60, min(200, (int)$m[0]));
            }

            $duration = random_int(120, 210);
            if ($durRaw !== null && preg_match('/\d+/', $durRaw, $m)) {
                $duration = max(90, min(240, (int)$m[0]));
            }

            if (!$key) {
                $key = 'C major';
            }

            if ($lang && preg_match('/[a-z]{2}/i', $lang, $m)) {
                $lang = strtolower($m[0]);
            } else {
                $lang = 'en';
            }

            return [
                'caption'  => $caption ?? '',
                'lyrics'   => $lyrics ?? '',
                'bpm'      => $bpm,
                'keyscale' => $key,
                'language' => $lang,
                'duration' => $duration,
            ];
        };

        $log('Handler called, user=' . $ctx['fromId'] . ', prompt=' . ($params['prompt'] ?? '(empty)'));

        // --- Rate limit: max 6 brani/ora per utente ---
        global $db;
        $maxPerHour = 6;
        try {
            $oneHourAgo = time() - 3600;
            $stmt = $db->prepare('SELECT COUNT(*) as cnt FROM audio_gen_usage WHERE user_id = :uid AND timestamp > :since');
            $stmt->bindValue(':uid', $ctx['fromId'], SQLITE3_INTEGER);
            $stmt->bindValue(':since', $oneHourAgo, SQLITE3_INTEGER);
            $count = (int)$stmt->execute()->fetchArray(SQLITE3_ASSOC)['cnt'];
            $log("Rate limit check: count={$count}, max={$maxPerHour}");
            if ($count >= $maxPerHour) {
                $msgParams = [
                    'chat_id' => $ctx['chatID'],
                    'text' => "Hai gia' composto {$count} brani nell'ultima ora. Il limite e' {$maxPerHour}/ora, riprova tra un po'!",
                ];
                if ($ctx['chatType'] !== 'private') {
                    $msgParams['reply_to_message_id'] = $ctx['messageId'];
                }
                makeAPIRequest('sendMessage', $msgParams);
                return ['handled' => true];
            }
        } catch (\Throwable $e) {
            $log('Rate limit check FAILED: ' . $e->getMessage());
            return ['handled' => true];
        }

        $italianPrompt = trim($params['prompt'] ?? '');
        if ($italianPrompt === '') {
            $msgParams = [
                'chat_id' => $ctx['chatID'],
                'text' => "Non ho capito che brano vuoi. Dimmi genere, mood, tema del testo!",
            ];
            if ($ctx['chatType'] !== 'private') {
                $msgParams['reply_to_message_id'] = $ctx['messageId'];
            }
            makeAPIRequest('sendMessage', $msgParams);
            return ['handled' => true];
        }

        // --- Status message + upload_audio action ---
        makeAPIRequest('sendChatAction', [
            'chat_id' => $ctx['chatID'],
            'action' => 'upload_voice',
        ]);

        $statusParams = [
            'chat_id' => $ctx['chatID'],
            'text' => "Sto componendo il brano... ci vuole qualche minuto.",
        ];
        if ($ctx['chatType'] !== 'private') {
            $statusParams['reply_to_message_id'] = $ctx['messageId'];
        }
        $statusMsg = makeAPIRequest('sendMessage', $statusParams);
        $statusMessageId = ($statusMsg && $statusMsg['ok']) ? $statusMsg['result']['message_id'] : null;

        $cleanup = function () use ($ctx, &$statusMessageId) {
            if ($statusMessageId) {
                makeAPIRequest('deleteMessage', [
                    'chat_id' => $ctx['chatID'],
                    'message_id' => $statusMessageId,
                ]);
                $statusMessageId = null;
            }
        };

        // --- Step 1: Composizione via LLM (guida ACE-Step) ---
        $recentContext = getChatContext($ctx['chatID'], 1, 10);
        $contextSection = '';
        if (!empty($recentContext)) {
            $contextSection = "\n\nCONTESTO CHAT RECENTE (usalo per risolvere riferimenti tipo 'fanne un altro ma piu' lento', 'stesso stile ma con testo inglese', ecc.):\n" . $recentContext;
        }

        $systemPrompt = <<<'SYS'
# Guida compatta ACE-Step per LLM

## Caption (stile musicale)
Combina più dimensioni, specifico batte vago.

**Dimensioni utili:** genere · emozione · strumenti · timbro · epoca · stile produzione · caratteristiche voce · tempo · struttura

**Esempio:** `female vocal, piano ballad, melancholic, intimate, strings, building to powerful chorus, 90s production`

**Regole:**
- Non mettere BPM/tonalità nella caption — vanno nei parametri dedicati
- Evita stili conflittuali (es. "classical strings" + "hardcore metal") — oppure trasformali in evoluzione temporale
- Più dettagli = meno libertà al modello; meno dettagli = più sorprese

---

## Lyrics (script temporale)

**Struttura base:**
```
[Intro]

[Verse 1]
testo prima strofa
continua strofa

[Pre-Chorus]
testo

[Chorus - powerful]
TESTO AD ALTA INTENSITÀ

[Bridge - whispered]
testo sommesso

[Outro - fade out]
```

**Tag strutturali:** `[Intro]` `[Verse]` `[Pre-Chorus]` `[Chorus]` `[Bridge]` `[Outro]` `[Build]` `[Drop]` `[Breakdown]` `[Instrumental]` `[Fade Out]`

**Tag vocali:** `[raspy vocal]` `[whispered]` `[falsetto]` `[powerful belting]` `[spoken word]` `[harmonies]`

**Tag energia:** `[high energy]` `[low energy]` `[building energy]` `[explosive]` `[melancholic]` `[euphoric]`

**Regole lyrics:**
- 6–10 sillabe per riga, coerente tra righe della stessa posizione
- Maiuscolo = intensità vocale alta
- `(testo)` = cori/armonie in background
- Sezioni separate da riga vuota
- Max 2 tag per sezione: `[Chorus - anthemic]` ✅ — `[Chorus - anthemic - stacked - epic - powerful]` ❌
- Caption e Lyrics devono essere **coerenti**: stessi strumenti, stessa energia, stesso registro vocale

**Da evitare nei testi:**
- Stacking di aggettivi vaghi ("neon skies, electric hearts, endless dreams")
- Rime forzate che rompono il senso
- Righe troppo lunghe (non cantabili in un fiato)
- Metafore miste — scegli un'immagine centrale e sviluppala

---

## Strumentale puro
```
[Instrumental]
```
oppure descrivere lo sviluppo:
```
[Intro - ambient]

[Main Theme - piano]

[Climax - powerful]

[Outro - fade out]
```

---

## Coerenza Caption ↔ Lyrics (checklist)
- Strumenti in Caption → tag strumentali in Lyrics
- Emozione in Caption → tag energia in Lyrics
- Descrizione voce in Caption → tag vocali in Lyrics

Seguendo questa guida componi un brano musicale, senza preamboli e conclusioni, usa il formato

##CAPTION:
<caption in inglese>

##LYRICS:
<lyrics nella lingua richiesta, o in inglese se non specificato>

#BPM:
<intero tra 60 e 200>

#KEYSCALE:
<es. C major, A minor, F# minor, ecc.>

#LANGUAGE:
<codice ISO a 2 lettere: it, en, es, fr, de, ja, ... — deve coincidere con la lingua del testo>

#DURATION:
<durata in secondi, intero tra 90 e 240, scelto in base alla struttura del brano>
SYS;

        $llmResult = callOllamaChatViaQBert(
            OLLAMA_MODEL,
            $italianPrompt . $contextSection,
            ollamaOptions(OLLAMA_MODEL_GPU),
            false,
            QBertClient::PRIORITY_NORMAL,
            $systemPrompt
        );

        $raw = stripThinkingTags($llmResult['response'] ?? '');
        $log("LLM raw output:\n" . $raw);

        // --- Step 2: Parser output ---
        $parsed = $parseAceStepOutput($raw);
        $log('Parsed: caption_len=' . strlen($parsed['caption'] ?? '') .
             ', lyrics_len=' . strlen($parsed['lyrics'] ?? '') .
             ', bpm=' . ($parsed['bpm'] ?? 'NULL') .
             ', key=' . ($parsed['keyscale'] ?? 'NULL') .
             ', lang=' . ($parsed['language'] ?? 'NULL') .
             ', duration=' . ($parsed['duration'] ?? 'NULL'));

        if (empty($parsed['caption']) || empty($parsed['lyrics'])) {
            $cleanup();
            return ['response' => "Non sono riuscito a comporre il brano. Riprova con una descrizione piu' chiara."];
        }

        // --- Step 3: Carica e configura workflow ---
        $workflowPath = dirname(__DIR__, 2) . '/workflows/audio_ace_step1_5_xl_turbo.json';
        $workflow = json_decode(file_get_contents($workflowPath), true);
        if (!$workflow) {
            $cleanup();
            $log('Failed to load workflow JSON');
            return ['response' => "Errore interno: impossibile caricare il workflow audio."];
        }

        $workflow['94']['inputs']['tags']     = $parsed['caption'];
        $workflow['94']['inputs']['lyrics']   = $parsed['lyrics'];
        $workflow['94']['inputs']['bpm']      = $parsed['bpm'];
        $workflow['94']['inputs']['keyscale'] = $parsed['keyscale'];
        $workflow['94']['inputs']['language'] = $parsed['language'];
        $workflow['94']['inputs']['duration'] = $parsed['duration'];
        $workflow['98']['inputs']['seconds']  = $parsed['duration'];
        $workflow['109']['inputs']['value']   = random_int(0, 2147483647);

        // --- Step 4: Submit a ComfyUI via QBert ---
        $qbert = getQBertClient();
        $submitResult = $qbert->post('comfyui', '/prompt', json: ['prompt' => $workflow], priority: QBertClient::PRIORITY_NORMAL);

        $promptId = $submitResult['json']['prompt_id'] ?? null;
        if (!$promptId) {
            $cleanup();
            $log('ComfyUI /prompt failed: ' . json_encode($submitResult));
            return ['response' => "Errore nell'avvio della generazione audio. Riprova piu' tardi."];
        }
        $log("Submitted to ComfyUI, prompt_id={$promptId}");

        // --- Step 5: Poll /history fino a output pronto ---
        $lastActionTime = time();
        $start = microtime(true);
        $maxWait = 240.0;
        $pollInterval = 3.0;
        $outputFilename = null;
        $outputSubfolder = 'audio';

        while (true) {
            if ((microtime(true) - $start) > $maxWait) {
                break;
            }

            if ((time() - $lastActionTime) >= 3) {
                makeAPIRequest('sendChatAction', [
                    'chat_id' => $ctx['chatID'],
                    'action' => 'upload_voice',
                ]);
                $lastActionTime = time();
            }

            usleep((int)($pollInterval * 1000000));

            $historyResult = $qbert->get('comfyui', '/history/' . $promptId);

            if (($historyResult['status_code'] ?? 0) === 200 && !empty($historyResult['json'])) {
                $entry = $historyResult['json'][$promptId] ?? null;
                if ($entry && isset($entry['outputs']['107']['audio'][0]['filename'])) {
                    $outputFilename  = $entry['outputs']['107']['audio'][0]['filename'];
                    $outputSubfolder = $entry['outputs']['107']['audio'][0]['subfolder'] ?? 'audio';
                    break;
                }
            }
        }

        if (!$outputFilename) {
            $cleanup();
            $log('Timeout waiting for ComfyUI output');
            return ['response' => "La composizione del brano ha impiegato troppo tempo. Riprova."];
        }
        $log("Output ready: filename={$outputFilename}, subfolder={$outputSubfolder}");

        // --- Step 6: Download MP3 ---
        $viewQuery = '/view?filename=' . urlencode($outputFilename)
                   . '&type=output&subfolder=' . urlencode($outputSubfolder);
        $viewResult = $qbert->get('comfyui', $viewQuery);

        if (($viewResult['status_code'] ?? 0) !== 200 || empty($viewResult['body_bytes'])) {
            $cleanup();
            $log('Failed to download MP3: ' . json_encode(['status' => $viewResult['status_code'] ?? null, 'filename' => $outputFilename]));
            return ['response' => "Errore nel recupero del brano generato."];
        }

        // --- Step 7: Salva in temp e invia ---
        $tmpFile = tempnam(sys_get_temp_dir(), 'audiogen_') . '.mp3';
        file_put_contents($tmpFile, $viewResult['body_bytes']);

        // Titolo = prima riga significativa della caption, troncata
        $title = mb_substr(preg_replace('/\s+/', ' ', $parsed['caption']), 0, 60);
        $caption = mb_substr($italianPrompt, 0, 200);

        $sendAudioParams = [
            'chat_id'   => $ctx['chatID'],
            'audio'     => new \CURLFile($tmpFile, 'audio/mpeg', 'brano.mp3'),
            'title'     => $title,
            'performer' => 'rootbot',
            'duration'  => $parsed['duration'],
            'caption'   => $caption,
        ];
        if ($ctx['chatType'] !== 'private') {
            $sendAudioParams['reply_to_message_id'] = $ctx['messageId'];
        }

        $audioResult = makeAPIRequest('sendAudio', $sendAudioParams);

        // Retry senza reply_to se messaggio originale eliminato
        if ((!$audioResult || !$audioResult['ok']) && isset($sendAudioParams['reply_to_message_id'])) {
            $errDesc = $audioResult['description'] ?? '';
            if (strpos($errDesc, 'message to be replied not found') !== false) {
                unset($sendAudioParams['reply_to_message_id']);
                $sendAudioParams['audio'] = new \CURLFile($tmpFile, 'audio/mpeg', 'brano.mp3');
                $audioResult = makeAPIRequest('sendAudio', $sendAudioParams);
            }
        }

        @unlink($tmpFile);
        $cleanup();

        if (!$audioResult || !$audioResult['ok']) {
            $log('sendAudio failed: ' . json_encode($audioResult));
            return ['response' => "Non sono riuscito a inviare il brano generato."];
        }

        // Salva nel contesto chat per riferimenti futuri
        saveMessageToContext($ctx['chatID'], 'rootbot', "[brano generato: {$italianPrompt}]");

        // Registra utilizzo per rate limit
        $stmt = $db->prepare('INSERT INTO audio_gen_usage (user_id, timestamp) VALUES (:uid, :ts)');
        $stmt->bindValue(':uid', $ctx['fromId'], SQLITE3_INTEGER);
        $stmt->bindValue(':ts', time(), SQLITE3_INTEGER);
        $stmt->execute();

        $log('Audio sent successfully, flow complete');
        return ['handled' => true];
    },
];
