<?php
declare(strict_types=1);

require_once __DIR__ . '/functions.php';

/**
 * Ergebnisbereich von index.php (Toolbar, Listen-Metadaten, Statusumschalter,
 * Tabelle, Pagination). Wird entweder direkt in die Seite gerendert (Tabelle
 * liegt im Session-Cache) oder als Fragment (?fragment=1) ausgeliefert, das
 * assets/result-loader.js nach dem Skeleton nachlädt.
 *
 * @param array<string, mixed> $config
 * @param list<int>            $pageSizeOptions
 * @param array<string, mixed> $data  Rückgabe von loadExplorerData()
 */
function renderResultArea(array $config, string $envKey, string $apiKey, string $query, int $perPage, ?string $sortKey, string $sortDir, string $statusFilter, ?string $statusParam, array $pageSizeOptions, array $data): void
{
    $apiCfg = $config['apis'][$apiKey];
    [
        'errorMessage' => $errorMessage, 'fetchedCount' => $fetchedCount, 'hasStatusFilter' => $hasStatusFilter, 'statusCounts' => $statusCounts,
        'entries' => $entries, 'listMeta' => $listMeta, 'pageSize' => $pageSize, 'totalFiltered' => $totalFiltered,
        'showPagination' => $showPagination, 'totalPages' => $totalPages, 'page' => $page, 'pageEntries' => $pageEntries,
    ] = $data;
?>
<?php
$fullUrl      = $config['environments'][$envKey]['base_url'] . $config['apis'][$apiKey]['path'];
$cacheMinutes = (int) round($config['cache_ttl'] / 60);
$specUrl      = $config['apis'][$apiKey]['doc_url'] ?? null;
?>

<div class="toolbar">
    <a class="url" href="<?= htmlspecialchars($fullUrl) ?>" target="_blank" rel="noopener"><?= htmlspecialchars($fullUrl) ?></a>
    <div class="toolbar-actions">
        <form method="get" data-tour="filter">
            <input type="hidden" name="env" value="<?= htmlspecialchars($envKey) ?>">
            <input type="hidden" name="api" value="<?= htmlspecialchars($apiKey) ?>">
            <?php if ($statusFilter !== ''): ?><input type="hidden" name="status" value="valid"><?php endif; ?>
            <?php if ($perPage !== $config['page_size']): ?>
                <input type="hidden" name="per_page" value="<?= htmlspecialchars((string) $perPage) ?>">
            <?php endif; ?>
            <input type="text" name="q" title="<?= htmlspecialchars(t('toolbar.search_syntax')) ?>" placeholder="<?= htmlspecialchars(t('toolbar.search_placeholder')) ?>" value="<?= htmlspecialchars($query) ?>">
            <button type="submit"><?= htmlspecialchars(t('common.search_button')) ?></button>
        </form>
        <a class="toolbar-btn refresh-btn" title="<?= htmlspecialchars(t($cacheMinutes === 1 ? 'toolbar.refresh_title_one' : 'toolbar.refresh_title_other', [$cacheMinutes])) ?>" href="<?= htmlspecialchars(buildUrl($envKey, $apiKey, $query, 0, array_filter(['refresh' => 1, 'per_page' => $perPage !== $config['page_size'] ? $perPage : null, 'status' => $statusParam]))) ?>"><?= htmlspecialchars(t('toolbar.refresh')) ?></a>
        <?php if ($errorMessage === null): ?>
            <a class="toolbar-btn spec-btn" title="<?= htmlspecialchars(t('toolbar.export_csv_title')) ?>" href="<?= htmlspecialchars(buildUrl($envKey, $apiKey, $query, 0, array_filter(['export' => 'csv', 'sort' => $sortKey, 'dir' => $sortKey !== null ? $sortDir : null, 'status' => $statusParam]))) ?>"><?= htmlspecialchars(t('toolbar.export_csv')) ?></a>
        <?php endif; ?>
        <?php if ($specUrl !== null): ?>
            <a class="toolbar-btn spec-btn" href="<?= htmlspecialchars($specUrl) ?>" target="_blank" rel="noopener"><?= htmlspecialchars($apiKey) ?> API Spec ↗</a>
        <?php endif; ?>
    </div>
</div>

<?php if ($listMeta !== null): ?>
    <div class="list-meta-panel">
        <p class="hint"><?= htmlspecialchars(t('listmeta.hint')) ?></p>
        <div class="list-meta-grid">
            <div class="list-meta-tile">
                <span class="lbl"><?= htmlspecialchars(t('listmeta.valid_from')) ?></span>
                <span class="val"><?= htmlspecialchars(formatUnixTimestamp($listMeta['nbf'])) ?></span>
            </div>
            <div class="list-meta-tile">
                <span class="lbl"><?= htmlspecialchars(t('listmeta.valid_until')) ?></span>
                <span class="val"><?= htmlspecialchars(formatUnixTimestamp($listMeta['exp'])) ?></span>
            </div>
            <div class="list-meta-tile">
                <span class="lbl"><?= htmlspecialchars(t('listmeta.created_at')) ?></span>
                <span class="val"><?= htmlspecialchars(formatUnixTimestamp($listMeta['iat'])) ?></span>
            </div>
            <div class="list-meta-tile">
                <span class="lbl"><?= htmlspecialchars(t('common.status')) ?> <?= renderInfoIcon('status') ?></span>
                <?php
                    $statusClass = match ($listMeta['status_value']) {
                        0 => 'status-valid',
                        1 => 'status-revoked',
                        2 => 'status-suspended',
                        default => 'status-unknown',
                    };
                ?>
                <span class="val">
                    <span class="status-badge <?= $statusClass ?>"><?= htmlspecialchars(statusDisplayLabel($listMeta['status_value'], $listMeta['status_label'])) ?></span>
                </span>
            </div>
            <div class="list-meta-tile">
                <span class="lbl"><?= htmlspecialchars(t('common.validity')) ?> <?= renderInfoIcon('validity') ?></span>
                <?php $listMetaValidity = computeValidity($listMeta['nbf'], $listMeta['exp']); ?>
                <span class="val">
                    <span class="status-badge <?= $listMetaValidity['class'] ?>"><?= htmlspecialchars($listMetaValidity['label']) ?></span>
                </span>
            </div>
        </div>
        <?php if (!empty($listMeta['jwt'])): ?>
            <details class="list-meta-raw">
                <summary><?= htmlspecialchars(t('detail.view_raw')) ?></summary>
                <?= renderRawJwt((string) $listMeta['jwt']) ?>
            </details>
        <?php endif; ?>
        <?php if ($listMeta['status_error'] !== null): ?>
            <p class="list-meta-error"><?= htmlspecialchars(t('listmeta.status_unavailable', [$listMeta['status_error']])) ?></p>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php if ($errorMessage !== null): ?>

    <div class="error"><?= htmlspecialchars(t('explorer.fetch_error', [$errorMessage])) ?></div>

<?php else: ?>

    <?php $hasBaseCol = !empty($apiCfg['base_registry_did_field']) && !empty($config['environments'][$envKey]['base_registry_url']); ?>
    <?php if ($hasStatusFilter && $statusCounts !== null):
        $filterBase = ['per_page' => $perPage !== $config['page_size'] ? $perPage : null, 'sort' => $sortKey, 'dir' => $sortKey !== null ? $sortDir : null];
        $urlAll   = buildUrl($envKey, $apiKey, $query, 0, $filterBase);
        $urlValid = buildUrl($envKey, $apiKey, $query, 0, $filterBase + ['status' => 'valid']);
        $hiddenTotal = $statusCounts['revoked'] + $statusCounts['suspended'] + $statusCounts['unknown'];
        $hiddenParts = [];
        if ($statusCounts['revoked'] > 0)   { $hiddenParts[] = t('status_filter.n_revoked', [$statusCounts['revoked']]); }
        if ($statusCounts['suspended'] > 0) { $hiddenParts[] = t('status_filter.n_suspended', [$statusCounts['suspended']]); }
        if ($statusCounts['unknown'] > 0)   { $hiddenParts[] = t('status_filter.n_unknown', [$statusCounts['unknown']]); }
    ?>
        <div class="status-filter" data-tour="filter" role="group" aria-label="<?= htmlspecialchars(t('status_filter.label')) ?>">
            <span class="status-filter-label"><?= htmlspecialchars(t('status_filter.label')) ?></span>
            <div class="status-seg">
                <a href="<?= htmlspecialchars($urlAll) ?>" class="<?= $statusFilter === '' ? 'active' : '' ?>"<?= $statusFilter === '' ? ' aria-current="true"' : '' ?>><?= htmlspecialchars(t('status_filter.all')) ?> <small><?= $fetchedCount ?></small></a>
                <a href="<?= htmlspecialchars($urlValid) ?>" class="<?= $statusFilter === 'valid' ? 'active' : '' ?>"<?= $statusFilter === 'valid' ? ' aria-current="true"' : '' ?>><?= $statusFilter === 'valid' ? '&#10003; ' : '' ?><?= htmlspecialchars(t('status_filter.valid')) ?> <small><?= $statusCounts['valid'] ?></small></a>
            </div>
            <?php if ($statusFilter === 'valid'): ?>
                <?php if ($hiddenTotal > 0): ?>
                    <span class="status-filter-note"><?= htmlspecialchars(t('status_filter.hidden', [$hiddenTotal])) ?>: <?= htmlspecialchars(implode(' · ', $hiddenParts)) ?></span>
                <?php endif; ?>
                <a class="status-filter-reset" href="<?= htmlspecialchars($urlAll) ?>"><?= htmlspecialchars(t('status_filter.reset')) ?> &#10005;</a>
            <?php endif; ?>
        </div>
        <?php if ($statusFilter === 'valid' && $statusCounts['unknown'] > 0): ?>
            <div class="status-filter-warning"><?= htmlspecialchars(t('status_filter.unknown_warning', [$statusCounts['unknown']])) ?></div>
        <?php endif; ?>
    <?php endif; ?>

    <div class="table-scroll">
    <table>
        <thead>
            <tr>
                <?php foreach ($config['apis'][$apiKey]['columns'] as $col): ?>
                    <?php
                        $isSorted = $sortKey === $col['key'];
                        $arrow = $isSorted ? ($sortDir === 'asc' ? '&#9650;' : '&#9660;') : '&#8693;';
                        $sortUrl = buildSortUrl($envKey, $apiKey, $query, $perPage, $col['key'], $sortKey, $sortDir, $statusParam);
                    ?>
                    <th>
                        <a class="sort-link<?= $isSorted ? ' active' : '' ?>" href="<?= htmlspecialchars($sortUrl) ?>">
                            <?= htmlspecialchars($col['label']) ?>
                            <span class="sort-arrow"><?= $arrow ?></span>
                        </a>
                        <?php if ($col['type'] === 'status_badge'): ?>
                            <?= renderInfoIcon('status') ?>
                        <?php elseif ($col['type'] === 'validity_badge'): ?>
                            <?= renderInfoIcon('validity') ?>
                        <?php endif; ?>
                    </th>
                <?php endforeach; ?>
                <?php if ($hasBaseCol): ?>
                    <th><?= htmlspecialchars(t('base.title')) ?></th>
                <?php endif; ?>
            </tr>
        </thead>
        <tbody>
            <?php
            $columnCount = count($config['apis'][$apiKey]['columns']) + ($hasBaseCol ? 1 : 0);
            $isExpandable = !empty($config['apis'][$apiKey]['expandable']);
            ?>
            <?php if (empty($pageEntries)): ?>
                <tr><td colspan="<?= $columnCount ?>">
                    <link rel="stylesheet" href="<?= htmlspecialchars(assetUrl('ui-extras.css')) ?>">
                    <div class="empty-state">
                        <span class="empty-icon" role="img" aria-label="<?= htmlspecialchars(t('empty.icon_label')) ?>">
                            <svg viewBox="0 0 48 48" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="21" cy="21" r="12"/><path d="M30 30l11 11"/><path d="M16 18v2M26 18v2"/><path d="M17 27c2.5-2.5 5.5-2.5 8 0"/></svg>
                        </span>
                        <?php if ($statusFilter === 'valid' && $query === ''): ?>
                            <div class="empty-title"><?= htmlspecialchars(t('status_filter.no_valid')) ?></div>
                        <?php else: ?>
                            <div class="empty-title"><?= htmlspecialchars(t('table.no_entries')) ?></div>
                            <?php if ($query !== ''): ?>
                                <div class="empty-hint"><?= htmlspecialchars(t('empty.hint_search')) ?></div>
                            <?php endif; ?>
                        <?php endif; ?>
                        <div class="empty-actions">
                            <?php if ($query !== ''): ?>
                                <a href="<?= htmlspecialchars(buildUrl($envKey, $apiKey, '', 0, array_filter(['per_page' => $perPage !== $config['page_size'] ? $perPage : null, 'sort' => $sortKey, 'dir' => $sortKey !== null ? $sortDir : null, 'status' => $statusParam]))) ?>"><?= htmlspecialchars(t('empty.reset_search')) ?></a>
                            <?php endif; ?>
                            <?php if ($statusFilter === 'valid'): ?>
                                <a href="<?= htmlspecialchars(buildUrl($envKey, $apiKey, $query, 0, ['per_page' => $perPage !== $config['page_size'] ? $perPage : null])) ?>"><?= htmlspecialchars(t('status_filter.show_all', [$fetchedCount])) ?></a>
                            <?php endif; ?>
                        </div>
                    </div>
                    <script>window.UX_CONFIG = <?= json_encode([
                        'endpoint' => 'ui_extras.php',
                        'i18n'     => [
                            'title' => t('ux.title'), 'moves' => t('ux.moves'), 'moves_short' => t('ux.moves_short'),
                            'close' => t('ux.close'), 'name' => t('ux.name'), 'save' => t('ux.save'), 'again' => t('ux.again'),
                            'top' => t('ux.top'), 'none' => t('ux.none'), 'won' => t('ux.won'), 'rank' => t('ux.rank'),
                            'error' => t('ux.error'), 'card' => t('ux.card'),
                        ],
                    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;</script>
                    <script src="<?= htmlspecialchars(assetUrl('ui-extras.js')) ?>"></script>
                </td></tr>
            <?php endif; ?>
            <?php foreach ($pageEntries as $i => $entry): ?>
                <tr class="entry-row<?= $isExpandable ? ' expandable' : '' ?>">
                    <?php foreach ($config['apis'][$apiKey]['columns'] as $j => $col): ?>
                        <td data-label="<?= htmlspecialchars($col['label']) ?>" class="<?= htmlspecialchars($col['class'] ?? '') ?>">
                            <?php if ($isExpandable && $j === 0): ?>
                                <span class="caret">&#9656;</span>
                            <?php endif; ?>
                            <?php if ($col['type'] === 'multilang'): ?>
                                <?= formatMultilangCell($entry, $col['key']) ?>
                            <?php elseif ($col['type'] === 'registry_ids'): ?>
                                <?= formatRegistryIdsCell(getPath($entry, $col['key'])) ?>
                            <?php elseif ($col['type'] === 'status_badge'): ?>
                                <?= formatStatusBadgeCell($entry) ?> <?= renderInfoIcon('status', true) ?>
                            <?php elseif ($col['type'] === 'vct_values'): ?>
                                <?= formatVctValuesCell($entry) ?>
                            <?php elseif ($col['type'] === 'can_issue'): ?>
                                <?= formatCanIssueCell($entry) ?>
                            <?php elseif ($col['type'] === 'validity_badge'): ?>
                                <?= formatValidityBadgeCell($entry) ?> <?= renderInfoIcon('validity', true) ?>
                            <?php elseif ($col['type'] === 'issuer_count'): ?>
                                <?= formatIssuerCountCell($entry, $envKey, $apiCfg['issuer_count_from'] ?? 'piaTS') ?>
                            <?php else: ?>
                                <?= formatCellValue(getPath($entry, $col['key']), $col['type']) ?>
                            <?php endif; ?>
                        </td>
                    <?php endforeach; ?>
                    <?php $entryDid = ''; ?>
                    <?php if ($hasBaseCol):
                        $entryDid = getPath($entry, $apiCfg['base_registry_did_field']);
                        $entryDid = is_string($entryDid) ? $entryDid : '';
                    ?>
                        <td class="br-cell" data-label="<?= htmlspecialchars(t('base.title')) ?>"<?= $entryDid !== '' ? ' data-br-did="' . htmlspecialchars($entryDid) . '"' : '' ?>>
                            <?php if ($entryDid === ''): ?>-<?php endif; ?>
                        </td>
                    <?php endif; ?>
                </tr>
                <?php if ($isExpandable): ?>
                    <tr class="detail-row">
                        <td colspan="<?= $columnCount ?>">
                            <?php if (!empty($apiCfg['issuer_count_from'])): ?>
                                <?= renderIssuerList($entry['_issuers'] ?? []) ?>
                            <?php elseif ($hasBaseCol && $entryDid !== ''): ?>
                                <div class="detail-tabs" role="tablist">
                                    <button type="button" role="tab" class="active" data-br-tab="statement"><?= htmlspecialchars(t('base.tab.statement')) ?></button>
                                    <button type="button" role="tab" data-br-tab="base"><?= htmlspecialchars(t('base.title')) ?> <span class="tab-chip" data-br-tab-chip></span></button>
                                </div>
                                <div class="detail-pane" data-br-pane-name="statement"><?= renderEntryDetail($entry, $apiCfg['show_header'] ?? true) ?></div>
                                <div class="detail-pane" data-br-pane-name="base" data-br-did="<?= htmlspecialchars($entryDid) ?>" hidden></div>
                            <?php else: ?>
                                <?= renderEntryDetail($entry, $apiCfg['show_header'] ?? true) ?>
                            <?php endif; ?>
                        </td>
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
                <?php if ($statusFilter !== ''): ?><input type="hidden" name="status" value="valid"><?php endif; ?>
                <?php if ($query !== ''): ?><input type="hidden" name="q" value="<?= htmlspecialchars($query) ?>"><?php endif; ?>
                <input type="hidden" name="p" value="0">
                <label><?= htmlspecialchars(t('pagination.per_page')) ?>
                    <select name="per_page" onchange="this.form.submit()">
                        <?php foreach ($pageSizeOptions as $opt): ?>
                            <option value="<?= $opt ?>" <?= $opt === $perPage ? 'selected' : '' ?>><?= $opt ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </form>

            <div class="page-numbers">
                <?php if ($page > 0): ?>
                    <a class="nav-edge" href="<?= htmlspecialchars(buildUrl($envKey, $apiKey, $query, 0, ['per_page' => $perPage, 'status' => $statusParam])) ?>" title="<?= htmlspecialchars(t('pagination.first')) ?>">&laquo;</a>
                    <a class="nav-step" href="<?= htmlspecialchars(buildUrl($envKey, $apiKey, $query, $page - 1, ['per_page' => $perPage, 'status' => $statusParam])) ?>" title="<?= htmlspecialchars(t('pagination.prev')) ?>">&lsaquo;</a>
                <?php else: ?>
                    <span class="disabled nav-edge">&laquo;</span>
                    <span class="disabled nav-step">&lsaquo;</span>
                <?php endif; ?>

                <?php foreach (paginationRange($page + 1, $totalPages) as $item): ?>
                    <?php if (!is_int($item)): ?>
                        <span class="ellipsis">…</span>
                    <?php elseif ($item === $page + 1): ?>
                        <span class="page-num current"><?= $item ?></span>
                    <?php else: ?>
                        <a class="page-num" href="<?= htmlspecialchars(buildUrl($envKey, $apiKey, $query, $item - 1, ['per_page' => $perPage, 'status' => $statusParam])) ?>"><?= $item ?></a>
                    <?php endif; ?>
                <?php endforeach; ?>

                <?php if ($page + 1 < $totalPages): ?>
                    <a class="nav-step" href="<?= htmlspecialchars(buildUrl($envKey, $apiKey, $query, $page + 1, ['per_page' => $perPage, 'status' => $statusParam])) ?>" title="<?= htmlspecialchars(t('pagination.next')) ?>">&rsaquo;</a>
                    <a class="nav-edge" href="<?= htmlspecialchars(buildUrl($envKey, $apiKey, $query, $totalPages - 1, ['per_page' => $perPage, 'status' => $statusParam])) ?>" title="<?= htmlspecialchars(t('pagination.last')) ?>">&raquo;</a>
                <?php else: ?>
                    <span class="disabled nav-step">&rsaquo;</span>
                    <span class="disabled nav-edge">&raquo;</span>
                <?php endif; ?>
            </div>

            <form method="get" class="page-jump-form" onsubmit="var i=this.querySelector('input[name=p]'); var v=parseInt(i.value,10); i.value = isNaN(v) ? 0 : Math.max(0, v - 1);">
                <input type="hidden" name="env" value="<?= htmlspecialchars($envKey) ?>">
                <input type="hidden" name="api" value="<?= htmlspecialchars($apiKey) ?>">
                <?php if ($statusFilter !== ''): ?><input type="hidden" name="status" value="valid"><?php endif; ?>
                <?php if ($query !== ''): ?><input type="hidden" name="q" value="<?= htmlspecialchars($query) ?>"><?php endif; ?>
                <?php if ($perPage !== $config['page_size']): ?><input type="hidden" name="per_page" value="<?= htmlspecialchars((string) $perPage) ?>"><?php endif; ?>
                <span><?= htmlspecialchars(t('pagination.page')) ?></span>
                <input type="text" inputmode="numeric" name="p" value="<?= $page + 1 ?>" style="width:44px; text-align:center;">
                <span><?= htmlspecialchars(t('pagination.of')) ?> <?= $totalPages ?></span>
            </form>
        <?php endif; ?>
    </div>

    <p class="meta">
        <?= htmlspecialchars(t('meta.entries', [$totalFiltered])) ?><?= $query !== '' ? ' ' . htmlspecialchars(t('meta.filtered_from', [$fetchedCount])) : '' ?><?= $statusFilter === 'valid' ? ' ' . htmlspecialchars(t('status_filter.meta', [$fetchedCount])) : '' ?>
        <?php if ($showPagination): ?>
            &middot; <?= htmlspecialchars(t('meta.showing', [$page * $perPage + 1, min($totalFiltered, ($page + 1) * $perPage)])) ?>
        <?php endif; ?>
    </p>

<?php endif; ?>
<?php
}
