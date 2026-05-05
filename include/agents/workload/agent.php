<?php
/**
 * Agente Workload — risponde a "sei libero?", "sei occupato?", "che stai facendo?"
 * Legge lo stato QBert (coda, job in esecuzione, GPU) + stats ultimi 30 min,
 * compone un brief numerico e lo fa verbalizzare al chat persona.
 */
require_once dirname(__DIR__, 2) . '/logger.php';

/**
 * GET semplice verso QBert (endpoint nativi /queue/status, /logs/*, /health)
 * — non passa per il gateway, non usa QBertClient (non è un servizio proxied).
 */
function workload_qbertGet(string $path, float $timeout = 4.0): ?array {
    $url = rtrim(QBERT_URL, '/') . $path;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    $body = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($body === false || $err || $http >= 400) {
        return null;
    }
    $json = json_decode($body, true);
    return is_array($json) ? $json : null;
}

function workload_formatMs(int $ms): string {
    if ($ms < 1000) return $ms . ' ms';
    $s = $ms / 1000;
    if ($s < 60) return number_format($s, 1, ',', '') . ' s';
    $m = (int)floor($s / 60);
    $r = (int)($s - $m * 60);
    return "{$m}m {$r}s";
}

/**
 * True se c'è un job GPU in esecuzione o almeno uno in coda GPU.
 * Quando è occupata, non possiamo chiamare _ai_core (passerebbe da QBert
 * e la risposta arriverebbe solo quando la GPU si libera).
 */
function workload_isGpuBusy(?array $queue): bool {
    if (!$queue) return false;
    if (!empty($queue['current']['gpu'])) return true;
    $gpuQ = $queue['queues']['gpu'] ?? [];
    foreach (['urgent', 'normal', 'lazy'] as $p) {
        if ((int)($gpuQ[$p]['count'] ?? 0) > 0) return true;
    }
    return false;
}

/**
 * Risposta diretta (senza LLM) da usare quando la GPU è occupata.
 * Colloquiale ma deterministica, basata sul brief numerico.
 */
function workload_buildDirectResponse(?array $queue, ?array $vram, ?array $stats): string {
    $parts = [];

    $current = $queue['current'] ?? [];
    $curGpu = $current['gpu'] ?? null;
    if ($curGpu) {
        $svc = $curGpu['service'] ?? '?';
        $app = $curGpu['app_name'] ?? 'anon';
        $since = workload_formatMs((int)($curGpu['running_ms'] ?? 0));
        $parts[] = "Sto macinando: \"{$svc}\" per {$app}, in esecuzione da {$since}.";
    } else {
        $parts[] = "GPU impegnata ma non ho il dettaglio del job corrente.";
    }

    $gpuQ = $queue['queues']['gpu'] ?? [];
    $u = (int)($gpuQ['urgent']['count'] ?? 0);
    $n = (int)($gpuQ['normal']['count'] ?? 0);
    $l = (int)($gpuQ['lazy']['count'] ?? 0);
    $totQ = $u + $n + $l;
    if ($totQ > 0) {
        $parts[] = "In coda GPU: {$totQ} lavori ({$u} urgent, {$n} normal, {$l} lazy).";
        $avgBackend = isset($stats['avg_backend_ms']) ? (int)$stats['avg_backend_ms'] : 0;
        if ($avgBackend > 0) {
            $eta = workload_formatMs($totQ * $avgBackend);
            $parts[] = "Stima smaltimento: ~{$eta}.";
        }
    }

    if ($vram && isset($vram['vram_used_mb'], $vram['vram_total_mb'])) {
        $used = (int)$vram['vram_used_mb'];
        $tot = max(1, (int)$vram['vram_total_mb']);
        $pct = (int)round($used / $tot * 100);
        $util = isset($vram['gpu_utilization_pct']) ? $vram['gpu_utilization_pct'] . '%' : 'n/d';
        $parts[] = "GPU util {$util}, VRAM {$used}/{$tot} MB ({$pct}%).";
    }

    $parts[] = "Risposta breve senza LLM — se ti scrivo con calma dopo, è perché ero occupato a pensare ad altro.";

    return implode(' ', $parts);
}

function workload_buildBrief(?array $queue, ?array $vram, ?array $stats): string {
    $lines = [];

    // --- Lavoro in corso ---
    $current = $queue['current'] ?? [];
    $runningLines = [];
    foreach (['gpu', 'cpu'] as $res) {
        $cur = $current[$res] ?? null;
        if ($cur) {
            $svc = $cur['service'] ?? '?';
            $app = $cur['app_name'] ?? 'anon';
            $since = workload_formatMs((int)($cur['running_ms'] ?? 0));
            $prio = $cur['priority'] ?? '?';
            $runningLines[] = "- {$res}: servizio \"{$svc}\" (app: {$app}, priorità: {$prio}, in esecuzione da {$since})";
        }
    }
    if ($runningLines) {
        $lines[] = "Lavori in esecuzione adesso:";
        $lines = array_merge($lines, $runningLines);
    } else {
        $lines[] = "Nessun lavoro in esecuzione adesso.";
    }

    // --- Coda ---
    $queues = $queue['queues'] ?? [];
    $queueLines = [];
    foreach (['gpu', 'cpu'] as $res) {
        $r = $queues[$res] ?? [];
        $u = (int)($r['urgent']['count'] ?? 0);
        $n = (int)($r['normal']['count'] ?? 0);
        $l = (int)($r['lazy']['count'] ?? 0);
        $tot = $u + $n + $l;
        if ($tot > 0) {
            $queueLines[] = "- {$res}: {$tot} in coda ({$u} urgent, {$n} normal, {$l} lazy)";
            // Dettaglio primi job per capire "chi" sta aspettando
            foreach (['urgent', 'normal', 'lazy'] as $prio) {
                $jobs = $r[$prio]['jobs'] ?? [];
                foreach ($jobs as $j) {
                    $svc = $j['service'] ?? '?';
                    $app = $j['app_name'] ?? 'anon';
                    $wait = workload_formatMs((int)($j['wait_ms'] ?? 0));
                    $queueLines[] = "  · [{$prio}] {$svc} (app: {$app}, in attesa da {$wait})";
                }
            }
        }
    }
    if ($queueLines) {
        $lines[] = "";
        $lines[] = "In coda:";
        $lines = array_merge($lines, $queueLines);
    } else {
        $lines[] = "Coda vuota.";
    }

    // --- GPU istantanea ---
    if ($vram && isset($vram['vram_used_mb'], $vram['vram_total_mb'])) {
        $used = (int)$vram['vram_used_mb'];
        $tot = max(1, (int)$vram['vram_total_mb']);
        $pctVram = (int)round($used / $tot * 100);
        $util = isset($vram['gpu_utilization_pct']) ? $vram['gpu_utilization_pct'] . '%' : 'n/d';
        $temp = isset($vram['gpu_temp_c']) ? $vram['gpu_temp_c'] . '°C' : '';
        $procs = [];
        foreach (($vram['processes'] ?? []) as $p) {
            $svc = $p['service_name'] ?? $p['process_name'] ?? '?';
            $pMb = (int)($p['vram_mb'] ?? 0);
            $procs[] = "{$svc} ({$pMb} MB)";
        }
        $lines[] = "";
        $lines[] = "GPU: util {$util}, VRAM {$used}/{$tot} MB ({$pctVram}%)" . ($temp ? ", temp {$temp}" : '');
        if ($procs) {
            $lines[] = "Processi GPU attivi: " . implode(', ', array_slice($procs, 0, 6));
        }
    }

    // --- Stats ultimi 30 minuti ---
    if ($stats && isset($stats['total_requests'])) {
        $tot = (int)$stats['total_requests'];
        $lines[] = "";
        if ($tot === 0) {
            $lines[] = "Ultimi 30 minuti: nessuna richiesta.";
        } else {
            $avgDur = isset($stats['avg_duration_ms']) ? workload_formatMs((int)$stats['avg_duration_ms']) : 'n/d';
            $avgWait = isset($stats['avg_queue_wait_ms']) ? workload_formatMs((int)$stats['avg_queue_wait_ms']) : 'n/d';
            $errRate = isset($stats['error_rate']) ? number_format($stats['error_rate'] * 100, 1, ',', '') . '%' : 'n/d';
            $lines[] = "Ultimi 30 minuti: {$tot} richieste, latenza media {$avgDur}, attesa coda media {$avgWait}, errori {$errRate}.";

            $byService = $stats['by_service'] ?? [];
            if ($byService) {
                uasort($byService, fn($a, $b) => ($b['count'] ?? 0) <=> ($a['count'] ?? 0));
                $top = array_slice($byService, 0, 4, true);
                $parts = [];
                foreach ($top as $svc => $info) {
                    $parts[] = "{$svc}: " . (int)($info['count'] ?? 0);
                }
                $lines[] = "Servizi più usati: " . implode(', ', $parts);
            }
        }
    }

    // --- Proiezione ---
    $totQueued = 0;
    foreach ($queues as $r) {
        foreach (['urgent', 'normal', 'lazy'] as $p) {
            $totQueued += (int)($r[$p]['count'] ?? 0);
        }
    }
    $avgBackend = isset($stats['avg_backend_ms']) ? (int)$stats['avg_backend_ms'] : 0;
    if ($totQueued > 0 && $avgBackend > 0) {
        $eta = workload_formatMs($totQueued * $avgBackend);
        $lines[] = "";
        $lines[] = "Proiezione: smaltimento coda in ~{$eta} (stima grezza = n_coda × latenza_media).";
    } elseif ($totQueued === 0 && !$runningLines) {
        $lines[] = "";
        $lines[] = "Proiezione: libero ora, pronto a partire subito.";
    }

    return implode("\n", $lines);
}

$workloadHandler = function (array $ctx, array $params): ?array {
        $log = function (string $msg) {
            @file_put_contents(
                logPath('workload'),
                '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n",
                FILE_APPEND
            );
        };

        $log('Handler called by user=' . $ctx['fromId'] . ' msg=' . substr($ctx['message'], 0, 120));

        $queue = workload_qbertGet('/queue/status?head_limit=5');
        $vram = workload_qbertGet('/logs/vram/current');
        $from = time() - 1800;
        $stats = workload_qbertGet('/logs/stats?from=' . $from);

        if ($queue === null && $vram === null && $stats === null) {
            $log('All QBert endpoints unreachable, fallback response');
            return ['response' => "Non riesco a leggere il mio stato da QBert in questo momento. Riprova tra poco."];
        }

        $brief = workload_buildBrief($queue, $vram, $stats);
        $log("Brief built:\n" . $brief);

        // Se la GPU è occupata (job in corso o coda GPU non vuota), salto _ai_core:
        // un LLM qui aspetterebbe in coda QBert e la risposta arriverebbe solo
        // quando la GPU si libera — esattamente quello che l'utente voleva sapere.
        if (workload_isGpuBusy($queue)) {
            $direct = workload_buildDirectResponse($queue, $vram, $stats);
            $log('GPU busy: returning direct response (no LLM)');
            return ['response' => $direct];
        }

        // Inietto il brief come sezione extra nel prompt di _ai_core.
        // Il persona usa i dati per rispondere in modo colloquiale (non elenca i numeri pari pari).
        $workloadSection = <<<SEC


### STATO OPERATIVO (dati freschi da QBert, USA QUESTI per rispondere) ###
{$brief}

Istruzioni specifiche per questa risposta:
- L'utente vuole sapere se sei libero/occupato o cosa stai facendo.
- Rispondi in modo colloquiale e sintetico basandoti SUI NUMERI sopra.
- Se non c'è nulla in esecuzione e la coda è vuota, dì che sei libero.
- Se c'è qualcosa in corso, menziona cosa (servizio/app) e da quanto.
- Se c'è coda, accenna a quanti lavori sono in attesa e la proiezione.
- Puoi fare un cenno agli ultimi 30 minuti SOLO se rilevante (es. ti chiedono "cosa hai fatto" o è un numero interessante).
- NON elencare i numeri come se fosse un report tecnico: parla come rootbot, con battuta se ci sta.
SEC;

        $response = _ai_core(
            $ctx['chatID'],
            $ctx['chatType'],
            $ctx['message'],
            $ctx['userName'],
            $workloadSection,
            $ctx['fromId'] ?? null
        );

        return ['response' => $response];
};

return [
    'id' => 'workload',
    'description' => "L'utente chiede al bot il suo stato operativo: se è libero, occupato, cosa sta facendo adesso, quanto carico ha, quante richieste ha gestito di recente, quanto lavoro ha in coda (es. 'sei libero?', 'sei occupato?', 'stai facendo qualcosa?', 'che carico hai?', 'quanto lavoro hai?', 'sei impegnato?')",
    'parameters' => [],
    'help' => "Stato operativo:
/stato — risposta immediata con lavoro in corso, coda GPU e riassunto ultimi 30 minuti (bypassa il classifier LLM, utile quando la GPU è occupata)
Oppure chiedi a voce: 'sei libero?', 'sei occupato?', 'che stai facendo?', 'quanto carico hai?'",
    'handler' => $workloadHandler,
    'commands' => [
        [
            'pattern' => '/^\/stato(?:@rootbotbot)?\s*$/ui',
            'handler' => function (array $ctx, array $matches) use ($workloadHandler): ?array {
                return $workloadHandler($ctx, []);
            },
        ],
    ],
];
