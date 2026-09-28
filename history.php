<?php
declare(strict_types=1);

require __DIR__ . '/functions.php';
$config = require __DIR__ . '/config.php';

$dbPath       = $config['history']['db_path'] ?? __DIR__ . '/storage/history.sqlite';
$rangeOptions = $config['history']['range_options'] ?? ['7d' => 'Letzte 7 Tage'];

// ---- Parameter aus der URL lesen ----

$rangeKey = $_GET['range'] ?? '7d';
if (!isset($rangeOptions[$rangeKey])) {
    $rangeKey = '7d';
}

$customFrom = trim((string) ($_GET['from'] ?? ''));
$customTo   = trim((string) ($_GET['to'] ?? ''));

$scale = ($_GET['scale'] ?? 'log') === 'linear' ? 'linear' : 'log';

// series=ENV:API,ENV:API,... — nur bekannte Umgebung/API-Kombinationen übernehmen.
$seriesParam = $_GET['series'] ?? null;
$selectedSeries = [];
if ($seriesParam !== null && $seriesParam !== '') {
    foreach (explode(',', $seriesParam) as $pair) {
        [$e, $a] = array_pad(explode(':', $pair, 2), 2, null);
        if ($e !== null && $a !== null && isset($config['environments'][$e]) && isset($config['apis'][$a])) {
            $selectedSeries[] = ['env' => $e, 'api' => $a];
        }
    }
} elseif ($seriesParam === null) {
    // Erster Aufruf ohne jegliche Auswahl: sinnvoller Default statt leerer Ansicht.
    $firstEnv = array_key_first($config['environments']);
    if ($firstEnv !== null && isset($config['apis']['idTS'])) {
        $selectedSeries[] = ['env' => $firstEnv, 'api' => 'idTS'];
    }
}

['from' => $fromTs, 'to' => $toTs] = historyResolveRange($rangeKey, $customFrom, $customTo);

/**
 * Baut eine URL zu dieser Seite mit den aktuellen Parametern, überschrieben
 * durch $overrides (analog zu buildUrl() in index.php).
 */
function buildHistoryUrl(string $rangeKey, string $scale, array $selectedSeries, string $customFrom, string $customTo, array $overrides = []): string
{
    $params = array_merge([
        'range'  => $rangeKey,
        'scale'  => $scale,
        'series' => implode(',', array_map(static fn ($s) => $s['env'] . ':' . $s['api'], $selectedSeries)),
        'from'   => $rangeKey === 'custom' ? $customFrom : null,
        'to'     => $rangeKey === 'custom' ? $customTo : null,
    ], $overrides);

    $params = array_filter($params, static fn ($v) => $v !== null && $v !== '');
    return '?' . http_build_query($params);
}

$isSelected = static function (string $env, string $api) use ($selectedSeries): bool {
    foreach ($selectedSeries as $s) {
        if ($s['env'] === $env && $s['api'] === $api) {
            return true;
        }
    }
    return false;
};

// ---- Daten laden ----

$chartSeries = [];
$dbError = null;

try {
    $pdo = historyDbConnect($dbPath);
    historyEnsureSchema($pdo);

    foreach ($selectedSeries as $s) {
        $rows = historyFetchSeries($pdo, $s['env'], $s['api'], $fromTs, $toTs);

        $points = [];
        $errorCount = 0;
        foreach ($rows as $row) {
            $points[] = [
                'x' => ((int) $row['ts_utc']) * 1000, // Chart.js erwartet Millisekunden
                'y' => $row['status'] === 'ok' ? (int) $row['count'] : null, // null -> Lücke (spanGaps:false)
            ];
            if ($row['status'] !== 'ok') {
                $errorCount++;
            }
        }

        $chartSeries[] = [
            'label'      => $config['environments'][$s['env']]['label'] . ' · ' . $config['apis'][$s['api']]['label'],
            'data'       => $points,
            'pointCount' => count($rows),
            'errorCount' => $errorCount,
        ];
    }
} catch (Throwable $e) {
    $dbError = $e->getMessage();
}

?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Swiyu Trust Registry Explorer – History</title>
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.4/chart.umd.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/chartjs-adapter-date-fns/3.0.0/chartjs-adapter-date-fns.bundle.min.js"></script>
<style>
    body { font-family: Arial, sans-serif; margin: 2em; color: #222; background: #fafafa; }
    h1 { font-size: 1.3em; margin-bottom: 1em; }

    .page-header { display: flex; justify-content: space-between; align-items: center; gap: 12px; margin-bottom: 1em; flex-wrap: wrap; }
    .page-header h1 { margin: 0; }
    .top-nav { display: flex; gap: 6px; }
    .top-nav a { padding: 6px 16px; font-size: 13px; border: 1px solid #ccc; border-radius: 20px; text-decoration: none; color: #444; background: #fff; }
    .top-nav a.active { background: #0b5fa5; border-color: #0b5fa5; color: #fff; font-weight: bold; }

    .history-controls { background: #fff; border: 1px solid #ddd; border-radius: 10px; padding: 16px; margin-bottom: 20px; }
    .history-toolbar { display: flex; gap: 18px; align-items: flex-end; flex-wrap: wrap; margin-bottom: 16px; }
    .history-toolbar label { display: flex; flex-direction: column; gap: 4px; font-size: 12px; color: #666; }
    .history-toolbar select, .history-toolbar input[type=date] { padding: 6px 8px; font-size: 13px; border: 1px solid #ccc; border-radius: 4px; }
    .history-toolbar button { padding: 7px 14px; font-size: 13px; border: 1px solid #0b5fa5; background: #0b5fa5; color: #fff; border-radius: 4px; cursor: pointer; }

    .series-matrix-wrap { overflow-x: auto; }
    table.series-matrix { border-collapse: collapse; }
    table.series-matrix th, table.series-matrix td { border: 1px solid #eee; padding: 6px 10px; text-align: center; font-size: 12px; }
    table.series-matrix thead th { background: #f2f2f2; white-space: nowrap; }
    table.series-matrix tbody th { background: #f7f9fb; text-align: left; white-space: nowrap; }
    table.series-matrix input[type=checkbox] { width: 16px; height: 16px; cursor: pointer; }

    .series-quick-actions { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 12px; align-items: center; }
    .quick-btn { padding: 5px 12px; font-size: 12px; border: 1px solid #ccc; background: #fff; color: #444; border-radius: 14px; cursor: pointer; }
    .quick-btn:hover { border-color: #0b5fa5; color: #0b5fa5; }
    .quick-btn-clear { color: #a12622; border-color: #f0c4c2; }
    .apply-btn { margin-left: auto; padding: 7px 18px; font-size: 13px; border: 1px solid #0b5fa5; background: #0b5fa5; color: #fff; border-radius: 4px; cursor: pointer; }

    .chart-wrap { background: #fff; border: 1px solid #ddd; border-radius: 10px; padding: 18px; }
    .meta { font-size: 12px; color: #888; margin-top: 8px; }
    .error { background: #fdecea; border: 1px solid #f5c2c0; color: #a12622; padding: 12px; border-radius: 6px; }
    .series-error-note { font-size: 11px; color: #a1651f; margin-top: 6px; }

    @media (max-width: 640px) {
        body { margin: 0.75em; }
        .page-header { flex-direction: column; align-items: stretch; gap: 8px; }
        .top-nav { justify-content: center; }
        .top-nav a { flex: 1; text-align: center; }
        .history-toolbar { flex-direction: column; align-items: stretch; }
        .apply-btn { margin-left: 0; }
    }
</style>
</head>
<body>

<div class="page-header">
    <h1>Swiyu Trust Registry Explorer</h1>
    <div class="top-nav">
        <a href="index.php">Explorer</a>
        <a href="history.php" class="active">History</a>
    </div>
</div>

<form method="get" class="history-controls" id="historyForm">
    <input type="hidden" name="series" id="seriesInput" value="<?= htmlspecialchars(implode(',', array_map(static fn ($s) => $s['env'] . ':' . $s['api'], $selectedSeries))) ?>">

    <div class="history-toolbar">
        <label>Zeitraum
            <select name="range" onchange="this.form.requestSubmit ? this.form.requestSubmit() : this.form.submit()">
                <?php foreach ($rangeOptions as $key => $label): ?>
                    <option value="<?= htmlspecialchars($key) ?>" <?= $key === $rangeKey ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                <?php endforeach; ?>
            </select>
        </label>

        <?php if ($rangeKey === 'custom'): ?>
            <label>Von
                <input type="date" name="from" value="<?= htmlspecialchars($customFrom) ?>">
            </label>
            <label>Bis
                <input type="date" name="to" value="<?= htmlspecialchars($customTo) ?>">
            </label>
        <?php endif; ?>

        <label>Skala
            <select name="scale" onchange="this.form.requestSubmit ? this.form.requestSubmit() : this.form.submit()">
                <option value="log" <?= $scale === 'log' ? 'selected' : '' ?>>Logarithmisch</option>
                <option value="linear" <?= $scale === 'linear' ? 'selected' : '' ?>>Linear</option>
            </select>
        </label>
    </div>

    <div class="series-matrix-wrap">
        <table class="series-matrix">
            <thead>
                <tr>
                    <th></th>
                    <?php foreach ($config['apis'] as $apiKey => $api): ?>
                        <th><?= htmlspecialchars($api['label']) ?></th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($config['environments'] as $envKey => $env): ?>
                    <tr>
                        <th><?= htmlspecialchars($env['label']) ?></th>
                        <?php foreach ($config['apis'] as $apiKey => $api): ?>
                            <td>
                                <input type="checkbox" class="series-checkbox"
                                       data-env="<?= htmlspecialchars($envKey) ?>"
                                       data-api="<?= htmlspecialchars($apiKey) ?>"
                                       <?= $isSelected($envKey, $apiKey) ? 'checked' : '' ?>>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div class="series-quick-actions">
            <span style="font-size:11px; color:#999;">Auswählen:</span>
            <?php foreach ($config['environments'] as $envKey => $env): ?>
                <button type="button" class="quick-btn" onclick="toggleRow('<?= htmlspecialchars($envKey) ?>')">Alle <?= htmlspecialchars($env['label']) ?></button>
            <?php endforeach; ?>
            <?php foreach ($config['apis'] as $apiKey => $api): ?>
                <button type="button" class="quick-btn" onclick="toggleCol('<?= htmlspecialchars($apiKey) ?>')">Alle <?= htmlspecialchars($api['label']) ?></button>
            <?php endforeach; ?>
            <button type="button" class="quick-btn quick-btn-clear" onclick="toggleAll(false)">Alle abwählen</button>
            <button type="submit" class="apply-btn">Anzeigen</button>
        </div>
    </div>
</form>

<?php if ($dbError !== null): ?>

    <div class="error">Fehler beim Zugriff auf die History-Datenbank: <?= htmlspecialchars($dbError) ?></div>

<?php elseif ($selectedSeries === []): ?>

    <p style="color:#888;">Bitte oben mindestens eine Kombination aus Umgebung und API auswählen und auf "Anzeigen" klicken.</p>

<?php else: ?>

    <div class="chart-wrap">
        <canvas id="historyChart" height="90"></canvas>
    </div>
    <p class="meta">
        Zeitraum: <?= htmlspecialchars($rangeOptions[$rangeKey]) ?>
        &middot; <?= count($chartSeries) ?> Kurve(n)
        &middot; <?= array_sum(array_map(static fn ($s) => $s['pointCount'], $chartSeries)) ?> Datenpunkte insgesamt
    </p>
    <?php $totalErrors = array_sum(array_map(static fn ($s) => $s['errorCount'], $chartSeries)); ?>
    <?php if ($totalErrors > 0): ?>
        <p class="series-error-note"><?= $totalErrors ?> fehlgeschlagene(r) Collector-Lauf/Läufe im gewählten Zeitraum (im Chart als Lücke sichtbar).</p>
    <?php endif; ?>

<?php endif; ?>

<script>
var form = document.getElementById('historyForm');
var seriesInput = document.getElementById('seriesInput');

function syncSeriesInput() {
    var checked = document.querySelectorAll('.series-checkbox:checked');
    var parts = [];
    checked.forEach(function (cb) {
        parts.push(cb.dataset.env + ':' + cb.dataset.api);
    });
    seriesInput.value = parts.join(',');
}

form.addEventListener('submit', syncSeriesInput);

function toggleRow(env) {
    document.querySelectorAll('.series-checkbox[data-env="' + env + '"]').forEach(function (cb) { cb.checked = true; });
}
function toggleCol(api) {
    document.querySelectorAll('.series-checkbox[data-api="' + api + '"]').forEach(function (cb) { cb.checked = true; });
}
function toggleAll(state) {
    document.querySelectorAll('.series-checkbox').forEach(function (cb) { cb.checked = state; });
}

<?php if ($selectedSeries !== [] && $dbError === null): ?>
var chartSeries = <?= json_encode($chartSeries, JSON_THROW_ON_ERROR) ?>;
var palette = ['#0b5fa5', '#1e7d34', '#a1651f', '#a12622', '#7a4fb5', '#0f9aa8', '#c2185b', '#5d6d7e', '#e08e0b', '#2e8b8b'];

var datasets = chartSeries.map(function (s, i) {
    return {
        label: s.label,
        data: s.data,
        borderColor: palette[i % palette.length],
        backgroundColor: palette[i % palette.length],
        spanGaps: false, // fehlgeschlagene Collector-Läufe zeigen eine Lücke statt eines Sprungs auf 0
        tension: 0.15,
        pointRadius: 2,
        fill: false,
    };
});

new Chart(document.getElementById('historyChart'), {
    type: 'line',
    data: { datasets: datasets },
    options: {
        responsive: true,
        interaction: { mode: 'nearest', axis: 'x', intersect: false },
        scales: {
            x: {
                type: 'time',
                time: { unit: 'day' },
                title: { display: true, text: 'Zeit' },
            },
            y: {
                type: '<?= $scale === 'log' ? 'logarithmic' : 'linear' ?>',
                title: { display: true, text: 'Anzahl Trust Statements' },
                beginAtZero: <?= $scale === 'linear' ? 'true' : 'false' ?>,
            },
        },
        plugins: {
            legend: { position: 'bottom' },
        },
    },
});
<?php endif; ?>
</script>

</body>
</html>
