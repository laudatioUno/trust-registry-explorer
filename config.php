<?php
/**
 * Zentrale Konfiguration für den swiyu Trust Registry Explorer.
 * Neue Umgebung: einfach in 'environments' ergänzen.
 * Neue API: einfach in 'apis' ergänzen (siehe bestehende Einträge als Vorlage).
 *
 * mode:
 *   'single_jwt_list'  -> Endpoint liefert EIN JWT, das eine Liste im Payload enthält
 *                         (z.B. ncTLS: non_compliant_actors, piTLS: vct_values)
 *   'paginated_jwt'     -> Endpoint liefert {"content":[JWT,...],"page":{...}} und
 *                         unterstützt ?page=&size= (vqPS, piaTS, pvaTS)
 *
 * columns: Liste von ['key' => <Punkt-Pfad im Payload>, 'label' => <Spaltentitel>, 'type' => text|unix|iso|list]
 *   - 'unix'  = Unix-Timestamp (int), wird in Europe/Zurich lesbar formatiert
 *   - 'iso'   = ISO-8601 Zeitstring, wird in Europe/Zurich lesbar formatiert
 *   - 'list'  = Array von Strings, wird kommagetrennt dargestellt
 *   - 'text'  = Default, wird als String dargestellt
 */

return [

    'environments' => [
        'REF'      => ['label' => 'REF',      'base_url' => 'https://trust-reg-r.trust-infra.swiyu.admin.ch'],
        'ABN'      => ['label' => 'ABN',      'base_url' => 'https://trust-reg-a.trust-infra.swiyu.admin.ch'],
        'INT-ABN'  => ['label' => 'INT-ABN',  'base_url' => 'https://trust-reg-a.trust-infra.swiyu-int.admin.ch'],
        'PROD'     => ['label' => 'PROD',     'base_url' => 'https://trust-reg.trust-infra.swiyu.admin.ch'],
        'INT-PROD' => ['label' => 'INT-PROD', 'base_url' => 'https://trust-reg.trust-infra.swiyu-int.admin.ch'],
    ],

    'apis' => [

        'idTS' => [
            'label'       => 'idTS',
            'description' => 'Identity Trust Statement',
            'path'        => '/api/v2/identity-trust-statement',
            'mode'        => 'paginated_jwt',
            // Zeilen sind aufklappbar und zeigen dann alle im JWT vorhandenen
            // Felder inkl. aller Sprachvarianten von entity_name.
            'expandable'  => true,
            // Hier hat JEDE Zeile ihre eigene Status-List-Referenz (anders als
            // list_meta bei ncTLS/piTLS, wo es nur eine für die ganze Liste gibt).
            'row_status'  => true,
            'columns'     => [
                ['key' => 'entity_name',    'label' => 'Entity Name',      'type' => 'text'],
                ['key' => 'is_state_actor', 'label' => 'is_state_actor',   'type' => 'raw_bool'],
                ['key' => 'registry_ids',   'label' => 'Registry IDs',     'type' => 'registry_ids'],
                ['key' => '_status_value',  'label' => 'Status',           'type' => 'status_badge'],
            ],
        ],

        'ncTLS' => [
            'label'             => 'ncTLS',
            'description'       => 'Non-Compliance Trust List',
            'path'              => '/api/v2/non-compliance-trust-list',
            'mode'              => 'single_jwt_list',
            'list_field'        => 'non_compliant_actors',
            // nbf/exp/iat + aufgelöster Status gelten hier für die GESAMTE Liste
            // (ein JWT, eine Statusliste) — werden oberhalb der Tabelle angezeigt.
            'list_meta'         => true,
            // DID-Feld heisst hier 'actor' (nicht 'sub' wie bei den meisten
            // anderen APIs) — Name wird trotzdem über idTS nachgeschlagen.
            'enrich_name_from'  => 'idTS',
            'enrich_did_field'  => 'actor',
            'columns'           => [
                ['key' => 'actor',        'label' => 'Actor (DID)',      'type' => 'text', 'class' => 'cell-did'],
                ['key' => '_entity_name', 'label' => 'Name (from idTS)', 'type' => 'text'],
                ['key' => 'flagged_at',   'label' => 'Geflaggt am',      'type' => 'iso'],
                ['key' => 'reason#de-CH', 'label' => 'Grund (DE)',       'type' => 'text'],
                ['key' => 'reason#en',    'label' => 'Grund (EN)',       'type' => 'text'],
                ['key' => 'reason#fr-CH', 'label' => 'Grund (FR)',       'type' => 'text'],
                ['key' => 'reason#it-CH', 'label' => 'Grund (IT)',       'type' => 'text'],
                ['key' => 'reason#rm-CH', 'label' => 'Grund (RM)',       'type' => 'text'],
            ],
        ],

        'piTLS' => [
            'label'       => 'piTLS',
            'description' => 'Protected Issuance Trust List',
            'path'        => '/api/v2/protected-issuance-trust-list',
            'mode'        => 'single_jwt_list',
            'list_field'  => 'vct_values',
            // nbf/exp/iat + aufgelöster Status gelten für die GESAMTE Liste,
            // gleiches Panel-Design wie bei ncTLS.
            'list_meta'   => true,
            'scalar_list' => true,
            'columns'     => [
                ['key' => 'value', 'label' => 'VCT-Wert', 'type' => 'text'],
            ],
        ],

        'vqPS' => [
            'label'       => 'vqPS',
            'description' => 'Verification Query Public Statement',
            'path'        => '/api/v2/verification-query-public-statement',
            'mode'        => 'paginated_jwt',
            // Zeilen sind aufklappbar (siehe index.php) und zeigen dann alle JWT-Felder.
            'expandable'  => true,
            'columns'     => [
                ['key' => 'request.scope',                            'label' => 'Scope',                  'type' => 'text'],
                ['key' => 'purpose_name',                             'label' => 'Zweck (alle Sprachen)',  'type' => 'multilang'],
                ['key' => 'request.query.credentials.0.format',       'label' => 'Format',                 'type' => 'text'],
                ['key' => 'nbf',                                      'label' => 'Gültig ab (nbf)',        'type' => 'unix'],
                ['key' => 'exp',                                      'label' => 'Gültig bis (exp)',       'type' => 'unix'],
                ['key' => 'iat',                                      'label' => 'Erstellt am (iat)',      'type' => 'unix'],
            ],
        ],

        'piaTS' => [
            'label'       => 'piaTS',
            'description' => 'Protected Issuance Authorization Trust Statement',
            'path'        => '/api/v2/protected-issuance-authorization-trust-statement',
            'mode'        => 'paginated_jwt',
            'columns'     => [
                ['key' => 'sub',                       'label' => 'Issuer (DID)', 'type' => 'text', 'class' => 'cell-did'],
                ['key' => 'iat',                       'label' => 'Erstellt am',  'type' => 'unix'],
                ['key' => 'can_issue.vct',              'label' => 'VCT',          'type' => 'text'],
                ['key' => 'can_issue.vct_name#de-CH',   'label' => 'VCT-Name (DE)','type' => 'text'],
                ['key' => 'can_issue.reason#de-CH',     'label' => 'Grund (DE)',   'type' => 'text'],
            ],
        ],

        'pvaTS' => [
            'label'             => 'pvaTS',
            'description'       => 'Protected Verification Authorization Trust Statement',
            'path'              => '/api/v2/protected-verification-authorization-trust-statement',
            'mode'              => 'paginated_jwt',
            // Jede Zeile ist ein eigenständiges JWT mit eigenem Status (wie idTS) —
            // daher row_status statt list_meta. Zusätzlich wird der Name über die
            // DID aus den idTS-Einträgen derselben Umgebung nachgeschlagen.
            'expandable'        => true,
            'row_status'        => true,
            'enrich_name_from'  => 'idTS',
            'columns'           => [
                ['key' => 'sub',               'label' => 'DID',                'type' => 'text', 'class' => 'cell-did'],
                ['key' => '_entity_name',      'label' => 'Name (from idTS)',    'type' => 'text'],
                ['key' => 'authorized_fields', 'label' => 'Authorized Fields',   'type' => 'list'],
                ['key' => '_status_value',     'label' => 'Status',              'type' => 'status_badge'],
            ],
        ],

    ],

    // Anzahl Einträge pro angezeigter Seite (nach Suche/Filterung) — Startwert,
    // wird bei jedem Umgebungs-/API-Wechsel wieder auf diesen Wert zurückgesetzt.
    'page_size' => 20,

    // Zur Auswahl stehende Seitengrössen (Dropdown in der Pagination-Leiste)
    'page_size_options' => [20, 50, 100, 200],

    // Wie lange der Session-Cache pro Umgebung+API gültig ist, bevor
    // ohne expliziten "Abfragen"-Klick automatisch neu geladen wird (Sekunden)
    'cache_ttl' => 300,
];
