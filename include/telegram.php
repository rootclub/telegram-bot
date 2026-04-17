<?php
require_once __DIR__ . '/logger.php';

/**
 * Layer semantico sopra api.php per invio messaggi Telegram.
 *
 * Risolve in un unico punto:
 *  - escape HTML automatico su parse_mode=HTML (no più injection via nomi utente/output LLM)
 *  - retry strutturato (reply_to mancante, parse entities, 429)
 *  - troncamento 4096 char
 *  - risposta errore LLM uniforme
 *
 * Regola d'uso:
 *  - testo utente/LLM crudo  -> sendTelegramMessage($chatId, $text, ['parse_mode'=>'HTML'])
 *    (viene escapato automaticamente)
 *  - testo con markup sicuro -> costruire via tgHtml("Ciao <b>{name}</b>", ['name'=>$u])
 *    restituisce TelegramHtml, che sendTelegramMessage NON riescapa.
 *
 * In Step A questo file viene solo caricato, nessun callsite lo usa ancora.
 */

/** Marker: una stringa già safe per parse_mode=HTML. */
final class TelegramHtml {
    public string $raw;
    public function __construct(string $raw) { $this->raw = $raw; }
    public function __toString(): string { return $this->raw; }
}

/** Escape minimo richiesto da Telegram HTML: &, <, >. ENT_QUOTES per sicurezza attributi. */
function escapeHtmlForTelegram(string $text): string {
    return htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/** Escape e fallback per first_name/last_name Telegram. Ritorna TelegramHtml. */
function escapeUserName(?string $name, string $fallback = 'Utente'): TelegramHtml {
    $name = trim((string)$name);
    if ($name === '') {
        $name = $fallback;
    }
    if (mb_strlen($name) > 64) {
        $name = mb_substr($name, 0, 64);
    }
    return new TelegramHtml(escapeHtmlForTelegram($name));
}

/**
 * Template HTML con placeholder {key}. I tag letterali nel template sono trusted.
 * Le variabili in $vars vengono escapate, tranne quelle già TelegramHtml.
 * Es: tgHtml('<b>{name}</b> ha scritto: {msg}', ['name'=>$user, 'msg'=>$text])
 */
function tgHtml(string $template, array $vars = []): TelegramHtml {
    $replacements = [];
    foreach ($vars as $key => $value) {
        $placeholder = '{' . $key . '}';
        if ($value instanceof TelegramHtml) {
            $replacements[$placeholder] = $value->raw;
        } else {
            $replacements[$placeholder] = escapeHtmlForTelegram((string)$value);
        }
    }
    return new TelegramHtml(strtr($template, $replacements));
}

/**
 * Invio principale. Ritorna:
 *   ['ok'=>bool, 'message_id'=>?int, 'error_code'=>?int, 'description'=>?string, 'raw'=>?array]
 *
 * Opzioni riconosciute: tutte quelle di Telegram sendMessage + 'no_retry' (bool).
 * - Se $text è TelegramHtml, parse_mode default = 'HTML' e non viene re-escapato.
 * - Se $text è string e parse_mode='HTML', il testo viene escapato automaticamente.
 */
function sendTelegramMessage(int|string $chatId, string|TelegramHtml $text, array $options = []): array {
    $noRetry = !empty($options['no_retry']);
    unset($options['no_retry']);

    if ($text instanceof TelegramHtml) {
        $textStr = $text->raw;
        if (!isset($options['parse_mode'])) {
            $options['parse_mode'] = 'HTML';
        }
    } else {
        $textStr = $text;
        if (($options['parse_mode'] ?? null) === 'HTML') {
            $textStr = escapeHtmlForTelegram($textStr);
        }
    }

    // Telegram limit: 4096 caratteri (non byte). Tronca con ellissi.
    if (mb_strlen($textStr) > 4096) {
        $originalLen = mb_strlen($textStr);
        $textStr = mb_substr($textStr, 0, 4095) . '…';
        tgLog("WARN truncated chat=$chatId from=$originalLen");
    }

    $payload = $options;
    $payload['chat_id'] = $chatId;
    $payload['text'] = $textStr;

    return _tgSendWithRetry('sendMessage', $payload, $noRetry);
}

/**
 * Modifica testo messaggio esistente. Stessa semantica di sendTelegramMessage.
 * Opzioni aggiuntive: inline_message_id (alternativa a chat_id+message_id).
 */
function editTelegramMessage(int|string $chatId, int $messageId, string|TelegramHtml $text, array $options = []): array {
    $noRetry = !empty($options['no_retry']);
    unset($options['no_retry']);

    if ($text instanceof TelegramHtml) {
        $textStr = $text->raw;
        if (!isset($options['parse_mode'])) {
            $options['parse_mode'] = 'HTML';
        }
    } else {
        $textStr = $text;
        if (($options['parse_mode'] ?? null) === 'HTML') {
            $textStr = escapeHtmlForTelegram($textStr);
        }
    }

    if (mb_strlen($textStr) > 4096) {
        $textStr = mb_substr($textStr, 0, 4095) . '…';
        tgLog("WARN edit truncated chat=$chatId msg=$messageId");
    }

    $payload = $options;
    $payload['chat_id'] = $chatId;
    $payload['message_id'] = $messageId;
    $payload['text'] = $textStr;

    return _tgSendWithRetry('editMessageText', $payload, $noRetry);
}

/**
 * Risposta uniforme quando un agente LLM fallisce. Sostituisce la stringa-sentinel
 * storica di ai.php ("Si è verificato un errore..."): d'ora in poi il dispatcher
 * distingue l'errore tramite ['ok'=>false,'is_error'=>true] nel risultato.
 */
function replyLlmError(int|string $chatId, ?int $replyTo = null, ?string $customMsg = null): array {
    $msg = $customMsg ?? "Ho perso il filo, riprova tra un po'.";
    $options = [];
    if ($replyTo !== null) {
        $options['reply_to_message_id'] = $replyTo;
    }
    return sendTelegramMessage($chatId, $msg, $options);
}

/**
 * Estrae la response da un risultato LLM. Se è null/vuoto:
 *  - con $chatId: invia messaggio di errore all'utente e ritorna null
 *  - senza $chatId: ritorna null (caller decide)
 * Non applica stripThinkingTags: resta responsabilità del caller.
 */
function llmResponseOrError(?array $llmResult, int|string|null $chatId = null, ?int $replyTo = null, ?string $customMsg = null): ?string {
    if (!is_array($llmResult)) {
        if ($chatId !== null) replyLlmError($chatId, $replyTo, $customMsg);
        return null;
    }
    $response = trim((string)($llmResult['response'] ?? ''));
    if ($response === '') {
        if ($chatId !== null) replyLlmError($chatId, $replyTo, $customMsg);
        return null;
    }
    return $response;
}

/**
 * Core retry. Gestisce:
 *  - curl failure (ritorno false da makeAPIRequest) -> fail immediato
 *  - 400 "reply ... not found" -> rimuove reply_to_message_id, ritenta
 *  - 400 "can't parse entities" -> rimuove parse_mode, ritenta plain
 *  - 429 retry_after (cap 5s) -> sleep + ritenta
 *  - altri errori -> fail
 */
function _tgSendWithRetry(string $method, array $payload, bool $noRetry): array {
    $maxAttempts = $noRetry ? 1 : 4;
    $attempt = 0;
    $chatIdLog = (string)($payload['chat_id'] ?? '?');

    while (true) {
        $attempt++;
        $raw = makeAPIRequest($method, $payload);

        if ($raw === false) {
            tgLog("ERROR curl_failed chat=$chatIdLog method=$method");
            return ['ok' => false, 'message_id' => null, 'error_code' => null, 'description' => 'curl failed', 'raw' => null];
        }

        if (!empty($raw['ok'])) {
            return [
                'ok' => true,
                'message_id' => $raw['result']['message_id'] ?? null,
                'error_code' => null,
                'description' => null,
                'raw' => $raw,
            ];
        }

        $errCode = $raw['error_code'] ?? null;
        $desc = (string)($raw['description'] ?? '');
        $descLower = strtolower($desc);
        $retryAfter = (int)($raw['parameters']['retry_after'] ?? 0);

        if ($noRetry || $attempt >= $maxAttempts) {
            tgLog("ERROR final chat=$chatIdLog method=$method err=$errCode desc=" . substr($desc, 0, 160));
            return [
                'ok' => false, 'message_id' => null,
                'error_code' => $errCode, 'description' => $desc,
                'raw' => $raw,
            ];
        }

        $retried = false;

        if ($errCode === 400
            && isset($payload['reply_to_message_id'])
            && strpos($descLower, 'reply') !== false
            && strpos($descLower, 'not found') !== false
        ) {
            unset($payload['reply_to_message_id']);
            tgLog("RETRY no_reply chat=$chatIdLog");
            $retried = true;
        } elseif ($errCode === 400
            && isset($payload['parse_mode'])
            && (strpos($descLower, "can't parse") !== false
                || strpos($descLower, 'parse entities') !== false
                || strpos($descLower, 'unsupported start tag') !== false)
        ) {
            unset($payload['parse_mode']);
            tgLog("RETRY plain chat=$chatIdLog desc=" . substr($desc, 0, 120));
            $retried = true;
        } elseif ($errCode === 429 && $retryAfter > 0 && $retryAfter <= 10) {
            $sleepFor = min($retryAfter, 5);
            sleep($sleepFor);
            tgLog("RETRY 429 chat=$chatIdLog sleep=$sleepFor");
            $retried = true;
        }

        if (!$retried) {
            tgLog("ERROR unhandled chat=$chatIdLog method=$method err=$errCode desc=" . substr($desc, 0, 160));
            return [
                'ok' => false, 'message_id' => null,
                'error_code' => $errCode, 'description' => $desc,
                'raw' => $raw,
            ];
        }
    }
}

/** Logger dedicato: logs/telegram.log (via logger centrale, rotazione inclusa). */
function tgLog(string $msg): void {
    logLine('telegram', $msg);
}
