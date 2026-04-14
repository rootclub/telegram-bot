<?php
function initDatabase() {
    global $db;

    $db->exec("CREATE TABLE IF NOT EXISTS profanity_stats (
        user_id INTEGER PRIMARY KEY,
        user_name TEXT,
        lieve INTEGER DEFAULT 0,
        moderata INTEGER DEFAULT 0,
        grave INTEGER DEFAULT 0,
        bestemmia INTEGER DEFAULT 0,
        last_updated DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    
    $db->exec("CREATE TABLE IF NOT EXISTS bot_silence (
        id INTEGER PRIMARY KEY CHECK (id = 1),
        silence_until INTEGER
    )");
    // Inserisci un record iniziale se la tabella è vuota
    $result = $db->query("SELECT COUNT(*) as count FROM bot_silence");
    $row = $result->fetchArray(SQLITE3_ASSOC);
    if ($row['count'] == 0) {
        $db->exec("INSERT INTO bot_silence (id, silence_until) VALUES (1, 0)");
    }
    
    $db->exec("CREATE TABLE IF NOT EXISTS pappatoie (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        pappatoia TEXT NOT NULL UNIQUE,
        indirizzo TEXT,
        telefono TEXT,
        giorni_chiusura TEXT
    )");
    $db->exec("CREATE TABLE IF NOT EXISTS immagini_pappatoie (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        pappatoia_id INTEGER,
        immagine TEXT,
        FOREIGN KEY (pappatoia_id) REFERENCES pappatoie(id)
    )");
    $db->exec("CREATE TABLE IF NOT EXISTS ordini (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        data DATE NOT NULL,
        pappatoia INTEGER,
        ordinante INTEGER,
        ordinante_name TEXT,
        ritirante INTEGER,
        ritirante_name TEXT,
        FOREIGN KEY (pappatoia) REFERENCES pappatoie(id)
    )");
    $db->exec("CREATE TABLE IF NOT EXISTS elementi_ordini (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        id_ordine INTEGER,
        utente INTEGER,
        user_name TEXT,
        descrizione TEXT,
        FOREIGN KEY (id_ordine) REFERENCES ordini(id)
    )");
    $db->exec("CREATE TABLE IF NOT EXISTS contesto_chat (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        group_id INTEGER,
        user_name TEXT,
        message_text TEXT,
        timestamp INTEGER,
        reply_to_user_id INTEGER
    )");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_contesto_group_time ON contesto_chat(group_id, timestamp)");

    // Migrazione idempotente: aggiungi user_id a contesto_chat se manca
    $hasUserId = false;
    $cols = $db->query("PRAGMA table_info(contesto_chat)");
    while ($col = $cols->fetchArray(SQLITE3_ASSOC)) {
        if ($col['name'] === 'user_id') { $hasUserId = true; break; }
    }
    if (!$hasUserId) {
        $db->exec("ALTER TABLE contesto_chat ADD COLUMN user_id INTEGER");
    }
    $db->exec("CREATE INDEX IF NOT EXISTS idx_contesto_user_time ON contesto_chat(user_id, timestamp)");

    // Migrazione: reply_to_user_id su contesto_chat e storico_messaggi
    $cols = $db->query("PRAGMA table_info(contesto_chat)");
    $hasReply = false;
    while ($col = $cols->fetchArray(SQLITE3_ASSOC)) {
        if ($col['name'] === 'reply_to_user_id') { $hasReply = true; break; }
    }
    if (!$hasReply) {
        $db->exec("ALTER TABLE contesto_chat ADD COLUMN reply_to_user_id INTEGER");
    }

    $cols = $db->query("PRAGMA table_info(storico_messaggi)");
    $hasReply = false;
    while ($col = $cols->fetchArray(SQLITE3_ASSOC)) {
        if ($col['name'] === 'reply_to_user_id') { $hasReply = true; break; }
    }
    if (!$hasReply) {
        $db->exec("ALTER TABLE storico_messaggi ADD COLUMN reply_to_user_id INTEGER");
    }
    $db->exec("CREATE INDEX IF NOT EXISTS idx_storico_reply_to ON storico_messaggi(reply_to_user_id)");

    // Memorie utenti: profilo testuale costruito incrementalmente dall'AI
    // NOTA: last_processed_msg_id contiene un UNIX timestamp, non un id riga.
    // È un cursore temporale: l'estrattore prende solo messaggi con timestamp > di questo valore.
    // Il nome è storico, non rinominato per evitare migrazione.
    $db->exec("CREATE TABLE IF NOT EXISTS memorie_utenti (
        user_id INTEGER PRIMARY KEY,
        user_name TEXT,
        profilo TEXT,
        bot_prompt TEXT,
        nickname TEXT,
        message_count INTEGER DEFAULT 0,
        last_processed_msg_id INTEGER DEFAULT 0,
        last_processed_bot_id INTEGER DEFAULT 0,
        last_processed_nick_id INTEGER DEFAULT 0,
        last_updated DATETIME
    )");

    // Migrazione: aggiunta colonne a memorie_utenti
    $result = $db->query("PRAGMA table_info(memorie_utenti)");
    $cols = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $cols[] = $row['name'];
    }
    if (!in_array('bot_prompt', $cols)) {
        $db->exec("ALTER TABLE memorie_utenti ADD COLUMN bot_prompt TEXT");
    }
    if (!in_array('last_processed_bot_id', $cols)) {
        $db->exec("ALTER TABLE memorie_utenti ADD COLUMN last_processed_bot_id INTEGER DEFAULT 0");
    }
    if (!in_array('nickname', $cols)) {
        $db->exec("ALTER TABLE memorie_utenti ADD COLUMN nickname TEXT");
    }
    if (!in_array('last_processed_nick_id', $cols)) {
        $db->exec("ALTER TABLE memorie_utenti ADD COLUMN last_processed_nick_id INTEGER DEFAULT 0");
    }

    // Storico messaggi: import una tantum dall'export Telegram Desktop.
    // Schema sostanzialmente uguale a contesto_chat ma SENZA pruning, con telegram_msg_id
    // per garantire idempotenza degli import (INSERT OR IGNORE su UNIQUE).
    $db->exec("CREATE TABLE IF NOT EXISTS storico_messaggi (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        group_id INTEGER,
        telegram_msg_id INTEGER,
        user_id INTEGER,
        user_name TEXT,
        message_text TEXT,
        timestamp INTEGER,
        reply_to_user_id INTEGER,
        UNIQUE(group_id, telegram_msg_id)
    )");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_storico_user_time ON storico_messaggi(user_id, timestamp)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_storico_group_time ON storico_messaggi(group_id, timestamp)");

    // Tabelle per gestione eventi
    $db->exec("CREATE TABLE IF NOT EXISTS eventi (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        descrizione TEXT NOT NULL,
        data_ora DATETIME NOT NULL,
        costo REAL DEFAULT 0,
        creatore_id INTEGER,
        creatore_name TEXT,
        chat_id INTEGER,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS partecipanti_eventi (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        evento_id INTEGER NOT NULL,
        user_id INTEGER NOT NULL,
        user_name TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (evento_id) REFERENCES eventi(id) ON DELETE CASCADE,
        UNIQUE(evento_id, user_id)
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS ospiti_eventi (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        evento_id INTEGER NOT NULL,
        invitante_id INTEGER NOT NULL,
        invitante_name TEXT,
        nome_ospite TEXT NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (evento_id) REFERENCES eventi(id) ON DELETE CASCADE
    )");

    // Tabella user_states (definita in orders.php ma verifichiamo qui la struttura)
    $db->exec("CREATE TABLE IF NOT EXISTS user_states (
        chat_id INTEGER,
        state TEXT,
        data TEXT
    )");

    // Tabella per tracciare news HN già postate dal DJ
    $db->exec("CREATE TABLE IF NOT EXISTS hn_posted (
        story_id INTEGER PRIMARY KEY,
        title TEXT,
        posted_at INTEGER
    )");
    // Pulizia automatica: rimuovi news più vecchie di 7 giorni
    $weekAgo = time() - (7 * 24 * 3600);
    $db->exec("DELETE FROM hn_posted WHERE posted_at < $weekAgo");

    // Tabella per tracciare articoli della rassegna stampa già postati
    $db->exec("CREATE TABLE IF NOT EXISTS rassegna_posted (
        url TEXT PRIMARY KEY,
        title TEXT,
        posted_at INTEGER
    )");
    // Pulizia automatica: rimuovi articoli più vecchi di 30 giorni
    $monthAgo = time() - (30 * 24 * 3600);
    $db->exec("DELETE FROM rassegna_posted WHERE posted_at < $monthAgo");

    // Tabella per stato generico del bot (chiave-valore)
    $db->exec("CREATE TABLE IF NOT EXISTS bot_state (
        key TEXT PRIMARY KEY,
        value TEXT,
        updated_at INTEGER
    )");

    // Tabella per deduplicazione messaggi (anti-spam)
    $db->exec("CREATE TABLE IF NOT EXISTS message_dedup (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        message_hash TEXT NOT NULL,
        timestamp INTEGER NOT NULL
    )");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_dedup_user_hash ON message_dedup(user_id, message_hash)");
    // Pulizia automatica: rimuovi record più vecchi di 5 minuti
    $fiveMinutesAgo = time() - 300;
    $db->exec("DELETE FROM message_dedup WHERE timestamp < $fiveMinutesAgo");

    // Rate limit generazione immagini
    $db->exec("CREATE TABLE IF NOT EXISTS image_gen_usage (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        timestamp INTEGER NOT NULL
    )");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_image_gen_user ON image_gen_usage(user_id, timestamp)");
    // Pulizia automatica: rimuovi record più vecchi di 1 ora
    $oneHourAgo = time() - 3600;
    $db->exec("DELETE FROM image_gen_usage WHERE timestamp < $oneHourAgo");

    // Migration: aggiungi colonne se non esistono
    // Per user_states - aggiungi user_id e created_at
    $result = $db->query("PRAGMA table_info(user_states)");
    $hasUserId = false;
    $hasCreatedAt = false;
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        if ($row['name'] == 'user_id') {
            $hasUserId = true;
        }
        if ($row['name'] == 'created_at') {
            $hasCreatedAt = true;
        }
    }
    if (!$hasUserId) {
        $db->exec("ALTER TABLE user_states ADD COLUMN user_id INTEGER");
    }
    if (!$hasCreatedAt) {
        $db->exec("ALTER TABLE user_states ADD COLUMN created_at INTEGER");
    }

    // Per elementi_ordini
    $result = $db->query("PRAGMA table_info(elementi_ordini)");
    $hasUserName = false;
    $hasDelegato = false;
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        if ($row['name'] == 'user_name') {
            $hasUserName = true;
        }
        if ($row['name'] == 'delegato_da') {
            $hasDelegato = true;
        }
    }
    if (!$hasUserName) {
        $db->exec("ALTER TABLE elementi_ordini ADD COLUMN user_name TEXT");
        // Migra i dati esistenti dalla colonna utente (che contiene nomi) a user_name
        $db->exec("UPDATE elementi_ordini SET user_name = CAST(utente AS TEXT) WHERE user_name IS NULL");
    }
    if (!$hasDelegato) {
        // Colonna per tracciare chi ha inserito l'ordine per conto di un altro (admin_id)
        $db->exec("ALTER TABLE elementi_ordini ADD COLUMN delegato_da INTEGER");
    }

    // Per ordini - ordinante_name
    $result = $db->query("PRAGMA table_info(ordini)");
    $hasOrdinanteName = false;
    $hasRitiranteName = false;
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        if ($row['name'] == 'ordinante_name') {
            $hasOrdinanteName = true;
        }
        if ($row['name'] == 'ritirante_name') {
            $hasRitiranteName = true;
        }
    }
    if (!$hasOrdinanteName) {
        $db->exec("ALTER TABLE ordini ADD COLUMN ordinante_name TEXT");
        // Migra i dati esistenti
        $db->exec("UPDATE ordini SET ordinante_name = CAST(ordinante AS TEXT) WHERE ordinante_name IS NULL");
    }
    if (!$hasRitiranteName) {
        $db->exec("ALTER TABLE ordini ADD COLUMN ritirante_name TEXT");
        // Migra i dati esistenti
        $db->exec("UPDATE ordini SET ritirante_name = CAST(ritirante AS TEXT) WHERE ritirante_name IS NULL");
    }

    // === TABELLE SISTEMA QUIZ ===

    // Argomenti quiz disponibili (per /quiz senza argomento)
    $db->exec("CREATE TABLE IF NOT EXISTS quiz_topics (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        topic TEXT NOT NULL UNIQUE,
        description TEXT,
        created_at INTEGER DEFAULT (strftime('%s', 'now'))
    )");

    // Storico quiz inviati
    $db->exec("CREATE TABLE IF NOT EXISTS quiz_history (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        chat_id INTEGER NOT NULL,
        poll_id TEXT NOT NULL UNIQUE,
        message_id INTEGER,
        topic TEXT NOT NULL,
        wikipedia_title TEXT,
        question TEXT NOT NULL,
        options TEXT,
        correct_option INTEGER NOT NULL,
        explanation TEXT,
        created_at INTEGER DEFAULT (strftime('%s', 'now'))
    )");

    // Migration: aggiungi colonna options se non esiste
    $result = $db->query("PRAGMA table_info(quiz_history)");
    $hasOptions = false;
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        if ($row['name'] == 'options') {
            $hasOptions = true;
            break;
        }
    }
    if (!$hasOptions) {
        $db->exec("ALTER TABLE quiz_history ADD COLUMN options TEXT");
    }

    // Risposte utenti per classifica
    $db->exec("CREATE TABLE IF NOT EXISTS quiz_responses (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        quiz_id INTEGER NOT NULL,
        poll_id TEXT NOT NULL,
        user_id INTEGER NOT NULL,
        user_name TEXT,
        selected_option INTEGER NOT NULL,
        is_correct INTEGER NOT NULL,
        answered_at INTEGER DEFAULT (strftime('%s', 'now')),
        FOREIGN KEY (quiz_id) REFERENCES quiz_history(id),
        UNIQUE(poll_id, user_id)
    )");

    // Indici per performance quiz
    $db->exec("CREATE INDEX IF NOT EXISTS idx_quiz_history_poll_id ON quiz_history(poll_id)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_quiz_responses_poll_id ON quiz_responses(poll_id)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_quiz_responses_user_id ON quiz_responses(user_id)");

    // Tabella cache TTS (voice file_id per evitare rigenerazione)
    $db->exec("CREATE TABLE IF NOT EXISTS tts_cache (
        message_id INTEGER NOT NULL,
        chat_id INTEGER NOT NULL,
        voice_file_id TEXT NOT NULL,
        created_at INTEGER NOT NULL,
        PRIMARY KEY (chat_id, message_id)
    )");
    // Pulizia automatica: rimuovi record più vecchi di 7 giorni
    $weekAgoTTS = time() - (7 * 24 * 3600);
    $db->exec("DELETE FROM tts_cache WHERE created_at < $weekAgoTTS");

    // Tabella per tracciare messaggi a cui il bot ha risposto (per gestire edited_message)
    $db->exec("CREATE TABLE IF NOT EXISTS bot_replied (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        chat_id INTEGER NOT NULL,
        message_id INTEGER NOT NULL,
        replied_at INTEGER NOT NULL,
        UNIQUE(chat_id, message_id)
    )");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_bot_replied_chat_msg ON bot_replied(chat_id, message_id)");
    // Pulizia automatica: rimuovi record più vecchi di 24 ore
    $oneDayAgo = time() - (24 * 3600);
    $db->exec("DELETE FROM bot_replied WHERE replied_at < $oneDayAgo");

    // Log immagini: salva file_id per ri-analisi su richiesta
    $db->exec("CREATE TABLE IF NOT EXISTS image_log (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        group_id INTEGER,
        user_id INTEGER,
        user_name TEXT,
        file_id TEXT NOT NULL,
        description TEXT,
        timestamp INTEGER
    )");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_image_log_group ON image_log(group_id, timestamp)");
    // Pulizia automatica: rimuovi immagini più vecchie di 30 giorni
    $monthAgo = time() - (30 * 24 * 3600);
    $db->exec("DELETE FROM image_log WHERE timestamp < $monthAgo");

    // Popola topic iniziali se tabella vuota
    $result = $db->query("SELECT COUNT(*) as count FROM quiz_topics");
    $row = $result->fetchArray(SQLITE3_ASSOC);
    if ($row['count'] == 0) {
        $defaultTopics = [
            ['storia', 'Eventi storici, personaggi, date importanti'],
            ['scienza', 'Fisica, chimica, biologia, astronomia'],
            ['tecnologia', 'Informatica, elettronica, innovazioni'],
            ['geografia', 'Paesi, citta, fiumi, montagne'],
            ['cultura', 'Arte, letteratura, musica, cinema'],
            ['natura', 'Animali, piante, ecosistemi'],
            ['sport', 'Discipline sportive, olimpiadi, record'],
            ['videogiochi', 'Arcade, console, storia dei videogiochi'],
            ['anime', 'Anime classici e moderni, manga'],
            ['elettronica', 'Circuiti, componenti, fondamenti']
        ];
        foreach ($defaultTopics as $t) {
            $stmt = $db->prepare("INSERT OR IGNORE INTO quiz_topics (topic, description) VALUES (:topic, :desc)");
            $stmt->bindValue(':topic', $t[0], SQLITE3_TEXT);
            $stmt->bindValue(':desc', $t[1], SQLITE3_TEXT);
            $stmt->execute();
        }
    }
}

/**
 * Verifica se il bot ha già risposto (o sta rispondendo) a un messaggio.
 * @param int $chatId ID della chat
 * @param int $messageId ID del messaggio
 * @return bool true se già risposto, false altrimenti
 */
function hasAlreadyReplied($chatId, $messageId) {
    global $db;

    $stmt = $db->prepare("SELECT COUNT(*) as cnt FROM bot_replied
                          WHERE chat_id = :chat_id AND message_id = :message_id");
    $stmt->bindValue(':chat_id', $chatId, SQLITE3_INTEGER);
    $stmt->bindValue(':message_id', $messageId, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $row = $result->fetchArray(SQLITE3_ASSOC);

    return ($row['cnt'] > 0);
}

/**
 * Marca un messaggio come "risposto" (o "in elaborazione").
 * Usa INSERT OR IGNORE per evitare errori su duplicati.
 * @param int $chatId ID della chat
 * @param int $messageId ID del messaggio
 * @return bool true se inserito, false se già esisteva
 */
function markAsReplied($chatId, $messageId) {
    global $db;

    $stmt = $db->prepare("INSERT OR IGNORE INTO bot_replied (chat_id, message_id, replied_at)
                          VALUES (:chat_id, :message_id, :replied_at)");
    $stmt->bindValue(':chat_id', $chatId, SQLITE3_INTEGER);
    $stmt->bindValue(':message_id', $messageId, SQLITE3_INTEGER);
    $stmt->bindValue(':replied_at', time(), SQLITE3_INTEGER);
    $stmt->execute();

    return ($db->changes() > 0);
}

/**
 * Salva un'immagine nel log per ri-analisi futura
 */
function saveImageLog($groupId, $userId, $userName, $fileId, $description) {
    global $db;
    $stmt = $db->prepare("INSERT INTO image_log (group_id, user_id, user_name, file_id, description, timestamp)
                          VALUES (:group_id, :user_id, :user_name, :file_id, :description, :timestamp)");
    $stmt->bindValue(':group_id', $groupId, SQLITE3_INTEGER);
    $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
    $stmt->bindValue(':user_name', $userName, SQLITE3_TEXT);
    $stmt->bindValue(':file_id', $fileId, SQLITE3_TEXT);
    $stmt->bindValue(':description', $description, SQLITE3_TEXT);
    $stmt->bindValue(':timestamp', time(), SQLITE3_INTEGER);
    $stmt->execute();
}

/**
 * Recupera le ultime N immagini di un gruppo
 * @return array Lista di ['file_id', 'description', 'user_name', 'timestamp']
 */
function getRecentImages($groupId, $limit = 10) {
    global $db;
    $stmt = $db->prepare("SELECT file_id, description, user_name, timestamp
                          FROM image_log
                          WHERE group_id = :group_id
                          ORDER BY timestamp DESC
                          LIMIT " . intval($limit));
    $stmt->bindValue(':group_id', $groupId, SQLITE3_INTEGER);
    $result = $stmt->execute();

    $images = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $images[] = $row;
    }
    return $images;
}
?>
