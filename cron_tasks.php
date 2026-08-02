<?php
/**
 * Scheduler dei lavori batch del bot.
 *
 * Crontab: ogni 15 minuti -> "0,15,30,45 * * * * /usr/bin/php /path/to/cron_tasks.php"
 *
 * Perché un cron a sé e non un innesto su cron_dj.php: le run in cui il DJ esce
 * subito (troppo presto, gruppo fermo, dado) sono la maggioranza — 33 su 46 in una
 * mattinata — e viene la tentazione di riempirle. Ma cron_dj tiene un lock con
 * scadenza a 300s, e lavoro LLM lungo appeso lì dentro sopprimerebbe le run
 * successive del DJ; e soprattutto si otterrebbe un accoppiamento assurdo da
 * diagnosticare fra sei mesi, del tipo "il tal lavoro non gira perché il DJ ha
 * postato di recente". La condizione giusta per far girare un lavoro batch è il suo
 * arretrato, non il silenzio di un'altra feature.
 *
 * La GPU non va contesa a mano: tutti i task girano in QBertClient::PRIORITY_LAZY,
 * che è il meccanismo previsto per "fai questa cosa quando la coda è libera".
 *
 * Uso da riga di comando:
 *   php cron_tasks.php                    esegue i task scaduti
 *   php cron_tasks.php --list             elenca i task e quando toccherà a loro
 *   php cron_tasks.php --task=nome        esegue solo quel task, se scaduto
 *   php cron_tasks.php --task=nome --force lo esegue comunque
 *
 * Via HTTP gli stessi comandi passano in query string (?list=1, ?task=, ?force=1),
 * con il token di diag nell'header Authorization.
 */

set_time_limit(0);
ini_set('max_execution_time', 0);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/include/logger.php';

// Il file sta nella webroot come tutti i cron del progetto, quindi è raggiungibile
// via HTTP: senza guardia, chiunque conosca l'URL potrebbe far girare i task a
// comando. Da riga di comando passa liberamente; via HTTP serve il token di diag,
// nell'header Authorization e mai in query string (finirebbe nei log di access).
if (PHP_SAPI !== 'cli') {
    $hdr = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if ($hdr === '' && function_exists('getallheaders')) {
        foreach (getallheaders() as $k => $v) {
            if (strcasecmp($k, 'Authorization') === 0) { $hdr = $v; break; }
        }
    }
    $token = stripos($hdr, 'Bearer ') === 0 ? trim(substr($hdr, 7)) : '';
    if (!defined('DIAG_TOKEN') || DIAG_TOKEN === '' || !hash_equals((string)DIAG_TOKEN, $token)) {
        http_response_code(403);
        logLine('tasks', 'accesso HTTP negato da ' . ($_SERVER['REMOTE_ADDR'] ?? '?'));
        exit('forbidden');
    }
    header('Content-Type: text/plain; charset=utf-8');
}
require_once __DIR__ . '/include/database.php';
require_once __DIR__ . '/include/api.php';
require_once __DIR__ . '/include/ai.php';
require_once __DIR__ . '/include/group_memory.php';

$db = new SQLite3(__DIR__ . '/' . DB_FILE);
initDatabase();

// Le tabelle degli agenti le crea initAgentSchemas(), che pero' gira solo dove e'
// caricato il dispatcher (bot.php). Qui il registro degli agenti non serve, quindi
// lo schema della memoria di gruppo lo chiamiamo diretto: e' idempotente.
initGroupMemorySchema($db);

// Oltre questo tempo non si avviano altri task: il tick è ogni 15 minuti e due
// esecuzioni sovrapposte non servono a nessuno. Un task già partito finisce.
define('TASKS_TICK_BUDGET', 600);

/**
 * Registro dei task.
 *
 * 'ogni'  = secondi minimi fra due esecuzioni. Non è una pianificazione precisa:
 *           il tick batte ogni 15 minuti, quindi il valore viene arrotondato per
 *           eccesso al tick successivo.
 * 'run'   = callable che ritorna un array con almeno 'status' e 'detail'.
 */
$TASKS = [
    'group_memory' => [
        'descrizione' => 'appunti del bot sul gruppo',
        // Un'ora: sono fatti e convenzioni, cose che cambiano lentamente. Piu' spesso
        // vorrebbe dire riscrivere il prompt di sistema di continuo per niente.
        'ogni'        => 3600,
        'run'         => fn(): array => updateGroupMemory(MAIN_GROUP_ID),
    ],
];

// --- parametri -------------------------------------------------------------
$soloTask = null;
$force    = false;

// Via HTTP gli stessi comandi arrivano in query string (?task=...&force=1&list=1):
// $argv non esiste, e senza questo non ci sarebbe modo di provare un task da remoto.
// L'accesso è già filtrato dal token qui sopra.
if (PHP_SAPI !== 'cli') {
    $argv = ['cron_tasks.php'];
    if (isset($_GET['list']))            { $argv[] = '--list'; }
    if (!empty($_GET['task']))           { $argv[] = '--task=' . preg_replace('/[^a-z0-9_-]/i', '', (string)$_GET['task']); }
    if (!empty($_GET['force']))          { $argv[] = '--force'; }
    if (!empty($_GET['reset_group_memory'])) { $argv[] = '--reset-group-memory'; }
}

foreach (array_slice($argv ?? [], 1) as $arg) {
    if ($arg === '--force') {
        $force = true;
    } elseif ($arg === '--reset-group-memory') {
        // Manutenzione: riparte da appunti vuoti e cursore a zero. Il blocco corretto
        // dall'amministratore NON viene toccato, che e' tutto il punto di tenerlo separato.
        global $db;
        $db->exec("UPDATE memoria_gruppo SET osservato = '', last_processed_id = 0");
        logLine('group_memory', 'RESET: appunti osservati e cursore azzerati (blocco corretto intatto)');
        echo "appunti osservati azzerati, blocco corretto intatto\n";
        exit(0);
    } elseif ($arg === '--list') {
        foreach ($TASKS as $nome => $t) {
            $last = (int)getBotState("task_last_{$nome}", 0);
            $manca = $last === 0 ? 0 : max(0, ($last + $t['ogni']) - time());
            printf("%-12s %-45s ogni %4ds   %s\n", $nome, $t['descrizione'], $t['ogni'],
                $manca === 0 ? 'pronto' : "fra {$manca}s");
        }
        exit(0);
    } elseif (str_starts_with($arg, '--task=')) {
        $soloTask = substr($arg, 7);
    }
}

if ($soloTask !== null && !isset($TASKS[$soloTask])) {
    fwrite(STDERR, "task sconosciuto: {$soloTask}\n");
    exit(1);
}

// --- lock ------------------------------------------------------------------
// Stessa idea del lock di cron_dj, ma la soglia di scadenza è legata al budget del
// tick: se un giro precedente è morto lasciando il file, dopo TASKS_TICK_BUDGET il
// lock si considera abbandonato e si riparte.
$lockFile = '/tmp/rootbot_tasks.lock';
if (is_file($lockFile)) {
    $age = time() - (int)@file_get_contents($lockFile);
    if ($age < TASKS_TICK_BUDGET) {
        logLine('tasks', "già in esecuzione da {$age}s, esco");
        exit(0);
    }
    logLine('tasks', "lock abbandonato da {$age}s, lo ignoro");
}
file_put_contents($lockFile, time());

$deadline = time() + TASKS_TICK_BUDGET;
$eseguiti = 0;

foreach ($TASKS as $nome => $task) {
    if ($soloTask !== null && $nome !== $soloTask) {
        continue;
    }

    if (time() >= $deadline) {
        // Va detto: un task che non parte perché il budget è finito non è un task
        // che non aveva niente da fare, ed è esattamente il tipo di differenza che
        // sparendo dai log rende poi incomprensibile un arretrato che non cala.
        logLine('tasks', "budget del tick esaurito, '{$nome}' non eseguito");
        continue;
    }

    $lastRun = (int)getBotState("task_last_{$nome}", 0);
    $atteso  = $lastRun + (int)$task['ogni'];

    if (!$force && time() < $atteso) {
        continue;
    }

    $inizio = time();
    logLine('tasks', "avvio '{$nome}' ({$task['descrizione']})");

    try {
        $esito = $task['run']();
        $durata = time() - $inizio;
        $status = is_array($esito) ? ($esito['status'] ?? '?') : '?';
        $detail = is_array($esito) ? ($esito['detail'] ?? '') : '';
        logLine('tasks', sprintf("'%s' -> %s%s (%ds)", $nome, $status, $detail !== '' ? " — {$detail}" : '', $durata));
    } catch (Throwable $e) {
        // Un task che esplode non deve impedire agli altri di girare, ma nemmeno
        // passare inosservato: il timestamp si aggiorna comunque, altrimenti un
        // errore permanente riproverebbe a ogni tick per sempre.
        logLine('tasks', "'{$nome}' ERRORE: " . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
    }

    setBotState("task_last_{$nome}", time());
    $eseguiti++;
}

if ($eseguiti === 0) {
    logLine('tasks', 'nessun task scaduto');
}

@unlink($lockFile);
