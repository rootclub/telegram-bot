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
 * Traduce una correzione in linguaggio naturale in un diff sui DUE blocchi.
 *
 * Prima versione sbagliata, per memoria: chiedeva al modello di riscrivere per
 * intero il solo blocco `corretto`. Due conseguenze, viste entrambe in produzione
 * al primo utilizzo reale. Primo, una richiesta del tipo "correggi espressionmente
 * in espressione" riguarda una riga scritta dal cron, che sta in `osservato`: il
 * blocco sbagliato veniva modificato e la riga storta restava dov'era. Secondo, la
 * riscrittura integrale di un blocco vuoto ha prodotto la sola parola "espressioni",
 * spazzando via il blocco che per costruzione doveva essere il piu' protetto.
 *
 * Ora si chiede un diff, come gia' fa il cron per la stessa ragione: quello che
 * non viene nominato non passa dal modello e resta intatto. E l'amministratore
 * puo' togliere righe da `osservato`, che e' il modo naturale di correggere un
 * errore del bot; le righe positive invece finiscono in `corretto`, dove il cron
 * non arriva. Nota utile: cio' che sta in `corretto` viene passato al prompt del
 * cron come gia' stabilito e da non ripetere, quindi una riga corretta a mano
 * scoraggia da sola il ritorno di quella sbagliata.
 *
 * @return array|null il diff grezzo del modello, o null se la chiamata fallisce
 */
function groupMemoryPlanEdit(array $memoria, string $richiesta): ?array {
    $numera = function (string $testo): string {
        $righe = groupMemoryLines($testo);
        if ($righe === []) {
            return '(vuoto)';
        }
        $out = [];
        foreach ($righe as $i => $r) {
            $out[] = ($i + 1) . '. ' . $r;
        }
        return implode("\n", $out);
    };

    $bloccoA = $numera(sanitizeMessageForPrompt($memoria['osservato'], true));
    $bloccoB = $numera(sanitizeMessageForPrompt($memoria['corretto'], true));
    $richiestaClean = sanitizeMessageForPrompt($richiesta, true);

    $prompt = <<<PROMPT
Un amministratore ti chiede di correggere i tuoi appunti su un gruppo Telegram. Gli appunti stanno in due elenchi separati. Traduci la sua richiesta in un elenco di modifiche.

ELENCO A — quello che hai scritto tu leggendo la chat (puoi solo TOGLIERE righe):
{$bloccoA}

ELENCO B — quello che ti hanno dettato gli amministratori (puoi togliere e aggiungere):
{$bloccoB}

RICHIESTA DELL'AMMINISTRATORE:
{$richiestaClean}

COME RAGIONARE:
- Se contesta una cosa che hai scritto tu, la riga sta quasi sempre nell'ELENCO A: mettine il numero in "a_rimuovi".
- Se ti chiede di correggere il testo di una riga dell'ELENCO A, togli quella riga da A e metti la versione giusta, per intero e con le sue parole, in "b_aggiungi".
- Se ti chiede di aggiungere un'informazione nuova, va in "b_aggiungi" e basta.
- Se ti chiede di togliere qualcosa che sta nell'ELENCO B, mettine il numero in "b_rimuovi".
- Se la richiesta non e' una modifica agli appunti (una domanda, una chiacchiera), lascia tutte e tre le liste vuote.

Rispondi SOLO con questo oggetto JSON:
{"a_rimuovi": [numeri], "b_aggiungi": ["frase intera"], "b_rimuovi": [numeri]}

- I numeri sono quelli degli elenchi qui sopra, ognuno riferito al proprio elenco.
- Le frasi di "b_aggiungi" devono stare in piedi da sole: una frase compiuta, non una parola sciolta. "espressioni" non e' una frase; "Si usano espressioni in dialetto romagnolo (es. indarli per tarlato)" lo e'.
- Non toccare righe che l'amministratore non ha nominato.
PROMPT;

    $result = callOllamaChatViaQBert(
        OLLAMA_MODEL,
        $prompt,
        ollamaOptions(OLLAMA_MODEL_GPU, ['temperature' => 0.1, 'num_ctx' => AI_NUM_CTX]),
        false,
        QBertClient::PRIORITY_NORMAL
    );

    if (!$result) {
        return null;
    }
    $raw    = trim(stripThinkingTags($result['response'] ?? ''));
    $parsed = extractJsonObject($raw);

    if (!is_array($parsed)) {
        logLine('group_memory', 'edit: parse fallito, raw=' . substr($raw, 0, 200));
        return null;
    }
    return $parsed;
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
            $piano = groupMemoryPlanEdit($memoria, $richiesta);
            if ($piano === null) {
                return ['response' => "Ho provato a metterci mano ma non mi ha risposto nessuno. Riprova fra poco."];
            }

            $righeA = groupMemoryLines($memoria['osservato']);
            $righeB = groupMemoryLines($memoria['corretto']);

            // Su `osservato` l'amministratore puo' solo togliere: aggiungere li'
            // sarebbe inutile, perche' il cron riscrive quel blocco.
            $diffA = applyGroupMemoryDiff($righeA, ['aggiungi' => [], 'rimuovi' => $piano['a_rimuovi'] ?? []], true);
            $diffB = applyGroupMemoryDiff($righeB, [
                'aggiungi' => $piano['b_aggiungi'] ?? [],
                'rimuovi'  => $piano['b_rimuovi'] ?? [],
            ], true);

            foreach (array_merge($diffA['note'], $diffB['note']) as $n) {
                logLine('group_memory', 'edit: ' . $n);
            }

            $cambiato = $diffA['rimosse'] + $diffB['aggiunte'] + $diffB['rimosse'];
            if ($cambiato === 0) {
                // Dirlo e' importante: la prima versione rispondeva "fatto, ho rimosso
                // quel glitch" anche quando non aveva rimosso niente, e chi legge non
                // ha modo di accorgersene se non ricontrollando.
                return ['response' => "Non ho capito cosa cambiare, quindi non ho toccato niente. "
                                    . "Prova a dirmelo citando la riga, tipo \"togli la riga sul dialetto\"."];
            }

            if ($diffA['rimosse'] > 0) {
                saveGroupMemoryBlock($groupId, 'osservato',
                    groupMemoryJoinLines($diffA['righe'])['testo'], $userName);
            }
            if ($diffB['aggiunte'] > 0 || $diffB['rimosse'] > 0) {
                saveGroupMemoryBlock($groupId, 'corretto',
                    groupMemoryJoinLines($diffB['righe'])['testo'], $userName);
            }

            $pezzi = [];
            if ($diffA['rimosse'] > 0) {
                $pezzi[] = $diffA['rimosse'] . ' riga' . ($diffA['rimosse'] > 1 ? 'he' : '') . ' tolta dai miei appunti';
            }
            if ($diffB['rimosse'] > 0) {
                $pezzi[] = $diffB['rimosse'] . ' tolta da quello che mi avevate dettato';
            }
            if ($diffB['aggiunte'] > 0) {
                $pezzi[] = $diffB['aggiunte'] . ' riga' . ($diffB['aggiunte'] > 1 ? 'he' : '') . ' aggiunta';
            }

            $aggiornata = getGroupMemory($groupId);
            $out = 'Fatto: ' . implode(', ', $pezzi) . ".\n";
            if (trim($aggiornata['corretto']) !== '') {
                $out .= "\nQuello che mi avete dettato voi, e che non tocco piu' da solo:\n\n"
                      . $aggiornata['corretto'];
            }
            return ['response' => $out];
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
