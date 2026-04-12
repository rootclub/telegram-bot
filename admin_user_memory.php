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

// === ACTION: reset (cancella profilo, cursore torna a 0) ===
if ($action === 'reset' && $userId > 0) {
    $stmt = $db->prepare("DELETE FROM memorie_utenti WHERE user_id = :uid");
    $stmt->bindValue(':uid', $userId, SQLITE3_INTEGER);
    $stmt->execute();
    header("Location: ?$tokenQs&action=show&user_id=$userId");
    exit;
}

// === ACTION: run (estrazione, output streaming) ===
// Supporta due modalità:
//  - default (HTML standalone): pagina completa, usata se aperta direttamente
//  - ?embed=1 (text/plain): solo testo grezzo, usata via fetch() dalla pagina di dettaglio
if ($action === 'run' && $userId > 0) {
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
        echo "Estrazione: $name (id=$userId)\n";
        echo "exhaust=" . ($exhaust ? 'SI' : 'NO') . " | gpu=" . ($useGpu ? 'SI' : 'NO') . "\n";
        // Padding per superare il buffer iniziale di alcuni browser/proxy
        echo str_repeat(" ", 1024) . "\n";
        @ob_flush(); @flush();
    } else {
        header('Content-Type: text/html; charset=utf-8');
        header('X-Accel-Buffering: no');
        echo "<!doctype html><meta charset=utf-8><title>Estrazione $name</title>";
        echo "<style>body{font-family:monospace;background:#111;color:#eee;padding:20px}a{color:#7af}pre{white-space:pre-wrap}</style>";
        echo "<a href=\"?$tokenQs\">&larr; lista</a> | <a href=\"?$tokenQs&action=show&user_id=$userId\">profilo attuale</a>";
        echo "<h2>Estrazione: " . h($name) . " (id=$userId)</h2>";
        echo "<p>exhaust=" . ($exhaust ? 'SI' : 'NO') . " | gpu=" . ($useGpu ? 'SI' : 'NO') . "</p>";
        echo "<pre>";
        echo str_repeat(" ", 1024) . "\n";
        @ob_flush(); @flush();
    }

    if ($exhaust) {
        $result = updateUserMemoryExhaust($userId, true, $useGpu);
    } else {
        $result = updateUserMemory($userId, true, $useGpu);
    }

    echo "\n=== FATTO ===\n";
    echo "Status: " . ($embed ? $result['status'] : h($result['status'])) . "\n";
    if (isset($result['iterations'])) echo "Iterazioni: " . $result['iterations'] . "\n";
    if (!empty($result['detail'])) echo "Dettaglio: " . ($embed ? $result['detail'] : h($result['detail'])) . "\n";
    if (!empty($result['profilo'])) {
        echo "\nProfilo:\n" . ($embed ? $result['profilo'] : h($result['profilo'])) . "\n";
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
    ?>
    <!doctype html><meta charset=utf-8><title>Profilo <?=h($name)?></title>
    <style>
    body{font-family:system-ui,sans-serif;background:#111;color:#eee;padding:20px;max-width:900px;margin:auto}
    a{color:#7af}
    .profilo{background:#222;padding:15px;border-radius:6px;line-height:1.5}
    .btns a{display:inline-block;margin:5px;padding:8px 14px;background:#2a4;color:#fff;border-radius:4px;text-decoration:none;cursor:pointer}
    .btns a.gpu{background:#a42}
    .btns a.simple{background:#246}
    .btns a.disabled{opacity:0.4;pointer-events:none}
    .btns a.danger{background:#a22}
    #runPanel{display:none;margin-top:20px;background:#000;border:1px solid #333;border-radius:6px;padding:15px}
    #runPanel h3{margin-top:0}
    #runOutput{background:#0a0a0a;color:#9f9;font-family:monospace;font-size:0.85em;padding:10px;border-radius:4px;max-height:400px;overflow-y:auto;white-space:pre-wrap;word-break:break-word}
    #runStatus{color:#fa5;font-weight:bold;margin:8px 0}
    #runStatus.done{color:#5f5}
    </style>
    <p><a href="?<?=$tokenQs?>">&larr; lista</a></p>
    <h1><?=h($name)?> <small>(id=<?=$userId?>)</small></h1>
    <p>Messaggi totali utili nel database: <strong><?=$msgCount?></strong></p>
    <?php
    $stats = getUserActivityStats($userId);
    ?>
    <h3>Attività (calcolata runtime)</h3>
    <ul>
        <li>Totale messaggi: <strong><?=$stats['total_messages']?></strong></li>
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
        <li>Anzianità: <em><?=h($stats['seniority_label'])?></em></li>
        <li>Frequenza: <em><?=h($stats['activity_label'])?></em></li>
    </ul>
    <?php if ($p):
        // Conta i messaggi con timestamp <= cursore: rappresentano la "porzione di vita"
        // dell'utente che il profilo ha già metabolizzato.
        $cursorTs = (int)$p['last_processed_msg_id'];
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
        $pct = $msgCount > 0 ? round($processed * 100 / $msgCount, 1) : 0;
    ?>
        <p>Profilo aggiornato il <?=h($p['last_updated'])?></p>
        <p><strong>Avanzamento estrazione: <?=$processed?> / <?=$msgCount?> messaggi (<?=$pct?>%)</strong>
           <br><small>Cursore: <?=date('Y-m-d H:i', $cursorTs)?> &middot; ts=<?=$cursorTs?></small></p>
        <div class=profilo><?=nl2br(h($p['profilo']))?></div>
    <?php else: ?>
        <p><em>Nessun profilo ancora generato.</em></p>
    <?php endif; ?>
    <h3>Azioni</h3>
    <p style="color:#999;font-size:0.9em">Modello leggero = <code><?=OLLAMA_MODEL_LIGHT?></code> &middot; Modello principale = <code><?=OLLAMA_MODEL?></code></p>
    <div class=btns id=actionBtns>
        <a class=simple data-url="?<?=$tokenQs?>&action=run&user_id=<?=$userId?>">Estrai 1 batch (modello leggero)</a>
        <a class=simple data-url="?<?=$tokenQs?>&action=run&user_id=<?=$userId?>&gpu=1">Estrai 1 batch (modello principale)</a>
        <a class=gpu    data-url="?<?=$tokenQs?>&action=run&user_id=<?=$userId?>&exhaust=1">Bootstrap completo (modello leggero, exhaust)</a>
        <a class=gpu    data-url="?<?=$tokenQs?>&action=run&user_id=<?=$userId?>&exhaust=1&gpu=1">Bootstrap completo (modello principale, exhaust)</a>
        <?php if ($p): ?>
            <a class=danger href="?<?=$tokenQs?>&action=reset&user_id=<?=$userId?>" onclick="return confirm('Cancellare il profilo di <?=h(addslashes($name))?> e azzerare il cursore? Il prossimo bootstrap rielaborerà tutti i messaggi da capo.')">Reset profilo</a>
        <?php endif; ?>
    </div>

    <div id=runPanel>
        <h3>Esecuzione in corso</h3>
        <div id=runStatus>Avvio...</div>
        <pre id=runOutput></pre>
    </div>

    <script>
    document.querySelectorAll('#actionBtns a[data-url]').forEach(btn => {
        btn.addEventListener('click', async (e) => {
            e.preventDefault();
            const url = btn.dataset.url + '&embed=1';
            const panel = document.getElementById('runPanel');
            const out = document.getElementById('runOutput');
            const status = document.getElementById('runStatus');
            const allBtns = document.querySelectorAll('#actionBtns a');

            allBtns.forEach(b => b.classList.add('disabled'));
            panel.style.display = 'block';
            out.textContent = '';
            status.textContent = 'In esecuzione...';
            status.classList.remove('done');

            try {
                const resp = await fetch(url);
                if (!resp.ok) throw new Error('HTTP ' + resp.status);
                const reader = resp.body.getReader();
                const dec = new TextDecoder();
                while (true) {
                    const {value, done} = await reader.read();
                    if (done) break;
                    out.textContent += dec.decode(value, {stream: true});
                    out.scrollTop = out.scrollHeight;
                }
                status.textContent = 'Completato. Ricarico la scheda...';
                status.classList.add('done');
                setTimeout(() => location.reload(), 1500);
            } catch (err) {
                status.textContent = 'Errore: ' + err.message;
                allBtns.forEach(b => b.classList.remove('disabled'));
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
tr:hover{background:#1a1a1a}
.tag{display:inline-block;padding:2px 8px;border-radius:10px;font-size:0.8em;background:#2a4;color:#fff}
.tag.no{background:#555}
.profilo{color:#aaa;font-size:0.9em;max-width:500px}
.actions a{margin-right:8px}
</style>
<h1>Memoria utenti</h1>
<p>Totale utenti con almeno 1 messaggio identificato: <?=count($users)?></p>
<table>
<tr><th>Nome</th><th>user_id</th><th>Msg</th><th>Profilo</th><th>Azioni</th></tr>
<?php foreach ($users as $u):
    $p = getUserMemoryProfile($u['user_id']);
    $hasProfile = $p !== null;
?>
<tr>
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
    <td class=actions>
        <a href="?<?=$tokenQs?>&action=show&user_id=<?=$u['user_id']?>">apri</a>
        <a href="?<?=$tokenQs?>&action=run&user_id=<?=$u['user_id']?>&exhaust=1&gpu=1">bootstrap</a>
    </td>
</tr>
<?php endforeach; ?>
</table>
