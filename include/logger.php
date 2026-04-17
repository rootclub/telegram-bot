<?php
/**
 * Log centralizzato: tutti i file finiscono in logs/$channel.log.
 * Rotazione automatica a LOG_MAX_SIZE con 1 backup (.1).
 *
 * Uso tipico:
 *   $logFile = logPath('ai');
 *   file_put_contents($logFile, "riga\n", FILE_APPEND);
 *
 * Oppure (preferito per righe semplici):
 *   logLine('ai', 'classificato intent=chat');
 */

const LOG_DIR_NAME = 'logs';
const LOG_MAX_SIZE = 10 * 1024 * 1024; // 10 MB, soglia di rotazione

function _logBaseDir(): string {
    return dirname(__DIR__) . '/' . LOG_DIR_NAME;
}

function _ensureLogDir(): void {
    $dir = _logBaseDir();
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
}

/**
 * Ritorna il path assoluto al file di log per $channel.
 * Esegue anche la rotazione se il file supera LOG_MAX_SIZE.
 *
 * $channel viene sanitizzato: ammessi [a-z0-9_-].
 */
function logPath(string $channel): string {
    _ensureLogDir();
    $clean = preg_replace('/[^a-z0-9_-]/i', '_', $channel);
    if ($clean === '' || $clean === null) {
        $clean = 'misc';
    }
    $path = _logBaseDir() . '/' . $clean . '.log';
    _maybeRotate($path);
    return $path;
}

/**
 * Ruota il log se supera la soglia. Rinomina a $path.1 (sovrascrivendo
 * eventuale backup precedente). Atomica su POSIX.
 * Rotazione "best effort": in caso di race tra worker, l'ultimo rename vince,
 * qualche riga di log può essere persa — accettabile per log non critici.
 */
function _maybeRotate(string $path): void {
    if (!is_file($path)) return;
    $size = @filesize($path);
    if ($size === false || $size < LOG_MAX_SIZE) return;
    @rename($path, $path . '.1');
}

/**
 * Scrittura di una singola riga con timestamp. Aggiunge newline se mancante.
 */
function logLine(string $channel, string $msg, bool $withTimestamp = true): void {
    $path = logPath($channel);
    $line = $withTimestamp ? '[' . date('Y-m-d H:i:s') . '] ' . $msg : $msg;
    if ($line === '' || substr($line, -1) !== "\n") {
        $line .= "\n";
    }
    @file_put_contents($path, $line, FILE_APPEND);
}

/**
 * Lista dei canali di log attualmente presenti su disco.
 * Usato dallo script diagnostico per scoprire i log dinamicamente.
 */
function logChannels(): array {
    $dir = _logBaseDir();
    if (!is_dir($dir)) return [];
    $out = [];
    foreach (glob($dir . '/*.log') as $f) {
        $out[] = basename($f, '.log');
    }
    sort($out);
    return $out;
}
