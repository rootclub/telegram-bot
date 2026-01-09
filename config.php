<?php
// Costanti e configurazioni
define('BOT_TOKEN', 'TELEGRAM_TOKEN');
define('WEBHOOK_URL', 'https://www.yourdomain.it/telegram/bot.php');
define('DB_FILE', 'telegram_bot.sqlite');
define('IMAGE_SAVE_PATH', __DIR__ . '/images/');
define('OLLAMA_URL','http://188.153.196.133:11434/api/generate');

// Modelli Ollama e configurazione GPU
define('OLLAMA_MODEL','deepseek-r1:14b');
define('OLLAMA_MODEL_GPU', false);  // CPU per il modello principale

define('OLLAMA_MODEL_LIGHT','gemma3:4b');  // Modello leggero per classificazioni veloci
define('OLLAMA_MODEL_LIGHT_GPU', false);  // CPU per modello leggero

define('OLLAMA_MODEL_VISION','gemma3:12b');  // Modello multimodale per analisi immagini
define('OLLAMA_MODEL_VISION_GPU', true);  // GPU per vision (serve velocità)

define('SILENCE_DURATION', 120); // Durata del silenzio in secondi (2 minuti)
define('RESPONSE_PROBABILITY', 0.8); // Probabilità di risposta (80%)

setlocale(LC_TIME, 'it_IT.utf8');
date_default_timezone_set('Europe/Rome');

?>
