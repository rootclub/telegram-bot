<?php
/**
 * Script CLI per testare l'estrazione/aggiornamento del profilo memoria di un utente.
 *
 * Uso:
 *   php extract_user_memory.php <user_id> [--exhaust] [--gpu]
 *   php extract_user_memory.php --list
 *   php extract_user_memory.php --all [--exhaust] [--gpu]
 *   php extract_user_memory.php --show <user_id>
 *
 * Flag:
 *   --exhaust   Continua a processare batch finché non ha esaurito i messaggi nuovi
 *   --gpu       Usa il modello principale OLLAMA_MODEL su GPU (più veloce e accurato)
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/include/database.php';
require_once __DIR__ . '/include/user_memory.php';

global $db;
$db = new SQLite3(DB_FILE);
initDatabase();

$argv = $_SERVER['argv'];
array_shift($argv);

if (empty($argv)) {
    echo "Uso:\n";
    echo "  php extract_user_memory.php <user_id> [--exhaust] [--gpu]\n";
    echo "  php extract_user_memory.php --list\n";
    echo "  php extract_user_memory.php --all [--exhaust] [--gpu]\n";
    echo "  php extract_user_memory.php --show <user_id>\n";
    exit(1);
}

// Estrai flag opzionali (rimossi dalla lista posizionale)
$exhaust = false;
$useGpu = false;
$positional = [];
foreach ($argv as $a) {
    if ($a === '--exhaust') $exhaust = true;
    elseif ($a === '--gpu') $useGpu = true;
    else $positional[] = $a;
}
$argv = $positional;
$cmd = $argv[0] ?? '';

if ($cmd === '--list') {
    $users = listKnownUserIds();
    if (empty($users)) {
        echo "Nessun utente idoneo (servono almeno " . USER_MEMORY_MIN_MESSAGES . " messaggi utili).\n";
        exit(0);
    }
    echo "Utenti idonei (>= " . USER_MEMORY_MIN_MESSAGES . " messaggi utili):\n";
    foreach ($users as $u) {
        $existing = getUserMemoryProfile($u['user_id']);
        $hasProfile = $existing ? ' [profilo presente]' : '';
        echo sprintf("  %12d  %-30s  %4d msg%s\n", $u['user_id'], $u['user_name'], $u['c'], $hasProfile);
    }
    exit(0);
}

if ($cmd === '--all') {
    $users = listKnownUserIds();
    echo "Aggiorno " . count($users) . " utenti (exhaust=" . ($exhaust ? 'SI' : 'NO') . ", gpu=" . ($useGpu ? 'SI' : 'NO') . ")...\n\n";
    foreach ($users as $u) {
        echo "=== {$u['user_name']} (id={$u['user_id']}, {$u['c']} msg) ===\n";
        if ($exhaust) {
            $result = updateUserMemoryExhaust((int)$u['user_id'], true, $useGpu);
            echo "  Risultato: {$result['status']} dopo {$result['iterations']} iter\n";
        } else {
            $result = updateUserMemory((int)$u['user_id'], true, $useGpu);
            echo "  Risultato: {$result['status']} - {$result['detail']}\n";
        }
        if ($result['profilo']) {
            echo "  Profilo: " . $result['profilo'] . "\n";
        }
        echo "\n";
    }
    exit(0);
}

if ($cmd === '--show') {
    if (empty($argv[1])) { echo "Manca user_id\n"; exit(1); }
    $userId = (int)$argv[1];
    $profile = getUserMemoryProfile($userId);
    if (!$profile) {
        echo "Nessun profilo per user_id $userId\n";
        exit(0);
    }
    echo "User: {$profile['user_name']} (id=$userId)\n";
    echo "Aggiornato: {$profile['last_updated']}\n";
    echo "Messaggi processati totali: {$profile['message_count']}\n";
    echo "Last msg id: {$profile['last_processed_msg_id']}\n";
    echo "Profilo:\n  {$profile['profilo']}\n";
    exit(0);
}

// Singolo user_id numerico
if (is_numeric($cmd)) {
    $userId = (int)$cmd;
    echo "=== Aggiornamento profilo user_id=$userId (exhaust=" . ($exhaust ? 'SI' : 'NO') . ", gpu=" . ($useGpu ? 'SI' : 'NO') . ") ===\n";
    if ($exhaust) {
        $result = updateUserMemoryExhaust($userId, true, $useGpu);
        echo "\nRisultato: {$result['status']} dopo {$result['iterations']} iter - {$result['detail']}\n";
    } else {
        $result = updateUserMemory($userId, true, $useGpu);
        echo "Risultato: {$result['status']} - {$result['detail']}\n";
    }
    if ($result['profilo']) {
        echo "\nProfilo:\n{$result['profilo']}\n";
    }
    exit(0);
}

echo "Comando non riconosciuto: $cmd\n";
exit(1);
