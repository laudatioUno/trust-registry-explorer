/*
 * Base-Registry-Frontend (nur index.php).
 *
 *  - Spalte "Base Registry" der idTS-Tabelle: jede Zelle (td.br-cell[data-br-did])
 *    lädt ihren Chip ("1 version", "Not found", ...) per fetch() von
 *    base_registry.php, sobald sie in die Nähe des Viewports kommt (max. 4
 *    parallele Abrufe) - es werden also nur sichtbare Zeilen abgefragt.
 *  - Klick auf den Chip klappt die Zeile auf und zeigt den Tab "Base Registry"
 *    (lädt Details + Rohlog beim ersten Öffnen nach).
 *  - Dialog "View DID log" in der Suche (natives <dialog>, serverseitig gerendert).
 *  - Kopieren des Rohlogs und einzelner Schlüssel (JWK/PEM) in die Zwischenablage.
 *
 * Konfiguration kommt von index.php:
 *   window.BR_CONFIG = { endpoint: 'base_registry.php', env: 'REF',
 *                        i18n: { loading, unavailable, copied, copyFailed } };
 */
(function () {
    'use strict';

    var cfg = window.BR_CONFIG || {};
    var i18n = cfg.i18n || {};
    var MAX_PARALLEL = 4;

    /* ---------- kleine Helfer ---------- */

    function requestUrl(did, view, refresh) {
        var p = new URLSearchParams({ env: cfg.env || '', did: did, view: view });
        if (refresh) p.set('refresh', '1');
        return (cfg.endpoint || 'base_registry.php') + '?' + p.toString();
    }

    function getJson(url) {
        return fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
            .then(function (r) {
                if (!r.ok) throw new Error('HTTP ' + r.status);
                return r.json();
            });
    }

    function loadingNode() {
        var wrap = document.createElement('span');
        wrap.className = 'br-loading';
        var spin = document.createElement('span');
        spin.className = 'br-spin';
        wrap.appendChild(spin);
        wrap.appendChild(document.createTextNode(i18n.loading || '…'));
        return wrap;
    }

    function setLoading(el) {
        el.textContent = '';
        el.appendChild(loadingNode());
    }

    function unavailableChip() {
        var b = document.createElement('button');
        b.type = 'button';
        b.className = 'br-chip br-unavailable';
        b.setAttribute('data-br-open', '');
        b.textContent = i18n.unavailable || '?';
        return b;
    }

    /* ---------- Warteschlange (max. N parallele Abrufe) ---------- */

    var queue = [];
    var active = 0;

    function enqueue(task) {
        queue.push(task);
        pump();
    }

    function pump() {
        while (active < MAX_PARALLEL && queue.length) {
            var task = queue.shift();
            active++;
            task().then(finished, finished);
        }
    }

    function finished() {
        active--;
        pump();
    }

    /* ---------- Tabellenzellen (Chip) ---------- */

    function rowOf(el) {
        return el.closest('tr.entry-row');
    }

    function detailOf(row) {
        var d = row ? row.nextElementSibling : null;
        return d && d.classList.contains('detail-row') ? d : null;
    }

    function syncTabChip(cell, data) {
        var detail = detailOf(rowOf(cell));
        var chip = detail ? detail.querySelector('[data-br-tab-chip]') : null;
        if (chip) chip.textContent = data.label || '';
    }

    function loadCell(cell, refresh) {
        setLoading(cell);
        enqueue(function () {
            return getJson(requestUrl(cell.getAttribute('data-br-did'), 'chip', refresh))
                .then(function (data) {
                    cell.innerHTML = data.html; // serverseitig escaped (functions.php)
                    syncTabChip(cell, data);
                })
                .catch(function () {
                    cell.textContent = '';
                    cell.appendChild(unavailableChip());
                });
        });
    }

    function initCells() {
        var cells = document.querySelectorAll('td.br-cell[data-br-did]');
        if (!cells.length) return;

        if (!('IntersectionObserver' in window)) {
            cells.forEach(function (c) { loadCell(c, false); });
            return;
        }

        var observer = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (!entry.isIntersecting) return;
                observer.unobserve(entry.target);
                loadCell(entry.target, false);
            });
        }, { rootMargin: '300px 0px' });

        cells.forEach(function (c) {
            setLoading(c);
            observer.observe(c);
        });
    }

    /* ---------- Detailzeile: Tabs + Nachladen ---------- */

    function loadPane(pane, refresh) {
        setLoading(pane);
        pane.setAttribute('data-br-loaded', '1');
        return getJson(requestUrl(pane.getAttribute('data-br-did'), 'detail', refresh))
            .then(function (data) { pane.innerHTML = data.html; })
            .catch(function () {
                pane.textContent = '';
                pane.appendChild(unavailableChip());
                pane.removeAttribute('data-br-loaded');
            });
    }

    function activateTab(detail, name) {
        detail.querySelectorAll('[data-br-tab]').forEach(function (b) {
            b.classList.toggle('active', b.getAttribute('data-br-tab') === name);
            b.setAttribute('aria-selected', b.getAttribute('data-br-tab') === name ? 'true' : 'false');
        });
        detail.querySelectorAll('[data-br-pane-name]').forEach(function (p) {
            p.hidden = p.getAttribute('data-br-pane-name') !== name;
            if (!p.hidden && name === 'base' && !p.hasAttribute('data-br-loaded')) loadPane(p, false);
        });
    }

    function openRow(row) {
        var detail = detailOf(row);
        if (!detail) return null;
        if (!detail.classList.contains('open')) {
            detail.classList.add('open');
            var caret = row.querySelector('.caret');
            if (caret) caret.innerHTML = '&#9662;';
        }
        return detail;
    }

    /* ---------- Dialog / Kopieren ---------- */

    /*
     * Fallback über eine unsichtbare Textarea (ohne Clipboard-API, z.B. http
     * über eine IP statt localhost). Die Textarea muss neben dem Button liegen:
     * Bei einem offenen modalen <dialog> ist alles ausserhalb inert, eine
     * Auswahl dort schlägt fehl - execCommand meldet trotzdem Erfolg.
     */
    function copyViaTextarea(text, near) {
        return new Promise(function (resolve, reject) {
            var host = (near && near.closest('dialog')) || document.body;
            var ta = document.createElement('textarea');
            ta.value = text;
            ta.setAttribute('readonly', '');
            ta.style.position = 'fixed';
            ta.style.top = '0';
            ta.style.left = '0';
            ta.style.opacity = '0';
            host.appendChild(ta);
            ta.select();
            try { document.execCommand('copy') ? resolve() : reject(); } catch (e) { reject(e); }
            host.removeChild(ta);
            if (near && near.focus) near.focus();
        });
    }

    /** Clipboard-API, bei Fehlen oder Ablehnung (Berechtigung, Fokus) der Fallback. */
    function copyText(text, near) {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            return navigator.clipboard.writeText(text).catch(function () {
                return copyViaTextarea(text, near);
            });
        }
        return copyViaTextarea(text, near);
    }

    /*
     * Live-Region für Screenreader: Der Zustand des Buttons ist nur über Farbe
     * und Icon sichtbar, das Ergebnis wird deshalb zusätzlich angesagt.
     */
    var liveRegion = null;

    function announce(message) {
        if (!liveRegion) {
            liveRegion = document.createElement('span');
            liveRegion.className = 'br-sr';
            liveRegion.setAttribute('role', 'status');
            liveRegion.setAttribute('aria-live', 'polite');
            document.body.appendChild(liveRegion);
        }
        liveRegion.textContent = '';
        // Kurz verzögert, damit dieselbe Meldung zweimal hintereinander erneut angesagt wird.
        setTimeout(function () { liveRegion.textContent = message; }, 50);
    }

    /**
     * Kopiert und zeigt das Ergebnis am Button selbst an (Klasse br-copied bzw.
     * br-copy-failed: Farbe + Häkchen/Kreuz statt Zwischenablage-Icon). Die
     * Beschriftung bleibt unverändert, damit die Tabellenzeile nicht springt.
     */
    function copyWithFeedback(button, text) {
        if (button.hasAttribute('data-br-copying')) return;
        button.setAttribute('data-br-copying', '');

        function show(cls, message, duration) {
            button.classList.add(cls);
            announce(message);
            setTimeout(function () {
                button.classList.remove(cls);
                button.removeAttribute('data-br-copying');
            }, duration);
        }

        copyText(text, button).then(function () {
            show('br-copied', i18n.copied || 'OK', 1500);
        }, function () {
            show('br-copy-failed', i18n.copyFailed || '!', 2500);
        });
    }

    /* ---------- Klick-Handling (Capture-Phase, siehe Kommentar unten) ---------- */

    /*
     * Capture-Phase, weil die Tabellenzeile selbst einen Klick-Handler hat
     * (Zeile auf-/zuklappen): Ein Klick auf den Chip soll die Zeile aber
     * IMMER öffnen und den Tab wählen - nicht togglen. Mit stopPropagation in
     * der Capture-Phase erreicht der Klick den Zeilen-Handler gar nicht erst.
     */
    document.addEventListener('click', function (e) {
        var t = e.target;
        if (!(t instanceof Element)) return;

        var chip = t.closest('td.br-cell [data-br-open]');
        if (chip) {
            e.stopPropagation();
            var row = rowOf(chip);
            var detail = openRow(row);
            if (detail) activateTab(detail, 'base');
            return;
        }

        var tab = t.closest('.detail-tabs [data-br-tab]');
        if (tab) {
            var d = tab.closest('tr.detail-row');
            if (d) activateTab(d, tab.getAttribute('data-br-tab'));
            return;
        }

        var retry = t.closest('[data-br-retry]');
        if (retry) {
            var pane = retry.closest('[data-br-pane-name]');
            if (pane) {
                loadPane(pane, true);
                var drow = pane.closest('tr.detail-row');
                var cell = drow && drow.previousElementSibling ? drow.previousElementSibling.querySelector('td.br-cell') : null;
                if (cell) loadCell(cell, true);
            }
            return;
        }

        var opener = t.closest('[data-br-dialog-open]');
        if (opener) {
            var dlg = document.getElementById(opener.getAttribute('data-br-dialog-open'));
            if (dlg && typeof dlg.showModal === 'function') dlg.showModal();
            return;
        }

        var closer = t.closest('[data-br-dialog-close]');
        if (closer) {
            var dlg2 = closer.closest('dialog');
            if (dlg2) dlg2.close();
            return;
        }

        if (t.tagName === 'DIALOG' && t.classList.contains('br-dialog')) {
            t.close(); // Klick auf den abgedunkelten Hintergrund
            return;
        }

        var view = t.closest('[data-br-view]');
        if (view) {
            var dlg3 = view.closest('dialog');
            if (!dlg3) return;
            var name = view.getAttribute('data-br-view');
            dlg3.querySelectorAll('[data-br-view]').forEach(function (b) {
                b.classList.toggle('active', b === view);
            });
            dlg3.querySelectorAll('[data-br-pane]').forEach(function (p) {
                p.hidden = p.getAttribute('data-br-pane') !== name;
            });
            return;
        }

        // Schlüssel als JWK/PEM: der Wert steht serverseitig im Attribut.
        var keyCopy = t.closest('[data-br-copy-value]');
        if (keyCopy) {
            e.stopPropagation();
            copyWithFeedback(keyCopy, keyCopy.getAttribute('data-br-copy-value'));
            return;
        }

        var copy = t.closest('[data-br-copy]');
        if (copy) {
            var pre = copy.closest('.br-raw-wrap');
            pre = pre ? pre.querySelector('pre.br-raw') : null;
            if (!pre) return;
            copyWithFeedback(copy, pre.textContent);
        }
    }, true);

    document.addEventListener('DOMContentLoaded', initCells);
})();
