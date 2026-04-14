<?php
/**
 * Interfaccia web minimale per gestire le memorie utenti.
 *
 * Protezione: richiede una costante MEMORY_ADMIN_TOKEN definita in config.php
 * e accesso via ?token=<valore>. Se la costante non è definita, lo script si rifiuta.
 *
 * Definisci in config.php qualcosa tipo:
 *   define('MEMORY_ADMIN_TOKEN', 'una-stringa-lunga-e-casuale');
 *
 * URL esempi:
 *   admin_user_memory.php?token=XXX                          → lista utenti
 *   admin_user_memory.php?token=XXX&action=show&user_id=N    → mostra profilo
 *   admin_user_memory.php?token=XXX&action=run&user_id=N     → un batch
 *   admin_user_memory.php?token=XXX&action=run&user_id=N&exhaust=1&gpu=1
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/include/database.php';
require_once __DIR__ . '/include/user_memory.php';

// === AUTH ===
if (!defined('MEMORY_ADMIN_TOKEN')) {
    http_response_code(503);
    echo "MEMORY_ADMIN_TOKEN non definita in config.php. Aggiungi:\n";
    echo "  define('MEMORY_ADMIN_TOKEN', '<stringa-casuale-lunga>');\n";
    exit;
}
$tokenIn = $_GET['token'] ?? '';
if (!hash_equals(MEMORY_ADMIN_TOKEN, $tokenIn)) {
    http_response_code(403);
    echo "Forbidden";
    exit;
}

global $db;
$db = new SQLite3(DB_FILE);
initDatabase();

$action = $_GET['action'] ?? 'list';
$userId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
$exhaust = !empty($_GET['exhaust']);
$useGpu = !empty($_GET['gpu']);
$tokenQs = 'token=' . urlencode(MEMORY_ADMIN_TOKEN);

function h($s) { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }

// === ACTION: reset (vari scope) ===
$resetSqls = [
    'reset'         => "DELETE FROM memorie_utenti WHERE user_id = :uid",
    'reset_profilo' => "UPDATE memorie_utenti SET profilo = NULL, last_processed_msg_id = 0 WHERE user_id = :uid",
    'reset_bot'     => "UPDATE memorie_utenti SET bot_prompt = NULL, last_processed_bot_id = 0 WHERE user_id = :uid",
    'reset_nick'    => "UPDATE memorie_utenti SET nickname = NULL, last_processed_nick_id = 0 WHERE user_id = :uid",
];
if (isset($resetSqls[$action]) && $userId > 0) {
    $stmt = $db->prepare($resetSqls[$action]);
    $stmt->bindValue(':uid', $userId, SQLITE3_INTEGER);
    $stmt->execute();
    header("Location: ?$tokenQs&action=show&user_id=$userId");
    exit;
}

// === ACTION: run / run_bot / run_nick (estrazione, output streaming) ===
// Supporta due modalità:
//  - default (HTML standalone): pagina completa, usata se aperta direttamente
//  - ?embed=1 (text/plain): solo testo grezzo, usata via fetch() dalla pagina di dettaglio
if (in_array($action, ['run', 'run_bot', 'run_nick']) && $userId > 0) {
    $modeLabels = ['run' => 'Profilo', 'run_bot' => 'Istruzioni bot', 'run_nick' => 'Nickname'];
    $modeLabel = $modeLabels[$action];
    $embed = !empty($_GET['embed']);
    @ini_set('output_buffering', 'off');
    @ini_set('zlib.output_compression', 'off');
    while (ob_get_level() > 0) ob_end_flush();
    ob_implicit_flush(true);
    set_time_limit(0);
    ignore_user_abort(true);

    $profileExisting = getUserMemoryProfile($userId);
    $name = $profileExisting['user_name'] ?? getLatestUserName($userId) ?? "utente_$userId";

    if ($embed) {
        header('Content-Type: text/plain; charset=utf-8');
        header('X-Accel-Buffering: no');
        echo "Estrazione $modeLabel: $name (id=$userId)\n";
        echo "exhaust=" . ($exhaust ? 'SI' : 'NO') . " | gpu=" . ($useGpu ? 'SI' : 'NO') . "\n";
        echo str_repeat(" ", 1024) . "\n";
        @ob_flush(); @flush();
    } else {
        header('Content-Type: text/html; charset=utf-8');
        header('X-Accel-Buffering: no');
        echo "<!doctype html><meta charset=utf-8><title>$modeLabel $name</title>";
        echo "<style>body{font-family:monospace;background:#111;color:#eee;padding:20px}a{color:#7af}pre{white-space:pre-wrap}</style>";
        echo "<a href=\"?$tokenQs\">&larr; lista</a> | <a href=\"?$tokenQs&action=show&user_id=$userId\">scheda</a>";
        echo "<h2>$modeLabel: " . h($name) . " (id=$userId)</h2>";
        echo "<p>exhaust=" . ($exhaust ? 'SI' : 'NO') . " | gpu=" . ($useGpu ? 'SI' : 'NO') . "</p>";
        echo "<pre>";
        echo str_repeat(" ", 1024) . "\n";
        @ob_flush(); @flush();
    }

    if ($action === 'run_bot') {
        $result = $exhaust
            ? updateUserBotPromptExhaust($userId, true, $useGpu)
            : updateUserBotPrompt($userId, true, $useGpu);
        $resultKey = 'bot_prompt';
        $resultLabel = 'Istruzioni bot';
    } elseif ($action === 'run_nick') {
        $result = $exhaust
            ? updateUserNicknameExhaust($userId, true)
            : updateUserNickname($userId, true);
        $resultKey = 'nickname';
        $resultLabel = 'Nickname';
    } else {
        $result = $exhaust
            ? updateUserMemoryExhaust($userId, true, $useGpu)
            : updateUserMemory($userId, true, $useGpu);
        $resultKey = 'profilo';
        $resultLabel = 'Profilo';
    }

    echo "\n=== FATTO ===\n";
    echo "Status: " . ($embed ? $result['status'] : h($result['status'])) . "\n";
    if (isset($result['iterations'])) echo "Iterazioni: " . $result['iterations'] . "\n";
    if (!empty($result['detail'])) echo "Dettaglio: " . ($embed ? $result['detail'] : h($result['detail'])) . "\n";
    if (!empty($result[$resultKey])) {
        echo "\n$resultLabel:\n" . ($embed ? $result[$resultKey] : h($result[$resultKey])) . "\n";
    }

    if (!$embed) {
        echo "</pre>";
        echo "<p><a href=\"?$tokenQs&action=show&user_id=$userId\">torna alla scheda</a></p>";
    }
    exit;
}

// === ACTION: show (dettaglio profilo) ===
if ($action === 'show' && $userId > 0) {
    $p = getUserMemoryProfile($userId);
    $name = $p['user_name'] ?? getLatestUserName($userId) ?? "utente_$userId";
    $msgCount = countUserMessages($userId);
    $allUsers = listKnownUserIds(1);
    ?>
    <!doctype html><meta charset=utf-8><title>Profilo <?=h($name)?></title>
    <style>
    body{font-family:system-ui,sans-serif;background:#111;color:#eee;padding:20px;max-width:1200px;margin:auto}
    a{color:#7af}
    .layout{display:flex;gap:20px;align-items:flex-start}
    .main{flex:1;min-width:0}
    .sidebar{width:220px;flex-shrink:0;position:sticky;top:20px;max-height:90vh;overflow-y:auto;background:#1a1a1a;border-radius:6px;padding:10px}
    .sidebar h3{margin:0 0 8px 0;font-size:0.9em;color:#999}
    .sidebar a{display:block;padding:5px 8px;border-radius:3px;text-decoration:none;font-size:0.85em;margin-bottom:2px}
    .sidebar a:not(.current){color:#7af}
    .sidebar a:not(.current):hover{background:#222}
    .sidebar a.current{background:#246;color:#fff;font-weight:bold}
    .sidebar small{color:#666;font-size:0.8em}
    .profilo{background:#222;padding:15px;border-radius:6px;line-height:1.5}
    .btns a{display:inline-block;margin:5px 5px 5px 0;padding:8px 14px;background:#2a4;color:#fff;border-radius:4px;text-decoration:none;cursor:pointer;font-size:0.9em}
    .btns a.gpu{background:#a42}
    .btns a.simple{background:#246}
    .btns a.disabled{opacity:0.4;pointer-events:none}
    .btns a.danger{background:#a22}
    .btns a.stop{background:#f80}
    #runPanel{display:none;margin-top:20px;background:#000;border:1px solid #333;border-radius:6px;padding:15px}
    #runPanel h3{margin-top:0}
    #runOutput{background:#0a0a0a;color:#9f9;font-family:monospace;font-size:0.85em;padding:10px;border-radius:4px;max-height:400px;overflow-y:auto;white-space:pre-wrap;word-break:break-word}
    #runStatus{color:#fa5;font-weight:bold;margin:8px 0}
    #runStatus.done{color:#5f5}
    #runStatus.stopped{color:#f80}
    </style>
    <div class=layout>
    <div class=main>
    <p><a href="?<?=$tokenQs?>">&larr; lista</a></p>
    <h1><?=h($name)?> <small>(id=<?=$userId?>)</small></h1>
    <?php
    $stats = getUserActivityStats($userId);
    $msgTypes = countUserMessagesByType($userId);
    ?>
    <h3>Messaggi nel database</h3>
    <table style="width:auto;margin-bottom:15px">
        <tr><td>Generici (utente)</td><td style="padding-left:15px"><strong><?=$msgTypes['total']?></strong></td></tr>
        <tr><td>Interazioni col bot</td><td style="padding-left:15px"><strong><?=$msgTypes['bot']?></strong></td></tr>
        <tr><td>Rivolti all'utente (reply + menzioni nome)</td><td style="padding-left:15px"><strong><?=$msgTypes['nick']?></strong></td></tr>
    </table>
    <h3>Attività</h3>
    <ul>
        <li>Primo messaggio: <?=$stats['first_seen_ts'] ? date('Y-m-d', $stats['first_seen_ts']) : '<em>mai</em>'?>
            <?php if ($stats['days_active'] !== null): ?>
                (nel gruppo da <?=$stats['days_active']?> giorni)
            <?php endif; ?>
        </li>
        <li>Ultimo messaggio: <?=$stats['last_seen_ts'] ? date('Y-m-d H:i', $stats['last_seen_ts']) : '<em>mai</em>'?>
            <?php if ($stats['days_since_last_seen'] !== null): ?>
                (<?=$stats['days_since_last_seen']?> giorni fa)
            <?php endif; ?>
        </li>
        <li>Ultimi 7 giorni: <strong><?=$stats['msgs_last_7d']?></strong> &middot; Ultimi 30 giorni: <strong><?=$stats['msgs_last_30d']?></strong></li>
        <li>Anzianità: <em><?=h($stats['seniority_label'])?></em> &middot; Frequenza: <em><?=h($stats['activity_label'])?></em></li>
    </ul>
    <?php
    // Cursori
    $cursorTs = $p ? (int)$p['last_processed_msg_id'] : 0;
    $botCursorTs = $p ? (int)($p['last_processed_bot_id'] ?? 0) : 0;
    $nickCursorTs = $p ? (int)($p['last_processed_nick_id'] ?? 0) : 0;

    // Avanzamento profilo
    if ($p && $cursorTs > 0) {
        $stmt = $db->prepare("
            SELECT
                (SELECT COUNT(*) FROM contesto_chat
                 WHERE user_id = :uid AND timestamp <= :ts AND length(message_text) >= :ml)
                +
                (SELECT COUNT(*) FROM storico_messaggi
                 WHERE user_id = :uid AND timestamp <= :ts AND length(message_text) >= :ml)
            AS processed
        ");
        $stmt->bindValue(':uid', $userId, SQLITE3_INTEGER);
        $stmt->bindValue(':ts',  $cursorTs, SQLITE3_INTEGER);
        $stmt->bindValue(':ml',  USER_MEMORY_MIN_MSG_LENGTH, SQLITE3_INTEGER);
        $processedRow = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
        $processed = (int)($processedRow['processed'] ?? 0);
        $pct = $msgTypes['total'] > 0 ? round($processed * 100 / $msgTypes['total'], 1) : 0;
    }
    ?>

    <?php if ($p): ?>
        <p style="color:#999;font-size:0.9em">Ultimo aggiornamento: <?=h($p['last_updated'])?></p>
    <?php endif; ?>

    <h3 style="color:#7af">Profilo</h3>
    <p>
        <?php if ($p && $cursorTs > 0): ?>
            <strong>Avanzamento: <?=$processed?> / <?=$msgTypes['total']?> messaggi (<?=$pct?>%)</strong><br>
            <small>Cursore: <?=date('Y-m-d H:i', $cursorTs)?></small>
        <?php else: ?>
            <small>Cursore: <em>mai eseguito</em></small>
        <?php endif; ?>
    </p>
    <?php if ($p && !empty($p['profilo'])): ?>
        <div class=profilo><?=nl2br(h($p['profilo']))?></div>
    <?php else: ?>
        <p><em>Non ancora generato.</em></p>
    <?php endif; ?>

    <h3 style="color:#f80">Istruzioni Bot</h3>
    <p>
        <?php if ($botCursorTs > 0): ?>
            <strong>Scansionato fino a: <?=date('Y-m-d H:i', $botCursorTs)?></strong> &middot; <?=$msgTypes['bot']?> interazioni totali
        <?php else: ?>
            <small>Mai eseguito &middot; <?=$msgTypes['bot']?> interazioni disponibili</small>
        <?php endif; ?>
    </p>
    <?php if ($p && !empty($p['bot_prompt'])): ?>
        <div class="profilo" style="border-left:3px solid #f80"><?=nl2br(h($p['bot_prompt']))?></div>
    <?php else: ?>
        <p><em>Non ancora generato.</em></p>
    <?php endif; ?>

    <h3 style="color:#5d5">Nickname</h3>
    <p>
        <?php if ($nickCursorTs > 0): ?>
            <strong>Scansionato fino a: <?=date('Y-m-d H:i', $nickCursorTs)?></strong> &middot; <?=$msgTypes['nick']?> menzioni totali
        <?php else: ?>
            <small>Mai eseguito &middot; <?=$msgTypes['nick']?> menzioni disponibili</small>
        <?php endif; ?>
    </p>
    <?php if ($p && !empty($p['nickname'])): ?>
        <div class="profilo" style="border-left:3px solid #5d5"><?=nl2br(h($p['nickname']))?></div>
    <?php else: ?>
        <p><em>Non ancora generato.</em></p>
    <?php endif; ?>

    <h3>Azioni</h3>
    <p style="color:#999;font-size:0.9em">Profilo/Nickname: <code><?=OLLAMA_MODEL_LIGHT?></code> (light) &middot; Bot prompt: <code><?=OLLAMA_MODEL?></code> (full, thinking) &middot; Priorità: <code>LAZY</code></p>
    <p style="color:#999;font-size:0.85em"><em>Bootstrap: esegue batch ripetuti lato client (no timeout, interrompibile).</em></p>

    <?php
    $hasProfilo = $p && (!empty($p['profilo']) || $cursorTs > 0);
    $hasBot     = $p && (!empty($p['bot_prompt']) || $botCursorTs > 0);
    $hasNick    = $p && (!empty($p['nickname']) || $nickCursorTs > 0);
    ?>

    <h4 style="color:#7af;margin-bottom:5px">Profilo</h4>
    <div class="btns actionBtns">
        <a class=simple data-url="?<?=$tokenQs?>&action=run&user_id=<?=$userId?>" data-loop="0">1 batch</a>
        <a class=gpu    data-url="?<?=$tokenQs?>&action=run&user_id=<?=$userId?>" data-loop="1">Bootstrap</a>
        <?php if ($hasProfilo): ?>
            <a class=danger href="?<?=$tokenQs?>&action=reset_profilo&user_id=<?=$userId?>" onclick="return confirm('Resettare il profilo? Il testo verrà cancellato e il cursore tornerà a 0.')">Reset profilo</a>
        <?php endif; ?>
    </div>

    <h4 style="color:#f80;margin-bottom:5px">Istruzioni Bot</h4>
    <div class="btns actionBtns">
        <a class=simple data-url="?<?=$tokenQs?>&action=run_bot&user_id=<?=$userId?>" data-loop="0">1 batch</a>
        <a class=gpu    data-url="?<?=$tokenQs?>&action=run_bot&user_id=<?=$userId?>" data-loop="1">Bootstrap</a>
        <?php if ($hasBot): ?>
            <a class=danger href="?<?=$tokenQs?>&action=reset_bot&user_id=<?=$userId?>" onclick="return confirm('Resettare le istruzioni bot? Il testo verrà cancellato e il cursore tornerà a 0.')">Reset bot</a>
        <?php endif; ?>
    </div>

    <h4 style="color:#5d5;margin-bottom:5px">Nickname</h4>
    <div class="btns actionBtns">
        <a class=simple data-url="?<?=$tokenQs?>&action=run_nick&user_id=<?=$userId?>" data-loop="0">1 batch</a>
        <a class=gpu    data-url="?<?=$tokenQs?>&action=run_nick&user_id=<?=$userId?>" data-loop="1">Bootstrap</a>
        <?php if ($hasNick): ?>
            <a class=danger href="?<?=$tokenQs?>&action=reset_nick&user_id=<?=$userId?>" onclick="return confirm('Resettare il nickname? Il testo verrà cancellato e il cursore tornerà a 0.')">Reset nickname</a>
        <?php endif; ?>
    </div>

    <h4 style="margin-bottom:5px">Gestione</h4>
    <div class=btns>
        <?php if ($p): ?>
            <a class=danger href="?<?=$tokenQs?>&action=reset&user_id=<?=$userId?>" onclick="return confirm('Cancellare TUTTO per <?=h(addslashes($name))?>? Profilo, istruzioni bot, nickname e tutti i cursori verranno azzerati.')">Reset completo</a>
        <?php endif; ?>
    </div>

    <div id=runPanel>
        <h3>Esecuzione in corso</h3>
        <div id=runStatus>Avvio...</div>
        <div class=btns><a class=stop id=stopBtn>Stop</a></div>
        <pre id=runOutput></pre>
    </div>
    </div>

    <aside class=sidebar>
        <h3>Utenti (<?=count($allUsers)?>)</h3>
        <?php foreach ($allUsers as $au):
            $isCurrent = (int)$au['user_id'] === $userId;
        ?>
            <a href="?<?=$tokenQs?>&action=show&user_id=<?=$au['user_id']?>" class="<?=$isCurrent?'current':''?>">
                <?=h($au['user_name'])?> <small>(<?=$au['c']?>)</small>
            </a>
        <?php endforeach; ?>
    </aside>
    </div>

    <script>
    let stopRequested = false;
    let running = false;

    const panel  = document.getElementById('runPanel');
    const out    = document.getElementById('runOutput');
    const status = document.getElementById('runStatus');
    const stopBtn = document.getElementById('stopBtn');
    const allBtns = document.querySelectorAll('.actionBtns a');

    stopBtn.addEventListener('click', () => {
        if (!running) return;
        stopRequested = true;
        status.textContent = 'Stop richiesto, attendo fine batch corrente...';
    });

    async function runSingle(url) {
        const resp = await fetch(url + '&embed=1');
        if (!resp.ok) throw new Error('HTTP ' + resp.status);
        const reader = resp.body.getReader();
        const dec = new TextDecoder();
        let text = '';
        while (true) {
            const {value, done} = await reader.read();
            if (done) break;
            const chunk = dec.decode(value, {stream: true});
            text += chunk;
            out.textContent += chunk;
            out.scrollTop = out.scrollHeight;
        }
        const m = text.match(/Status:\s*(\S+)/);
        return m ? m[1] : '';
    }

    document.querySelectorAll('.actionBtns a[data-url]').forEach(btn => {
        btn.addEventListener('click', async (e) => {
            e.preventDefault();
            if (running) return;
            running = true;
            stopRequested = false;
            const url = btn.dataset.url;
            const isLoop = btn.dataset.loop === '1';

            allBtns.forEach(b => b.classList.add('disabled'));
            panel.style.display = 'block';
            out.textContent = '';
            status.classList.remove('done', 'stopped');

            try {
                if (!isLoop) {
                    status.textContent = 'Batch in corso...';
                    const st = await runSingle(url);
                    status.textContent = `Completato (status: ${st}). Ricarico...`;
                    status.classList.add('done');
                    setTimeout(() => location.reload(), 1500);
                } else {
                    let iter = 0;
                    while (!stopRequested) {
                        iter++;
                        status.textContent = `Batch ${iter} in corso...`;
                        out.textContent += `\n=== Batch ${iter} ===\n`;
                        const st = await runSingle(url);
                        if (st !== 'updated') {
                            status.textContent = `Fine dopo ${iter} batch (status: ${st}). Ricarico...`;
                            status.classList.add('done');
                            setTimeout(() => location.reload(), 1800);
                            return;
                        }
                    }
                    status.textContent = `Interrotto dopo ${iter} batch. Ricarico...`;
                    status.classList.add('stopped');
                    setTimeout(() => location.reload(), 1500);
                }
            } catch (err) {
                status.textContent = 'Errore: ' + err.message;
                allBtns.forEach(b => b.classList.remove('disabled'));
                running = false;
            }
        });
    });
    </script>
    <?php
    exit;
}

// === ACTION: list (default) ===
$users = listKnownUserIds(1); // Mostra anche quelli con pochi messaggi
?>
<!doctype html><meta charset=utf-8><title>Memoria utenti</title>
<style>
body{font-family:system-ui,sans-serif;background:#111;color:#eee;padding:20px;max-width:1100px;margin:auto}
a{color:#7af}
table{width:100%;border-collapse:collapse;margin-top:10px}
th,td{padding:8px;border-bottom:1px solid #333;text-align:left;vertical-align:top}
th{background:#222}
tbody tr{cursor:pointer}
tbody tr:hover{background:#1a1a1a}
.tag{display:inline-block;padding:2px 8px;border-radius:10px;font-size:0.8em;background:#2a4;color:#fff}
.tag.no{background:#555}
.profilo{color:#aaa;font-size:0.9em;max-width:600px}
</style>
<h1>Memoria utenti</h1>
<p>Totale utenti con almeno 1 messaggio identificato: <?=count($users)?></p>
<table>
<thead><tr><th>Nome</th><th>user_id</th><th>Msg</th><th>Profilo</th></tr></thead>
<tbody>
<?php foreach ($users as $u):
    $p = getUserMemoryProfile($u['user_id']);
    $hasProfile = $p !== null;
    $href = "?$tokenQs&action=show&user_id={$u['user_id']}";
?>
<tr onclick="location='<?=$href?>'">
    <td><strong><?=h($u['user_name'])?></strong></td>
    <td><code><?=$u['user_id']?></code></td>
    <td><?=$u['c']?></td>
    <td>
        <?php if ($hasProfile): ?>
            <span class=tag>OK</span>
            <div class=profilo><?=h(mb_substr($p['profilo'], 0, 200))?><?=mb_strlen($p['profilo'])>200?'...':''?></div>
        <?php else: ?>
            <span class="tag no">vuoto</span>
        <?php endif; ?>
    </td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
