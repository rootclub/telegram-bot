<?php
header('Content-Type: text/plain');
$base = __DIR__;
$targets = [
    "$base/include/ai.php",
    "$base/include/QBertClient.php",
    "$base/bot.php",
];
if (function_exists('opcache_invalidate')) {
    foreach ($targets as $f) {
        $ok = opcache_invalidate($f, true);
        echo "invalidate $f: " . ($ok ? 'OK' : 'FAIL') . "\n";
    }
}
if (function_exists('opcache_reset')) {
    $ok = opcache_reset();
    echo "opcache_reset: " . ($ok ? "OK" : "FAIL") . "\n";
}
if (function_exists('opcache_get_status')) {
    $status = opcache_get_status(false);
    echo "enabled: " . (($status['opcache_enabled'] ?? false) ? 'yes' : 'no') . "\n";
    echo "cached_scripts: " . ($status['opcache_statistics']['num_cached_scripts'] ?? 'n/a') . "\n";
    foreach ($targets as $f) {
        $cached = isset($status['scripts'][$f]) ? 'YES' : 'NO';
        echo "in_cache $f: $cached\n";
    }
}
echo "---\n";
echo "ai.php has postNonBlocking: " . (strpos(file_get_contents("$base/include/QBertClient.php"), 'postNonBlocking') !== false ? 'YES' : 'NO') . "\n";
echo "ai.php has curl_multi: " . (strpos(file_get_contents("$base/include/ai.php"), 'curl_multi') !== false ? 'YES' : 'NO') . "\n";
echo "ai.php has 'CALL_MULTI_PERFORM': " . (strpos(file_get_contents("$base/include/ai.php"), 'CALL_MULTI_PERFORM') !== false ? 'YES (vecchio loop)' : 'NO (nuovo)') . "\n";
echo "ai.php has 'safety break': " . (strpos(file_get_contents("$base/include/ai.php"), 'safety break') !== false ? 'YES (nuovo)' : 'NO (vecchio)') . "\n";
echo "ai.php mtime: " . date('Y-m-d H:i:s', filemtime("$base/include/ai.php")) . "\n";
echo "---\n";
echo "opcache.validate_timestamps: " . ini_get('opcache.validate_timestamps') . "\n";
echo "opcache.revalidate_freq: " . ini_get('opcache.revalidate_freq') . "\n";
echo "opcache.restrict_api: " . ini_get('opcache.restrict_api') . "\n";
