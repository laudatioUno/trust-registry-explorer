<?php
declare(strict_types=1);

/**
 * Spracherkennung + kleine t()-Hilfsfunktion fürs mehrsprachige UI (DE/EN/FR).
 * MUSS als ALLERERSTES eingebunden werden (vor functions.php/config.php,
 * vor jeglicher Ausgabe) — ein Sprachwechsel per ?lang= setzt hier ggf.
 * noch ein Cookie und schickt einen Redirect, was nach der ersten Ausgabe
 * nicht mehr möglich ist. config.php selbst ruft t() auf (Spaltentitel,
 * Zeitraum-Presets), muss also NACH diesem Require stehen.
 */

const I18N_COOKIE   = 'trustExplorerLang';
const I18N_SUPPORTED = ['de', 'en', 'fr'];
const I18N_DEFAULT  = 'en'; // Zielgruppe spricht primär Englisch

/**
 * Ermittelt die aktive Sprache: expliziter ?lang=-Wechsel (persistiert per
 * Cookie + Redirect ohne den Parameter, damit URLs sauber bleiben) > zuvor
 * per Cookie gewählte Sprache > Browser-Spracheinstellung (Accept-Language)
 * > Default.
 */
function i18nDetectLanguage(): string
{
    $requested = $_GET['lang'] ?? null;
    if (is_string($requested) && in_array($requested, I18N_SUPPORTED, true)) {
        setcookie(I18N_COOKIE, $requested, time() + 60 * 60 * 24 * 365, '/');
        $params = $_GET;
        unset($params['lang']);
        $query = http_build_query($params);
        $self = $_SERVER['PHP_SELF'] ?? '';
        header('Location: ' . $self . ($query !== '' ? '?' . $query : ''));
        exit;
    }

    $cookie = $_COOKIE[I18N_COOKIE] ?? null;
    if (is_string($cookie) && in_array($cookie, I18N_SUPPORTED, true)) {
        return $cookie;
    }

    $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''));
    if ($accept !== '' && (str_starts_with($accept, 'de') || str_contains($accept, ',de'))) {
        return 'de';
    }
    if ($accept !== '' && (str_starts_with($accept, 'fr') || str_contains($accept, ',fr'))) {
        return 'fr';
    }

    return I18N_DEFAULT;
}

/**
 * Aktive Sprache für diesen Request (einmal ermittelt, danach gecacht).
 */
function currentLang(): string
{
    static $lang = null;
    if ($lang === null) {
        $lang = i18nDetectLanguage();
    }
    return $lang;
}

function i18nDict(): array
{
    static $dict = null;
    if ($dict === null) {
        $dict = require __DIR__ . '/lang.php';
    }
    return $dict;
}

/**
 * Übersetzt $key in die aktive Sprache. Fällt auf Englisch zurück, wenn der
 * Key dort fehlt, und auf den Key selbst, wenn er nirgends existiert (macht
 * einen fehlenden Eintrag sofort sichtbar statt ihn stillschweigend zu
 * verschlucken). $vars füllt sprintf-Platzhalter (%s, %d) im Text.
 */
function t(string $key, array $vars = []): string
{
    $dict = i18nDict();
    $lang = currentLang();
    $template = $dict[$lang][$key] ?? $dict['en'][$key] ?? $key;
    return vsprintf($template, $vars);
}

/**
 * Baut die URL für einen Sprachwechsel: aktuelle Query-Parameter plus
 * lang=<code> (der eigentliche Wechsel inkl. Cookie passiert dann beim
 * nächsten Request, siehe i18nDetectLanguage()).
 */
function langSwitchUrl(string $lang): string
{
    return '?' . http_build_query(array_merge($_GET, ['lang' => $lang]));
}

// Sprache sofort ermitteln (löst ggf. den Redirect oben aus), damit sie für
// config.php und alles Weitere in diesem Request feststeht.
currentLang();
