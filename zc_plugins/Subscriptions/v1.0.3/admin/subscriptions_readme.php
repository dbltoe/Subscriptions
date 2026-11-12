<?php
/**
 * Subscriptions -- this plugin's README.md, for superusers only, behind the admin login
 * (index.php?cmd=subscriptions_readme; the ReadMe button in Plugin Manager).
 *
 * Following the Zen Cart discussion on plugin readmes (zencart/zencart#8031):
 * - the guide is Markdown; this page turns it into HTML with subs_markdown_html(), which
 *   escapes every character first, so nothing in the file can become markup or script,
 *   and any browser shows it formatted without an extension;
 * - opened in its own window (the button has target="_blank" rel="noopener noreferrer");
 * - no scripts at all (CSP), can't be framed (X-Frame-Options, frame-ancestors), sends
 *   no referrer; links in the guide open in a new window with noopener noreferrer.
 *
 * README.md isn't served from zc_plugins/ directly (core's zc_plugins/.htaccess allows
 * only web assets). This page exists only while the plugin is installed and enabled;
 * the same README.md is at the root of the download for everything before that.
 *
 * @package  Subscriptions
 * @license  https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License v3.0
 */
if (!defined('IS_ADMIN_FLAG')) {
    http_response_code(404);
    exit;
}
// Login and page-permission checks happen in application_top; never skip it.
require 'includes/application_top.php';

if (!function_exists('zen_is_superuser') || !zen_is_superuser()) {
    zen_redirect(zen_href_link(FILENAME_DENIED, '', 'SSL'));
}

if (!function_exists('subs_markdown_html')) {
    /**
     * README.md as HTML: a small, deliberately limited Markdown subset (# headings,
     * paragraphs, - and 1. lists, > quotes, ``` code blocks, | tables, **bold**, *italic*,
     * `code`, bare http(s) links). Every character is escaped first, so nothing in the file can
     * become markup or script; only the tags made here are output.
     *
     * @param string $md
     * @return string
     */
    function subs_markdown_html($md)
    {
        $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", (string)$md));
        $html = '';
        $para = [];
        $list = null;      // 'ul' or 'ol'
        $items = [];
        $quote = [];
        $code = null;      // lines of an open ``` block, or null
        $table = [];       // rows of an open | table
        $flush = static function () use (&$html, &$para, &$list, &$items, &$quote, &$table) {
            if ($para !== []) {
                $html .= '<p>' . subs_markdown_inline(implode(' ', $para)) . "</p>\n";
                $para = [];
            }
            if ($list !== null) {
                $html .= "<$list>\n";
                foreach ($items as $item) {
                    $html .= '<li>' . subs_markdown_inline($item) . "</li>\n";
                }
                $html .= "</$list>\n";
                $list = null;
                $items = [];
            }
            if ($quote !== []) {
                $html .= '<blockquote>' . subs_markdown_inline(implode(' ', $quote)) . "</blockquote>\n";
                $quote = [];
            }
            if ($table !== []) {
                $rows = [];
                foreach ($table as $row) {
                    $cells = preg_split('/(?<!\\\\)\|/',preg_replace('/^\||\|$/', '', $row));
                    $rows[] = array_map(static function ($cell) {
                        return trim(str_replace('\|', '|', $cell));
                    }, $cells);
                }
                $rule = isset($rows[1]) ? implode('', $rows[1]) : '';
                $head = ($rule !== '' && preg_match('/^[\s:-]+$/', $rule) === 1);
                $html .= "<table>
";
                foreach ($rows as $i => $cells) {
                    if ($head && $i === 1) {
                        continue;
                    }
                    $tag = ($head && $i === 0) ? 'th' : 'td';
                    $html .= '<tr>';
                    foreach ($cells as $cell) {
                        $html .= "<$tag>" . subs_markdown_inline($cell) . "</$tag>";
                    }
                    $html .= "</tr>
";
                }
                $html .= "</table>
";
                $table = [];
            }
        };
        foreach ($lines as $line) {
            $trim = trim($line);
            if (strpos($trim, '```') === 0) {
                if ($code === null) {
                    $flush();
                    $code = [];
                } else {
                    $html .= '<pre><code>' . htmlspecialchars(implode("\n", $code), ENT_QUOTES, 'UTF-8') . "</code></pre>\n";
                    $code = null;
                }
                continue;
            }
            if ($code !== null) {
                $code[] = $line;
                continue;
            }
            if ($trim === '') {
                $flush();
                continue;
            }
            if ($trim[0] === '|') {
                if ($table === []) {
                    $flush();
                }
                $table[] = $trim;
                continue;
            }
            if (preg_match('/^(#{1,3})\s+(.+)$/', $trim, $m)) {
                $flush();
                $n = strlen($m[1]);
                $html .= "<h$n>" . subs_markdown_inline($m[2]) . "</h$n>\n";
                continue;
            }
            if (preg_match('/^>\s?(.*)$/', $trim, $m)) {
                if ($quote === [] || $table !== []) {
                    $flush();
                }
                $quote[] = $m[1];
                continue;
            }
            if (preg_match('/^-\s+(.+)$/', $line, $m) || preg_match('/^\d+\.\s+(.+)$/', $line, $m2)) {
                $type = isset($m[1]) ? 'ul' : 'ol';
                $text = isset($m[1]) ? $m[1] : $m2[1];
                if ($list !== $type || $table !== []) {
                    $flush();
                    $list = $type;
                }
                $items[] = $text;
                $m = $m2 = [];
                continue;
            }
            if ($list !== null && preg_match('/^\s{2,}\S/', $line)) {
                $items[count($items) - 1] .= ' ' . $trim;
                continue;
            }
            if ($list !== null || $quote !== [] || $table !== []) {
                $flush();
            }
            $para[] = $trim;
        }
        if ($code !== null) {
            $html .= '<pre><code>' . htmlspecialchars(implode("\n", $code), ENT_QUOTES, 'UTF-8') . "</code></pre>\n";
        }
        $flush();
        return $html;
    }

    /**
     * One line of README text: escaped, then `code`, **bold**, *italic* and bare
     * http(s) links (opened in a new window, no opener, no referrer).
     *
     * @param string $text
     * @return string
     */
    function subs_markdown_inline($text)
    {
        $text = htmlspecialchars((string)$text, ENT_QUOTES, 'UTF-8');
        $codes = [];
        $text = preg_replace_callback('/`([^`]+)`/', static function ($m) use (&$codes) {
            $codes[] = '<code>' . $m[1] . '</code>';
            return "\x00" . (count($codes) - 1) . "\x00";
        }, $text);
        $text = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $text);
        $text = preg_replace('/(?<![\w*])\*(?!\s)(.+?)(?<!\s)\*(?![\w*])/', '<em>$1</em>', $text);
        $text = preg_replace_callback('~https?://[^\s<>()"\']+~', static function ($m) {
            $url = rtrim($m[0], '.,;:');
            $tail = substr($m[0], strlen($url));
            return '<a href="' . $url . '" target="_blank" rel="noopener noreferrer">' . $url . '</a>' . $tail;
        }, $text);
        return preg_replace_callback('/\x00(\d+)\x00/', static function ($m) use ($codes) {
            return $codes[(int)$m[1]];
        }, $text);
    }
}

$subsReadmeFile = dirname(__DIR__) . '/README.md';
$subsReadmeText = is_file($subsReadmeFile) ? (string)file_get_contents($subsReadmeFile) : '';
$subsReadmeHtml = ($subsReadmeText !== '') ? subs_markdown_html($subsReadmeText) : '<p>README.md is missing from the Subscriptions plugin folder. The same guide is in the root of the plugin&#039;s download.</p>';

header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'; sandbox allow-popups allow-popups-to-escape-sandbox");
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store, private');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="referrer" content="no-referrer">
<title>Subscriptions ReadMe</title>
<style>
body{font-family:system-ui,-apple-system,"Segoe UI",Arial,sans-serif;color:#1b1f24;background:#fff;margin:0;padding:0 16px;line-height:1.55}
main{max-width:860px;margin:32px auto 64px}
h1{font-size:2rem;line-height:1.25;margin:0 0 6px}
h2{font-size:1.35rem;line-height:1.3;margin:36px 0 10px;padding-top:8px;border-top:1px solid #c8ccd0}
h3{font-size:1.1rem;line-height:1.35;margin:22px 0 8px}
p,li{margin:0 0 10px}
code{background:#f4f6f9;padding:1px 5px;border-radius:4px;font-size:.92em}
pre{background:#f4f6f9;padding:12px 14px;border-radius:6px;overflow:auto;line-height:1.4;margin:12px 0}
pre code{background:none;padding:0}
table{border-collapse:collapse;width:100%;margin:12px 0;font-size:.95em;display:block;overflow-x:auto}
th,td{border:1px solid #c8ccd0;padding:6px 10px;text-align:left;vertical-align:top}
th{background:#f4f6f9}
blockquote{border-left:4px solid #8a6500;background:#fff8e1;padding:10px 14px;margin:12px 0}
a{color:#0b3d91}
</style>
</head>
<body>
<main>
<?php echo $subsReadmeHtml; ?>
</main>
</body>
</html>
<?php
exit;
