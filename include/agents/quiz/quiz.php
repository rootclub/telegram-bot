<?php
/**
 * Sistema Quiz per rootbot
 * Genera quiz da Wikipedia usando doppio LLM (generatore + revisore)
 */

require_once dirname(__DIR__, 2) . '/QBertClient.php';
require_once dirname(__DIR__, 2) . '/logger.php';

// Le funzioni Wikipedia (fetchWikipediaContentByLang, isDisambiguationPage)
// vivono in include/wikipedia.php: sono condivise con l'agente wikipedia e con il DJ.
require_once dirname(__DIR__, 2) . '/wikipedia.php';

/**
 * Chiama Ollama con un modello specifico via QBert
 * @param string $prompt Il prompt da inviare
 * @param string $model Il modello da usare
 * @param bool $useGpu Se usare GPU o CPU
 * @param string $logFile File di log
 * @param string $label Etichetta per il log
 * @param int $timeout Timeout in secondi (non più usato direttamente)
 * @return string|null Risposta o null se errore
 */
function callOllamaQuiz($prompt, $model, $useGpu, $logFile, $label = 'quiz', $timeout = 90) {
    $gpuLabel = $useGpu ? 'GPU' : 'CPU';
    file_put_contents($logFile, "\n[" . date('Y-m-d H:i:s') . "] --- $label (model: $model, $gpuLabel) ---\n", FILE_APPEND);
    file_put_contents($logFile, "PROMPT:\n$prompt\n", FILE_APPEND);

    $options = [
        'temperature' => OLLAMA_TEMPERATURE,
        'top_p' => OLLAMA_TOP_P,
        'top_k' => OLLAMA_TOP_K,
    ];
    if (!$useGpu) {
        $options['num_gpu'] = 0;
    }

    $startTime = time();
    // /api/chat + think=false (Gemma 4 pattern). Il reasoning via <|think|> nel prompt
    // era il vecchio modo di attivarlo su /api/generate ma causava response vuote.
    $result = callOllamaChatViaQBert($model, $prompt, $options, false, QBertClient::PRIORITY_LAZY);
    $elapsed = time() - $startTime;

    file_put_contents($logFile, "[$label] QBert call, time: {$elapsed}s\n", FILE_APPEND);

    if (!$result) {
        file_put_contents($logFile, "[$label] QBert ERROR\n", FILE_APPEND);
        return null;
    }

    $response = $result['response'] ?? '';

    $response = stripThinkingTags($response);
    $response = trim($response);

    file_put_contents($logFile, "RESPONSE:\n$response\n", FILE_APPEND);

    return $response;
}

/**
 * Punto di ingresso principale per /quiz
 * @param string $text Testo del comando
 * @param int $chatId ID della chat
 * @param int $userId ID utente che ha richiesto
 * @param string $userName Nome utente
 * @return string|null Messaggio di errore o null se quiz inviato
 */
function handleQuizCommand($text, $chatId, $userId, $userName) {
    // Estrai argomento dal comando: /quiz [argomento]
    $topic = extractQuizTopic($text);

    return generateAndSendQuiz($chatId, $topic, $userId, $userName);
}

/**
 * Gestisce pattern naturale "hey rootbot, fai un quiz su X"
 * @param string $text Messaggio completo
 * @param bool $isPrivateChat Se true, accetta anche pattern senza menzione bot
 * @return string|null Argomento estratto o null se non è richiesta quiz
 */
function detectNaturalQuizRequest($text, $isPrivateChat = false) {
    // Pattern: menzione bot + quiz su/di/circa [argomento]
    $patterns = [
        '/\b(?:rootbot|@root|@rootbot|@rootbotbot)[,\s]+.*?(?:fai|fammi|facciamo|genera|lancia|un)\s+(?:un\s+)?quiz\s+(?:su|di|circa|riguardo\s+a?|sul|sulla|sullo|sui|sulle|sugli)\s+(.+?)(?:\?|!|$|\.)/ui',
        '/(?:fai|fammi|facciamo|genera|lancia)\s+(?:un\s+)?quiz\s+(?:su|di|circa|riguardo\s+a?|sul|sulla|sullo|sui|sulle|sugli)\s+(.+?)\s+(?:rootbot|@root|@rootbot|@rootbotbot)/ui',
        '/\b(?:rootbot|@root|@rootbot|@rootbotbot)[,\s]+.*?quiz\s*$/ui', // Solo "rootbot quiz" senza argomento
    ];

    // In chat privata, accetta anche pattern senza menzione bot
    if ($isPrivateChat) {
        $patterns[] = '/(?:fai|fammi|facciamo|genera|lancia)\s+(?:un\s+)?quiz\s+(?:su|di|circa|riguardo\s+a?|sul|sulla|sullo|sui|sulle|sugli)\s+(.+?)(?:\?|!|$|\.)/ui';
        $patterns[] = '/(?:fai|fammi|facciamo|genera|lancia)\s+(?:un\s+)?quiz\s*$/ui'; // Solo "fai un quiz" senza argomento
    }

    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $text, $matches)) {
            // Se cattura gruppo 1 presente, è l'argomento
            if (!empty($matches[1])) {
                return trim($matches[1]);
            }
            // Altrimenti quiz senza argomento
            return '';
        }
    }
    return null;
}

/**
 * Estrae argomento dal comando /quiz
 * Supporta lista separata da virgole: /quiz storia, arte, scienza
 * In quel caso sceglie random tra gli argomenti
 */
function extractQuizTopic($text) {
    if (preg_match('/^\/quiz(?:@rootbotbot)?(?:\s+(.+))?$/ui', $text, $matches)) {
        if (!empty($matches[1])) {
            $topicString = trim($matches[1]);

            // Se contiene virgole, è una lista di argomenti
            if (strpos($topicString, ',') !== false) {
                $topics = array_map('trim', explode(',', $topicString));
                $topics = array_filter($topics); // Rimuovi vuoti
                if (!empty($topics)) {
                    return $topics[array_rand($topics)]; // Scegli random
                }
            }

            return $topicString;
        }
    }
    return null; // Random topic
}

/**
 * Ottiene un argomento random dal database
 */
function getRandomTopic() {
    global $db;
    $result = $db->query("SELECT topic FROM quiz_topics ORDER BY RANDOM() LIMIT 1");
    $row = $result->fetchArray(SQLITE3_ASSOC);
    return $row ? $row['topic'] : 'cultura generale';
}


/**
 * Aggiorna il messaggio di stato del quiz
 */
function updateQuizStatus($chatId, $messageId, $text) {
    editTelegramMessage($chatId, $messageId, $text);
}

/**
 * Genera e invia il quiz
 */
function generateAndSendQuiz($chatId, $topic, $userId, $userName) {
    global $db;

    $logFile = logPath('quiz');
    $statusMessageId = null;

    // Se topic null o vuoto, scegli random dal DB
    if ($topic === null || $topic === '') {
        $topic = getRandomTopic();
    }
    // Se topic contiene virgole, scegli random dalla lista
    elseif (strpos($topic, ',') !== false) {
        $topics = array_map('trim', explode(',', $topic));
        $topics = array_filter($topics);
        if (!empty($topics)) {
            $topic = $topics[array_rand($topics)];
        }
    }

    // Invia messaggio di stato iniziale
    $statusResult = sendTelegramMessage($chatId, "Sto preparando un quiz su $topic...");
    if ($statusResult['ok']) {
        $statusMessageId = $statusResult['message_id'];
    }

    file_put_contents($logFile, "\n" . str_repeat('=', 60) . "\n", FILE_APPEND);
    file_put_contents($logFile, "[" . date('Y-m-d H:i:s') . "] Quiz richiesto da $userName su: $topic\n", FILE_APPEND);

    // Aggiorna stato: cerco informazioni
    if ($statusMessageId) {
        updateQuizStatus($chatId, $statusMessageId, "Cerco informazioni su $topic...");
    }

    // Step 1: Prima prova a cercare direttamente su Wikipedia
    $wikiContent = null;
    $wikiTitle = null;
    $needSubtopics = false;

    $directResult = fetchWikipediaContent($topic);
    if ($directResult && strlen($directResult['extract']) > 500) {
        // Controlla se è una pagina di disambiguazione
        if (isDisambiguationPage($directResult['extract'])) {
            file_put_contents($logFile, "Wikipedia '$topic' e' una disambiguazione, genero subtopics\n", FILE_APPEND);
            $needSubtopics = true;
        } else {
            $wikiContent = $directResult['extract'];
            $wikiTitle = $directResult['title'];
            $wikiLang = $directResult['lang'] ?? '?';
            file_put_contents($logFile, "Wikipedia diretto [$wikiLang]: $wikiTitle (" . strlen($wikiContent) . " chars)\n", FILE_APPEND);
        }
    } else {
        file_put_contents($logFile, "Wikipedia diretto non trovato o insufficiente, genero subtopics\n", FILE_APPEND);
        $needSubtopics = true;
    }

    // Step 2: Se serve, genera subtopics e cerca con quelli
    if ($needSubtopics) {
        $subtopics = generateSubtopics($topic, $logFile);
        if (empty($subtopics)) {
            if ($statusMessageId) {
                makeAPIRequest('deleteMessage', ['chat_id' => $chatId, 'message_id' => $statusMessageId]);
            }
            return "Non sono riuscito a generare argomenti per '$topic'. Riprova!";
        }

        shuffle($subtopics);

        foreach ($subtopics as $subtopic) {
            $wikiResult = fetchWikipediaContent($subtopic);
            if ($wikiResult && strlen($wikiResult['extract']) > 500 && !isDisambiguationPage($wikiResult['extract'])) {
                $wikiContent = $wikiResult['extract'];
                $wikiTitle = $wikiResult['title'];
                $wikiLang = $wikiResult['lang'] ?? '?';
                file_put_contents($logFile, "Wikipedia subtopic [$wikiLang]: $wikiTitle (" . strlen($wikiContent) . " chars)\n", FILE_APPEND);
                break;
            }
        }
    }

    if (!$wikiContent) {
        if ($statusMessageId) {
            makeAPIRequest('deleteMessage', ['chat_id' => $chatId, 'message_id' => $statusMessageId]);
        }
        return "Non ho trovato contenuti sufficienti per '$topic'. Prova un altro argomento!";
    }

    // Aggiorna stato: genero la domanda
    if ($statusMessageId) {
        updateQuizStatus($chatId, $statusMessageId, "Genero la domanda...");
    }

    // Step 3: Genera quiz con LLM GENERATOR
    $quizData = generateQuizFromContent($wikiContent, $wikiTitle, $topic, $logFile);
    if (!$quizData) {
        if ($statusMessageId) {
            makeAPIRequest('deleteMessage', ['chat_id' => $chatId, 'message_id' => $statusMessageId]);
        }
        return "Errore nella generazione del quiz. Riprova!";
    }

    // Aggiorna stato: revisiono il quiz
    if ($statusMessageId) {
        updateQuizStatus($chatId, $statusMessageId, "Revisiono il quiz...");
    }

    // Step 4: Revisiona quiz con LLM REVIEWER
    $reviewedQuiz = reviewQuiz($quizData, $wikiContent, $wikiTitle, $logFile);
    if (!$reviewedQuiz) {
        // Se revisione fallisce, usa quiz originale
        file_put_contents($logFile, "Revisione fallita, uso quiz originale\n", FILE_APPEND);
        $reviewedQuiz = $quizData;
    }

    // Cancella messaggio di stato prima di inviare il poll
    if ($statusMessageId) {
        makeAPIRequest('deleteMessage', ['chat_id' => $chatId, 'message_id' => $statusMessageId]);
    }

    // Step 5: Invia poll quiz
    $pollResult = sendQuizPoll($chatId, $reviewedQuiz);
    if (!$pollResult || !isset($pollResult['ok']) || !$pollResult['ok']) {
        file_put_contents($logFile, "Errore sendPoll: " . print_r($pollResult, true) . "\n", FILE_APPEND);
        return "Errore nell'invio del quiz. Riprova!";
    }

    // Step 6: Salva nel database
    $pollId = $pollResult['result']['poll']['id'];
    $messageId = $pollResult['result']['message_id'];
    $optionsJson = json_encode($reviewedQuiz['options'], JSON_UNESCAPED_UNICODE);

    $stmt = $db->prepare("INSERT INTO quiz_history
        (chat_id, poll_id, message_id, topic, wikipedia_title, question, options, correct_option, explanation)
        VALUES (:chat_id, :poll_id, :message_id, :topic, :wiki_title, :question, :options, :correct, :explanation)");
    $stmt->bindValue(':chat_id', $chatId, SQLITE3_INTEGER);
    $stmt->bindValue(':poll_id', $pollId, SQLITE3_TEXT);
    $stmt->bindValue(':message_id', $messageId, SQLITE3_INTEGER);
    $stmt->bindValue(':topic', $topic, SQLITE3_TEXT);
    $stmt->bindValue(':wiki_title', $wikiTitle, SQLITE3_TEXT);
    $stmt->bindValue(':question', $reviewedQuiz['question'], SQLITE3_TEXT);
    $stmt->bindValue(':options', $optionsJson, SQLITE3_TEXT);
    $stmt->bindValue(':correct', $reviewedQuiz['correct_option'], SQLITE3_INTEGER);
    $stmt->bindValue(':explanation', $reviewedQuiz['explanation'] ?? '', SQLITE3_TEXT);
    $stmt->execute();

    file_put_contents($logFile, "Quiz inviato con successo! poll_id: $pollId\n", FILE_APPEND);

    return null; // Quiz inviato con successo
}

/**
 * Genera lista di subtopics usando LLM GENERATOR
 */
function generateSubtopics($topic, $logFile) {
    $prompt = <<<PROMPT
Generate a list of 10 specific topics related to "$topic" suitable for searching on English Wikipedia.
The topics must be:
- Specific but not too obscure
- Suitable for creating general knowledge quiz questions
- Searchable on Wikipedia (proper names, events, known concepts)

Reply ONLY with a JSON array of strings in English, nothing else:
["topic1", "topic2", ...]
PROMPT;

    $response = callOllamaQuiz($prompt, OLLAMA_QUIZ_SUBTOPICS, OLLAMA_QUIZ_SUBTOPICS_GPU, $logFile, 'subtopics', 60);

    if (empty($response)) {
        return [];
    }

    // Estrai JSON dalla risposta
    if (preg_match('/\[.*\]/s', $response, $matches)) {
        $decoded = json_decode($matches[0], true);
        if (is_array($decoded)) {
            return $decoded;
        }
    }

    return [];
}


/**
 * Cerca contenuto su Wikipedia (italiano e inglese), restituisce il migliore
 */
function fetchWikipediaContent($searchTerm) {
    // Cerca su entrambe le Wikipedia
    $resultIt = fetchWikipediaContentByLang($searchTerm, 'it');
    $resultEn = fetchWikipediaContentByLang($searchTerm, 'en');

    // Se nessun risultato
    if (!$resultIt && !$resultEn) {
        return null;
    }

    // Se solo uno dei due ha risultato
    if (!$resultIt) {
        return $resultEn;
    }
    if (!$resultEn) {
        return $resultIt;
    }

    // Entrambi hanno risultato: scegli quello con più contenuto
    // (escludendo le disambiguazioni)
    $itIsDisambig = isDisambiguationPage($resultIt['extract']);
    $enIsDisambig = isDisambiguationPage($resultEn['extract']);

    // Se uno è disambiguazione e l'altro no, scegli quello che non lo è
    if ($itIsDisambig && !$enIsDisambig) {
        return $resultEn;
    }
    if (!$itIsDisambig && $enIsDisambig) {
        return $resultIt;
    }

    // Altrimenti scegli quello con più contenuto
    if (strlen($resultIt['extract']) >= strlen($resultEn['extract'])) {
        return $resultIt;
    }
    return $resultEn;
}

/**
 * Genera quiz dal contenuto Wikipedia usando LLM GENERATOR
 * @return array|null ['question', 'options', 'correct_option', 'explanation']
 */
function generateQuizFromContent($content, $title, $topic, $logFile) {
    // Tronca contenuto se troppo lungo
    if (strlen($content) > 2500) {
        $content = substr($content, 0, 2500) . '...';
    }

    $prompt = <<<PROMPT
You have this information about "$title":

$content

Create a trivia quiz question in ITALIAN about this topic.

IMPORTANT RULES:
1. Write a natural trivia question - as if for a pub quiz or TV game show
2. DO NOT reference "the text", "the article", "the content", "according to..." - the user hasn't read anything
3. The question must be self-contained and make sense on its own
4. Provide 4 answer options in ITALIAN
5. Only ONE answer must be correct
6. Wrong options must be plausible but clearly incorrect
7. Provide a brief explanation in ITALIAN (max 180 characters)

GOOD EXAMPLE: "In quale anno e' stato fondato il circolo /root?"
BAD EXAMPLE: "Secondo il testo, quando e' stato fondato il circolo?"

Reply ONLY with this JSON:
{
    "question": "Domanda naturale in italiano?",
    "options": ["Opzione A", "Opzione B", "Opzione C", "Opzione D"],
    "correct_option": 0,
    "explanation": "Spiegazione (max 180 caratteri)"
}

correct_option is the INDEX (0-3) of the correct answer.
PROMPT;

    // Retry fino a 2 tentativi in caso di JSON malformato
    $maxRetries = 2;
    for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
        $label = $attempt > 1 ? "quiz_gen_retry$attempt" : 'quiz_gen';
        $response = callOllamaQuiz($prompt, OLLAMA_QUIZ_GENERATOR, OLLAMA_QUIZ_GENERATOR_GPU, $logFile, $label, 120);

        if (empty($response)) {
            continue;
        }

        $parsed = parseQuizJson($response);
        if ($parsed !== null) {
            return $parsed;
        }

        file_put_contents($logFile, "[quiz_gen] JSON parsing fallito, tentativo $attempt/$maxRetries\n", FILE_APPEND);
    }

    return null;
}

/**
 * Revisiona il quiz con LLM REVIEWER
 */
function reviewQuiz($quizData, $wikiContent, $wikiTitle, $logFile) {
    // Tronca contenuto se troppo lungo
    if (strlen($wikiContent) > 2500) {
        $wikiContent = substr($wikiContent, 0, 2500) . '...';
    }

    $quizJson = json_encode($quizData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

    $prompt = <<<PROMPT
You are a quiz reviewer. Check this quiz for accuracy and quality.

WIKIPEDIA SOURCE about "$wikiTitle":
$wikiContent

QUIZ TO REVIEW:
$quizJson

YOUR TASKS:
1. Verify the correct answer is factually accurate based on the Wikipedia source
2. Check that wrong options are plausible but clearly incorrect
3. Improve the question wording if needed (keep it in Italian)
4. Ensure explanation is clear and under 180 characters (in Italian)
5. If everything is correct, return the quiz unchanged
6. If there are errors, fix them

Reply ONLY with the corrected/validated JSON:
{
    "question": "Domanda corretta in italiano?",
    "options": ["Opzione A", "Opzione B", "Opzione C", "Opzione D"],
    "correct_option": 0,
    "explanation": "Spiegazione corretta (max 180 caratteri)"
}
PROMPT;

    // Retry fino a 2 tentativi in caso di JSON malformato
    $maxRetries = 2;
    for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
        $label = $attempt > 1 ? "quiz_review_retry$attempt" : 'quiz_review';
        $response = callOllamaQuiz($prompt, OLLAMA_QUIZ_REVIEWER, OLLAMA_QUIZ_REVIEWER_GPU, $logFile, $label, 120);

        if (empty($response)) {
            continue;
        }

        $parsed = parseQuizJson($response);
        if ($parsed !== null) {
            return $parsed;
        }

        file_put_contents($logFile, "[quiz_review] JSON parsing fallito, tentativo $attempt/$maxRetries\n", FILE_APPEND);
    }

    return null;
}

/**
 * Parsa e valida JSON quiz
 */
function parseQuizJson($response) {
    // Estrai JSON dalla risposta
    if (preg_match('/\{.*\}/s', $response, $matches)) {
        $decoded = json_decode($matches[0], true);

        // Valida struttura
        if (isset($decoded['question'], $decoded['options'], $decoded['correct_option'])) {
            if (count($decoded['options']) === 4 &&
                is_numeric($decoded['correct_option']) &&
                $decoded['correct_option'] >= 0 &&
                $decoded['correct_option'] <= 3) {

                // Assicura che correct_option sia int
                $decoded['correct_option'] = (int)$decoded['correct_option'];

                // Tronca explanation se necessario
                if (isset($decoded['explanation']) && mb_strlen($decoded['explanation']) > 200) {
                    $decoded['explanation'] = mb_substr($decoded['explanation'], 0, 197) . '...';
                }

                // Tronca question se necessario (max 300 per Telegram)
                if (mb_strlen($decoded['question']) > 300) {
                    $decoded['question'] = mb_substr($decoded['question'], 0, 297) . '...';
                }

                // Tronca opzioni se necessario (max 100 per Telegram)
                foreach ($decoded['options'] as &$opt) {
                    if (mb_strlen($opt) > 100) {
                        $opt = mb_substr($opt, 0, 97) . '...';
                    }
                }

                return $decoded;
            }
        }
    }

    return null;
}

/**
 * Invia il quiz come poll Telegram
 */
function sendQuizPoll($chatId, $quizData) {
    return makeAPIRequest('sendPoll', [
        'chat_id' => $chatId,
        'question' => $quizData['question'],
        'options' => json_encode($quizData['options']),
        'type' => 'quiz',
        'correct_option_id' => $quizData['correct_option'],
        'explanation' => $quizData['explanation'] ?? '',
        'is_anonymous' => false,  // Per tracciare le risposte
        'allows_multiple_answers' => false
    ]);
}

/**
 * Gestisce poll_answer callback da Telegram
 */
function handlePollAnswer($pollAnswer) {
    global $db;

    $pollId = $pollAnswer['poll_id'];
    $userId = $pollAnswer['user']['id'];
    $userName = $pollAnswer['user']['first_name'] ?? 'Utente';
    $selectedOptions = $pollAnswer['option_ids'] ?? [];

    if (empty($selectedOptions)) {
        return; // Utente ha ritrattato il voto
    }

    $selectedOption = $selectedOptions[0];

    // Trova il quiz nel database
    $stmt = $db->prepare("SELECT id, correct_option FROM quiz_history WHERE poll_id = :poll_id");
    $stmt->bindValue(':poll_id', $pollId, SQLITE3_TEXT);
    $result = $stmt->execute();
    $quiz = $result->fetchArray(SQLITE3_ASSOC);

    if (!$quiz) {
        return; // Quiz non trovato
    }

    $isCorrect = ((int)$selectedOption === (int)$quiz['correct_option']) ? 1 : 0;

    // Inserisci o aggiorna risposta
    $stmt = $db->prepare("INSERT OR REPLACE INTO quiz_responses
        (quiz_id, poll_id, user_id, user_name, selected_option, is_correct, answered_at)
        VALUES (:quiz_id, :poll_id, :user_id, :user_name, :selected, :correct, :time)");
    $stmt->bindValue(':quiz_id', $quiz['id'], SQLITE3_INTEGER);
    $stmt->bindValue(':poll_id', $pollId, SQLITE3_TEXT);
    $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
    $stmt->bindValue(':user_name', $userName, SQLITE3_TEXT);
    $stmt->bindValue(':selected', $selectedOption, SQLITE3_INTEGER);
    $stmt->bindValue(':correct', $isCorrect, SQLITE3_INTEGER);
    $stmt->bindValue(':time', time(), SQLITE3_INTEGER);
    $stmt->execute();
}

/**
 * Classifica quiz
 */
function getQuizLeaderboard($chatId, $limit = 10) {
    global $db;

    $stmt = $db->prepare("
        SELECT
            qr.user_name,
            COUNT(*) as total_answers,
            SUM(qr.is_correct) as correct_answers,
            ROUND(100.0 * SUM(qr.is_correct) / COUNT(*), 1) as accuracy
        FROM quiz_responses qr
        JOIN quiz_history qh ON qr.quiz_id = qh.id
        WHERE qh.chat_id = :chat_id
        GROUP BY qr.user_id
        HAVING total_answers >= 3
        ORDER BY correct_answers DESC, accuracy DESC
        LIMIT :limit
    ");
    $stmt->bindValue(':chat_id', $chatId, SQLITE3_INTEGER);
    $stmt->bindValue(':limit', $limit, SQLITE3_INTEGER);
    $result = $stmt->execute();

    $leaderboard = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $leaderboard[] = $row;
    }

    if (empty($leaderboard)) {
        return "Nessuna statistica quiz disponibile. Servono almeno 3 risposte per entrare in classifica!";
    }

    $response = "Classifica Quiz:\n\n";
    $medals = ['1.', '2.', '3.'];

    foreach ($leaderboard as $i => $entry) {
        $medal = $medals[$i] ?? ($i + 1) . '.';
        $response .= sprintf(
            "%s %s - %d/%d corrette (%.1f%%)\n",
            $medal,
            $entry['user_name'],
            $entry['correct_answers'],
            $entry['total_answers'],
            $entry['accuracy']
        );
    }

    return $response;
}

/**
 * Lista tutti i topic disponibili
 */
function listQuizTopics() {
    global $db;

    $result = $db->query("SELECT topic, description FROM quiz_topics ORDER BY topic");

    $topics = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $topics[] = $row;
    }

    if (empty($topics)) {
        return "Nessun argomento quiz configurato.";
    }

    $total = count($topics);
    $header = "📚 Argomenti Quiz disponibili ({$total}):\n\n";
    $footer = "\nUsa /quiz [argomento] per un quiz specifico, o /quiz per uno casuale.\nPuoi anche specificare argomenti liberi!";
    $maxLen = 4000; // margine sotto il limite Telegram di 4096

    $messages = [];
    $current = $header;

    foreach ($topics as $t) {
        $line = "- " . ucfirst($t['topic']);
        if ($t['description']) {
            $line .= " (" . $t['description'] . ")";
        }
        $line .= "\n";

        if (mb_strlen($current) + mb_strlen($line) > $maxLen) {
            $messages[] = $current;
            $current = '';
        }
        $current .= $line;
    }
    $current .= $footer;
    $messages[] = $current;

    return $messages;
}

/**
 * Aggiunge un nuovo topic (admin only)
 */
function addQuizTopic($topic, $description = null) {
    global $db;

    $stmt = $db->prepare("INSERT OR IGNORE INTO quiz_topics (topic, description) VALUES (:topic, :desc)");
    $stmt->bindValue(':topic', strtolower(trim($topic)), SQLITE3_TEXT);
    $stmt->bindValue(':desc', $description, SQLITE3_TEXT);
    $stmt->execute();

    return $db->changes() > 0;
}

/**
 * Verifica se utente è admin
 */
function isQuizAdmin($chatId, $userId) {
    $chatMember = makeAPIRequest('getChatMember', [
        'chat_id' => $chatId,
        'user_id' => $userId
    ]);

    return isset($chatMember['result']['status']) &&
           in_array($chatMember['result']['status'], ['creator', 'administrator']);
}
?>
