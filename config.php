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
 *
 * Spaltentitel/Beschreibungen, die bereits rein englisch/technisch sind
 * (z.B. "Status", "DID", API-Beschreibungen), stehen hier direkt als Literal
 * — identisch in beiden Sprachen, kein Übersetzungs-Key nötig. Nur die
 * tatsächlich deutschen Originaltexte laufen über t() (siehe lang.php).
 * Setzt voraus, dass i18n.php VOR dieser Datei geladen wurde (index.php/explorer.php/
 * history.php tun das bereits als allerersten Require).
 */

return [

    // Anwendungsversion (Semantic Versioning). Wird in der Fusszeile angezeigt
    // und von der GitHub-Action .github/workflows/release.yml gelesen: ändert
    // sich dieser Wert auf main, entsteht automatisch Tag + Release (+ ZIP).
    // Nur im Release-PR anpassen.
    'version'  => '1.0.1',
    'repo_url' => 'https://github.com/laudatioUno/trust-registry-explorer',

    // base_url:          Trust Registry (idTS, ncTLS, ... Trust Statements)
    // base_registry_url: Base Registry derselben Umgebung (did.jsonl pro DID,
    //                    siehe 'base_registry' unten)
    // internal:          true  -> Umgebung erscheint NUR im internen Modus
    //                    (Umschalter "Internal" im Seitenkopf, Cookie-basiert,
    //                    siehe internalMode()/visibleEnvironments() in
    //                    functions.php). Ohne das Flag gilt eine Umgebung als
    //                    öffentlich und ist immer sichtbar.
    //                    Öffentlich relevant sind nur PROD und INT-PROD; die
    //                    übrigen Umgebungen sind interne Stufen.
    // label:             interner Name (Codename), wie er im internen Modus
    //                    angezeigt wird.
    // public_label:      öffentlicher Name - PROD und INT-PROD sind interne
    //                    Codenamen, nach aussen heissen die beiden Umgebungen
    //                    "Production" und "Sandbox" (so auch in der
    //                    swiyu-Dokumentation). Fehlt der Eintrag, wird 'label'
    //                    in beiden Modi verwendet (siehe envLabel()).
    // Die Reihenfolge hier ist auch die Reihenfolge der Umgebungs-Tabs; die
    // erste SICHTBARE Umgebung ist die Standardauswahl (öffentlich: Production).
    'environments' => [
        'PROD'     => ['label' => 'PROD',     'public_label' => 'Production', 'base_url' => 'https://trust-reg.trust-infra.swiyu.admin.ch', 'base_registry_url' => 'https://identifier-reg.trust-infra.swiyu.admin.ch'],
        'INT-PROD' => ['label' => 'INT-PROD', 'public_label' => 'Sandbox',    'base_url' => 'https://trust-reg.trust-infra.swiyu-int.admin.ch', 'base_registry_url' => 'https://identifier-reg.trust-infra.swiyu-int.admin.ch'],
        'REF'      => ['label' => 'REF',      'base_url' => 'https://trust-reg-r.trust-infra.swiyu.admin.ch', 'base_registry_url' => 'https://identifier-reg-r.trust-infra.swiyu.admin.ch', 'internal' => true],
        'ABN'      => ['label' => 'ABN',      'base_url' => 'https://trust-reg-a.trust-infra.swiyu.admin.ch', 'base_registry_url' => 'https://identifier-reg-a.trust-infra.swiyu.admin.ch', 'internal' => true],
        'INT-ABN'  => ['label' => 'INT-ABN',  'base_url' => 'https://trust-reg-a.trust-infra.swiyu-int.admin.ch', 'base_registry_url' => 'https://identifier-reg-a.trust-infra.swiyu-int.admin.ch', 'internal' => true],
    ],

    // Base Registry: pro DID wird das did.jsonl (did:tdw 0.3 und did:webvh 1.0)
    // über die UUID aus dem letzten DID-Segment abgerufen:
    //   did:tdw:<scid>:<host>:api:v1:did:<UUID>  ->  <base_registry_url>/api/v1/did/<UUID>/did.jsonl
    // Nur DIDs, deren Host zu base_registry_url der Umgebung passt, werden
    // abgefragt (alle anderen gelten als "External DID").
    'base_registry' => [
        'path_template'  => '/api/v1/did/%s/did.jsonl',
        // Max. Anzahl DIDs, die die globale Suche pro Suchlauf im Base Registry nachschlägt
        'max_search_dids' => 5,
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
            // Zusätzliche Spalte "Base Registry" (Anzahl Versionen im DID-Log, per
            // AJAX nachgeladen) + Tab "Base Registry" in der aufgeklappten Zeile.
            // Wert = Feld im Eintrag, das die DID trägt.
            'base_registry_did_field' => 'sub',
            'columns'     => [
                ['key' => 'entity_name',    'label' => 'Entity Name',      'type' => 'text'],
                ['key' => 'is_state_actor', 'label' => 'is_state_actor',   'type' => 'raw_bool'],
                ['key' => 'registry_ids',   'label' => 'Registry IDs',     'type' => 'registry_ids'],
                ['key' => 'iat',            'label' => t('col.issued_at'), 'type' => 'unix'],
                ['key' => '_status_value',  'label' => 'Status',           'type' => 'status_badge'],
                ['key' => 'validity',       'label' => 'Validity',         'type' => 'validity_badge'],
            ],
            'doc_url'     => 'https://swiyu-admin-ch.github.io/specifications/trust-protocol-v2-0/#identity-trust-statement-idts',
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
                ['key' => 'flagged_at',   'label' => t('col.flagged_at'), 'type' => 'iso'],
                ['key' => 'reason#de-CH', 'label' => t('col.reason_de'), 'type' => 'text'],
                ['key' => 'reason#en',    'label' => t('col.reason_en'), 'type' => 'text'],
                ['key' => 'reason#fr-CH', 'label' => t('col.reason_fr'), 'type' => 'text'],
                ['key' => 'reason#it-CH', 'label' => t('col.reason_it'), 'type' => 'text'],
                ['key' => 'reason#rm-CH', 'label' => t('col.reason_rm'), 'type' => 'text'],
            ],
            'doc_url'           => 'https://swiyu-admin-ch.github.io/specifications/trust-protocol-v2-0/#non-compliance-trust-list-statement-nctls',
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
            // Verknüpfung mit piaTS: jedes VCT hier wird gegen piaTS'
            // can_issue-Felder gezählt (siehe attachIssuerCounts()). Zeile
            // aufklappbar (Klick auf Zeile) zeigt die gefundenen Issuer inline;
            // Klick auf die Zahl selbst springt stattdessen zu piaTS (vorgefiltert).
            'expandable'        => true,
            'issuer_count_from' => 'piaTS',
            'columns'     => [
                ['key' => 'value',         'label' => t('col.vct_value'),           'type' => 'text'],
                ['key' => '_issuer_count', 'label' => 'Amount of allowed issuers',  'type' => 'issuer_count'],
            ],
            'doc_url'           => 'https://swiyu-admin-ch.github.io/specifications/trust-protocol-v2-0/#protected-issuance-trust-list-statement-pitls',
        ],

        'vqPS' => [
            'label'             => 'vqPS',
            'description'       => 'Verification Query Public Statement',
            'path'              => '/api/v2/verification-query-public-statement',
            'mode'              => 'paginated_jwt',
            // Zeilen sind aufklappbar (siehe explorer.php) und zeigen dann alle JWT-Felder.
            'expandable'        => true,
            // Der JWT-Header ist bei jedem Eintrag identisch (derselbe Issuer-Key) —
            // bietet keinen Mehrwert pro Zeile, daher in der Detailansicht ausgeblendet.
            'show_header'       => false,
            'enrich_name_from'  => 'idTS',
            'columns'           => [
                ['key' => '_entity_name', 'label' => 'Name (from idTS)', 'type' => 'text'],
                ['key' => 'vct_values',   'label' => 'VCT Values',       'type' => 'vct_values'],
                ['key' => 'request.query.credentials.0.format', 'label' => 'Format', 'type' => 'text'],
                ['key' => 'iat',          'label' => t('col.issued_at'), 'type' => 'unix'],
                ['key' => 'validity',     'label' => 'Validity',         'type' => 'validity_badge'],
            ],
            'doc_url'           => 'https://swiyu-admin-ch.github.io/specifications/trust-protocol-v2-0/#verification-query-public-statement-vqps',
        ],

        'piaTS' => [
            'label'             => 'piaTS',
            'description'       => 'Protected Issuance Authorization Trust Statement',
            'path'              => '/api/v2/protected-issuance-authorization-trust-statement',
            'mode'              => 'paginated_jwt',
            'expandable'        => true,
            'row_status'        => true,
            'enrich_name_from'  => 'idTS',
            // Header ist bei jedem Eintrag identisch (gleicher Issuer-Key) —
            // kein Mehrwert pro Zeile, daher in der Detailansicht ausgeblendet.
            'show_header'       => false,
            'columns'           => [
                ['key' => '_entity_name', 'label' => 'Name (from idTS)',      'type' => 'text'],
                ['key' => 'can_issue',    'label' => 'Can Issue',             'type' => 'can_issue'],
                ['key' => 'iat',          'label' => t('col.issued_at'),      'type' => 'unix'],
                ['key' => '_status_value','label' => 'Status (from Statuslist)', 'type' => 'status_badge'],
                ['key' => 'validity',     'label' => 'Validity',              'type' => 'validity_badge'],
            ],
            'doc_url'           => 'https://swiyu-admin-ch.github.io/specifications/trust-protocol-v2-0/#protected-issuance-authorization-trust-statement-piats',
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
                ['key' => 'iat',               'label' => t('col.issued_at'),    'type' => 'unix'],
                ['key' => '_status_value',     'label' => 'Status',              'type' => 'status_badge'],
                ['key' => 'validity',          'label' => 'Validity',            'type' => 'validity_badge'],
            ],
            'doc_url'           => 'https://swiyu-admin-ch.github.io/specifications/trust-protocol-v2-0/#protected-verification-authorization-trust-statement-pvats',
        ],

    ],

    // Anzahl Einträge pro angezeigter Seite (nach Suche/Filterung) — Startwert,
    // wird bei jedem Umgebungs-/API-Wechsel wieder auf diesen Wert zurückgesetzt.
    'page_size' => 20,

    // Zur Auswahl stehende Seitengrössen (Dropdown in der Pagination-Leiste)
    'page_size_options' => [20, 50, 100, 200],

    // Wie lange der Session-Cache pro Umgebung+API gültig ist, bevor
    // ohne expliziten "Refresh"-Klick automatisch neu geladen wird (Sekunden)
    'cache_ttl' => 300,

    // Wie viele Treffer die APIs-weite Suche PRO API ausgeschrieben zeigt.
    // Die Trefferzahl im Badge ist davon unberührt - gedeckelt wird nur, wie
    // viele Einträge vollständig gerendert werden.
    //
    // Hintergrund: die Suche über alle APIs beantwortet die Frage "wo kommt
    // das vor?". Bei einer DID sind das eine Handvoll Treffer, bei einem
    // Allerweltsbegriff ("swiyu") können es mehrere hundert sein - alle
    // ausgeschrieben ergäbe das eine Seite von zweistelliger Megabyte-Grösse.
    // Darüber hinaus führt "In der Tabelle zeigen" in die paginierte Liste,
    // die für grosse Mengen das richtige Werkzeug ist.
    'search_max_detail_hits' => 5,

    // Dokumentations-Links: generischer Overview-Link (im Seitenkopf, API-
    // unabhängig) sowie pro API ein 'doc_url' (siehe oben bei den APIs),
    // der als "Spec"-Button in der Toolbar neben "Refresh" erscheint.
    'docs' => [
        'overview_url' => 'https://swiyu-admin-ch.github.io/introduction/#trust-in-the-swiyu-ecosystem',
    ],

    // History-Feature: Ablage der nächtlich per Cronjob (collect.php)
    // gesammelten Trust-Statement-Zählungen (SQLite, filebasiert).
    'history' => [
        // Pfad zur SQLite-Datenbank. Verzeichnis muss für den Cron-User
        // (collect.php) und den Webserver-User (history.php) beschreibbar
        // bzw. lesbar sein.
        'db_path' => __DIR__ . '/storage/history.sqlite',

        // Zeitraum-Presets für die History-Ansicht (Dropdown), Schlüssel
        // 'custom' schaltet die Von/Bis-Datumsfelder frei.
        'range_options' => [
            '7d'     => t('range.7d'),
            '30d'    => t('range.30d'),
            '90d'    => t('range.90d'),
            '1y'     => t('range.1y'),
            'all'    => t('range.all'),
            'custom' => t('range.custom'),
        ],
    ],
];
