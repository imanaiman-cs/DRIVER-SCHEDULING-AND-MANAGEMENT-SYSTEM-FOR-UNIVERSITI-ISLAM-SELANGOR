<?php
// ============================================================
// includes/i18n.php  -  English / Bahasa Melayu (outside SRS v1.1; the source text of every page stays English)
//
// How it works: pages are written in English. When the chosen language is Bahasa Melayu ('ms'), the finished page
// (HTML or JSON) goes through translateOutput(): the text between tags, placeholder/title/aria-label attributes and
// the quoted texts inside scripts are looked up in includes/lang/ms.php. A text that is not in the dictionary stays
// English, so a missing entry never breaks a page. Texts with a space inside also work as fragments of longer
// sentences (the program joins fragments with data, e.g. 'Request #' . $id . ' was approved').
// The chosen language is kept in users.language (logged in) and in the cookie dsms_lang.
// Notifications and e-mails are sent in the language of the person who receives them.
// ============================================================

const LANGUAGES = ['en' => 'English', 'ms' => 'Bahasa Melayu'];

function validLanguage($lang): string
{
    return is_string($lang) && isset(LANGUAGES[$lang]) ? $lang : 'en';
}

/** The language of the current request: session (synced from users.language) > cookie > English. */
function currentLanguage(): string
{
    return validLanguage($_SESSION['lang'] ?? $_COOKIE['dsms_lang'] ?? 'en');
}

/** Remembers the language in the cookie and, when someone is logged in, on the account. */
function setLanguage(mysqli $conn, string $lang): string
{
    $lang = validLanguage($lang);
    $_SESSION['lang'] = $lang;
    if (!empty($_SESSION['user_id'])) { $_SESSION['lang_synced'] = true; }
    if (PHP_SAPI !== 'cli' && !headers_sent()) {
        setcookie('dsms_lang', $lang, ['expires' => time() + 365 * 86400, 'path' => '/', 'samesite' => 'Lax', 'httponly' => true, 'secure' => !empty($_SERVER['HTTPS'])]);
    }
    $_COOKIE['dsms_lang'] = $lang;
    if (!empty($_SESSION['user_id'])) {
        $stmt = $conn->prepare('UPDATE users SET language = ? WHERE user_id = ?');
        $uid = (int)$_SESSION['user_id'];
        $stmt->bind_param('si', $lang, $uid);
        $stmt->execute();
        $stmt->close();
    }
    return $lang;
}

/** After login: the language saved on the account wins over the cookie. */
function syncLanguageFromAccount(mysqli $conn): void
{
    if (empty($_SESSION['user_id']) || !empty($_SESSION['lang_synced'])) { return; }
    try {
        $stmt = $conn->prepare('SELECT language FROM users WHERE user_id = ?');
        $uid = (int)$_SESSION['user_id'];
        $stmt->bind_param('i', $uid);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row && isset(LANGUAGES[$row['language'] ?? ''])) {
            $_SESSION['lang'] = $row['language'];
            if (PHP_SAPI !== 'cli' && !headers_sent()) {
                setcookie('dsms_lang', $row['language'], ['expires' => time() + 365 * 86400, 'path' => '/', 'samesite' => 'Lax', 'httponly' => true, 'secure' => !empty($_SERVER['HTTPS'])]);
            }
        }
        $_SESSION['lang_synced'] = true;
    } catch (Throwable $e) {
        error_log('syncLanguageFromAccount: ' . $e->getMessage());   // column missing before the migration ran
    }
}

/** @return array{exact: array<string,string>, fragments: array<string,string>, patterns: string[], words: array<string,string>} */
function languageDictionary(string $lang): array
{
    static $cache = [];
    if (isset($cache[$lang])) { return $cache[$lang]; }
    $exact = [];
    $file = __DIR__ . '/lang/' . $lang . '.php';
    if ($lang !== 'en' && is_file($file)) {
        foreach ((array)include $file as $en => $tr) { $exact[trim(preg_replace('/\s+/', ' ', (string)$en))] = (string)$tr; }
    }
    $fragments = [];
    foreach ($exact as $en => $tr) {
        // fragments of longer sentences: more than one word, or a text that ends where data is added
        if (strlen($en) >= 4 && (strpos($en, ' ') !== false || preg_match('/[#:()]/', $en))) { $fragments[$en] = $tr; }
    }
    uksort($fragments, fn($a, $b) => strlen($b) <=> strlen($a));
    // one pattern, longest first; a fragment never starts or ends in the middle of a word
    $alt = [];
    foreach (array_keys($fragments) as $f) {
        $alt[] = (preg_match('/^\p{L}/u', $f) ? '(?<!\p{L})' : '') . preg_quote($f, '/') . (preg_match('/\p{L}$/u', $f) ? '(?!\p{L})' : '');
    }
    $fragmentPattern = [];   // several patterns: one big alternation is over the PCRE size limit
    foreach (array_chunk($alt, 100) as $chunk) { $fragmentPattern[] = '/' . implode('|', $chunk) . '/u'; }
    $words = [];
    if ($lang === 'ms') {
        $words = ['January' => 'Januari', 'February' => 'Februari', 'March' => 'Mac', 'May' => 'Mei', 'June' => 'Jun', 'July' => 'Julai', 'August' => 'Ogos', 'October' => 'Oktober', 'December' => 'Disember',
                  'Monday' => 'Isnin', 'Tuesday' => 'Selasa', 'Wednesday' => 'Rabu', 'Thursday' => 'Khamis', 'Friday' => 'Jumaat', 'Saturday' => 'Sabtu', 'Sunday' => 'Ahad',
                  'Mon' => 'Isn', 'Tue' => 'Sel', 'Wed' => 'Rab', 'Thu' => 'Kha', 'Fri' => 'Jum', 'Sat' => 'Sab', 'Sun' => 'Ahd',
                  'Mar' => 'Mac', 'Aug' => 'Ogo', 'Oct' => 'Okt', 'Dec' => 'Dis'];
    }
    return $cache[$lang] = ['exact' => $exact, 'fragments' => $fragments, 'patterns' => $fragmentPattern, 'words' => $words];
}

/** Replaces the known fragments inside a longer text (the longest fragments first). */
function replaceFragments(string $text, array $d): string
{
    foreach ($d['patterns'] as $pattern) {
        $new = preg_replace_callback($pattern, fn($x) => $d['fragments'][$x[0]], $text);
        if ($new !== null) { $text = $new; }
    }
    return $text;
}

/** Translates one plain text (no HTML). Leading and trailing spaces are kept. */
function translateText(string $text, ?string $lang = null): string
{
    $lang = $lang ?? currentLanguage();
    if ($lang === 'en' || $text === '' || trim($text) === '') { return $text; }
    $d = languageDictionary($lang);
    if (!$d['exact']) { return $text; }
    preg_match('/^(\s*)(.*?)(\s*)$/s', $text, $m);
    [$lead, $core, $tail] = [$m[1], $m[2], $m[3]];
    $key = preg_replace('/\s+/', ' ', $core);
    if (isset($d['exact'][$key])) { return $lead . $d['exact'][$key] . $tail; }
    // the same text with HTML entities written out (&amp;, &#039;)
    if (strpos($core, '&') !== false) {
        $plain = html_entity_decode($key, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (isset($d['exact'][$plain])) { return $lead . htmlspecialchars($d['exact'][$plain], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . $tail; }
    }
    $out = $core;
    if (strpos($core, ' ') !== false || preg_match('/[#:(]/', $core)) {
        $out = replaceFragments($out, $d);
        // fragments written with an apostrophe in PHP appear as &#039; in HTML
        if (strpos($out, '&#039;') !== false) {
            $plain = html_entity_decode($out, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $again = replaceFragments($plain, $d);
            if ($again !== $plain) { $out = htmlspecialchars($again, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
        }
    }
    if ($lang === 'ms') {
        $out = preg_replace(['/\bwaiting (\d+) h\b/', '/\bwaiting (\d+) days?\b/', '/\b(\d+) outside working hours\b/', '/\b(\d+) new\b/', '/\bone day\b/', '/\btwo days\b/', '/\b(\d+) days?\b/'],
                            ['menunggu $1 j', 'menunggu $1 hari', '$1 di luar waktu bekerja', '$1 baharu', 'satu hari', 'dua hari', '$1 hari'], $out);
    }
    if ($d['words'] && preg_match('/[A-Z][a-z]/', $out)) {
        $out = preg_replace_callback('/\b(' . implode('|', array_map('preg_quote', array_keys($d['words']))) . ')\b/', fn($x) => $d['words'][$x[1]], $out);
    }
    return $lead . $out . $tail;
}

/** Translates a finished HTML page. Scripts, styles, comments and textareas are not touched, except quoted texts in scripts. */
function translateHtml(string $html, string $lang): string
{
    $parts = preg_split('~(<script\b[^>]*>.*?</script>|<style\b[^>]*>.*?</style>|<textarea\b[^>]*>.*?</textarea>|<!--.*?-->|<[^>]+>)~is', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
    $d = languageDictionary($lang);
    foreach ($parts as $i => $p) {
        if ($p === '') { continue; }
        if ($p[0] !== '<') { $parts[$i] = translateText($p, $lang); continue; }
        if (stripos($p, '<script') === 0) {
            $parts[$i] = preg_replace_callback('/([\'"])((?:(?!\1)[^\\\\\n]|\\\\.){3,200})\1/', function ($m) use ($d) {
                $raw = stripslashes($m[2]);
                $key = trim(preg_replace('/\s+/', ' ', $raw));
                $tr = $d['exact'][$key] ?? $d['words'][$key] ?? null;   // month and day names are used by the calendar script
                if ($tr === null) { return $m[0]; }
                return $m[1] . addcslashes($tr, $m[1] . '\\') . $m[1];
            }, $p);
            continue;
        }
        if ($p[1] === '!' || $p[1] === '/' || stripos($p, '<style') === 0 || stripos($p, '<textarea') === 0) {
            if (stripos($p, '<textarea') === 0 && preg_match('~^(<textarea\b[^>]*>)(.*)(</textarea>)$~is', $p, $t)) {
                $parts[$i] = translateTagAttributes($t[1], $lang) . $t[2] . $t[3];
            }
            continue;
        }
        $parts[$i] = translateTagAttributes($p, $lang);
    }
    $html = implode('', $parts);
    return preg_replace('/<html lang="en"/i', '<html lang="' . $lang . '"', $html, 1);
}

function translateTagAttributes(string $tag, string $lang): string
{
    if (strpos($tag, '=') === false) { return $tag; }
    $attrs = 'placeholder|title|aria-label|alt|data-confirm|data-confirm-title|data-confirm-button|data-bs-original-title';
    if (preg_match('/^<input\b[^>]*\btype=["\'](?:submit|button)["\']/i', $tag)) { $attrs .= '|value'; }
    return preg_replace_callback('/\b(' . $attrs . ')=(["\'])(.*?)\2/s', fn($m) => $m[1] . '=' . $m[2] . translateText($m[3], $lang) . $m[2], $tag);
}

/** JSON answers (AJAX): every string value is translated. */
function translateJson(string $json, string $lang): string
{
    $data = json_decode($json);
    if ($data === null && json_last_error() !== JSON_ERROR_NONE) { return $json; }
    $changed = false;
    $walk = function ($v) use (&$walk, &$changed, $lang) {
        if (is_string($v)) {
            $t = strpos($v, '<') !== false ? translateHtml($v, $lang) : translateText($v, $lang);
            if ($t !== $v) { $changed = true; }
            return $t;
        }
        if (is_array($v)) { return array_map($walk, $v); }
        if (is_object($v)) { foreach ($v as $k => $x) { $v->$k = $walk($x); } }
        return $v;
    };
    $out = $walk($data);
    return $changed ? (string)json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : $json;
}

/** Output-buffer callback: translates HTML and JSON pages; downloads and other types pass through. */
function translateOutput(string $buffer): string
{
    $lang = currentLanguage();
    if ($lang === 'en' || $buffer === '') { return $buffer; }
    $type = '';
    foreach (headers_list() as $h) {
        if (stripos($h, 'Content-Type:') === 0) { $type = strtolower($h); }
        if (stripos($h, 'Content-Disposition:') === 0 && stripos($h, 'attachment') !== false) { return $buffer; }
    }
    if (strpos($type, 'application/json') !== false) { return translateJson($buffer, $lang); }
    if ($type !== '' && strpos($type, 'text/html') === false) { return $buffer; }
    if ((ltrim($buffer)[0] ?? '') !== '<') { return $buffer; }
    return translateHtml($buffer, $lang);
}

/** The EN | BM switch. $class is added to the wrapper. */
function languageSwitcher(string $class = ''): string
{
    $cur = currentLanguage();
    $back = $_SERVER['REQUEST_URI'] ?? '';
    $html = '<div class="lang-switch ' . htmlspecialchars($class) . '" aria-label="Language">';
    foreach (['en' => 'EN', 'ms' => 'BM'] as $code => $label) {
        $html .= '<a href="' . htmlspecialchars(SITE_URL) . '/set_language.php?lang=' . $code . '&amp;back=' . rawurlencode($back) . '" lang="' . $code . '" title="' . htmlspecialchars(LANGUAGES[$code]) . '"'
              . ' class="lang-link' . ($cur === $code ? ' active' : '') . '"' . ($cur === $code ? ' aria-current="true"' : '') . '>' . $label . '</a>';
    }
    return $html . '</div>';
}

/** Starts the translation of the page when Bahasa Melayu is chosen (called from config/database.php). */
function startTranslation(): void
{
    if (PHP_SAPI === 'cli' || currentLanguage() === 'en') { return; }
    ob_start('translateOutput');
}
