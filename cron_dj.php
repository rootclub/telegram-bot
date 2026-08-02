<?php
/**
 * Script per interventi casuali del DJ nel gruppo
 * Da eseguire via cron ogni ora
 *
 * Crontab: ogni 15 minuti. NON ogni ora, come diceva questa riga fino al 2026-08-02:
 * misurato sui log, l'intervallo mediano fra due run e' di 14.9 minuti. A questo si
 * somma lo sleep(rand(0,300)) qui sotto, che sfalsa l'orario.
 */

// Rimuovi limiti di tempo
set_time_limit(0);
ini_set('max_execution_time', 0);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/include/database.php';
require_once __DIR__ . '/include/api.php';
require_once __DIR__ . '/include/telegram.php';
require_once __DIR__ . '/include/ai.php';

// Inizializza connessione database
$db = new SQLite3(__DIR__ . '/' . DB_FILE);
initDatabase();

// Delay casuale 0-5 minuti per variare l'orario
sleep(rand(0, 300));

// Configurazione
if (!defined('MAIN_GROUP_ID')) define('MAIN_GROUP_ID', -1001402757977);
// Il dado decide solo se TENTARE. Se il tentativo produca o meno un messaggio lo
// stabiliscono i gate qualitativi dentro _dj() (fatto verificabile su Wikipedia,
// fallback HN col suo tetto, giudizio finale): per questo la probabilità è alta.
// Il freno vero resta MIN_HOURS_BETWEEN_POSTS, non il dado.
define('POST_PROBABILITY', 80);        // Probabilità % di tentare (0-100)
define('MIN_HOURS_BETWEEN_POSTS', 2);  // Minimo ore tra un post e l'altro
define('MIN_MESSAGES_TO_POST', 3);     // Minimo messaggi recenti per tentare
define('CONTEXT_WINDOW_HOURS', 6);     // Finestra su cui misurare l'attività del gruppo

// Logging via logger centrale (visibile in logs/dj_debug.log, accessibile da diag.php).
// Stesso canale usato da _dj()/fetchHN/ecc. in ai.php: così l'intera attività DJ
// resta unificata in un solo file.
function dj_log($msg) {
    logLine('dj_debug', "[cron_dj] $msg");
}

// Lock anti-duplicati (in /tmp per evitare problemi di permessi)
$lockFile = '/tmp/dj_cron.lock';
if (file_exists($lockFile)) {
    $lockTime = (int)file_get_contents($lockFile);
    if (time() - $lockTime < 300) {
        exit(0); // Già in esecuzione
    }
}
file_put_contents($lockFile, time());

try {
    dj_log("=== Cron DJ started ===");

    // Controlla ultimo post (da database)
    $lastPostTime = (int)getBotState('dj_last_post', 0);
    $hoursSinceLastPost = (time() - $lastPostTime) / 3600;
    dj_log("Ore dall'ultimo post: " . round($hoursSinceLastPost, 1));

    if ($hoursSinceLastPost < MIN_HOURS_BETWEEN_POSTS) {
        dj_log("Troppo presto per un altro post, skip");
        @unlink($lockFile);
        exit(0);
    }

    // Il gruppo deve essere stato vivo di recente: su una chat ferma da giorni
    // non ha senso intervenire, nemmeno con una notizia. Finestra più larga
    // dell'ora secca perché _dj() ragiona sugli ultimi messaggi, non sull'orologio.
    // I messaggi di rootbot non contano come attività del gruppo.
    $stmt = $db->prepare("
        SELECT COUNT(*) AS c FROM contesto_chat
        WHERE group_id = :group_id AND timestamp >= :since AND user_name != 'rootbot'
    ");
    $stmt->bindValue(':group_id', MAIN_GROUP_ID, SQLITE3_INTEGER);
    $stmt->bindValue(':since', time() - CONTEXT_WINDOW_HOURS * 3600, SQLITE3_INTEGER);
    $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    $messageCount = (int)($row['c'] ?? 0);
    dj_log("Messaggi nelle ultime " . CONTEXT_WINDOW_HOURS . "h: $messageCount");

    if ($messageCount < MIN_MESSAGES_TO_POST) {
        dj_log("Gruppo troppo fermo, skip");
        @unlink($lockFile);
        exit(0);
    }

    // Lancio del dado
    $roll = rand(1, 100);
    dj_log("Dado: $roll (soglia: " . POST_PROBABILITY . "%)");

    if ($roll > POST_PROBABILITY) {
        dj_log("Dado sfavorevole, skip");
        @unlink($lockFile);
        exit(0);
    }

    // Genera il messaggio DJ. Stringa vuota = il bot ha deciso di tacere
    // (nessun fatto verificabile, o commento scartato dal giudice): il motivo
    // preciso è già finito in dj_debug.log dentro _dj().
    dj_log("Generazione messaggio DJ...");
    $djMessage = _dj(MAIN_GROUP_ID, 0);

    if (trim($djMessage) === '') {
        dj_log("Niente da dire, skip");
        @unlink($lockFile);
        exit(0);
    }

    dj_log("Messaggio generato (" . strlen($djMessage) . " chars)");

    // Invia al gruppo in plain text (no parse_mode): output LLM passato così com'è.
    $result = sendTelegramMessage(MAIN_GROUP_ID, $djMessage);

    if ($result['ok']) {
        dj_log("Messaggio inviato con successo!");
        setBotState('dj_last_post', time());
        // Il DJ deve vedere i propri interventi nel contesto delle run successive,
        // altrimenti torna sugli stessi argomenti senza accorgersene.
        saveMessageToContext(MAIN_GROUP_ID, 'rootbot', $djMessage);
    } else {
        dj_log("Errore invio: err=" . ($result['error_code'] ?? '?') . " desc=" . ($result['description'] ?? ''));
    }

} catch (Exception $e) {
    dj_log("ERRORE: " . $e->getMessage());
}

dj_log("=== Cron DJ finished ===\n");
@unlink($lockFile);
