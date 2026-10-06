<?php
declare(strict_types=1);

/**
 * JSON-Endpoint für die Base-Registry-Abfrage (vom Browser per fetch() genutzt:
 * Spalte "Base Registry" in der idTS-Tabelle und Tab in der aufgeklappten Zeile).
 *
 * GET base_registry.php?env=<ENV>&did=<DID>&view=chip|detail[&refresh=1]
 *
 *   view=chip    (Default) kompakte Antwort, Session-gecacht -> {"status","label","html"}
 *   view=detail  vollständige Antwort inkl. Rohlog, ungecacht -> {"status","label","html"}
 *
 * Es wird NIE eine frei übergebene URL abgerufen: der Host kommt aus der Config
 * (environments[ENV].base_registry_url), aus der DID wird nur die UUID übernommen
 * und streng als UUID validiert (siehe baseRegistryDidInfo).
 *
 * i18n.php muss zuerst geladen werden (t(), Sprache aus dem Cookie).
 */

require __DIR__ . '/i18n.php';
require __DIR__ . '/functions.php';
$config = require __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function brJson(array $payload, int $code = 200): never
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    exit;
}

$envKey = (string) ($_GET['env'] ?? '');
$did    = trim((string) ($_GET['did'] ?? ''));
$view   = ($_GET['view'] ?? 'chip') === 'detail' ? 'detail' : 'chip';
$force  = isset($_GET['refresh']);

if (!isset($config['environments'][$envKey]) || $did === '' || strlen($did) > 400) {
    brJson(['status' => 'error', 'label' => '', 'html' => ''], 400);
}

$registryUrl = $config['environments'][$envKey]['base_registry_url'] ?? null;
if (!is_string($registryUrl) || $registryUrl === '') {
    brJson(['status' => 'error', 'label' => '', 'html' => ''], 400);
}
$pathTemplate = $config['base_registry']['path_template'] ?? '/api/v1/did/%s/did.jsonl';

if ($view === 'detail') {
    // Nur lesen, keine Session -> kein Session-Lock, parallele Aufrufe bleiben schnell.
    $res = fetchBaseRegistryEntry($registryUrl, $did, $pathTemplate);
    $html = renderBaseRegistryDetail($res);
} else {
    $res = getBaseRegistryCached($envKey, $registryUrl, $did, $force, (int) $config['cache_ttl'], true, $pathTemplate);
    $html = renderBaseRegistryChip($res);
}

$label = ($res['status'] === 'found' && $res['log'] !== null)
    ? baseRegistryVersionsLabel((int) $res['log']['versions'])
    : baseRegistryStatusLabel($res['status']);

brJson(['status' => $res['status'], 'label' => $label, 'html' => $html]);
