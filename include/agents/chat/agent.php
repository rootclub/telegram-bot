<?php
/**
 * Agente Chat (default) — conversazione normale con il bot
 */
return [
    'id' => 'chat',
    'description' => "Conversazione normale, opinioni, saluti, battute, domande personali, richieste generiche",
    'parameters' => [],
    'default' => true,
    'handler' => function (array $ctx, array $params, array $enrichments = []): ?array {
        $wikiSection = '';
        foreach ($enrichments as $e) {
            if (!empty($e['prompt_section'])) {
                $wikiSection .= $e['prompt_section'];
            }
        }
        $response = _ai_core($ctx['chatID'], $ctx['chatType'], $ctx['message'], $ctx['userName'], $wikiSection, $ctx['fromId'] ?? null);
        return ['response' => $response];
    },
];
