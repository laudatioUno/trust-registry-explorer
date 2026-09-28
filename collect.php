#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * History-Collector für den swiyu Trust Registry Explorer.
 *
 * Läuft per Cronjob (typischerweise 1x pro Nacht, z.B. 23:00 Europe/Zurich)
 * und ermittelt für JEDE konfigurierte Umgebung x JEDE konfigurierte API
 * die aktuelle Gesamtanzahl an Trust Statements (siehe fetchEntryCount() in
 * functions.php) — bewusst UNABHÄNGIG von jeglichem Filter (alle Statements,
 * aktiv + inaktiv), damit die History eine konsistente, filterunabhängige
 * Zeitreihe ergibt.
 *
 * Jede Kombination wird als eigene Zeile in die SQLite-Tabelle 'snapshots'
 * geschrieben (siehe historyInsertSnapshot() in functions.php). Schlägt eine
 * Abfrage fehl, wird explizit eine Fehler-Zeile gespeichert (status='error',
 * count=null) statt einer 0 — damit der Chart später eine Lücke zeigt statt
 * eines irreführenden Einbruchs auf 0.
 *
 * Aufruf (Beispiel-Crontab, täglich um 23:00 Europe/Zurich):
 *   0 23 * * * php /pfad/zum/projekt/collect.php >> /pfad/zum/projekt/storage/collect.log 2>&1
 *
 * Nutzt bewusst KEINEN Session-Cache (CLI hat ohnehin keine Session) — jeder
 * Lauf fragt alle APIs frisch ab.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "collect.php darf nur über die Kommandozeile (CLI) ausgeführt werden, nicht über den Webserver.\n";
    exit(1);
}

require __DIR__ . '/functions.php';
$config = require __DIR__ . '/config.php';

$dbPath = $config['history']['db_path'] ?? __DIR__ . '/storage/history.sqlite';

try {
    $pdo = historyDbConnect($dbPath);
    historyEnsureSchema($pdo);
} catch (Throwable $e) {
    fwrite(STDERR, 'Konnte History-Datenbank nicht öffnen/initialisieren: ' . $e->getMessage() . "\n");
    exit(1);
}

// Ein gemeinsamer Zeitstempel (UTC) für den gesamten Lauf, damit alle
// Umgebungen/APIs dieser Nacht denselben Zeitpunkt tragen und im Chart
// sauber als ein Datenpunkt pro Kurve erscheinen.
$runTs = time();

$total = 0;
$errors = 0;

foreach ($config['environments'] as $envKey => $envCfg) {
    foreach ($config['apis'] as $apiKey => $apiCfg) {
        $total++;

        try {
            $count = fetchEntryCount($envCfg['base_url'], $apiCfg);
            historyInsertSnapshot($pdo, $runTs, $envKey, $apiKey, $count, 'ok', null);
            echo "[$envKey/$apiKey] OK: $count\n";
        } catch (Throwable $e) {
            $errors++;
            historyInsertSnapshot($pdo, $runTs, $envKey, $apiKey, null, 'error', $e->getMessage());
            echo "[$envKey/$apiKey] ERROR: {$e->getMessage()}\n";
        }
    }
}

echo "Fertig: $total Kombinationen verarbeitet, $errors Fehler.\n";
exit($errors > 0 ? 1 : 0);
