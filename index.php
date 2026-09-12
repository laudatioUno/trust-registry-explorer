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

/**
 * Baut eine URL zu dieser Seite mit den aktuellen Parametern, überschrieben
 * durch $overrides. Damit bleiben Umgebung/API/Suche beim Navigieren erhalten.
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

if ($apiKey !== null) {
    $apiCfg = $config['apis'][$apiKey];
    $baseUrl = $config['environments'][$envKey]['base_url'];

    try {
        $entries = getEntriesCached($envKey, $apiKey, $baseUrl, $apiCfg, $forceRefresh, $config['cache_ttl']);
        $fetchedCount = count($entries);
        $entries = filterEntries($entries, $query);
    } catch (Throwable $e) {
        $errorMessage = $e->getMessage();
    }
}

$pageSize = $config['page_size'];
$totalFiltered = count($entries);
$totalPages = max(1, (int) ceil($totalFiltered / $pageSize));
$page = min($page, $totalPages - 1);
$pageEntries = array_slice($entries, $page * $pageSize, $pageSize);

?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<title>Swiyu Trust Registry Explorer</title>
<style>
    body { font-family: Arial, sans-serif; margin: 2em; color: #222; background: #fafafa; }
    h1 { font-size: 1.3em; margin-bottom: 1em; }

    .tabs { display: flex; gap: 4px; border-bottom: 1px solid #ccc; margin-bottom: 16px; }
    .tabs a { padding: 8px 18px; font-size: 14px; text-decoration: none; color: #555; border-bottom: 3px solid transparent; }
    .tabs a.active { color: #0b5fa5; border-bottom-color: #0b5fa5; font-weight: bold; }

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

    table { border-collapse: collapse; width: 100%; background: #fff; }
    th, td { border: 1px solid #ddd; padding: 7px 10px; text-align: left; vertical-align: top; font-size: 0.88em; }
    th { background: #f2f2f2; }

    tr.entry-row.expandable { cursor: pointer; }
    tr.entry-row.expandable:hover { background: #f7fbff; }
    tr.entry-row .caret { display: inline-block; width: 14px; color: #0b5fa5; }
    tr.detail-row { display: none; background: #fbfcfe; }
    tr.detail-row td { padding: 14px 20px; }
    .detail-section { margin-bottom: 14px; }
    .detail-section h4 { margin: 0 0 6px; font-size: 13px; color: #0b5fa5; }
    table.detail-kv { width: 100%; border: none; background: transparent; }
    table.detail-kv th { background: transparent; border: none; width: 220px; font-weight: normal; color: #666; font-size: 12px; vertical-align: top; padding: 3px 8px 3px 0; }
    table.detail-kv td { border: none; padding: 3px 0; font-size: 12px; }
    .detail-raw { color: #999; font-size: 11px; }
    ul.detail-list { margin: 0; padding-left: 18px; font-size: 12px; }
    .detail-empty { color: #999; }

    .pagination { margin-top: 14px; display: flex; align-items: center; gap: 10px; font-size: 13px; color: #555; }
    .pagination a { text-decoration: none; color: #0b5fa5; }
    .pagination a.disabled { color: #bbb; pointer-events: none; }

    .error { background: #fdecea; border: 1px solid #f5c2c0; color: #a12622; padding: 12px; border-radius: 6px; }
    .meta { font-size: 12px; color: #888; margin-top: 8px; }
</style>
</head>
<body>

<h1>Swiyu Trust Registry Explorer</h1>

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
                <?php if ($totalFiltered > $pageSize || $fetchedCount > $pageSize): ?>
                    <input type="text" name="q" placeholder="Suchen..." value="<?= htmlspecialchars($query) ?>">
                    <button type="submit">Suchen</button>
                <?php endif; ?>
            </form>
            <a class="refresh-btn" href="<?= htmlspecialchars(buildUrl($envKey, $apiKey, $query, 0, ['refresh' => 1])) ?>">Abfragen</a>
        </div>
    </div>

    <?php if ($errorMessage !== null): ?>

        <div class="error">Fehler beim Abrufen/Dekodieren: <?= htmlspecialchars($errorMessage) ?></div>

    <?php else: ?>

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
                            <td>
                                <?php if ($isExpandable && $j === 0): ?>
                                    <span class="caret">&#9656;</span>
                                <?php endif; ?>
                                <?php if ($col['type'] === 'multilang'): ?>
                                    <?= formatMultilangCell($entry, $col['key']) ?>
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

        <div class="pagination">
            <?php if ($page > 0): ?>
                <a href="<?= htmlspecialchars(buildUrl($envKey, $apiKey, $query, $page - 1)) ?>">&laquo; Zurück</a>
            <?php else: ?>
                <span class="disabled">&laquo; Zurück</span>
            <?php endif; ?>

            <span>Seite <?= $page + 1 ?> von <?= $totalPages ?></span>

            <?php if ($page + 1 < $totalPages): ?>
                <a href="<?= htmlspecialchars(buildUrl($envKey, $apiKey, $query, $page + 1)) ?>">Weiter &raquo;</a>
            <?php else: ?>
                <span class="disabled">Weiter &raquo;</span>
            <?php endif; ?>
        </div>

        <p class="meta">
            <?= $totalFiltered ?> Einträge<?= $query !== '' ? " (gefiltert aus $fetchedCount)" : '' ?>
        </p>

    <?php endif; ?>

<?php endif; ?>

<script>
document.querySelectorAll('tr.entry-row.expandable').forEach(function (row) {
    row.addEventListener('click', function () {
        var detail = row.nextElementSibling;
        if (!detail || !detail.classList.contains('detail-row')) return;
        var isOpen = detail.style.display === 'table-row';
        detail.style.display = isOpen ? 'none' : 'table-row';
        var caret = row.querySelector('.caret');
        if (caret) caret.innerHTML = isOpen ? '&#9656;' : '&#9662;';
    });
});
</script>

</body>
</html>
