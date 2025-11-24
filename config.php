<?php
// Costanti e configurazioni
define('BOT_TOKEN', '697681705:AAEGt_SRMPkNnht7ac1aQkh-xUUOp9v6-KQ');
define('WEBHOOK_URL', 'https://www.yourdomain.it/telegram/bot.php');
define('DB_FILE', 'telegram_bot.sqlite');
define('IMAGE_SAVE_PATH', __DIR__ . '/images/');
define('OLLAMA_URL','http://188.153.196.133:11434/api/generate');
define('OLLAMA_MODEL','llama3.2:3b');

define('SILENCE_DURATION', 120); // Durata del silenzio in secondi (2 minuti)
define('RESPONSE_PROBABILITY', 0.8); // Probabilità di risposta (80%)

setlocale(LC_TIME, 'it_IT.utf8');
date_default_timezone_set('Europe/Rome');

?>
