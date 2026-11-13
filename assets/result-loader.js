/*
 * Lädt den Ergebnisbereich von index.php nach (Skeleton -> Tabelle).
 *
 * Ist die Tabelle nicht im Session-Cache, liefert der Server nur den Seiten-
 * rahmen samt Skeleton aus (<div id="treResult" data-pending>). Dieses Skript
 * holt danach denselben URL mit ?fragment=1 per fetch() und ersetzt den
 * Inhalt. Es braucht kein Server-Streaming und verhält sich in allen Browsern
 * gleich. Bei einem Fehler erscheint eine Meldung mit "Erneut versuchen".
 *
 * Andere Skripte können mit TrustExplorer.whenResultReady(cb) warten, bis der
 * Bereich geladen ist (z.B. die Tour); ohne Nachladen läuft cb sofort.
 */
(function () {
    'use strict';

    var TE = window.TrustExplorer = window.TrustExplorer || {};
    var host = document.getElementById('treResult');
    var waiters = [];
    var TIMEOUT_MS = 120000;

    function isPending() {
        return !!host && host.hasAttribute('data-pending');
    }

    TE.whenResultReady = function (cb) {
        if (isPending()) waiters.push(cb);
        else cb();
    };

    function finish() {
        if (!isPending()) return;
        host.removeAttribute('data-pending');
        waiters.splice(0).forEach(function (cb) {
            try { cb(); } catch (e) { /* ein fehlerhafter Wartender darf die anderen nicht stoppen */ }
        });
    }

    // <script>-Elemente, die per innerHTML eingefügt wurden, laufen nicht von
    // selbst: durch frische Elemente ersetzen, damit sie ausgeführt werden.
    function runScripts(root) {
        root.querySelectorAll('script').forEach(function (old) {
            var s = document.createElement('script');
            for (var i = 0; i < old.attributes.length; i++) {
                s.setAttribute(old.attributes[i].name, old.attributes[i].value);
            }
            s.text = old.textContent;
            old.parentNode.replaceChild(s, old);
        });
    }

    function showError() {
        host.textContent = '';
        var box = document.createElement('div');
        box.className = 'error';
        box.setAttribute('role', 'alert');
        box.appendChild(document.createTextNode((host.getAttribute('data-err') || 'Error') + ' '));
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'toolbar-btn';
        btn.textContent = host.getAttribute('data-retry') || 'Retry';
        btn.addEventListener('click', function () {
            location.reload();
        });
        box.appendChild(btn);
        host.appendChild(box);
    }

    function load() {
        var url = new URL(window.location.href);
        url.searchParams.set('fragment', '1');
        url.hash = '';

        var ctrl = typeof AbortController === 'function' ? new AbortController() : null;
        var timer = ctrl ? setTimeout(function () { ctrl.abort(); }, TIMEOUT_MS) : 0;

        var opts = { credentials: 'same-origin', cache: 'no-store', headers: { 'Accept': 'text/html' } };
        if (ctrl) opts.signal = ctrl.signal;

        fetch(url.toString(), opts)
            .then(function (r) {
                if (!r.ok) throw new Error('HTTP ' + r.status);
                return r.text();
            })
            .then(function (html) {
                clearTimeout(timer);
                host.innerHTML = html;
                runScripts(host);
                if (typeof TE.initBaseRegistry === 'function') TE.initBaseRegistry();
            })
            .catch(function () {
                clearTimeout(timer);
                showError();
            })
            .then(finish);
    }

    if (isPending()) load();
})();
