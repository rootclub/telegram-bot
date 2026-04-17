<?php
/**
 * Script per generazione automatica del saluto di fine giornata
 * Da eseguire via cron alle 23:50
 *
 * Crontab: 50 23 * * * /usr/bin/php /path/to/cron_saluto.php
 */

// Rimuovi limiti di tempo per evitare timeout
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

// ID del gruppo principale
define('MAIN_GROUP_ID', -1001402757977);

// Lock anti-duplicati (in /tmp per evitare problemi di permessi)
$lockFile = '/tmp/saluto_cron.lock';
$lockTimeout = 600; // 10 minuti

if (file_exists($lockFile)) {
    $lockTime = (int)file_get_contents($lockFile);
    if (time() - $lockTime < $lockTimeout) {
        error_log("[cron_saluto] Already running, skipping");
        exit(0);
    }
}

file_put_contents($lockFile, time());

// Logging via logger centrale (logs/saluto.log, stesso canale di _saluto() in ai.php).
function cron_log($msg) {
    logLine('saluto', "[cron_saluto] $msg");
}

try {
    cron_log("=== Starting daily saluto generation ===");

    // Genera il saluto per oggi
    cron_log("Calling _saluto()...");
    $startTime = time();
    $saluto = _saluto(MAIN_GROUP_ID, 0);
    $elapsed = time() - $startTime;
    cron_log("_saluto() completed in {$elapsed} seconds");

    if (empty($saluto) || strpos($saluto, 'Nessun messaggio trovato') === 0) {
        cron_log("No messages today, skipping");
        @unlink($lockFile);
        exit(0);
    }

    cron_log("Saluto generated, length: " . strlen($saluto));

    // Invia al gruppo (parse_mode=HTML: il wrapper escapa automaticamente
    // il testo LLM; il prompt di _saluto produce testo plain, nessun tag intenzionale)
    cron_log("Sending to Telegram...");
    $options = ['parse_mode' => 'HTML'];
    if (TTS_ENABLED) {
        $options['reply_markup'] = json_encode([
            'inline_keyboard' => [[
                ['text' => "\xF0\x9F\x94\x8A Ascolta", 'callback_data' => 'tts']
            ]]
        ]);
    }
    $result = sendTelegramMessage(MAIN_GROUP_ID, $saluto, $options);

    if ($result['ok']) {
        cron_log("Message sent successfully!");
    } else {
        cron_log("Failed to send message: err=" . ($result['error_code'] ?? '?') . " desc=" . ($result['description'] ?? ''));
    }

} catch (Exception $e) {
    cron_log("ERROR: " . $e->getMessage());
}

cron_log("=== Finished ===\n");
@unlink($lockFile);
