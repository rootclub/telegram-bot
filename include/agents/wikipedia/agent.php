<?php
/**
 * Agente Wikipedia (enrichment) — arricchisce la risposta chat con informazioni enciclopediche
 */
return [
    'id' => 'wikipedia',
    'description' => "L'utente chiede informazioni fattuali, enciclopediche, storiche, scientifiche, biografiche (chi è, cos'è, quando, dove, storia di, significato di, definizione, spiegami...)",
    'parameters' => [
        'search_term' => "Il termine o argomento da cercare su Wikipedia per rispondere alla domanda",
    ],
    'enriches' => 'chat',
    'handler' => function (array $ctx, array $params): ?array {
        $searchTerm = $params['search_term'] ?? '';
        if (empty($searchTerm)) {
            return null;
        }

        $wikiContext = getWikipediaContext($searchTerm);
        if ($wikiContext) {
            return [
                'prompt_section' => "\n\n{$wikiContext}\n\nUSA QUESTE INFORMAZIONI per rispondere in modo accurato, ma mantieni il tuo stile e non citare Wikipedia esplicitamente.",
                'status_message' => "Sto cercando informazioni su {$searchTerm}...",
            ];
        }

        return [
            'prompt_section' => "\n\n### NOTA ###\nHo cercato informazioni su \"{$searchTerm}\" ma non ho trovato nulla di rilevante. Rispondi onestamente che non hai informazioni su questo argomento.",
            'status_message' => null,
        ];
    },
];
