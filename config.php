<?php
// Costanti e configurazioni
define('BOT_TOKEN', 'TELEGRAM_TOKEN');
define('BOT_NAME', 'rootbot');  // Nome del bot impostato su BotFather (usato come appName per QBert)
define('WEBHOOK_URL', 'https://www.yourdomain.it/telegram/bot.php');
define('DB_FILE', 'telegram_bot.sqlite');
define('IMAGE_SAVE_PATH', __DIR__ . '/images/');
define('QBERT_URL', 'https://qbert.neocerebrum.work');  // Gateway QBert per Ollama e altri servizi

// Modelli Ollama (Gemma 4) e configurazione GPU
define('OLLAMA_MODEL','gemma4:26b');          // MoE 26b per risposte utenti (con reasoning)
define('OLLAMA_MODEL_GPU', true);             // GPU per il modello principale (~18GB VRAM)

define('OLLAMA_MODEL_LIGHT','gemma4:e4b');    // Modello leggero per classificazioni veloci (senza reasoning)
define('OLLAMA_MODEL_LIGHT_GPU', false);      // CPU per modello leggero

define('OLLAMA_MODEL_VISION','gemma4:e4b');   // Modello multimodale per analisi immagini (con reasoning)
define('OLLAMA_MODEL_VISION_GPU', false);     // CPU per vision

// Modelli per sistema Quiz
define('OLLAMA_QUIZ_SUBTOPICS', 'gemma4:e4b');       // Genera lista subtopics (veloce, senza reasoning)
define('OLLAMA_QUIZ_SUBTOPICS_GPU', false);

define('OLLAMA_QUIZ_GENERATOR', 'gemma4:e4b');       // Genera il quiz (con GPU)
define('OLLAMA_QUIZ_GENERATOR_GPU', true);

define('OLLAMA_QUIZ_REVIEWER', 'gemma4:e4b');        // Revisiona e valida il quiz (con GPU)
define('OLLAMA_QUIZ_REVIEWER_GPU', true);

// Parametri sampling consigliati per Gemma 4
define('OLLAMA_TEMPERATURE', 1.0);
define('OLLAMA_TOP_P', 0.95);
define('OLLAMA_TOP_K', 64);

define('SILENCE_DURATION', 120); // Durata del silenzio in secondi (2 minuti)
define('RESPONSE_PROBABILITY', 0.8); // Probabilità di risposta (80%)

// TTS via qwen-tts (voice clone con profilo Bender)
define('TTS_ENABLED', true);  // Abilita/disabilita pulsante "Ascolta" sulle risposte AI
define('TTS_VOICE_PROFILE', 'bender');
define('TTS_LANGUAGE', 'italian');

setlocale(LC_TIME, 'it_IT.utf8');
date_default_timezone_set('Europe/Rome');

?>
