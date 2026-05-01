<?php
/**
 * Estrazione incrementale profili utente notturna.
 * Ogni esecuzione processa UN batch per l'utente più attivo di recente
 * che ha ancora messaggi da analizzare (profilo o istruzioni bot).
 *
 * Crontab: ogni 15 min tra le 00:00 e le 06:00 -> /usr/bin/php /path/to/cron_memory.php
 */

set_time_limit(120);
ini_set('max_execution_time', 120);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/include/database.php';
require_once __DIR__ . '/include/user_memory.php';
require_once __DIR__ . '/include/logger.php';

$db = new SQLite3(__DIR__ . '/' . DB_FILE);
initDatabase();

$logFile = logPath('memory_cron');
$log = function($msg) use ($logFile) {
    $line = date('Y-m-d H:i:s') . " [cron_memory] $msg\n";
    file_put_contents($logFile, $line, FILE_APPEND);
};

// Trova l'utente con backlog da processare, ordinato per attività recente (ultimi 30gg)
$candidate = pickNextUser();

if (!$candidate) {
    $log("Nessun utente con backlog da processare. Skip.");
    exit;
}

$userId = (int)$candidate['user_id'];
$userName = $candidate['user_name'];
$pending = (int)$candidate['pending'];
$recent = (int)$candidate['recent_activity'];

$log("Scelto: $userName (id=$userId) — $pending msg in backlog, $recent msg ultimi 30gg");

// 1. Batch profilo (modello light)
$result = updateUserMemory($userId, false, false);
$log("Profilo: {$result['status']}" . (!empty($result['detail']) ? " — {$result['detail']}" : ""));

// 2. Batch istruzioni bot (modello full + thinking)
$botResult = updateUserBotPrompt($userId, false, false);
$log("Bot prompt: {$botResult['status']}" . (!empty($botResult['detail']) ? " — {$botResult['detail']}" : ""));

// 3. Batch nickname (modello light)
$nickResult = updateUserNickname($userId, false);
$log("Nickname: {$nickResult['status']}" . (!empty($nickResult['detail']) ? " — {$nickResult['detail']}" : ""));

/**
 * Seleziona il prossimo utente da processare.
 * Criteri:
 *  1. Ha almeno USER_MEMORY_MIN_MESSAGES messaggi utili
 *  2. Ha messaggi non ancora processati (timestamp > cursore profilo O cursore bot)
 *  3. Ordinato per attività negli ultimi 30 giorni (DESC), poi per backlog (DESC)
 */
function pickNextUser() {
    global $db;

    $minLen = USER_MEMORY_MIN_MSG_LENGTH;
    $minMsgs = USER_MEMORY_MIN_MESSAGES;
    $thirtyDaysAgo = time() - (30 * 86400);

    $sql = "
        SELECT
            u.user_id,
            u.user_name,
            u.total,
            u.total - COALESCE(m.message_count, 0) AS pending,
            u.recent_activity
        FROM (
            SELECT
                user_id,
                MAX(user_name) AS user_name,
                COUNT(*) AS total,
                SUM(CASE WHEN timestamp >= :thirty THEN 1 ELSE 0 END) AS recent_activity
            FROM (
                SELECT user_id, user_name, timestamp FROM contesto_chat
                WHERE user_id IS NOT NULL AND length(message_text) >= :min_len
                  AND ltrim(message_text) NOT GLOB '/*'
                UNION ALL
                SELECT user_id, user_name, timestamp FROM storico_messaggi
                WHERE user_id IS NOT NULL AND length(message_text) >= :min_len
                  AND ltrim(message_text) NOT GLOB '/*'
            )
            GROUP BY user_id
            HAVING total >= :min_msgs
        ) u
        LEFT JOIN memorie_utenti m ON m.user_id = u.user_id
        WHERE u.user_id NOT IN (
            -- Escludi utenti col profilo completamente processato (cursore profilo aggiornato).
            -- Bot prompt e nickname vengono comunque tentati ad ogni giro,
            -- le rispettive funzioni sono no-op se non c'è nulla da fare.
            -- I filtri (length, NOT GLOB '/*') devono combaciare con quelli di
            -- getUnprocessedUserMessages: altrimenti l'utente viene scelto ma
            -- i 3 batch ritornano subito no_messages (giro a vuoto ogni 5 min).
            SELECT mu.user_id FROM memorie_utenti mu
            WHERE NOT EXISTS (
                SELECT 1 FROM contesto_chat cc
                WHERE cc.user_id = mu.user_id
                  AND cc.timestamp > mu.last_processed_msg_id
                  AND length(cc.message_text) >= :min_len
                  AND ltrim(cc.message_text) NOT GLOB '/*'
            )
            AND NOT EXISTS (
                SELECT 1 FROM storico_messaggi sm
                WHERE sm.user_id = mu.user_id
                  AND sm.timestamp > mu.last_processed_msg_id
                  AND length(sm.message_text) >= :min_len
                  AND ltrim(sm.message_text) NOT GLOB '/*'
            )
        )
        ORDER BY u.recent_activity DESC, pending DESC
        LIMIT 1
    ";

    $stmt = $db->prepare($sql);
    $stmt->bindValue(':min_len', $minLen, SQLITE3_INTEGER);
    $stmt->bindValue(':min_msgs', $minMsgs, SQLITE3_INTEGER);
    $stmt->bindValue(':thirty', $thirtyDaysAgo, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $row = $result->fetchArray(SQLITE3_ASSOC);
    return $row ?: null;
}
