<?php
///////////////////////////////////////////////////////////
///////////////// MODERAZIONE IMPRECAZIONI ////////////////
///////////////////////////////////////////////////////////

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
function is_porto_al_root($text) {
    $text = mb_strtolower($text);
    
    $azioni = '(porta|porto|portare|porterei|porterò|porteremo|porteremmo|posso\s+portare|vorrei\s+portare|' .
              'portarlo|portarla|portarli|portarle|portarcelo|portarcela|portarceli|portarcele|' .
              'lascia|lascio|lasciare|lascerei|lascierò|lascerò|lasceremo|laseremmo|posso\s+lasciare|vorrei\s+lasciare|' .
              'lasciarlo|lasciarla|lasciarli|lasciarle|lasciarcelo|lasciarcela|lasciarceli|lasciarcele|' .
              'da|do|dare|darei|posso\s+dare|vorrei\s+dare|' .
              'darlo|darla|darli|darle|darcelo|darcela|darceli|darcele|' .
              'consegna|consegno|consegnare|consegnerei|posso\s+consegnare|vorrei\s+consegnare|' .
              'consegnarlo|consegnarla|consegnarli|consegnarle|consegnarcelo|consegnarcela|consegnarceli|consegnarcele|' .
              'arriva|arriver[àa]|far[àa]\s+arrivare|posso\s+far\s+arrivare|' .
              'farlo\s+arrivare|farla\s+arrivare|farli\s+arrivare|farle\s+arrivare|' .
              'contribui|contribuire|contribuirei|posso\s+contribuire|vorrei\s+contribuire)';
    
    $oggetti = '(qualcosa|roba|cose?|oggett[oi]|materiale?|attrezzatura?)?';
    
    $destinazioni = '(root|\/root|circolo|associazione|club|sede)';
    
    $preposizioni = '(al|nel|allo?|nella?|a|in|presso|verso|per\s+il?)?';
    
    $patterns = [
        "/\b$azioni\s*$oggetti\s*$preposizioni\s*$destinazioni\b/i",
        "/\b$oggetti\s*$azioni\s*$preposizioni\s*$destinazioni\b/i",
        "/\b(te|vi|voi)\s*$azioni\s*$oggetti\s*$preposizioni\s*$destinazioni?\b/i",
        "/\b(ho|avrei)\s*(intenzione|voglia|idea)\s*di\s*$azioni\s*$oggetti\s*$preposizioni\s*$destinazioni\b/i",
        "/\b(posso|potrei|si\s+pu[òo])\s*$azioni\s*$oggetti\s*$preposizioni\s*$destinazioni\b/i",
        "/\bc'[èe]\s*$oggetti\s*(da|che\s+devo)\s*$azioni\s*$preposizioni\s*$destinazioni\b/i",
        "/\bserve\s*$oggetti\s*$preposizioni\s*$destinazioni\b/i",
        "/\b$destinazioni\s*ha\s*bisogno\s*di\s*$oggetti\b/i",
        "/\b$azioni\s*$preposizioni\s*$destinazioni\b/i"  // Nuovo pattern per forme come "portarlo al root"
    ];
    
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $text)) {
            return true;
        }
    }
    return false;
}

function handle_porto_al_root($message) {
    $userName = $message['from']['first_name'] . ' ' . ($message['from']['last_name'] ?? '');
    $text = mb_strtolower($message['text']);
    
    // Estrai l'azione (porto/lascio) dal messaggio
    $action = preg_match('/\b(porto)\b/', $text) ? 'portare' : 'lasciare';
    
    // Rimuovi le parole chiave per isolare l'oggetto
    $item = preg_replace('/\b(lo|la|li|le)?\s*(porto|lascio)\s*(al|a)\s*(root|\/root|circolo)\b/', '', $text);
    $item = preg_replace('/\bte\s*(lo|la|li|le)\s*(porto|lascio)\s*(al|a)?\s*(root|\/root|circolo)?\b/', '', $item);
    $item = trim($item);

    #if (empty($item)) {
    #    return "$userName, grazie per offrirti di $action qualcosa al root! Cosa hai intenzione di $action?";
    #} else {
    #    // Qui puoi aggiungere la logica per gestire l'elemento portato/lasciato, ad esempio aggiungerlo a un database o a una lista
    #    return "$userName, grazie per offrirti di $action '$item' al root! È molto apprezzato.";
    #}
    
    return "Scusate se mi intrometto, se non ho capito male $userName porterebbe qualcosa al /Root. Vorrei ricordare a tutti che portare cose nella sede dell'associazione, senza uno specifico scopo, ma solo perchè c'è posto, ha portato ad accumulare rottami (e ora non c'è più il posto per fare altro), che poi qualcun'altro ha dovuto portare in discarica (o dovrà portare in discarica).";
}
?>
