/*
 * Geführte Tour durch den Explorer.
 *
 *  - Startet automatisch beim allerersten Besuch (Merker im Browser, siehe
 *    seen()/markSeen()) und danach nie wieder von selbst, auch nicht nach
 *    neuen Versionen: der Merker enthält bewusst keine Versionsnummer.
 *  - Erneut starten: "?"-Button im Seitenkopf, Link mit ?tour=1 oder die
 *    "Tour wiederholen"-Leiste am Ende.
 *  - Texte kommen als JSON aus lang.php (#tour-data), Schritte sind reine
 *    Konfiguration (STEPS). Fehlt das Zielelement eines Schritts, wird er
 *    übersprungen.
 *  - Der aktuelle Schritt liegt in sessionStorage: So überlebt die Tour den
 *    Sprachwechsel und das automatische Laden von idTS (Seitenwechsel).
 */
(function () {
    'use strict';

    var dataEl = document.getElementById('tour-data');
    if (!dataEl) return;
    var T = JSON.parse(dataEl.textContent);

    var SEEN_KEY = 'tre.tour.seen';
    var SEEN_COOKIE = 'treTourSeen';
    var STATE_KEY = 'tre.tour.state';
    var STATE_MAX_AGE_MS = 10 * 60 * 1000;

    // ---- Merker "Tour gesehen" ----------------------------------------
    // localStorage; nur wenn es nicht verfügbar ist (z.B. blockiert), ein Cookie.
    function seen() {
        try { if (window.localStorage.getItem(SEEN_KEY)) return true; } catch (e) { /* weiter zum Cookie */ }
        return document.cookie.split('; ').indexOf(SEEN_COOKIE + '=1') !== -1;
    }
    function markSeen() {
        try {
            window.localStorage.setItem(SEEN_KEY, '1');
        } catch (e) {
            document.cookie = SEEN_COOKIE + '=1; max-age=31536000; path=/; SameSite=Lax';
        }
    }

    // ---- Zustand über Seitenwechsel hinweg ------------------------------
    function readState() {
        try {
            var s = JSON.parse(window.sessionStorage.getItem(STATE_KEY) || 'null');
            if (s && typeof s.step === 'number' && Date.now() - s.ts < STATE_MAX_AGE_MS) return s;
        } catch (e) { /* ignorieren */ }
        return null;
    }
    function writeState(step, autoLoaded) {
        try { window.sessionStorage.setItem(STATE_KEY, JSON.stringify({ step: step, auto: !!autoLoaded, ts: Date.now() })); } catch (e) { /* ignorieren */ }
    }
    function clearState() {
        try { window.sessionStorage.removeItem(STATE_KEY); } catch (e) { /* ignorieren */ }
    }

    // ---- Schritte (Konfiguration) ---------------------------------------
    function byTour(name) {
        return Array.prototype.slice.call(document.querySelectorAll('[data-tour="' + name + '"]'));
    }
    function firstRow() { return document.querySelector('tr.entry-row.expandable'); }
    function rowTargets() {
        var row = firstRow();
        if (!row) return [];
        var detail = row.nextElementSibling;
        var list = [row];
        if (detail && detail.classList.contains('detail-row') && detail.classList.contains('open')) list.push(detail);
        return list;
    }
    function hasApiSelected() { return !!document.querySelector('.api-chips a.active'); }

    var STEPS = [
        { id: 's1', targets: function () { return byTour('env'); } },
        { id: 's2', targets: function () { return byTour('apis'); }, list: ['idTS', 'piTLS · piaTS', 'vqPS · pvaTS', 'ncTLS'] },
        { id: 's3', targets: rowTargets, needsData: true, onEnter: openFirstRow },
        { id: 's4', targets: function () { return byTour('filter'); }, needsData: true },
        { id: 's5', targets: function () { return byTour('nav'); } },
        { id: 's6', targets: function () { return byTour('search'); } },
        { id: 's7', targets: function () { return byTour('help'); } }
    ];

    var rootEl, shieldEl, spotEl, popEl, liveEl, endEl;
    var current = -1;          // Index in STEPS
    var autoLoaded = false;    // idTS wurde von der Tour geladen (verhindert Schleifen)
    var openedRow = null;      // von der Tour aufgeklappte Zeile
    var lastFocus = null;

    function fmt(template, a, b) { return template.replace('%d', a).replace('%d', b); }
    function reducedMotion() { return window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches; }
    function isMobile() { return window.matchMedia && window.matchMedia('(max-width: 640px)').matches; }

    function el(tag, cls, text) {
        var n = document.createElement(tag);
        if (cls) n.className = cls;
        if (text != null) n.textContent = text;
        return n;
    }
    function button(label, cls, handler) {
        var b = el('button', cls, label);
        b.type = 'button';
        b.addEventListener('click', handler);
        return b;
    }

    // ---- Verfügbarkeit -------------------------------------------------
    function visible(nodes) {
        return nodes.filter(function (n) { var r = n.getBoundingClientRect(); return r.width > 0 && r.height > 0; });
    }
    // Ein Schritt ist verfügbar, wenn sein Ziel da ist, oder wenn er Daten
    // braucht, noch keine API gewählt ist und idTS nachgeladen werden kann.
    function available(i) {
        var step = STEPS[i];
        if (step.needsData && !hasApiSelected() && !autoLoaded && idtsLink()) return true;
        if (step.id === 's3') return !!firstRow();
        return visible(step.targets()).length > 0;
    }
    function availableIndexes() {
        var out = [];
        for (var i = 0; i < STEPS.length; i++) if (available(i)) out.push(i);
        return out;
    }
    function idtsLink() {
        return document.querySelector('.api-chips a[data-api="idTS"]') || document.querySelector('.api-chips a[data-api]');
    }

    // ---- Aufbau --------------------------------------------------------
    function ensureRoot() {
        if (rootEl) return;
        rootEl = el('div', 'tour-root');
        shieldEl = el('div', 'tour-shield');
        spotEl = el('div', 'tour-spot');
        spotEl.hidden = true;
        liveEl = el('div', 'tour-sr');
        liveEl.setAttribute('aria-live', 'polite');
        liveEl.setAttribute('role', 'status');
        rootEl.appendChild(shieldEl);
        rootEl.appendChild(spotEl);
        rootEl.appendChild(liveEl);
        document.body.appendChild(rootEl);
        document.body.classList.add('tour-active');
        document.addEventListener('keydown', onKey, true);
        window.addEventListener('resize', reposition);
        window.addEventListener('scroll', reposition, true);
    }

    function removePop() {
        if (popEl && popEl.parentNode) popEl.parentNode.removeChild(popEl);
        popEl = null;
    }
    function removeEndbar() {
        if (endEl && endEl.parentNode) endEl.parentNode.removeChild(endEl);
        endEl = null;
    }

    function showWelcome() {
        removeEndbar();
        ensureRoot();
        current = -1;
        shieldEl.className = 'tour-shield dim';
        spotEl.hidden = true;
        removePop();
        lastFocus = lastFocus || document.activeElement;

        popEl = el('div', 'tour-pop tour-welcome');
        popEl.setAttribute('role', 'dialog');
        popEl.setAttribute('aria-modal', 'true');
        popEl.setAttribute('aria-labelledby', 'tour-title');
        popEl.setAttribute('aria-describedby', 'tour-body');
        var h = el('h2', null, T['welcome.title']); h.id = 'tour-title';
        var p = el('p', null, T['welcome.body']); p.id = 'tour-body';
        var hint = el('p', 'tour-hint', T['welcome.hint']);
        var nav = el('div', 'tour-nav');
        var start = button(T['welcome.start'], 'tour-btn primary', function () { goTo(firstAvailable(0), 1); });
        nav.appendChild(button(T['welcome.skip'], 'tour-btn', end));
        nav.appendChild(start);
        popEl.appendChild(h); popEl.appendChild(p); popEl.appendChild(hint); popEl.appendChild(nav);
        rootEl.appendChild(popEl);
        liveEl.textContent = T['welcome.title'];
        start.focus();
    }

    function firstAvailable(from) {
        for (var i = from; i < STEPS.length; i++) if (available(i)) return i;
        return -1;
    }
    function lastAvailableBefore(idx) {
        for (var i = idx - 1; i >= 0; i--) if (available(i)) return i;
        return -1;
    }

    function closeOpenedRow() {
        if (openedRow && openedRow.nextElementSibling && openedRow.nextElementSibling.classList.contains('open')) {
            openedRow.click();
        }
        openedRow = null;
    }
    function openFirstRow() {
        var row = firstRow();
        if (!row) return;
        var detail = row.nextElementSibling;
        if (detail && detail.classList.contains('detail-row') && !detail.classList.contains('open')) {
            row.click();
            openedRow = row;
        }
    }

    // direction: 1 vor, -1 zurück (steuert, wohin übersprungene Schritte laufen)
    function goTo(idx, direction) {
        if (idx < 0 || idx >= STEPS.length) { finish(); return; }
        var step = STEPS[idx];

        // Daten nötig, aber keine API gewählt: idTS laden, Tour setzt danach fort.
        if (step.needsData && !hasApiSelected() && !autoLoaded) {
            var link = idtsLink();
            if (link) {
                writeState(idx, true);
                window.location.href = link.href;
                return;
            }
        }
        if (idx !== 2 && openedRow) closeOpenedRow();
        if (!available(idx)) {
            var next = direction < 0 ? lastAvailableBefore(idx) : firstAvailable(idx + 1);
            if (next < 0) { if (direction < 0) { showWelcome(); } else { finish(); } return; }
            goTo(next, direction);
            return;
        }
        current = idx;
        writeState(idx, autoLoaded);
        if (step.onEnter) step.onEnter();
        renderStep();
    }

    function renderStep() {
        ensureRoot();
        removeEndbar();
        var idx = current, step = STEPS[idx];
        var plan = availableIndexes();
        var pos = plan.indexOf(idx);
        var total = plan.length;
        var isLast = pos === total - 1;
        var stepText = fmt(T.step, pos + 1, total);

        shieldEl.className = 'tour-shield';
        removePop();

        popEl = el('div', 'tour-pop');
        popEl.setAttribute('role', 'dialog');
        popEl.setAttribute('aria-modal', 'true');
        popEl.setAttribute('aria-labelledby', 'tour-title');
        popEl.setAttribute('aria-describedby', 'tour-body');

        var head = el('div', 'tour-head');
        head.appendChild(el('span', 'tour-badge', stepText));
        head.appendChild(button(T.close, 'tour-link', end));
        var h = el('h2', null, T[step.id + '.title']); h.id = 'tour-title';
        var p = el('p', null, T[step.id + '.body']); p.id = 'tour-body';
        popEl.appendChild(head); popEl.appendChild(h); popEl.appendChild(p);

        if (step.list) {
            var dl = el('dl', 'tour-list');
            step.list.forEach(function (code, i) {
                var row = el('div');
                row.appendChild(el('dt', null, code));
                row.appendChild(el('dd', null, T[step.id + '.l' + (i + 1)]));
                dl.appendChild(row);
            });
            popEl.appendChild(dl);
        }

        var dots = el('div', 'tour-dots');
        dots.setAttribute('role', 'group');
        plan.forEach(function (stepIdx, i) {
            var d = el('button', 'tour-dot' + (stepIdx === idx ? ' active' : ''));
            d.type = 'button';
            d.setAttribute('aria-label', fmt(T.step, i + 1, total));
            if (stepIdx === idx) d.setAttribute('aria-current', 'step');
            d.addEventListener('click', function () { goTo(stepIdx, stepIdx > idx ? 1 : -1); });
            dots.appendChild(d);
        });
        popEl.appendChild(dots);

        var nav = el('div', 'tour-nav');
        nav.appendChild(button(T.prev, 'tour-btn', function () { goTo(lastAvailableBefore(idx), -1); }));
        var nextBtn = button(isLast ? T.done : T.next, 'tour-btn primary', function () {
            if (isLast) finish(); else goTo(firstAvailable(idx + 1), 1);
        });
        nav.appendChild(nextBtn);
        popEl.appendChild(nav);
        rootEl.appendChild(popEl);

        liveEl.textContent = stepText + ': ' + T[step.id + '.title'];
        reveal(step);
        reposition();
        nextBtn.focus({ preventScroll: true });
    }

    // ---- Spotlight und Platzierung --------------------------------------
    function unionRect(nodes) {
        var rect = null;
        nodes.forEach(function (n) {
            var r = n.getBoundingClientRect();
            if (r.width <= 0 && r.height <= 0) return;
            if (!rect) { rect = { left: r.left, top: r.top, right: r.right, bottom: r.bottom }; return; }
            rect.left = Math.min(rect.left, r.left); rect.top = Math.min(rect.top, r.top);
            rect.right = Math.max(rect.right, r.right); rect.bottom = Math.max(rect.bottom, r.bottom);
        });
        return rect;
    }

    // Zielelement in den sichtbaren Bereich holen (oberhalb des Bottom Sheets mobil).
    function reveal(step) {
        var rect = unionRect(step.targets());
        if (!rect) return;
        var vh = window.innerHeight;
        var reserved = isMobile() && popEl ? popEl.getBoundingClientRect().height + 16 : 0;
        var avail = vh - reserved;
        var needsScroll = rect.top < 8 || rect.bottom > avail - 8;
        if (!needsScroll) return;
        var h = rect.bottom - rect.top;
        var delta = h < avail - 80 ? (rect.top + h / 2) - avail / 2 : rect.top - 60;
        window.scrollBy(0, delta);
    }

    function reposition() {
        if (!spotEl) return;
        if (current < 0 || !popEl || popEl.classList.contains('tour-welcome')) { spotEl.hidden = true; return; }
        var rect = unionRect(STEPS[current].targets());
        if (!rect) { spotEl.hidden = true; return; }
        var pad = 6;
        spotEl.hidden = false;
        spotEl.style.left = (rect.left - pad) + 'px';
        spotEl.style.top = (rect.top - pad) + 'px';
        spotEl.style.width = (rect.right - rect.left + pad * 2) + 'px';
        spotEl.style.height = (rect.bottom - rect.top + pad * 2) + 'px';

        if (isMobile()) { popEl.style.top = popEl.style.left = ''; return; }
        var vw = window.innerWidth, vh = window.innerHeight;
        var pw = popEl.offsetWidth, ph = popEl.offsetHeight, gap = 14;
        var left = Math.max(12, Math.min(rect.left, vw - pw - 12));
        var top;
        if (rect.bottom + pad + gap + ph <= vh - 12) top = rect.bottom + pad + gap;       // unterhalb
        else if (rect.top - pad - gap - ph >= 12) top = rect.top - pad - gap - ph;       // oberhalb
        else top = Math.max(12, vh - ph - 16);                                            // Notlösung: unten andocken
        popEl.style.left = left + 'px';
        popEl.style.top = top + 'px';
    }

    // ---- Ende ----------------------------------------------------------
    function teardown() {
        closeOpenedRow();
        removePop();
        if (rootEl && rootEl.parentNode) rootEl.parentNode.removeChild(rootEl);
        rootEl = shieldEl = spotEl = liveEl = null;
        current = -1;
        document.body.classList.remove('tour-active');
        document.removeEventListener('keydown', onKey, true);
        window.removeEventListener('resize', reposition);
        window.removeEventListener('scroll', reposition, true);
        clearState();
        if (lastFocus && lastFocus.focus) { try { lastFocus.focus({ preventScroll: true }); } catch (e) { /* ignorieren */ } }
        lastFocus = null;
    }

    // Abbrechen (Überspringen, "Tour beenden", Esc)
    function end() {
        markSeen();
        teardown();
    }

    // Regulärer Abschluss: ruhige Leiste mit "Tour wiederholen"
    function finish() {
        markSeen();
        closeOpenedRow();
        removePop();
        if (rootEl && rootEl.parentNode) rootEl.parentNode.removeChild(rootEl);
        rootEl = null;
        current = -1;
        document.body.classList.remove('tour-active');
        document.removeEventListener('keydown', onKey, true);
        window.removeEventListener('resize', reposition);
        window.removeEventListener('scroll', reposition, true);
        clearState();

        endEl = el('div', 'tour-endbar');
        endEl.setAttribute('role', 'status');
        endEl.appendChild(el('span', null, T.done_note));
        endEl.appendChild(button(T.again, 'tour-btn', function () { removeEndbar(); showWelcome(); }));
        endEl.appendChild(button('×', 'tour-btn', removeEndbar));
        endEl.lastChild.setAttribute('aria-label', T.close);
        document.body.appendChild(endEl);
        if (lastFocus && lastFocus.focus) { try { lastFocus.focus({ preventScroll: true }); } catch (e) { /* ignorieren */ } }
        lastFocus = null;
    }

    // ---- Tastatur ------------------------------------------------------
    function onKey(e) {
        if (!popEl) return;
        if (e.key === 'Escape') { e.preventDefault(); end(); return; }
        if (e.key === 'Tab') {
            var f = popEl.querySelectorAll('button');
            if (!f.length) return;
            var first = f[0], last = f[f.length - 1];
            if (!popEl.contains(document.activeElement)) { e.preventDefault(); first.focus(); }
            else if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
            else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
            return;
        }
        if (current < 0) return;
        if (e.key === 'ArrowRight') { e.preventDefault(); goTo(firstAvailable(current + 1), 1); }
        if (e.key === 'ArrowLeft') { e.preventDefault(); goTo(lastAvailableBefore(current), -1); }
    }

    // ---- Start ---------------------------------------------------------
    function stripTourParam() {
        try {
            var url = new URL(window.location.href);
            if (url.searchParams.has('tour')) {
                url.searchParams.delete('tour');
                window.history.replaceState(null, '', url.pathname + url.search + url.hash);
            }
        } catch (e) { /* ignorieren */ }
    }

    function init() {
        var helpBtn = document.querySelector('.tour-help');
        if (helpBtn) {
            helpBtn.addEventListener('click', function (e) {
                e.preventDefault();
                if (rootEl) return;
                lastFocus = helpBtn;
                showWelcome();
            });
        }

        var state = readState();
        var wantsTour = /(^|[?&])tour=1(&|$)/.test(window.location.search);
        if (wantsTour) stripTourParam();

        if (state) {
            // Tour läuft schon (Sprachwechsel oder automatisch geladenes idTS).
            autoLoaded = !!state.auto || hasApiSelected();
            lastFocus = helpBtn;
            var idx = firstAvailable(state.step);
            if (idx < 0) { clearState(); return; }
            goTo(idx, 1);
        } else if (wantsTour) {
            lastFocus = helpBtn;
            showWelcome();
        } else if (!seen()) {
            markSeen();   // sofort setzen: Die Tour erscheint nie ein zweites Mal von selbst
            showWelcome();
        }
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
})();
