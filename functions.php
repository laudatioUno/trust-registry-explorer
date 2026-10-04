<?php
declare(strict_types=1);

/**
 * Wird erhöht, wann immer sich die Struktur dessen ändert, was pro
 * Umgebung+API im Session-Cache liegt (z.B. ein neues Feld wie list_meta
 * oder eine Anreicherung wie _entity_name). Ein alter Cache-Eintrag mit
 * abweichender Version gilt automatisch als veraltet und wird neu geladen —
 * verhindert, dass ein Code-Update durch einen stehengebliebenen Session-
 * Cache "verschluckt" wird (siehe z.B. das list_meta-Panel bei ncTLS/REF).
 */
const CACHE_SCHEMA_VERSION = 7;

/**
 * Base64URL-Dekodierung (JWT-Standard) mit Padding-Korrektur.
 */
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
    if ($body === false || trim($body) === '') {
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
 * der verfügbaren Bytes liegt.
 */
function extractStatusBit(string $bytes, int $bits, int $idx): ?int
{
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
 * Übersetzt einen rohen Statuswert (0/1/2) in das lesbare Label.
 * Unbekannte Werte werden als "<Wert> (Unknown Status)" dargestellt.
 */
function statusLabel(?int $value): string
{
    static $labels = [0 => 'VALID', 1 => 'REVOKED', 2 => 'SUSPENDED'];

    if ($value === null) {
        return t('status.unavailable');
    }
    return $labels[$value] ?? ($value . ' (Unknown Status)');
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
        return ['status' => null, 'label' => t('status.unavailable'), 'error' => $fetched['error']];
    }

    $value = extractStatusBit($fetched['bytes'], $fetched['bits'], (int) $idx);
    if ($value === null) {
        return ['status' => null, 'label' => t('status.unavailable'), 'error' => t('error.status_list_index_oob')];
    }

    return ['status' => $value, 'label' => statusLabel($value), 'error' => null];
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
 * Ergänzt an jedem Eintrag: '_status_value', '_status_label', '_status_error'.
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
            $entry['_status_label'] = t('status.unavailable');
            $entry['_status_error'] = $cached['error'];
            continue;
        }

        $value = extractStatusBit($cached['bytes'], $cached['bits'], (int) $idx);
        $entry['_status_value'] = $value;
        $entry['_status_label'] = $value === null ? t('status.unavailable') : statusLabel($value);
        $entry['_status_error'] = $value === null ? t('error.status_list_index_oob') : null;
    }
    unset($entry);

    return $entries;
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
            if ($sub !== null && $name !== null && $name !== '') {
                $nameBySub[$sub] = $name;
            }
        }
    } catch (Throwable) {
        $nameBySub = [];
    }

    foreach ($entries as &$entry) {
        $entry['_entity_name'] = $nameBySub[$entry[$didField] ?? null] ?? null;
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
 * '_issuer_count' und '_issuers' (die vollständigen piaTS-Einträge, für die
 * aufklappbare Detailansicht) an — nachgeschlagen über $sourceApiKey
 * (piaTS) derselben Umgebung. Nutzt deren Session-Cache, löst also KEINEN
 * zusätzlichen API-Call aus, wenn piaTS schon geladen war.
 */
function attachIssuerCounts(array $entries, string $envKey, string $baseUrl, string $sourceApiKey, array $allApis, int $ttl): array
{
    if (!isset($allApis[$sourceApiKey])) {
        foreach ($entries as &$entry) {
            $entry['_issuer_count'] = 0;
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
        $entry['_issuer_count'] = count($issuers);
        $entry['_issuers'] = $issuers;
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
            ];
        }

        return ['entries' => $entries, 'list_meta' => $listMeta];
    }

    if ($apiCfg['mode'] === 'paginated_jwt') {
        $entries = [];
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
            $pagedUrl = $url . '?filterActive=false&page=' . $page . '&size=20';
            $json = json_decode(httpGet($pagedUrl), true, flags: JSON_THROW_ON_ERROR);

            foreach ($json['content'] ?? [] as $jwt) {
                $decoded = decodeJwt($jwt);
                // Payload bleibt an der Wurzel (für Spalten-Pfade wie "request.scope"),
                // der Header wird unter '_jwt_header' mitgeführt für die Detailansicht.
                $entry = $decoded['payload'];
                $entry['_jwt_header'] = $decoded['header'];
                $entries[] = $entry;
            }

            $totalPages = (int) ($json['page']['totalPages'] ?? 1);
            $page++;
        } while ($page < $totalPages);

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
        default    => htmlspecialchars(is_scalar($value) ? (string) $value : json_encode($value)),
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
    $label = $entry['_status_label'] ?? '-';

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

    if ($count === 0) {
        return '<span class="issuer-count issuer-count-zero">' . htmlspecialchars($label) . '</span>';
    }

    $vct = (string) ($entry['value'] ?? '');
    $url = '?' . http_build_query(['env' => $envKey, 'api' => $targetApiKey, 'q' => $vct]);

    return '<a href="' . htmlspecialchars($url) . '" class="issuer-count-link" onclick="event.stopPropagation()">'
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
            if ($key === '_jwt_header' || $key === '_status_value' || $key === '_status_label' || $key === '_status_error' || $key === '_entity_name') {
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
 */
function renderEntryDetail(array $entry, bool $showHeader = true): string
{
    $header = $entry['_jwt_header'] ?? null;

    $html = '';
    if ($showHeader && $header !== null) {
        $html .= '<div class="detail-section"><h4>' . htmlspecialchars(t('detail.jwt_header')) . '</h4>' . renderDetailTree($header) . '</div>';
    }
    $html .= '<div class="detail-section"><h4>' . htmlspecialchars(t('detail.payload_full')) . '</h4>' . renderDetailTree($entry) . '</div>';
    return $html;
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
 * Filtert eine Liste von Einträgen anhand eines Suchbegriffs (case-insensitive,
 * über alle Felder des Eintrags hinweg).
 */
function filterEntries(array $entries, string $query): array
{
    $query = trim($query);
    if ($query === '') {
        return $entries;
    }
    $needle = mb_strtolower($query);

    return array_values(array_filter($entries, function ($entry) use ($needle) {
        return str_contains(mb_strtolower(flattenForSearch($entry)), $needle);
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
        'status_badge' => mb_strtolower((string) ($entry['_status_label'] ?? '')),
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
