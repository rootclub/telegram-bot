<?php
// Costanti e configurazioni
define('BOT_TOKEN', 'TELEGRAM_TOKEN');
define('WEBHOOK_URL', 'https://www.yourdomain.it/telegram/bot.php');
define('DB_FILE', 'telegram_bot.sqlite');
define('IMAGE_SAVE_PATH', __DIR__ . '/images/');
define('QBERT_URL', 'https://qbert.neocerebrum.work');  // Gateway QBert per Ollama e altri servizi

// Modelli Ollama e configurazione GPU
define('OLLAMA_MODEL','deepseek-r1:14b');
define('OLLAMA_MODEL_GPU', false);  // CPU per il modello principale

define('OLLAMA_MODEL_LIGHT','gemma3:4b');  // Modello leggero per classificazioni veloci
define('OLLAMA_MODEL_LIGHT_GPU', false);  // CPU per modello leggero

define('OLLAMA_MODEL_VISION','gemma3:12b');  // Modello multimodale per analisi immagini
define('OLLAMA_MODEL_VISION_GPU', true);  // GPU per vision (serve velocità)

// Modelli per sistema Quiz
define('OLLAMA_QUIZ_SUBTOPICS', 'gemma3:4b');        // Genera lista subtopics (veloce)
define('OLLAMA_QUIZ_SUBTOPICS_GPU', false);

define('OLLAMA_QUIZ_GENERATOR', 'deepseek-r1:14b');  // Genera il quiz da Wikipedia
define('OLLAMA_QUIZ_GENERATOR_GPU', false);

define('OLLAMA_QUIZ_REVIEWER', 'gemma3:4b');         // Revisiona e valida il quiz
define('OLLAMA_QUIZ_REVIEWER_GPU', false);

define('SILENCE_DURATION', 120); // Durata del silenzio in secondi (2 minuti)
define('RESPONSE_PROBABILITY', 0.8); // Probabilità di risposta (80%)

// TTS via qwen-tts (voice clone con profilo Bender)
define('TTS_ENABLED', true);  // Abilita/disabilita pulsante "Ascolta" sulle risposte AI
define('TTS_VOICE_PROFILE', 'bender');
define('TTS_LANGUAGE', 'italian');

setlocale(LC_TIME, 'it_IT.utf8');
date_default_timezone_set('Europe/Rome');

?>
