<?php
declare(strict_types=1);

require __DIR__ . '/i18n.php';
require __DIR__ . '/functions.php';
require __DIR__ . '/result_view.php';
$config = require __DIR__ . '/config.php';

// ---- Parameter aus der URL lesen ----

// env/api werden nicht aus der Eingabe übernommen, sondern per Allowlist aus den
// Konfigurationsschlüsseln gewählt: so ist der Wert garantiert einer der bekannten
// Schlüssel (und für die statische Analyse nicht mehr "tainted").
$requestedEnv = requestString($_GET, 'env');
$requestedApi = requestString($_GET, 'api');
$environments = is_array($config['environments']) ? $config['environments'] : [];
$envKey = (string) array_key_first($environments);
foreach ($environments as $knownEnv => $_) {
    if ((string) $knownEnv === $requestedEnv) {
        $envKey = (string) $knownEnv;
        break;
    }
}

$apiKey = null;
foreach ($config['apis'] as $knownApi => $_) {
    if ((string) $knownApi === $requestedApi) {
        $apiKey = (string) $knownApi;
        break;
    }
}

$query = trim(requestString($_GET, 'q'));
$page  = max(0, requestInt($_GET, 'p'));
$forceRefresh = isset($_GET['refresh']);

// Seitengrösse: wählbar (20/50/100/200), fällt aber bei jeder Navigation, die
// diesen Wert nicht explizit mitgibt (z.B. Umgebungs-/API-Wechsel), auf den
// Standardwert aus config.php zurück — kein dauerhaftes Merken gewünscht.
$pageSizeOptions = $config['page_size_options'] ?? [$config['page_size']];
$perPage = requestInt($_GET, 'per_page', (int) $config['page_size']);
if (!in_array($perPage, $pageSizeOptions, true)) {
    $perPage = is_int($config['page_size']) ? $config['page_size'] : 20;
}

// Spalten-Sortierung: welche Spalte (per key) + Richtung. Fällt wie per_page
// beim Umgebungs-/API-Wechsel automatisch weg (nicht in den Default-Params
// von buildUrl), bleibt aber bei Suche/Pagination/Seitengrösse erhalten.
$sortKey = isset($_GET['sort']) && is_string($_GET['sort']) ? $_GET['sort'] : null;
$sortDir = ($_GET['dir'] ?? 'asc') === 'desc' ? 'desc' : 'asc';

// ---- Globale DID-Suche (unabhängig von der Umgebungs-/API-Auswahl unten) ----

$didQuery = trim(requestString($_GET, 'did'));
$didEnv   = requestString($_GET, 'did_env', $envKey);
if (!isset($config['environments'][$didEnv])) {
    $didEnv = $envKey;
}
$didSearch = null; // ['results' => [...], 'entity_name' => ...] wenn eine Suche aktiv ist

if ($didQuery !== '') {
    $didBaseUrl = $config['environments'][$didEnv]['base_url'];
    $didSearch  = searchAcrossApis($didEnv, $didBaseUrl, $config['apis'], $didQuery, $config['cache_ttl']);

    // Base Registry: DIDs aus dem Suchbegriff und aus den idTS-Treffern nachschlagen.
    $baseResults  = [];
    $baseRegUrl   = $config['environments'][$didEnv]['base_registry_url'] ?? null;
    $baseRegPath  = $config['base_registry']['path_template'] ?? '/api/v1/did/%s/did.jsonl';
    if (is_string($baseRegUrl) && $baseRegUrl !== '') {
        $baseDids = baseRegistryCandidateDids(
            $didQuery,
            $didSearch['results']['idTS']['entries'] ?? [],
            (int) ($config['base_registry']['max_search_dids'] ?? 5)
        );
        foreach ($baseDids as $baseDid) {
            $baseResults[$baseDid] = fetchBaseRegistryEntry($baseRegUrl, $baseDid, $baseRegPath);
        }
    }
}

/**
 * Holt die Einträge der gewählten API (Session-Cache, sonst Registry), wendet
 * Status-Filter, Textsuche und Sortierung an und schneidet die aktuelle Seite
 * aus. Liegt als Funktion vor, weil die Abfrage je nach Fall direkt (Seite) oder
 * erst im Fragment-Request (?fragment=1, siehe result-loader.js) läuft.
 *
 * @return array{
 *     errorMessage: string|null, fetchedCount: int, hasStatusFilter: bool, statusCounts: array<string, int>|null,
 *     entries: list<array>, listMeta: array|null, pageSize: int, totalFiltered: int,
 *     showPagination: bool, totalPages: int, page: int, pageEntries: list<array>
 * }
 */
function loadExplorerData(array $config, string $envKey, ?string $apiKey, string $query, bool $forceRefresh, string $statusFilter, ?string $sortKey, string $sortDir, int $page, int $perPage): array
{
    $errorMessage = null;
    $hasStatusFilter = false;   // Umschalter "Alle | Nur gültige" sichtbar (API mit row_status)
    $statusCounts = null;       // ['valid'=>, 'revoked'=>, 'suspended'=>, 'unknown'=>]
    $entries = [];
    $fetchedCount = 0;          // alle Einträge der API (vor Filter/Suche)
    $listMeta = null;

    if ($apiKey !== null) {
        $apiCfg = $config['apis'][$apiKey];
        $baseUrl = $config['environments'][$envKey]['base_url'];

        try {
            $fetched = getEntriesCached($envKey, $apiKey, $baseUrl, $apiCfg, $forceRefresh, $config['cache_ttl'], $config['apis']);
            $entries = $fetched['entries'];
            $listMeta = $fetched['list_meta'];
            $fetchedCount = count($entries);

            // Status-Filter "nur gültige": nur bei APIs mit Statuslist pro Eintrag
            // (row_status). Zählung über ALLE Einträge (vor der Textsuche), damit
            // die Zahlen am Umschalter stabil bleiben; nur Statuslist-Wert 0 gilt.
            if (!empty($apiCfg['row_status'])) {
                $hasStatusFilter = true;
                $statusCounts = statusBucketCounts($entries);
                if ($statusFilter === 'valid') {
                    $entries = array_values(array_filter($entries, static fn (array $e): bool => statusBucket($e) === 'valid'));
                }
            }

            $entries = filterEntries($entries, $query);

            if ($sortKey !== null) {
                $sortCol = null;
                foreach ($apiCfg['columns'] as $c) {
                    if ($c['key'] === $sortKey) {
                        $sortCol = $c;
                        break;
                    }
                }
                if ($sortCol !== null) {
                    $entries = sortEntries($entries, $sortCol, $sortDir);
                }
            }
        } catch (Throwable $e) {
            $errorMessage = $e->getMessage();
        }
    }

    $pageSize = $config['page_size']; // fixer Schwellwert: bis 20 Treffer keine Pagination
    $totalFiltered = count($entries);
    $showPagination = $totalFiltered > $pageSize;

    if ($showPagination) {
        $totalPages = max(1, (int) ceil($totalFiltered / $perPage));
        $page = min($page, $totalPages - 1);
        $pageEntries = array_slice($entries, $page * $perPage, $perPage);
    } else {
        $totalPages = 1;
        $page = 0;
        $pageEntries = $entries; // alles auf einer Seite
    }

    return [
        'errorMessage' => $errorMessage, 'fetchedCount' => $fetchedCount, 'hasStatusFilter' => $hasStatusFilter, 'statusCounts' => $statusCounts,
        'entries' => $entries, 'listMeta' => $listMeta, 'pageSize' => $pageSize, 'totalFiltered' => $totalFiltered,
        'showPagination' => $showPagination, 'totalPages' => $totalPages, 'page' => $page, 'pageEntries' => $pageEntries,
    ];
}

$statusFilter = (($_GET['status'] ?? '') === 'valid' && !empty($config['apis'][$apiKey ?? '']['row_status'])) ? 'valid' : '';
$statusParam = $statusFilter !== '' ? $statusFilter : null; // für buildUrl (null = Parameter weglassen)
$apiCfg = $apiKey !== null ? $config['apis'][$apiKey] : [];

// Ergebnisbereich (Toolbar, Tabelle, Pagination): Liegt die Tabelle im
// Session-Cache, wird sie direkt in die Seite gerendert. Sonst (erster Aufruf,
// abgelaufen oder Refresh) liefert die Seite nur Rahmen + Skeleton und
// assets/result-loader.js holt den Bereich danach per fetch() als Fragment
// (?fragment=1). Das funktioniert in jedem Browser, unabhängig davon, ob der
// Server die Antwort stückweise ausliefert. Ausgenommen sind der CSV-Export
// (braucht die Daten vor dem ersten Byte), die DID-Suche (ihr Ergebnis steht im
// Seitenrahmen oberhalb der Tabs) und ?sync=1 (Fallback ohne JavaScript).
$wantsCsv  = ($_GET['export'] ?? '') === 'csv';
$fragment  = ($_GET['fragment'] ?? '') === '1';
$syncLoad  = ($_GET['sync'] ?? '') === '1';
$cacheTtl  = is_int($config['cache_ttl']) ? $config['cache_ttl'] : 300;
$defer     = $apiKey !== null && !$wantsCsv && !$fragment && !$syncLoad && $didQuery === ''
    && ($forceRefresh || !entriesCacheIsFresh($envKey, $apiKey, $cacheTtl));

if ($fragment) {
    // Nur der Ergebnisbereich, ohne Seitenrahmen (wird von result-loader.js geholt).
    if ($apiKey === null) {
        http_response_code(400);
        exit;
    }
    $data = loadExplorerData($config, $envKey, $apiKey, $query, $forceRefresh, $statusFilter, $sortKey, $sortDir, $page, $perPage);
    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: no-store');
    renderResultArea($config, $envKey, $apiKey, $query, $perPage, $sortKey, $sortDir, $statusFilter, $statusParam, $pageSizeOptions, $data);
    exit;
}

$data = null;
if (!$defer) {
    $data = loadExplorerData($config, $envKey, $apiKey, $query, $forceRefresh, $statusFilter, $sortKey, $sortDir, $page, $perPage);
    if ($wantsCsv && $apiKey !== null && $data['errorMessage'] === null) {
        $entries = $data['entries'];
        $filename = sprintf('trust-registry-%s-%s-%s.csv', preg_replace('/[^A-Za-z0-9_-]/', '', $envKey), preg_replace('/[^A-Za-z0-9_-]/', '', $apiKey), date('Ymd-His'));
        header('Content-Type: text/csv; charset=UTF-8');
        header('X-Content-Type-Options: nosniff');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-store');

        $out = fopen('php://output', 'wb');
        fwrite($out, "\xEF\xBB\xBF"); // UTF-8-BOM: Excel erkennt Umlaute korrekt
        $issuerApi = $apiCfg['issuer_count_from'] ?? 'piaTS';
        fwrite($out, implode(';', array_map(static fn (array $c): string => csvCell((string) $c['label']), $apiCfg['columns'])) . "\r\n");
        foreach ($entries as $entry) {
            fwrite($out, implode(';', array_map(static fn (array $c): string => csvCell(exportCellText($entry, $c, $envKey, $issuerApi)), $apiCfg['columns'])) . "\r\n");
        }
        fclose($out);
        exit;
    }
}

?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars(currentLang()) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?= renderHeadMeta() ?>
<link rel="stylesheet" href="<?= htmlspecialchars(assetUrl('theme.css')) ?>">
<script src="<?= htmlspecialchars(assetUrl('theme.js')) ?>"></script>
<link rel="stylesheet" href="<?= htmlspecialchars(assetUrl('base-registry.css')) ?>">
<style>
    body { font-family: Arial, sans-serif; margin: 2em; color: var(--text); background: var(--bg); display: flex; flex-direction: column; min-height: calc(100vh - 4em); min-height: calc(100dvh - 4em); }
    h1 { font-size: 1.3em; margin-bottom: 1em; }

    .page-header { display: flex; justify-content: space-between; align-items: center; gap: 12px; margin-bottom: 1em; flex-wrap: wrap; }
    .page-header h1 { margin: 0; }
    .page-header .brand { display: flex; align-items: center; gap: 10px; color: inherit; text-decoration: none; }
    .top-nav { display: flex; gap: 6px; align-items: stretch; }
    .top-nav a { display: inline-flex; align-items: center; justify-content: center; line-height: 1; padding: 6px 16px; font-size: 13px; border: 1px solid var(--border); border-radius: 20px; text-decoration: none; color: var(--text-2); background: var(--surface); box-sizing: border-box; }
    .top-nav a.active { background: var(--accent-fill); border-color: var(--accent-fill); color: var(--on-accent); font-weight: bold; }
    .top-nav a.docs-link { color: var(--accent); border-color: var(--accent-soft); }
    .top-nav a.docs-link:hover { background: var(--accent-soft-2); }

    .tabs { display: flex; gap: 4px; border-bottom: 1px solid var(--border); margin-bottom: 16px; }
    .tabs a { padding: 8px 18px; font-size: 14px; text-decoration: none; color: var(--text-2); border-bottom: 3px solid transparent; }
    .tabs a.active { color: var(--accent); border-bottom-color: var(--accent); font-weight: bold; }

    .did-search { background: var(--surface); border: 1px solid var(--border); border-radius: 10px; padding: 14px 16px; margin-bottom: 20px; }
    .did-search form { display: flex; gap: 8px; }
    .did-search input[type=text] { flex: 1; padding: 7px 10px; font-size: 12px; font-family: monospace; border: 1px solid var(--border); border-radius: 4px; background: var(--surface); color: var(--text); }
    .did-search select { padding: 7px; font-size: 13px; border: 1px solid var(--border); border-radius: 4px; background: var(--surface); color: var(--text); }
    .did-search form button { padding: 7px 16px; font-size: 13px; border: 1px solid var(--accent-fill); background: var(--accent-fill); color: var(--on-accent); border-radius: 4px; cursor: pointer; }
    .did-search .hint { font-size: 11px; color: var(--text-3); margin: 6px 0 0; }

    .did-entity { display: flex; align-items: center; gap: 10px; background: var(--accent-soft); border-radius: 6px; padding: 8px 12px; margin-top: 12px; }
    .did-entity .lbl { font-size: 11px; color: var(--text-3); }
    .did-entity .val { font-size: 14px; font-weight: bold; color: var(--accent); }

    .did-results { margin-top: 14px; display: flex; flex-direction: column; gap: 8px; }
    .did-card { background: var(--accent-soft-2); border: 1px solid var(--border); border-radius: 8px; padding: 10px 14px; }
    .did-card.no-hit { opacity: 0.55; border-color: var(--border); background: transparent; }
    .did-card-head { display: flex; justify-content: space-between; align-items: center; cursor: pointer; }
    .did-card-head .name { font-weight: bold; font-size: 14px; }
    .did-card-head .name small { font-weight: normal; color: var(--text-3); font-size: 12px; margin-left: 6px; }
    .did-badge { background: var(--accent-soft); color: var(--accent); font-size: 12px; padding: 2px 10px; border-radius: 10px; }
    .did-card-body { display: none; margin-top: 10px; padding-top: 10px; border-top: 1px solid var(--border); }
    .did-card-body.open { display: block; }
    .did-hit-label { font-size: 11px; color: var(--text-3); margin: 10px 0 4px; text-transform: uppercase; }
    .did-hit-label:first-child { margin-top: 0; }
    .did-error { color: var(--error-text); font-size: 12px; }

    .api-chips { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 20px; }
    .api-chips a { padding: 6px 14px; font-size: 13px; border-radius: 16px; border: 1px solid var(--border); text-decoration: none; color: var(--text-2); background: var(--surface); }
    .api-chips a.active { background: var(--accent-soft); border-color: var(--accent); color: var(--accent); font-weight: bold; }
    .api-chips small { color: var(--text-3); margin-left: 4px; }

    .status-filter { display: flex; align-items: center; gap: 14px; flex-wrap: wrap; padding: 10px 14px; margin-bottom: 12px; border: 1px solid var(--border); background: var(--accent-soft-2); border-radius: 8px; }
    .status-filter-label { font-size: 12px; font-weight: bold; color: var(--text-2); }
    .status-seg { display: inline-flex; border: 1px solid var(--accent-fill); border-radius: 20px; overflow: hidden; }
    .status-seg a { padding: 6px 14px; font-size: 13px; text-decoration: none; color: var(--accent); background: var(--surface); }
    .status-seg a + a { border-left: 1px solid var(--accent-fill); }
    .status-seg a.active { background: var(--accent-fill); color: var(--on-accent); font-weight: bold; }
    .status-seg small { font-size: 11px; opacity: .8; margin-left: 2px; }
    .status-filter-note { font-size: 12px; color: var(--text-2); }
    .status-filter-reset { margin-left: auto; font-size: 12px; font-weight: bold; color: var(--accent); text-decoration: none; }
    .status-filter-reset:hover { text-decoration: underline; }
    .status-filter-warning { margin: -4px 0 12px; padding: 8px 14px; font-size: 12px; background: var(--warning-bg); color: var(--warning-text); border-radius: 8px; }
    @media (max-width: 640px) {
        .status-filter { flex-direction: column; align-items: stretch; }
        .status-seg { display: flex; }
        .status-seg a { flex: 1; text-align: center; padding: 12px 8px; }
        .status-filter-reset { margin-left: 0; }
    }

    .toolbar { display: flex; justify-content: space-between; align-items: center; gap: 12px; margin-bottom: 12px; flex-wrap: wrap; }
    /* URL bricht auf max. 2 Zeilen um; würde eine 3. Zeile nötig, wird mit "…"
       abgeschnitten statt weiter umzubrechen oder horizontal zu scrollen. */
    .toolbar .url {
        font-family: monospace; font-size: 12px; color: var(--accent); text-decoration: none;
        word-break: break-all;
        display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical;
        overflow: hidden; text-overflow: ellipsis;
        flex: 1 1 260px; min-width: 0;
    }
    .toolbar .url:hover { text-decoration: underline; }
    .toolbar-actions { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; min-width: 0; }
    .toolbar form { display: flex; gap: 6px; }
    .toolbar input[type=text] { padding: 6px 10px; font-size: 13px; border: 1px solid var(--border); border-radius: 4px; min-width: 220px; background: var(--surface); color: var(--text); }
    .toolbar button { padding: 6px 14px; font-size: 13px; border: 1px solid var(--accent-fill); background: var(--accent-fill); color: var(--on-accent); border-radius: 4px; cursor: pointer; }
    /* Refresh- und Spec-Button teilen sich dieselbe Basis -> immer exakt gleich gross */
    .toolbar-btn {
        display: inline-flex; align-items: center; justify-content: center;
        padding: 6px 14px; font-size: 13px; border-radius: 4px; border: 1px solid var(--accent-fill);
        text-decoration: none; white-space: nowrap; box-sizing: border-box; line-height: 1.4;
    }
    .toolbar-btn.refresh-btn { background: var(--accent-fill); color: var(--on-accent); }
    .toolbar-btn.refresh-btn:hover { background: var(--accent-fill-hover); }
    .toolbar-btn.spec-btn { background: var(--surface); color: var(--accent); border-color: var(--accent); }
    .toolbar-btn.spec-btn:hover { background: var(--accent-soft-2); }

    .table-scroll { width: 100%; max-width: 100%; overflow-x: auto; -webkit-overflow-scrolling: touch; }
    table { border-collapse: collapse; width: 100%; background: var(--surface); }
    th, td { border: 1px solid var(--border); padding: 7px 10px; text-align: left; vertical-align: top; font-size: 0.88em; word-break: break-word; overflow-wrap: break-word; }
    th { background: var(--table-head); white-space: nowrap; }
    .sort-link { display: inline-flex; align-items: center; gap: 4px; color: inherit; text-decoration: none; cursor: pointer; }
    .sort-link:hover { color: var(--accent); }
    .sort-link .sort-arrow { font-size: 10px; color: var(--text-3); }
    .sort-link.active { color: var(--accent); }
    .sort-link.active .sort-arrow { color: var(--accent); }
    td.cell-did { font-family: monospace; font-size: 11px; word-break: break-all; max-width: 220px; }

    tr.entry-row.expandable { cursor: pointer; }
    tr.entry-row.expandable:hover { background: var(--accent-soft-2); }
    tr.entry-row .caret { display: inline-block; width: 14px; color: var(--accent); }
    tr.detail-row { display: none; background: var(--accent-soft-2); }
    tr.detail-row.open { display: table-row; }
    tr.detail-row td { padding: 14px 20px; }
    .detail-section { margin-bottom: 14px; }
    .detail-section h4 { margin: 0 0 6px; font-size: 13px; color: var(--accent); }
    .raw-switch { margin: 0 0 12px; }
    .raw-view { display: flex; flex-direction: column; gap: 14px; }
    pre.br-raw.raw-token { white-space: pre-wrap; overflow-wrap: anywhere; word-break: break-all; max-height: 180px; }
    .list-meta-raw { margin: 12px 0 0; }
    .list-meta-raw > summary { cursor: pointer; font-size: 13px; font-weight: bold; color: var(--accent); }
    .list-meta-raw[open] > summary { margin-bottom: 10px; }
    table.detail-kv { width: 100%; border: none; background: transparent; }
    table.detail-kv th { background: transparent; border: none; width: 220px; font-weight: normal; color: var(--text-2); font-size: 12px; vertical-align: top; padding: 3px 8px 3px 0; }
    table.detail-kv td { border: none; padding: 3px 0; font-size: 12px; }
    .detail-raw { color: var(--text-3); font-size: 11px; }
    ul.detail-list { margin: 0; padding-left: 0; list-style: none; font-size: 12px; }
    ul.detail-list li.is-object { margin-bottom: 8px; padding-bottom: 8px; border-bottom: 1px dashed var(--border-soft); }
    ul.detail-list li.is-object:last-child { margin-bottom: 0; padding-bottom: 0; border-bottom: none; }
    ul.detail-list li.is-scalar { position: relative; padding: 2px 0 2px 14px; }
    ul.detail-list li.is-scalar::before { content: '•'; position: absolute; left: 0; color: var(--text-3); }
    .detail-empty { color: var(--text-3); }
    .value-empty { color: var(--warning-text); font-style: italic; }

    .pagination { margin-top: 14px; display: flex; align-items: center; justify-content: space-between; gap: 14px; font-size: 13px; color: var(--text-2); flex-wrap: wrap; }
    .pagination form { display: flex; align-items: center; gap: 6px; }
    .pagination select, .pagination input[type=text] { padding: 4px 6px; font-size: 13px; border: 1px solid var(--border); border-radius: 4px; background: var(--surface); color: var(--text); }
    .page-numbers { display: flex; align-items: center; gap: 2px; }
    .page-numbers a, .page-numbers span.page-num, .page-numbers span.disabled { display: inline-flex; align-items: center; justify-content: center; min-width: 26px; height: 26px; padding: 0 4px; text-decoration: none; color: var(--accent); border-radius: 4px; }
    .page-numbers a:hover { background: var(--accent-soft-2); }
    .page-numbers span.current { background: var(--accent-fill); color: var(--on-accent); font-weight: bold; }
    .page-numbers span.disabled { color: var(--border); }
    .page-numbers span.ellipsis { color: var(--text-3); padding: 0 2px; }

    .error { background: var(--error-bg); border: 1px solid var(--error-border); color: var(--error-text); padding: 12px; border-radius: 6px; }
    .meta { font-size: 12px; color: var(--text-3); margin-top: 8px; }

    .list-meta-panel { background: var(--surface); border: 1px solid var(--border); border-radius: 10px; padding: 14px 16px; margin-bottom: 16px; }
    .list-meta-panel .hint { font-size: 11px; color: var(--text-3); margin: 0 0 10px; }
    .list-meta-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 10px; }
    .list-meta-tile { background: var(--table-head-2); border-radius: 6px; padding: 8px 10px; display: flex; flex-direction: column; gap: 3px; }
    .list-meta-tile .lbl { font-size: 11px; color: var(--text-3); }
    .list-meta-tile .val { font-size: 13px; font-weight: bold; color: var(--text); }
    .status-badge { display: inline-block; font-size: 12px; font-weight: bold; padding: 2px 10px; border-radius: 10px; white-space: nowrap; }
    .status-badge.status-valid { background: var(--success-bg); color: var(--success-text); }
    .status-badge.status-revoked { background: var(--error-bg); color: var(--error-text); }
    .status-badge.status-suspended { background: var(--warning-bg); color: var(--warning-text); }
    .status-badge.status-unknown { background: var(--neutral-bg); color: var(--neutral-text); }
    .list-meta-error { font-size: 11px; color: var(--error-text); margin: 8px 0 0; }

    /* ---- Info-Icon mit Erklärungs-Tooltip (Status/Validity) ---- */
    .info-icon {
        position: relative;
        display: inline-flex; align-items: center; justify-content: center;
        width: 14px; height: 14px; margin-left: 4px;
        border-radius: 50%; background: var(--accent-soft); color: var(--accent);
        font-size: 10px; font-weight: bold; line-height: 1;
        cursor: help; vertical-align: middle; user-select: none;
    }
    .info-icon .info-tooltip {
        display: none;
        /* Bewusst UNTERHALB des Icons (nicht oberhalb): Header-Icons stecken in
           .table-scroll, dessen overflow-x:auto laut CSS-Spec automatisch auch
           overflow-y auf "auto" setzt -- eine nach oben herausragende Tooltip-
           Box würde dadurch am oberen Rand des Containers abgeschnitten. */
        position: absolute; top: 130%; left: 50%; transform: translateX(-50%);
        width: 220px; max-width: 60vw;
        background: var(--tooltip-bg); color: var(--tooltip-text); font-size: 11px; font-weight: normal;
        line-height: 1.4; padding: 8px 10px; border-radius: 6px;
        text-align: left; white-space: normal; z-index: 30;
    }
    .info-icon .info-tooltip::after {
        content: ''; position: absolute; bottom: 100%; left: 50%; transform: translateX(-50%);
        border: 5px solid transparent; border-bottom-color: var(--tooltip-bg);
    }
    .info-icon:hover .info-tooltip,
    .info-icon:focus .info-tooltip,
    .info-icon.open .info-tooltip { display: block; }
    th .info-icon { text-transform: none; }
    .list-meta-tile .lbl .info-icon { margin-left: 5px; }
    .info-icon-cell { display: none; } /* auf Desktop reicht das Icon im Spaltenkopf; sichtbar gemacht im Mobile-Media-Query unten */

    /* ---- piTLS: "Amount of allowed issuers" (Zahl + aufklappbare Issuer-Liste) ---- */
    .issuer-count-link { color: var(--accent); font-weight: bold; text-decoration: none; }
    .issuer-count-link:hover { text-decoration: underline; }
    .issuer-count-zero { color: var(--text-3); }
    table.issuer-table { width: 100%; border-collapse: collapse; background: var(--surface); }
    table.issuer-table th, table.issuer-table td { border: 1px solid var(--border); padding: 7px 10px; font-size: 12px; text-align: left; }
    table.issuer-table th { background: var(--table-head); white-space: nowrap; }
    table.issuer-table td.cell-did { font-family: monospace; font-size: 11px; word-break: break-all; max-width: 220px; }

    /* ---- Mobile: Tabelle wird zu einer gestapelten Karten-Liste ---- */
    @media (max-width: 640px) {
        body { margin: 0.75em; min-height: calc(100vh - 1.5em); min-height: calc(100dvh - 1.5em); }

        .page-header { flex-direction: column; align-items: stretch; gap: 8px; }
        .top-nav { justify-content: center; flex-wrap: wrap; }
        .top-nav a { flex: 1; text-align: center; }

        .tabs { gap: 4px; }
        .tabs a { flex: 1; text-align: center; padding: 10px 4px; }

        .api-chips { flex-wrap: nowrap; overflow-x: auto; -webkit-overflow-scrolling: touch; padding-bottom: 6px; }
        .api-chips a { flex: 0 0 auto; }
        .api-chips small { display: none; } /* Beschreibung spart Platz, Kürzel reicht auf Mobile */

        .toolbar { flex-direction: column; align-items: stretch; gap: 8px; }
        .toolbar-actions { justify-content: space-between; }
        .toolbar form { flex: 1; }
        .toolbar input[type=text] { flex: 1; min-width: 0; }

        table, thead, tbody, tr, th, td { display: block; width: 100%; box-sizing: border-box; }
        thead { display: none; }
        table { border: none; background: transparent; }
        td.cell-did { max-width: none; font-size: 12px; }

        tr.entry-row { background: var(--surface); border: 1px solid var(--border); border-radius: 10px; margin-bottom: 8px; padding: 6px 10px; }
        tr.entry-row td { border: none; padding: 5px 0; }
        tr.entry-row td[data-label]::before {
            content: attr(data-label);
            display: block;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.02em;
            color: var(--text-3);
            margin-bottom: 1px;
        }

        tr.detail-row.open { display: block; }
        tr.detail-row { border: none; margin: -8px 0 8px; }
        tr.detail-row td { padding: 12px 14px; background: var(--accent-soft-2); border: 1px solid var(--border); border-top: none; border-radius: 0 0 10px 10px; }

        table.detail-kv th { width: 40%; }

        .list-meta-grid { grid-template-columns: repeat(2, 1fr); }

        /* thead (und damit das Spaltenkopf-Info-Icon) ist hier ausgeblendet
           -- stattdessen das Icon direkt in der Zelle neben dem Badge zeigen. */
        .info-icon-cell { display: inline-flex; }

        /* Issuer-Liste (piTLS-Detailansicht) bewusst von der generischen
           "Tabelle wird Kartenliste"-Regel ausnehmen -- sie hat keine
           data-label-Attribute und würde sonst unbeschriftet zerlaufen.
           Bleibt stattdessen eine normale (horizontal scrollbare) Tabelle. */
        table.issuer-table { display: table; width: 100%; }
        table.issuer-table thead { display: table-header-group; }
        table.issuer-table tbody { display: table-row-group; }
        table.issuer-table tr { display: table-row; }
        table.issuer-table th, table.issuer-table td { display: table-cell; width: auto; }
        table.issuer-table td.cell-did { max-width: none; }

        .pagination { justify-content: center; }
        .page-numbers .page-num:not(.current), .page-numbers .ellipsis, .page-numbers .nav-edge { display: none; }
        .page-numbers { gap: 10px; }
    }
</style>
</head>
<body<?= isPreview() ? ' class="is-preview"' : '' ?>>

<div class="page-header">
    <a class="brand" href="index.php" aria-label="Swiyu Trust Registry Explorer">
        <svg class="tre-logo" width="28" height="28" viewBox="0 0 100 100" aria-hidden="true">
            <rect class="tre-bar" x="12" y="14" width="76" height="16" rx="6"></rect>
            <rect class="tre-bar" x="12" y="40" width="76" height="16" rx="6"></rect>
            <rect class="tre-bar" x="12" y="66" width="48" height="16" rx="6"></rect>
            <circle class="tre-seal" cx="76" cy="74" r="20"></circle>
            <path class="tre-check" d="M67 74 L74 81 L87 66" fill="none" stroke-width="7" stroke-linecap="round" stroke-linejoin="round"></path>
        </svg>
        <h1>Swiyu Trust Registry Explorer</h1>
        <?= renderPreviewBadge() ?>
    </a>
    <div class="top-nav">
        <a href="index.php" class="active" data-tour="nav">Explorer</a>
        <a href="history.php" data-tour="nav">History</a>
        <a href="<?= htmlspecialchars($config['docs']['overview_url']) ?>" class="docs-link" target="_blank" rel="noopener">📖 Docs</a>
        <div class="lang-switch" role="group" aria-label="<?= htmlspecialchars(t('nav.lang_toggle_aria')) ?>">
            <a href="<?= htmlspecialchars(langSwitchUrl('de')) ?>" class="lang-btn<?= currentLang() === 'de' ? ' active' : '' ?>">DE</a>
            <a href="<?= htmlspecialchars(langSwitchUrl('en')) ?>" class="lang-btn<?= currentLang() === 'en' ? ' active' : '' ?>">EN</a>
            <a href="<?= htmlspecialchars(langSwitchUrl('fr')) ?>" class="lang-btn<?= currentLang() === 'fr' ? ' active' : '' ?>">FR</a>
        </div>
        <button type="button" class="theme-toggle" id="themeToggle" aria-label="<?= htmlspecialchars(t('nav.theme_toggle_aria')) ?>">🌙</button>
        <?= renderTourHelpButton() ?>
    </div>
</div>

<div class="did-search" data-tour="search">
    <form method="get">
        <?php foreach (['env' => $envKey, 'api' => $apiKey, 'q' => $query, 'p' => $page] as $k => $v): ?>
            <?php if ($v !== null && $v !== ''): ?>
                <input type="hidden" name="<?= htmlspecialchars($k) ?>" value="<?= htmlspecialchars((string) $v) ?>">
            <?php endif; ?>
        <?php endforeach; ?>
        <input type="text" name="did" placeholder="<?= htmlspecialchars(t('search.did_placeholder')) ?>" value="<?= htmlspecialchars($didQuery) ?>">
        <select name="did_env">
            <?php foreach ($config['environments'] as $key => $env): ?>
                <option value="<?= htmlspecialchars($key) ?>" <?= $key === $didEnv ? 'selected' : '' ?>><?= htmlspecialchars($env['label']) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit"><?= htmlspecialchars(t('common.search_button')) ?></button>
    </form>
    <p class="hint"><?= htmlspecialchars(t('search.hint')) ?></p>

    <?php if ($didSearch !== null): ?>
        <?php
        $idtsHits  = $didSearch['results']['idTS']['entries'] ?? [];
        $idtsError = $didSearch['results']['idTS']['error'] ?? null;
        ?>
        <div class="br-top">
            <section class="br-panel" aria-label="<?= htmlspecialchars(t('base.trust_registry')) ?>">
                <div class="br-panel-head">
                    <h3><?= htmlspecialchars(t('base.trust_registry')) ?></h3>
                    <span class="did-badge"><?= count($idtsHits) ?> <?= htmlspecialchars(t(count($idtsHits) === 1 ? 'search.hit_singular' : 'search.hit_plural')) ?></span>
                </div>
                <div class="br-panel-body">
                    <?php if ($idtsError !== null): ?>
                        <p class="did-error"><?= htmlspecialchars($idtsError) ?></p>
                    <?php elseif ($idtsHits === []): ?>
                        <p class="br-msg">0 <?= htmlspecialchars(t('search.hit_plural')) ?></p>
                    <?php endif; ?>
                    <?php foreach ($idtsHits as $i => $hit): ?>
                        <div class="tr-hit">
                            <?php if (count($idtsHits) > 1): ?><p class="did-hit-label"><?= htmlspecialchars(t('search.hit_n', [$i + 1])) ?></p><?php endif; ?>
                            <?php if ($didSearch['entity_name'] !== null && $i === 0): ?>
                                <div class="tr-entity">
                                    <span class="lbl"><?= htmlspecialchars(t('search.entity_label')) ?></span>
                                    <span class="val"><?= htmlspecialchars($didSearch['entity_name']) ?></span>
                                </div>
                            <?php endif; ?>
                            <dl class="br-dl">
                                <?php foreach ($config['apis']['idTS']['columns'] as $col):
                                    if (!in_array($col['type'], ['registry_ids', 'status_badge', 'validity_badge'], true)) { continue; }
                                ?>
                                    <dt><?= htmlspecialchars($col['label']) ?></dt>
                                    <dd>
                                        <?php if ($col['type'] === 'registry_ids'): ?><?= formatRegistryIdsCell(getPath($hit, $col['key'])) ?>
                                        <?php elseif ($col['type'] === 'status_badge'): ?><?= formatStatusBadgeCell($hit) ?>
                                        <?php else: ?><?= formatValidityBadgeCell($hit) ?>
                                        <?php endif; ?>
                                    </dd>
                                <?php endforeach; ?>
                            </dl>
                            <details class="tr-full">
                                <summary><?= htmlspecialchars(t('base.show_statement')) ?></summary>
                                <?= renderEntryDetail($hit, $config['apis']['idTS']['show_header'] ?? true) ?>
                            </details>
                            <?php if (isset($hit['sub']) && is_string($hit['sub'])): ?>
                                <div class="br-actions">
                                    <a class="br-link" href="<?= htmlspecialchars(buildUrl($didEnv, 'idTS', $hit['sub'], 0)) ?>"><?= htmlspecialchars(t('base.open_in_explorer')) ?> &rarr;</a>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>
            <?= renderBaseRegistrySearchPanel($baseResults, $didSearch['entity_name']) ?>
        </div>

        <?php
        $otherApis = array_diff_key($config['apis'], ['idTS' => true]);
        $noHitLabels = [];
        foreach ($otherApis as $apiKeyIter => $apiCfgIter) {
            $r = $didSearch['results'][$apiKeyIter] ?? ['entries' => [], 'error' => null];
            if (count($r['entries']) === 0 && $r['error'] === null) {
                $noHitLabels[] = $apiCfgIter['label'];
            }
        }
        ?>
        <?php if ($otherApis !== []): ?>
        <h3 class="br-others-title"><?= htmlspecialchars(t('base.other_apis')) ?></h3>
        <div class="did-results">
            <?php foreach ($otherApis as $apiKeyIter => $apiCfgIter):
                $r = $didSearch['results'][$apiKeyIter] ?? ['entries' => [], 'error' => null];
                $hitCount = count($r['entries']);
            ?>
                <?php if ($hitCount > 0 || $r['error'] !== null): ?>
                    <div class="did-card">
                        <div class="did-card-head" onclick="this.nextElementSibling.classList.toggle('open')">
                            <span class="name"><?= htmlspecialchars($apiCfgIter['label']) ?> <small><?= htmlspecialchars($apiCfgIter['description']) ?></small></span>
                            <?php if ($r['error'] !== null): ?>
                                <span class="did-error"><?= htmlspecialchars(t('common.error')) ?></span>
                            <?php else: ?>
                                <span class="did-badge"><?= $hitCount ?> <?= htmlspecialchars(t($hitCount === 1 ? 'search.hit_singular' : 'search.hit_plural')) ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="did-card-body">
                            <?php if ($r['error'] !== null): ?>
                                <p class="did-error"><?= htmlspecialchars($r['error']) ?></p>
                            <?php else: ?>
                                <?php foreach ($r['entries'] as $i => $hit): ?>
                                    <?php if ($hitCount > 1): ?><p class="did-hit-label"><?= htmlspecialchars(t('search.hit_n', [$i + 1])) ?></p><?php endif; ?>
                                    <?= renderEntryDetail($hit, $apiCfgIter['show_header'] ?? true) ?>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endforeach; ?>

            <?php if ($noHitLabels !== []): ?>
                <div class="did-card no-hit">
                    <div class="did-card-head">
                        <span class="name"><?= htmlspecialchars(implode(', ', $noHitLabels)) ?></span>
                        <span class="did-badge" style="background:transparent;color:var(--text-3);">0 <?= htmlspecialchars(t('search.hit_plural')) ?></span>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<div class="tabs" data-tour="env">
    <?php foreach ($config['environments'] as $key => $env): ?>
        <a href="<?= htmlspecialchars(buildUrl($key, $apiKey, '', 0)) ?>"
           class="<?= $key === $envKey ? 'active' : '' ?>"><?= htmlspecialchars($env['label']) ?></a>
    <?php endforeach; ?>
</div>

<div class="api-chips" data-tour="apis">
    <?php foreach ($config['apis'] as $key => $api): ?>
        <a href="<?= htmlspecialchars(buildUrl($envKey, $key, '', 0)) ?>" data-api="<?= htmlspecialchars($key) ?>"
           class="<?= $key === $apiKey ? 'active' : '' ?>">
            <?= htmlspecialchars($api['label']) ?>
            <small><?= htmlspecialchars($api['description']) ?></small>
        </a>
    <?php endforeach; ?>
</div>

<?php /* Ergebnisbereich. Liegt die Tabelle im Session-Cache, wird sie direkt
         gerendert. Sonst liefert die Seite nur das Skeleton; assets/result-loader.js
         holt den Bereich per fetch() (?fragment=1) und ersetzt es. So hängt das
         Skeleton nicht an Server-Streaming/Pufferung. */ ?>
<div id="treResult"<?= $defer ? ' data-pending="1" data-err="' . htmlspecialchars(t('error.load_failed')) . '" data-retry="' . htmlspecialchars(t('error.retry')) . '"' : '' ?>>
<?php if ($apiKey === null): ?>

    <p style="color:var(--text-3);"><?= htmlspecialchars(t('explorer.select_prompt')) ?></p>

<?php elseif ($defer): ?>

    <noscript>
        <p class="error"><?= htmlspecialchars(t('explorer.no_js')) ?>
            <a href="<?= htmlspecialchars(buildUrl($envKey, $apiKey, $query, $page, array_filter(['sync' => 1, 'per_page' => $perPage !== $config['page_size'] ? $perPage : null, 'sort' => $sortKey, 'dir' => $sortKey !== null ? $sortDir : null, 'status' => $statusParam]))) ?>"><?= htmlspecialchars(t('explorer.no_js_link')) ?></a>
        </p>
    </noscript>
    <?= renderResultSkeleton($apiCfg, 10, !empty($apiCfg['base_registry_did_field']) && !empty($config['environments'][$envKey]['base_registry_url'])) ?>

<?php elseif ($data !== null): ?>

    <?php renderResultArea($config, $envKey, $apiKey, $query, $perPage, $sortKey, $sortDir, $statusFilter, $statusParam, $pageSizeOptions, $data); ?>

<?php endif; ?>
</div>

<?= renderFooter($config) ?>
<?= renderTourAssets() ?>

<script>
window.BR_CONFIG = <?= json_encode([
    'endpoint' => 'base_registry.php',
    'env'      => $envKey,
    'i18n'     => [
        'loading'     => t('base.status.loading'),
        'unavailable' => t('base.status.unavailable'),
        'copied'      => t('base.copied'),
    ],
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
</script>
<script src="<?= htmlspecialchars(assetUrl('base-registry.js')) ?>"></script>
<script src="<?= htmlspecialchars(assetUrl('raw-view.js')) ?>"></script>
<script src="<?= htmlspecialchars(assetUrl('result-loader.js')) ?>"></script>
<script>
// Delegierte Handler: gelten auch für Zeilen/Icons, die erst später per
// result-loader.js nachgeladen werden.
document.addEventListener('click', function (e) {
    var target = e.target instanceof Element ? e.target : null;
    if (!target) return;

    // Info-Icons (Status/Validity-Erklärung): Klick/Tap toggelt die Tooltip-
    // Sichtbarkeit (nötig für Touch-Geräte ohne Hover), Klick ausserhalb
    // schliesst alle offenen Tooltips wieder.
    var icon = target.closest('.info-icon');
    var wasOpen = icon ? icon.classList.contains('open') : false;
    document.querySelectorAll('.info-icon.open').forEach(function (i) { i.classList.remove('open'); });
    if (icon) {
        if (!wasOpen) icon.classList.add('open');
        return;
    }

    // Aufklappbare Zeilen
    var row = target.closest('tr.entry-row.expandable');
    if (!row) return;
    var detail = row.nextElementSibling;
    if (!detail || !detail.classList.contains('detail-row')) return;
    var isOpen = detail.classList.toggle('open');
    var caret = row.querySelector('.caret');
    if (caret) caret.innerHTML = isOpen ? '&#9662;' : '&#9656;';
});
</script>

</body>
</html>
