<?php
/**
 * Script per pubblicazione automatica della rassegna stampa
 * Da eseguire via cron la mattina (es. 08:00)
 *
 * Crontab: 0 8 * * * /usr/bin/php /path/to/cron_rassegna.php
 */

set_time_limit(0);
ini_set('max_execution_time', 0);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/include/api.php';
require_once __DIR__ . '/include/telegram.php';

// DB per dedup articoli già postati
$db = new SQLite3(__DIR__ . '/' . DB_FILE);

// ID del gruppo principale
define('MAIN_GROUP_ID', -1001402757977);

// ID chat privata per test/debug
define('DEBUG_CHAT_ID', 138516148);

// URL della pagina news
define('NEWS_URL', 'https://www.rootclub.it/news/');

// Lock anti-duplicati
$lockFile = '/tmp/rassegna_cron.lock';
$lockTimeout = 600; // 10 minuti

if (file_exists($lockFile)) {
    $lockTime = (int)file_get_contents($lockFile);
    if (time() - $lockTime < $lockTimeout) {
        error_log("[cron_rassegna] Already running, skipping");
        exit(0);
    }
}

file_put_contents($lockFile, time());

function cron_log($msg) {
    error_log("[cron_rassegna] $msg");
}

/**
 * Scarica e parsa la pagina delle news
 * @return array Array di news con titolo, url, data
 */
function fetchNews() {
    $html = file_get_contents(NEWS_URL);
    if ($html === false) {
        throw new Exception("Impossibile scaricare la pagina news");
    }

    $news = [];

    // Usa DOMDocument per parsare l'HTML
    libxml_use_internal_errors(true);
    $doc = new DOMDocument();
    $doc->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
    libxml_clear_errors();

    $xpath = new DOMXPath($doc);

    // Cerca tutti gli articoli - la struttura tipica WordPress usa article o div con class post
    // Cerchiamo i link agli articoli e le date
    $articles = $xpath->query("//article | //div[contains(@class, 'post')] | //div[contains(@class, 'blog-item')] | //div[contains(@class, 'entry')]");

    if ($articles->length === 0) {
        // Fallback: cerca pattern più generici
        // Cerca tutti i link che sembrano articoli
        $links = $xpath->query("//a[contains(@href, 'rootclub.it/') and not(contains(@href, '/news/')) and not(contains(@href, '/tag/')) and not(contains(@href, '/category/'))]");

        $seenUrls = [];
        foreach ($links as $link) {
            $href = $link->getAttribute('href');

            // Salta URL già visti, pagine speciali
            if (isset($seenUrls[$href]) ||
                strpos($href, '/page/') !== false ||
                strpos($href, '#') !== false ||
                $href === 'https://www.rootclub.it/' ||
                $href === 'https://www.rootclub.it') {
                continue;
            }

            $seenUrls[$href] = true;

            // Cerca il titolo nel testo del link o in elementi vicini
            $title = trim($link->textContent);
            if (empty($title) || strlen($title) < 10) {
                // Cerca un'immagine con alt
                $img = $xpath->query(".//img", $link);
                if ($img->length > 0) {
                    $title = $img->item(0)->getAttribute('alt');
                }
            }

            if (empty($title) || strlen($title) < 10) {
                continue;
            }

            // Salta titoli generici
            if (in_array(strtolower($title), ['scopri di più', 'leggi tutto', 'read more', 'continua'])) {
                continue;
            }

            $news[] = [
                'titolo' => $title,
                'url' => $href
            ];
        }
    }

    return $news;
}

/**
 * Scarica la pagina e cerca di estrarre la data dall'HTML grezzo
 * cercando pattern di date in italiano vicino ai titoli
 */
function fetchNewsWithDates() {
    $html = file_get_contents(NEWS_URL);
    if ($html === false) {
        throw new Exception("Impossibile scaricare la pagina news");
    }

    $news = [];
    $today = strftime('%e %B %Y'); // es. " 5 Gennaio 2026"
    $todayTrimmed = trim($today); // "5 Gennaio 2026"

    // Pattern per trovare date in italiano
    $mesiIt = ['Gennaio', 'Febbraio', 'Marzo', 'Aprile', 'Maggio', 'Giugno',
               'Luglio', 'Agosto', 'Settembre', 'Ottobre', 'Novembre', 'Dicembre'];
    $mesiPattern = implode('|', $mesiIt);

    // Cerca blocchi che contengono data e link
    // Pattern: data seguita da contenuto con link
    $datePattern = '/(\d{1,2})\s+(' . $mesiPattern . ')\s+(\d{4})/iu';

    // Trova tutte le date nella pagina
    preg_match_all($datePattern, $html, $dateMatches, PREG_OFFSET_CAPTURE);

    // Trova tutti i link agli articoli
    $linkPattern = '/<a[^>]+href=["\']?(https?:\/\/www\.rootclub\.it\/[^"\'>\s]+)["\']?[^>]*>([^<]*)</i';
    preg_match_all($linkPattern, $html, $linkMatches, PREG_OFFSET_CAPTURE);

    // Mappa per evitare duplicati
    $seenUrls = [];

    // Per ogni data trovata, cerca il link più vicino
    foreach ($dateMatches[0] as $idx => $match) {
        $dateStr = $match[0];
        $datePos = $match[1];

        $giorno = (int)$dateMatches[1][$idx][0];
        $mese = $dateMatches[2][$idx][0];
        $anno = (int)$dateMatches[3][$idx][0];

        // Converti mese in numero
        $meseNum = array_search(ucfirst(strtolower($mese)), $mesiIt) + 1;
        $newsDate = mktime(0, 0, 0, $meseNum, $giorno, $anno);
        $todayStart = mktime(0, 0, 0);

        // Considera news delle ultime 48 ore (buffer anti cron saltati; il dedup per URL evita doppioni)
        $cutoff = time() - (48 * 3600);
        if ($newsDate < $cutoff) {
            continue;
        }

        // Trova il link più vicino a questa data (entro 2000 caratteri)
        $closestLink = null;
        $closestDist = PHP_INT_MAX;

        foreach ($linkMatches[1] as $linkIdx => $linkMatch) {
            $url = $linkMatch[0];
            $linkPos = $linkMatch[1];
            $title = trim($linkMatches[2][$linkIdx][0]);

            // Salta URL non validi
            if (strpos($url, '/news/') !== false ||
                strpos($url, '/tag/') !== false ||
                strpos($url, '/category/') !== false ||
                strpos($url, '/page/') !== false ||
                strpos($url, '#') !== false ||
                $url === 'https://www.rootclub.it/' ||
                isset($seenUrls[$url])) {
                continue;
            }

            // Salta titoli vuoti o troppo corti
            if (empty($title) || strlen($title) < 15) {
                continue;
            }

            // Salta titoli generici
            $lowerTitle = strtolower($title);
            if (in_array($lowerTitle, ['scopri di più', 'leggi tutto', 'read more', 'continua', 'leggi di più'])) {
                continue;
            }

            $dist = abs($linkPos - $datePos);
            if ($dist < $closestDist && $dist < 2000) {
                $closestDist = $dist;
                $closestLink = ['url' => $url, 'titolo' => $title];
            }
        }

        if ($closestLink && !isset($seenUrls[$closestLink['url']])) {
            $seenUrls[$closestLink['url']] = true;
            $news[] = $closestLink;
        }
    }

    return $news;
}

/**
 * Metodo alternativo: scarica la pagina e usa pattern matching semplice
 */
function fetchNewsByPattern() {
    $html = file_get_contents(NEWS_URL);
    if ($html === false) {
        throw new Exception("Impossibile scaricare la pagina news");
    }

    $news = [];
    $seenUrls = [];

    // Data di oggi in formato italiano
    $oggi = date('j');
    $mesiIt = [
        1 => 'Gennaio', 2 => 'Febbraio', 3 => 'Marzo', 4 => 'Aprile',
        5 => 'Maggio', 6 => 'Giugno', 7 => 'Luglio', 8 => 'Agosto',
        9 => 'Settembre', 10 => 'Ottobre', 11 => 'Novembre', 12 => 'Dicembre'
    ];
    $meseOggi = $mesiIt[(int)date('n')];
    $annoOggi = date('Y');
    $dataOggi = "$oggi $meseOggi $annoOggi";

    // Data di ieri (per recuperare news pubblicate nelle ultime 23 ore)
    $tsIeri = time() - 86400;
    $giornoIeri = (int)date('j', $tsIeri);
    $meseIeri = $mesiIt[(int)date('n', $tsIeri)];
    $annoIeri = (int)date('Y', $tsIeri);
    $dataIeri = "$giornoIeri $meseIeri $annoIeri";

    cron_log("Cerco news per data: $dataOggi oppure $dataIeri");

    // Se nessuna delle due date è presente nel documento, esci subito
    if (strpos($html, $dataOggi) === false && strpos($html, $dataIeri) === false) {
        cron_log("Nessuna news trovata per oggi né per ieri");
        return [];
    }

    // Pattern per trovare articoli: cerca href seguiti da titoli
    // Tipicamente: <a href="URL">TITOLO</a> ... DATA
    $pattern = '/<a[^>]+href=["\']?(https:\/\/www\.rootclub\.it\/[a-z0-9\-]+\/)["\']?[^>]*>\s*([^<]{20,}?)\s*<\/a>/i';

    preg_match_all($pattern, $html, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

    foreach ($matches as $match) {
        $url = $match[1][0];
        $titolo = html_entity_decode(trim($match[2][0]), ENT_QUOTES, 'UTF-8');
        $pos = $match[0][1];

        // Salta se già visto
        if (isset($seenUrls[$url])) {
            continue;
        }

        // Salta titoli generici
        $lowerTitle = strtolower($titolo);
        if (strpos($lowerTitle, 'scopri di più') !== false ||
            strpos($lowerTitle, 'leggi tutto') !== false ||
            strpos($lowerTitle, 'read more') !== false) {
            continue;
        }

        // Verifica che la data di oggi o di ieri sia vicina a questo match (entro 1500 caratteri)
        $context = substr($html, max(0, $pos - 500), 2000);
        if (strpos($context, $dataOggi) !== false || strpos($context, $dataIeri) !== false) {
            $seenUrls[$url] = true;
            $news[] = [
                'titolo' => $titolo,
                'url' => $url
            ];
        }
    }

    return $news;
}

/**
 * Formatta il messaggio della rassegna stampa
 */
function formatRassegna($news) {
    if (empty($news)) {
        return null;
    }

    $oggi = strftime('%A %e %B %Y');

    $msg = "☕ <b>morning.sh</b>\n";
    $msg .= "<code>$ ./rassegna --date=\"$oggi\"</code>\n\n";

    foreach ($news as $idx => $item) {
        $titolo = htmlspecialchars($item['titolo'], ENT_QUOTES, 'UTF-8');
        $url = $item['url'];
        $msg .= "→ <a href=\"$url\">$titolo</a>\n\n";
    }

    $msg .= "📡 <a href=\"" . NEWS_URL . "\">rootclub.it/news</a>";

    return $msg;
}

// Main
try {
    cron_log("=== Starting rassegna stampa ===");

    // Assicura che la tabella di dedup esista (nel caso initDatabase non sia ancora stato eseguito)
    $db->exec("CREATE TABLE IF NOT EXISTS rassegna_posted (
        url TEXT PRIMARY KEY,
        title TEXT,
        posted_at INTEGER
    )");

    // Prova prima il metodo pattern
    cron_log("Fetching news...");
    $news = fetchNewsByPattern();

    if (empty($news)) {
        cron_log("Pattern method returned 0 news, trying alternative...");
        $news = fetchNewsWithDates();
    }

    cron_log("Found " . count($news) . " news nelle ultime 48h");

    // Filtra articoli già postati in precedenza
    $stmt = $db->prepare("SELECT 1 FROM rassegna_posted WHERE url = :url");
    $newsFiltrate = [];
    foreach ($news as $item) {
        $stmt->bindValue(':url', $item['url'], SQLITE3_TEXT);
        $res = $stmt->execute();
        $row = $res ? $res->fetchArray(SQLITE3_ASSOC) : false;
        $stmt->reset();
        if (!$row) {
            $newsFiltrate[] = $item;
        }
    }
    $news = $newsFiltrate;
    cron_log("After dedup: " . count($news) . " nuovi articoli da postare");

    if (empty($news)) {
        cron_log("No new news, skipping");
        @unlink($lockFile);
        exit(0);
    }

    // Formatta il messaggio
    $messaggio = formatRassegna($news);

    if (empty($messaggio)) {
        cron_log("Empty message, skipping");
        @unlink($lockFile);
        exit(0);
    }

    cron_log("Message formatted, length: " . strlen($messaggio));

    // Invia al gruppo. $messaggio è già HTML-safe (formatRassegna escapa i titoli con
    // htmlspecialchars e compone tag fissi <b>/<a>), quindi lo avvolgo in TelegramHtml
    // per evitare il double-escape del wrapper.
    cron_log("Sending to Telegram...");
    $result = sendTelegramMessage(MAIN_GROUP_ID, new TelegramHtml($messaggio), [
        'disable_web_page_preview' => true,
    ]);

    if ($result['ok']) {
        cron_log("Message sent successfully!");
        // Salva gli URL appena postati per evitare doppioni ai prossimi run
        $ins = $db->prepare("INSERT OR REPLACE INTO rassegna_posted (url, title, posted_at) VALUES (:url, :title, :ts)");
        $now = time();
        foreach ($news as $item) {
            $ins->bindValue(':url', $item['url'], SQLITE3_TEXT);
            $ins->bindValue(':title', $item['titolo'], SQLITE3_TEXT);
            $ins->bindValue(':ts', $now, SQLITE3_INTEGER);
            $ins->execute();
            $ins->reset();
        }
        // Pulizia: rimuovi articoli più vecchi di 30 giorni
        $monthAgo = time() - (30 * 24 * 3600);
        $db->exec("DELETE FROM rassegna_posted WHERE posted_at < $monthAgo");
    } else {
        cron_log("Failed to send message: " . json_encode($result));
    }

} catch (Exception $e) {
    cron_log("ERROR: " . $e->getMessage());
}

cron_log("=== Finished ===\n");
@unlink($lockFile);
