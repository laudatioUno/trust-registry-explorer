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
const CACHE_SCHEMA_VERSION = 3;

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
        throw new RuntimeException('Ungültiges JWT-Format.');
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
        throw new RuntimeException("cURL-Fehler ($errNo): $err");
    }
    if ($httpCode !== 200) {
        throw new RuntimeException("Unerwarteter HTTP-Status: $httpCode für $url");
    }
    if ($body === false || trim($body) === '') {
        throw new RuntimeException('Leere Antwort von der API.');
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
            return ['bytes' => null, 'bits' => null, 'error' => 'status_list-Feld fehlt in der Antwort'];
        }

        $bits = (int) ($statusList['bits'] ?? 1);
        $compressed = base64UrlDecode((string) $statusList['lst']);
        $bytes = @gzuncompress($compressed);

        if ($bytes === false) {
            return ['bytes' => null, 'bits' => null, 'error' => 'Statusliste konnte nicht entpackt werden'];
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
        return 'nicht abrufbar';
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
        return ['status' => null, 'label' => 'nicht abrufbar', 'error' => $fetched['error']];
    }

    $value = extractStatusBit($fetched['bytes'], $fetched['bits'], (int) $idx);
    if ($value === null) {
        return ['status' => null, 'label' => 'nicht abrufbar', 'error' => 'Index ausserhalb der Statusliste'];
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
            $entry['_status_label'] = 'nicht abrufbar';
            $entry['_status_error'] = $cached['error'];
            continue;
        }

        $value = extractStatusBit($cached['bytes'], $cached['bits'], (int) $idx);
        $entry['_status_value'] = $value;
        $entry['_status_label'] = $value === null ? 'nicht abrufbar' : statusLabel($value);
        $entry['_status_error'] = $value === null ? 'Index ausserhalb der Statusliste' : null;
    }
    unset($entry);

    return $entries;
}

/**
 * Reichert jeden Eintrag um '_entity_name' an, nachgeschlagen über 'sub'
 * (die DID) in den Einträgen einer ANDEREN API derselben Umgebung (z.B.
 * pvaTS-Zeilen mit dem Namen aus idTS verknüpfen). Nutzt denselben Session-
 * Cache wie die Quell-API selbst — löst also KEINEN zusätzlichen API-Call
 * aus, wenn diese schon geladen war. Kein Treffer → '_entity_name' bleibt null.
 */
function attachEntityNames(array $entries, string $envKey, string $baseUrl, string $sourceApiKey, array $allApis, int $ttl): array
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
        $entry['_entity_name'] = $nameBySub[$entry['sub'] ?? null] ?? null;
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
            $pagedUrl = $url . '?filterActive=true&page=' . $page . '&size=20';
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
            $entries = attachEntityNames($entries, $envKey, $baseUrl, $apiCfg['enrich_name_from'], $allApis, $ttl);
        }

        return ['entries' => $entries, 'list_meta' => null];
    }

    throw new RuntimeException("Unbekannter API-Modus: {$apiCfg['mode']}");
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
        $rendered = ($val === '') ? '<span class="value-empty">(leer)</span>' : htmlspecialchars((string) $val);
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
        return '<span class="value-empty">(leer)</span>';
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
 */
function renderEntryDetail(array $entry): string
{
    $header = $entry['_jwt_header'] ?? null;

    $html = '';
    if ($header !== null) {
        $html .= '<div class="detail-section"><h4>JWT-Header</h4>' . renderDetailTree($header) . '</div>';
    }
    $html .= '<div class="detail-section"><h4>Payload (vollständig)</h4>' . renderDetailTree($entry) . '</div>';
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
