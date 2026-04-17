<?php
/**
 * Agente Image Query — domande su immagini condivise in precedenza
 *
 * Cerca tra le immagini recenti quella a cui l'utente si riferisce,
 * poi rilancia analyzeImage() con la domanda specifica.
 */
return [
    'id' => 'image_query',
    'description' => "L'utente chiede informazioni su un'immagine o foto condivisa in precedenza nel gruppo (es. 'cosa c'era nell'ultima foto?', 'nella foto dei gattini...', 'dimmi di piu sull'immagine di prima', 'che auto era quella nella foto?')",
    'parameters' => [
        'riferimento' => "Descrizione dell'immagine a cui l'utente si riferisce (es. 'ultima foto', 'foto dei gattini', 'immagine del circuito'). Se l'utente dice 'ultima' o non specifica, scrivi 'ultima'.",
        'domanda' => "La domanda specifica dell'utente sull'immagine",
    ],
    'handler' => function (array $ctx, array $params): ?array {
        $riferimento = trim($params['riferimento'] ?? 'ultima');
        $domanda = trim($params['domanda'] ?? '');

        // Recupera immagini recenti
        $images = getRecentImages($ctx['chatID'], 10);

        if (empty($images)) {
            return ['response' => "Non ho immagini recenti in memoria per questa chat."];
        }

        // Se riferimento generico ("ultima"), prendi la più recente senza chiamare LLM
        $isUltima = preg_match('/\b(ultima|ultime|recente|prima|precedente)\b/i', $riferimento);

        $selectedImage = null;

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

            $matchResponse = stripThinkingTags($matchResult['response'] ?? '');

            // Estrai il numero dalla risposta
            if (preg_match('/(\d+)/', $matchResponse, $m)) {
                $idx = intval($m[1]) - 1;
                if ($idx >= 0 && $idx < count($images)) {
                    $selectedImage = $images[$idx];
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
