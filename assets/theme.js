/*
 * Dark-Mode-Logik, gemeinsam genutzt von index.php und history.php.
 * Wird synchron im <head> eingebunden (vor dem ersten Rendering der Seite),
 * damit ein gespeicherter Theme-Wunsch sofort greift und es nicht kurz
 * im "falschen" Theme aufblitzt.
 *
 * Verhalten:
 *   - Kein gespeicherter Wert  -> Systemeinstellung (prefers-color-scheme)
 *     entscheidet, und die Seite folgt live, wenn sich die Systemeinstellung
 *     ändert (z.B. automatischer Dark Mode bei Sonnenuntergang).
 *   - Klick auf den Umschalter -> Wahl wird explizit in localStorage
 *     gespeichert und ab dann bei jedem Aufruf verwendet, unabhängig von
 *     der Systemeinstellung.
 *
 * Andere Teile der Seite (z.B. das History-Chart) können auf Änderungen
 * reagieren, indem sie auf 'trustexplorer:themechange' am document lauschen:
 *   document.addEventListener('trustexplorer:themechange', function (e) {
 *       console.log(e.detail.theme); // 'light' | 'dark'
 *   });
 */
(function () {
    'use strict';

    var STORAGE_KEY = 'trustExplorerTheme';
    var root = document.documentElement;

    function systemPrefersDark() {
        return !!(window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches);
    }

    function storedTheme() {
        try {
            var v = localStorage.getItem(STORAGE_KEY);
            return (v === 'light' || v === 'dark') ? v : null;
        } catch (e) {
            return null;
        }
    }

    function effectiveTheme() {
        var explicit = root.getAttribute('data-theme');
        if (explicit === 'light' || explicit === 'dark') return explicit;
        return systemPrefersDark() ? 'dark' : 'light';
    }

    function dispatchChange() {
        document.dispatchEvent(new CustomEvent('trustexplorer:themechange', {
            detail: { theme: effectiveTheme() },
        }));
    }

    // Sofort beim Laden anwenden (vor DOMContentLoaded), um Flackern zu vermeiden.
    var initial = storedTheme();
    if (initial) {
        root.setAttribute('data-theme', initial);
    }
    // Ohne gespeicherten Wert: kein data-theme-Attribut setzen, CSS übernimmt
    // über @media (prefers-color-scheme) automatisch die Systemeinstellung.

    function updateToggleIcon(btn) {
        if (!btn) return;
        var isDark = effectiveTheme() === 'dark';
        btn.textContent = isDark ? '☀️' : '🌙';
        btn.setAttribute('aria-label', isDark ? 'Light Mode aktivieren' : 'Dark Mode aktivieren');
        btn.title = isDark ? 'Light Mode aktivieren' : 'Dark Mode aktivieren';
    }

    function setTheme(theme, persist) {
        root.setAttribute('data-theme', theme);
        if (persist) {
            try { localStorage.setItem(STORAGE_KEY, theme); } catch (e) { /* ignore */ }
        }
        document.querySelectorAll('.theme-toggle').forEach(updateToggleIcon);
        dispatchChange();
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('.theme-toggle').forEach(function (btn) {
            updateToggleIcon(btn);
            btn.addEventListener('click', function () {
                setTheme(effectiveTheme() === 'dark' ? 'light' : 'dark', true);
            });
        });
        // Erstes Event, damit Verbraucher (z.B. Chart.js) ihre Startfarben kennen.
        dispatchChange();
    });

    // Live mitziehen, solange der User noch nie manuell umgeschaltet hat.
    if (window.matchMedia) {
        window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', function () {
            if (storedTheme() === null) {
                root.removeAttribute('data-theme');
                document.querySelectorAll('.theme-toggle').forEach(updateToggleIcon);
                dispatchChange();
            }
        });
    }
})();
