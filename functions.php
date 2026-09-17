<?php
declare(strict_types=1);

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
 * Holt ALLE Einträge einer API (über alle Seiten hinweg, falls paginiert)
 * und gibt sie als flaches Array dekodierter Payloads/Items zurück.
 */
function fetchAllEntries(string $baseUrl, array $apiCfg): array
{
    $url = $baseUrl . $apiCfg['path'];

    if ($apiCfg['mode'] === 'single_jwt_list') {
        $raw = httpGet($url);
        $decoded = decodeJwt($raw);
        $listRaw = $decoded['payload'][$apiCfg['list_field']] ?? [];

        $entries = [];
        foreach ($listRaw as $item) {
            $entries[] = !empty($apiCfg['scalar_list']) ? ['value' => $item] : $item;
        }
        return $entries;
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

        return $entries;
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
            if ($key === '_jwt_header') {
                continue; // Header wird separat gerendert
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
        $html .= '<li>' . (is_array($value) ? renderDetailTree($value, $dateKeys) : renderDetailScalar($value)) . '</li>';
    }
    $html .= '</ul>';
    return $html;
}

/**
 * Rendert die vollständige Detailansicht (Payload + Header) für einen Eintrag.
 */
function renderEntryDetail(array $entry): string
{
    $header = $entry['_jwt_header'] ?? null;

    $html = '<div class="detail-section"><h4>Payload (vollständig)</h4>' . renderDetailTree($entry) . '</div>';
    if ($header !== null) {
        $html .= '<div class="detail-section"><h4>JWT-Header</h4>' . renderDetailTree($header) . '</div>';
    }
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
 * Holt die Einträge für Umgebung+API, nutzt einen Session-Cache und
 * erzwingt bei $forceRefresh einen frischen API-Abruf.
 */
function getEntriesCached(string $envKey, string $apiKey, string $baseUrl, array $apiCfg, bool $forceRefresh, int $ttl): array
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    $cacheKey = $envKey . '::' . $apiKey;
    $cached = $_SESSION['trust_explorer_cache'][$cacheKey] ?? null;

    $isStale = $cached === null || (time() - $cached['fetched_at']) > $ttl;

    if ($forceRefresh || $isStale) {
        $entries = fetchAllEntries($baseUrl, $apiCfg);
        $_SESSION['trust_explorer_cache'][$cacheKey] = [
            'entries'    => $entries,
            'fetched_at' => time(),
        ];
        return $entries;
    }

    return $cached['entries'];
}
