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
 *   0 23 * * * php /pfad/zum/projekt/collect.php
 *
 * Das Skript schreibt sein Log SELBST nach storage/collect.log (via __DIR__,
 * also unabhängig vom Arbeitsverzeichnis des Cron-/Scheduler-Aufrufs — bei
 * vielen Hosting-Panels ist das nicht das Projektverzeichnis, wodurch eine
 * Shell-Umleitung ">> storage/collect.log" mit relativem Pfad ins Leere
 * läuft). Eine zusätzliche Umleitung (">> ... 2>&1") schadet nicht, ist aber
 * nicht mehr nötig.
 *
 * Nutzt bewusst KEINEN Session-Cache (CLI hat ohnehin keine Session) — jeder
 * Lauf fragt alle APIs frisch ab.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "collect.php darf nur über die Kommandozeile (CLI) ausgeführt werden, nicht über den Webserver.\n";
    exit(1);
}

// config.php ruft t() auf (übersetzte Spaltentitel/Zeitraum-Presets) -- auf
// der CLI gibt es kein Accept-Language/Cookie, i18n.php fällt dann einfach
// auf I18N_DEFAULT zurück. Für den Collector selbst irrelevant (er nutzt nur
// Keys/base_url/path), aber config.php muss trotzdem fehlerfrei laden.
require __DIR__ . '/i18n.php';
require __DIR__ . '/functions.php';
$config = require __DIR__ . '/config.php';

$dbPath  = $config['history']['db_path'] ?? __DIR__ . '/storage/history.sqlite';
$logPath = __DIR__ . '/storage/collect.log';

/**
 * Schreibt eine Zeile gleichzeitig auf STDOUT (sichtbar im Scheduler-Log
 * des Hosting-Panels) und in storage/collect.log (verlässlich, weil über
 * __DIR__ aufgelöst statt über eine Shell-Umleitung mit relativem Pfad).
 */
function logLine(string $logPath, string $line): void
{
    $stamped = '[' . date('Y-m-d H:i:s') . '] ' . $line;
    echo $stamped . "\n";

    $dir = dirname($logPath);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    @file_put_contents($logPath, $stamped . "\n", FILE_APPEND | LOCK_EX);
}

try {
    $pdo = historyDbConnect($dbPath);
    historyEnsureSchema($pdo);
} catch (Throwable $e) {
    logLine($logPath, 'FATAL: Konnte History-Datenbank nicht öffnen/initialisieren: ' . $e->getMessage());
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
            logLine($logPath, "[$envKey/$apiKey] OK: $count");
        } catch (Throwable $e) {
            $errors++;
            historyInsertSnapshot($pdo, $runTs, $envKey, $apiKey, null, 'error', $e->getMessage());
            logLine($logPath, "[$envKey/$apiKey] ERROR: {$e->getMessage()}");
        }
    }
}

logLine($logPath, "Fertig: $total Kombinationen verarbeitet, $errors Fehler.");
exit($errors > 0 ? 1 : 0);
