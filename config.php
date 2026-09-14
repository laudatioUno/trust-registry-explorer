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
        'REF'  => ['label' => 'REF',  'base_url' => 'https://trust-reg-r.trust-infra.swiyu.admin.ch'],
        'ABN'  => ['label' => 'ABN',  'base_url' => 'https://trust-reg-a.trust-infra.swiyu.admin.ch'],
        'INT-ABN'  => ['label' => 'ABN',  'base_url' => 'https://trust-reg-a.trust-infra.swiyu-int.admin.ch'],
        'PROD' => ['label' => 'PROD', 'base_url' => 'https://trust-reg.trust-infra.swiyu.admin.ch'],
        'INT-PROD' => ['label' => 'PROD', 'base_url' => 'https://trust-reg.trust-infra.swiyu-int.admin.ch'],
    ],

    'apis' => [

        'ncTLS' => [
            'label'       => 'ncTLS',
            'description' => 'Non-Compliance Trust List',
            'path'        => '/api/v2/non-compliance-trust-list',
            'mode'        => 'single_jwt_list',
            'list_field'  => 'non_compliant_actors',
            'columns'     => [
                ['key' => 'actor',        'label' => 'Actor (DID)',  'type' => 'text'],
                ['key' => 'flagged_at',   'label' => 'Geflaggt am',  'type' => 'iso'],
                ['key' => 'reason#de-CH', 'label' => 'Grund (DE)',   'type' => 'text'],
                ['key' => 'reason#en',    'label' => 'Grund (EN)',   'type' => 'text'],
                ['key' => 'reason#fr-CH', 'label' => 'Grund (FR)',   'type' => 'text'],
                ['key' => 'reason#it-CH', 'label' => 'Grund (IT)',   'type' => 'text'],
                ['key' => 'reason#rm-CH', 'label' => 'Grund (RM)',   'type' => 'text'],
            ],
        ],

        'piTLS' => [
            'label'       => 'piTLS',
            'description' => 'Protected Issuance Trust List',
            'path'        => '/api/v2/protected-issuance-trust-list',
            'mode'        => 'single_jwt_list',
            'list_field'  => 'vct_values',
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
                ['key' => 'sub',                       'label' => 'Issuer (DID)', 'type' => 'text'],
                ['key' => 'iat',                       'label' => 'Erstellt am',  'type' => 'unix'],
                ['key' => 'can_issue.vct',              'label' => 'VCT',          'type' => 'text'],
                ['key' => 'can_issue.vct_name#de-CH',   'label' => 'VCT-Name (DE)','type' => 'text'],
                ['key' => 'can_issue.reason#de-CH',     'label' => 'Grund (DE)',   'type' => 'text'],
            ],
        ],

        'pvaTS' => [
            'label'       => 'pvaTS',
            'description' => 'Protected Verification Authorization Trust Statement',
            'path'        => '/api/v2/protected-verification-authorization-trust-statement',
            'mode'        => 'paginated_jwt',
            'columns'     => [
                ['key' => 'sub',               'label' => 'Verifier (DID)',         'type' => 'text'],
                ['key' => 'iat',               'label' => 'Erstellt am',            'type' => 'unix'],
                ['key' => 'authorized_fields', 'label' => 'Autorisierte Felder',    'type' => 'list'],
            ],
        ],

    ],

    // Anzahl Einträge pro angezeigter Seite (nach Suche/Filterung)
    'page_size' => 20,

    // Wie lange der Session-Cache pro Umgebung+API gültig ist, bevor
    // ohne expliziten "Abfragen"-Klick automatisch neu geladen wird (Sekunden)
    'cache_ttl' => 300,
];
