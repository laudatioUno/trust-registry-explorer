<?php
declare(strict_types=1);

/**
 * Inspect: EINE DID, EIN Dossier. Zugleich die Startseite.
 *
 * Bewusst eine eigene Seite neben dem Explorer, weil es eine andere Aufgabe
 * ist - nicht nur eine breitere Suche:
 *
 *   Explorer (explorer.php) "zeig mir eine Liste und filtere sie"
 *                         -> viele Subjekte, Tabelle, Sortierung, Pagination,
 *                            CSV-Export
 *   Inspect  (diese Seite)  "zeig mir alles über diese eine Identität"
 *                         -> ein Subjekt, Verdikt, Belege aus allen sechs
 *                            APIs plus Base Registry
 *
 * Die beiden hingen früher auf einer Seite übereinander und wurden zuletzt
 * sogar hinter EIN Suchfeld mit Bereichsumschalter gezwängt. Das Ergebnis war
 * ein Dossier UND eine gefilterte Tabelle gleichzeitig auf dem Schirm - und
 * ein Deckel auf der Trefferzahl, damit ein Allerweltsbegriff die Seite nicht
 * sprengt. Beides waren Symptome derselben Fehldiagnose. Getrennte Seiten mit
 * gemeinsamer Umgebung und gegenseitigen Querverweisen lösen das sauber.
 *
 * Diese Seite nimmt deshalb NUR DIDs entgegen (siehe looksLikeDid()). Ein
 * Freitextbegriff ist eine Listenfrage und wird mit einem Klick an den
 * Explorer weitergereicht, statt hier abgewiesen zu werden.
 */

require __DIR__ . '/i18n.php';
require __DIR__ . '/functions.php';
$config = require __DIR__ . '/config.php';

applyInternalToggle();

// Alte Lesezeichen: index.php trug früher beide Aufgaben. Listen-URLs wandern
// nach explorer.php, DID-URLs bleiben hier (siehe functions.php).
applyLegacySearchRedirect();

$environments = visibleEnvironments($config);

$envKey = $_GET['env'] ?? array_key_first($environments);
if (!isset($environments[$envKey])) {
    $envKey = array_key_first($environments);
}

$did = trim((string) ($_GET['did'] ?? ''));

$result      = null;   // ['results' => [...], 'entity_name' => ...]
$baseResults = [];
$notADid     = ($did !== '' && !looksLikeDid($did));

if ($did !== '' && !$notADid) {
    $baseUrl = $environments[$envKey]['base_url'];
    $result  = searchAcrossApis($envKey, $baseUrl, $config['apis'], $did, $config['cache_ttl']);

    // Base Registry: DIDs aus der Eingabe und aus den idTS-Treffern nachschlagen.
    $baseRegUrl  = $environments[$envKey]['base_registry_url'] ?? null;
    $baseRegPath = $config['base_registry']['path_template'] ?? '/api/v1/did/%s/did.jsonl';
    if (is_string($baseRegUrl) && $baseRegUrl !== '') {
        $baseDids = baseRegistryCandidateDids(
            $did,
            $result['results']['idTS']['entries'] ?? [],
            (int) ($config['base_registry']['max_search_dids'] ?? 5)
        );
        foreach ($baseDids as $baseDid) {
            $baseResults[$baseDid] = fetchBaseRegistryEntry($baseRegUrl, $baseDid, $baseRegPath);
        }
    }
}

/** Link auf diese Seite (Umgebung + DID sind der ganze Zustand). */
function inspectUrl(string $envKey, string $did): string
{
    return '?' . http_build_query(array_filter(['env' => $envKey, 'did' => $did]));
}

/** Link in den Explorer: eine Liste, gefiltert auf diesen Begriff. */
function explorerUrl(string $envKey, string $apiKey, string $term = ''): string
{
    return 'explorer.php?' . http_build_query(array_filter(['env' => $envKey, 'api' => $apiKey, 'q' => $term]));
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
<link rel="stylesheet" href="<?= htmlspecialchars(assetUrl('inspect.css')) ?>">
<style>
    body { font-family: Arial, sans-serif; margin: 2em; color: var(--text); background: var(--bg); display: flex; flex-direction: column; min-height: calc(100vh - 4em); min-height: calc(100dvh - 4em); }
    h1 { font-size: 1.3em; margin-bottom: 1em; }

    .page-header { display: flex; justify-content: space-between; align-items: center; gap: 12px; margin-bottom: 1em; flex-wrap: wrap; }
    .page-header h1 { margin: 0; }
    .page-header .brand { display: flex; align-items: center; gap: 10px; color: inherit; text-decoration: none; }
    .top-nav { display: flex; gap: 6px; align-items: stretch; }
    .top-nav a { display: inline-flex; align-items: center; justify-content: center; line-height: 1; padding: 6px 16px; font-size: 13px; border: 1px solid var(--border); border-radius: 20px; text-decoration: none; color: var(--text-2); background: var(--surface); box-sizing: border-box; }
    .top-nav a.active { background: var(--accent-fill); color: var(--on-accent); border-color: var(--accent-fill); font-weight: bold; }

    .tabs { display: flex; gap: 4px; border-bottom: 1px solid var(--border); margin-bottom: 16px; }
    .tabs a { padding: 8px 18px; font-size: 14px; text-decoration: none; color: var(--text-2); border-bottom: 3px solid transparent; }
    .tabs a.active { color: var(--accent); border-bottom-color: var(--accent); font-weight: bold; }

    .inspect-empty { color: var(--text-3); font-size: 14px; margin-top: 18px; display: flex; flex-wrap: wrap; gap: 10px; align-items: baseline; }
    .inspect-wrong-kind { margin-top: 14px; padding: 12px 14px; border-radius: 8px;
                          border-left: 4px solid var(--accent-2); background: var(--accent-soft-2); font-size: 13px; }
    .inspect-wrong-kind p { margin: 0 0 8px; }

    main { flex: 1; }

    @media (max-width: 760px) {
        body { margin: 1em; }
        .page-header { flex-direction: column; align-items: flex-start; }
        .tabs { overflow-x: auto; }
    }
</style>
</head>
<body<?= isPreview() ? ' class="is-preview"' : '' ?>>

<?= renderPageHeader('inspect', $config, $envKey) ?>

<?php /* Die Umgebung gilt für die ganze Anwendung - hier wie im Explorer.
         Wechseln behält die eingegebene DID, damit "gleiche Frage, andere
         Umgebung" ein Klick bleibt. */ ?>
<div class="tabs">
    <?php foreach ($environments as $key => $env): ?>
        <a href="<?= htmlspecialchars(inspectUrl($key, $did)) ?>"
           class="<?= $key === $envKey ? 'active' : '' ?>"><?= htmlspecialchars(envLabel($env)) ?></a>
    <?php endforeach; ?>
</div>

<main>
<div class="did-search<?= $result === null ? ' is-idle' : '' ?>">
    <form method="get" class="search-form">
        <input type="hidden" name="env" value="<?= htmlspecialchars($envKey) ?>">
        <input type="text" name="did" class="search-input"
               placeholder="<?= htmlspecialchars(t('inspect.placeholder')) ?>"
               value="<?= htmlspecialchars($did) ?>">
        <button type="submit"><?= htmlspecialchars(t('inspect.button')) ?></button>
    </form>
    <p class="hint"><?= htmlspecialchars(t('inspect.hint')) ?></p>

    <?php /* Keine DID eingegeben: das ist eine Listenfrage, keine
             Identitätsfrage. Statt sie abzuweisen, wird sie weitergereicht. */ ?>
    <?php if ($notADid): ?>
        <div class="inspect-wrong-kind">
            <p><?= htmlspecialchars(t('inspect.not_a_did')) ?></p>
            <a class="br-link" href="<?= htmlspecialchars(explorerUrl($envKey, 'idTS', $did)) ?>"><?= htmlspecialchars(t('inspect.try_explorer')) ?> &rarr;</a>
        </div>
    <?php endif; ?>

    <?php if ($result !== null): ?>
        <?php
        $idtsHits  = $result['results']['idTS']['entries'] ?? [];
        $idtsError = $result['results']['idTS']['error'] ?? null;

        $totalHits = array_sum(array_map(static fn (array $r): int => count($r['entries']), $result['results']));
        $apisWithHits = 0;
        foreach ($result['results'] as $r) {
            if (count($r['entries']) > 0) {
                $apisWithHits++;
            }
        }

        // Obergrenze, wie viele Treffer pro API ausgeschrieben werden. Auch bei
        // DIDs nötig: eine viel genutzte Issuer-DID kann in vqPS/piaTS in
        // hunderten Einträgen vorkommen (ein Eintrag pro Credential-Typ).
        $maxDetail = (int) ($config['search_max_detail_hits'] ?? 5);

        // Verdikt: zuerst die Antwort, dann die Belege. Massgeblich ist idTS -
        // dort steht, ob eine Identität eingetragen ist; die Statuslist
        // entscheidet über gültig/gesperrt.
        $idtsBuckets = statusBucketCounts($idtsHits);
        if ($totalHits === 0) {
            $verdictKey = 'search.verdict_not_found';
        } elseif ($idtsHits === []) {
            $verdictKey = 'search.verdict_no_identity';
        } elseif ($idtsBuckets['valid'] > 0) {
            $verdictKey = 'search.verdict_valid';
        } elseif ($idtsBuckets['revoked'] > 0 || $idtsBuckets['suspended'] > 0) {
            $verdictKey = 'search.verdict_revoked';
        } else {
            $verdictKey = 'search.verdict_unknown';
        }
        $verdictState = match ($verdictKey) {
            'search.verdict_valid'     => 'ok',
            'search.verdict_revoked'   => 'bad',
            'search.verdict_not_found' => 'none',
            default                    => 'warn',
        };
        ?>
        <div class="search-verdict verdict-<?= $verdictState ?>">
            <?php if ($result['entity_name'] !== null): ?>
                <span class="verdict-name"><?= htmlspecialchars($result['entity_name']) ?></span>
            <?php endif; ?>
            <span class="verdict-text"><?= htmlspecialchars(t($verdictKey)) ?></span>
            <span class="verdict-env"><?= htmlspecialchars(envLabel($environments[$envKey])) ?></span>
        </div>

        <div class="search-summary">
            <p class="search-summary-text">
                <?= htmlspecialchars(t('search.summary_all', [$totalHits, $apisWithHits, count($config['apis'])])) ?>
            </p>
        </div>

        <?php /* Sackgasse vermeiden: nichts gefunden, aber es gibt eine andere
                 Umgebung -> den naheliegenden nächsten Schritt anbieten.
                 Bewusst nur als Link: die andere Umgebung wird NICHT im
                 Hintergrund geladen (ein kalter vqPS-Abruf läuft über 1'500
                 Einträge). */ ?>
        <?php if ($totalHits === 0): ?>
            <?php $otherEnvs = array_diff_key($environments, [$envKey => true]); ?>
            <?php if ($otherEnvs !== []): ?>
                <p class="search-elsewhere">
                    <?= htmlspecialchars(t('search.not_found_here', [envLabel($environments[$envKey])])) ?>
                    <?php foreach ($otherEnvs as $key => $env): ?>
                        <a href="<?= htmlspecialchars(inspectUrl($key, $did)) ?>"><?= htmlspecialchars(t('search.look_in', [envLabel($env)])) ?> &rarr;</a>
                    <?php endforeach; ?>
                </p>
            <?php endif; ?>
        <?php endif; ?>

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
                    <?php foreach (array_slice($idtsHits, 0, $maxDetail) as $i => $hit): ?>
                        <div class="tr-hit">
                            <?php if (count($idtsHits) > 1): ?><p class="did-hit-label"><?= htmlspecialchars(t('search.hit_n', [$i + 1])) ?></p><?php endif; ?>
                            <?php if ($result['entity_name'] !== null && $i === 0): ?>
                                <div class="tr-entity">
                                    <span class="lbl"><?= htmlspecialchars(t('search.entity_label')) ?></span>
                                    <span class="val"><?= htmlspecialchars($result['entity_name']) ?></span>
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
                        </div>
                    <?php endforeach; ?>
                    <?php if (count($idtsHits) > $maxDetail): ?>
                        <p class="more-hits"><?= htmlspecialchars(t('search.showing_n_of_m', [$maxDetail, count($idtsHits)])) ?></p>
                    <?php endif; ?>
                    <?php if ($idtsHits !== []): ?>
                        <div class="br-actions">
                            <a class="br-link" href="<?= htmlspecialchars(explorerUrl($envKey, 'idTS', $did)) ?>"><?= htmlspecialchars(t('inspect.open_in_explorer')) ?> &rarr;</a>
                        </div>
                    <?php endif; ?>
                </div>
            </section>
            <?= renderBaseRegistrySearchPanel($baseResults, $result['entity_name']) ?>
        </div>

        <?php
        $otherApis = array_diff_key($config['apis'], ['idTS' => true]);
        $noHitLabels = [];
        foreach ($otherApis as $apiKeyIter => $apiCfgIter) {
            $r = $result['results'][$apiKeyIter] ?? ['entries' => [], 'error' => null];
            if (count($r['entries']) === 0 && $r['error'] === null) {
                $noHitLabels[] = $apiCfgIter['label'];
            }
        }
        ?>
        <?php if ($otherApis !== []): ?>
        <h3 class="br-others-title"><?= htmlspecialchars(t('base.other_apis')) ?></h3>
        <div class="did-results">
            <?php foreach ($otherApis as $apiKeyIter => $apiCfgIter):
                $r = $result['results'][$apiKeyIter] ?? ['entries' => [], 'error' => null];
                $hitCount = count($r['entries']);
            ?>
                <?php if ($hitCount > 0 || $r['error'] !== null): ?>
                    <div class="did-card">
                        <div class="did-card-head" onclick="this.nextElementSibling.classList.toggle('open')">
                            <span class="name"><?= htmlspecialchars($apiCfgIter['description']) ?> <small><?= htmlspecialchars($apiCfgIter['label']) ?></small></span>
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
                                <?php foreach (array_slice($r['entries'], 0, $maxDetail) as $i => $hit): ?>
                                    <?php if ($hitCount > 1): ?><p class="did-hit-label"><?= htmlspecialchars(t('search.hit_n', [$i + 1])) ?></p><?php endif; ?>
                                    <?= renderEntryDetail($hit, $apiCfgIter['show_header'] ?? true) ?>
                                <?php endforeach; ?>
                                <?php if ($hitCount > $maxDetail): ?>
                                    <p class="more-hits"><?= htmlspecialchars(t('search.showing_n_of_m', [$maxDetail, $hitCount])) ?></p>
                                <?php endif; ?>
                                <?php /* Von hier in die Liste: der Explorer ist das richtige
                                         Werkzeug für Menge, Sortierung und Export. */ ?>
                                <div class="br-actions">
                                    <a class="br-link" href="<?= htmlspecialchars(explorerUrl($envKey, $apiKeyIter, $did)) ?>"><?= htmlspecialchars(t('inspect.open_in_explorer')) ?> &rarr;</a>
                                </div>
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
    <?php elseif (!$notADid): ?>
        <?php /* Startseite ohne Eingabe: hier landet, wer die App zum ersten
                 Mal öffnet. Also nicht nur auffordern, sondern auch den
                 anderen Weg zeigen - wer keine DID zur Hand hat, will
                 vermutlich erst einmal eine Liste durchblättern. */ ?>
        <p class="inspect-empty">
            <?= htmlspecialchars(t('inspect.empty')) ?>
            <a href="<?= htmlspecialchars(explorerUrl($envKey, 'idTS')) ?>"><?= htmlspecialchars(t('inspect.or_browse')) ?> &rarr;</a>
        </p>
    <?php endif; ?>
</div>
</main>

<?= renderFooter($config) ?>

<?php /* base-registry.js braucht Endpunkt, Umgebung und ein paar Texte -
         dieselbe Konfiguration wie im Explorer (siehe explorer.php). */ ?>
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
</body>
</html>
