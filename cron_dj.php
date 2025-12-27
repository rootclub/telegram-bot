<?php
/**
 * Script per interventi casuali del DJ nel gruppo
 * Da eseguire via cron ogni ora
 *
 * Crontab: 0 * * * * /usr/bin/php /path/to/cron_dj.php
 */

// Rimuovi limiti di tempo
set_time_limit(0);
ini_set('max_execution_time', 0);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/include/database.php';
require_once __DIR__ . '/include/api.php';
require_once __DIR__ . '/include/ai.php';

// Inizializza connessione database
$db = new SQLite3(__DIR__ . '/' . DB_FILE);
initDatabase();

// Delay casuale 0-5 minuti per variare l'orario
sleep(rand(0, 300));

// Configurazione
define('MAIN_GROUP_ID', -1001402757977);
define('POST_PROBABILITY', 15);        // Probabilità % di postare (0-100)
define('MIN_HOURS_BETWEEN_POSTS', 2);  // Minimo ore tra un post e l'altro
define('MIN_MESSAGES_TO_POST', 3);     // Minimo messaggi nell'ultima ora per considerare

// File di log e stato
$logFile = __DIR__ . '/cron_dj.log';
$lastPostFile = __DIR__ . '/dj_last_post.txt';

function dj_log($msg) {
    global $logFile;
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($logFile, "[$timestamp] $msg\n", FILE_APPEND);
}

// Lock anti-duplicati
$lockFile = __DIR__ . '/dj_cron.lock';
if (file_exists($lockFile)) {
    $lockTime = (int)file_get_contents($lockFile);
    if (time() - $lockTime < 300) {
        exit(0); // Già in esecuzione
    }
}
file_put_contents($lockFile, time());

try {
    dj_log("=== Cron DJ started ===");

    // Controlla ultimo post
    $lastPostTime = 0;
    if (file_exists($lastPostFile)) {
        $lastPostTime = (int)file_get_contents($lastPostFile);
    }
    $hoursSinceLastPost = (time() - $lastPostTime) / 3600;
    dj_log("Ore dall'ultimo post: " . round($hoursSinceLastPost, 1));

    if ($hoursSinceLastPost < MIN_HOURS_BETWEEN_POSTS) {
        dj_log("Troppo presto per un altro post, skip");
        @unlink($lockFile);
        exit(0);
    }

    // Controlla se ci sono abbastanza messaggi
    $context = getChatContextForHour(MAIN_GROUP_ID, 0, 100);
    $messageCount = empty(trim($context)) ? 0 : count(explode("\n", $context));
    dj_log("Messaggi nell'ultima ora: $messageCount");

    if ($messageCount < MIN_MESSAGES_TO_POST) {
        dj_log("Troppi pochi messaggi, skip");
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

    // Genera il messaggio DJ
    dj_log("Generazione messaggio DJ...");
    $djMessage = _dj(MAIN_GROUP_ID, 0);

    if (empty($djMessage) || strpos($djMessage, 'Nessun messaggio') !== false) {
        dj_log("Messaggio vuoto o errore, skip");
        @unlink($lockFile);
        exit(0);
    }

    dj_log("Messaggio generato (" . strlen($djMessage) . " chars)");

    // Invia al gruppo
    $result = makeAPIRequest('sendMessage', [
        'chat_id' => MAIN_GROUP_ID,
        'text' => $djMessage
    ]);

    if ($result && $result['ok']) {
        dj_log("Messaggio inviato con successo!");
        file_put_contents($lastPostFile, time());
    } else {
        dj_log("Errore invio: " . json_encode($result));
    }

} catch (Exception $e) {
    dj_log("ERRORE: " . $e->getMessage());
}

dj_log("=== Cron DJ finished ===\n");
@unlink($lockFile);
