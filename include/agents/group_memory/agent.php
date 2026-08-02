<?php
/**
 * Agente Group Memory — ispezione e correzione della memoria del gruppo.
 *
 * La memoria è la parte variabile del prompt di sistema (include/group_memory.php):
 * il cron riscrive il blocco `osservato` dai messaggi, questo agente permette a un
 * amministratore di vedere cosa ha capito il bot e di correggerlo a voce, dalla
 * chat privata. Le correzioni finiscono nel blocco `corretto`, che il cron non
 * tocca mai — senza quella separazione la prima correzione sparirebbe al giro
 * successivo e nessuno userebbe più la funzione.
 *
 * Due filtri, entrambi necessari:
 *  - solo chat privata: la memoria è roba di servizio, non spettacolo da gruppo
 *  - solo amministratori del gruppo principale. Attenzione: in chat privata
 *    l'utente non è amministratore di niente, quindi il controllo va fatto contro
 *    MAIN_GROUP_ID e non contro la chat corrente.
 */

require_once dirname(__DIR__, 2) . '/group_memory.php';

/**
 * L'utente è amministratore del gruppo principale?
 *
 * isAdmin() di events.php interroga la chat che gli passi: chiamarlo con la chat
 * privata restituirebbe sempre false. Qui si guarda sempre il gruppo.
 */
function groupMemoryUserIsAdmin(int $userId): bool {
    if (!defined('MAIN_GROUP_ID')) {
        return false;
    }
    return isAdmin(MAIN_GROUP_ID, $userId);
}

/** Il gruppo di riferimento: la memoria è una sola, quella del gruppo principale. */
function groupMemoryTargetGroup(): int {
    return defined('MAIN_GROUP_ID') ? (int)MAIN_GROUP_ID : 0;
}

/**
 * Applica una correzione in linguaggio naturale al blocco `corretto`.
 *
 * Il testo non viene ricostruito da zero: si passa quello attuale e la richiesta,
 * e si chiede una riscrittura. Così "togli la parte su X" e "aggiungi che Y"
 * funzionano allo stesso modo senza doverli distinguere a monte.
 *
 * @return string|null il nuovo testo, o null se la chiamata è fallita
 */
function groupMemoryApplyEdit(string $attuale, string $richiesta): ?string {
    $maxLen  = GROUP_MEMORY_MAX_LEN;
    $attualeBlock = trim($attuale) !== ''
        ? sanitizeMessageForPrompt($attuale, true)
        : '(vuoto, non c\'è ancora niente)';
    $richiestaClean = sanitizeMessageForPrompt($richiesta, true);

    $prompt = <<<PROMPT
Stai modificando un elenco di fatti su un gruppo Telegram. Un amministratore ti chiede una modifica: applicala e restituisci l'elenco completo aggiornato.

ELENCO ATTUALE:
{$attualeBlock}

RICHIESTA DELL'AMMINISTRATORE:
{$richiestaClean}

REGOLE:
- Applica SOLO la modifica richiesta. Tutto il resto resta identico, parola per parola.
- Se chiede di aggiungere, aggiungi una riga. Se chiede di togliere, togli la riga. Se chiede di correggere, riscrivi solo quella riga.
- Frasi brevi, una per riga, in italiano. Niente titoli, niente markdown, niente simboli di elenco.
- Se la richiesta non è una modifica all'elenco ma una domanda o una chiacchiera, restituisci l'elenco attuale invariato.
- Massimo {$maxLen} caratteri.
- Rispondi con il solo elenco aggiornato, senza preamboli e senza commenti.

ELENCO AGGIORNATO:
PROMPT;

    $result = callOllamaChatViaQBert(
        OLLAMA_MODEL,
        $prompt,
        ollamaOptions(OLLAMA_MODEL_GPU, ['temperature' => 0.2, 'num_ctx' => AI_NUM_CTX]),
        false,
        QBertClient::PRIORITY_NORMAL
    );

    if (!$result) {
        return null;
    }
    $nuovo = trim(stripThinkingTags($result['response'] ?? ''));
    return $nuovo !== '' ? $nuovo : null;
}

return [
    'id' => 'group_memory',
    'description' => "L'amministratore vuole vedere o correggere quello che il bot ha capito del gruppo: la sua memoria, i suoi appunti sul gruppo (es. 'cosa hai capito del gruppo?', 'cosa sai di noi?', 'mostrami i tuoi appunti', 'aggiungi che le pappatoie sono i posti da cui ordiniamo', 'togli la parte su X', 'questo l'hai capito male', 'correggi la memoria', 'com'era prima?')",
    'parameters' => [
        'azione'    => "Una fra: 'mostra' (vuole vedere), 'modifica' (vuole aggiungere/togliere/correggere qualcosa), 'storico' (vuole vedere le versioni precedenti)",
        'richiesta' => "Se azione è 'modifica', la modifica richiesta con parole sue, il più fedelmente possibile. Altrimenti stringa vuota",
    ],

    'help' => "🧠 *Memoria del gruppo* (solo amministratori, in privato)\n"
            . "Scrivimi in privato per vedere cosa ho capito del gruppo (\"cosa sai di noi?\") "
            . "o per correggermi (\"aggiungi che...\", \"togli la parte su...\").",

    'schema' => function (SQLite3 $db): void {
        initGroupMemorySchema($db);
    },

    'handler' => function (array $ctx, array $params): ?array {
        // Nel gruppo non se ne parla: la memoria è roba di servizio.
        if (($ctx['chatType'] ?? '') !== 'private') {
            return ['response' => "Di quello che ho in testa parlo solo in privato. Scrivimi in DM."];
        }

        $userId = (int)($ctx['fromId'] ?? 0);
        if ($userId === 0 || !groupMemoryUserIsAdmin($userId)) {
            return ['response' => "I miei appunti sul gruppo li discuto con chi lo amministra. Non è il tuo caso, mi spiace."];
        }

        $groupId = groupMemoryTargetGroup();
        if ($groupId === 0) {
            return ['response' => "Non so a quale gruppo ti riferisci: manca MAIN_GROUP_ID nella configurazione."];
        }

        $azione    = mb_strtolower(trim((string)($params['azione'] ?? 'mostra')));
        $richiesta = trim((string)($params['richiesta'] ?? ''));
        $memoria   = getGroupMemory($groupId);
        $userName  = $ctx['firstName'] ?? $ctx['userName'] ?? 'admin';

        // --- storico ---------------------------------------------------------
        if ($azione === 'storico') {
            $versioni = getGroupMemoryVersions($groupId, 'osservato', 3);
            if ($versioni === []) {
                return ['response' => "Non ho versioni precedenti da mostrarti: gli appunti non sono ancora cambiati."];
            }
            $out = "Ecco le versioni precedenti dei miei appunti:\n";
            foreach ($versioni as $i => $v) {
                $out .= "\n--- " . date('d/m alle H:i', (int)$v['created_at'])
                     . " (scritti da {$v['autore']}) ---\n" . mb_substr((string)$v['testo'], 0, 600) . "\n";
            }
            return ['response' => $out];
        }

        // --- modifica --------------------------------------------------------
        if ($azione === 'modifica' && $richiesta !== '') {
            $nuovo = groupMemoryApplyEdit($memoria['corretto'], $richiesta);
            if ($nuovo === null) {
                return ['response' => "Ho provato ad aggiornare gli appunti ma non mi ha risposto nessuno. Riprova fra poco."];
            }
            if (!saveGroupMemoryBlock($groupId, 'corretto', $nuovo, $userName)) {
                return ['response' => "Qualcosa è andato storto nel salvataggio, non ho cambiato niente."];
            }
            return ['response' => "Fatto. Ora la parte che mi hai dettato tu dice:\n\n" . $nuovo
                                . "\n\nQuesta non la tocco più da solo: resta finché non me la cambi."];
        }

        // --- mostra (default) ------------------------------------------------
        $osservato = trim($memoria['osservato']);
        $corretto  = trim($memoria['corretto']);

        if ($osservato === '' && $corretto === '') {
            return ['response' => "Per ora non ho capito granché: non ho ancora macinato abbastanza messaggi. Dammi tempo."];
        }

        $out = '';
        if ($osservato !== '') {
            $out .= "Quello che ho capito da solo leggendovi:\n\n" . $osservato . "\n";
        }
        if ($corretto !== '') {
            $out .= "\nQuello che mi avete detto voi (e che non tocco):\n\n" . $corretto . "\n";
        }
        $out .= "\nSe c'è qualcosa da correggere dimmelo pure, tipo \"aggiungi che...\" o \"togli la parte su...\".";

        return ['response' => $out];
    },
];
