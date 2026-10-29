<?php
declare(strict_types=1);

/**
 * Wird erhöht, wann immer sich die Struktur dessen ändert, was pro
 * Umgebung+API im Session-Cache liegt (z.B. ein neues Feld wie list_meta
 * oder eine Anreicherung wie _entity_name). Ein alter Cache-Eintrag mit
 * abweichender Version gilt automatisch als veraltet und wird neu geladen —
 * verhindert, dass ein Code-Update durch einen stehengebliebenen Session-
 * Cache "verschluckt" wird (siehe z.B. das list_meta-Panel bei ncTLS).
 */
const CACHE_SCHEMA_VERSION = 10;

// Paginierte JWT-APIs: Die Registry blättert per Offset über eine nach
// iat/nbf/exp sortierte Liste, deren Reihenfolge bei Gleichstand nicht stabil
// ist (ein eindeutiges Sortierfeld gibt es nicht; erlaubt sind nur typ, alg,
// kid, profile_version, iat, exp, nbf). Deshalb wird in mehreren Durchläufen
// mit wechselnder Seitengrösse (Maximum der Registry: 50) und wechselnder
// Sortierrichtung geladen und vereinigt — die Gleichstände fallen jedes Mal
// anders, so dass nach 2–3 Durchläufen alles da ist.
const PAGINATED_PASSES = [
    [50, 'asc'], [47, 'desc'], [43, 'asc'], [41, 'desc'], [37, 'asc'], [31, 'desc'],
];

/**
 * Liest einen Request-Parameter (aus $_GET oder $_POST) als String.
 * PHP liefert für "?x[]=1" ein Array statt eines Strings; ein solcher Wert
 * wird wie ein fehlender Parameter behandelt (Default), statt eine Warnung
 * ("Array to string conversion") oder einen TypeError auszulösen.
 *
 * @param array<array-key, mixed> $source
 */
function requestString(array $source, string $key, string $default = ''): string
{
    $value = $source[$key] ?? null;
    return is_string($value) ? $value : $default;
}

/**
 * Wie requestString(), aber als Ganzzahl. Nicht-numerische Strings ergeben
 * wie bisher beim (int)-Cast 0; Arrays und fehlende Werte den Default.
 *
 * @param array<array-key, mixed> $source
 */
function requestInt(array $source, string $key, int $default = 0): int
{
    $value = $source[$key] ?? null;
    return is_string($value) ? (int) $value : $default;
}

/**
 * Base64URL-Dekodierung (JWT-Standard) mit Padding-Korrektur.
 */

/**
 * Preview-Erkennung: Die Instanz gilt als Preview, wenn die Marker-Datei
 * storage/.preview existiert (leere Datei genügt). Die Datei wird per
 * .gitignore vom Repository ausgeschlossen und liegt im storage/-Ordner,
 * der Deployments überlebt - Code ist damit in preview und main identisch.
 */
function isPreview(): bool
{
    static $preview = null;
    if ($preview === null) {
        $preview = is_file(__DIR__ . '/storage/.preview');
    }
    return $preview;
}

/** Seitentitel; in der Preview mit Präfix "[Preview]". */
function pageTitle(string $suffix = ''): string
{
    $title = 'Swiyu Trust Registry Explorer' . $suffix;
    return isPreview() ? '[Preview] ' . $title : $title;
}

/** <title>, Favicons und (nur Preview) noindex für den <head>. */
function renderHeadMeta(string $suffix = ''): string
{
    $p = isPreview() ? '-preview' : '';
    $out  = '<title>' . htmlspecialchars(pageTitle($suffix)) . "</title>\n";
    $out .= '<link rel="icon" href="' . htmlspecialchars(assetUrl('favicon' . $p . '.svg')) . '" type="image/svg+xml">' . "\n";
    $out .= '<link rel="alternate icon" href="' . htmlspecialchars(assetUrl('favicon' . $p . '.ico')) . '">' . "\n";
    $out .= '<link rel="apple-touch-icon" href="' . htmlspecialchars(assetUrl('apple-touch-icon' . $p . '.png')) . '">' . "\n";
    if (isPreview()) {
        $out .= '<meta name="robots" content="noindex, nofollow">' . "\n";
    }
    return $out;
}

/** "PREVIEW"-Badge neben dem Seitentitel (leer auf PROD). */
function renderPreviewBadge(): string
{
    return isPreview() ? '<span class="env-badge" title="Preview">PREVIEW</span>' : '';
}

/**
 * URL einer Datei aus assets/ mit Inhalts-Hash als Cache-Buster
 * (z.B. assets/theme.css?v=1a2b3c4d). Ändert sich die Datei, ändert sich die
 * URL, und Browser laden sie neu - ohne dass nach einem Deploy der Cache
 * geleert werden muss. Fehlt die Datei, wird die URL ohne Parameter geliefert.
 */
function assetUrl(string $path): string
{
    static $cache = [];
    if (!isset($cache[$path])) {
        $file = __DIR__ . '/assets/' . $path;
        $hash = is_file($file) ? substr((string)md5_file($file), 0, 8) : '';
        $cache[$path] = 'assets/' . $path . ($hash !== '' ? '?v=' . $hash : '');
    }
    return $cache[$path];
}

/** Fusszeile mit Version (Link auf das GitHub-Release) und Repository-Link. */
function renderFooter(array $config): string
{
    $version = trim((string)($config['version'] ?? ''));
    $repo    = rtrim((string)($config['repo_url'] ?? ''), '/');
    $out = '<footer class="app-footer">';
    if ($version !== '') {
        $label = 'v' . htmlspecialchars($version) . (isPreview() ? ' · preview' : '');
        $out .= $repo !== ''
            ? '<a href="' . htmlspecialchars($repo . '/releases/tag/v' . $version) . '" target="_blank" rel="noopener" class="app-version">' . $label . '</a>'
            : '<span class="app-version">' . $label . '</span>';
    }
    if ($repo !== '') {
        $out .= ($version !== '' ? ' · ' : '') . '<a href="' . htmlspecialchars($repo) . '" target="_blank" rel="noopener">GitHub</a>';
    }
    return $out . "</footer>\n";
}

function base64UrlDecode(string $data): string
{
    $remainder = strlen($data) % 4;
    if ($remainder !== 0) {
        $data .= str_repeat('=', 4 - $remainder);
    }
    return (string) base64_decode(strtr($data, '-_', '+/'));
}

/**
 * Zerlegt ein JWT in Header und Payload (ohne Signaturprüfung).
 */
function decodeJwt(string $jwt): array
{
    $parts = explode('.', trim($jwt));
    if (count($parts) < 2) {
        throw new RuntimeException(t('error.invalid_jwt'));
    }
    $header  = json_decode(base64UrlDecode($parts[0]), true, flags: JSON_THROW_ON_ERROR);
    $payload = json_decode(base64UrlDecode($parts[1]), true, flags: JSON_THROW_ON_ERROR);
    return ['header' => $header, 'payload' => $payload];
}

/**
 * Führt einen HTTP-GET-Request aus und gibt den Rohtext zurück.
 */
function httpGet(string $url): string
{
    $ch = curl_init($url);
    if ($ch === false) {
        throw new RuntimeException(t('error.curl', [0, 'curl_init']));
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_HTTPHEADER     => ['Accept: text/plain, application/json, application/jwt, */*'],
    ]);
    $body = curl_exec($ch);
    $errNo = curl_errno($ch);
    $err = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errNo !== 0) {
        throw new RuntimeException(t('error.curl', [$errNo, $err]));
    }
    if ($httpCode !== 200) {
        throw new RuntimeException(t('error.http_status', [$httpCode, $url]));
    }
    if (!is_string($body) || trim($body) === '') {
        throw new RuntimeException(t('error.empty_response'));
    }
    return trim($body);
}

/**
 * Ruft eine Token-Status-List-JWT ab und entpackt ihr komprimiertes
 * Bit-Array (gemäss IETF draft-ietf-oauth-status-list). Liefert die
 * Rohbytes + Bitbreite, damit daraus für einen beliebigen Index der
 * Statuswert gelesen werden kann, ohne pro Index neu abzurufen.
 *
 * @return array{bytes: string|null, bits: int|null, error: string|null}
 */
function fetchStatusListBytes(string $uri): array
{
    try {
        $raw = httpGet($uri);
        $decoded = decodeJwt($raw);
        $statusList = $decoded['payload']['status_list'] ?? null;

        if (!is_array($statusList) || !isset($statusList['lst'])) {
            return ['bytes' => null, 'bits' => null, 'error' => t('error.status_list_missing')];
        }

        $bits = (int) ($statusList['bits'] ?? 1);
        $compressed = base64UrlDecode((string) $statusList['lst']);
        $bytes = @gzuncompress($compressed);

        if ($bytes === false) {
            return ['bytes' => null, 'bits' => null, 'error' => t('error.status_list_decompress')];
        }

        return ['bytes' => $bytes, 'bits' => $bits, 'error' => null];
    } catch (Throwable $e) {
        return ['bytes' => null, 'bits' => null, 'error' => $e->getMessage()];
    }
}

/**
 * Liest den Statuswert an Position $idx aus den entpackten Statuslisten-
 * Bytes (bitweise, gemäss $bits pro Eintrag). null, wenn $idx ausserhalb
 * der verfügbaren Bytes liegt, keine Bytes vorliegen oder die Werte aus der
 * Statusliste ungültig sind: ein negativer Index würde in PHP sonst vom Ende
 * des Strings lesen (falscher Status), und die Spezifikation erlaubt nur
 * 1, 2, 4 oder 8 Bit pro Eintrag.
 */
function extractStatusBit(?string $bytes, ?int $bits, int $idx): ?int
{
    if ($bytes === null || $bits === null || $idx < 0 || !in_array($bits, [1, 2, 4, 8], true)) {
        return null;
    }

    $bitOffset = $idx * $bits;
    $byteIndex = intdiv($bitOffset, 8);
    $bitShift  = $bitOffset % 8;

    if (!isset($bytes[$byteIndex])) {
        return null;
    }

    $mask = (1 << $bits) - 1;
    return (ord($bytes[$byteIndex]) >> $bitShift) & $mask;
}

/**
 * Übersetzt einen rohen Statuswert (0/1/2) in das lesbare Label der aktiven
 * Sprache (Valid/Revoked/Suspended). Unbekannte Werte werden als
 * "<Wert> (Unknown Status)" dargestellt.
 *
 * Wird bewusst erst beim ANZEIGEN aufgerufen und nie im Session-Cache
 * abgelegt, sonst bliebe das Label nach einem Sprachwechsel in der alten
 * Sprache stehen. Im Cache liegt nur der Rohwert plus ein sprachneutraler
 * Marker (siehe statusDisplayLabel).
 */
function statusLabel(?int $value): string
{
    if ($value === null) {
        return t('status.unavailable');
    }
    return match ($value) {
        0 => t('status.valid'),
        1 => t('status.revoked'),
        2 => t('status.suspended'),
        default => t('status.unknown_value', [$value]),
    };
}

/**
 * Anzeige-Label für einen gecachten Status: $value ist der Rohwert aus der
 * Statusliste, $marker der sprachneutrale Marker aus dem Cache ('-' = keine
 * Statuslisten-Referenz, 'unavailable' = nicht abrufbar, '' = Wert vorhanden).
 */
function statusDisplayLabel(?int $value, ?string $marker): string
{
    if ($value !== null) {
        return statusLabel($value);
    }
    return $marker === '-' ? '-' : t('status.unavailable');
}

/**
 * Löst eine einzelne Token-Status-List-Referenz (uri + idx) zu einem
 * konkreten Status-Wert auf. Für LISTEN-WEITE Status (ncTLS/piTLS) gedacht,
 * wo es pro API nur eine einzige Referenz gibt.
 *
 * @return array{status: int|null, label: string, error: string|null}
 */
function resolveTrustListStatus(?string $uri, mixed $idx): array
{
    if ($uri === null || $idx === null) {
        return ['status' => null, 'label' => '-', 'error' => null];
    }

    $fetched = fetchStatusListBytes($uri);
    if ($fetched['error'] !== null) {
        return ['status' => null, 'label' => 'unavailable', 'error' => $fetched['error']];
    }

    $value = extractStatusBit($fetched['bytes'], $fetched['bits'], (int) $idx);
    if ($value === null) {
        return ['status' => null, 'label' => 'unavailable', 'error' => t('error.status_list_index_oob')];
    }

    return ['status' => $value, 'label' => '', 'error' => null];
}

/**
 * Löst den Status für JEDEN Eintrag einzeln auf (z.B. idTS: jede Zeile hat
 * eine eigene status.status_list.{uri,idx}-Referenz — jeder Eintrag hat
 * dabei seinen EIGENEN Index, auch wenn mehrere Einträge auf dieselbe
 * Statuslisten-URI verweisen). Um das effizient zu halten, wird jede
 * vorkommende URI nur EINMAL abgerufen/entpackt, unabhängig davon, wie
 * viele Einträge darauf verweisen — der jeweilige Index wird danach pro
 * Eintrag individuell aus denselben Bytes gelesen.
 *
 * Ergänzt an jedem Eintrag: '_status_value', '_status_error' und '_status_label'
 * (sprachneutraler Marker, kein Anzeigetext: '-', 'unavailable' oder ''; den
 * Anzeigetext liefert erst statusDisplayLabel() beim Rendern).
 */
function attachRowStatuses(array $entries): array
{
    $bytesCache = []; // uri => ['bytes'=>..., 'bits'=>..., 'error'=>...]

    foreach ($entries as $entry) {
        $uri = $entry['status']['status_list']['uri'] ?? null;
        if ($uri !== null && !isset($bytesCache[$uri])) {
            $bytesCache[$uri] = fetchStatusListBytes($uri);
        }
    }

    foreach ($entries as &$entry) {
        $uri = $entry['status']['status_list']['uri'] ?? null;
        $idx = $entry['status']['status_list']['idx'] ?? null;

        if ($uri === null || $idx === null) {
            $entry['_status_value'] = null;
            $entry['_status_label'] = '-';
            $entry['_status_error'] = null;
            continue;
        }

        $cached = $bytesCache[$uri];
        if ($cached['error'] !== null) {
            $entry['_status_value'] = null;
            $entry['_status_label'] = 'unavailable';
            $entry['_status_error'] = $cached['error'];
            continue;
        }

        $value = extractStatusBit($cached['bytes'], $cached['bits'], (int) $idx);
        $entry['_status_value'] = $value;
        $entry['_status_label'] = $value === null ? 'unavailable' : '';
        $entry['_status_error'] = $value === null ? t('error.status_list_index_oob') : null;
    }
    unset($entry);

    return $entries;
}

/**
 * Ordnet einen Eintrag (mit '_status_value' aus attachRowStatuses) einer von
 * vier Gruppen zu: 'valid' (Statuslist-Wert 0), 'revoked' (1), 'suspended' (2)
 * oder 'unknown' (kein Status ermittelbar: keine Statuslist-Referenz,
 * Statuslist nicht erreichbar, Index ausserhalb, unbekannter Wert).
 * Nur der Status aus der Statusliste zählt, die Validity (nbf/exp) nicht.
 */
function statusBucket(array $entry): string
{
    return match ($entry['_status_value'] ?? null) {
        0       => 'valid',
        1       => 'revoked',
        2       => 'suspended',
        default => 'unknown',
    };
}

/**
 * Zählt die Einträge pro Status-Gruppe (siehe statusBucket()).
 *
 * @return array{valid:int, revoked:int, suspended:int, unknown:int}
 */
function statusBucketCounts(array $entries): array
{
    $counts = ['valid' => 0, 'revoked' => 0, 'suspended' => 0, 'unknown' => 0];
    foreach ($entries as $entry) {
        $counts[statusBucket($entry)]++;
    }
    return $counts;
}

/**
 * Reichert jeden Eintrag um '_entity_name' an, nachgeschlagen über die DID
 * in den Einträgen einer ANDEREN API derselben Umgebung (z.B. pvaTS/ncTLS-
 * Zeilen mit dem Namen aus idTS verknüpfen). $didField ist der Feldname, in
 * dem DIESE Einträge ihre DID tragen (z.B. 'sub' bei pvaTS, 'actor' bei
 * ncTLS) — idTS selbst trägt seine DID immer unter 'sub'. Nutzt denselben
 * Session-Cache wie die Quell-API selbst — löst also KEINEN zusätzlichen
 * API-Call aus, wenn diese schon geladen war. Kein Treffer → '_entity_name' bleibt null.
 */
function attachEntityNames(array $entries, string $envKey, string $baseUrl, string $sourceApiKey, array $allApis, int $ttl, string $didField = 'sub'): array
{
    if (!isset($allApis[$sourceApiKey])) {
        foreach ($entries as &$entry) {
            $entry['_entity_name'] = null;
        }
        return $entries;
    }

    try {
        $source = getEntriesCached($envKey, $sourceApiKey, $baseUrl, $allApis[$sourceApiKey], false, $ttl, $allApis);
        $nameBySub = [];
        foreach ($source['entries'] as $sourceEntry) {
            $sub = $sourceEntry['sub'] ?? null;
            $name = $sourceEntry['entity_name'] ?? null;
            if (is_string($sub) && $name !== null && $name !== '') {
                $nameBySub[$sub] = $name;
            }
        }
    } catch (Throwable) {
        $nameBySub = [];
    }

    foreach ($entries as &$entry) {
        $did = $entry[$didField] ?? null;
        $entry['_entity_name'] = is_string($did) ? ($nameBySub[$did] ?? null) : null;
    }
    unset($entry);

    return $entries;
}

/**
 * Baut eine Map VCT-Wert => Liste der piaTS-Einträge, die genau dieses VCT
 * in ihrem can_issue-Feld führen. can_issue kann ein einzelnes Objekt
 * {vct: "..."} ODER ein Array mehrerer solcher Objekte sein (siehe
 * formatCanIssueCell) — beide Formen werden hier berücksichtigt.
 */
function buildIssuersByVct(array $piaTsEntries): array
{
    $map = [];
    foreach ($piaTsEntries as $entry) {
        $canIssue = $entry['can_issue'] ?? null;
        if (!is_array($canIssue)) {
            continue;
        }
        $items = array_key_exists('vct', $canIssue) ? [$canIssue] : $canIssue;

        $vcts = [];
        foreach ($items as $item) {
            if (is_array($item) && isset($item['vct'])) {
                $vcts[] = (string) $item['vct'];
            }
        }
        foreach (array_unique($vcts) as $vct) {
            $map[$vct][] = $entry;
        }
    }
    return $map;
}

/**
 * Reichert piTLS-Einträge (scalar_list: ['value' => <VCT>]) um
 * '_issuer_count' (nur gültige Issuer), '_issuer_total' und '_issuers' (die vollständigen piaTS-Einträge, für die
 * aufklappbare Detailansicht) an — nachgeschlagen über $sourceApiKey
 * (piaTS) derselben Umgebung. Nutzt deren Session-Cache, löst also KEINEN
 * zusätzlichen API-Call aus, wenn piaTS schon geladen war.
 */
function attachIssuerCounts(array $entries, string $envKey, string $baseUrl, string $sourceApiKey, array $allApis, int $ttl): array
{
    if (!isset($allApis[$sourceApiKey])) {
        foreach ($entries as &$entry) {
            $entry['_issuer_count'] = 0;
            $entry['_issuer_total'] = 0;
            $entry['_issuers'] = [];
        }
        return $entries;
    }

    try {
        $source = getEntriesCached($envKey, $sourceApiKey, $baseUrl, $allApis[$sourceApiKey], false, $ttl, $allApis);
        $byVct = buildIssuersByVct($source['entries']);
    } catch (Throwable) {
        $byVct = [];
    }

    foreach ($entries as &$entry) {
        $vct = $entry['value'] ?? null;
        $issuers = ($vct !== null && isset($byVct[$vct])) ? $byVct[$vct] : [];
        // Nur Issuer mit gültigem Status (Statuslist-Wert 0) werden gezählt und
        // in der aufklappbaren Liste angezeigt; widerrufene/suspendierte/
        // unbekannte nicht. '_issuer_total' hält die Gesamtzahl für den Hinweis.
        $validIssuers = array_values(array_filter($issuers, static fn (array $i): bool => statusBucket($i) === 'valid'));
        $entry['_issuer_count'] = count($validIssuers);
        $entry['_issuer_total'] = count($issuers);
        $entry['_issuers'] = $validIssuers;
    }
    unset($entry);

    return $entries;
}

/**
 * Holt ALLE Einträge einer API (über alle Seiten hinweg, falls paginiert).
 *
 * @param array $allApis Die komplette 'apis'-Config — wird nur gebraucht, wenn
 *   $apiCfg 'enrich_name_from' gesetzt hat (Nachschlagen in einer ANDEREN API).
 * @return array{entries: array, list_meta: array|null} list_meta enthält
 *   bei APIs mit dem Config-Flag 'list_meta' (aktuell ncTLS und piTLS) die für
 *   die GESAMTE Liste geltenden Angaben (nbf/exp/iat + aufgelöster Status) —
 *   sonst null. Bei APIs mit dem Config-Flag 'row_status' (aktuell idTS,
 *   pvaTS) bekommt stattdessen JEDER Eintrag seinen eigenen aufgelösten
 *   Status (siehe attachRowStatuses). Bei 'enrich_name_from' bekommt jeder
 *   Eintrag zusätzlich '_entity_name', nachgeschlagen über 'sub' in den
 *   Einträgen der referenzierten API (z.B. idTS).
 */
function fetchAllEntries(string $envKey, string $baseUrl, array $apiCfg, array $allApis, int $ttl): array
{
    $url = $baseUrl . $apiCfg['path'];

    if ($apiCfg['mode'] === 'single_jwt_list') {
        $raw = httpGet($url);
        $decoded = decodeJwt($raw);
        $payload = $decoded['payload'];
        $listRaw = $payload[$apiCfg['list_field']] ?? [];

        $entries = [];
        foreach ($listRaw as $item) {
            $entries[] = !empty($apiCfg['scalar_list']) ? ['value' => $item] : $item;
        }

        if (!empty($apiCfg['enrich_name_from'])) {
            $entries = attachEntityNames($entries, $envKey, $baseUrl, $apiCfg['enrich_name_from'], $allApis, $ttl, $apiCfg['enrich_did_field'] ?? 'sub');
        }

        if (!empty($apiCfg['issuer_count_from'])) {
            $entries = attachIssuerCounts($entries, $envKey, $baseUrl, $apiCfg['issuer_count_from'], $allApis, $ttl);
        }

        $listMeta = null;
        if (!empty($apiCfg['list_meta'])) {
            $statusUri = $payload['status']['status_list']['uri'] ?? null;
            $statusIdx = $payload['status']['status_list']['idx'] ?? null;
            $status = resolveTrustListStatus($statusUri, $statusIdx);

            $listMeta = [
                'nbf'          => $payload['nbf'] ?? null,
                'exp'          => $payload['exp'] ?? null,
                'iat'          => $payload['iat'] ?? null,
                'status_value' => $status['status'],
                'status_label' => $status['label'],
                'status_error' => $status['error'],
                'jwt'          => $raw,
            ];
        }

        return ['entries' => $entries, 'list_meta' => $listMeta];
    }

    if ($apiCfg['mode'] === 'paginated_jwt') {
        // Siehe PAGINATED_PASSES: Einträge über ihre jti eindeutig machen und so
        // lange weitere Durchläufe machen, bis page.totalElements erreicht ist.
        $entries = [];
        $seen = [];
        $totalElements = null;
        $useSort = true;

        foreach (PAGINATED_PASSES as [$size, $dir]) {
            $sort = $useSort
                ? '&sort=iat%2C' . $dir . '&sort=nbf%2C' . $dir . '&sort=exp%2C' . $dir
                : '';
            $page = 0;
            $totalPages = 1;

            do {
                // Explizit filterActive=false (statt den Parameter wegzulassen):
                // der Default bei fehlendem Parameter ist serverseitig "true", ein
                // Weglassen würde also weiterhin gefiltert. Ausserdem ist das Flag
                // laut API-Doku ohnehin nur ein Best-Effort-Hinweis ("client still
                // needs to validate the statements and cannot assume that all
                // statements returned are indeed active") — der Explorer holt daher
                // IMMER alle Einträge (aktiv + inaktiv) und verlässt sich für die
                // eigentliche Gültigkeit auf die eigene client-seitige Prüfung
                // (Status-Badge / Validity-Badge).
                $pagedUrl = $url . '?filterActive=false&page=' . $page . '&size=' . $size . $sort;
                try {
                    $json = json_decode(httpGet($pagedUrl), true, flags: JSON_THROW_ON_ERROR);
                } catch (RuntimeException $e) {
                    // Eine API, die das sort-Argument ablehnt: ohne Sortierung neu starten.
                    if ($sort !== '' && $page === 0) {
                        $useSort = false;
                        $sort = '';
                        $pagedUrl = $url . '?filterActive=false&page=0&size=' . $size;
                        $json = json_decode(httpGet($pagedUrl), true, flags: JSON_THROW_ON_ERROR);
                    } else {
                        throw $e;
                    }
                }

                foreach ($json['content'] ?? [] as $jwt) {
                    $decoded = decodeJwt($jwt);
                    // Payload bleibt an der Wurzel (für Spalten-Pfade wie "request.scope"),
                    // der Header wird unter '_jwt_header' mitgeführt für die Detailansicht,
                    // das Original-JWT unter '_jwt' für die Rohdaten-Ansicht.
                    $entry = $decoded['payload'];
                    $entry['_jwt_header'] = $decoded['header'];
                    $entry['_jwt'] = $jwt;

                    $key = isset($entry['jti']) && is_string($entry['jti']) && $entry['jti'] !== ''
                        ? 'jti:' . $entry['jti']
                        : 'jwt:' . md5((string) $jwt);
                    if (isset($seen[$key])) {
                        continue;
                    }
                    $seen[$key] = true;
                    $entries[] = $entry;
                }

                $totalPages = (int) ($json['page']['totalPages'] ?? 1);
                if ($page === 0 && isset($json['page']['totalElements'])) {
                    $totalElements = (int) $json['page']['totalElements'];
                }
                $page++;
            } while ($page < $totalPages);

            // Vollständig — oder die API nennt keine Gesamtanzahl (dann reicht ein Durchlauf).
            if ($totalElements === null || count($entries) >= $totalElements) {
                break;
            }
        }

        // Die Durchläufe laden teils aufsteigend; die Anzeige-Reihenfolge soll
        // aber wie gewohnt "neueste zuerst" sein (iat absteigend, dann nbf).
        // usort ist stabil: bei Gleichstand bleibt die Lade-Reihenfolge erhalten.
        usort($entries, static fn(array $a, array $b): int =>
            [(int) ($b['iat'] ?? 0), (int) ($b['nbf'] ?? 0)] <=> [(int) ($a['iat'] ?? 0), (int) ($a['nbf'] ?? 0)]
        );

        if (!empty($apiCfg['row_status'])) {
            $entries = attachRowStatuses($entries);
        }

        if (!empty($apiCfg['enrich_name_from'])) {
            $entries = attachEntityNames($entries, $envKey, $baseUrl, $apiCfg['enrich_name_from'], $allApis, $ttl, $apiCfg['enrich_did_field'] ?? 'sub');
        }

        return ['entries' => $entries, 'list_meta' => null];
    }

    throw new RuntimeException(t('error.unknown_api_mode', [$apiCfg['mode']]));
}

/**
 * Ermittelt NUR die Gesamtanzahl der Einträge einer API, ohne alle Seiten
 * zu laden/dekodieren — für den nächtlichen History-Collector (collect.php),
 * der jede Nacht jede Umgebung x jede API abfragt und dabei möglichst wenig
 * Daten übertragen soll.
 *
 * - 'paginated_jwt': nutzt den size=1-Trick, um page.totalElements zu lesen,
 *   ohne die eigentlichen (potenziell hunderten) JWTs herunterzuladen.
 *   filterActive=false, damit konsistent zu fetchAllEntries() ALLE
 *   Statements gezählt werden (aktiv + inaktiv), nicht nur ein Teil.
 * - 'single_jwt_list': hier gibt es ohnehin nur EINEN Request (ein JWT),
 *   der die komplette Liste im Payload enthält — wird also normal
 *   abgerufen und die Liste gezählt.
 */
function fetchEntryCount(string $baseUrl, array $apiCfg): int
{
    $url = $baseUrl . $apiCfg['path'];

    if ($apiCfg['mode'] === 'single_jwt_list') {
        $raw = httpGet($url);
        $decoded = decodeJwt($raw);
        $list = $decoded['payload'][$apiCfg['list_field']] ?? [];
        return is_array($list) ? count($list) : 0;
    }

    if ($apiCfg['mode'] === 'paginated_jwt') {
        $countUrl = $url . '?filterActive=false&page=0&size=1';
        $json = json_decode(httpGet($countUrl), true, flags: JSON_THROW_ON_ERROR);
        return (int) ($json['page']['totalElements'] ?? 0);
    }

    throw new RuntimeException(t('error.unknown_api_mode', [$apiCfg['mode']]));
}

/**
 * Öffnet (und erstellt bei Bedarf das Verzeichnis für) die SQLite-Datenbank
 * für das History-Feature.
 */
function historyDbConnect(string $dbPath): PDO
{
    $dir = dirname($dbPath);
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException(t('error.history_dir', [$dir]));
    }

    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA journal_mode = WAL'); // robuster bei gleichzeitigem Cron-Write + Web-Read

    return $pdo;
}

/**
 * Legt das Schema an, falls noch nicht vorhanden (idempotent, sicher bei
 * jedem Aufruf ausführbar — sowohl vom Collector als auch von der Web-Ansicht).
 */
function historyEnsureSchema(PDO $pdo): void
{
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS snapshots (
            id       INTEGER PRIMARY KEY AUTOINCREMENT,
            ts_utc   INTEGER NOT NULL,
            env      TEXT NOT NULL,
            api      TEXT NOT NULL,
            count    INTEGER,
            status   TEXT NOT NULL,
            error    TEXT
        )
    ');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_snapshots_env_api_ts ON snapshots (env, api, ts_utc)');
}

/**
 * Schreibt einen einzelnen Snapshot (eine Umgebung+API, ein Collector-Lauf).
 * $count ist null bei status='error' — bewusst NICHT 0, damit ein Fehlschlag
 * im Chart später als Lücke sichtbar ist statt als (falscher) Einbruch auf 0.
 */
function historyInsertSnapshot(PDO $pdo, int $tsUtc, string $env, string $api, ?int $count, string $status, ?string $error): void
{
    $stmt = $pdo->prepare('
        INSERT INTO snapshots (ts_utc, env, api, count, status, error)
        VALUES (:ts_utc, :env, :api, :count, :status, :error)
    ');
    $stmt->execute([
        ':ts_utc' => $tsUtc,
        ':env'    => $env,
        ':api'    => $api,
        ':count'  => $count,
        ':status' => $status,
        ':error'  => $error,
    ]);
}

/**
 * Liest die Snapshot-Reihe für eine Umgebung+API im Zeitraum [$fromTs, $toTs]
 * (beide inklusive, Unix-Timestamps UTC), aufsteigend nach Zeit sortiert.
 *
 * @return list<array{ts_utc:int, count:int|null, status:string, error:string|null}>
 */
function historyFetchSeries(PDO $pdo, string $env, string $api, int $fromTs, int $toTs): array
{
    $stmt = $pdo->prepare('
        SELECT ts_utc, count, status, error
        FROM snapshots
        WHERE env = :env AND api = :api AND ts_utc BETWEEN :from_ts AND :to_ts
        ORDER BY ts_utc ASC
    ');
    $stmt->execute([':env' => $env, ':api' => $api, ':from_ts' => $fromTs, ':to_ts' => $toTs]);

    /** @var list<array{ts_utc:int, count:int|null, status:string, error:string|null}> $rows */
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    return $rows;
}

/**
 * Löst einen Zeitraum-Preset-Key (siehe config.php 'history.range_options')
 * zu einem konkreten [from, to]-Unix-Timestamp-Paar (UTC) auf. 'custom'
 * nutzt $customFrom/$customTo (Format Y-m-d, aus <input type=date>),
 * ausgewertet in der Zeitzone Europe/Zurich (Tagesgrenzen lokal, nicht UTC).
 *
 * @return array{from:int, to:int}
 */
function historyResolveRange(string $rangeKey, ?string $customFrom, ?string $customTo): array
{
    $tz  = new DateTimeZone('Europe/Zurich');
    $now = new DateTimeImmutable('now', $tz);
    $to  = $now->getTimestamp();

    $from = match ($rangeKey) {
        '7d'     => $now->modify('-7 days')->getTimestamp(),
        '30d'    => $now->modify('-30 days')->getTimestamp(),
        '90d'    => $now->modify('-90 days')->getTimestamp(),
        '1y'     => $now->modify('-1 year')->getTimestamp(),
        'all'    => 0,
        'custom' => (function () use ($customFrom, $tz): int {
            if ($customFrom === null || $customFrom === '') {
                return 0;
            }
            try {
                return (new DateTimeImmutable($customFrom, $tz))->setTime(0, 0)->getTimestamp();
            } catch (Exception) {
                return 0;
            }
        })(),
        default => $now->modify('-7 days')->getTimestamp(),
    };

    if ($rangeKey === 'custom' && $customTo !== null && $customTo !== '') {
        try {
            $to = (new DateTimeImmutable($customTo, $tz))->setTime(23, 59, 59)->getTimestamp();
        } catch (Exception) {
            // $to bleibt "jetzt"
        }
    }

    return ['from' => $from, 'to' => $to];
}

/**
 * Liest einen Wert aus einem verschachtelten Array via Punkt-Pfad,
 * z.B. "can_issue.vct_name#de-CH" oder "request.scope".
 */
function getPath(array $entry, string $path): mixed
{
    $value = $entry;
    foreach (explode('.', $path) as $segment) {
        if (!is_array($value) || !array_key_exists($segment, $value)) {
            return null;
        }
        $value = $value[$segment];
    }
    return $value;
}

/**
 * Formatiert einen Unix-Timestamp lesbar (Europe/Zurich).
 */
function formatUnixTimestamp(mixed $timestamp): string
{
    if ($timestamp === null || $timestamp === '') {
        return '-';
    }
    $dt = (new DateTimeImmutable('@' . (int) $timestamp))->setTimezone(new DateTimeZone('Europe/Zurich'));
    return $dt->format('d.m.Y H:i:s');
}

/**
 * Formatiert einen ISO-8601 Zeitstring lesbar (Europe/Zurich).
 */
function formatIsoTimestamp(mixed $isoString): string
{
    if ($isoString === null || $isoString === '') {
        return '-';
    }
    try {
        $dt = (new DateTimeImmutable((string) $isoString))->setTimezone(new DateTimeZone('Europe/Zurich'));
        return $dt->format('d.m.Y H:i:s');
    } catch (Exception) {
        return htmlspecialchars((string) $isoString);
    }
}

/**
 * Formatiert einen Zellenwert gemäss Spaltentyp für die Anzeige (bereits escaped).
 */
function formatCellValue(mixed $value, string $type): string
{
    if ($value === null) {
        return '-';
    }
    return match ($type) {
        'unix'     => htmlspecialchars(formatUnixTimestamp($value)),
        'iso'      => htmlspecialchars(formatIsoTimestamp($value)),
        'list'     => is_array($value) ? htmlspecialchars(implode(', ', $value)) : htmlspecialchars((string) $value),
        // Zeigt einen PHP-Bool literal als "true"/"false" (keine Übersetzung, 1:1 wie im JWT).
        'raw_bool' => is_bool($value) ? ($value ? 'true' : 'false') : htmlspecialchars((string) $value),
        default    => htmlspecialchars(is_scalar($value) ? (string) $value : (string) json_encode($value, JSON_INVALID_UTF8_SUBSTITUTE)),
    };
}

/**
 * Sammelt alle Sprachvarianten eines mehrsprachigen Feldes aus einem Eintrag.
 * Erkennt sowohl das Basisfeld (z.B. "purpose_name") als auch Varianten mit
 * Sprach-Suffix ("purpose_name#en", "purpose_name#de-CH", ...).
 * Gibt ein assoziatives Array [Sprachlabel => Wert] zurück.
 */
function collectMultilangValues(array $entry, string $prefix): array
{
    $values = [];
    foreach ($entry as $key => $value) {
        if ($key === $prefix) {
            $values['default'] = $value;
        } elseif (str_starts_with($key, $prefix . '#')) {
            $lang = substr($key, strlen($prefix) + 1);
            $values[$lang] = $value;
        }
    }
    return $values;
}

/**
 * Rendert eine mehrsprachige Zelle: eine Zeile pro Sprachvariante.
 */
function formatMultilangCell(array $entry, string $prefix): string
{
    $values = collectMultilangValues($entry, $prefix);
    if (empty($values)) {
        return '-';
    }
    $lines = [];
    foreach ($values as $lang => $value) {
        $lines[] = '<strong>' . htmlspecialchars((string) $lang) . ':</strong> ' . htmlspecialchars((string) $value);
    }
    return implode('<br>', $lines);
}

/**
 * Rendert eine "registry_ids"-Zelle: Liste von {type, value}-Objekten,
 * eine Zeile pro Eintrag ("TYPE: Wert"). Ein leerer Wert wird explizit
 * als "(leer)" markiert statt eine leere Zelle zu zeigen.
 */
function formatRegistryIdsCell(mixed $value): string
{
    if (!is_array($value) || $value === []) {
        return '-';
    }
    $lines = [];
    foreach ($value as $item) {
        if (!is_array($item)) {
            continue;
        }
        $type = $item['type'] ?? '?';
        $val  = $item['value'] ?? null;
        $rendered = ($val === '') ? '<span class="value-empty">' . htmlspecialchars(t('cell.empty')) . '</span>' : htmlspecialchars((string) $val);
        $lines[] = htmlspecialchars((string) $type) . ': ' . $rendered;
    }
    return $lines !== [] ? implode('<br>', $lines) : '-';
}

/**
 * Rendert den (pro Zeile aufgelösten) Status als farbigen Badge — dieselbe
 * Optik wie beim list_meta-Panel von ncTLS/piTLS, nur pro Tabellenzeile
 * statt einmal für die ganze Liste. Erwartet den kompletten Eintrag, da die
 * Werte unter '_status_value'/'_status_label' liegen (siehe attachRowStatuses).
 */
function formatStatusBadgeCell(array $entry): string
{
    if (!array_key_exists('_status_label', $entry)) {
        return '-';
    }

    $value = $entry['_status_value'] ?? null;
    $label = statusDisplayLabel($value, $entry['_status_label'] ?? '-');

    if ($label === '-') {
        return '-';
    }

    $class = match ($value) {
        0 => 'status-valid',
        1 => 'status-revoked',
        2 => 'status-suspended',
        default => 'status-unknown',
    };

    return '<span class="status-badge ' . $class . '">' . htmlspecialchars($label) . '</span>';
}

/**
 * Sammelt alle vct_values aus request.query.credentials[*].meta.vct_values
 * eines vqPS-Eintrags (es kann theoretisch mehrere Credential-Queries pro
 * Eintrag geben) und zeigt sie zusammengefasst in einer Zelle.
 */
function formatVctValuesCell(array $entry): string
{
    $credentials = $entry['request']['query']['credentials'] ?? [];
    if (!is_array($credentials)) {
        return '-';
    }

    $values = [];
    foreach ($credentials as $credential) {
        $vctValues = $credential['meta']['vct_values'] ?? [];
        if (is_array($vctValues)) {
            foreach ($vctValues as $v) {
                $values[] = (string) $v;
            }
        }
    }
    $values = array_values(array_unique($values));

    return $values !== [] ? htmlspecialchars(implode(', ', $values)) : '-';
}

/**
 * Sammelt alle 'vct'-Werte aus can_issue (piaTS). Robust gegenüber beiden
 * denkbaren Formen: ein einzelnes Objekt {vct: "..."} (aktuell beobachtet)
 * ODER ein Array mehrerer solcher Objekte, falls das künftig vorkommt.
 */
function formatCanIssueCell(array $entry): string
{
    $canIssue = $entry['can_issue'] ?? null;
    if (!is_array($canIssue)) {
        return '-';
    }

    // Einzelnes Objekt (hat einen 'vct'-Schlüssel) in ein Array mit einem
    // Element umwandeln, damit dieselbe Sammel-Logik für beide Fälle greift.
    $items = array_key_exists('vct', $canIssue) ? [$canIssue] : $canIssue;

    $values = [];
    foreach ($items as $item) {
        if (is_array($item) && isset($item['vct'])) {
            $values[] = (string) $item['vct'];
        }
    }
    $values = array_values(array_unique($values));

    return $values !== [] ? htmlspecialchars(implode(', ', $values)) : '-';
}

/**
 * Rendert die "Amount of allowed issuers"-Zelle einer piTLS-Zeile: bei 0
 * Issuers nur Text ("0 Issuer", nicht klickbar), sonst ein Link ("X
 * Issuer(s)"), der direkt zur piaTS-Ansicht springt, vorgefiltert per
 * Suchbegriff auf das VCT (nutzt die bestehende Volltextsuche — kein
 * eigener Filter-Mechanismus nötig). $envKey/$targetApiKey bauen den Link,
 * 'onclick="event.stopPropagation()"' verhindert, dass der Klick zusätzlich
 * das Aufklappen der Zeile auslöst (siehe Option A: Klick auf die Zeile
 * selbst klappt die Issuer-Liste inline auf).
 */
function formatIssuerCountCell(array $entry, string $envKey, string $targetApiKey): string
{
    $count = (int) ($entry['_issuer_count'] ?? 0);
    $label = match (true) {
        $count === 0 => '0 Issuer',
        $count === 1 => '1 Issuer',
        default => $count . ' Issuers',
    };

    // Hinweis, wenn nicht alle Issuer gezählt werden (widerrufen/suspendiert/unbekannt).
    $total = (int) ($entry['_issuer_total'] ?? $count);
    $title = $total > $count ? ' title="' . htmlspecialchars(t('issuers.not_counted', [$total - $count, $total])) . '"' : '';

    if ($count === 0) {
        return '<span class="issuer-count issuer-count-zero"' . $title . '>' . htmlspecialchars($label) . '</span>';
    }

    // piaTS wird mit dem Filter "nur gültige" geöffnet (passend zur gezählten Zahl).
    $vct = (string) ($entry['value'] ?? '');
    $url = '?' . http_build_query(['env' => $envKey, 'api' => $targetApiKey, 'q' => $vct, 'status' => 'valid']);

    return '<a href="' . htmlspecialchars($url) . '" class="issuer-count-link"' . $title . ' onclick="event.stopPropagation()">'
        . htmlspecialchars($label) . '</a>';
}

/**
 * Rendert die aufgeklappte Issuer-Liste eines piTLS-Eintrags (siehe
 * attachIssuerCounts): DID, Name (aus idTS), Status und Validity je
 * piaTS-Issuer — dieselben Formatter wie in der piaTS-Tabelle selbst,
 * damit die Darstellung konsistent bleibt.
 */
function renderIssuerList(array $issuers): string
{
    if ($issuers === []) {
        return '<p class="detail-empty">' . htmlspecialchars(t('issuers.none_found')) . '</p>';
    }

    // In einen .table-scroll-Wrapper (wie die Haupttabellen) UND bewusst NICHT
    // vom generischen Mobile-"Tabelle wird Kartenliste"-Umbau erfasst (siehe
    // CSS-Ausnahme für table.issuer-table in index.php) -- eine 4-spaltige
    // Liste ohne data-label-Attribute würde dort unbeschriftet zerlaufen.
    $html = '<div class="table-scroll"><table class="issuer-table"><thead><tr>'
        . '<th>DID</th><th>Name (from idTS)</th><th>Status</th><th>Validity</th>'
        . '</tr></thead><tbody>';

    foreach ($issuers as $issuer) {
        $did  = htmlspecialchars((string) ($issuer['sub'] ?? '-'));
        $name = htmlspecialchars((string) ($issuer['_entity_name'] ?? '-'));
        $html .= '<tr>'
            . '<td class="cell-did">' . $did . '</td>'
            . '<td>' . $name . '</td>'
            . '<td>' . formatStatusBadgeCell($issuer) . '</td>'
            . '<td>' . formatValidityBadgeCell($issuer) . '</td>'
            . '</tr>';
    }

    $html .= '</tbody></table></div>';
    return $html;
}

/**
 * Berechnet (rein clientseitig aus nbf/exp, KEIN API-Call) ob ein Eintrag
 * aktuell gültig ist: "not yet valid" | "valid" | "expired". Für APIs ohne
 * eigene status_list-Referenz (z.B. vqPS), im Unterschied zu
 * attachRowStatuses/resolveTrustListStatus, die eine externe Statusliste abfragen.
 */
function computeValidity(mixed $nbf, mixed $exp): array
{
    $now = time();

    if ($nbf !== null && $now < (int) $nbf) {
        return ['label' => t('validity.not_yet_valid'), 'class' => 'status-suspended'];
    }
    if ($exp !== null && $now > (int) $exp) {
        return ['label' => t('validity.expired'), 'class' => 'status-revoked'];
    }
    return ['label' => t('validity.valid'), 'class' => 'status-valid'];
}

/**
 * Rendert die berechnete Validity als farbigen Badge (gleiche Optik wie
 * formatStatusBadgeCell, aber ohne HTTP-Abruf — reine nbf/exp-Berechnung).
 */
function formatValidityBadgeCell(array $entry): string
{
    $validity = computeValidity($entry['nbf'] ?? null, $entry['exp'] ?? null);
    return '<span class="status-badge ' . $validity['class'] . '">' . htmlspecialchars($validity['label']) . '</span>';
}

/**
 * Text einer Tabellenzelle für den CSV-Export: dieselben Formatter wie die
 * HTML-Tabelle, danach als Klartext (Status/Validity ohne Pille, <br> wird
 * zu einem Zeilenumbruch in der Zelle).
 */
function exportCellText(array $entry, array $col, string $envKey, string $issuerApiKey): string
{
    $html = match ($col['type']) {
        'multilang'     => formatMultilangCell($entry, $col['key']),
        'registry_ids'  => formatRegistryIdsCell(getPath($entry, $col['key'])),
        'status_badge'  => formatStatusBadgeCell($entry),
        'vct_values'    => formatVctValuesCell($entry),
        'can_issue'     => formatCanIssueCell($entry),
        'validity_badge' => formatValidityBadgeCell($entry),
        'issuer_count'  => formatIssuerCountCell($entry, $envKey, $issuerApiKey),
        default         => formatCellValue(getPath($entry, $col['key']), $col['type']),
    };

    $text = preg_replace('#<br\s*/?>#i', "\n", $html) ?? $html;
    $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return trim($text);
}

/**
 * Maskiert einen Wert für eine CSV-Zelle (Trennzeichen ';', Anführungszeichen
 * verdoppelt). Werte stammen aus externen Registries: beginnt ein Wert mit
 * =, +, @ (oder '-' gefolgt von weiterem Text), würde Excel ihn als Formel
 * auswerten — dem wird ein Apostroph vorangestellt (CSV-/Formula-Injection).
 */
function csvCell(string $value): string
{
    if ($value !== '' && (strpbrk($value[0], "=+@\t\r") !== false || ($value[0] === '-' && strlen($value) > 1 && !is_numeric($value)))) {
        $value = "'" . $value;
    }
    return '"' . str_replace('"', '""', $value) . '"';
}

/**
 * Prüft, ob ein Array assoziativ ist (vs. einer sequentiellen Liste entspricht).
 */
function isAssocArray(array $arr): bool
{
    return $arr !== [] && array_keys($arr) !== range(0, count($arr) - 1);
}

/**
 * Rendert einen einzelnen skalaren Wert für die Detailansicht:
 * - Bool literal als "true"/"false" (keine Übersetzung)
 * - Leerer String explizit als "(leer)" markiert (unterscheidet sich von
 *   einem komplett fehlenden Feld, das gar nicht erst als Zeile auftaucht)
 * - Alles andere normal escaped
 */
function renderDetailScalar(mixed $value): string
{
    if (is_bool($value)) {
        return $value ? 'true' : 'false';
    }
    if ($value === '') {
        return '<span class="value-empty">' . htmlspecialchars(t('cell.empty')) . '</span>';
    }
    if ($value === null) {
        return '-';
    }
    return htmlspecialchars((string) $value);
}

/**
 * Rendert einen beliebig verschachtelten Payload/Header rekursiv als
 * übersichtliche Schlüssel/Wert-Struktur für die Detailansicht.
 * Bekannte Zeitstempel-Felder (nbf/exp/iat) werden zusätzlich lesbar formatiert.
 * Felder, die bei einem Eintrag komplett fehlen, tauchen hier gar nicht erst
 * auf (nur tatsächlich vorhandene Schlüssel werden iteriert) — das macht auf
 * einen Blick sichtbar, welche Sprachvarianten/Felder bei welchem Eintrag
 * überhaupt existieren.
 */
function renderDetailTree(mixed $data, array $dateKeys = ['nbf', 'exp', 'iat']): string
{
    if (!is_array($data)) {
        return renderDetailScalar($data);
    }

    if ($data === [] ) {
        return '<span class="detail-empty">-</span>';
    }

    if (isAssocArray($data)) {
        $html = '<table class="detail-kv">';
        foreach ($data as $key => $value) {
            if ($key === '_jwt_header' || $key === '_jwt' || $key === '_status_value' || $key === '_status_label' || $key === '_status_error' || $key === '_entity_name') {
                continue; // Header wird separat gerendert, diese Felder sind synthetisch (eigene Spalte/Badge)
            }
            $label = htmlspecialchars((string) $key);

            if (in_array($key, $dateKeys, true) && is_scalar($value) && $value !== '') {
                $rendered = htmlspecialchars(formatUnixTimestamp($value))
                    . ' <span class="detail-raw">(' . htmlspecialchars((string) $value) . ')</span>';
            } elseif (is_array($value)) {
                $rendered = renderDetailTree($value, $dateKeys);
            } else {
                $rendered = renderDetailScalar($value);
            }

            $html .= "<tr><th>{$label}</th><td>{$rendered}</td></tr>";
        }
        $html .= '</table>';
        return $html;
    }

    $html = '<ul class="detail-list">';
    foreach ($data as $value) {
        $isObject = is_array($value);
        $itemClass = $isObject ? 'is-object' : 'is-scalar';
        $html .= '<li class="' . $itemClass . '">' . ($isObject ? renderDetailTree($value, $dateKeys) : renderDetailScalar($value)) . '</li>';
    }
    $html .= '</ul>';
    return $html;
}

/**
 * Rendert die vollständige Detailansicht (Header + Payload) für einen
 * Eintrag. Der JWT-Header steht zuerst, da er den Payload technisch "einleitet".
 * $showHeader = false unterdrückt den Header-Abschnitt (z.B. bei vqPS, wo der
 * Header bei jedem Eintrag identisch ist und daher keinen Mehrwert bietet).
 *
 * Ist das Original-JWT vorhanden ('_jwt'), gibt es oben einen Umschalter
 * "Aufbereitet | Rohdaten" (wie beim Base Registry): Rohdaten = das unverändert
 * dekodierte JSON (siehe renderRawJwt()).
 */
function renderEntryDetail(array $entry, bool $showHeader = true): string
{
    $header = $entry['_jwt_header'] ?? null;

    $html = '';
    if ($showHeader && $header !== null) {
        $html .= '<div class="detail-section"><h4>' . htmlspecialchars(t('detail.jwt_header')) . '</h4>' . renderDetailTree($header) . '</div>';
    }
    $html .= '<div class="detail-section"><h4>' . htmlspecialchars(t('detail.payload_full')) . '</h4>' . renderDetailTree($entry) . '</div>';

    $jwt = $entry['_jwt'] ?? null;
    if (!is_string($jwt) || $jwt === '') {
        return $html;
    }

    return '<div class="raw-root">'
        . '<div class="raw-switch"><div class="br-seg" role="group">'
        .   '<button type="button" class="active" data-raw-view="parsed">' . htmlspecialchars(t('detail.view_parsed')) . '</button>'
        .   '<button type="button" data-raw-view="raw">' . htmlspecialchars(t('detail.view_raw')) . '</button>'
        . '</div></div>'
        . '<div data-raw-pane="parsed">' . $html . '</div>'
        . '<div data-raw-pane="raw" hidden>' . renderRawJwt($jwt, $showHeader) . '</div>'
        . '</div>';
}

/** Ein JWT-Segment (Base64URL) als formatiertes JSON; bei Fehlern der rohe Text. */
function prettyJwtSegment(string $segment): string
{
    $json = base64UrlDecode($segment);
    // Als Objekte (nicht assoziativ) dekodieren, damit leere {} nicht zu [] werden.
    $decoded = json_decode($json);
    return $decoded === null
        ? $json
        : (string) json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

/**
 * Rohdaten-Ansicht eines JWT: Header und Payload als formatiertes JSON sowie das
 * Original-JWT (kompakt, inkl. Signatur), je mit Kopieren-Knopf. Reine Anzeige,
 * es wird nichts an Dritte gesendet.
 */
function renderRawJwt(string $jwt, bool $showHeader = true): string
{
    $parts = explode('.', trim($jwt));
    if (count($parts) < 2) {
        return '';
    }

    $block = static function (string $title, string $content, bool $pre = true): string {
        return '<div class="br-raw-wrap raw-block">'
            . '<div class="br-raw-head"><h4>' . htmlspecialchars($title) . '</h4>'
            . '<span class="br-raw-actions"><button type="button" class="br-btn" data-raw-copy data-copied="' . htmlspecialchars(t('base.copied')) . '">' . htmlspecialchars(t('base.copy')) . '</button></span></div>'
            . '<pre class="br-raw' . ($pre ? '' : ' raw-token') . '">' . htmlspecialchars($content) . '</pre>'
            . '</div>';
    };

    $html = '';
    if ($showHeader) {
        $html .= $block(t('detail.raw_header'), prettyJwtSegment($parts[0]));
    }
    $html .= $block(t('detail.raw_payload'), prettyJwtSegment($parts[1]));
    $html .= $block(t('detail.raw_token'), trim($jwt), false);
    return '<div class="raw-view">' . $html . '</div>';
}

/**
 * Flacht einen beliebig verschachtelten Wert zu einem durchsuchbaren String ab.
 */
function flattenForSearch(mixed $value): string
{
    if (is_array($value)) {
        $parts = [];
        foreach ($value as $v) {
            $parts[] = flattenForSearch($v);
        }
        return implode(' ', $parts);
    }
    return (string) $value;
}

/**
 * Zerlegt eine Suchanfrage in OR-Gruppen aus AND-Termen.
 * Syntax: Leerzeichen und "AND" verknüpfen mit UND, "OR" mit ODER (bindet
 * schwächer als UND), "Anführungszeichen" ergeben eine exakte Phrase,
 * ein vorangestelltes "-" schliesst einen Begriff aus.
 * Rückgabe: Liste von Gruppen; jede Gruppe ist eine Liste von
 * ['text' => string (lowercase), 'not' => bool].
 */
function parseSearchQuery(string $query): array
{
    preg_match_all('/(-?)"([^"]*)"|(\S+)/u', $query, $m, PREG_SET_ORDER);
    $groups = [[]];
    foreach ($m as $t) {
        if (isset($t[3]) && $t[3] !== '') {
            $word = $t[3];
            if ($word === 'AND') {
                continue;
            }
            if ($word === 'OR') {
                if ($groups[count($groups) - 1] !== []) {
                    $groups[] = [];
                }
                continue;
            }
            $not = false;
            if (strlen($word) > 1 && $word[0] === '-') {
                $not = true;
                $word = substr($word, 1);
            }
            $groups[count($groups) - 1][] = ['text' => mb_strtolower($word), 'not' => $not];
        } else {
            $phrase = trim($t[2]);
            if ($phrase !== '') {
                $groups[count($groups) - 1][] = ['text' => mb_strtolower($phrase), 'not' => $t[1] === '-'];
            }
        }
    }
    return array_values(array_filter($groups, fn($g) => $g !== []));
}

/**
 * Filtert eine Liste von Einträgen anhand einer Suchanfrage (case-insensitive,
 * über alle Felder des Eintrags hinweg). Mehrere Begriffe werden mit UND
 * verknüpft (siehe parseSearchQuery() für OR, Phrasen und Ausschluss).
 */
function filterEntries(array $entries, string $query): array
{
    $groups = parseSearchQuery(trim($query));
    if ($groups === []) {
        return $entries;
    }

    return array_values(array_filter($entries, function ($entry) use ($groups) {
        // Das Original-JWT (Base64URL) würde sonst zufällige Treffer liefern
        unset($entry['_jwt']);
        $haystack = mb_strtolower(flattenForSearch($entry));
        foreach ($groups as $terms) {
            foreach ($terms as $term) {
                if (str_contains($haystack, $term['text']) === $term['not']) {
                    continue 2;
                }
            }
            return true;
        }
        return false;
    }));
}

/**
 * Ermittelt einen vergleichbaren Sortier-Wert für einen Eintrag anhand einer
 * Spalten-Definition — je nach Spaltentyp aus dem rohen Feld oder aus der
 * berechneten/aggregierten Darstellung (z.B. Status-Label, VCT-Liste als Text).
 * HTML aus den Zell-Renderern wird dabei entfernt (reiner Text zum Vergleichen).
 */
function getSortValue(array $entry, array $col): int|float|string
{
    return match ($col['type']) {
        'unix' => (int) (getPath($entry, $col['key']) ?? PHP_INT_MIN),
        'iso' => (function () use ($entry, $col) {
            $v = getPath($entry, $col['key']);
            return $v ? (strtotime((string) $v) ?: 0) : 0;
        })(),
        'raw_bool' => getPath($entry, $col['key']) === true ? 1 : 0,
        'multilang' => (function () use ($entry, $col) {
            $vals = collectMultilangValues($entry, $col['key']);
            return mb_strtolower((string) (reset($vals) ?: ''));
        })(),
        'registry_ids' => mb_strtolower(strip_tags(formatRegistryIdsCell(getPath($entry, $col['key'])))),
        'status_badge' => mb_strtolower(statusDisplayLabel(isset($entry['_status_value']) ? (int) $entry['_status_value'] : null, $entry['_status_label'] ?? '-')),
        'validity_badge' => mb_strtolower(computeValidity($entry['nbf'] ?? null, $entry['exp'] ?? null)['label']),
        'vct_values' => mb_strtolower(strip_tags(formatVctValuesCell($entry))),
        'can_issue' => mb_strtolower(strip_tags(formatCanIssueCell($entry))),
        'issuer_count' => (int) ($entry['_issuer_count'] ?? 0),
        'list' => (function () use ($entry, $col) {
            $v = getPath($entry, $col['key']);
            return mb_strtolower(is_array($v) ? implode(', ', $v) : (string) $v);
        })(),
        default => mb_strtolower((function () use ($entry, $col) {
            $v = getPath($entry, $col['key']);
            return is_scalar($v) ? (string) $v : '';
        })()),
    };
}

/**
 * Sortiert die (bereits gefilterten) Einträge nach einer Spalte, auf- oder
 * absteigend. Zeitstempel-/Bool-Spalten werden numerisch verglichen, alles
 * andere als Text (case-insensitive). Läuft über den KOMPLETTEN Datensatz,
 * nicht nur die aktuell angezeigte Seite — konsistent mit der Pagination.
 */
function sortEntries(array $entries, array $col, string $dir): array
{
    $isNumeric = in_array($col['type'], ['unix', 'iso', 'raw_bool', 'issuer_count'], true);

    usort($entries, function ($a, $b) use ($col, $isNumeric) {
        $va = getSortValue($a, $col);
        $vb = getSortValue($b, $col);
        return $isNumeric ? ($va <=> $vb) : strcasecmp((string) $va, (string) $vb);
    });

    return $dir === 'desc' ? array_reverse($entries) : $entries;
}

/**
 * Holt die Einträge (+ ggf. list_meta) für Umgebung+API, nutzt einen
 * Session-Cache und erzwingt bei $forceRefresh einen frischen API-Abruf.
 *
 * @param array $allApis Die komplette 'apis'-Config, wird an fetchAllEntries
 *   durchgereicht (nötig für 'enrich_name_from', das eine andere API nachschlägt).
 * @return array{entries: array, list_meta: array|null}
 */
function getEntriesCached(string $envKey, string $apiKey, string $baseUrl, array $apiCfg, bool $forceRefresh, int $ttl, array $allApis = []): array
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    $cacheKey = $envKey . '::' . $apiKey;
    $cached = $_SESSION['trust_explorer_cache'][$cacheKey] ?? null;

    // Ein Cache-Eintrag mit abweichender/fehlender CACHE_SCHEMA_VERSION stammt
    // von vor einer Struktur-Änderung (z.B. neues Feld list_meta/_entity_name)
    // und gilt automatisch als veraltet — verhindert, dass ein Code-Update
    // durch einen stehengebliebenen Session-Cache "verschluckt" wird.
    $isStale = $cached === null
        || ($cached['schema'] ?? null) !== CACHE_SCHEMA_VERSION
        || (time() - $cached['fetched_at']) > $ttl;

    if ($forceRefresh || $isStale) {
        $result = fetchAllEntries($envKey, $baseUrl, $apiCfg, $allApis, $ttl);
        $_SESSION['trust_explorer_cache'][$cacheKey] = [
            'entries'    => $result['entries'],
            'list_meta'  => $result['list_meta'],
            'schema'     => CACHE_SCHEMA_VERSION,
            'fetched_at' => time(),
        ];
        return $result;
    }

    return ['entries' => $cached['entries'], 'list_meta' => $cached['list_meta'] ?? null];
}

/**
 * Berechnet eine kompakte Liste von Seitenzahlen für die Pagination-Leiste,
 * mit "…"-Platzhaltern für ausgelassene Bereiche. $current und $total sind
 * 1-basiert (Seite 1 = erste Seite). Beispiel bei current=5, total=7:
 * [1, '…', 4, 5, 6, '…', 7] (aktuelle Seite ± 1 sichtbar, Rand immer sichtbar).
 *
 * @return array<int|string>
 */
function paginationRange(int $current, int $total): array
{
    if ($total <= 1) {
        return [1];
    }

    $delta = 1;
    $left  = max(1, $current - $delta);
    $right = min($total, $current + $delta);

    $range = [1];
    if ($left > 2) {
        $range[] = '…';
    }
    for ($i = $left; $i <= $right; $i++) {
        if ($i !== 1 && $i !== $total) {
            $range[] = $i;
        }
    }
    if ($right < $total - 1) {
        $range[] = '…';
    }
    $range[] = $total;

    return $range;
}

/**
 * Durchsucht alle konfigurierten APIs einer Umgebung nach einem Suchbegriff
 * (typischerweise eine DID) und liefert pro API die gefundenen Einträge.
 * Nutzt für jede API den bestehenden Session-Cache (kein Zwangs-Refresh),
 * damit die Suche selbst keine 6 neuen API-Abrufe auslöst, wenn die Daten
 * schon geladen sind.
 *
 * Rückgabe: [
 *   'results' => [apiKey => ['entries' => [...], 'error' => string|null]],
 *   'entity_name' => string|null,  // aus dem ersten idTS-Treffer, falls vorhanden
 * ]
 */
function searchAcrossApis(string $envKey, string $baseUrl, array $apis, string $needle, int $ttl): array
{
    $results = [];
    $entityName = null;

    foreach ($apis as $apiKey => $apiCfg) {
        try {
            $fetched = getEntriesCached($envKey, $apiKey, $baseUrl, $apiCfg, false, $ttl, $apis);
            $matches = filterEntries($fetched['entries'], $needle);
            $results[$apiKey] = ['entries' => $matches, 'error' => null];

            if ($apiKey === 'idTS' && $entityName === null) {
                foreach ($matches as $match) {
                    if (!empty($match['entity_name'])) {
                        $entityName = (string) $match['entity_name'];
                        break;
                    }
                }
            }
        } catch (Throwable $e) {
            $results[$apiKey] = ['entries' => [], 'error' => $e->getMessage()];
        }
    }

    return ['results' => $results, 'entity_name' => $entityName];
}

/* ========================================================================
 * Base Registry (did.jsonl pro DID)
 *
 * Jede DID eines Trust-Registry-Eintrags (z.B. idTS 'sub') hat im Base
 * Registry der Umgebung ein DID-Log:
 *   did:<tdw|webvh>:<scid>:<host>:api:v1:did:<UUID>
 *     -> <base_registry_url>/api/v1/did/<UUID>/did.jsonl
 *
 * Unterstützte Log-Formate (eine JSON-Zeile pro Version):
 *   - did:tdw 0.3:   Array  [versionId, versionTime, parameters, {"value": doc}|{"patch": [...]}, proof]
 *   - did:webvh 1.0: Objekt {versionId, versionTime, parameters, state, proof}
 * ====================================================================== */

/**
 * Wird erhöht, wenn sich die Struktur eines gecachten Base-Registry-Ergebnisses ändert.
 */
const BASE_REGISTRY_CACHE_SCHEMA = 1;

/**
 * Zerlegt eine DID des Base Registry und prüft, ob sie zu DIESEM Base Registry
 * gehört (Host == Host von $registryUrl, Pfad api:v1:did:<uuid>).
 * null = DID liegt nicht in diesem Base Registry ("External DID").
 *
 * @return array{method: string, scid: string, uuid: string}|null
 */
function baseRegistryDidInfo(string $did, string $registryUrl): ?array
{
    $parts = explode(':', trim($did));
    if (count($parts) !== 8 || $parts[0] !== 'did' || $parts[4] !== 'api' || $parts[5] !== 'v1' || $parts[6] !== 'did') {
        return null;
    }
    if (!in_array($parts[1], ['tdw', 'webvh'], true)) {
        return null;
    }
    if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $parts[7])) {
        return null;
    }

    $regHost = parse_url($registryUrl, PHP_URL_HOST);
    $regPort = parse_url($registryUrl, PHP_URL_PORT);
    if (!is_string($regHost)) {
        return null;
    }
    $expected = strtolower($regHost . ($regPort !== null ? ':' . $regPort : ''));
    // Ein Port steht in einer DID als %3A (did:tdw:...:host%3A8080:...)
    if (strtolower(rawurldecode($parts[3])) !== $expected) {
        return null;
    }

    return ['method' => $parts[1], 'scid' => $parts[2], 'uuid' => strtolower($parts[7])];
}

/**
 * GET ohne Exception bei Nicht-200 (anders als httpGet): das Base Registry
 * unterscheidet "404 = kein Eintrag" von "Fehler/nicht erreichbar".
 *
 * @return array{code: int, body: string, error: string|null}
 */
function httpGetStatus(string $url, int $timeout = 10): array
{
    $ch = curl_init($url);
    if ($ch === false) {
        return ['code' => 0, 'body' => '', 'error' => t('error.curl', [0, 'curl_init'])];
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_HTTPHEADER     => ['Accept: application/jsonl, application/json, text/plain, */*'],
    ]);
    $body = curl_exec($ch);
    $errNo = curl_errno($ch);
    $err = curl_error($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [
        'code'  => $code,
        'body'  => is_string($body) ? $body : '',
        'error' => $errNo !== 0 ? t('error.curl', [$errNo, $err]) : null,
    ];
}

/**
 * Normalisiert EINE did.jsonl-Zeile (did:tdw 0.3 = Array, did:webvh 1.0 = Objekt).
 *
 * @return array{version_id: mixed, number: int, time: mixed, parameters: array, doc: array|null, is_patch: bool}
 */
function baseRegistryNormalizeLine(array $line, int $index): array
{
    if ($line !== [] && !isAssocArray($line)) {
        // did:tdw 0.3: [versionId, versionTime, parameters, {"value": doc}|{"patch": [...]}, proof]
        $versionId = $line[0] ?? null;
        $time      = $line[1] ?? null;
        $params    = is_array($line[2] ?? null) ? $line[2] : [];
        $state     = is_array($line[3] ?? null) ? $line[3] : [];
        $doc       = is_array($state['value'] ?? null) ? $state['value'] : null;
        $isPatch   = isset($state['patch']);
    } else {
        // did:webvh 1.0: {versionId, versionTime, parameters, state, proof}
        $versionId = $line['versionId'] ?? null;
        $time      = $line['versionTime'] ?? null;
        $params    = is_array($line['parameters'] ?? null) ? $line['parameters'] : [];
        $state     = $line['state'] ?? null;
        $doc       = is_array($state) ? $state : null;
        $isPatch   = false;
    }

    $number = (is_string($versionId) && preg_match('/^(\d+)-/', $versionId, $m)) ? (int) $m[1] : $index + 1;

    return ['version_id' => $versionId, 'number' => $number, 'time' => $time, 'parameters' => $params, 'doc' => $doc, 'is_patch' => $isPatch];
}

/**
 * Liest die Schlüssel (verificationMethod) eines DID-Dokuments inkl. ihrer
 * Verwendung (authentication, assertionMethod, ...). Key-IDs sind NICHT fest
 * verdrahtet (tdw: auth-key-01, webvh: version-auth-1, ...).
 *
 * @return list<array{kid: string, type: string, curve: string, purposes: list<string>}>
 */
function baseRegistryKeysFromDoc(?array $doc): array
{
    if ($doc === null) {
        return [];
    }

    $purposesById = [];
    foreach (['authentication', 'assertionMethod', 'keyAgreement', 'capabilityInvocation', 'capabilityDelegation'] as $prop) {
        $refs = $doc[$prop] ?? [];
        foreach (is_array($refs) ? $refs : [] as $ref) {
            $id = is_array($ref) ? ($ref['id'] ?? null) : $ref;
            if (is_string($id)) {
                $purposesById[$id][] = $prop;
            }
        }
    }

    $keys = [];
    $methods = $doc['verificationMethod'] ?? [];
    foreach (is_array($methods) ? $methods : [] as $vm) {
        if (!is_array($vm)) {
            continue;
        }
        $id = (string) ($vm['id'] ?? '');
        $jwk = is_array($vm['publicKeyJwk'] ?? null) ? $vm['publicKeyJwk'] : [];
        $fragment = str_contains($id, '#') ? substr($id, (int) strrpos($id, '#') + 1) : $id;
        $curve = isset($jwk['crv'])
            ? trim((isset($jwk['kty']) ? $jwk['kty'] . ' ' : '') . $jwk['crv'])
            : '-';
        $keys[] = [
            'kid'      => (string) ($jwk['kid'] ?? $fragment),
            'type'     => (string) ($vm['type'] ?? '-'),
            'curve'    => $curve,
            'purposes' => $purposesById[$id] ?? [],
        ];
    }
    return $keys;
}

/**
 * Parst ein komplettes did.jsonl (alle Versionen). Wirft eine RuntimeException
 * bei ungültigem Format.
 *
 * @return array{method: string|null, scid: string|null, did: string|null, versions: int,
 *   created: mixed, last_update: mixed, deactivated: bool, keys: array, keys_version: int|null,
 *   keys_stale: bool, lines: list<array>}
 */
function parseDidLog(string $raw): array
{
    $effective = [];
    $lines = [];
    $lastDoc = null;
    $lastDocVersion = null;
    $deactivated = false;
    $index = 0;

    foreach (preg_split('/\R/', trim($raw)) ?: [] as $rawLine) {
        $rawLine = trim($rawLine);
        if ($rawLine === '') {
            continue;
        }
        $decoded = json_decode($rawLine, true);
        if (!is_array($decoded)) {
            throw new RuntimeException(t('base.error.invalid_log'));
        }

        $n = baseRegistryNormalizeLine($decoded, $index);
        if (!is_string($n['version_id']) || $n['version_id'] === '') {
            throw new RuntimeException(t('base.error.invalid_log'));
        }

        // Parameter gelten ab ihrer Zeile weiter, bis sie überschrieben werden.
        $effective = array_replace($effective, $n['parameters']);
        $deactivatedHere = ($n['parameters']['deactivated'] ?? false) === true;
        $deactivated = ($effective['deactivated'] ?? false) === true;

        if ($n['doc'] !== null) {
            $lastDoc = $n['doc'];
            $lastDocVersion = $n['number'];
        }

        $updateKeys = $effective['updateKeys'] ?? [];
        $lines[] = [
            'number'     => $n['number'],
            'version_id' => $n['version_id'],
            'time'       => $n['time'],
            'update_key' => is_array($updateKeys) && isset($updateKeys[0]) ? (string) $updateKeys[0] : null,
            'change'     => $index === 0 ? 'created' : ($deactivatedHere ? 'deactivated' : 'updated'),
            'is_patch'   => $n['is_patch'],
        ];
        $index++;
    }

    if ($lines === []) {
        throw new RuntimeException(t('base.error.invalid_log'));
    }

    $last = $lines[count($lines) - 1];

    return [
        'method'       => isset($effective['method']) ? (string) $effective['method'] : null,
        'scid'         => isset($effective['scid']) ? (string) $effective['scid'] : null,
        'did'          => is_string($lastDoc['id'] ?? null) ? $lastDoc['id'] : null,
        'versions'     => count($lines),
        'created'      => $lines[0]['time'],
        'last_update'  => $last['time'],
        'deactivated'  => $deactivated,
        'keys'         => baseRegistryKeysFromDoc($lastDoc),
        'keys_version' => $lastDocVersion,
        // did:tdw erlaubt "patch"-Zeilen: dann stammen die Keys aus der letzten
        // Zeile mit vollständigem Dokument (Patch wird nicht angewendet).
        'keys_stale'   => $last['is_patch'],
        'lines'        => $lines,
    ];
}

/**
 * Fragt das Base Registry für eine DID ab.
 *
 * status: found | deactivated | not_found | external | unavailable
 *   - external    = DID gehört nicht zu diesem Base Registry (kein Abruf)
 *   - not_found   = HTTP 404
 *   - unavailable = Netzwerk-/HTTP-/Format-Fehler (Meldung in 'error')
 *
 * @return array{status: string, did: string, uuid: string|null, url: string|null, http_code: int|null,
 *   error: string|null, raw: string|null, log: array|null}
 */
function fetchBaseRegistryEntry(string $registryUrl, string $did, string $pathTemplate = '/api/v1/did/%s/did.jsonl'): array
{
    $base = ['status' => 'external', 'did' => $did, 'uuid' => null, 'url' => null, 'http_code' => null, 'error' => null, 'raw' => null, 'log' => null];

    $info = baseRegistryDidInfo($did, $registryUrl);
    if ($info === null) {
        return $base;
    }

    $base['uuid'] = $info['uuid'];
    $base['url'] = rtrim($registryUrl, '/') . sprintf($pathTemplate, $info['uuid']);

    $resp = httpGetStatus($base['url']);
    $base['http_code'] = $resp['code'];

    if ($resp['error'] !== null) {
        return ['status' => 'unavailable', 'error' => $resp['error']] + $base;
    }
    if ($resp['code'] === 404) {
        return ['status' => 'not_found'] + $base;
    }
    if ($resp['code'] !== 200) {
        return ['status' => 'unavailable', 'error' => t('base.error.http', [$resp['code']])] + $base;
    }

    try {
        $log = parseDidLog($resp['body']);
    } catch (Throwable $e) {
        return ['status' => 'unavailable', 'error' => $e->getMessage()] + $base;
    }

    return ['status' => $log['deactivated'] ? 'deactivated' : 'found', 'raw' => trim($resp['body']), 'log' => $log] + $base;
}

/**
 * Wie fetchBaseRegistryEntry, aber mit Session-Cache für die kompakte Form
 * (ohne Rohantwort — hält die Session klein, auch bei 200 Tabellenzeilen).
 * Nur endgültige Antworten (found/deactivated/not_found) werden gecacht; ein
 * Fehler wird beim nächsten Aufruf erneut versucht.
 *
 * $releaseSessionLock: für parallele AJAX-Aufrufe — die Session wird vor dem
 * (langsamen) HTTP-Abruf freigegeben, sonst würde PHP die Requests nacheinander abarbeiten.
 */
function getBaseRegistryCached(string $envKey, string $registryUrl, string $did, bool $forceRefresh, int $ttl, bool $releaseSessionLock = false, string $pathTemplate = '/api/v1/did/%s/did.jsonl'): array
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    $key = $envKey . '::' . $did;
    $cached = $_SESSION['trust_explorer_base_cache'][$key] ?? null;
    $isFresh = $cached !== null
        && ($cached['schema'] ?? null) === BASE_REGISTRY_CACHE_SCHEMA
        && (time() - (int) $cached['fetched_at']) <= $ttl;

    if (!$forceRefresh && $isFresh) {
        $result = $cached['result'];
        if ($releaseSessionLock) {
            session_write_close();
        }
        return $result;
    }

    if ($releaseSessionLock) {
        session_write_close();
    }

    $result = fetchBaseRegistryEntry($registryUrl, $did, $pathTemplate);

    if (in_array($result['status'], ['found', 'deactivated', 'not_found'], true)) {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        $compact = $result;
        $compact['raw'] = null;
        $_SESSION['trust_explorer_base_cache'][$key] = [
            'schema'     => BASE_REGISTRY_CACHE_SCHEMA,
            'fetched_at' => time(),
            'result'     => $compact,
        ];
        if ($releaseSessionLock) {
            session_write_close();
        }
    }

    return $result;
}

/**
 * Welche DIDs die globale Suche im Base Registry nachschlägt: der Suchbegriff
 * selbst, falls er eine DID ist, plus die 'sub'-DIDs der idTS-Treffer.
 *
 * @return list<string>
 */
function baseRegistryCandidateDids(string $query, array $idtsHits, int $limit): array
{
    $dids = [];
    $query = trim($query);
    if (str_starts_with($query, 'did:')) {
        $dids[] = $query;
    }
    foreach ($idtsHits as $hit) {
        $sub = $hit['sub'] ?? null;
        if (is_string($sub) && str_starts_with($sub, 'did:')) {
            $dids[] = $sub;
        }
    }
    return array_slice(array_values(array_unique($dids)), 0, max(1, $limit));
}

/* ---- Base Registry: HTML-Rendering ---- */

/** "did:tdw:0.3" -> "did:tdw 0.3", "did:webvh:1.0" -> "did:webvh 1.0" */
function baseRegistryMethodLabel(?string $method): string
{
    if ($method === null || $method === '') {
        return '-';
    }
    return (string) preg_replace('/^(did:[a-z0-9]+):(.+)$/i', '$1 $2', $method);
}

function baseRegistryVersionsLabel(int $versions): string
{
    return t($versions === 1 ? 'base.versions_one' : 'base.versions_many', [$versions]);
}

function baseRegistryStatusLabel(string $status): string
{
    return t(match ($status) {
        'found'       => 'base.status.found',
        'deactivated' => 'base.status.deactivated',
        'not_found'   => 'base.status.not_found',
        'external'    => 'base.status.external',
        default       => 'base.status.unavailable',
    });
}

function baseRegistryStatusClass(string $status): string
{
    return match ($status) {
        'found'       => 'br-found',
        'deactivated' => 'br-deactivated',
        'not_found'   => 'br-notfound',
        'external'    => 'br-external',
        default       => 'br-unavailable',
    };
}

/** Hinweis-/Fehlertext für Status ohne DID-Log. */
function baseRegistryMessage(array $res): string
{
    return match ($res['status']) {
        'external'  => t('base.hint.external'),
        'not_found' => t('base.hint.not_found'),
        default     => (string) ($res['error'] ?? t('base.status.unavailable')),
    };
}

/** Kurzform langer Schlüssel/Hashes: "z6MkuVmb…9xWua1u". */
function baseRegistryShorten(?string $value): string
{
    if ($value === null || $value === '') {
        return '-';
    }
    return strlen($value) > 20 ? substr($value, 0, 8) . '…' . substr($value, -7) : $value;
}

/** Tabellen-Chip (Spalte "Base Registry"): Anzahl Versionen bzw. Status. */
function renderBaseRegistryChip(array $res): string
{
    $status = $res['status'];
    $label = ($status === 'found' && $res['log'] !== null)
        ? baseRegistryVersionsLabel((int) $res['log']['versions'])
        : baseRegistryStatusLabel($status);
    $class = 'br-chip ' . baseRegistryStatusClass($status);
    $title = $status === 'unavailable' ? baseRegistryMessage($res) : ($status === 'external' ? t('base.hint.external') : '');

    if ($status === 'external') {
        return '<span class="' . $class . '" title="' . htmlspecialchars($title) . '">' . htmlspecialchars($label) . '</span>';
    }
    return '<button type="button" class="' . $class . '" data-br-open'
        . ($title !== '' ? ' title="' . htmlspecialchars($title) . '"' : '')
        . '>' . htmlspecialchars($label) . '</button>';
}

/** Nicht klickbare Status-Pille (Panel-Kopf in der Suche). */
function renderBaseRegistryPill(array $res): string
{
    return '<span class="br-pill ' . baseRegistryStatusClass($res['status']) . '">' . htmlspecialchars(baseRegistryStatusLabel($res['status'])) . '</span>';
}

/** Zusammenfassung (Definition-Liste) oder Hinweistext, wenn es kein DID-Log gibt. */
function renderBaseRegistrySummary(array $res): string
{
    $log = $res['log'];
    if (!in_array($res['status'], ['found', 'deactivated'], true) || $log === null) {
        return '<p class="br-msg">' . htmlspecialchars(baseRegistryMessage($res)) . '</p>';
    }

    $curves = array_values(array_unique(array_map(static fn (array $k): string => $k['curve'], $log['keys'])));
    $kids   = array_map(static fn (array $k): string => $k['kid'], $log['keys']);
    $keysText = $log['keys'] === []
        ? t('base.keys_none')
        : count($log['keys']) . ' · ' . implode(', ', $curves) . ' (' . implode(', ', $kids) . ')';

    $statusBadge = $log['deactivated']
        ? '<span class="status-badge status-revoked">' . htmlspecialchars(t('base.status.deactivated')) . '</span>'
        : '<span class="status-badge status-valid">' . htmlspecialchars(t('base.status.active')) . '</span>';

    $rows = [
        [t('common.status'),            $statusBadge],
        [t('base.field.method'),        htmlspecialchars(baseRegistryMethodLabel($log['method']))],
        [t('base.field.versions'),      '<strong>' . (int) $log['versions'] . '</strong>'],
        [t('base.field.created'),       htmlspecialchars(formatIsoTimestamp($log['created']))],
        [t('base.field.last_update'),   htmlspecialchars(formatIsoTimestamp($log['last_update']))],
        [t('base.field.keys'),          htmlspecialchars($keysText)],
        [t('base.field.uuid'),          '<span class="br-mono">' . htmlspecialchars((string) $res['uuid']) . '</span>'],
    ];

    $html = '<dl class="br-dl">';
    foreach ($rows as [$label, $valueHtml]) {
        $html .= '<dt>' . htmlspecialchars($label) . '</dt><dd>' . $valueHtml . '</dd>';
    }
    return $html . '</dl>';
}

/** Versionshistorie (eine Zeile pro Log-Eintrag). */
function renderBaseRegistryVersions(array $log): string
{
    $html = '<div class="table-scroll"><table class="br-table"><thead><tr>'
        . '<th>#</th><th>versionId</th><th>' . htmlspecialchars(t('base.col.timestamp')) . '</th>'
        . '<th>' . htmlspecialchars(t('base.col.update_key')) . '</th><th>' . htmlspecialchars(t('base.col.change')) . '</th>'
        . '</tr></thead><tbody>';

    foreach ($log['lines'] as $line) {
        $changeClass = $line['change'] === 'deactivated' ? 'status-revoked' : 'status-unknown';
        $html .= '<tr>'
            . '<td>' . (int) $line['number'] . '</td>'
            . '<td class="br-mono br-wrap">' . htmlspecialchars((string) $line['version_id']) . '</td>'
            . '<td>' . htmlspecialchars(formatIsoTimestamp($line['time'])) . '</td>'
            . '<td class="br-mono">' . htmlspecialchars(baseRegistryShorten($line['update_key'])) . '</td>'
            . '<td><span class="status-badge ' . $changeClass . '">' . htmlspecialchars(t('base.change.' . $line['change'])) . '</span></td>'
            . '</tr>';
    }
    return $html . '</tbody></table></div>';
}

/** Schlüssel des aktuellen DID-Dokuments. */
function renderBaseRegistryKeys(array $log): string
{
    $html = '';
    if ($log['keys_stale'] && $log['keys_version'] !== null) {
        $html .= '<p class="br-msg">' . htmlspecialchars(t('base.keys_stale_note', [(int) $log['keys_version']])) . '</p>';
    }
    if ($log['keys'] === []) {
        return $html . '<p class="br-msg">' . htmlspecialchars(t('base.keys_none')) . '</p>';
    }

    $html .= '<div class="table-scroll"><table class="br-table"><thead><tr>'
        . '<th>kid</th><th>' . htmlspecialchars(t('base.col.purpose')) . '</th>'
        . '<th>' . htmlspecialchars(t('base.col.type')) . '</th><th>' . htmlspecialchars(t('base.col.curve')) . '</th>'
        . '</tr></thead><tbody>';
    foreach ($log['keys'] as $key) {
        $html .= '<tr>'
            . '<td class="br-mono">' . htmlspecialchars($key['kid']) . '</td>'
            . '<td>' . htmlspecialchars(implode(', ', $key['purposes']) ?: '-') . '</td>'
            . '<td>' . htmlspecialchars($key['type']) . '</td>'
            . '<td>' . htmlspecialchars($key['curve']) . '</td>'
            . '</tr>';
    }
    return $html . '</tbody></table></div>';
}

/** Rohantwort, je Log-Zeile formatiert (Leerzeile zwischen den Versionen). */
function baseRegistryPrettyRaw(string $raw): string
{
    $out = [];
    foreach (preg_split('/\R/', trim($raw)) ?: [] as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        // Als Objekte (nicht assoziativ) dekodieren, damit leere {} nicht zu [] werden.
        $decoded = json_decode($line);
        $out[] = $decoded === null
            ? $line
            : (string) json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
    return implode("\n\n", $out);
}

/** Rohantwort-Block mit Kopieren/Öffnen-Aktionen. */
function renderBaseRegistryRaw(array $res, bool $withHeading = true): string
{
    $html = '<div class="br-raw-wrap">';
    $html .= '<div class="br-raw-head">'
        . ($withHeading ? '<h4>' . htmlspecialchars(t('base.section.raw')) . '</h4>' : '<span></span>')
        . '<span class="br-raw-actions">'
        . '<button type="button" class="br-btn" data-br-copy>' . htmlspecialchars(t('base.copy')) . '</button>'
        . ($res['url'] !== null ? '<a class="br-link" href="' . htmlspecialchars($res['url']) . '" target="_blank" rel="noopener">' . htmlspecialchars(t('base.open_jsonl')) . ' &#8599;</a>' : '')
        . '</span></div>'
        . '<pre class="br-raw">' . htmlspecialchars(baseRegistryPrettyRaw((string) $res['raw'])) . '</pre>'
        . '</div>';
    return $html;
}

/**
 * Inhalt des Tabs "Base Registry" in der aufgeklappten Tabellenzeile:
 * Zusammenfassung, Versionshistorie, Schlüssel, Rohantwort. Bei not_found/
 * external/unavailable stattdessen der Hinweis (bei Fehlern mit "Retry").
 */
function renderBaseRegistryDetail(array $res): string
{
    if (!in_array($res['status'], ['found', 'deactivated'], true) || $res['log'] === null) {
        $html = renderBaseRegistrySummary($res);
        if ($res['status'] === 'unavailable') {
            $html .= '<button type="button" class="br-btn" data-br-retry>' . htmlspecialchars(t('base.retry')) . '</button>';
        }
        return $html;
    }

    return '<div class="br-detail">'
        . renderBaseRegistrySummary($res)
        . '<div class="br-section"><h4>' . htmlspecialchars(t('base.section.versions')) . '</h4>' . renderBaseRegistryVersions($res['log']) . '</div>'
        . '<div class="br-section"><h4>' . htmlspecialchars(t('base.section.keys')) . '</h4>' . renderBaseRegistryKeys($res['log']) . '</div>'
        . '<div class="br-section">' . renderBaseRegistryRaw($res) . '</div>'
        . '</div>';
}

/**
 * Dialog "View DID log" (natives <dialog>): Umschalter Parsed / Raw response,
 * Standardansicht = Rohantwort.
 */
function renderBaseRegistryDialog(array $res, string $dialogId, string $title): string
{
    $lines = $res['log'] !== null ? (int) $res['log']['versions'] : 0;
    $reqLine = t('base.request_line', [(string) $res['url'], (int) $res['http_code'], $lines]);

    return '<dialog class="br-dialog" id="' . htmlspecialchars($dialogId) . '" aria-labelledby="' . htmlspecialchars($dialogId) . '-title">'
        . '<div class="br-dialog-head">'
        .   '<h3 id="' . htmlspecialchars($dialogId) . '-title">' . htmlspecialchars(t('base.dialog_title') . ' · ' . $title) . '</h3>'
        .   renderBaseRegistryPill($res)
        .   '<button type="button" class="br-x" data-br-dialog-close aria-label="' . htmlspecialchars(t('base.close')) . '">&times;</button>'
        . '</div>'
        . '<div class="br-dialog-bar">'
        .   '<div class="br-seg" role="group">'
        .     '<button type="button" data-br-view="parsed">' . htmlspecialchars(t('base.view_parsed')) . '</button>'
        .     '<button type="button" data-br-view="raw" class="active">' . htmlspecialchars(t('base.view_raw')) . '</button>'
        .   '</div>'
        .   '<span class="br-reqline br-mono" title="' . htmlspecialchars($reqLine) . '">' . htmlspecialchars($reqLine) . '</span>'
        . '</div>'
        . '<div class="br-dialog-body">'
        .   '<div data-br-pane="parsed" hidden>'
        .     renderBaseRegistrySummary($res)
        .     ($res['log'] !== null
                ? '<div class="br-section"><h4>' . htmlspecialchars(t('base.section.versions')) . '</h4>' . renderBaseRegistryVersions($res['log']) . '</div>'
                  . '<div class="br-section"><h4>' . htmlspecialchars(t('base.section.keys')) . '</h4>' . renderBaseRegistryKeys($res['log']) . '</div>'
                : '')
        .   '</div>'
        .   '<div data-br-pane="raw">' . renderBaseRegistryRaw($res, false) . '</div>'
        . '</div>'
        . '<div class="br-dialog-foot"><button type="button" class="br-btn br-btn-primary" data-br-dialog-close>' . htmlspecialchars(t('base.close')) . '</button></div>'
        . '</dialog>';
}

/**
 * Panel "Base Registry" der globalen DID-Suche (rechte Spalte). $results ist
 * did => Ergebnis aus fetchBaseRegistryEntry; $entityName (aus idTS) dient nur
 * als Dialog-Titel, wenn genau eine DID nachgeschlagen wurde.
 */
function renderBaseRegistrySearchPanel(array $results, ?string $entityName): string
{
    $count = count($results);
    $html = '<section class="br-panel" aria-label="' . htmlspecialchars(t('base.title')) . '">'
        . '<div class="br-panel-head"><h3>' . htmlspecialchars(t('base.title')) . '</h3>'
        . ($count === 1 ? renderBaseRegistryPill(reset($results)) : '')
        . '</div><div class="br-panel-body">';

    if ($count === 0) {
        $html .= '<p class="br-msg">' . htmlspecialchars(t('base.no_did')) . '</p>';
    }

    $dialogs = '';
    $i = 0;
    foreach ($results as $did => $res) {
        $i++;
        $html .= '<div class="br-block">';
        if ($count > 1) {
            $html .= '<div class="br-block-head"><span class="br-mono br-wrap">' . htmlspecialchars((string) $did) . '</span>' . renderBaseRegistryPill($res) . '</div>';
        }
        $html .= renderBaseRegistrySummary($res);

        if ($res['raw'] !== null) {
            $dialogId = 'br-dialog-' . $i;
            $title = ($count === 1 && $entityName !== null) ? $entityName : baseRegistryShorten((string) $res['uuid']);
            $html .= '<div class="br-actions">'
                . '<button type="button" class="br-btn br-btn-outline" data-br-dialog-open="' . htmlspecialchars($dialogId) . '">' . htmlspecialchars(t('base.view_log')) . '</button>'
                . '<a class="br-link" href="' . htmlspecialchars((string) $res['url']) . '" target="_blank" rel="noopener">' . htmlspecialchars(t('base.open_jsonl')) . ' &#8599;</a>'
                . '</div>';
            $dialogs .= renderBaseRegistryDialog($res, $dialogId, $title);
        }
        $html .= '</div>';
    }

    return $html . '</div></section>' . $dialogs;
}

/**
 * Runder "?"-Button im Seitenkopf: startet die geführte Tour. Ohne JavaScript
 * (und auf der History-Seite) ist es ein normaler Link auf den Explorer mit
 * ?tour=1, assets/tour.js fängt den Klick auf dem Explorer selbst ab.
 */
function renderTourHelpButton(): string
{
    $label = htmlspecialchars(t('tour.help_aria'));
    return '<a class="tour-help" href="index.php?tour=1" data-tour="help" role="button"'
        . ' aria-label="' . $label . '" title="' . $label . '">?</a>';
}

/**
 * Bindet die geführte Tour ein: Texte der aktiven Sprache als JSON (alle
 * Keys mit Präfix "tour.", Englisch als Fallback) plus tour.css/tour.js.
 * Nur auf dem Explorer einbinden, dort läuft die Tour.
 */
function renderTourAssets(): string
{
    $dict = i18nDict();
    // Rohtexte (ohne vsprintf): "tour.step" enthält %d-Platzhalter, die JS füllt.
    $lang = currentLang();
    $texts = [];
    foreach (($dict['en'] ?? []) as $key => $fallback) {
        if (str_starts_with($key, 'tour.')) {
            $texts[substr($key, 5)] = $dict[$lang][$key] ?? $fallback;
        }
    }
    $json = json_encode($texts, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    return '<script type="application/json" id="tour-data">' . $json . "</script>\n"
        . '<link rel="stylesheet" href="' . htmlspecialchars(assetUrl('tour.css')) . "\">\n"
        . '<script src="' . htmlspecialchars(assetUrl('tour.js')) . "\"></script>\n";
}
