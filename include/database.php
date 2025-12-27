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
        timestamp INTEGER
    )");

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

    // Migration: aggiungi colonne se non esistono
    // Per user_states - aggiungi user_id
    $result = $db->query("PRAGMA table_info(user_states)");
    $hasUserId = false;
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        if ($row['name'] == 'user_id') {
            $hasUserId = true;
            break;
        }
    }
    if (!$hasUserId) {
        $db->exec("ALTER TABLE user_states ADD COLUMN user_id INTEGER");
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
}
?>
