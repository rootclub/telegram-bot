<?php
///////////////////////////////////////////////////////////
///////////////// MODERAZIONE IMPRECAZIONI ////////////////
///////////////////////////////////////////////////////////

require_once __DIR__ . '/QBertClient.php';

// Lista di parolacce e loro categorie (utilizzando placeholder per termini più espliciti)
$profanity_list = [
    'lieve' => [
        'cavolo', 'cacchio', 'accidenti', 'mannaggia', 'diamine', 'perbacco',
        'cribbio', 'perdinci', 'perdiana', 'accipicchia', 'caspita'
    ],
    'moderata' => [
        'cazzo', 'merda', 'figa', 'p***e', 'v*****a', 'b***a',
        'f*****o', 'inc***o', 'sc**o', 'put***a'
    ],
    'grave' => [
        'fanculo', 'vaffanculo', 'stronzo', 'testa di cazzo', 'figlio di puttana', 'rottinculo', 'frocio', 'finocchio', 'troia', 'puttana',
        'battona', 'succhiacazzi', 'pompinara', 'pezzo di merda', 'testa di minchia', 'faccia di cazzo', 'faccia di culo', 'aranzulla'
    ],
    'x' => [
        'https://x.com', 'https://www.x.com'
    ],
    'facebook' => [
        'https://facebook.com', 'https://www.facebook.com'
    ]
];

// Pattern per rilevare potenziali bestemmie
$blasphemy_patterns = [
    '/\bdio\s*[a-z]+/i',
    '/[a-z]+\s*dio\b/i',
    '/\bgesu\s*[a-z]+/i',
    '/[a-z]+\s*gesu\b/i',
    '/\bmadonna\s*[a-z]+/i',
    '/[a-z]+\s*madonna\b/i'
];

$lecit_words = ['addio', 'antidio', 'audio', 'avvedio', 'cardio', 'compendio', 'custodio', 'epicardio', 'fastidio', 'gaudio', 'eccidio', 'incudio', 'interdio', 'iridio', 'meridio', 'miocardio', 'oddio', 'odio',
	'palladio', 'pericardio', 'pendio', 'podio', 'presidio', 'radio', 'repudio', 'rhodio', 'rodio', 'ripudio', 'rubidio', 'scandio', 'siddio', 'stipendio' , 'studio', 'stadio', 'sussidio', 'tedio', 'vannadio', 'vanadio'];

// Pool di risposte per categoria
$responses = [
    'lieve' => [
        "Ehi, {user}! Anche 'per tutti i bit!' può essere efficace, sai?",
        "Wow {user}, stai calmo! Hai provato con 'santo transistor!'?",
        "{user}, sei così vicino all'essere un hacker con un vocabolario PG!"
    ],
    'moderata' => [
        "Attenzione {user}, il tuo firewall anti-parolacce sembra bucato!",
        "{user}, hai appena triggato l'IDS (Imprecation Detection System)!",
        "Codice errore 418: {user} è una teiera dal linguaggio colorito"
    ],
    'grave' => [
        "{user}, con quel linguaggio potresti far crashare un server!",
        "Allarme rosso! {user} ha appena eseguito un attacco DoS (Denial of Sobriety)!",
        "{user}, hai appena violato il protocollo di comunicazione del root!"
    ],
    'x' => [
        "{user}, per fortuna non ho uno stomaco perché quel link mi farebbe vomitare",
        "Oh no! {user} ha postato un link da quella fogna che è X!",
        "{user}, il link che hai postato puzza come una latrina di Calcutta in estate!"
    ],
    'facebook' => [
        "{user}, oh no, un altro link a quel covo di boomer",
        "Oh no! {user} ha postato un link da quella fabbrica di boomer che è Facebook!",
        "{user}, il link che hai postato puzza di vecchio..."
    ],
    'bestemmia' => [
        "{user}, per tutte le schede madri! Hai appena fatto un overflow nel registro delle bestemmie!",
        "KERNEL PANIC: {user} ha appena corrotto il file system divino!",
        "{user}, neanche un BIOS del '99 era così instabile! Prova a fare un upgrade al tuo vocabolario.",
        "Santo rootkit, {user}! Hai appena hackerato il firewall celeste!",
        "Attenzione {user}, stai per causare un fork bomb nell'aldilà!",
        "{user}, hai appena triggerato un interrupt non maskerable nell'etere cosmico!"
    ]
];

// Funzione per ottenere il valore di silence_until dal database
function getSilenceUntil() {
    global $db;
    $result = $db->query("SELECT silence_until FROM bot_silence WHERE id = 1");
    $row = $result->fetchArray(SQLITE3_ASSOC);
    return $row['silence_until'] ?? 0;
}

// Funzione per impostare il valore di silence_until nel database
function setSilenceUntil($timestamp) {
    global $db;
    $stmt = $db->prepare("UPDATE bot_silence SET silence_until = :timestamp WHERE id = 1");
    $stmt->bindValue(':timestamp', $timestamp, SQLITE3_INTEGER);
    $stmt->execute();
}


// Funzione per rilevare e gestire le imprecazioni
function handle_profanity($message) {
    global $profanity_list, $responses, $blasphemy_patterns, $lecit_words ;

    $text = mb_strtolower($message['text']);
    $user_id = $message['from']['id'];
    $user_name = $message['from']['first_name'];
    $chat_id = $message['chat']['id'];
    
    $silence_until = getSilenceUntil();
    
    if (time() < $silence_until) {
        return null;
    }

    if (strpos($text, 'bot stai zitto') !== false || strpos($text, 'bot taci') !== false || strpos($text, 'bot non rompere') !== false) {
        $new_silence_until = time() + SILENCE_DURATION;
        setSilenceUntil($new_silence_until);
        return "Ok, entro in modalità stealth per un po'. Ma vi tengo sempre d'occhio!";
    }

    $detected_category = null;
    foreach ($profanity_list as $category => $words) {
        foreach ($words as $word) {
            if (strpos($text, $word) !== false) {
                $detected_category = $category;
                break 2;
            }
        }
    }

	// Controllo per bestemmie
	/*
	if (!$detected_category) {
		foreach ($blasphemy_patterns as $pattern) {
		    if (preg_match($pattern, $text, $matches)) {
		        $potential_blasphemy = strtolower($matches[0]);
		        $is_lecit = false;
		        
		        foreach ($lecit_words as $lecit_word) {
		            if (strpos($potential_blasphemy, strtolower($lecit_word)) !== false) {
		                $is_lecit = true;
		                break;
		            }
		        }
		        
		        if (!$is_lecit) {
		            $detected_category = 'bestemmia';
		            break;
		        }
		    }
		}
	}
	*/

    if ($detected_category ) {
        updateProfanityStats($user_id, $user_name, $detected_category);
	if ($detected_category!="lieve" && $detected_category!="moderata"){
		$response = $responses[$detected_category][array_rand($responses[$detected_category])];
		$response = str_replace('{user}', $user_name, $response);
		//if ($detected_category === 'bestemmia') {
		//    $response .= "\nSuggerimento: prova con 'Per tutti i bit dannati!' o 'Sacro firewall!'";
		//}
		return $response;
	}
    }

    return null;
}

// Funzione per aggiornare le statistiche di un utente
function updateProfanityStats($user_id, $user_name, $category) {
    global $db;
    $stmt = $db->prepare("INSERT INTO profanity_stats 
        (user_id, user_name, $category, last_updated) 
        VALUES (:user_id, :user_name, 1, CURRENT_TIMESTAMP)
        ON CONFLICT(user_id) DO UPDATE SET
        $category = profanity_stats.$category + 1,
        user_name = :user_name,
        last_updated = CURRENT_TIMESTAMP
    ");
    $stmt->bindValue(':user_id', $user_id, SQLITE3_INTEGER);
    $stmt->bindValue(':user_name', $user_name, SQLITE3_TEXT);
    $stmt->execute();
}

// Funzione per ottenere le statistiche
function getProfanityStats() {
    global $db;
    $stats = [];
    $categories = ['lieve', 'moderata', 'grave', 'bestemmia'];
    
    foreach ($categories as $category) {
        $result = $db->query("SELECT user_name, $category as count 
                              FROM profanity_stats 
                              ORDER BY $category DESC 
                              LIMIT 1");
        $row = $result->fetchArray(SQLITE3_ASSOC);
        if ($row) {
            $stats[$category] = [
                'user' => $row['user_name'],
                'count' => $row['count']
            ];
        }
    }
    
    $response = "Statistiche delle imprecazioni:\n";
    foreach ($categories as $category) {
        if (isset($stats[$category])) {
            $response .= ucfirst($category) . ": " . $stats[$category]['user'] . 
                         " con " . $stats[$category]['count'] . " occorrenze\n";
        }
    }
    
    return $response;
}


///////////////////////////////////////////////////////////
//////////////////////// PORTO AL ROOT ////////////////////
///////////////////////////////////////////////////////////

/**
 * Filtro preliminare basato su parole chiave.
 * Triggera solo se ci sono verbi di "portare/lasciare" + destinazione "root/circolo".
 */
function is_porto_al_root($text) {
    $text = mb_strtolower($text);

    // Verbi che indicano l'azione di portare/lasciare qualcosa
    $verbi = '(port[oai]|porter[òoei]|portare|porterei|porteremo|' .
             'lasci[oai]|lascer[òoei]|lasciare|lascerei|lasceremo|' .
             'moll[oai]|moller[òoei]|mollare|mollerei|molleremo|' .
             'don[oai]|doner[òoei]|donare|donerei|doneremo|' .
             'sbarazz|liberarmi|liberarci|disfarmi|disfarci)';

    // Destinazioni che indicano il circolo
    $destinazioni = '(root|\/root|circolo|sede|club)';

    $patterns = [
        "/\b$verbi\b.*\b$destinazioni\b/i",
        "/\b$destinazioni\b.*\b$verbi\b/i"
    ];

    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $text)) {
            return true;
        }
    }
    return false;
}

/**
 * Valuta con modello leggero se il messaggio parla di hardware/oggetti fisici.
 * @return bool true se è hardware, false altrimenti
 */
function isHardwareOffer($text) {
    $prompt = <<<PROMPT
Analizza questo messaggio e rispondi SOLO con "SI" o "NO".

Rispondi "SI" SOLO se:
- Qualcuno vuole SERIAMENTE portare/lasciare/donare oggetti fisici REALI e IDENTIFICABILI (es: computer, monitor, stampante, cavi, router, mobili, attrezzi)
- L'oggetto deve essere chiaramente nominato e riconoscibile

Rispondi "NO" se:
- Parla di portare persone, amici, ospiti
- Parla di cibo o bevande
- L'oggetto non è chiaramente identificabile o ha un nome inventato/nonsense
- Il messaggio sembra una battuta, parodia, supercazzola, o test
- Contiene parole inventate o senza senso (es: "qualcosimetro", "antani", "tapioca")
- È una citazione o presa in giro di un altro messaggio
- C'è qualsiasi dubbio sulla serietà del messaggio

NEL DUBBIO, RISPONDI SEMPRE "NO".

Messaggio: "{$text}"

Risposta (solo SI o NO):
PROMPT;

    $result = callOllamaChatViaQBert(
        OLLAMA_MODEL_LIGHT,
        $prompt,
        ollamaOptions(OLLAMA_MODEL_LIGHT_GPU, ['temperature' => 0.1]),
        false,
        QBertClient::PRIORITY_NORMAL
    );

    if (!$result) {
        error_log("isHardwareOffer QBert error");
        return false;
    }

    $answer = strtoupper(trim($result['response'] ?? ''));

    $answer = stripThinkingTags($answer);
    $answer = strtoupper(trim($answer));

    error_log("isHardwareOffer: '$text' -> '$answer'");

    return (strpos($answer, 'SI') !== false || strpos($answer, 'SÌ') !== false);
}

/**
 * Genera un messaggio contestuale con il modello principale.
 */
function generateHardwareWarning($userName, $text) {
    $prompt = <<<PROMPT
Sei rootbot, il bot del circolo /root. Hai un carattere cinico e ironico come Bender di Futurama, ma sotto sotto ti stanno simpatici questi umani.

Un utente ({$userName}) ha scritto questo messaggio nel gruppo:
"{$text}"

L'utente sembra voler portare/lasciare/donare degli oggetti fisici al circolo.

Scrivi un messaggio che:
- Faccia capire gentilmente ma fermamente che il circolo ha GIÀ accumulato troppi oggetti/rottami nel corso degli anni
- Spieghi che portare cose "perché c'è posto" o "magari servono" ha riempito la sede di materiale che poi qualcuno deve smaltire (portare in discarica, pagare lo smaltimento, ecc.)
- Sia contestuale a quello che l'utente ha scritto
- Abbia un tono amichevole ma fermo, con un pizzico di ironia/sarcasmo
- Non superi le 3-4 frasi
- Non sia offensivo o maleducato

Rispondi SOLO con il messaggio, senza preamboli:
PROMPT;

    $result = callOllamaChatViaQBert(
        OLLAMA_MODEL,
        $prompt,
        ollamaOptions(OLLAMA_MODEL_GPU, ['temperature' => 0.8]),
        false,
        QBertClient::PRIORITY_NORMAL
    );

    if (!$result) {
        error_log("generateHardwareWarning QBert error");
        // Fallback a messaggio standard
        return "Ehi $userName, apprezzo il pensiero, ma il /root è già pieno di rottami che qualcuno prima o poi dovrà portare in discarica. Se non c'è uno scopo specifico, meglio evitare di accumulare altra roba!";
    }

    $answer = trim($result['response'] ?? '');

    $answer = stripThinkingTags($answer);
    $answer = trim($answer);

    error_log("generateHardwareWarning: generated message for '$text'");

    if (empty($answer)) {
        return "Ehi $userName, apprezzo il pensiero, ma il /root è già pieno di rottami che qualcuno prima o poi dovrà portare in discarica. Se non c'è uno scopo specifico, meglio evitare di accumulare altra roba!";
    }

    return $answer;
}

/**
 * Gestisce il messaggio "porto al root".
 * 1. Valuta con modello leggero se è hardware
 * 2. Se sì, genera messaggio con modello principale
 */
function handle_porto_al_root($message) {
    $userName = $message['from']['first_name'] . ' ' . ($message['from']['last_name'] ?? '');
    $text = $message['text'];

    // Fase 1: Valutazione con modello leggero
    if (!isHardwareOffer($text)) {
        error_log("handle_porto_al_root: non è hardware, skip");
        return null;
    }

    // Fase 2: Generazione messaggio con modello principale
    error_log("handle_porto_al_root: rilevato hardware, generando messaggio");
    return generateHardwareWarning($userName, $text);
}
?>
