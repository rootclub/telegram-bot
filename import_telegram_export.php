<?php
/**
 * Importa l'export JSON di Telegram Desktop nella tabella storico_messaggi.
 *
 * Uso:
 *   php import_telegram_export.php <file.json> [--group-id=<id>] [--dry-run]
 *
 * Note:
 *   - Idempotente: usa INSERT OR IGNORE su (group_id, telegram_msg_id), puoi rilanciarlo
 *   - Skippa messaggi di servizio (joined/left/pinned/...) e messaggi senza testo
 *   - Skippa from_id non-utente (channel/anonymous) — non sono profilabili
 *   - Default group_id = MAIN_GROUP_ID definito in config.php
 *
 * Memoria: il file viene caricato interamente in RAM. Per 60-100 MB di JSON
 * il peak è nell'ordine di 300-500 MB. Lo script alza memory_limit a 1G.
 */

ini_set('memory_limit', '1G');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/include/database.php';

global $db;
$db = new SQLite3(DB_FILE);
initDatabase();

$argv = $_SERVER['argv'];
array_shift($argv);

$file = null;
$groupId = defined('MAIN_GROUP_ID') ? MAIN_GROUP_ID : null;
$dryRun = false;

foreach ($argv as $a) {
    if ($a === '--dry-run') $dryRun = true;
    elseif (strpos($a, '--group-id=') === 0) $groupId = (int)substr($a, 11);
    elseif ($a[0] !== '-') $file = $a;
}

if (!$file) {
    echo "Uso: php import_telegram_export.php <file.json> [--group-id=<id>] [--dry-run]\n";
    exit(1);
}
if (!file_exists($file)) {
    echo "File non trovato: $file\n";
    exit(1);
}
if ($groupId === null) {
    echo "Manca group_id (passa --group-id=... oppure definisci MAIN_GROUP_ID in config.php)\n";
    exit(1);
}

echo "File: $file (" . round(filesize($file) / 1024 / 1024, 1) . " MB)\n";
echo "Group ID di destinazione: $groupId\n";
echo "Dry run: " . ($dryRun ? 'SI' : 'NO') . "\n\n";

echo "Caricamento JSON in memoria...\n";
$t0 = microtime(true);
$raw = file_get_contents($file);
echo "  letto in " . round(microtime(true) - $t0, 1) . "s\n";

$t0 = microtime(true);
$data = json_decode($raw, true);
unset($raw);
echo "  decodificato in " . round(microtime(true) - $t0, 1) . "s\n";

if (!is_array($data) || !isset($data['messages'])) {
    echo "Formato JSON non riconosciuto: manca il campo 'messages'.\n";
    exit(1);
}

$messages = $data['messages'];
echo "Messaggi totali nell'export: " . count($messages) . "\n\n";

/**
 * Normalizza il campo 'text' di Telegram Desktop in stringa pulita.
 * Può essere: stringa, oppure array di mix [stringhe, {type, text}].
 */
function normalizeText($text) {
    if (is_string($text)) return $text;
    if (!is_array($text)) return '';
    $out = '';
    foreach ($text as $part) {
        if (is_string($part)) $out .= $part;
        elseif (is_array($part) && isset($part['text'])) $out .= $part['text'];
    }
    return $out;
}

/**
 * Estrae user_id numerico da from_id Telegram Desktop ("user12345" -> 12345).
 * Ritorna null se non è un utente reale.
 */
function extractUserId($fromId) {
    if (!is_string($fromId)) return null;
    if (strpos($fromId, 'user') === 0) {
        return (int)substr($fromId, 4);
    }
    return null;
}

$stats = [
    'inserted'        => 0,
    'duplicate'       => 0,
    'skipped_service' => 0,
    'skipped_no_text' => 0,
    'skipped_no_user' => 0,
];

// Pre-build mappa message_id -> user_id per risolvere i reply_to
echo "Costruzione mappa reply_to...\n";
$msgToUser = [];
foreach ($messages as $m) {
    if (isset($m['id'], $m['from_id'])) {
        $uid = extractUserId($m['from_id']);
        if ($uid !== null) {
            $msgToUser[(int)$m['id']] = $uid;
        }
    }
}
echo "  " . count($msgToUser) . " messaggi mappati\n\n";

if (!$dryRun) {
    $db->exec("BEGIN TRANSACTION");
    $stmt = $db->prepare("
        INSERT OR IGNORE INTO storico_messaggi
            (group_id, telegram_msg_id, user_id, user_name, message_text, timestamp, reply_to_user_id)
        VALUES (:gid, :tid, :uid, :uname, :text, :ts, :reply_uid)
    ");
}

$processed = 0;
$totalMessages = count($messages);

foreach ($messages as $m) {
    $processed++;
    if ($processed % 5000 === 0) {
        echo "  ...$processed/$totalMessages\n";
    }

    if (!isset($m['type']) || $m['type'] !== 'message') {
        $stats['skipped_service']++;
        continue;
    }

    $text = normalizeText($m['text'] ?? '');
    $text = trim($text);
    if ($text === '') {
        $stats['skipped_no_text']++;
        continue;
    }

    $userId = extractUserId($m['from_id'] ?? null);
    if ($userId === null) {
        $stats['skipped_no_user']++;
        continue;
    }

    $userName = $m['from'] ?? "utente_$userId";
    $tid = (int)($m['id'] ?? 0);
    $ts = isset($m['date_unixtime']) ? (int)$m['date_unixtime']
        : (isset($m['date']) ? strtotime($m['date']) : 0);

    if ($tid === 0 || $ts === 0) {
        $stats['skipped_no_text']++;
        continue;
    }

    if ($dryRun) {
        $stats['inserted']++;
        continue;
    }

    // Risolvi reply_to_message_id -> user_id dell'autore citato
    $replyToUserId = null;
    if (isset($m['reply_to_message_id'])) {
        $replyToUserId = $msgToUser[(int)$m['reply_to_message_id']] ?? null;
    }

    $stmt->bindValue(':gid',       $groupId, SQLITE3_INTEGER);
    $stmt->bindValue(':tid',       $tid,     SQLITE3_INTEGER);
    $stmt->bindValue(':uid',       $userId,  SQLITE3_INTEGER);
    $stmt->bindValue(':uname',     $userName, SQLITE3_TEXT);
    $stmt->bindValue(':text',      $text,    SQLITE3_TEXT);
    $stmt->bindValue(':ts',        $ts,      SQLITE3_INTEGER);
    $stmt->bindValue(':reply_uid', $replyToUserId, $replyToUserId === null ? SQLITE3_NULL : SQLITE3_INTEGER);
    $stmt->execute();

    if ($db->changes() > 0) {
        $stats['inserted']++;
    } else {
        $stats['duplicate']++;
    }
}

if (!$dryRun) {
    $db->exec("COMMIT");
}

echo "\n=== RISULTATI ===\n";
echo "Inseriti:               {$stats['inserted']}\n";
echo "Duplicati ignorati:     {$stats['duplicate']}\n";
echo "Skippati (servizio):    {$stats['skipped_service']}\n";
echo "Skippati (no testo):    {$stats['skipped_no_text']}\n";
echo "Skippati (no user_id):  {$stats['skipped_no_user']}\n";

if (!$dryRun) {
    $r = $db->querySingle("SELECT COUNT(*) FROM storico_messaggi WHERE group_id = $groupId");
    echo "\nTotale righe in storico_messaggi per group_id=$groupId: $r\n";
}
