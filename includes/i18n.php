<?php
// ============================================================
// includes/i18n.php  –  Bahasa Melayu / English
//
// The pages are written in English. When the user picks BM (cookie `uis_lang` = ms), the finished HTML is
// translated on its way out: every text node and the readable attributes (placeholder, title, aria-label,
// data-tip, data-confirm…) are looked up in lang/ms.php. Only whole texts are looked up, so names,
// destinations and other data typed by users are left alone. Texts with numbers in them (counts, dates,
// "request #12") go through the patterns in the same file.
// In the browser, assets/js/i18n.js uses the same dictionary for text that JavaScript adds later
// (dialogs, the calendar, the Today card).
//
// Switch with any URL + ?lang=ms or ?lang=en (the header and the login page have the buttons).
// ============================================================

const UIS_LANG_COOKIE = 'uis_lang';

/** 'ms' or 'en' */
function uisLang(): string
{
    static $lang = null;
    if ($lang === null) {
        $lang = (($_COOKIE[UIS_LANG_COOKIE] ?? 'en') === 'ms') ? 'ms' : 'en';
    }
    return $lang;
}

/** The BM dictionary: ['exact' => [en => ms], 'patterns' => [[regex, replacement], ...]] */
function uisDict(): array
{
    static $dict = null;
    return $dict ??= require __DIR__ . '/../lang/ms.php';
}

/** Translate one English text for the current language (PHP side, e.g. for strings built in code). */
function t(string $en): string
{
    return uisLang() === 'ms' ? uisTranslateText($en) : $en;
}

/** The current page's address with ?lang= set (used by the switch buttons). */
function uisLangUrl(string $lang): string
{
    $uri   = $_SERVER['REQUEST_URI'] ?? '/';
    $path  = strtok($uri, '?');
    parse_str((string)parse_url($uri, PHP_URL_QUERY), $q);
    $q['lang'] = $lang;
    return $path . '?' . http_build_query($q);
}

/** Translate a plain (decoded, whitespace-collapsed) text. Returns it unchanged when nothing matches. */
function uisTranslateText(string $text): string
{
    $d = uisDict();
    if (isset($d['exact'][$text])) {
        return $d['exact'][$text];
    }
    // "Purpose *", "Driver:" — the label without its marker
    if (preg_match('/^(.*?)(\s*[*:])$/u', $text, $m) && isset($d['exact'][$m[1]])) {
        return $d['exact'][$m[1]] . $m[2];
    }
    if (str_contains($text, ' | ')) {                     // "Drivers | UIS Driver Management"
        return implode(' | ', array_map('uisTranslateText', explode(' | ', $text)));
    }
    $out = $text;
    foreach ($d['patterns'] as [$re, $rep]) {
        $out = preg_replace('/' . $re . '/u', $rep, $out) ?? $out;
    }
    return $out;
}

/** Translate a raw piece of HTML text (keeps the surrounding whitespace; entities in, entities out). */
function uisTranslateRaw(string $raw, int $quotes): string
{
    $plain = trim(preg_replace('/\s+/u', ' ', html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    if ($plain === '' || !preg_match('/[A-Za-z]/', $plain)) {
        return $raw;
    }
    $new = uisTranslateText($plain);
    if ($new === $plain) {
        return $raw;
    }
    preg_match('/^\s*/u', $raw, $lead);
    preg_match('/\s*$/u', $raw, $trail);
    return $lead[0] . htmlspecialchars($new, $quotes, 'UTF-8') . $trail[0];
}

/** Output-buffer callback: translate a whole HTML page. */
function uisTranslateHtml(string $html): string
{
    if (stripos($html, '<html') === false) {
        return $html;                                   // JSON, files, fragments: untouched
    }
    foreach (headers_list() as $h) {
        if (stripos($h, 'content-type:') === 0 && stripos($h, 'text/html') === false) {
            return $html;
        }
    }
    // keep scripts, styles and text areas exactly as they are
    $parts = preg_split('~(<script\b.*?</script>|<style\b.*?</style>|<textarea\b.*?</textarea>)~is', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
    foreach ($parts as $i => $part) {
        if ($i % 2 === 1) {
            continue;
        }
        $part = preg_replace_callback('/>([^<>]+)</u', fn($m) => '>' . uisTranslateRaw($m[1], ENT_NOQUOTES) . '<', $part) ?? $part;
        $part = preg_replace_callback(
            '/\b(placeholder|title|aria-label|data-tip|data-confirm|data-confirm-title|data-confirm-button|data-loading-text|alt|data-label)="([^"]*)"/u',
            fn($m) => $m[1] . '="' . uisTranslateRaw($m[2], ENT_QUOTES) . '"',
            $part
        ) ?? $part;
        $parts[$i] = $part;
    }
    $html = implode('', $parts);
    return preg_replace('/<html lang="en"/', '<html lang="ms"', $html, 1);
}

/** Called once from config/database.php: handle ?lang=, then start translating when BM is chosen. */
function uisI18nBoot(): void
{
    if (PHP_SAPI === 'cli') {
        return;
    }
    $want = $_GET['lang'] ?? null;
    if (($want === 'ms' || $want === 'en') && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && !headers_sent()) {
        setcookie(UIS_LANG_COOKIE, $want, [
            'expires'  => time() + 365 * 86400,
            'path'     => '/',
            'samesite' => 'Lax',
            'secure'   => !empty($_SERVER['HTTPS']),
        ]);
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        parse_str((string)parse_url($uri, PHP_URL_QUERY), $q);
        unset($q['lang']);
        header('Location: ' . strtok($uri, '?') . ($q ? '?' . http_build_query($q) : ''));
        exit();
    }
    if (uisLang() === 'ms') {
        ob_start('uisTranslateHtml');
    }
}

/** <head> tags for BM: the dictionary for assets/js/i18n.js. Nothing in English. */
function uisI18nHead(): void
{
    if (uisLang() !== 'ms') {
        return;
    }
    $d = uisDict();
    echo '<script>window.UIS_I18N = ' . json_encode(['exact' => $d['exact'], 'patterns' => $d['patterns']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) . ";</script>\n";
    echo '<script src="' . SITE_URL . '/assets/js/i18n.js?v=' . @filemtime(__DIR__ . '/../assets/js/i18n.js') . '" defer></script>' . "\n";
}

/** The BM | EN switch (header and login page). */
function uisLangSwitch(string $extra = ''): string
{
    $ms = uisLang() === 'ms';
    $btn = 'grid h-full min-w-11 place-items-center rounded-full px-2.5 text-xs font-semibold transition-colors';
    $on  = 'bg-primary text-primary-content';
    $off = 'text-base-content/70 hover:text-base-content';
    return '<nav class="flex h-10 items-center rounded-full bg-base-100 p-1 shadow-[var(--ember-shadow)] ' . $extra . '" aria-label="Language / Bahasa">'
        . '<a class="' . $btn . ' ' . ($ms ? $on : $off) . '" href="' . htmlspecialchars(uisLangUrl('ms')) . '" lang="ms" hreflang="ms"' . ($ms ? ' aria-current="true"' : '') . ' title="Bahasa Melayu">BM</a>'
        . '<a class="' . $btn . ' ' . ($ms ? $off : $on) . '" href="' . htmlspecialchars(uisLangUrl('en')) . '" lang="en" hreflang="en"' . ($ms ? '' : ' aria-current="true"') . ' title="English">EN</a>'
        . '</nav>';
}

/** The light / dark switch. */
function uisThemeSwitch(string $extra = ''): string
{
    return '<button type="button" class="btn btn-circle ' . $extra . '" data-theme-toggle aria-label="Switch to light mode" title="Light / dark mode">'
        . '<i class="fas fa-sun" aria-hidden="true"></i></button>';
}

/** <head> script for light / dark: applied before paint (no flash), and the click handler for [data-theme-toggle]. */
function uisThemeHead(): void
{
    ?>
    <script>
    (function () {
        var KEY = 'uis-theme', root = document.documentElement;
        try { if (localStorage.getItem(KEY) === 'dark') { root.setAttribute('data-theme', 'uis'); } } catch (e) {}
        function sync() {
            var light = root.getAttribute('data-theme') === 'uis-light';
            document.querySelectorAll('[data-theme-toggle]').forEach(function (b) {
                b.setAttribute('aria-label', light ? 'Switch to dark mode' : 'Switch to light mode');
                var i = b.querySelector('i'); if (i) { i.className = 'fas ' + (light ? 'fa-moon' : 'fa-sun'); }
            });
        }
        document.addEventListener('DOMContentLoaded', sync);
        document.addEventListener('uis-theme', sync);              // the Profile page can change the theme too
        document.addEventListener('click', function (e) {
            var b = e.target.closest && e.target.closest('[data-theme-toggle]');
            if (!b) { return; }
            var light = root.getAttribute('data-theme') !== 'uis-light';
            root.classList.add('theme-switching');                 // no colour fade: switch at once
            root.setAttribute('data-theme', light ? 'uis-light' : 'uis');
            requestAnimationFrame(function () { requestAnimationFrame(function () { root.classList.remove('theme-switching'); }); });
            try { localStorage.setItem(KEY, light ? 'light' : 'dark'); } catch (e2) {}
            sync();
            document.dispatchEvent(new Event('uis-theme'));
        });
    })();
    </script>
    <?php
    uisI18nHead();
}
