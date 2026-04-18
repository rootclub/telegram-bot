<?php
/**
 * Agente Quiz — genera un quiz/trivia su un argomento.
 *
 * Autocontenuto: tutta la logica quiz (generateAndSendQuiz, listQuizTopics,
 * getQuizLeaderboard, addQuizTopic, isQuizAdmin, ecc.) vive in quiz.php sotto
 * questa directory. Le tabelle quiz_topics/quiz_history/quiz_responses sono
 * dichiarate in 'schema'. Rimuovendo la directory sparisce tutto il sistema quiz.
 */
require_once __DIR__ . '/quiz.php';

return [
    'id' => 'quiz',
    'description' => "L'utente vuole fare un quiz, trivia o gioco a domande (es. 'fai un quiz', 'facciamo un quiz su storia', 'quizzami')",
    'parameters' => [
        'topic' => "L'argomento del quiz se specificato dall'utente, stringa vuota se non specificato",
    ],
    'sends_own_response' => true,
    'schema' => function (SQLite3 $db): void {
        $db->exec("CREATE TABLE IF NOT EXISTS quiz_topics (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            topic TEXT NOT NULL UNIQUE,
            description TEXT,
            created_at INTEGER DEFAULT (strftime('%s', 'now'))
        )");

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

        // Migrazione idempotente: aggiungi colonna options se manca
        $result = $db->query("PRAGMA table_info(quiz_history)");
        $hasOptions = false;
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            if ($row['name'] === 'options') { $hasOptions = true; break; }
        }
        if (!$hasOptions) {
            $db->exec("ALTER TABLE quiz_history ADD COLUMN options TEXT");
        }

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

        $db->exec("CREATE INDEX IF NOT EXISTS idx_quiz_history_poll_id ON quiz_history(poll_id)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_quiz_responses_poll_id ON quiz_responses(poll_id)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_quiz_responses_user_id ON quiz_responses(user_id)");

        // Seed topic di default se la tabella è vuota
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
                ['elettronica', 'Circuiti, componenti, fondamenti'],
            ];
            foreach ($defaultTopics as $t) {
                $stmt = $db->prepare("INSERT OR IGNORE INTO quiz_topics (topic, description) VALUES (:topic, :desc)");
                $stmt->bindValue(':topic', $t[0], SQLITE3_TEXT);
                $stmt->bindValue(':desc', $t[1], SQLITE3_TEXT);
                $stmt->execute();
            }
        }
    },
    'handler' => function (array $ctx, array $params): ?array {
        $topic = $params['topic'] ?? '';
        $error = generateAndSendQuiz($ctx['chatID'], $topic, $ctx['fromId'], $ctx['firstName']);
        if ($error !== null) {
            return ['response' => $error];
        }
        return ['handled' => true];
    },
];
