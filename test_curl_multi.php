<?php
header('Content-Type: text/plain');
include __DIR__ . '/config.php';
include __DIR__ . '/include/database.php';
include __DIR__ . '/include/api.php';
include __DIR__ . '/include/QBertClient.php';
include __DIR__ . '/include/ai.php';

$db = new SQLite3(DB_FILE);

$req = [
    'model' => OLLAMA_MODEL_LIGHT ?? 'qwen3:0.6b',
    'prompt' => 'Say hello',
    'stream' => false,
];

$t0 = microtime(true);
$progressCalls = 0;
$result = qbertPostWithProgress('ollama', '/api/generate', $req, function () use (&$progressCalls) { $progressCalls++; });
echo "elapsed: " . round((microtime(true) - $t0) * 1000) . "ms, progress=$progressCalls\n";
echo "is_ticket=" . ($result['is_ticket'] ? '1' : '0') . " status_code=" . ($result['status_code'] ?? 'n/a') . " err=" . ($result['error'] ?? '') . "\n";
echo "body (first 200): " . substr($result['body'] ?? '', 0, 200) . "\n";
