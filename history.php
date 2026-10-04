<?php
declare(strict_types=1);

require __DIR__ . '/i18n.php';
require __DIR__ . '/functions.php';
$config = require __DIR__ . '/config.php';

$dbPath       = $config['history']['db_path'] ?? __DIR__ . '/storage/history.sqlite';
$rangeOptions = $config['history']['range_options'] ?? ['7d' => t('range.7d')];

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
//
// Bewusst KEINE echte Chart.js-Zeitachse (die einen zusätzlichen Datums-
// Adapter + date-fns als weitere CDN-Abhängigkeit bräuchte, siehe PR-Notiz).
// Stattdessen: eine gemeinsame, sortierte Liste aller vorkommenden
// Zeitstempel (Vereinigung über alle gewählten Serien) als Kategorie-Achse
// mit vorformatierten Labels — für tägliche Snapshots völlig ausreichend.
// Jede Serie wird an diese gemeinsame Liste ausgerichtet; ein Zeitpunkt, an
// dem eine Serie keinen (oder einen Fehler-)Eintrag hat, wird null (Lücke).

$chartSeries = [];
$labels = [];
$dbError = null;

try {
    $pdo = historyDbConnect($dbPath);
    historyEnsureSchema($pdo);

    $rawSeries = [];
    $allTs = [];
    foreach ($selectedSeries as $s) {
        $rows = historyFetchSeries($pdo, $s['env'], $s['api'], $fromTs, $toTs);
        $rawSeries[] = ['s' => $s, 'rows' => $rows];
        foreach ($rows as $row) {
            $allTs[(int) $row['ts_utc']] = true;
        }
    }

    $allTs = array_keys($allTs);
    sort($allTs);

    $tz = new DateTimeZone('Europe/Zurich');
    $labels = array_map(
        static fn (int $ts) => (new DateTimeImmutable('@' . $ts))->setTimezone($tz)->format('d.m.Y'),
        $allTs
    );

    foreach ($rawSeries as $entry) {
        $byTs = [];
        $errorCount = 0;
        foreach ($entry['rows'] as $row) {
            $byTs[(int) $row['ts_utc']] = $row['status'] === 'ok' ? (int) $row['count'] : null;
            if ($row['status'] !== 'ok') {
                $errorCount++;
            }
        }

        $data = [];
        foreach ($allTs as $ts) {
            $data[] = $byTs[$ts] ?? null; // fehlender Zeitpunkt für diese Serie -> auch eine Lücke
        }

        $s = $entry['s'];
        $chartSeries[] = [
            'label'      => $config['environments'][$s['env']]['label'] . ' · ' . $config['apis'][$s['api']]['label'],
            'data'       => $data,
            'pointCount' => count($entry['rows']),
            'errorCount' => $errorCount,
        ];
    }
} catch (Throwable $e) {
    $dbError = $e->getMessage();
}

?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars(currentLang()) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Swiyu Trust Registry Explorer – History</title>
<link rel="icon" href="assets/favicon.svg" type="image/svg+xml">
<link rel="alternate icon" href="assets/favicon.ico">
<link rel="apple-touch-icon" href="assets/apple-touch-icon.png">
<link rel="stylesheet" href="assets/theme.css">
<script src="assets/theme.js"></script>
<script src="assets/chart.umd.min.js"></script>
<style>
    body { font-family: Arial, sans-serif; margin: 2em; color: var(--text); background: var(--bg); }
    h1 { font-size: 1.3em; margin-bottom: 1em; }

    .page-header { display: flex; justify-content: space-between; align-items: center; gap: 12px; margin-bottom: 1em; flex-wrap: wrap; }
    .page-header h1 { margin: 0; }
    .page-header .brand { display: flex; align-items: center; gap: 10px; }
    .top-nav { display: flex; gap: 6px; align-items: stretch; }
    .top-nav a { display: inline-flex; align-items: center; justify-content: center; line-height: 1; padding: 6px 16px; font-size: 13px; border: 1px solid var(--border); border-radius: 20px; text-decoration: none; color: var(--text-2); background: var(--surface); box-sizing: border-box; }
    .top-nav a.active { background: var(--accent-fill); border-color: var(--accent-fill); color: var(--on-accent); font-weight: bold; }
    .top-nav a.docs-link { color: var(--accent); border-color: var(--accent-soft); }
    .top-nav a.docs-link:hover { background: var(--accent-soft-2); }

    .history-controls { background: var(--surface); border: 1px solid var(--border); border-radius: 10px; padding: 16px; margin-bottom: 20px; }
    .history-toolbar { display: flex; gap: 18px; align-items: flex-end; flex-wrap: wrap; margin-bottom: 16px; }
    .history-toolbar label { display: flex; flex-direction: column; gap: 4px; font-size: 12px; color: var(--text-2); }
    .history-toolbar select, .history-toolbar input[type=date] { padding: 6px 8px; font-size: 13px; border: 1px solid var(--border); border-radius: 4px; background: var(--surface); color: var(--text); }
    .history-toolbar button { padding: 7px 14px; font-size: 13px; border: 1px solid var(--accent-fill); background: var(--accent-fill); color: var(--on-accent); border-radius: 4px; cursor: pointer; }

    .series-matrix-wrap { overflow-x: auto; }
    table.series-matrix { border-collapse: collapse; }
    table.series-matrix th, table.series-matrix td { border: 1px solid var(--border-soft); padding: 6px 10px; text-align: center; font-size: 12px; }
    table.series-matrix thead th { background: var(--table-head); white-space: nowrap; }
    table.series-matrix tbody th { background: var(--table-head-2); text-align: left; white-space: nowrap; }
    table.series-matrix input[type=checkbox] { width: 16px; height: 16px; cursor: pointer; }

    .series-quick-actions { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 12px; align-items: center; }
    .quick-btn { padding: 5px 12px; font-size: 12px; border: 1px solid var(--border); background: var(--surface); color: var(--text-2); border-radius: 14px; cursor: pointer; }
    .quick-btn:hover { border-color: var(--accent); color: var(--accent); }
    .quick-btn-clear { color: var(--error-text); border-color: var(--error-border); }
    .apply-btn { margin-left: auto; padding: 7px 18px; font-size: 13px; border: 1px solid var(--accent-fill); background: var(--accent-fill); color: var(--on-accent); border-radius: 4px; cursor: pointer; }

    .chart-wrap { background: var(--surface); border: 1px solid var(--border); border-radius: 10px; padding: 18px; }
    .meta { font-size: 12px; color: var(--text-3); margin-top: 8px; }
    .error { background: var(--error-bg); border: 1px solid var(--error-border); color: var(--error-text); padding: 12px; border-radius: 6px; }
    .series-error-note { font-size: 11px; color: var(--warning-text); margin-top: 6px; }

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
    <div class="brand">
        <svg class="tre-logo" width="28" height="28" viewBox="0 0 100 100" aria-hidden="true">
            <rect class="tre-bar" x="12" y="14" width="76" height="16" rx="6"></rect>
            <rect class="tre-bar" x="12" y="40" width="76" height="16" rx="6"></rect>
            <rect class="tre-bar" x="12" y="66" width="48" height="16" rx="6"></rect>
            <circle class="tre-seal" cx="76" cy="74" r="20"></circle>
            <path class="tre-check" d="M67 74 L74 81 L87 66" fill="none" stroke-width="7" stroke-linecap="round" stroke-linejoin="round"></path>
        </svg>
        <h1>Swiyu Trust Registry Explorer</h1>
    </div>
    <div class="top-nav">
        <a href="index.php">Explorer</a>
        <a href="history.php" class="active">History</a>
        <a href="<?= htmlspecialchars($config['docs']['overview_url']) ?>" class="docs-link" target="_blank" rel="noopener">📖 Docs</a>
        <div class="lang-switch" role="group" aria-label="<?= htmlspecialchars(t('nav.lang_toggle_aria')) ?>">
            <a href="<?= htmlspecialchars(langSwitchUrl('de')) ?>" class="lang-btn<?= currentLang() === 'de' ? ' active' : '' ?>">DE</a>
            <a href="<?= htmlspecialchars(langSwitchUrl('en')) ?>" class="lang-btn<?= currentLang() === 'en' ? ' active' : '' ?>">EN</a>
            <a href="<?= htmlspecialchars(langSwitchUrl('fr')) ?>" class="lang-btn<?= currentLang() === 'fr' ? ' active' : '' ?>">FR</a>
        </div>
        <button type="button" class="theme-toggle" id="themeToggle" aria-label="<?= htmlspecialchars(t('nav.theme_toggle_aria')) ?>">🌙</button>
    </div>
</div>

<form method="get" class="history-controls" id="historyForm">
    <input type="hidden" name="series" id="seriesInput" value="<?= htmlspecialchars(implode(',', array_map(static fn ($s) => $s['env'] . ':' . $s['api'], $selectedSeries))) ?>">

    <div class="history-toolbar">
        <label><?= htmlspecialchars(t('history.range_label')) ?>
            <select name="range" onchange="this.form.requestSubmit ? this.form.requestSubmit() : this.form.submit()">
                <?php foreach ($rangeOptions as $key => $label): ?>
                    <option value="<?= htmlspecialchars($key) ?>" <?= $key === $rangeKey ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                <?php endforeach; ?>
            </select>
        </label>

        <?php if ($rangeKey === 'custom'): ?>
            <label><?= htmlspecialchars(t('history.from')) ?>
                <input type="date" name="from" value="<?= htmlspecialchars($customFrom) ?>">
            </label>
            <label><?= htmlspecialchars(t('history.to')) ?>
                <input type="date" name="to" value="<?= htmlspecialchars($customTo) ?>">
            </label>
        <?php endif; ?>

        <label><?= htmlspecialchars(t('history.scale_label')) ?>
            <select name="scale" onchange="this.form.requestSubmit ? this.form.requestSubmit() : this.form.submit()">
                <option value="log" <?= $scale === 'log' ? 'selected' : '' ?>><?= htmlspecialchars(t('history.scale_log')) ?></option>
                <option value="linear" <?= $scale === 'linear' ? 'selected' : '' ?>><?= htmlspecialchars(t('history.scale_linear')) ?></option>
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
            <span style="font-size:11px; color:var(--text-3);"><?= htmlspecialchars(t('history.select_label')) ?></span>
            <?php foreach ($config['environments'] as $envKey => $env): ?>
                <button type="button" class="quick-btn" onclick="toggleRow('<?= htmlspecialchars($envKey) ?>')"><?= htmlspecialchars(t('history.all_x', [$env['label']])) ?></button>
            <?php endforeach; ?>
            <?php foreach ($config['apis'] as $apiKey => $api): ?>
                <button type="button" class="quick-btn" onclick="toggleCol('<?= htmlspecialchars($apiKey) ?>')"><?= htmlspecialchars(t('history.all_x', [$api['label']])) ?></button>
            <?php endforeach; ?>
            <button type="button" class="quick-btn quick-btn-clear" onclick="toggleAll(false)"><?= htmlspecialchars(t('history.deselect_all')) ?></button>
            <button type="submit" class="apply-btn"><?= htmlspecialchars(t('history.apply')) ?></button>
        </div>
    </div>
</form>

<?php /* Lade-Overlay liegt IMMER im DOM (auch vor der ersten Auswahl/dem
         ersten "Anzeigen"-Klick), sonst findet das allererste Submit noch
         kein Element zum Anzeigen - siehe TrustExplorer.attachLoadingOverlay.
         Oben an den Ergebnisbereich angepinnt, nicht mittig über dem ganzen
         Chart. */ ?>
<div class="tre-loading-host">
<div class="tre-loading-overlay" id="chartLoadingOverlay">
    <svg class="tre-spinner" width="22" height="22" viewBox="0 0 100 100" aria-hidden="true">
        <rect class="tre-bar tre-bar-1" x="12" y="14" width="76" height="16" rx="6"></rect>
        <rect class="tre-bar tre-bar-2" x="12" y="40" width="76" height="16" rx="6"></rect>
        <rect class="tre-bar tre-bar-3" x="12" y="66" width="48" height="16" rx="6"></rect>
        <g class="tre-seal-group">
            <circle class="tre-seal" cx="76" cy="74" r="20"></circle>
            <path class="tre-check" d="M67 74 L74 81 L87 66" fill="none" stroke-width="7" stroke-linecap="round" stroke-linejoin="round"></path>
        </g>
    </svg>
    <span><?= htmlspecialchars(t('common.loading')) ?></span>
</div>

<?php if ($dbError !== null): ?>

    <div class="error"><?= htmlspecialchars(t('history.db_error', [$dbError])) ?></div>

<?php elseif ($selectedSeries === []): ?>

    <p style="color:var(--text-3);"><?= htmlspecialchars(t('history.select_prompt')) ?></p>

<?php else: ?>

    <div class="chart-wrap">
        <canvas id="historyChart" height="90"></canvas>
    </div>
    <p class="meta">
        <?= htmlspecialchars(t('history.meta_range', [$rangeOptions[$rangeKey]])) ?>
        &middot; <?= htmlspecialchars(t('history.meta_curves', [count($chartSeries)])) ?>
        &middot; <?= htmlspecialchars(t('history.meta_points', [array_sum(array_map(static fn ($s) => $s['pointCount'], $chartSeries))])) ?>
    </p>
    <?php $totalErrors = array_sum(array_map(static fn ($s) => $s['errorCount'], $chartSeries)); ?>
    <?php if ($totalErrors > 0): ?>
        <p class="series-error-note"><?= htmlspecialchars(t('history.collector_errors', [$totalErrors])) ?></p>
    <?php endif; ?>

<?php endif; ?>
</div>

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

// Lade-Spinner über dem Chart: Zeitraum/Skala wechseln (Auto-Submit per
// requestSubmit() der <select>s unten) und "Anzeigen" lösen beides ein
// echtes Submit auf #historyForm aus, worauf wir einfach lauschen.
TrustExplorer.attachLoadingOverlay('chartLoadingOverlay', ['#historyForm']);

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
var chartLabels = <?= json_encode($labels, JSON_THROW_ON_ERROR) ?>;
var chartSeries = <?= json_encode($chartSeries, JSON_THROW_ON_ERROR) ?>;

// Farben kommen bewusst NICHT als feste Hex-Werte, sondern werden aus den
// CSS-Tokens (assets/theme.css) gelesen -- so zieht das Chart beim
// Umschalten von Light/Dark (assets/theme.js) automatisch mit, ohne dass
// hier eine zweite Palette gepflegt werden müsste.
function readChartColors() {
    var cs = getComputedStyle(document.documentElement);
    var paletteRaw = cs.getPropertyValue('--chart-palette') || '';
    return {
        palette: paletteRaw.split(',').map(function (c) { return c.trim(); }).filter(Boolean),
        text: cs.getPropertyValue('--text-2').trim(),
        grid: cs.getPropertyValue('--border-soft').trim(),
    };
}

var chartColors = readChartColors();

var datasets = chartSeries.map(function (s, i) {
    var color = chartColors.palette[i % chartColors.palette.length];
    return {
        label: s.label,
        data: s.data, // ausgerichtet auf chartLabels, null = Lücke
        borderColor: color,
        backgroundColor: color,
        spanGaps: false, // fehlgeschlagene Collector-Läufe zeigen eine Lücke statt eines Sprungs auf 0
        tension: 0.15,
        pointRadius: 2,
        fill: false,
    };
});

var historyChart = new Chart(document.getElementById('historyChart'), {
    type: 'line',
    data: { labels: chartLabels, datasets: datasets },
    options: {
        responsive: true,
        interaction: { mode: 'nearest', axis: 'x', intersect: false },
        scales: {
            x: {
                title: { display: true, text: <?= json_encode(t('chart.axis_time'), JSON_THROW_ON_ERROR) ?>, color: chartColors.text },
                ticks: { autoSkip: true, maxRotation: 60, minRotation: 0, color: chartColors.text },
                grid: { color: chartColors.grid },
            },
            y: {
                type: '<?= $scale === 'log' ? 'logarithmic' : 'linear' ?>',
                title: { display: true, text: <?= json_encode(t('chart.axis_count'), JSON_THROW_ON_ERROR) ?>, color: chartColors.text },
                beginAtZero: <?= $scale === 'linear' ? 'true' : 'false' ?>,
                ticks: { color: chartColors.text },
                grid: { color: chartColors.grid },
            },
        },
        plugins: {
            legend: { position: 'bottom', labels: { color: chartColors.text } },
        },
    },
});

// Beim Umschalten des Themes (Klick auf den Toggle oder Live-Wechsel der
// Systemeinstellung, siehe assets/theme.js) Kurven- und Achsenfarben neu
// aus den (jetzt aktualisierten) CSS-Tokens lesen und ohne Reload neu zeichnen.
document.addEventListener('trustexplorer:themechange', function () {
    var colors = readChartColors();
    historyChart.data.datasets.forEach(function (ds, i) {
        var color = colors.palette[i % colors.palette.length];
        ds.borderColor = color;
        ds.backgroundColor = color;
    });
    historyChart.options.scales.x.title.color = colors.text;
    historyChart.options.scales.x.ticks.color = colors.text;
    historyChart.options.scales.x.grid.color = colors.grid;
    historyChart.options.scales.y.title.color = colors.text;
    historyChart.options.scales.y.ticks.color = colors.text;
    historyChart.options.scales.y.grid.color = colors.grid;
    historyChart.options.plugins.legend.labels.color = colors.text;
    historyChart.update();
});
<?php endif; ?>
</script>

</body>
</html>
