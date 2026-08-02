<?php
///////////////////////////////////////////////////////////
///////////////// MODERAZIONE IMPRECAZIONI ////////////////
///////////////////////////////////////////////////////////

require_once __DIR__ . '/QBertClient.php';

// Qui restano solo i link da sfottere: match esatto su URL, non riguarda le persone.
//
// L'elenco di parolacce, i pattern per le bestemmie e la whitelist di parole lecite
// (addio, stadio, radio...) sono stati rimossi. Sbagliavano per costruzione — strpos()
// senza confini di parola faceva scattare "figa" dentro "figata" e "cazzo" dentro
// "cazzotto" — ma soprattutto servivano a rimproverare chi scriveva parolacce, e il
// bot non lo fa piu'.
$link_shaming = [
    'x' => [
        'https://x.com', 'https://www.x.com'
    ],
    'facebook' => [
        'https://facebook.com', 'https://www.facebook.com'
    ]
];

// Battute sui link. Le risposte per lieve/moderata/grave/bestemmia sono state
// tolte: erano rimproveri rivolti a una persona per come parla, e in un gruppo di
// amici non sono simpatici.
$responses = [
    'x' => [
        "{user}, per fortuna non ho uno stomaco perche' quel link mi farebbe vomitare",
        "Oh no! {user} ha postato un link da quella fogna che e' X!",
        "{user}, il link che hai postato puzza come una latrina di Calcutta in estate!"
    ],
    'facebook' => [
        "{user}, oh no, un altro link a quel covo di boomer",
        "Oh no! {user} ha postato un link da quella fabbrica di boomer che e' Facebook!",
        "{user}, il link che hai postato puzza di vecchio..."
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


/**
 * Silenzio a richiesta e battuta sui link.
 *
 * Non rimprovera piu' nessuno per come parla. Resta comunque disattivata
 * (message.php:157); se la si riaccende, l'unica cosa che puo' fare e' commentare
 * un link a X o Facebook.
 */
function handle_profanity($message) {
    global $link_shaming, $responses;

    $text = mb_strtolower($message['text']);
    $user_name = $message['from']['first_name'];

    if (time() < getSilenceUntil()) {
        return null;
    }

    if (strpos($text, 'bot stai zitto') !== false || strpos($text, 'bot taci') !== false || strpos($text, 'bot non rompere') !== false) {
        setSilenceUntil(time() + SILENCE_DURATION);
        return "Ok, entro in modalita' stealth per un po'. Ma vi tengo sempre d'occhio!";
    }

    foreach ($link_shaming as $category => $urls) {
        foreach ($urls as $url) {
            if (strpos($text, $url) !== false) {
                $pool = $responses[$category];
                return str_replace('{user}', $user_name, $pool[array_rand($pool)]);
            }
        }
    }

    return null;
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
