<?php
/**
 * Agente Profile Self — racconta all'utente il profilo estratto dal cron notturno.
 * Solo in chat privata: nei gruppi risponde con un nudge nello stile del bot.
 */
require_once dirname(__DIR__, 2) . '/user_memory.php';

return [
    'id' => 'profile_self',
    'description' => "L'utente chiede cosa il bot pensa di lui/lei, come lo vede, che idea si è fatto, chi è per il bot, di raccontargli il suo profilo (es. 'cosa pensi di me?', 'dimmi di me', 'come mi vedi?', 'che idea ti sei fatto di me?', 'chi sono per te?', 'descrivimi')",
    'parameters' => [],
    'sends_own_response' => true,
    'handler' => function (array $ctx, array $params): ?array {
        $userName = $ctx['firstName'] ?? $ctx['userName'] ?? 'tu';
        $persona = rootbotPersona();
        $userMessage = sanitizeMessageForPrompt($ctx['message']);

        // Caso 1: non siamo in privato -> nudge nello stile rootbot, profilo mai esposto
        if ($ctx['chatType'] !== 'private') {
            $prompt = <<<PROMPT
{$persona}

### SITUAZIONE ###
{$userName} ti ha chiesto in un GRUPPO cosa pensi di lui/lei, o di raccontargli il suo profilo. Rifiuti elegantemente perché queste cose le dici solo in chat privata (DM).

### MESSAGGIO DI {$userName} ###
{$userMessage}

Rispondi con UNA battuta breve (max 200 caratteri), nel tuo stile sarcastico-affettuoso, invitandolo a scriverti in privato. Non rivelare nulla del profilo. Italiano.
IMPORTANTE: Scrivi SOLO la tua risposta, senza prefissi come "rootbot:" o simili.
PROMPT;

            $result = callOllamaChatViaQBertWithTyping(
                OLLAMA_MODEL_LIGHT,
                $prompt,
                (int)$ctx['chatID'],
                ollamaOptions(OLLAMA_MODEL_LIGHT_GPU),
                false,
                QBertClient::PRIORITY_NORMAL
            );
            $response = trim(stripThinkingTags($result['response'] ?? ''));
            if ($response === '') {
                $response = "Di queste cose non parlo in pubblico, scrivimi in DM.";
            }
            return ['response' => $response];
        }

        // Caso 2: chat privata -> recupera profilo
        $profile = getUserMemoryProfile($ctx['fromId']);
        $profiloRaw = trim($profile['profilo'] ?? '');
        if ($profiloRaw === '') {
            return ['response' => "Non ho ancora abbastanza materiale su di te per dire qualcosa di sensato. Continua a scrivere nel gruppo e tra qualche notte ne riparliamo."];
        }

        $profiloSanitized = sanitizeMessageForPrompt($profiloRaw, true);
        $nickname = trim($profile['nickname'] ?? '');
        $nicknameSection = $nickname !== '' ? "\nNICKNAME AFFETTUOSO CHE GLI HAI DATO: {$nickname}" : '';

        $prompt = <<<PROMPT
{$persona}

### IDEA CHE TI SEI FATTO DI {$userName} ###
Osservandolo nel gruppo nel tempo, ti sei fatto quest'idea di lui/lei:

{$profiloSanitized}{$nicknameSection}

### MESSAGGIO DI {$userName} A CUI DEVI RISPONDERE ###
{$userMessage}

Raccontagli cosa pensi di lui/lei basandoti sull'idea che ti sei fatto, nel tuo stile (sarcastico, affettuoso, curioso). Regole:
- Scegli 3-4 tratti salienti e tessili insieme in uno-due paragrafi, non fare elenchi puntati.
- NON citare "il profilo", "i dati", "le informazioni che ho": parla come se ti fossi fatto un'idea osservandolo.
- NON inventare fatti non presenti nel testo sopra.
- Italiano, max ~1500 caratteri.
IMPORTANTE: Scrivi SOLO la tua risposta, senza prefissi come "rootbot:" o simili.
PROMPT;

        $result = callOllamaChatViaQBertWithTyping(
            OLLAMA_MODEL,
            $prompt,
            (int)$ctx['chatID'],
            ollamaOptions(OLLAMA_MODEL_GPU),
            false,
            QBertClient::PRIORITY_NORMAL
        );

        $response = trim(stripThinkingTags($result['response'] ?? ''));
        if ($response === '') {
            return ['response' => "Ho provato a mettere insieme un pensiero su di te ma mi si sono incrociati i fili. Riprova tra poco."];
        }
        return ['response' => $response];
    },
];
