/*
 * Leerer Tabellenzustand: kleine Extras. Wird nur geladen, wenn die Tabelle leer ist.
 * Konfiguration von index.php: window.UX_CONFIG = { endpoint, i18n: {...} }
 */
(function () {
    'use strict';

    var cfg = window.UX_CONFIG || {};
    var t = cfg.i18n || {};
    var endpoint = cfg.endpoint || 'ui_extras.php';
    var SYMBOLS = ['🔑', '🪪', '📜', '🛡️', '🔒', '🌐', '✅', '🧾'];
    var dialog = null;

    function el(tag, cls, text) {
        var n = document.createElement(tag);
        if (cls) n.className = cls;
        if (text !== undefined) n.textContent = text;
        return n;
    }

    function shuffle(a) {
        for (var i = a.length - 1; i > 0; i--) {
            var j;
            if (window.crypto && crypto.getRandomValues) {
                var buf = new Uint32Array(1);
                crypto.getRandomValues(buf);
                j = buf[0] % (i + 1);
            } else {
                j = Math.floor(Math.random() * (i + 1));
            }
            var tmp = a[i]; a[i] = a[j]; a[j] = tmp;
        }
        return a;
    }

    function fmtTime(s) {
        var m = Math.floor(s / 60), r = s % 60;
        return m + ':' + (r < 10 ? '0' : '') + r;
    }

    function api(path, opts) {
        return fetch(endpoint + path, Object.assign({ credentials: 'same-origin', headers: { 'Accept': 'application/json' } }, opts || {}))
            .then(function (r) { return r.json().catch(function () { return { ok: false }; }); });
    }

    function lsGet(k) { try { return localStorage.getItem(k) || ''; } catch (e) { return ''; } }
    function lsSet(k, v) { try { localStorage.setItem(k, v); } catch (e) { /* ignorieren */ } }

    /* ---------- Spiel ---------- */

    function openGame() {
        if (dialog) { dialog.showModal(); newGame(); return; }

        dialog = el('dialog', 'ux-dialog');
        dialog.setAttribute('aria-label', t.title || 'Memory');

        var head = el('div', 'ux-head');
        head.appendChild(el('h3', '', t.title || 'Memory'));
        var stat = el('span', 'ux-stat');
        head.appendChild(stat);
        var close = el('button', 'ux-close', '×');
        close.type = 'button';
        close.setAttribute('aria-label', t.close || 'Close');
        head.appendChild(close);

        var body = el('div', 'ux-body');
        var grid = el('div', 'ux-grid');
        body.appendChild(grid);

        var win = el('div', 'ux-win');
        win.hidden = true;
        var winTitle = el('strong');
        var form = el('form');
        var input = el('input');
        input.type = 'text';
        input.maxLength = 20;
        input.required = true;
        input.autocomplete = 'nickname';
        input.placeholder = t.name || 'Your name';
        input.setAttribute('aria-label', t.name || 'Your name');
        var save = el('button', 'ux-btn', t.save || 'Save');
        save.type = 'submit';
        form.appendChild(input);
        form.appendChild(save);
        var again = el('button', 'ux-btn is-secondary', t.again || 'Play again');
        again.type = 'button';
        var msg = el('div', 'ux-msg');
        msg.setAttribute('role', 'status');
        win.appendChild(winTitle);
        win.appendChild(form);
        win.appendChild(msg);
        win.appendChild(again);
        body.appendChild(win);

        var foot = el('div', 'ux-foot');
        foot.appendChild(el('h4', '', t.top || 'Hall of fame'));
        var list = el('ol', 'ux-top');
        foot.appendChild(list);

        dialog.appendChild(head);
        dialog.appendChild(body);
        dialog.appendChild(foot);
        document.body.appendChild(dialog);

        var state = null, timer = null;

        function renderTop(top, myRank) {
            list.textContent = '';
            if (!top || !top.length) {
                var li = el('li', 'ux-empty', t.none || '-');
                li.style.display = 'block';
                list.appendChild(li);
                return;
            }
            top.forEach(function (row, i) {
                var li = el('li');
                if (myRank && myRank === i + 1) li.className = 'ux-me';
                li.appendChild(el('span', 'ux-rank', String(i + 1)));
                li.appendChild(el('span', 'ux-name', row.name));
                li.appendChild(el('span', '', row.moves + ' ' + (t.moves_short || 'moves')));
                li.appendChild(el('span', '', fmtTime(row.secs)));
                list.appendChild(li);
            });
        }

        function loadTop() {
            api('?a=top').then(function (d) { renderTop(d && d.ok ? d.top : []); }).catch(function () { renderTop([]); });
        }

        function updateStat() {
            var secs = state.startedAt ? Math.floor((Date.now() - state.startedAt) / 1000) : 0;
            stat.textContent = (t.moves || 'Moves') + ' ' + state.moves + ' · ' + fmtTime(state.finishedSecs !== null ? state.finishedSecs : secs);
        }

        function finish() {
            clearInterval(timer);
            state.finishedSecs = Math.max(1, Math.round((Date.now() - state.startedAt) / 1000));
            updateStat();
            winTitle.textContent = (t.won || 'Done!') + ' ' + state.moves + ' ' + (t.moves_short || 'moves') + ', ' + fmtTime(state.finishedSecs);
            msg.textContent = '';
            form.hidden = false;
            save.disabled = false;
            input.value = lsGet('ux_name');
            win.hidden = false;
            setTimeout(function () { input.focus(); }, 50);
        }

        function flip(card) {
            if (state.lock || card.el.classList.contains('is-open') || card.el.classList.contains('is-done')) return;
            if (!state.startedAt) {
                state.startedAt = Date.now();
                timer = setInterval(updateStat, 500);
                api('?a=start').then(function (d) { state.token = d && d.ok ? d.token : null; }).catch(function () { state.token = null; });
            }
            card.el.classList.add('is-open');
            card.el.setAttribute('aria-pressed', 'true');
            state.open.push(card);
            if (state.open.length < 2) return;

            state.moves++;
            updateStat();
            var a = state.open[0], b = state.open[1];
            state.open = [];
            if (a.sym === b.sym) {
                [a, b].forEach(function (c) { c.el.classList.remove('is-open'); c.el.classList.add('is-done'); c.el.disabled = true; });
                state.found++;
                if (state.found === SYMBOLS.length) setTimeout(finish, 450);
            } else {
                state.lock = true;
                setTimeout(function () {
                    [a, b].forEach(function (c) { c.el.classList.remove('is-open'); c.el.setAttribute('aria-pressed', 'false'); });
                    state.lock = false;
                }, 750);
            }
        }

        function newGame() {
            clearInterval(timer);
            state = { moves: 0, found: 0, open: [], lock: false, startedAt: 0, finishedSecs: null, token: null };
            win.hidden = true;
            grid.textContent = '';
            var syms = shuffle(SYMBOLS.concat(SYMBOLS));
            syms.forEach(function (sym, i) {
                var b = el('button', 'ux-card');
                b.type = 'button';
                b.setAttribute('aria-pressed', 'false');
                b.setAttribute('aria-label', (t.card || 'Card') + ' ' + (i + 1));
                var inner = el('span', 'ux-card-inner');
                inner.appendChild(el('span', 'ux-face ux-front', '?'));
                inner.appendChild(el('span', 'ux-face ux-back', sym));
                b.appendChild(inner);
                var card = { el: b, sym: sym };
                b.addEventListener('click', function () { flip(card); });
                grid.appendChild(b);
            });
            updateStat();
            loadTop();
        }

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var name = input.value.trim();
            if (!name || !state.token) {
                msg.textContent = t.error || 'Could not save.';
                return;
            }
            save.disabled = true;
            lsSet('ux_name', name);
            var body = new URLSearchParams({ a: 'save', token: state.token, name: name, moves: String(state.moves), secs: String(state.finishedSecs) });
            api('', { method: 'POST', body: body }).then(function (d) {
                if (d && d.ok) {
                    form.hidden = true;
                    msg.textContent = (t.rank || 'Rank') + ' ' + d.rank;
                    renderTop(d.top, d.rank);
                } else {
                    msg.textContent = t.error || 'Could not save.';
                    save.disabled = false;
                }
            }).catch(function () { msg.textContent = t.error || 'Could not save.'; save.disabled = false; });
        });

        again.addEventListener('click', newGame);
        close.addEventListener('click', function () { dialog.close(); });
        dialog.addEventListener('close', function () { clearInterval(timer); });
        dialog.addEventListener('click', function (e) { if (e.target === dialog) dialog.close(); });

        dialog.showModal();
        newGame();
    }

    /* ---------- Auslöser: dreimal schnell auf das Icon im leeren Zustand ---------- */

    document.querySelectorAll('.empty-icon').forEach(function (icon) {
        var clicks = [];
        icon.addEventListener('click', function () {
            var now = Date.now();
            clicks = clicks.filter(function (c) { return now - c < 1200; });
            clicks.push(now);
            if (clicks.length >= 3) {
                clicks = [];
                openGame();
            }
        });
    });
})();
