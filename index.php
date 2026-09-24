<?php
declare(strict_types=1);

require __DIR__ . '/functions.php';
$config = require __DIR__ . '/config.php';

// ---- Parameter aus der URL lesen ----

$envKey = $_GET['env'] ?? array_key_first($config['environments']);
if (!isset($config['environments'][$envKey])) {
    $envKey = array_key_first($config['environments']);
}

$apiKey = $_GET['api'] ?? null;
if ($apiKey !== null && !isset($config['apis'][$apiKey])) {
    $apiKey = null;
}

$query = trim((string) ($_GET['q'] ?? ''));
$page  = max(0, (int) ($_GET['p'] ?? 0));
$forceRefresh = isset($_GET['refresh']);

// Seitengrösse: wählbar (20/50/100/200), fällt aber bei jeder Navigation, die
// diesen Wert nicht explizit mitgibt (z.B. Umgebungs-/API-Wechsel), auf den
// Standardwert aus config.php zurück — kein dauerhaftes Merken gewünscht.
$pageSizeOptions = $config['page_size_options'] ?? [$config['page_size']];
$perPage = (int) ($_GET['per_page'] ?? $config['page_size']);
if (!in_array($perPage, $pageSizeOptions, true)) {
    $perPage = $config['page_size'];
}

// ---- Globale DID-Suche (unabhängig von der Umgebungs-/API-Auswahl unten) ----

$didQuery = trim((string) ($_GET['did'] ?? ''));
$didEnv   = $_GET['did_env'] ?? $envKey;
if (!isset($config['environments'][$didEnv])) {
    $didEnv = $envKey;
}
$didSearch = null; // ['results' => [...], 'entity_name' => ...] wenn eine Suche aktiv ist

if ($didQuery !== '') {
    $didBaseUrl = $config['environments'][$didEnv]['base_url'];
    $didSearch  = searchAcrossApis($didEnv, $didBaseUrl, $config['apis'], $didQuery, $config['cache_ttl']);
}

/**
 * Baut eine URL zu dieser Seite mit den aktuellen Parametern, überschrieben
 * durch $overrides. Damit bleiben Umgebung/API/Suche beim Navigieren erhalten.
 * per_page wird NUR übernommen, wenn es explizit in $overrides steht (z.B. für
 * die Pagination-Links) — beim Umgebungs-/API-Wechsel via Tabs/Chips fällt die
 * Seitengrösse absichtlich auf den Standardwert zurück.
 */
function buildUrl(string $envKey, ?string $apiKey, string $query, int $page, array $overrides = []): string
{
    $params = array_merge([
        'env' => $envKey,
        'api' => $apiKey,
        'q'   => $query,
        'p'   => $page,
    ], $overrides);

    $params = array_filter($params, static fn ($v) => $v !== null && $v !== '');
    return '?' . http_build_query($params);
}

$errorMessage = null;
$entries = [];
$fetchedCount = 0;
$listMeta = null;

if ($apiKey !== null) {
    $apiCfg = $config['apis'][$apiKey];
    $baseUrl = $config['environments'][$envKey]['base_url'];

    try {
        $fetched = getEntriesCached($envKey, $apiKey, $baseUrl, $apiCfg, $forceRefresh, $config['cache_ttl'], $config['apis']);
        $entries = $fetched['entries'];
        $listMeta = $fetched['list_meta'];
        $fetchedCount = count($entries);
        $entries = filterEntries($entries, $query);
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

?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Swiyu Trust Registry Explorer</title>
<style>
    body { font-family: Arial, sans-serif; margin: 2em; color: #222; background: #fafafa; }
    h1 { font-size: 1.3em; margin-bottom: 1em; }

    .tabs { display: flex; gap: 4px; border-bottom: 1px solid #ccc; margin-bottom: 16px; }
    .tabs a { padding: 8px 18px; font-size: 14px; text-decoration: none; color: #555; border-bottom: 3px solid transparent; }
    .tabs a.active { color: #0b5fa5; border-bottom-color: #0b5fa5; font-weight: bold; }

    .did-search { background: #fff; border: 1px solid #ddd; border-radius: 10px; padding: 14px 16px; margin-bottom: 20px; }
    .did-search form { display: flex; gap: 8px; }
    .did-search input[type=text] { flex: 1; padding: 7px 10px; font-size: 12px; font-family: monospace; border: 1px solid #ccc; border-radius: 4px; }
    .did-search select { padding: 7px; font-size: 13px; border: 1px solid #ccc; border-radius: 4px; }
    .did-search button { padding: 7px 16px; font-size: 13px; border: 1px solid #0b5fa5; background: #0b5fa5; color: #fff; border-radius: 4px; cursor: pointer; }
    .did-search .hint { font-size: 11px; color: #999; margin: 6px 0 0; }

    .did-entity { display: flex; align-items: center; gap: 10px; background: #eef6fd; border-radius: 6px; padding: 8px 12px; margin-top: 12px; }
    .did-entity .lbl { font-size: 11px; color: #888; }
    .did-entity .val { font-size: 14px; font-weight: bold; color: #0b5fa5; }

    .did-results { margin-top: 14px; display: flex; flex-direction: column; gap: 8px; }
    .did-card { background: #fbfdff; border: 1px solid #bcd8ee; border-radius: 8px; padding: 10px 14px; }
    .did-card.no-hit { opacity: 0.55; border-color: #ddd; background: transparent; }
    .did-card-head { display: flex; justify-content: space-between; align-items: center; cursor: pointer; }
    .did-card-head .name { font-weight: bold; font-size: 14px; }
    .did-card-head .name small { font-weight: normal; color: #888; font-size: 12px; margin-left: 6px; }
    .did-badge { background: #e6f1fb; color: #0b5fa5; font-size: 12px; padding: 2px 10px; border-radius: 10px; }
    .did-card-body { display: none; margin-top: 10px; padding-top: 10px; border-top: 1px solid #ddd; }
    .did-card-body.open { display: block; }
    .did-hit-label { font-size: 11px; color: #999; margin: 10px 0 4px; text-transform: uppercase; }
    .did-hit-label:first-child { margin-top: 0; }
    .did-error { color: #a12622; font-size: 12px; }

    .api-chips { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 20px; }
    .api-chips a { padding: 6px 14px; font-size: 13px; border-radius: 16px; border: 1px solid #ccc; text-decoration: none; color: #444; background: #fff; }
    .api-chips a.active { background: #e6f1fb; border-color: #0b5fa5; color: #0b5fa5; font-weight: bold; }
    .api-chips small { color: #888; margin-left: 4px; }

    .toolbar { display: flex; justify-content: space-between; align-items: center; gap: 12px; margin-bottom: 12px; flex-wrap: wrap; }
    .toolbar .url { font-family: monospace; font-size: 12px; color: #0b5fa5; word-break: break-all; text-decoration: none; }
    .toolbar .url:hover { text-decoration: underline; }
    .toolbar form { display: flex; gap: 6px; }
    .toolbar input[type=text] { padding: 6px 10px; font-size: 13px; border: 1px solid #ccc; border-radius: 4px; min-width: 220px; }
    .toolbar button, .refresh-btn { padding: 6px 14px; font-size: 13px; border: 1px solid #0b5fa5; background: #0b5fa5; color: #fff; border-radius: 4px; cursor: pointer; text-decoration: none; }

    .table-scroll { width: 100%; max-width: 100%; overflow-x: auto; -webkit-overflow-scrolling: touch; }
    table { border-collapse: collapse; width: 100%; background: #fff; }
    th, td { border: 1px solid #ddd; padding: 7px 10px; text-align: left; vertical-align: top; font-size: 0.88em; word-break: break-word; overflow-wrap: break-word; }
    th { background: #f2f2f2; white-space: nowrap; }

    tr.entry-row.expandable { cursor: pointer; }
    tr.entry-row.expandable:hover { background: #f7fbff; }
    tr.entry-row .caret { display: inline-block; width: 14px; color: #0b5fa5; }
    tr.detail-row { display: none; background: #fbfcfe; }
    tr.detail-row.open { display: table-row; }
    tr.detail-row td { padding: 14px 20px; }
    .detail-section { margin-bottom: 14px; }
    .detail-section h4 { margin: 0 0 6px; font-size: 13px; color: #0b5fa5; }
    table.detail-kv { width: 100%; border: none; background: transparent; }
    table.detail-kv th { background: transparent; border: none; width: 220px; font-weight: normal; color: #666; font-size: 12px; vertical-align: top; padding: 3px 8px 3px 0; }
    table.detail-kv td { border: none; padding: 3px 0; font-size: 12px; }
    .detail-raw { color: #999; font-size: 11px; }
    ul.detail-list { margin: 0; padding-left: 0; list-style: none; font-size: 12px; }
    ul.detail-list li.is-object { margin-bottom: 8px; padding-bottom: 8px; border-bottom: 1px dashed #e5e5e5; }
    ul.detail-list li.is-object:last-child { margin-bottom: 0; padding-bottom: 0; border-bottom: none; }
    ul.detail-list li.is-scalar { position: relative; padding: 2px 0 2px 14px; }
    ul.detail-list li.is-scalar::before { content: '•'; position: absolute; left: 0; color: #bbb; }
    .detail-empty { color: #999; }
    .value-empty { color: #b06a00; font-style: italic; }

    .pagination { margin-top: 14px; display: flex; align-items: center; justify-content: space-between; gap: 14px; font-size: 13px; color: #555; flex-wrap: wrap; }
    .pagination form { display: flex; align-items: center; gap: 6px; }
    .pagination select, .pagination input[type=text] { padding: 4px 6px; font-size: 13px; border: 1px solid #ccc; border-radius: 4px; }
    .page-numbers { display: flex; align-items: center; gap: 2px; }
    .page-numbers a, .page-numbers span.page-num, .page-numbers span.disabled { display: inline-flex; align-items: center; justify-content: center; min-width: 26px; height: 26px; padding: 0 4px; text-decoration: none; color: #0b5fa5; border-radius: 4px; }
    .page-numbers a:hover { background: #eef6fd; }
    .page-numbers span.current { background: #0b5fa5; color: #fff; font-weight: bold; }
    .page-numbers span.disabled { color: #ccc; }
    .page-numbers span.ellipsis { color: #999; padding: 0 2px; }

    .error { background: #fdecea; border: 1px solid #f5c2c0; color: #a12622; padding: 12px; border-radius: 6px; }
    .meta { font-size: 12px; color: #888; margin-top: 8px; }

    .list-meta-panel { background: #fff; border: 1px solid #ddd; border-radius: 10px; padding: 14px 16px; margin-bottom: 16px; }
    .list-meta-panel .hint { font-size: 11px; color: #999; margin: 0 0 10px; }
    .list-meta-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; }
    .list-meta-tile { background: #f7f9fb; border-radius: 6px; padding: 8px 10px; display: flex; flex-direction: column; gap: 3px; }
    .list-meta-tile .lbl { font-size: 11px; color: #888; }
    .list-meta-tile .val { font-size: 13px; font-weight: bold; color: #222; }
    .status-badge { display: inline-block; font-size: 12px; font-weight: bold; padding: 2px 10px; border-radius: 10px; }
    .status-badge.status-valid { background: #e2f3e6; color: #1e7d34; }
    .status-badge.status-revoked { background: #fdecea; color: #a12622; }
    .status-badge.status-suspended { background: #fdf3e2; color: #a1651f; }
    .status-badge.status-unknown { background: #eee; color: #666; }
    .list-meta-error { font-size: 11px; color: #a12622; margin: 8px 0 0; }

    /* ---- Mobile: Tabelle wird zu einer gestapelten Karten-Liste ---- */
    @media (max-width: 640px) {
        body { margin: 0.75em; }

        .tabs { gap: 4px; }
        .tabs a { flex: 1; text-align: center; padding: 10px 4px; }

        .api-chips { flex-wrap: nowrap; overflow-x: auto; -webkit-overflow-scrolling: touch; padding-bottom: 6px; }
        .api-chips a { flex: 0 0 auto; }
        .api-chips small { display: none; } /* Beschreibung spart Platz, Kürzel reicht auf Mobile */

        .toolbar { flex-direction: column; align-items: stretch; gap: 8px; }
        .toolbar .url { display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .toolbar > div { justify-content: space-between; }
        .toolbar form { flex: 1; }
        .toolbar input[type=text] { flex: 1; min-width: 0; }

        table, thead, tbody, tr, th, td { display: block; width: 100%; box-sizing: border-box; }
        thead { display: none; }
        table { border: none; background: transparent; }

        tr.entry-row { background: #fff; border: 1px solid #ddd; border-radius: 10px; margin-bottom: 8px; padding: 6px 10px; }
        tr.entry-row td { border: none; padding: 5px 0; }
        tr.entry-row td[data-label]::before {
            content: attr(data-label);
            display: block;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.02em;
            color: #999;
            margin-bottom: 1px;
        }

        tr.detail-row.open { display: block; }
        tr.detail-row { border: none; margin: -8px 0 8px; }
        tr.detail-row td { padding: 12px 14px; background: #fbfcfe; border: 1px solid #ddd; border-top: none; border-radius: 0 0 10px 10px; }

        table.detail-kv th { width: 40%; }

        .list-meta-grid { grid-template-columns: repeat(2, 1fr); }

        .pagination { justify-content: center; }
        .page-numbers .page-num:not(.current), .page-numbers .ellipsis, .page-numbers .nav-edge { display: none; }
        .page-numbers { gap: 10px; }
    }
</style>
</head>
<body>

<h1>Swiyu Trust Registry Explorer</h1>

<div class="did-search">
    <form method="get">
        <?php foreach (['env' => $envKey, 'api' => $apiKey, 'q' => $query, 'p' => $page] as $k => $v): ?>
            <?php if ($v !== null && $v !== ''): ?>
                <input type="hidden" name="<?= htmlspecialchars($k) ?>" value="<?= htmlspecialchars((string) $v) ?>">
            <?php endif; ?>
        <?php endforeach; ?>
        <input type="text" name="did" placeholder="DID durchsuchen (über alle APIs)..." value="<?= htmlspecialchars($didQuery) ?>">
        <select name="did_env">
            <?php foreach ($config['environments'] as $key => $env): ?>
                <option value="<?= htmlspecialchars($key) ?>" <?= $key === $didEnv ? 'selected' : '' ?>><?= htmlspecialchars($env['label']) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit">Suchen</button>
    </form>
    <p class="hint">Durchsucht alle Trust Statements der gewählten Umgebung nach dem eingegebenen Begriff (z.B. eine DID).</p>

    <?php if ($didSearch !== null): ?>
        <?php if ($didSearch['entity_name'] !== null): ?>
            <div class="did-entity">
                <span class="lbl">Entität laut idTS</span>
                <span class="val"><?= htmlspecialchars($didSearch['entity_name']) ?></span>
            </div>
        <?php endif; ?>

        <div class="did-results">
            <?php foreach ($config['apis'] as $apiKeyIter => $apiCfgIter):
                $r = $didSearch['results'][$apiKeyIter] ?? ['entries' => [], 'error' => null];
                $hitCount = count($r['entries']);
            ?>
                <?php if ($hitCount > 0 || $r['error'] !== null): ?>
                    <div class="did-card">
                        <div class="did-card-head" onclick="this.nextElementSibling.classList.toggle('open')">
                            <span class="name"><?= htmlspecialchars($apiCfgIter['label']) ?> <small><?= htmlspecialchars($apiCfgIter['description']) ?></small></span>
                            <?php if ($r['error'] !== null): ?>
                                <span class="did-error">Fehler</span>
                            <?php else: ?>
                                <span class="did-badge"><?= $hitCount ?> Treffer</span>
                            <?php endif; ?>
                        </div>
                        <div class="did-card-body">
                            <?php if ($r['error'] !== null): ?>
                                <p class="did-error"><?= htmlspecialchars($r['error']) ?></p>
                            <?php else: ?>
                                <?php foreach ($r['entries'] as $i => $hit): ?>
                                    <?php if ($hitCount > 1): ?><p class="did-hit-label">Treffer <?= $i + 1 ?></p><?php endif; ?>
                                    <?= renderEntryDetail($hit) ?>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endforeach; ?>

            <?php
            $noHitLabels = [];
            foreach ($config['apis'] as $apiKeyIter => $apiCfgIter) {
                $r = $didSearch['results'][$apiKeyIter] ?? ['entries' => [], 'error' => null];
                if ($r['error'] === null && count($r['entries']) === 0) {
                    $noHitLabels[] = $apiCfgIter['label'];
                }
            }
            ?>
            <?php if ($noHitLabels !== []): ?>
                <div class="did-card no-hit">
                    <div class="did-card-head">
                        <span class="name"><?= htmlspecialchars(implode(', ', $noHitLabels)) ?></span>
                        <span class="did-badge" style="background:transparent;color:#999;">0 Treffer</span>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<div class="tabs">
    <?php foreach ($config['environments'] as $key => $env): ?>
        <a href="<?= htmlspecialchars(buildUrl($key, $apiKey, '', 0)) ?>"
           class="<?= $key === $envKey ? 'active' : '' ?>"><?= htmlspecialchars($env['label']) ?></a>
    <?php endforeach; ?>
</div>

<div class="api-chips">
    <?php foreach ($config['apis'] as $key => $api): ?>
        <a href="<?= htmlspecialchars(buildUrl($envKey, $key, '', 0)) ?>"
           class="<?= $key === $apiKey ? 'active' : '' ?>">
            <?= htmlspecialchars($api['label']) ?>
            <small><?= htmlspecialchars($api['description']) ?></small>
        </a>
    <?php endforeach; ?>
</div>

<?php if ($apiKey === null): ?>

    <p style="color:#888;">Bitte oben eine Umgebung und eine API auswählen.</p>

<?php else: ?>

    <?php $fullUrl = $config['environments'][$envKey]['base_url'] . $config['apis'][$apiKey]['path']; ?>

    <div class="toolbar">
        <a class="url" href="<?= htmlspecialchars($fullUrl) ?>" target="_blank" rel="noopener"><?= htmlspecialchars($fullUrl) ?></a>
        <div style="display:flex; gap:8px; align-items:center;">
            <form method="get">
                <input type="hidden" name="env" value="<?= htmlspecialchars($envKey) ?>">
                <input type="hidden" name="api" value="<?= htmlspecialchars($apiKey) ?>">
                <?php if ($perPage !== $config['page_size']): ?>
                    <input type="hidden" name="per_page" value="<?= htmlspecialchars((string) $perPage) ?>">
                <?php endif; ?>
                <?php if ($totalFiltered > $pageSize || $fetchedCount > $pageSize): ?>
                    <input type="text" name="q" placeholder="Suchen..." value="<?= htmlspecialchars($query) ?>">
                    <button type="submit">Suchen</button>
                <?php endif; ?>
            </form>
            <a class="refresh-btn" href="<?= htmlspecialchars(buildUrl($envKey, $apiKey, $query, 0, array_filter(['refresh' => 1, 'per_page' => $perPage !== $config['page_size'] ? $perPage : null]))) ?>">Abfragen</a>
        </div>
    </div>

    <?php if ($listMeta !== null): ?>
        <div class="list-meta-panel">
            <p class="hint">Gültigkeit dieser Trust-List (gilt für die gesamte Liste)</p>
            <div class="list-meta-grid">
                <div class="list-meta-tile">
                    <span class="lbl">Gültig ab (nbf)</span>
                    <span class="val"><?= htmlspecialchars(formatUnixTimestamp($listMeta['nbf'])) ?></span>
                </div>
                <div class="list-meta-tile">
                    <span class="lbl">Gültig bis (exp)</span>
                    <span class="val"><?= htmlspecialchars(formatUnixTimestamp($listMeta['exp'])) ?></span>
                </div>
                <div class="list-meta-tile">
                    <span class="lbl">Erstellt am (iat)</span>
                    <span class="val"><?= htmlspecialchars(formatUnixTimestamp($listMeta['iat'])) ?></span>
                </div>
                <div class="list-meta-tile">
                    <span class="lbl">Status</span>
                    <?php
                        $statusClass = match ($listMeta['status_value']) {
                            0 => 'status-valid',
                            1 => 'status-revoked',
                            2 => 'status-suspended',
                            default => 'status-unknown',
                        };
                    ?>
                    <span class="val">
                        <span class="status-badge <?= $statusClass ?>"><?= htmlspecialchars($listMeta['status_label']) ?></span>
                    </span>
                </div>
            </div>
            <?php if ($listMeta['status_error'] !== null): ?>
                <p class="list-meta-error">Status nicht abrufbar (<?= htmlspecialchars($listMeta['status_error']) ?>)</p>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if ($errorMessage !== null): ?>

        <div class="error">Fehler beim Abrufen/Dekodieren: <?= htmlspecialchars($errorMessage) ?></div>

    <?php else: ?>

        <div class="table-scroll">
        <table>
            <thead>
                <tr>
                    <?php foreach ($config['apis'][$apiKey]['columns'] as $col): ?>
                        <th><?= htmlspecialchars($col['label']) ?></th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php
                $columnCount = count($config['apis'][$apiKey]['columns']);
                $isExpandable = !empty($config['apis'][$apiKey]['expandable']);
                ?>
                <?php if (empty($pageEntries)): ?>
                    <tr><td colspan="<?= $columnCount ?>" style="color:#888;">Keine Einträge gefunden.</td></tr>
                <?php endif; ?>
                <?php foreach ($pageEntries as $i => $entry): ?>
                    <tr class="entry-row<?= $isExpandable ? ' expandable' : '' ?>">
                        <?php foreach ($config['apis'][$apiKey]['columns'] as $j => $col): ?>
                            <td data-label="<?= htmlspecialchars($col['label']) ?>">
                                <?php if ($isExpandable && $j === 0): ?>
                                    <span class="caret">&#9656;</span>
                                <?php endif; ?>
                                <?php if ($col['type'] === 'multilang'): ?>
                                    <?= formatMultilangCell($entry, $col['key']) ?>
                                <?php elseif ($col['type'] === 'registry_ids'): ?>
                                    <?= formatRegistryIdsCell(getPath($entry, $col['key'])) ?>
                                <?php elseif ($col['type'] === 'status_badge'): ?>
                                    <?= formatStatusBadgeCell($entry) ?>
                                <?php else: ?>
                                    <?= formatCellValue(getPath($entry, $col['key']), $col['type']) ?>
                                <?php endif; ?>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                    <?php if ($isExpandable): ?>
                        <tr class="detail-row">
                            <td colspan="<?= $columnCount ?>"><?= renderEntryDetail($entry) ?></td>
                        </tr>
                    <?php endif; ?>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>

        <div class="pagination">
            <?php if ($showPagination): ?>
                <form method="get" class="page-size-form">
                    <input type="hidden" name="env" value="<?= htmlspecialchars($envKey) ?>">
                    <input type="hidden" name="api" value="<?= htmlspecialchars($apiKey) ?>">
                    <?php if ($query !== ''): ?><input type="hidden" name="q" value="<?= htmlspecialchars($query) ?>"><?php endif; ?>
                    <input type="hidden" name="p" value="0">
                    <label>Pro Seite
                        <select name="per_page" onchange="this.form.submit()">
                            <?php foreach ($pageSizeOptions as $opt): ?>
                                <option value="<?= $opt ?>" <?= $opt === $perPage ? 'selected' : '' ?>><?= $opt ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                </form>

                <div class="page-numbers">
                    <?php if ($page > 0): ?>
                        <a class="nav-edge" href="<?= htmlspecialchars(buildUrl($envKey, $apiKey, $query, 0, ['per_page' => $perPage])) ?>" title="Erste Seite">&laquo;</a>
                        <a class="nav-step" href="<?= htmlspecialchars(buildUrl($envKey, $apiKey, $query, $page - 1, ['per_page' => $perPage])) ?>" title="Vorherige Seite">&lsaquo;</a>
                    <?php else: ?>
                        <span class="disabled nav-edge">&laquo;</span>
                        <span class="disabled nav-step">&lsaquo;</span>
                    <?php endif; ?>

                    <?php foreach (paginationRange($page + 1, $totalPages) as $item): ?>
                        <?php if ($item === '…'): ?>
                            <span class="ellipsis">…</span>
                        <?php elseif ($item === $page + 1): ?>
                            <span class="page-num current"><?= $item ?></span>
                        <?php else: ?>
                            <a class="page-num" href="<?= htmlspecialchars(buildUrl($envKey, $apiKey, $query, $item - 1, ['per_page' => $perPage])) ?>"><?= $item ?></a>
                        <?php endif; ?>
                    <?php endforeach; ?>

                    <?php if ($page + 1 < $totalPages): ?>
                        <a class="nav-step" href="<?= htmlspecialchars(buildUrl($envKey, $apiKey, $query, $page + 1, ['per_page' => $perPage])) ?>" title="Nächste Seite">&rsaquo;</a>
                        <a class="nav-edge" href="<?= htmlspecialchars(buildUrl($envKey, $apiKey, $query, $totalPages - 1, ['per_page' => $perPage])) ?>" title="Letzte Seite">&raquo;</a>
                    <?php else: ?>
                        <span class="disabled nav-step">&rsaquo;</span>
                        <span class="disabled nav-edge">&raquo;</span>
                    <?php endif; ?>
                </div>

                <form method="get" class="page-jump-form" onsubmit="var i=this.querySelector('input[name=p]'); var v=parseInt(i.value,10); i.value = isNaN(v) ? 0 : Math.max(0, v - 1);">
                    <input type="hidden" name="env" value="<?= htmlspecialchars($envKey) ?>">
                    <input type="hidden" name="api" value="<?= htmlspecialchars($apiKey) ?>">
                    <?php if ($query !== ''): ?><input type="hidden" name="q" value="<?= htmlspecialchars($query) ?>"><?php endif; ?>
                    <?php if ($perPage !== $config['page_size']): ?><input type="hidden" name="per_page" value="<?= htmlspecialchars((string) $perPage) ?>"><?php endif; ?>
                    <span>Seite</span>
                    <input type="text" inputmode="numeric" name="p" value="<?= $page + 1 ?>" style="width:44px; text-align:center;">
                    <span>von <?= $totalPages ?></span>
                </form>
            <?php endif; ?>
        </div>

        <p class="meta">
            <?= $totalFiltered ?> Einträge<?= $query !== '' ? " (gefiltert aus $fetchedCount)" : '' ?>
            <?php if ($showPagination): ?>
                &middot; zeige <?= $page * $perPage + 1 ?>–<?= min($totalFiltered, ($page + 1) * $perPage) ?>
            <?php endif; ?>
        </p>

    <?php endif; ?>

<?php endif; ?>

<script>
document.querySelectorAll('tr.entry-row.expandable').forEach(function (row) {
    row.addEventListener('click', function () {
        var detail = row.nextElementSibling;
        if (!detail || !detail.classList.contains('detail-row')) return;
        var isOpen = detail.classList.toggle('open');
        var caret = row.querySelector('.caret');
        if (caret) caret.innerHTML = isOpen ? '&#9662;' : '&#9656;';
    });
});
</script>

</body>
</html>
