/*
 * Umschalter "Aufbereitet | Rohdaten" in den Detailansichten (index.php,
 * explorer.php) und
 * Kopieren-Knöpfe der Rohdaten-Blöcke. Reine Event-Delegation, keine Konfiguration.
 */
(function () {
    'use strict';

    function copyText(text) {
        if (navigator.clipboard && window.isSecureContext) {
            return navigator.clipboard.writeText(text);
        }
        return new Promise(function (resolve, reject) {
            var ta = document.createElement('textarea');
            ta.value = text;
            ta.setAttribute('readonly', '');
            ta.style.position = 'fixed';
            ta.style.opacity = '0';
            document.body.appendChild(ta);
            ta.select();
            try { document.execCommand('copy') ? resolve() : reject(); } catch (e) { reject(e); }
            document.body.removeChild(ta);
        });
    }

    document.addEventListener('click', function (e) {
        var t = e.target;
        if (!(t instanceof Element)) return;

        var view = t.closest('[data-raw-view]');
        if (view) {
            var root = view.closest('.raw-root');
            if (!root) return;
            var name = view.getAttribute('data-raw-view');
            root.querySelectorAll('[data-raw-view]').forEach(function (b) {
                b.classList.toggle('active', b === view);
            });
            root.querySelectorAll('[data-raw-pane]').forEach(function (p) {
                p.hidden = p.getAttribute('data-raw-pane') !== name;
            });
            return;
        }

        var copy = t.closest('[data-raw-copy]');
        if (copy) {
            var wrap = copy.closest('.raw-block');
            var pre = wrap ? wrap.querySelector('pre') : null;
            if (!pre) return;
            var original = copy.textContent;
            copyText(pre.textContent).then(function () {
                copy.textContent = copy.getAttribute('data-copied') || 'OK';
                setTimeout(function () { copy.textContent = original; }, 1500);
            }).catch(function () { /* Kopieren nicht möglich: still ignorieren */ });
        }
    });
})();
