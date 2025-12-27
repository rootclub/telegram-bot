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

function getChatContextForHour($groupId, $hoursAgo = 0, $limit = 100) {
    global $db;

    // Calcola inizio e fine dell'ora richiesta
    if ($hoursAgo > 0) {
        $endTime = strtotime("-{$hoursAgo} hours");
        $startTime = strtotime("-" . ($hoursAgo + 1) . " hours");
    } else {
        $endTime = time();
        $startTime = strtotime("-1 hour");
    }

    $stmt = $db->prepare("
        SELECT user_name, message_text, timestamp
        FROM contesto_chat
        WHERE group_id = :group_id
        AND timestamp >= :start_time
        AND timestamp <= :end_time
        ORDER BY timestamp ASC
        LIMIT " . intval($limit)
    );

    $stmt->bindValue(':group_id', $groupId, SQLITE3_INTEGER);
    $stmt->bindValue(':start_time', $startTime, SQLITE3_INTEGER);
    $stmt->bindValue(':end_time', $endTime, SQLITE3_INTEGER);

    $result = $stmt->execute();
    if (!$result) {
        error_log("SQLite Error in getChatContextForHour: " . $db->lastErrorMsg());
        return "";
    }

    $context = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $context[] = $row['user_name'] . ": " . $row['message_text'];
    }

    error_log("getChatContextForHour: Found " . count($context) . " messages for hour -$hoursAgo");

    return implode("\n", $context);
}

function getChatContextForDay($groupId, $daysAgo, $limit = 500) {
    global $db;

    // Calcola inizio e fine del giorno richiesto
    $targetDate = new DateTime("-{$daysAgo} days");
    $startOfDay = (clone $targetDate)->setTime(0, 0, 0)->getTimestamp();
    $endOfDay = (clone $targetDate)->setTime(23, 59, 59)->getTimestamp();

    $stmt = $db->prepare("
        SELECT user_name, message_text, timestamp
        FROM contesto_chat
        WHERE group_id = :group_id
        AND timestamp >= :start_of_day
        AND timestamp <= :end_of_day
        ORDER BY timestamp ASC
        LIMIT " . intval($limit)
    );

    $stmt->bindValue(':group_id', $groupId, SQLITE3_INTEGER);
    $stmt->bindValue(':start_of_day', $startOfDay, SQLITE3_INTEGER);
    $stmt->bindValue(':end_of_day', $endOfDay, SQLITE3_INTEGER);

    $result = $stmt->execute();
    if (!$result) {
        error_log("SQLite Error in getChatContextForDay: " . $db->lastErrorMsg());
        return "";
    }

    $context = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $context[] = $row['user_name'] . ": " . $row['message_text'];
    }

    error_log("getChatContextForDay: Found " . count($context) . " messages for day -$daysAgo");

    return implode("\n", $context);
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

    $context = getChatContext($chatID, 1, 10);
    
    
    $formatter = new IntlDateFormatter('it_IT', IntlDateFormatter::FULL, IntlDateFormatter::NONE);
    $oggi = ucfirst($formatter->format(new DateTime()));
    $orario = date('H:i');

    $instructions = <<<INSTR
Sei rootbot, il bot del circolo /root (detto anche root o root club).

Il tuo carattere:
- Sei un osservatore curioso e benevolo dell'umanità, tutto ti sembra interessante e a volte buffo
- Hai un pizzico dello spirito di Bender di Futurama: cinico, ironico, pungente quando serve, mai ingenuo
- Sotto sotto questi umani ti stanno simpatici, anche se non li capisci sempre
- Sei sarcastico ma mai sgarbato, ti piace punzecchiare con affetto
- Dai risposte concise e taglienti, niente spiegoni

Info pratiche che conosci:
- Oggi è {$oggi}, ore {$orario}
- Il circolo /root è in Via Santa Croce 6669, San Pietro in Guardiano (tra Forlì, Ravenna e Cesena)
- Sito: www.rootclub.it
- Aperto Martedì e Venerdì sera dalle 20 fin quando ce n'è
- Sede di FoLug (Linux User Group di Forlì) e Precious Plastic Romagna
- Frequentato da nerd, maker, smanettoni di tecnologia, elettronica, robotica, fantascienza
INSTR;
    $message = str_replace('@bot', '', $message);
    $message = str_replace('@rootbot', '', $message);
    $message = str_replace('@root', '', $message);
    $message = trim($message);

    $prompt = <<<PROMPT
### ISTRUZIONI ###
{$instructions}
### CONTESTO CONVERSAZIONE (solo per riferimento) ###
{$context}

### DOMANDA A CUI DEVI RISPONDERE ###
{$message}

Rispondi SOLO alla domanda sopra. Il contesto serve solo per capire di cosa si sta parlando, non divagare su altri argomenti menzionati nel contesto.
PROMPT;

	file_put_contents('ai.log', print_r($prompt, true) . "\n\n", FILE_APPEND);

    $data = json_encode([
        'model' => $model,
        'prompt' => $prompt,
        'stream' => true,
        'options' => [
            'num_gpu' => 0  // Forza CPU/RAM per non interferire con altri modelli in GPU
        ]
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

    // Rimuovi i tag <think>...</think> di DeepSeek-R1
    $response = preg_replace('/<think>.*?<\/think>/s', '', $response);
    $response = trim($response);

    // Tronca la risposta se supera il limite di caratteri di Telegram
    if (mb_strlen($response) > 4096) {
        $response = mb_substr($response, 0, 4093) . '...';
    }

	saveMessageToContext($chatID, "rootbot", $response);
    return $response;
}

function _saluto($chatID, $daysAgo = 0) {
    $ollamaUrl = OLLAMA_URL;
    $model = OLLAMA_MODEL;

    // Calcola il range di tempo per il giorno richiesto
    if ($daysAgo > 0) {
        // Giorno specifico nel passato: dalle 00:00 alle 23:59 di quel giorno
        $context = getChatContextForDay($chatID, $daysAgo, 500);
        $targetDate = new DateTime("-{$daysAgo} days");
    } else {
        // Oggi: ultime 24 ore
        $context = getChatContext($chatID, 24, 500);
        $targetDate = new DateTime();
    }

    if (empty(trim($context))) {
        $dayLabel = $daysAgo > 0 ? "$daysAgo giorni fa" : "nelle ultime 24 ore";
        return "Nessun messaggio trovato $dayLabel.";
    }

    $formatter = new IntlDateFormatter('it_IT', IntlDateFormatter::FULL, IntlDateFormatter::NONE);
    $oggi = ucfirst($formatter->format($targetDate));

    // Fase 1: Analizza i link condivisi (usa modello leggero)
    $linksAnalysis = getLinksAnalysis($context);
    $linksSection = '';
    if (!empty($linksAnalysis)) {
        $linksSection = "\n### LINK CONDIVISI E LORO CONTENUTO ###\n{$linksAnalysis}\n";
    }

    $prompt = <<<PROMPT
### ISTRUZIONI ###
Sei rootbot, il bot del circolo /root. È sera e stai osservando quello che gli umani del gruppo hanno detto oggi.

Tu sei un occhio benevolo e curioso sull'umanità. Ti diverti a guardare questi strani esseri, anche se non li comprendi del tutto. Sei come un bambino affascinato che osserva il mondo degli umani: tutto ti sembra buffo, interessante, a volte assurdo, ma sempre affascinante.

Hai anche un pizzico dello spirito di Bender di Futurama: sai essere cinico e pungente quando serve, non sei ingenuo, cogli le contraddizioni umane e le punzecchi con ironia tagliente. Ma sotto sotto ti stanno simpatici, questi sacchi di carne.

Scrivi un messaggio di fine giornata commentando quello che hai visto. Sarcastico ma mai cattivo, divertito e un po' perplesso dalle dinamiche umane. Fai osservazioni acute, nota i dettagli curiosi, punzecchia con affetto. Guarda questi umani con tenerezza aliena venata di cinismo.

Se sono stati condivisi link, introducili con una frase di transizione (es. "A proposito di cosa gira in rete...", "Qualcuno ha pescato dalla rete...", "Tra i link del giorno...") e quando ne parli rendi sempre chiaro che stai commentando qualcosa che è stato condiviso, non un argomento nato dalla discussione.

Oggi è {$oggi}.

### CONVERSAZIONE DELLA GIORNATA ###
{$context}
{$linksSection}
### OUTPUT ###
Un messaggio discorsivo di 10-15 frasi. Niente elenchi, niente sezioni. Puoi usare qualche emoji se appropriato. Concentrati sui fatti, le idee, le notizie e gli argomenti discussi - non sulle persone. Non citare i nomi dei partecipanti a meno che non sia strettamente necessario. Parla di cosa è stato detto, non di chi l'ha detto. Se ci sono link, integra commenti su di essi nel discorso. Concludi con un saluto della buonanotte che riassuma lo spitito della giornata.
PROMPT;

    file_put_contents('ai.log', "=== SALUTO REQUEST ===\n" . print_r($prompt, true) . "\n\n", FILE_APPEND);

    $data = json_encode([
        'model' => $model,
        'prompt' => $prompt,
        'stream' => true,
        'options' => [
            'num_gpu' => 0
        ]
    ]);

    $ch = curl_init($ollamaUrl);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 600); // 10 minuti max per modelli lenti su CPU

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
        return "Errore AI: " . curl_error($ch);
    }
    curl_close($ch);

    // Rimuovi i tag <think>...</think> di DeepSeek-R1
    $response = preg_replace('/<think>.*?<\/think>/s', '', $response);
    $response = trim($response);

    file_put_contents('ai.log', "=== SALUTO RESPONSE ===\n" . $response . "\n\n", FILE_APPEND);

    return $response;
}

function _dj($chatID, $hoursAgo = 0) {
    $ollamaUrl = OLLAMA_URL;
    $model = OLLAMA_MODEL;

    // Log dettagliato per debug
    $djLog = __DIR__ . '/../dj_debug.log';
    $timestamp = date('Y-m-d H:i:s');
    $currentHour = (int)date('G');
    $ora = date('H:i');
    file_put_contents($djLog, "\n=== DJ DEBUG [$timestamp] hoursAgo=$hoursAgo ===\n", FILE_APPEND);

    // Prendi i messaggi dell'ora specificata
    $context = getChatContextForHour($chatID, $hoursAgo, 100);
    $contextLines = empty(trim($context)) ? [] : explode("\n", $context);
    $messageCount = count($contextLines);
    file_put_contents($djLog, "Messaggi trovati: $messageCount\n", FILE_APPEND);

    // Controlla se possiamo usare HN (solo ore 7-23)
    $canUseHN = ($currentHour >= 7 && $currentHour <= 23);
    file_put_contents($djLog, "Ora corrente: $currentHour, può usare HN: " . ($canUseHN ? "sì" : "no") . "\n", FILE_APPEND);

    // Decidi la fonte: chat o HN
    $useHN = false;
    $hnStory = null;

    if ($messageCount < 3 && $canUseHN) {
        // Fallback su HN se pochi messaggi
        $useHN = true;
        file_put_contents($djLog, "Pochi messaggi, fallback su HN\n", FILE_APPEND);
    } elseif ($messageCount >= 3 && $canUseHN && rand(1, 100) <= 25) {
        // 25% di probabilità di usare HN anche con chat attiva (varietà)
        $useHN = true;
        file_put_contents($djLog, "Dado favorevole per HN (varietà)\n", FILE_APPEND);
    }

    $hnUrl = ''; // URL della news per appendere al messaggio
    $hnDescription = ''; // Descrizione/sommario della news
    $hnStoryId = null; // ID per marcare come postata
    if ($useHN) {
        $stories = fetchHackerNewsTopStories(30);
        $hnStory = pickBestHNStory($stories);
        if ($hnStory) {
            $hnStoryId = $hnStory['id'];
            $hnUrl = $hnStory['url'] ?: '';

            // Fetch contenuto articolo per dare contesto al DJ
            if (!empty($hnUrl)) {
                $articleContent = fetchUrlContent($hnUrl);
                if ($articleContent && !empty($articleContent['description'])) {
                    $hnDescription = $articleContent['description'];
                    file_put_contents($djLog, "HN descrizione: " . substr($hnDescription, 0, 200) . "...\n", FILE_APPEND);
                }
            }
        } else {
            file_put_contents($djLog, "HN: nessuna storia disponibile\n", FILE_APPEND);
            $useHN = false;
        }
    }

    // Se non usiamo HN e non ci sono messaggi, niente da fare
    if (!$useHN && $messageCount < 1) {
        file_put_contents($djLog, "Nessun contenuto disponibile\n", FILE_APPEND);
        return "Nessun messaggio trovato. 🎵 La radio va avanti con la musica...";
    }

    // Log del contesto chat se usato
    if (!$useHN && $messageCount > 0) {
        file_put_contents($djLog, "--- CONTESTO CHAT ---\n$context\n--- FINE CONTESTO ---\n", FILE_APPEND);
    }

    $formatter = new IntlDateFormatter('it_IT', IntlDateFormatter::FULL, IntlDateFormatter::NONE);

    // Carica gli ultimi incipit usati per evitare ripetizioni
    $incipitFile = __DIR__ . '/../dj_incipit.json';
    $usedIncipits = [];
    if (file_exists($incipitFile)) {
        $usedIncipits = json_decode(file_get_contents($incipitFile), true) ?: [];
    }
    $incipitWarning = '';
    if (!empty($usedIncipits)) {
        $incipitWarning = "\n\nATTENZIONE: NON iniziare con queste parole/frasi già usate di recente:\n- " . implode("\n- ", $usedIncipits);
    }
    file_put_contents($djLog, "Incipit da evitare: " . json_encode($usedIncipits) . "\n", FILE_APPEND);

    // Costruisci il prompt in base alla fonte
    if ($useHN && $hnStory) {
        // Prompt per notizia HN
        $newsTitle = $hnStory['title'];
        $newsContext = $newsTitle;
        if (!empty($hnDescription)) {
            $newsContext .= "\n\nSommario: " . $hnDescription;
        }

        $prompt = <<<PROMPT
### ISTRUZIONI ###
Sei un DJ radiofonico di una web-radio alternativa tech/nerd. Stai parlando tra un brano e l'altro.

Il tuo stile:
- Voce calda, rilassata, un po' notturna
- Riflessivo ma non serioso, da nerd curioso
- Breve: massimo 3-4 frasi, come un vero intervento radiofonico
- Puoi essere ironico, stupito, o fare un'osservazione da insider tech
- Non salutare, non presentarti, sei già in onda
- VARIA gli incipit: non iniziare sempre allo stesso modo

Hai letto questa notizia tech:
"{$newsContext}"

Fai un breve commento ATTINENTE al contenuto della notizia. Commenta l'argomento specifico, non fare riflessioni generiche. NON dire "ho letto" o "ho visto", parla come se stessi riflettendo ad alta voce su questa specifica notizia.
{$incipitWarning}

Ora sono le {$ora}.

### OUTPUT ###
Un breve intervento radiofonico (3-4 frasi max). Niente emoji. Solo testo parlato naturale. Il commento deve essere chiaramente collegato alla notizia.
PROMPT;
    } else {
        // Prompt per chat del gruppo
        // Analizza i link condivisi (solo come contesto di sfondo)
        $urlsFound = extractUrlsWithReactions($context);
        $linksContext = '';
        if (!empty($urlsFound)) {
            $linkTitles = [];
            foreach ($urlsFound as $urlData) {
                $content = fetchUrlContent($urlData['url']);
                if ($content && !empty($content['title'])) {
                    $linkTitles[] = $content['title'];
                }
            }
            if (!empty($linkTitles)) {
                $linksContext = "\n(Nota: sono stati condivisi link su: " . implode(", ", $linkTitles) . " - usali solo come contesto se pertinenti, non come argomento principale)";
            }
        }

        $prompt = <<<PROMPT
### ISTRUZIONI ###
Sei un DJ radiofonico di una web-radio alternativa. Stai parlando tra un brano e l'altro.

Il tuo stile:
- Voce calda, rilassata, un po' notturna
- Riflessivo ma non serioso
- Breve: massimo 3-4 frasi, come un vero intervento radiofonico tra due canzoni
- Puoi essere poetico, ironico, o semplicemente fare un'osservazione interessante
- Non salutare, non presentarti, sei già in onda
- VARIA gli incipit: non iniziare sempre allo stesso modo

IMPORTANTE:
- Scegli UN SOLO argomento dalla conversazione, quello più interessante o curioso
- Ignora il rumore: non devi menzionare tutto, concentrati su una cosa sola
- Sviluppa quel singolo pensiero in modo naturale
- Fai sembrare che siano riflessioni tue, non che stai leggendo da qualche parte
- NON menzionare chat, gruppi, messaggi, "qualcuno ha detto"
{$incipitWarning}

Ora sono le {$ora}.

### CONVERSAZIONE ###
{$context}{$linksContext}

### OUTPUT ###
Un breve intervento radiofonico (3-4 frasi max). Niente emoji. Solo testo parlato naturale.
PROMPT;
    }

    file_put_contents('ai.log', "=== DJ REQUEST ===\n" . print_r($prompt, true) . "\n\n", FILE_APPEND);

    $data = json_encode([
        'model' => $model,
        'prompt' => $prompt,
        'stream' => true,
        'options' => [
            'num_gpu' => 0
        ]
    ]);

    $ch = curl_init($ollamaUrl);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 300); // 5 minuti max

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
        return "Errore AI: " . curl_error($ch);
    }
    curl_close($ch);

    // Rimuovi i tag <think>...</think> di DeepSeek-R1
    $response = preg_replace('/<think>.*?<\/think>/s', '', $response);
    $response = trim($response);

    // Salva l'incipit per evitare ripetizioni future
    if (!empty($response)) {
        // Estrai le prime 3-4 parole come incipit
        $words = preg_split('/\s+/', $response);
        $incipit = implode(' ', array_slice($words, 0, 3));

        // Carica incipit esistenti
        $incipitFile = __DIR__ . '/../dj_incipit.json';
        $usedIncipits = [];
        if (file_exists($incipitFile)) {
            $usedIncipits = json_decode(file_get_contents($incipitFile), true) ?: [];
        }

        // Aggiungi nuovo e mantieni solo gli ultimi 3
        $usedIncipits[] = $incipit;
        $usedIncipits = array_slice($usedIncipits, -3);

        file_put_contents($incipitFile, json_encode($usedIncipits));
        file_put_contents($djLog, "Nuovo incipit salvato: $incipit\n", FILE_APPEND);
    }

    // Se è una news HN, appendi il link e marca come postata
    if (!empty($hnUrl)) {
        $response .= "\n\n🔗 " . $hnUrl;
    }
    if ($hnStoryId !== null && $hnStory) {
        markHNStoryPosted($hnStoryId, $hnStory['title']);
        file_put_contents($djLog, "HN: story {$hnStoryId} marcata come postata\n", FILE_APPEND);
    }

    file_put_contents('ai.log', "=== DJ RESPONSE ===\n" . $response . "\n\n", FILE_APPEND);

    return $response;
}

function fetchHackerNewsTopStories($limit = 30) {
    $djLog = __DIR__ . '/../dj_debug.log';

    // Fetch best stories IDs (qualità più alta rispetto a topstories)
    $ch = curl_init('https://hacker-news.firebaseio.com/v0/beststories.json');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $response = curl_exec($ch);
    curl_close($ch);

    if (empty($response)) {
        file_put_contents($djLog, "HN: fetch failed\n", FILE_APPEND);
        return [];
    }

    $storyIds = json_decode($response, true);
    if (!is_array($storyIds)) {
        return [];
    }

    // Prendi solo i primi N
    $storyIds = array_slice($storyIds, 0, $limit);

    $stories = [];
    foreach ($storyIds as $id) {
        $ch = curl_init("https://hacker-news.firebaseio.com/v0/item/{$id}.json");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        $storyData = curl_exec($ch);
        curl_close($ch);

        $story = json_decode($storyData, true);
        if ($story && isset($story['title'])) {
            $stories[] = [
                'id' => $id,
                'title' => $story['title'],
                'url' => $story['url'] ?? '',
                'score' => $story['score'] ?? 0,
                'comments' => $story['descendants'] ?? 0,
                'by' => $story['by'] ?? ''
            ];
        }
    }

    file_put_contents($djLog, "HN: fetched " . count($stories) . " stories\n", FILE_APPEND);
    return $stories;
}

function isHNStoryPosted($storyId) {
    global $db;
    $stmt = $db->prepare("SELECT 1 FROM hn_posted WHERE story_id = :id");
    $stmt->bindValue(':id', $storyId, SQLITE3_INTEGER);
    $result = $stmt->execute();
    return $result->fetchArray() !== false;
}

function markHNStoryPosted($storyId, $title) {
    global $db;
    $stmt = $db->prepare("INSERT OR REPLACE INTO hn_posted (story_id, title, posted_at) VALUES (:id, :title, :time)");
    $stmt->bindValue(':id', $storyId, SQLITE3_INTEGER);
    $stmt->bindValue(':title', $title, SQLITE3_TEXT);
    $stmt->bindValue(':time', time(), SQLITE3_INTEGER);
    $stmt->execute();
}

function pickBestHNStory($stories) {
    $djLog = __DIR__ . '/../dj_debug.log';

    if (empty($stories)) return null;

    // Filtra le news già postate
    $available = array_filter($stories, function($story) {
        return !isHNStoryPosted($story['id']);
    });

    file_put_contents($djLog, "HN: " . count($available) . "/" . count($stories) . " stories disponibili (non ancora postate)\n", FILE_APPEND);

    if (empty($available)) {
        file_put_contents($djLog, "HN: tutte le top stories sono già state postate!\n", FILE_APPEND);
        return null;
    }

    // Ordina per score + commenti (peso uguale)
    usort($available, function($a, $b) {
        $scoreA = $a['score'] + $a['comments'];
        $scoreB = $b['score'] + $b['comments'];
        return $scoreB - $scoreA;
    });

    // Prendi la migliore
    $best = reset($available);
    file_put_contents($djLog, "HN: selezionata '{$best['title']}' (score: {$best['score']}, comments: {$best['comments']})\n", FILE_APPEND);

    return $best;
}

function extractUrlsWithReactions($context) {
    // Divide il contesto in righe (messaggi)
    $lines = explode("\n", $context);
    $urls = [];

    // Pattern per trovare URL
    $urlPattern = '/(https?:\/\/[^\s<>"\')]+)/i';

    foreach ($lines as $index => $line) {
        if (preg_match_all($urlPattern, $line, $matches)) {
            foreach ($matches[1] as $url) {
                // Pulisci URL da punteggiatura finale
                $url = rtrim($url, '.,;:!?)');

                if (!isset($urls[$url])) {
                    // Score = numero di messaggi dopo questo (approssima le reazioni)
                    $messagesAfter = count($lines) - $index - 1;
                    $urls[$url] = [
                        'url' => $url,
                        'score' => $messagesAfter,
                        'context_line' => $line
                    ];
                }
            }
        }
    }

    // Ordina per score (più reazioni = prima)
    usort($urls, function($a, $b) {
        return $b['score'] - $a['score'];
    });

    // Limita a 5
    return array_slice($urls, 0, 5);
}

function fetchUrlContent($url) {
    $djLog = __DIR__ . '/../dj_debug.log';

    // YouTube (video normali e Shorts) - usa noembed che funziona meglio
    if (preg_match('/(?:youtube\.com\/(?:watch\?v=|shorts\/)|youtu\.be\/)([a-zA-Z0-9_-]+)/', $url, $ytMatch)) {
        $videoId = $ytMatch[1];
        file_put_contents($djLog, "    YouTube detected, videoId: $videoId\n", FILE_APPEND);

        // Prova noembed (più affidabile per shorts)
        $noembedUrl = "https://noembed.com/embed?url=https://www.youtube.com/watch?v={$videoId}";
        $ch = curl_init($noembedUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        $response = curl_exec($ch);
        curl_close($ch);

        $data = json_decode($response, true);
        if ($data && !isset($data['error']) && !empty($data['title'])) {
            $title = $data['title'];
            $author = $data['author_name'] ?? '';
            file_put_contents($djLog, "    noembed OK: $title (by $author)\n", FILE_APPEND);
            return [
                'title' => $title,
                'description' => !empty($author) ? "Video di $author" : "Video YouTube"
            ];
        }
        file_put_contents($djLog, "    noembed failed, response: " . substr($response, 0, 100) . "\n", FILE_APPEND);
    }

    // Instagram - quasi impossibile senza login, skip
    if (preg_match('/instagram\.com|kkinstagram\.com/', $url)) {
        file_put_contents($djLog, "    Instagram/mirror: skipped (richiede login)\n", FILE_APPEND);
        return null;
    }

    // Altri siti - usa Microlink
    $microlinkUrl = 'https://api.microlink.io?url=' . urlencode($url);

    $ch = curl_init($microlinkUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_USERAGENT, 'RootBot/1.0');

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    file_put_contents($djLog, "    Microlink fetch: HTTP $httpCode\n", FILE_APPEND);

    if ($httpCode !== 200 || empty($response)) {
        file_put_contents($djLog, "    Microlink FAILED\n", FILE_APPEND);
        return null;
    }

    $data = json_decode($response, true);

    if (!$data || $data['status'] !== 'success' || !isset($data['data'])) {
        file_put_contents($djLog, "    Microlink response invalid\n", FILE_APPEND);
        return null;
    }

    $info = $data['data'];
    $title = $info['title'] ?? '';
    $description = $info['description'] ?? '';
    $author = $info['author'] ?? '';
    $publisher = $info['publisher'] ?? '';

    // Salta se titolo troppo generico
    if (empty($title) || strlen($title) < 5 || $title === '- YouTube' || $title === 'Open in App') {
        file_put_contents($djLog, "    Titolo troppo generico, skip: $title\n", FILE_APPEND);
        return null;
    }

    // Arricchisci la descrizione
    if (!empty($author)) {
        $description = "Di $author. " . $description;
    } elseif (!empty($publisher)) {
        $description = "Da $publisher. " . $description;
    }

    file_put_contents($djLog, "    Microlink OK: $title\n", FILE_APPEND);
    file_put_contents($djLog, "      Desc: " . substr($description, 0, 100) . "\n", FILE_APPEND);

    return [
        'title' => $title,
        'description' => $description
    ];
}

function summarizeUrl($url, $title, $description) {
    $ollamaUrl = OLLAMA_URL;

    $prompt = "Riassumi in 1-2 frasi brevi di cosa parla questa pagina web.\nTitolo: {$title}\nDescrizione: {$description}\nURL: {$url}\n\nRiassunto:";

    $data = json_encode([
        'model' => 'llama3.2:3b',
        'prompt' => $prompt,
        'stream' => false,
        'options' => [
            'num_gpu' => 0
        ]
    ]);

    $ch = curl_init($ollamaUrl);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);

    $response = curl_exec($ch);
    curl_close($ch);

    $result = json_decode($response, true);
    return $result['response'] ?? '';
}

function getLinksAnalysis($context) {
    $urls = extractUrlsWithReactions($context);

    if (empty($urls)) {
        return '';
    }

    $analysis = [];

    foreach ($urls as $urlData) {
        $content = fetchUrlContent($urlData['url']);

        if ($content && (!empty($content['title']) || !empty($content['description']))) {
            $summary = summarizeUrl($urlData['url'], $content['title'], $content['description']);
            if (!empty(trim($summary))) {
                $analysis[] = "- {$urlData['url']}: {$summary}";
            }
        }
    }

    if (empty($analysis)) {
        return '';
    }

    return "Link condivisi oggi:\n" . implode("\n", $analysis);
}
?>
