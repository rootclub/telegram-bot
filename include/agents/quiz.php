<?php
/**
 * Agente Quiz — genera un quiz/trivia su un argomento
 */
return [
    'id' => 'quiz',
    'description' => "L'utente vuole fare un quiz, trivia o gioco a domande (es. 'fai un quiz', 'facciamo un quiz su storia', 'quizzami')",
    'parameters' => [
        'topic' => "L'argomento del quiz se specificato dall'utente, stringa vuota se non specificato",
    ],
    'sends_own_response' => true,
    'handler' => function (array $ctx, array $params): ?array {
        $topic = $params['topic'] ?? '';
        $error = generateAndSendQuiz($ctx['chatID'], $topic, $ctx['fromId'], $ctx['firstName']);
        if ($error !== null) {
            return ['response' => $error];
        }
        return ['handled' => true];
    },
];
