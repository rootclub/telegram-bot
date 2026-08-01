<?php
/**
 * Accesso a Wikipedia — funzioni condivise.
 *
 * Vivevano dentro include/agents/quiz/quiz.php, ma le usa anche
 * getWikipediaContext() in ai.php (agente wikipedia, DJ): tenerle dentro un
 * agente significava che rimuovere la cartella quiz rompeva il resto del bot,
 * e che i cron che non caricano il registro agenti andavano in fatal error.
 */

/**
 * Cerca contenuto su una specifica Wikipedia
 * @param string $searchTerm Termine di ricerca
 * @param string $lang Lingua Wikipedia (en, it, etc.)
 * @return array|null ['title', 'extract', 'lang'] o null
 */
function fetchWikipediaContentByLang($searchTerm, $lang = 'en') {
    $baseUrl = "https://{$lang}.wikipedia.org/w/api.php";

    // API Wikipedia - cerca
    $searchUrl = $baseUrl . '?' . http_build_query([
        'action' => 'query',
        'format' => 'json',
        'list' => 'search',
        'srsearch' => $searchTerm,
        'srlimit' => 1,
        'utf8' => 1
    ]);

    $ch = curl_init($searchUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_USERAGENT, 'RootBot/1.0 (Telegram Quiz Bot)');
    $searchResult = curl_exec($ch);
    curl_close($ch);

    $searchData = json_decode($searchResult, true);
    if (empty($searchData['query']['search'])) {
        return null;
    }

    $pageTitle = $searchData['query']['search'][0]['title'];

    // Ottieni estratto della pagina (senza exintro per avere tutto l'articolo)
    $extractUrl = $baseUrl . '?' . http_build_query([
        'action' => 'query',
        'format' => 'json',
        'titles' => $pageTitle,
        'prop' => 'extracts',
        'explaintext' => true,
        'exsectionformat' => 'plain',
        'exchars' => 3000,
        'utf8' => 1
    ]);

    $ch = curl_init($extractUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_USERAGENT, 'RootBot/1.0 (Telegram Quiz Bot)');
    $extractResult = curl_exec($ch);
    curl_close($ch);

    $extractData = json_decode($extractResult, true);
    $pages = $extractData['query']['pages'] ?? [];
    $page = reset($pages);

    if (empty($page['extract'])) {
        return null;
    }

    return [
        'title' => $pageTitle,
        'extract' => $page['extract'],
        'lang' => $lang
    ];
}

/**
 * Controlla se una pagina Wikipedia è una disambiguazione
 * @param string $extract Estratto della pagina
 * @return bool True se è una disambiguazione
 */
function isDisambiguationPage($extract) {
    // Prima rimuovi i template di navigazione "Se stai cercando..." che non indicano disambiguazione
    $cleanExtract = preg_replace('/^Disambiguazione\s*[-–—]\s*Se stai cercando[^.]+\./iu', '', $extract);
    $cleanExtract = trim($cleanExtract);

    // Pattern che indicano VERE pagine di disambiguazione (liste di significati)
    $disambiguationPatterns = [
        // Inglese - pattern tipici di pagine disambigua
        '/\bmay refer to\s*:/i',
        '/\bcan refer to\s*:/i',
        '/\bcommonly refers to\s*:/i',
        '/\bmight refer to\s*:/i',
        '/\brefers to\b[^.]*:/i',
        '/\bis the name of\s*:/i',
        // Italiano - pattern tipici di pagine disambigua
        '/\bpuò riferirsi a\s*:/i',
        '/\bpuò indicare\s*:/i',
        '/\bsi può riferire a\s*:/i',
        '/\bè il nome di\s*:/i',
        // Pattern generico: se dopo la pulizia inizia subito con una lista
        '/^[A-Z][^.]{0,50}\s*[-–—]\s*[A-Z]/u',  // "Nome – Descrizione" ripetuto = lista disambigua
    ];

    foreach ($disambiguationPatterns as $pattern) {
        if (preg_match($pattern, $cleanExtract)) {
            return true;
        }
    }

    // Se il testo pulito è molto corto, potrebbe essere una disambigua
    if (strlen($cleanExtract) < 100) {
        return true;
    }

    return false;
}
