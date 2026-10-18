<?php
declare(strict_types=1);

/**
 * Kleiner JSON-Endpoint für UI-Extras (Bestenliste), vom Browser per fetch() genutzt.
 *
 *   GET  ui_extras.php?a=top    -> {"ok":true,"top":[{"name","moves","secs"},...]}
 *   GET  ui_extras.php?a=start  -> {"ok":true,"token":"..."}   (Server-Startzeit, signiert)
 *   POST ui_extras.php  a=save, token, name, moves, secs
 *                               -> {"ok":true,"rank":N,"top":[...]}
 *
 * Daten liegen in der History-SQLite (config['history']['db_path']). Der Browser
 * meldet sein Ergebnis selbst, ein Fälschen lässt sich daher nie ganz verhindern;
 * der Server lehnt nur Unmögliches ab (ungültiges/abgelaufenes/doppeltes Token,
 * zu schnell, zu wenige Züge).
 */

require __DIR__ . '/i18n.php';
require __DIR__ . '/functions.php';
$config = require __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

const UX_NAME_MAX      = 20;
const UX_TOP_N         = 10;
const UX_MIN_SECONDS   = 8;      // 8 Paare lassen sich nicht schneller aufdecken
const UX_MAX_SECONDS   = 3600;   // Token ist maximal eine Stunde gültig
const UX_MIN_MOVES     = 8;
const UX_MAX_MOVES     = 99;

function uxJson(array $payload, int $code = 200): never
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    exit;
}

function uxEnsureSchema(PDO $pdo): void
{
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS ui_scores (
            id      INTEGER PRIMARY KEY AUTOINCREMENT,
            ts_utc  INTEGER NOT NULL,
            name    TEXT NOT NULL,
            moves   INTEGER NOT NULL,
            secs    INTEGER NOT NULL,
            nonce   TEXT NOT NULL UNIQUE
        )
    ');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_ui_scores_rank ON ui_scores (moves, secs, ts_utc)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS ui_kv (k TEXT PRIMARY KEY, v TEXT NOT NULL)');
}

function uxSecret(PDO $pdo): string
{
    $stmt = $pdo->prepare('SELECT v FROM ui_kv WHERE k = :k');
    $stmt->execute([':k' => 'secret']);
    $v = $stmt->fetchColumn();
    if (is_string($v) && $v !== '') {
        return $v;
    }
    $v = bin2hex(random_bytes(32));
    $pdo->prepare('INSERT OR IGNORE INTO ui_kv (k, v) VALUES (:k, :v)')->execute([':k' => 'secret', ':v' => $v]);
    $stmt->execute([':k' => 'secret']);
    return (string) $stmt->fetchColumn();
}

function uxTop(PDO $pdo): array
{
    $rows = $pdo->query('SELECT name, moves, secs FROM ui_scores ORDER BY moves ASC, secs ASC, ts_utc ASC LIMIT ' . UX_TOP_N)
        ->fetchAll(PDO::FETCH_ASSOC);
    return array_map(static fn(array $r): array => [
        'name'  => (string) $r['name'],
        'moves' => (int) $r['moves'],
        'secs'  => (int) $r['secs'],
    ], $rows);
}

function uxCleanName(string $name): string
{
    $name = preg_replace('/[\x00-\x1F\x7F\p{Cf}]+/u', '', $name) ?? '';
    $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
    return mb_substr($name, 0, UX_NAME_MAX);
}

try {
    $dbPath = $config['history']['db_path'] ?? __DIR__ . '/storage/history.sqlite';
    $pdo = historyDbConnect($dbPath);
    uxEnsureSchema($pdo);

    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $action = (string) ($method === 'POST' ? ($_POST['a'] ?? '') : ($_GET['a'] ?? 'top'));

    if ($method === 'GET' && $action === 'top') {
        uxJson(['ok' => true, 'top' => uxTop($pdo)]);
    }

    if ($method === 'GET' && $action === 'start') {
        $payload = time() . '.' . bin2hex(random_bytes(6));
        $sig = hash_hmac('sha256', $payload, uxSecret($pdo));
        uxJson(['ok' => true, 'token' => $payload . '.' . $sig]);
    }

    if ($method === 'POST' && $action === 'save') {
        $parts = explode('.', (string) ($_POST['token'] ?? ''));
        if (count($parts) !== 3) {
            uxJson(['ok' => false, 'error' => 'token'], 400);
        }
        [$ts, $nonce, $sig] = $parts;
        $payload = $ts . '.' . $nonce;
        if (!ctype_digit($ts) || !ctype_xdigit($nonce) || !hash_equals(hash_hmac('sha256', $payload, uxSecret($pdo)), $sig)) {
            uxJson(['ok' => false, 'error' => 'token'], 400);
        }

        $elapsed = time() - (int) $ts;
        $moves = (int) ($_POST['moves'] ?? 0);
        $secs  = (int) ($_POST['secs'] ?? 0);
        $name  = uxCleanName((string) ($_POST['name'] ?? ''));

        if ($elapsed < UX_MIN_SECONDS || $elapsed > UX_MAX_SECONDS
            || $moves < UX_MIN_MOVES || $moves > UX_MAX_MOVES
            || $secs < UX_MIN_SECONDS || $secs > $elapsed + 2
            || $name === '') {
            uxJson(['ok' => false, 'error' => 'invalid'], 422);
        }

        try {
            $pdo->prepare('INSERT INTO ui_scores (ts_utc, name, moves, secs, nonce) VALUES (:ts, :name, :moves, :secs, :nonce)')
                ->execute([':ts' => time(), ':name' => $name, ':moves' => $moves, ':secs' => $secs, ':nonce' => $nonce]);
        } catch (PDOException $e) {
            uxJson(['ok' => false, 'error' => 'duplicate'], 409); // Token wurde schon eingelöst
        }

        $rank = 1 + (int) $pdo->query(
            'SELECT COUNT(*) FROM ui_scores WHERE moves < ' . $moves . ' OR (moves = ' . $moves . ' AND secs < ' . $secs . ')'
        )->fetchColumn();

        uxJson(['ok' => true, 'rank' => $rank, 'top' => uxTop($pdo)]);
    }

    uxJson(['ok' => false, 'error' => 'bad_request'], 400);
} catch (Throwable $e) {
    uxJson(['ok' => false, 'error' => 'unavailable'], 500);
}
