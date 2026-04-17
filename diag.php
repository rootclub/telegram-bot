<?php
/**
 * Endpoint diagnostico per il bot. Espone log e stato in JSON.
 *
 * Protezione: richiede una costante DIAG_TOKEN definita in config.php, ES:
 *   define('DIAG_TOKEN', 'stringa-random-32-byte-hex');  // openssl rand -hex 32
 * Se la costante manca o è vuota, l'endpoint risponde 503 (disabilitato).
 *
 * Autenticazione: header "Authorization: Bearer <token>".
 * NON usare query string per il token (finirebbe in Referer / log di access).
 *
 * Modalità (?mode=):
 *  - summary (default): mtime/size/errori-recenti per ogni canale + mtime file chiave
 *  - channels: solo elenco canali log presenti
 *  - tail: ultime N righe di un canale (?log=name&tail=N, max 500)
 *  - errors: righe con pattern d'errore nel canale (?log=name|all, ?since=30m)
 *  - search: righe con pattern letterale (?log=name|all, ?pattern=testo)
 *
 * Parametri comuni:
 *  - log=<channel|all>  nome canale (stesso formato di logger.php), 'all' = tutti
 *  - tail=N             righe max per canale (default 50, cap 500)
 *  - since=30m          finestra temporale: Ns|Nm|Nh|Nd (default 60m)
 *  - pattern=...        stringa letterale (max 200 char)
 *
 * Esempi:
 *  curl -H "Authorization: Bearer $T" https://host/diag.php
 *  curl -H "Authorization: Bearer $T" 'https://host/diag.php?mode=tail&log=ai&tail=20'
 *  curl -H "Authorization: Bearer $T" 'https://host/diag.php?mode=errors&log=all&since=10m'
 *  curl -H "Authorization: Bearer $T" 'https://host/diag.php?mode=search&log=ai&pattern=QBert'
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/include/logger.php';

/** Restituisce errore JSON e termina. */
function diagFail(int $code, string $msg, array $extra = []): void {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg] + $extra, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

// --- AUTH ---
if (!defined('DIAG_TOKEN') || DIAG_TOKEN === '') {
    diagFail(503, 'diag disabled: define DIAG_TOKEN in config.php');
}

$remote = $_SERVER['REMOTE_ADDR'] ?? '?';
$tokenIn = '';
$authHdr = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if ($authHdr === '' && function_exists('getallheaders')) {
    // Alcuni server non popolano HTTP_AUTHORIZATION, ma solo la tabella headers.
    foreach (getallheaders() as $k => $v) {
        if (strcasecmp($k, 'Authorization') === 0) { $authHdr = $v; break; }
    }
}
if (stripos($authHdr, 'Bearer ') === 0) {
    $tokenIn = trim(substr($authHdr, 7));
}
if (!hash_equals((string)DIAG_TOKEN, $tokenIn)) {
    logLine('diag', "auth_fail from=$remote ua=" . substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 80));
    diagFail(401, 'unauthorized');
}

// --- INPUT ---
$mode      = preg_replace('/[^a-z]/', '', (string)($_GET['mode'] ?? 'summary')) ?: 'summary';
$logName   = preg_replace('/[^a-z0-9_-]/i', '', (string)($_GET['log'] ?? ''));
$tailN     = max(1, min(500, (int)($_GET['tail'] ?? 50)));
$sinceSec  = diagParseSince((string)($_GET['since'] ?? '60m'));
$pattern   = substr((string)($_GET['pattern'] ?? ''), 0, 200);

// --- HELPERS ---
function diagParseSince(string $s): int {
    $s = trim($s);
    if (preg_match('/^(\d+)\s*([smhd]?)$/', $s, $m)) {
        $n = (int)$m[1];
        $unit = $m[2] !== '' ? $m[2] : 's';
        $mult = ['s' => 1, 'm' => 60, 'h' => 3600, 'd' => 86400][$unit];
        return $n * $mult;
    }
    return 3600;
}

function diagResolveChannels(string $maybe): array {
    $all = logChannels();
    if ($maybe === '' || $maybe === 'all') return $all;
    return in_array($maybe, $all, true) ? [$maybe] : [];
}

function diagReadLastLines(string $path, int $n): array {
    if (!is_file($path)) return [];
    // Il logger ruota a 10MB, quindi una lettura intera è accettabile per un admin endpoint.
    $content = @file_get_contents($path);
    if ($content === false) return [];
    $lines = preg_split('/\r?\n/', rtrim($content, "\n"));
    return $lines === false ? [] : array_slice($lines, -$n);
}

/** Pattern standard di errori osservabili nei log del bot. */
function diagErrorRegex(): string {
    return '/PHP (Fatal|Parse|Warning|Notice|Deprecated)|Uncaught|Curl error|API Error|QBert.*error|can\'t parse entities|"ok"\s*:\s*false|^\[[^\]]*\]\s*ERROR |^ERROR /i';
}

/** Filtra per cutoff temporale basato sul timestamp prefisso [YYYY-MM-DD HH:MM:SS]. */
function diagLineAfter(string $line, int $cutoff): bool {
    if (!preg_match('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\]/', $line, $m)) {
        return true; // riga senza timestamp: include per default
    }
    $ts = strtotime($m[1]);
    return $ts === false ? true : $ts >= $cutoff;
}

// --- MODES ---

function diagModeSummary(int $sinceSec): array {
    $channels = logChannels();
    $cutoff   = time() - $sinceSec;
    $re       = diagErrorRegex();
    $out      = [];
    foreach ($channels as $ch) {
        $path  = logPath($ch);
        $size  = @filesize($path);
        $mtime = @filemtime($path);
        // Per conteggio errori, scorri solo le ultime 500 righe (cap cost).
        $lines = diagReadLastLines($path, 500);
        $errs  = 0;
        foreach ($lines as $l) {
            if (!diagLineAfter($l, $cutoff)) continue;
            if (preg_match($re, $l)) $errs++;
        }
        $out[$ch] = [
            'size_bytes' => $size === false ? 0 : $size,
            'mtime'      => $mtime ? date('Y-m-d H:i:s', $mtime) : null,
            'age_sec'    => $mtime ? time() - $mtime : null,
            'errors_in_window' => $errs,
            'rotated_backup' => is_file($path . '.1'),
        ];
    }

    // mtime dei file chiave per verificare lo stato del deploy
    $keyFiles = [
        'bot.php',
        'diag.php',
        'include/logger.php',
        'include/telegram.php',
        'include/dispatcher.php',
        'include/ai.php',
    ];
    $files = [];
    foreach ($keyFiles as $f) {
        $p = __DIR__ . '/' . $f;
        $files[$f] = is_file($p)
            ? ['mtime' => date('Y-m-d H:i:s', filemtime($p)), 'size' => filesize($p)]
            : 'missing';
    }

    // stato del DB
    $dbFile = __DIR__ . '/' . (defined('DB_FILE') ? DB_FILE : 'telegram_bot.sqlite');
    $db = [
        'path'       => $dbFile,
        'exists'     => is_file($dbFile),
        'size_bytes' => is_file($dbFile) ? filesize($dbFile) : 0,
        'writable'   => is_file($dbFile) && is_writable($dbFile),
    ];

    return [
        'ok'          => true,
        'now'         => date('Y-m-d H:i:s'),
        'php_version' => PHP_VERSION,
        'window_sec'  => $sinceSec,
        'channels'    => $out,
        'files'       => $files,
        'db'          => $db,
    ];
}

function diagModeTail(string $ch, int $n): array {
    $resolved = diagResolveChannels($ch);
    if ($resolved === []) diagFail(404, 'log not found', ['have' => logChannels()]);
    $channel = $resolved[0];
    return [
        'ok'      => true,
        'channel' => $channel,
        'lines'   => diagReadLastLines(logPath($channel), $n),
    ];
}

function diagModeErrors(string $ch, int $sinceSec, int $limit): array {
    $channels = diagResolveChannels($ch);
    if ($ch !== '' && $ch !== 'all' && $channels === []) {
        diagFail(404, 'log not found', ['have' => logChannels()]);
    }
    $re      = diagErrorRegex();
    $cutoff  = time() - $sinceSec;
    $results = [];
    foreach ($channels as $channel) {
        $lines = diagReadLastLines(logPath($channel), 2000);
        $matches = [];
        foreach ($lines as $l) {
            if (!preg_match($re, $l)) continue;
            if (!diagLineAfter($l, $cutoff)) continue;
            $matches[] = $l;
            if (count($matches) >= $limit) break;
        }
        if ($matches !== []) $results[$channel] = $matches;
    }
    return [
        'ok'                 => true,
        'since_sec'          => $sinceSec,
        'errors_by_channel'  => $results,
    ];
}

function diagModeSearch(string $ch, string $pattern, int $limit): array {
    if ($pattern === '') diagFail(400, 'pattern required');
    $channels = diagResolveChannels($ch);
    if ($ch !== '' && $ch !== 'all' && $channels === []) {
        diagFail(404, 'log not found', ['have' => logChannels()]);
    }
    // Match letterale case-insensitive: più sicuro di regex libero (no ReDoS).
    $needle = mb_strtolower($pattern);
    $results = [];
    foreach ($channels as $channel) {
        $lines = diagReadLastLines(logPath($channel), 2000);
        $matches = [];
        foreach ($lines as $l) {
            if (mb_stripos($l, $needle) !== false) {
                $matches[] = $l;
                if (count($matches) >= $limit) break;
            }
        }
        if ($matches !== []) $results[$channel] = $matches;
    }
    return [
        'ok'               => true,
        'pattern'          => $pattern,
        'matches_by_channel' => $results,
    ];
}

function diagModeChannels(): array {
    return ['ok' => true, 'channels' => logChannels()];
}

// --- DISPATCH ---
$response = match ($mode) {
    'summary'  => diagModeSummary($sinceSec),
    'channels' => diagModeChannels(),
    'tail'     => $logName === ''
                    ? diagFail(400, 'log param required for tail mode')
                    : diagModeTail($logName, $tailN),
    'errors'   => diagModeErrors($logName, $sinceSec, $tailN),
    'search'   => diagModeSearch($logName, $pattern, $tailN),
    default    => diagFail(400, 'invalid mode', [
                    'valid' => ['summary', 'channels', 'tail', 'errors', 'search']
                  ]),
};

echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
