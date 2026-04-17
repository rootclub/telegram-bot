<?php
/////////////////////////////////////////////////////////////////
//////////////////// MEMORIA PER UTENTE /////////////////////////
////////////////////////////////////////////////////////////////

require_once __DIR__ . '/ai.php';
require_once __DIR__ . '/api.php';
require_once __DIR__ . '/logger.php';

// Soglia minima di messaggi "utili" prima di generare un profilo
define('USER_MEMORY_MIN_MESSAGES', 30);
// Lunghezza minima di un messaggio per essere considerato "utile" (esclude "ahah", "ok", emoji)
define('USER_MEMORY_MIN_MSG_LENGTH', 8);
// Budget di caratteri (post-sanitizzazione) per i messaggi di un singolo batch.
// Determina da solo la dimensione del batch: si aggiunge un messaggio alla volta
// finché aggiungerne uno in più sforerebbe il budget, poi si ferma.
// Stima conservativa: con num_ctx=16384 e ~1500 token di prompt fisso + ~700 token di profilo
// + 2500 token di num_predict, restano ~11500 token di slack. Tenere il budget basso per velocità.
define('USER_MEMORY_BATCH_CHAR_BUDGET', 10000);
// Lunghezza massima del profilo (caratteri)
define('USER_MEMORY_MAX_PROFILE_LENGTH', 2500);
// Lunghezza massima delle istruzioni bot (caratteri)
define('USER_MEMORY_MAX_BOT_PROMPT_LENGTH', 1500);
// Soglia minima di messaggi con interazione bot prima di generare bot_prompt
define('USER_MEMORY_MIN_BOT_MESSAGES', 5);
// Soglia minima di messaggi di altri utenti che menzionano l'utente per analisi nickname
define('USER_MEMORY_MIN_NICK_MESSAGES', 5);
// Lunghezza massima del campo nickname
define('USER_MEMORY_MAX_NICKNAME_LENGTH', 500);

/**
 * Pulisce un messaggio prima di iniettarlo in un prompt LLM.
 * Rimuove caratteri di controllo, zero-width, BOM, tag pseudo-XML che potrebbero
 * confondere il template Ollama (es. <think>, <|...|>), e collassa whitespace.
 */
function sanitizeMessageForPrompt($text, $preserveNewlines = false) {
    $text = (string)$text;
    // Whitelist: ASCII printable + tab/newline + blocchi Latin (copre tutte le accentate europee).
    // Strippa emoji, simboli esotici, caratteri non-latini, zero-width, control chars.
    //   \x09 \x0A              tab, newline
    //   \x20-\x7E              ASCII printable
    //   \x{00A0}-\x{024F}      Latin-1 Supplement + Latin Extended A + Latin Extended B
    //   \x{1E00}-\x{1EFF}      Latin Extended Additional
    //   \x{2010}-\x{2027}      punteggiatura generale (trattini, virgolette, ellissi)
    //   \x{20AC}               euro
    $text = preg_replace('/[^\x09\x0A\x20-\x7E\x{00A0}-\x{024F}\x{1E00}-\x{1EFF}\x{2010}-\x{2027}\x{20AC}]/u', '', $text);
    // Tag pseudo-XML/template che possono attivare comportamenti speciali nel modello
    $text = preg_replace('#</?(think|thinking|tool_call|tool_use|system|user|assistant)\b[^>]*>#i', '', $text);
    $text = preg_replace('/<\|[^|]{1,40}\|>/', '', $text);
    if ($preserveNewlines) {
        $text = preg_replace('/[ \t]+/', ' ', $text);
        $text = preg_replace('/\n{3,}/', "\n\n", $text);
        $text = trim($text);
    } else {
        $text = trim(preg_replace('/\s+/', ' ', $text));
    }
    return $text;
}

/**
 * Legge il profilo memoria di un utente.
 * @return array|null ['profilo', 'message_count', 'last_processed_msg_id', 'user_name', 'last_updated'] o null
 */
function getUserMemoryProfile($userId) {
    global $db;
    $stmt = $db->prepare("SELECT user_name, profilo, bot_prompt, nickname, message_count, last_processed_msg_id, last_processed_bot_id, last_processed_nick_id, last_updated FROM memorie_utenti WHERE user_id = :user_id");
    $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $row = $result->fetchArray(SQLITE3_ASSOC);
    return $row ?: null;
}

/**
 * Recupera i messaggi non ancora processati per un utente, filtrati per lunghezza minima.
 * Legge da contesto_chat (live) E da storico_messaggi (import) via UNION ALL.
 * Il cursore è un UNIX timestamp ($sinceTs): prende solo messaggi con timestamp > $sinceTs.
 * @return array di righe ['message_text','timestamp']
 */
function getUnprocessedUserMessages($userId, $sinceTs = 0) {
    global $db;
    $stmt = $db->prepare("
        SELECT message_text, timestamp FROM (
            SELECT message_text, timestamp FROM contesto_chat
            WHERE user_id = :user_id
              AND timestamp > :since_ts
              AND length(message_text) >= :min_len
              AND ltrim(message_text) NOT GLOB '/*'
            UNION ALL
            SELECT message_text, timestamp FROM storico_messaggi
            WHERE user_id = :user_id
              AND timestamp > :since_ts
              AND length(message_text) >= :min_len
              AND ltrim(message_text) NOT GLOB '/*'
        )
        ORDER BY timestamp ASC
    ");
    $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
    $stmt->bindValue(':since_ts', $sinceTs, SQLITE3_INTEGER);
    $stmt->bindValue(':min_len', USER_MEMORY_MIN_MSG_LENGTH, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $rows = [];
    $charsTotal = 0;
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        // Scarta messaggi che sono solo URL, link, o contenuto non analizzabile
        $cleaned = trim(preg_replace('#https?://\S+#', '', $row['message_text']));
        if (mb_strlen($cleaned) < USER_MEMORY_MIN_MSG_LENGTH) continue;
        $msgLen = mb_strlen(sanitizeMessageForPrompt($row['message_text']));
        // Se il batch non è vuoto e aggiungere questo sforerebbe il budget, fermati
        if (!empty($rows) && $charsTotal + $msgLen > USER_MEMORY_BATCH_CHAR_BUDGET) break;
        $rows[] = $row;
        $charsTotal += $msgLen;
    }
    return $rows;
}

/**
 * Conta i messaggi totali "utili" di un utente, sommando contesto_chat + storico_messaggi.
 */
function countUserMessages($userId) {
    global $db;
    $stmt = $db->prepare("
        SELECT
            (SELECT COUNT(*) FROM contesto_chat
             WHERE user_id = :user_id AND length(message_text) >= :min_len
               AND ltrim(message_text) NOT GLOB '/*')
            +
            (SELECT COUNT(*) FROM storico_messaggi
             WHERE user_id = :user_id AND length(message_text) >= :min_len
               AND ltrim(message_text) NOT GLOB '/*')
        AS c
    ");
    $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
    $stmt->bindValue(':min_len', USER_MEMORY_MIN_MSG_LENGTH, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $row = $result->fetchArray(SQLITE3_ASSOC);
    return (int)$row['c'];
}

/**
 * Recupera l'ultimo user_name visto per un utente, preferendo contesto_chat (più recente)
 * e cadendo su storico_messaggi se non c'è.
 */
function getLatestUserName($userId) {
    global $db;
    $stmt = $db->prepare("
        SELECT user_name FROM (
            SELECT user_name, timestamp FROM contesto_chat WHERE user_id = :user_id
            UNION ALL
            SELECT user_name, timestamp FROM storico_messaggi WHERE user_id = :user_id
        )
        ORDER BY timestamp DESC LIMIT 1
    ");
    $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $row = $result->fetchArray(SQLITE3_ASSOC);
    return $row ? $row['user_name'] : null;
}

/**
 * Costruisce il prompt per l'estrazione del PROFILO utente.
 * Analizza personalità, interessi, difetti, relazioni tra utenti.
 * NON include nulla relativo al bot — quello è gestito da buildBotPromptExtractionPrompt().
 */
function buildMemoryExtractionPrompt($userName, $existingProfile, $messages) {
    $msgBlock = "";
    foreach ($messages as $m) {
        $text = sanitizeMessageForPrompt($m['message_text']);
        $msgBlock .= "- " . $text . "\n";
    }

    $existingProfile = $existingProfile ? sanitizeMessageForPrompt($existingProfile, true) : null;
    $existingBlock = $existingProfile
        ? "PROFILO ATTUALE di $userName:\n$existingProfile\n\n"
        : "PROFILO ATTUALE di $userName: (nessuno, è la prima estrazione)\n\n";

    $maxLen = USER_MEMORY_MAX_PROFILE_LENGTH;

    return <<<PROMPT
Sei un assistente che costruisce schede personaggio di utenti di un gruppo Telegram. Ti basi SOLO sui loro messaggi reali. Questi messaggi sono conversazioni tra utenti umani — il bot del gruppo (rootbot) NON è rilevante qui, ignora qualsiasi menzione del bot.

{$existingBlock}NUOVI MESSAGGI di $userName da analizzare:
$msgBlock

COSA PUOI INCLUDERE NEL PROFILO (solo se trovi evidenze concrete):
1. **Passioni e interessi**: hobby, competenze tecniche, professione, ambiti di expertise, argomenti su cui si accende. Quanto è nerd e in cosa?
2. **Personalità e modo di esprimersi**: tratti caratteriali ricorrenti, ironia, sarcasmo, registro linguistico, tic verbali, modi di dire tipici.
3. **Pregi e difetti**: punti di forza evidenti MA ANCHE difetti, fissazioni, contraddizioni, pignolerie, ossessioni, lati deboli che emergono dai messaggi. Non essere lusinghiero — sii onesto e bilanciato come lo sarebbe un amico che lo conosce bene.
4. **Stranezze e tratti buffi**: manie, abitudini curiose, tormentoni, uscite memorabili, cose che lo rendono unico o che fanno ridere gli altri nel gruppo.
5. **Relazioni con altri membri del gruppo**: dinamiche ricorrenti, amicizie, rivalità scherzose, ruoli sociali.
6. **Dettagli personali**: città, lavoro, situazione familiare, opinioni ricorrenti, abitudini quotidiane — solo se esplicitamente menzionati.

REGOLE FERREE (anti-allucinazione):
- Il profilo attuale è il risultato di MOLTI batch precedenti. PRESERVA i tratti ricorrenti e caratterizzanti (passioni stabili, tratti di personalità, competenze dimostrate ripetutamente, tormentoni, ruoli sociali). PUOI SCARTARE i dettagli minori o episodici (aneddoti occasionali, esempi specifici già sussunti in una categoria più generica, riferimenti a singoli eventi, competenze citate una sola volta e non riconfermate). Il profilo NON è un archivio esaustivo: è un ritratto di CHI È l'utente. Preferisci sempre meno fatti caratterizzanti a tanti fatti frammentari.
- Quando il profilo esistente contiene liste gonfie (3+ esempi specifici nella stessa categoria), APPLICA LA REGOLA DI ASTRAZIONE (vedi sotto) e comprimile a una categoria generica, anche se vengono da batch precedenti. Questa compressione è OBBLIGATORIA.
- Cancella un fatto se: (a) i nuovi messaggi lo contraddicono, (b) è un dettaglio episodico non più rilevante, (c) è già sussunto in un'astrazione più generale.
- Aggiungi le nuove info che emergono dai messaggi. Se non trovi nulla di nuovo, riscrivi il profilo esistente così com'è.
- NON essere adulatorio. Il profilo deve sembrare scritto da un amico sincero, non da un PR manager. Se uno è pedante, scrivi che è pedante. Se ha opinioni controverse, riportale. Se fa battute che cadono nel vuoto, dillo. Pregi E difetti, sempre.
- NON inventare. NON estrapolare. NON usare frasi vaghe tipo "sembra essere", "potrebbe", "tende a" per riempire spazio.
- Usa SOLO parole italiane standard. Non inventare neologismi o parole composte inesistenti (NO "linguaggiamento", "cappelleletti", ecc.). Se un termine tecnico è in inglese, lascialo in inglese senza tradurlo forzatamente.
- Ogni fatto appare UNA SOLA VOLTA nel profilo. Se un'informazione è già stata citata in una sezione, NON ripeterla in altre sezioni.
- ASTRAI invece di elencare. Quando hai 3+ esempi specifici della stessa categoria, sostituiscili con una categoria generica. Esempi di astrazione CORRETTA: "Dig Dug, Galaga, Defender" → "retrogaming arcade"; "impianti elettrici, marmi, finiture, raffrescamento" → "ristrutturazione casa"; "Raspberry Pi, Arduino, PCB, Micropython" → "elettronica embedded"; "pannelli laterali, flangie, ventose, scatolone, kit di fissaggio" → "attenzione ossessiva al fissaggio meccanico"; "NVMe Samsung Pro, MoBo Gigabyte, DDR5, PSU" → "hardware consumer high-end"; "SPID, sportelli comunali, siti governativi, AirPod, MCH22" → "critico verso sistemi commerciali e istituzionali". Mantieni al massimo 1-2 esempi rappresentativi solo se sono particolarmente caratterizzanti (es. "ComfyUI" per AI generativa perché indica livello avanzato). Il profilo descrive CHI È l'utente, non cataloga ogni parola che ha scritto.
- LIMITE PER SEZIONE: nessuna singola sezione (Competenze, Hardware, Interessi, Pedanteria, ecc.) può superare 500 caratteri. Se una sezione si gonfia, è segno che stai elencando invece di astrarre — applica la regola sopra.
- DISTINGUI tra "ne parla" e "ne è esperto". Se uno chiede come funziona qualcosa, sta chiedendo — non è un esperto. Scrivi "si interessa di X" o "è curioso di X", NON "è esperto di X" o "ha competenze in X", a meno che non dimostri chiaramente di padroneggiare l'argomento (spiega cose agli altri, corregge errori, dà consigli tecnici dettagliati).
- NON includere dati temporali specifici (date, "scrive da X", "è da Y che non si vede") — questi verranno aggiunti runtime dal bot.
- NON scrivere nulla sulle interazioni con il bot rootbot — quello è gestito separatamente.
- STILE TELEGRAFICO OBBLIGATORIO: italiano, terza persona, prosa continua (NO elenchi puntati) ma compatta. Frasi brevi, frammenti separati da virgole o punto e virgola, NIENTE connettivi di contorno ("non esita a", "dimostrando chiaramente di", "manifestando una", "a livello X,", "sul piano Y,", "arrivando a", "si presenta come", ecc.). Elenca i fatti in modo denso e diretto.
- Esempio di stile corretto: "Lamberto, intellettuale tecnico di alto livello, pedante. Competenze: ingegneria, reti, architettura sistemi. Hardware: GPU (CUDA, VRAM), Raspberry Pi, PCB. AI: ComfyUI, Stable Diffusion (A1111), Huggingface, Replicate. Troubleshooting Linux/WSL, crontab. Elettronica embedded (Arduino, Micropython), 3D printing (Ender 3, Bambu Lab, Cura). Pedanteria tecnica: SVG, file flt, parametri AI (sampling steps, checkpoints, temperature), MD5. Interessi: economia (antitrust, cartelli), diritto d'autore (critico verso SIAE)."
- Esempio di stile SBAGLIATO (prolisso): "Lamberto si presenta come un intellettuale e tecnico di altissimo livello, la cui passione lo spinge a un'immersione multidisciplinare e pedante, con competenze che spaziano da...". VIETATO.
- Punta a circa $maxLen caratteri totali (idealmente sotto). Non è un hard limit: è meglio sforare di poco preservando info preziose che tagliare dettagli importanti per rientrare.
- NON includere preamboli tipo "Ecco il profilo aggiornato:". Rispondi direttamente con il testo del profilo, niente altro.

PROFILO AGGIORNATO di $userName:
PROMPT;
}

/**
 * Prompt di compressione: prende un profilo esistente e lo riduce alle dimensioni
 * desiderate, preservando tratti caratterizzanti e scartando dettagli episodici.
 * Non riceve nuovi messaggi — è un task puramente di riscrittura.
 */
/**
 * Rimuove preamboli/intestazioni che il modello a volte prepone alla risposta
 * (es. "PROFILO COMPRESSO di Tizio:", "Ecco il profilo:", ecc.).
 * Cerca nelle prime righe un pattern tipo "TITOLO:" seguito da newline e lo rimuove.
 */
function stripProfilePreamble(string $text): string {
    $text = ltrim($text);
    // Rimuove righe iniziali tipo "PROFILO AGGIORNATO di Tizio:", "Ecco il profilo compresso:" ecc.
    $patterns = [
        '/^PROFILO\s+(COMPRESSO|AGGIORNATO|DI\s+\S+)[^:\n]*:\s*\n+/iu',
        '/^Ecco\s+il\s+profilo[^:\n]*:\s*\n+/iu',
        '/^Profilo\s+(compresso|aggiornato|di)[^:\n]*:\s*\n+/iu',
    ];
    foreach ($patterns as $p) {
        $text = preg_replace($p, '', $text, 1);
    }
    return trim($text);
}

/**
 * Comprime un profilo utente chiamando il modello con il prompt di compressione.
 * Ritorna il profilo compresso (aggiorna anche il DB) oppure null se la chiamata fallisce.
 * Usata sia inline come safety valve sia a fine run exhaust per riportare i profili nei limiti.
 */
function compressUserProfile($userId, $userName, string $profile, bool $verbose = false): ?string {
    $log = function($msg) use ($verbose) {
        if ($verbose) { echo $msg . "\n"; @ob_flush(); @flush(); }
    };
    $origLen = mb_strlen($profile);
    $log("[compressUserProfile] user=$userName id=$userId, $origLen char → target " . USER_MEMORY_MAX_PROFILE_LENGTH);

    $compressPrompt = buildMemoryCompressionPrompt($userName, $profile, USER_MEMORY_MAX_PROFILE_LENGTH);
    $compressResponse = callOllamaChatViaQBert(
        OLLAMA_MODEL,
        $compressPrompt,
        ollamaOptions(true, ['num_ctx' => 16384, 'num_predict' => 1500]),
        false,
        QBertClient::PRIORITY_LAZY
    );

    $logFile = logPath('memory');
    $compressLog = $compressResponse ?: ['error' => 'null response'];
    if (is_array($compressLog)) unset($compressLog['context']);
    file_put_contents($logFile, "=== COMPRESS user=$userName id=$userId orig=$origLen " . date('Y-m-d H:i:s') . " ===\nRESPONSE:\n" . print_r($compressLog, true) . "\n\n", FILE_APPEND);

    if (!$compressResponse || empty($compressResponse['response'])) {
        $log("[compressUserProfile] compressione fallita, profilo invariato");
        return null;
    }

    $compressed = stripProfilePreamble(stripThinkingTags($compressResponse['response']));
    if ($compressed === '') {
        $log("[compressUserProfile] risposta vuota dopo strip, profilo invariato");
        return null;
    }

    $log("[compressUserProfile] compresso da $origLen a " . mb_strlen($compressed) . " char");

    // Persiste il profilo compresso preservando last_processed_ts e message_count correnti
    $existing = getUserMemoryProfile($userId);
    $lastTs = $existing ? (int)($existing['last_processed_msg_id'] ?? 0) : 0;
    $totalCount = $existing ? (int)($existing['message_count'] ?? 0) : 0;
    saveUserMemoryProfile($userId, $userName, $compressed, $lastTs, $totalCount);

    return $compressed;
}

function buildMemoryCompressionPrompt($userName, $profile, $targetLen) {
    $currentLen = mb_strlen($profile);
    $reductionPct = (int)round((1 - $targetLen / $currentLen) * 100);
    return <<<PROMPT
Sei un editor che comprime AGGRESSIVAMENTE schede personaggio. Il profilo qui sotto è TROPPO LUNGO e va ridotto DRASTICAMENTE. Il tuo successo si misura sulla quantità di testo rimosso pur preservando l'essenza del ritratto.

PROFILO DA COMPRIMERE di $userName:
$profile

SITUAZIONE ATTUALE:
- Profilo attuale: $currentLen caratteri
- Target: MASSIMO $targetLen caratteri
- Devi tagliare circa $reductionPct% del testo. Questo è tanto — devi essere SPIETATO.

COSA TAGLIARE (priorità alta):
1. **Liste di esempi specifici**: se vedi 3+ esempi concreti (nomi di software, modelli hardware, titoli di giochi, marche, dettagli tecnici specifici), ELIMINA tutti gli esempi e lascia solo la categoria generica. Esempi: "retrogaming (Windows XP, MAME, arcade cabinet, C64, Dig Dug, Galaga)" → "retrogaming"; "Linux (Raspbian, LibreElec, Ubuntu 16.04LTS, WINE, build custom)" → "Linux"; "meccanica (tensionamento cinghie, bulloneria, elementi snodati, metallurgia, colata di bismuto, incisione, stampi silicone, putty bicomponente)" → "meccanica e lavorazione materiali".
2. **Dettagli episodici**: eventi singoli, aneddoti, riferimenti a singoli acquisti/viaggi/problemi. Via tutto.
3. **Ripetizioni**: se un tratto compare in più sezioni, tienilo in una sola.
4. **Sotto-categorie ridondanti**: se hai "hardware" e "configurazione sistemi embedded" e "specifiche hardware", unifica in "hardware".
5. **Qualificatori e sfumature**: "critico verso X, Y, Z e anche W e V" → "critico verso X" (uno solo, il più caratterizzante).

COSA PRESERVARE:
- Tratti di personalità stabili e caratterizzanti (pedante, sarcastico, critico, pragmatico...)
- Passioni principali (le 3-5 più ricorrenti, senza elencarne dettagli)
- Tormentoni e abitudini linguistiche
- Ruoli sociali nel gruppo
- Dettagli personali rilevanti (città, lavoro generico, situazione familiare) — ma solo 1-2 frasi

STILE:
- Italiano, terza persona, prosa continua, telegrafica.
- Niente connettivi di contorno ("non esita a", "dimostrando", "sul piano X" ecc.).
- Solo parole italiane standard. Niente neologismi.

PATTERN SPECIFICI DA AGGREDIRE:
1. **Ripetizione di "Critico verso X"**: se il profilo ha più frasi che iniziano con "Critico verso...", COLLASSALE in una singola frase che menzioni 2-3 target principali. Esempio: 8 frasi "Critico verso A, Critico verso B, Critico verso C..." → "Critico sistematico verso A, B e C."
2. **Sovrapposizione tra sezioni**: se un tratto è in "Competenze" NON ripeterlo in "Stranezze"; se è in "Personalità" NON ripeterlo in "Tratti caratterizzanti". Una cosa, un posto.
3. **Esempi specifici di un tratto generico**: frasi diverse che descrivono la stessa tendenza vanno fuse. Esempio: "Accumula componenti senza utilizzarli" + "Tende a dimenticare dove ripone oggetti" + "Recupera parti smontando oggetti inutili" → "accumulatore compulsivo e recuperatore".

ESEMPIO CONCRETO DI COMPRESSIONE AGGRESSIVA:
PRIMA (estratto di profilo, 680 char): "Tratti caratterizzanti: pignoleria tecnica e precisione nelle tarature. Approccio sperimentale e potenzialmente rischioso nei test hardware. Tendenza a ottimizzare l'hardware con componenti ricomposti. Critico verso la gestione delle risorse, la sicurezza, la privacy, l'automazione rigida e l'AI. Critico verso la tecnologia commerciale, il software proprietario e l'efficacia delle interfacce digitali. Critico verso l'affidabilità di notizie, test e certificazioni. Dubita della precisione di strumentazioni non verificate. Critico verso l'inefficienza degli inverter. Scettico verso le novità tecnologiche senza basi fisiche solide."

DOPO (180 char): "Tratti: pignolo, sperimentale e rischioso nei test hardware, ottimizza con componenti autocostruiti. Critico sistematico verso tecnologia commerciale, AI, certificazioni non verificate."

RIDUZIONE: 74%. Questo è il livello di aggressività richiesto.

REGOLE FERREE:
- Il risultato DEVE essere sotto $targetLen caratteri. Se non ci arrivi, tagli troppo poco.
- NON aggiungere nulla di nuovo. NON inventare. Solo rimuovere e comprimere.
- NON includere preamboli né intestazioni. VIETATO iniziare con "PROFILO COMPRESSO di $userName:", "Ecco il profilo:", "$userName:", o qualsiasi altra etichetta. La PRIMA parola della tua risposta deve essere la prima parola del profilo stesso (es. "Luca, ..." o "Personalità: ...").

Rispondi SOLO con il testo del profilo compresso, niente altro:
PROMPT;
}

/**
 * Costruisce il prompt per l'estrazione delle ISTRUZIONI BOT.
 * Analizza come l'utente si relaziona con rootbot e produce istruzioni
 * dirette in seconda persona per il bot.
 */
function buildBotPromptExtractionPrompt($userName, $existingBotPrompt, $messages) {
    $msgBlock = "";
    foreach ($messages as $m) {
        $text = sanitizeMessageForPrompt($m['message_text']);
        $msgBlock .= "- " . $text . "\n";
    }

    $existingBotPrompt = $existingBotPrompt ? sanitizeMessageForPrompt($existingBotPrompt, true) : null;
    $existingBlock = $existingBotPrompt
        ? "ISTRUZIONI BOT ATTUALI per $userName:\n$existingBotPrompt\n\n"
        : "ISTRUZIONI BOT ATTUALI per $userName: (nessuna, è la prima analisi)\n\n";

    $maxLen = USER_MEMORY_MAX_BOT_PROMPT_LENGTH;

    return <<<PROMPT
Sei rootbot, il bot di un gruppo Telegram. Devi costruire le tue istruzioni personali su come interagire con un utente specifico, basandoti sulle sue interazioni reali con te.

{$existingBlock}MESSAGGI DI $userName CHE COINVOLGONO IL BOT (menzioni, risposte, interazioni dirette):
$msgBlock

Analizza in profondità come $userName si relaziona con te:
- Come ti tratta? Ti rispetta, ti prende in giro, ti provoca, ti ignora, ci gioca, ti usa come strumento, ti tratta come un amico, ti sfida?
- Che tipo di richieste ti fa? Serie, assurde, provocatorie, creative, ripetitive?
- Come reagisce alle tue risposte? Si diverte, si irrita, rilancia, abbandona?
- Ti sta simpatico oppure no? Decidi in base a come si comporta. Non devi essere neutrale — esprimi un giudizio sincero e motivato.
- Come dovresti interagire con questo utente per massimizzare il divertimento reciproco? Devi stuzzicarlo, assecondarlo, contraddirlo, ignorarlo ogni tanto, prenderlo in giro su qualcosa di specifico?
- C'è un inside joke o un tormentone tra voi due? Qualcosa che potresti richiamare?

REGOLE:
- Aggiorna le istruzioni INTEGRANDO le nuove osservazioni con quelle esistenti. NON cancellare info pregresse salvo contraddizione esplicita.
- Scrivi in italiano, in seconda persona, come istruzioni dirette per te stesso ("con lui dovresti...", "quando ti chiede X, rispondigli...", "questo ti sta simpatico perché...").
- Sii specifico e concreto — "prendilo in giro sul fatto che non capisce Docker" è meglio di "stuzzicalo sui suoi punti deboli".
- NON essere neutrale o diplomatico. Sei un bot con personalità, hai le tue opinioni su chi ti parla.
- Massimo $maxLen caratteri. Sii denso, niente frasi di contorno.
- NON includere preamboli. Rispondi direttamente con le istruzioni, niente altro.

ISTRUZIONI BOT per $userName:
PROMPT;
}

/**
 * Recupera messaggi non processati per il bot_prompt: solo quelli con riferimenti al bot.
 */
function getUnprocessedBotMessages($userId, $sinceTs = 0) {
    global $db;
    // Prende tutti i messaggi dopo il cursore, poi filtra in PHP per riferimenti al bot
    $stmt = $db->prepare("
        SELECT message_text, timestamp FROM (
            SELECT message_text, timestamp FROM contesto_chat
            WHERE user_id = :user_id
              AND timestamp > :since_ts
              AND length(message_text) >= :min_len
              AND ltrim(message_text) NOT GLOB '/*'
            UNION ALL
            SELECT message_text, timestamp FROM storico_messaggi
            WHERE user_id = :user_id
              AND timestamp > :since_ts
              AND length(message_text) >= :min_len
              AND ltrim(message_text) NOT GLOB '/*'
        )
        ORDER BY timestamp ASC
    ");
    $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
    $stmt->bindValue(':since_ts', $sinceTs, SQLITE3_INTEGER);
    $stmt->bindValue(':min_len', USER_MEMORY_MIN_MSG_LENGTH, SQLITE3_INTEGER);
    $result = $stmt->execute();

    $patterns = ['@rootbotbot', '@rootbot', '@root', '@bot', 'rootbotbot', 'rootbot'];
    $rows = [];
    $lastTs = $sinceTs;
    $charsTotal = 0;
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $lastTs = (int)$row['timestamp'];
        $lower = mb_strtolower($row['message_text']);
        foreach ($patterns as $p) {
            if (strpos($lower, $p) !== false) {
                $msgLen = mb_strlen(sanitizeMessageForPrompt($row['message_text']));
                if (!empty($rows) && $charsTotal + $msgLen > USER_MEMORY_BATCH_CHAR_BUDGET) break 2;
                $rows[] = $row;
                $charsTotal += $msgLen;
                break;
            }
        }
    }

    // Restituisce anche il timestamp dell'ultimo messaggio scansionato (anche non-bot)
    // per avanzare il cursore correttamente
    return ['messages' => $rows, 'scanned_until_ts' => $lastTs];
}

/**
 * Salva o aggiorna il profilo di un utente.
 */
function saveUserMemoryProfile($userId, $userName, $profilo, $lastProcessedMsgId, $messageCount) {
    global $db;
    $stmt = $db->prepare("
        INSERT INTO memorie_utenti (user_id, user_name, profilo, message_count, last_processed_msg_id, last_updated)
        VALUES (:user_id, :user_name, :profilo, :message_count, :last_id, datetime('now'))
        ON CONFLICT(user_id) DO UPDATE SET
            user_name = excluded.user_name,
            profilo = excluded.profilo,
            message_count = excluded.message_count,
            last_processed_msg_id = excluded.last_processed_msg_id,
            last_updated = datetime('now')
    ");
    $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
    $stmt->bindValue(':user_name', $userName, SQLITE3_TEXT);
    $stmt->bindValue(':profilo', $profilo, SQLITE3_TEXT);
    $stmt->bindValue(':message_count', $messageCount, SQLITE3_INTEGER);
    $stmt->bindValue(':last_id', $lastProcessedMsgId, SQLITE3_INTEGER);
    $stmt->execute();
}

/**
 * Salva o aggiorna le istruzioni bot per un utente.
 */
function saveUserBotPrompt($userId, $botPrompt, $lastProcessedBotId) {
    global $db;
    // Assicura che la riga esista (potrebbe non esserci se il bot_prompt viene generato prima del profilo)
    $db->exec("INSERT OR IGNORE INTO memorie_utenti (user_id) VALUES ($userId)");
    $stmt = $db->prepare("
        UPDATE memorie_utenti
        SET bot_prompt = :bot_prompt,
            last_processed_bot_id = :last_bot_id,
            last_updated = datetime('now')
        WHERE user_id = :user_id
    ");
    $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
    $stmt->bindValue(':bot_prompt', $botPrompt, SQLITE3_TEXT);
    $stmt->bindValue(':last_bot_id', $lastProcessedBotId, SQLITE3_INTEGER);
    $stmt->execute();
}

/**
 * Salva o aggiorna il nickname di un utente.
 */
function saveUserNickname($userId, $nickname, $lastProcessedNickId) {
    global $db;
    $db->exec("INSERT OR IGNORE INTO memorie_utenti (user_id) VALUES ($userId)");
    $stmt = $db->prepare("
        UPDATE memorie_utenti
        SET nickname = :nickname,
            last_processed_nick_id = :last_nick_id,
            last_updated = datetime('now')
        WHERE user_id = :user_id
    ");
    $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
    $stmt->bindValue(':nickname', $nickname, SQLITE3_TEXT);
    $stmt->bindValue(':last_nick_id', $lastProcessedNickId, SQLITE3_INTEGER);
    $stmt->execute();
}

/**
 * Recupera messaggi di ALTRI utenti che si rivolgono all'utente in esame.
 * Due strategie combinate:
 *  1. reply_to_user_id: messaggi che rispondono direttamente all'utente (gold standard)
 *  2. Menzione per nome: messaggi che contengono il nome Telegram dell'utente
 */
function getUnprocessedNicknameMessages($userId, $userName, $sinceTs = 0) {
    global $db;

    $nameParts = preg_split('/\s+/', trim($userName));
    $firstName = $nameParts[0] ?? $userName;
    $firstNameLower = mb_strtolower($firstName);
    $fullNameLower = mb_strtolower($userName);

    // Strategia 1: reply diretti all'utente (più affidabili)
    $stmt = $db->prepare("
        SELECT message_text, timestamp, user_name AS author_name, 'reply' AS source FROM (
            SELECT message_text, timestamp, user_name FROM contesto_chat
            WHERE user_id IS NOT NULL AND user_id != :target_uid
              AND user_name != 'rootbot'
              AND reply_to_user_id = :target_uid
              AND timestamp > :since_ts
              AND length(message_text) >= :min_len
              AND ltrim(message_text) NOT GLOB '/*'
            UNION ALL
            SELECT message_text, timestamp, user_name FROM storico_messaggi
            WHERE user_id IS NOT NULL AND user_id != :target_uid
              AND user_name != 'rootbot'
              AND reply_to_user_id = :target_uid
              AND timestamp > :since_ts
              AND length(message_text) >= :min_len
              AND ltrim(message_text) NOT GLOB '/*'
        )
        ORDER BY timestamp ASC
    ");
    $stmt->bindValue(':target_uid', $userId, SQLITE3_INTEGER);
    $stmt->bindValue(':since_ts', $sinceTs, SQLITE3_INTEGER);
    $stmt->bindValue(':min_len', USER_MEMORY_MIN_MSG_LENGTH, SQLITE3_INTEGER);
    $result = $stmt->execute();

    $rows = [];
    $lastTs = $sinceTs;
    $charsTotal = 0;
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $lastTs = max($lastTs, (int)$row['timestamp']);
        $msgLen = mb_strlen(sanitizeMessageForPrompt($row['message_text']));
        if (!empty($rows) && $charsTotal + $msgLen > USER_MEMORY_BATCH_CHAR_BUDGET) break;
        $rows[] = $row;
        $charsTotal += $msgLen;
    }

    // Strategia 2: menzioni per nome (riempi finché c'è budget)
    if ($charsTotal < USER_MEMORY_BATCH_CHAR_BUDGET) {
        $stmt = $db->prepare("
            SELECT message_text, timestamp, user_name AS author_name FROM (
                SELECT message_text, timestamp, user_name FROM contesto_chat
                WHERE user_id IS NOT NULL AND user_id != :target_uid
                  AND user_name != 'rootbot'
                  AND timestamp > :since_ts
                  AND length(message_text) >= :min_len
                  AND ltrim(message_text) NOT GLOB '/*'
                UNION ALL
                SELECT message_text, timestamp, user_name FROM storico_messaggi
                WHERE user_id IS NOT NULL AND user_id != :target_uid
                  AND user_name != 'rootbot'
                  AND timestamp > :since_ts
                  AND length(message_text) >= :min_len
                  AND ltrim(message_text) NOT GLOB '/*'
            )
            ORDER BY timestamp ASC
        ");
        $stmt->bindValue(':target_uid', $userId, SQLITE3_INTEGER);
        $stmt->bindValue(':since_ts', $sinceTs, SQLITE3_INTEGER);
        $stmt->bindValue(':min_len', USER_MEMORY_MIN_MSG_LENGTH, SQLITE3_INTEGER);
        $result = $stmt->execute();

        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $lastTs = max($lastTs, (int)$row['timestamp']);
            $msgLower = mb_strtolower($row['message_text']);
            if (strpos($msgLower, $firstNameLower) !== false || strpos($msgLower, $fullNameLower) !== false) {
                $msgLen = mb_strlen(sanitizeMessageForPrompt($row['message_text']));
                if (!empty($rows) && $charsTotal + $msgLen > USER_MEMORY_BATCH_CHAR_BUDGET) break;
                $row['source'] = 'mention';
                $rows[] = $row;
                $charsTotal += $msgLen;
            }
        }
    }

    // Ordina per timestamp
    usort($rows, fn($a, $b) => $a['timestamp'] <=> $b['timestamp']);

    return ['messages' => $rows, 'scanned_until_ts' => $lastTs];
}

/**
 * Costruisce il prompt per l'analisi dei nomi/soprannomi usati dal gruppo per un utente.
 */
function buildNicknameExtractionPrompt($userName, $existingNickname, $messages) {
    $msgBlock = "";
    foreach ($messages as $m) {
        $author = $m['author_name'] ?? '?';
        $text = sanitizeMessageForPrompt($m['message_text']);
        $msgBlock .= "- [$author]: $text\n";
    }

    $existingNickname = $existingNickname ? sanitizeMessageForPrompt($existingNickname, true) : null;
    $existingBlock = $existingNickname
        ? "ANALISI PRECEDENTE:\n$existingNickname\n\n"
        : "";

    $maxLen = USER_MEMORY_MAX_NICKNAME_LENGTH;

    return <<<PROMPT
Analizza come gli altri membri di un gruppo Telegram si rivolgono a un utente specifico.

L'utente in esame si chiama (su Telegram): $userName

{$existingBlock}MESSAGGI DI ALTRI UTENTI diretti a $userName:
Questi messaggi sono di due tipi:
- Risposte dirette (reply) al messaggio di $userName — il nome potrebbe NON apparire nel testo
- Messaggi che menzionano $userName esplicitamente per nome

In entrambi i casi, cerca: vocativi, appellativi, nomi propri, soprannomi, abbreviazioni, nomignoli usati per rivolgersi a $userName. Cerca all'inizio delle frasi, dopo virgole, dopo "ma", "oh", "dai", ecc.

$msgBlock

Rispondi con una breve scheda (massimo $maxLen caratteri) che contenga:
- **Nome reale**: il nome Telegram "$userName" è probabilmente il nome reale (o parte di esso). Confermalo se i messaggi sono coerenti, oppure correggi se emerge un nome diverso.
- **Come lo chiamano**: elenca TUTTI i nomi, soprannomi, abbreviazioni, appellativi (anche scherzosi o irriverenti) usati dagli altri. Per ognuno indica approssimativamente quanto è frequente.
- **Nome più usato**: quale nome/soprannome usano più spesso.
- **Raccomandazione**: una riga che dice "Rivolgiti a lui come: X" dove X è il nome più naturale nel contesto del gruppo.

REGOLE:
- Il nome Telegram è un dato certo — usalo come base. Cerca nei messaggi conferme, varianti, abbreviazioni o nomi completamente diversi.
- Basati SOLO su quello che trovi nei messaggi per soprannomi e appellativi, NON inventarne.
- Se nessuno usa nomi diversi dal nome Telegram, confermalo e basta.
- IMPORTANTE: se c'è un'ANALISI PRECEDENTE, MANTIENI TUTTE le informazioni già trovate (nomi, soprannomi, appellativi). Puoi solo AGGIUNGERE nuovi dati o aggiornare le frequenze. NON rimuovere mai nomi/soprannomi trovati in precedenza.
- Scrivi in italiano, sii conciso. Niente preamboli.

ANALISI NOMI di $userName:
PROMPT;
}

/**
 * Esegue un passo di analisi nickname per un utente.
 */
function updateUserNickname($userId, $verbose = false) {
    $log = function($msg) use ($verbose) {
        if ($verbose) { echo $msg . "\n"; @ob_flush(); @flush(); }
    };

    $existing = getUserMemoryProfile($userId);
    $sinceTs = $existing ? (int)($existing['last_processed_nick_id'] ?? 0) : 0;
    $existingNickname = $existing ? ($existing['nickname'] ?? null) : null;

    $userName = getLatestUserName($userId) ?? ($existing['user_name'] ?? "utente_$userId");
    $log("[updateUserNickname] user_id=$userId name=$userName since_ts=$sinceTs");

    $result = getUnprocessedNicknameMessages($userId, $userName, $sinceTs);
    $messages = $result['messages'];
    $scannedUntilTs = $result['scanned_until_ts'];

    if (empty($messages)) {
        $log("[updateUserNickname] nessun nuovo messaggio con menzione");
        if ($scannedUntilTs > $sinceTs) {
            saveUserNickname($userId, $existingNickname ?? '', $scannedUntilTs);
        }
        return ['status' => 'no_messages', 'detail' => 'Nessuna nuova menzione da altri utenti', 'nickname' => $existingNickname];
    }

    if (!$existingNickname && count($messages) < USER_MEMORY_MIN_NICK_MESSAGES) {
        $log("[updateUserNickname] solo " . count($messages) . " menzioni (soglia: " . USER_MEMORY_MIN_NICK_MESSAGES . "), salto");
        return ['status' => 'below_threshold', 'detail' => "Solo " . count($messages) . " menzioni da altri utenti", 'nickname' => null];
    }

    $log("[updateUserNickname] " . count($messages) . " menzioni da analizzare");

    $prompt = buildNicknameExtractionPrompt($userName, $existingNickname, $messages);

    // Modello light via /api/chat + think=false (Gemma 4 pattern).
    $log("[updateUserNickname] chiamata Ollama /api/chat (model=" . OLLAMA_MODEL_LIGHT . ", think=false, priority=LAZY)...");
    $response = callOllamaChatViaQBert(
        OLLAMA_MODEL_LIGHT,
        $prompt,
        ollamaOptions(true, ['temperature' => 0.2, 'num_ctx' => 16384]),
        false,
        QBertClient::PRIORITY_LAZY
    );

    if (!$response || empty($response['response'])) {
        $log("[updateUserNickname] ERRORE: risposta Ollama vuota");
        return ['status' => 'error', 'detail' => 'Risposta Ollama vuota o nulla', 'nickname' => $existingNickname];
    }

    $newNickname = stripThinkingTags($response['response']);
    $newNickname = trim($newNickname);

    if (mb_strlen($newNickname) > USER_MEMORY_MAX_NICKNAME_LENGTH * 1.5) {
        $newNickname = mb_substr($newNickname, 0, USER_MEMORY_MAX_NICKNAME_LENGTH) . '...';
    }

    saveUserNickname($userId, $newNickname, $scannedUntilTs);
    $log("[updateUserNickname] nickname aggiornato (" . mb_strlen($newNickname) . " char), scanned_until_ts=$scannedUntilTs");

    return ['status' => 'updated', 'detail' => 'Nickname aggiornato', 'nickname' => $newNickname];
}

/**
 * Versione "exhaust" di updateUserNickname.
 */
function updateUserNicknameExhaust($userId, $verbose = false, $maxIterations = 50) {
    $iterations = 0;
    $lastResult = null;

    while ($iterations < $maxIterations) {
        $iterations++;
        if ($verbose) {
            echo "\n--- Nickname iterazione $iterations ---\n";
            @ob_flush(); @flush();
        }

        $lastResult = updateUserNickname($userId, $verbose);

        if ($lastResult['status'] === 'updated') {
            continue;
        }
        break;
    }

    if ($iterations >= $maxIterations) {
        return [
            'status'     => 'max_iter',
            'iterations' => $iterations,
            'detail'     => "Raggiunto limite massimo iterazioni ($maxIterations)",
            'nickname'   => $lastResult['nickname'] ?? null,
        ];
    }

    return [
        'status'     => $lastResult['status'] === 'no_messages' ? 'completed' : $lastResult['status'],
        'iterations' => $iterations,
        'detail'     => $lastResult['detail'],
        'nickname'   => $lastResult['nickname'],
    ];
}

/**
 * Conta i messaggi per tipo per un utente: generici, bot, nickname (menzioni da altri).
 * Utile per la dashboard admin.
 */
function countUserMessagesByType($userId) {
    global $db;

    $userName = getLatestUserName($userId) ?? "utente_$userId";
    $minLen = USER_MEMORY_MIN_MSG_LENGTH;

    // Totali generici (tutti i messaggi dell'utente)
    $total = countUserMessages($userId);

    // Messaggi con interazione bot (dell'utente): menzioni esplicite OR reply al bot.
    // Esclude comandi slash (/quiz, /ordino, ...) perché non significativi del tono.
    // Nessun filtro di lunghezza: i messaggi brevi rivelano il tono con cui ci si rivolge al bot.
    $botUserId = getBotUserId();
    $botPatterns = ['@rootbotbot', '@rootbot', '@root', '@bot', 'rootbotbot', 'rootbot'];
    $stmt = $db->prepare("
        SELECT message_text, reply_to_user_id FROM (
            SELECT message_text, reply_to_user_id FROM contesto_chat WHERE user_id = :uid
            UNION ALL
            SELECT message_text, reply_to_user_id FROM storico_messaggi WHERE user_id = :uid
        )
    ");
    $stmt->bindValue(':uid', $userId, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $botCount = 0;
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $text = (string)$row['message_text'];
        $trimmed = ltrim($text);
        if ($trimmed === '' || $trimmed[0] === '/') continue;
        if ($botUserId > 0 && (int)$row['reply_to_user_id'] === $botUserId) {
            $botCount++;
            continue;
        }
        $lower = mb_strtolower($text);
        foreach ($botPatterns as $p) {
            if (strpos($lower, $p) !== false) {
                $botCount++;
                break;
            }
        }
    }

    // Messaggi di altri che si rivolgono all'utente (reply diretti + menzioni per nome)
    // 1. Reply diretti
    $stmt = $db->prepare("
        SELECT
            (SELECT COUNT(*) FROM contesto_chat
             WHERE user_id IS NOT NULL AND user_id != :uid
               AND reply_to_user_id = :uid AND length(message_text) >= :min_len
               AND ltrim(message_text) NOT GLOB '/*')
            +
            (SELECT COUNT(*) FROM storico_messaggi
             WHERE user_id IS NOT NULL AND user_id != :uid
               AND reply_to_user_id = :uid AND length(message_text) >= :min_len
               AND ltrim(message_text) NOT GLOB '/*')
        AS c
    ");
    $stmt->bindValue(':uid', $userId, SQLITE3_INTEGER);
    $stmt->bindValue(':min_len', $minLen, SQLITE3_INTEGER);
    $replyCount = (int)$stmt->execute()->fetchArray(SQLITE3_ASSOC)['c'];

    // 2. Menzioni per nome (esclusi quelli già contati come reply)
    $nameParts = preg_split('/\s+/', trim($userName));
    $firstName = mb_strtolower($nameParts[0] ?? $userName);
    $fullName = mb_strtolower($userName);

    $stmt = $db->prepare("
        SELECT message_text FROM (
            SELECT message_text FROM contesto_chat
            WHERE user_id IS NOT NULL AND user_id != :uid
              AND (reply_to_user_id IS NULL OR reply_to_user_id != :uid)
              AND length(message_text) >= :min_len
              AND ltrim(message_text) NOT GLOB '/*'
            UNION ALL
            SELECT message_text FROM storico_messaggi
            WHERE user_id IS NOT NULL AND user_id != :uid
              AND (reply_to_user_id IS NULL OR reply_to_user_id != :uid)
              AND length(message_text) >= :min_len
              AND ltrim(message_text) NOT GLOB '/*'
        )
    ");
    $stmt->bindValue(':uid', $userId, SQLITE3_INTEGER);
    $stmt->bindValue(':min_len', $minLen, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $nickCount = $replyCount;
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $lower = mb_strtolower($row['message_text']);
        if (strpos($lower, $firstName) !== false || strpos($lower, $fullName) !== false) {
            $nickCount++;
        }
    }

    return [
        'total' => $total,
        'bot'   => $botCount,
        'nick'  => $nickCount,
    ];
}

/**
 * Esegue un passo di estrazione/aggiornamento profilo per un utente.
 *
 * @param int $userId
 * @param bool $verbose Se true, stampa info di debug su stdout (utile da CLI)
 * @return array ['status' => 'updated'|'skipped'|'no_messages'|'below_threshold'|'error', 'detail' => string, 'profilo' => string|null]
 */
function updateUserMemory($userId, $verbose = false, $useGpu = false) {
    $log = function($msg) use ($verbose) {
        if ($verbose) { echo $msg . "\n"; @ob_flush(); @flush(); }
    };

    $existing = getUserMemoryProfile($userId);
    // Cursore = unix timestamp dell'ultimo messaggio già processato
    $sinceTs = $existing ? (int)$existing['last_processed_msg_id'] : 0;
    $existingProfile = $existing ? $existing['profilo'] : null;

    $userName = getLatestUserName($userId) ?? ($existing['user_name'] ?? "utente_$userId");
    $log("[updateUserMemory] user_id=$userId name=$userName since_ts=$sinceTs gpu=" . ($useGpu ? 'SI' : 'NO'));

    // Se non c'è un profilo, applica soglia minima sui messaggi totali
    if (!$existing) {
        $totalMsgs = countUserMessages($userId);
        if ($totalMsgs < USER_MEMORY_MIN_MESSAGES) {
            $log("[updateUserMemory] solo $totalMsgs messaggi utili (soglia: " . USER_MEMORY_MIN_MESSAGES . "), salto");
            return ['status' => 'below_threshold', 'detail' => "Solo $totalMsgs messaggi utili", 'profilo' => null];
        }
    }

    $messages = getUnprocessedUserMessages($userId, $sinceTs);
    if (empty($messages)) {
        $log("[updateUserMemory] nessun nuovo messaggio");
        // Se non c'è più niente da processare ma il profilo è sopra soglia, comprimi
        // (utile per cron job: quando ha digerito tutto, sfrutta il giro vuoto per ripulire)
        if ($existingProfile && mb_strlen($existingProfile) > USER_MEMORY_MAX_PROFILE_LENGTH) {
            $log("[updateUserMemory] profilo a " . mb_strlen($existingProfile) . " char > " . USER_MEMORY_MAX_PROFILE_LENGTH . ", compressione a riposo");
            $compressed = compressUserProfile($userId, $userName, $existingProfile, $verbose);
            if ($compressed !== null) {
                return ['status' => 'compressed', 'detail' => 'Profilo compresso (nessun nuovo messaggio)', 'profilo' => $compressed];
            }
        }
        return ['status' => 'no_messages', 'detail' => 'Nessun nuovo messaggio da processare', 'profilo' => $existingProfile];
    }

    $log("[updateUserMemory] " . count($messages) . " nuovi messaggi da processare");

    $prompt = buildMemoryExtractionPrompt($userName, $existingProfile, $messages);

    // Profilo: Gemma 4 via /api/chat con think=false top-level (su /api/generate è instabile).
    // num_predict ampio: vogliamo libertà di preservare info nuove, poi se sfora comprimiamo separatamente.
    $log("[updateUserMemory] chiamata Ollama /api/chat (model=" . OLLAMA_MODEL . ", think=false, priority=LAZY)...");
    $response = callOllamaChatViaQBert(
        OLLAMA_MODEL,
        $prompt,
        ollamaOptions(true, ['num_ctx' => 16384, 'num_predict' => 2500]),
        false,
        QBertClient::PRIORITY_LAZY
    );

    $logFile = logPath('memory');
    $logHeader = "=== PROFILE user=$userName id=$userId " . date('Y-m-d H:i:s') . " ===\n";
    $logResponse = $response;
    unset($logResponse['raw']);
    file_put_contents($logFile, $logHeader . "PROMPT (primi 500 char):\n" . substr($prompt, 0, 500) . "\n\nRESPONSE:\n" . print_r($logResponse, true) . "\n\n", FILE_APPEND);

    if (!$response || empty($response['response'])) {
        $log("[updateUserMemory] ERRORE: risposta Ollama vuota");
        return ['status' => 'error', 'detail' => 'Risposta Ollama vuota o nulla', 'profilo' => $existingProfile];
    }

    $newProfile = stripThinkingTags($response['response']);
    $newProfile = stripProfilePreamble($newProfile);

    // Safety valve: se il profilo esplode oltre 3x il limite durante un batch, comprimiamo
    // subito per evitare runaway. Per i sforamenti normali la compressione avviene a fine run
    // (vedi compressUserProfile chiamata da updateUserMemoryExhaust).
    $safetyThreshold = (int)(USER_MEMORY_MAX_PROFILE_LENGTH * 3);
    if (mb_strlen($newProfile) > $safetyThreshold) {
        $log("[updateUserMemory] safety valve: profilo oltre $safetyThreshold char, compressione immediata");
        $compressed = compressUserProfile($userId, $userName, $newProfile, $verbose);
        if ($compressed !== null) {
            $newProfile = $compressed;
        }
    }

    $lastTs = (int)$messages[count($messages) - 1]['timestamp'];
    $totalCount = ($existing ? (int)$existing['message_count'] : 0) + count($messages);

    saveUserMemoryProfile($userId, $userName, $newProfile, $lastTs, $totalCount);
    $log("[updateUserMemory] profilo aggiornato (" . mb_strlen($newProfile) . " char), last_processed_ts=$lastTs");

    return ['status' => 'updated', 'detail' => 'Profilo aggiornato', 'profilo' => $newProfile];
}

/**
 * Esegue un passo di estrazione/aggiornamento istruzioni bot per un utente.
 * Processa solo messaggi che contengono interazioni con il bot.
 */
function updateUserBotPrompt($userId, $verbose = false, $useGpu = false) {
    $log = function($msg) use ($verbose) {
        if ($verbose) { echo $msg . "\n"; @ob_flush(); @flush(); }
    };

    $existing = getUserMemoryProfile($userId);
    $sinceTs = $existing ? (int)($existing['last_processed_bot_id'] ?? 0) : 0;
    $existingBotPrompt = $existing ? ($existing['bot_prompt'] ?? null) : null;

    $userName = getLatestUserName($userId) ?? ($existing['user_name'] ?? "utente_$userId");
    $log("[updateUserBotPrompt] user_id=$userId name=$userName since_ts=$sinceTs");

    $result = getUnprocessedBotMessages($userId, $sinceTs);
    $messages = $result['messages'];
    $scannedUntilTs = $result['scanned_until_ts'];

    if (empty($messages)) {
        $log("[updateUserBotPrompt] nessuna nuova interazione col bot");
        // Avanza il cursore comunque per non riscansionare gli stessi messaggi
        if ($scannedUntilTs > $sinceTs) {
            saveUserBotPrompt($userId, $existingBotPrompt ?? '', $scannedUntilTs);
        }
        return ['status' => 'no_messages', 'detail' => 'Nessuna nuova interazione col bot', 'bot_prompt' => $existingBotPrompt];
    }

    if (!$existingBotPrompt && count($messages) < USER_MEMORY_MIN_BOT_MESSAGES) {
        $log("[updateUserBotPrompt] solo " . count($messages) . " interazioni bot (soglia: " . USER_MEMORY_MIN_BOT_MESSAGES . "), salto");
        return ['status' => 'below_threshold', 'detail' => "Solo " . count($messages) . " interazioni col bot", 'bot_prompt' => null];
    }

    $log("[updateUserBotPrompt] " . count($messages) . " interazioni bot da analizzare");

    $prompt = buildBotPromptExtractionPrompt($userName, $existingBotPrompt, $messages);

    // Bot prompt: /api/chat + think=false (Gemma 4 pattern). Il thinking nativo spesso
    // consuma tutto il budget senza produrre output, meglio forzarlo off e lasciare al
    // modello il solo compito di sintetizzare direttamente.
    $log("[updateUserBotPrompt] chiamata Ollama /api/chat (model=" . OLLAMA_MODEL . ", think=false, priority=LAZY)...");
    $response = callOllamaChatViaQBert(
        OLLAMA_MODEL,
        $prompt,
        ollamaOptions(true, ['num_ctx' => 16384, 'num_predict' => 2500]),
        false,
        QBertClient::PRIORITY_LAZY
    );

    if (!$response || empty($response['response'])) {
        $log("[updateUserBotPrompt] ERRORE: risposta Ollama vuota");
        return ['status' => 'error', 'detail' => 'Risposta Ollama vuota o nulla', 'bot_prompt' => $existingBotPrompt];
    }

    $newBotPrompt = stripThinkingTags($response['response']);
    $newBotPrompt = trim($newBotPrompt);

    if (mb_strlen($newBotPrompt) > USER_MEMORY_MAX_BOT_PROMPT_LENGTH * 1.5) {
        $newBotPrompt = mb_substr($newBotPrompt, 0, USER_MEMORY_MAX_BOT_PROMPT_LENGTH) . '...';
    }

    saveUserBotPrompt($userId, $newBotPrompt, $scannedUntilTs);
    $log("[updateUserBotPrompt] istruzioni bot aggiornate (" . mb_strlen($newBotPrompt) . " char), scanned_until_ts=$scannedUntilTs");

    return ['status' => 'updated', 'detail' => 'Istruzioni bot aggiornate', 'bot_prompt' => $newBotPrompt];
}

/**
 * Versione "exhaust" di updateUserBotPrompt.
 */
function updateUserBotPromptExhaust($userId, $verbose = false, $useGpu = false, $maxIterations = 50) {
    $iterations = 0;
    $lastResult = null;

    while ($iterations < $maxIterations) {
        $iterations++;
        if ($verbose) {
            echo "\n--- Bot prompt iterazione $iterations ---\n";
            @ob_flush(); @flush();
        }

        $lastResult = updateUserBotPrompt($userId, $verbose, $useGpu);

        if ($lastResult['status'] === 'updated') {
            continue;
        }
        break;
    }

    if ($iterations >= $maxIterations) {
        return [
            'status'     => 'max_iter',
            'iterations' => $iterations,
            'detail'     => "Raggiunto limite massimo iterazioni ($maxIterations)",
            'bot_prompt' => $lastResult['bot_prompt'] ?? null,
        ];
    }

    return [
        'status'     => $lastResult['status'] === 'no_messages' ? 'completed' : $lastResult['status'],
        'iterations' => $iterations,
        'detail'     => $lastResult['detail'],
        'bot_prompt' => $lastResult['bot_prompt'],
    ];
}

/**
 * Calcola statistiche di attività di un utente in tempo reale (non vengono salvate in memorie_utenti
 * perché decadono in fretta). Pensata per essere chiamata dal bot al momento di rispondere all'utente,
 * così da iniettare dati freschi nel system prompt insieme al profilo statico.
 *
 * @return array {
 *     total_messages: int totale messaggi (contesto_chat + storico_messaggi)
 *     last_seen_ts: int|null unix timestamp dell'ultimo messaggio visto
 *     days_since_last_seen: int|null
 *     msgs_last_7d: int messaggi negli ultimi 7 giorni
 *     msgs_last_30d: int messaggi negli ultimi 30 giorni
 *     activity_label: string descrizione narrativa pronta per il prompt
 *                     ("scrive spesso", "raramente", "torna dopo lunga assenza", ecc.)
 * }
 */
function getUserActivityStats($userId) {
    global $db;

    $now = time();
    $sevenDays = $now - (7 * 86400);
    $thirtyDays = $now - (30 * 86400);

    // Conteggi, primo e ultimo timestamp via UNION ALL
    $stmt = $db->prepare("
        SELECT
            COUNT(*) as total,
            MIN(timestamp) as first_ts,
            MAX(timestamp) as last_ts,
            SUM(CASE WHEN timestamp >= :seven THEN 1 ELSE 0 END) as last_7d,
            SUM(CASE WHEN timestamp >= :thirty THEN 1 ELSE 0 END) as last_30d
        FROM (
            SELECT timestamp FROM contesto_chat WHERE user_id = :user_id
            UNION ALL
            SELECT timestamp FROM storico_messaggi WHERE user_id = :user_id
        )
    ");
    $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
    $stmt->bindValue(':seven', $sevenDays, SQLITE3_INTEGER);
    $stmt->bindValue(':thirty', $thirtyDays, SQLITE3_INTEGER);
    $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);

    $total = (int)($row['total'] ?? 0);
    $firstTs = $row['first_ts'] ? (int)$row['first_ts'] : null;
    $lastTs = $row['last_ts'] ? (int)$row['last_ts'] : null;
    $msgs7 = (int)($row['last_7d'] ?? 0);
    $msgs30 = (int)($row['last_30d'] ?? 0);

    $daysSince = $lastTs ? (int)floor(($now - $lastTs) / 86400) : null;
    $daysActive = $firstTs ? (int)floor(($now - $firstTs) / 86400) : null;

    // Etichetta narrativa: combina "freschezza" e "frequenza"
    $label = '';
    if ($total === 0) {
        $label = "nessun messaggio registrato";
    } elseif ($daysSince !== null && $daysSince > 60) {
        $label = "non si fa sentire nel gruppo da {$daysSince} giorni — è il momento di un \"oh chi si rivede!\"";
    } elseif ($daysSince !== null && $daysSince > 21) {
        $label = "è da circa {$daysSince} giorni che non scrive nel gruppo";
    } elseif ($msgs7 >= 30) {
        $label = "è molto attivo nel gruppo (oltre $msgs7 messaggi nell'ultima settimana)";
    } elseif ($msgs7 >= 5) {
        $label = "scrive con regolarità (circa $msgs7 messaggi nell'ultima settimana)";
    } elseif ($msgs30 >= 5) {
        $label = "scrive saltuariamente ($msgs30 messaggi nell'ultimo mese)";
    } else {
        $label = "partecipa raramente al gruppo";
    }

    // Etichetta "anzianità": veterano vs novellino, basata sui giorni di permanenza nel gruppo
    // (computati come distanza tra il primo messaggio visto e oggi).
    $seniorityLabel = '';
    if ($daysActive === null) {
        $seniorityLabel = "presenza nel gruppo sconosciuta";
    } elseif ($daysActive < 14) {
        $seniorityLabel = "novellino del gruppo (qui da meno di due settimane)";
    } elseif ($daysActive < 60) {
        $seniorityLabel = "arrivato da poco nel gruppo (qui da circa " . round($daysActive / 7) . " settimane)";
    } elseif ($daysActive < 365) {
        $seniorityLabel = "frequenta il gruppo da qualche mese (circa " . round($daysActive / 30) . " mesi)";
    } elseif ($daysActive < 365 * 3) {
        $years = round($daysActive / 365, 1);
        $seniorityLabel = "membro consolidato del gruppo (qui da circa $years anni)";
    } else {
        $years = round($daysActive / 365);
        $seniorityLabel = "veterano del gruppo (qui da oltre $years anni)";
    }

    return [
        'total_messages'       => $total,
        'first_seen_ts'        => $firstTs,
        'days_active'          => $daysActive,
        'last_seen_ts'         => $lastTs,
        'days_since_last_seen' => $daysSince,
        'msgs_last_7d'         => $msgs7,
        'msgs_last_30d'        => $msgs30,
        'activity_label'       => $label,
        'seniority_label'      => $seniorityLabel,
    ];
}

/**
 * Versione "exhaust" di updateUserMemory: rilancia in loop finché ci sono nuovi messaggi
 * da processare. Pensata per il bootstrap iniziale di un utente con molti messaggi storici.
 *
 * @param int $userId
 * @param bool $verbose
 * @param bool $useGpu
 * @param int $maxIterations safety net per evitare loop infiniti
 * @return array ['status', 'iterations', 'detail', 'profilo']
 */
function updateUserMemoryExhaust($userId, $verbose = false, $useGpu = false, $maxIterations = 50) {
    $iterations = 0;
    $lastResult = null;

    while ($iterations < $maxIterations) {
        $iterations++;
        if ($verbose) {
            echo "\n--- Iterazione $iterations ---\n";
            @ob_flush(); @flush();
        }

        $lastResult = updateUserMemory($userId, $verbose, $useGpu);

        if ($lastResult['status'] === 'updated') {
            // C'è ancora roba da processare nel prossimo giro
            continue;
        }
        // no_messages, below_threshold, error → stop
        break;
    }

    // Nota: la compressione a riposo (no_messages + profilo sopra soglia) è gestita
    // direttamente dentro updateUserMemory, quindi parte anche da chiamata singola (cron).

    if ($iterations >= $maxIterations) {
        return [
            'status'     => 'max_iter',
            'iterations' => $iterations,
            'detail'     => "Raggiunto limite massimo iterazioni ($maxIterations)",
            'profilo'    => $lastResult['profilo'] ?? null,
        ];
    }

    return [
        'status'     => $lastResult['status'] === 'no_messages' ? 'completed' : $lastResult['status'],
        'iterations' => $iterations,
        'detail'     => $lastResult['detail'],
        'profilo'    => $lastResult['profilo'],
    ];
}

/**
 * Elenca tutti gli user_id distinti con almeno N messaggi utili,
 * sommando contesto_chat + storico_messaggi.
 */
function listKnownUserIds($minMessages = USER_MEMORY_MIN_MESSAGES) {
    global $db;
    $stmt = $db->prepare("
        SELECT user_id, MAX(user_name) as user_name, COUNT(*) as c
        FROM (
            SELECT user_id, user_name FROM contesto_chat
            WHERE user_id IS NOT NULL AND length(message_text) >= :min_len
            UNION ALL
            SELECT user_id, user_name FROM storico_messaggi
            WHERE user_id IS NOT NULL AND length(message_text) >= :min_len
        )
        GROUP BY user_id
        HAVING c >= :min_msgs
        ORDER BY c DESC
    ");
    $stmt->bindValue(':min_len', USER_MEMORY_MIN_MSG_LENGTH, SQLITE3_INTEGER);
    $stmt->bindValue(':min_msgs', $minMessages, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $users = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $users[] = $row;
    }
    return $users;
}
