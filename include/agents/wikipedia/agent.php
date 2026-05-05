<?php
/**
 * Agente Wikipedia (enrichment) — arricchisce la risposta chat con informazioni enciclopediche
 */
return [
    'id' => 'wikipedia',
    'description' => "Da scegliere in due casi: (A) l'utente CHIEDE informazioni fattuali/enciclopediche/storiche/scientifiche/biografiche (chi è, cos'è, quando, dove, storia di, significato di, definizione, spiegami...); (B) anche durante una conversazione normale, il messaggio MENZIONA nomi propri, personaggi, band/artisti, opere (film, libri, canzoni, album), eventi storici, luoghi specifici, termini tecnici/scientifici, concetti specialistici che potrebbero richiedere verifica fattuale per rispondere in modo accurato (es. 'ieri ho sentito X in radio', 'sto leggendo Y', 'sono stato a Z'). NON scegliere questo agente se il messaggio è solo chiacchiera senza riferimenti specifici, o se contiene solo nomi iper-noti di uso comune (es. Roma, Italia, Google) che non richiedono verifica.",
    'parameters' => [
        'search_term' => "Il termine o argomento da cercare su Wikipedia. Nel caso (A) è ciò che l'utente chiede esplicitamente; nel caso (B) è il nome/concetto menzionato nel messaggio su cui serve verifica fattuale. Scegli il termine più specifico e cercabile.",
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
                'prompt_section' => "\n\n### FONTE AUTOREVOLE (WIKIPEDIA) ###\n{$wikiContext}\n\nGERARCHIA FONTI: questi fatti vengono da Wikipedia e sono la FONTE PRIMARIA per rispondere alla domanda dell'utente. Se contraddicono qualcosa presente nel CONTESTO GRUPPO o nella CONVERSAZIONE, IGNORA il contesto chat e FIDATI DI QUESTI FATTI — i messaggi di chat possono contenere battute, imprecisioni, errori o informazioni obsolete. Usa questi fatti per rispondere in modo accurato mantenendo il tuo stile, senza citare Wikipedia esplicitamente.",
                'status_message' => "Sto cercando informazioni su {$searchTerm}...",
            ];
        }

        return [
            'prompt_section' => "\n\n### NOTA ###\nHo cercato informazioni su \"{$searchTerm}\" su Wikipedia ma non ho trovato nulla di rilevante. Rispondi onestamente che non hai informazioni affidabili su questo argomento; NON inventare fatti e NON basarti sul contesto chat per rispondere come se fossero fatti certi.",
            'status_message' => null,
        ];
    },
];
