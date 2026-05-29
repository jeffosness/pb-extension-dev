<?php
// kb.php — Public-facing KB endpoint.
//
// Serves KB_EXTENSION_TROUBLESHOOTING.md from the repo root, in two formats:
//   - https://extension.phoneburner.biz/kb.php            → HTML for humans
//   - https://extension.phoneburner.biz/kb.php?format=md  → raw markdown for AI agents
//
// The source markdown deploys with the rest of the repo via the auto-deploy
// pipeline, so this endpoint is always in sync with the latest merged main.

declare(strict_types=1);

// Read from repo root, two levels up from server/public/.
$kbPath = realpath(__DIR__ . '/../../KB_EXTENSION_TROUBLESHOOTING.md');

if ($kbPath === false || !is_readable($kbPath)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "KB source file not found on the server.\n";
    exit;
}

$mtime = filemtime($kbPath);
$etag = '"' . substr(hash('sha256', $kbPath . '|' . $mtime), 0, 16) . '"';

// Honor conditional GETs so the AI agent doesn't waste bandwidth refetching.
if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH']) === $etag) {
    http_response_code(304);
    header('ETag: ' . $etag);
    exit;
}

header('ETag: ' . $etag);
header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
header('Cache-Control: public, max-age=300'); // 5 min — short, since main deploys are frequent.
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');

$markdown = file_get_contents($kbPath);
if ($markdown === false) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Failed to read KB source file.\n";
    exit;
}

$format = isset($_GET['format']) ? strtolower((string)$_GET['format']) : '';
$accept = $_SERVER['HTTP_ACCEPT'] ?? '';
$wantsMarkdown =
    $format === 'md' ||
    $format === 'markdown' ||
    $format === 'raw' ||
    stripos($accept, 'text/markdown') !== false ||
    stripos($accept, 'text/plain') !== false;

if ($wantsMarkdown) {
    header('Content-Type: text/markdown; charset=utf-8');
    echo $markdown;
    exit;
}

// -----------------------------------------------------------------------------
// HTML rendering. Minimal markdown→HTML converter that handles the subset of
// markdown used in KB_EXTENSION_TROUBLESHOOTING.md. Intentionally not a full
// CommonMark parser — keep this dependency-free.
// -----------------------------------------------------------------------------

/**
 * Convert the subset of markdown actually used in the KB file to HTML.
 */
function kb_md_to_html(string $md): string
{
    // Normalize line endings.
    $md = str_replace(["\r\n", "\r"], "\n", $md);

    $lines = explode("\n", $md);
    $out = [];
    $n = count($lines);
    $i = 0;

    $inUl = false;
    $inOl = false;

    $closeLists = function () use (&$inUl, &$inOl, &$out) {
        if ($inUl) { $out[] = '</ul>'; $inUl = false; }
        if ($inOl) { $out[] = '</ol>'; $inOl = false; }
    };

    while ($i < $n) {
        $line = $lines[$i];

        // Fenced code block.
        if (preg_match('/^```/', $line)) {
            $closeLists();
            $code = [];
            $i++;
            while ($i < $n && !preg_match('/^```/', $lines[$i])) {
                $code[] = $lines[$i];
                $i++;
            }
            $i++; // skip closing fence
            $out[] = '<pre><code>' . htmlspecialchars(implode("\n", $code), ENT_QUOTES, 'UTF-8') . '</code></pre>';
            continue;
        }

        // Horizontal rule.
        if (preg_match('/^---\s*$/', $line)) {
            $closeLists();
            $out[] = '<hr>';
            $i++;
            continue;
        }

        // Headers.
        if (preg_match('/^(#{1,6})\s+(.+?)\s*$/', $line, $m)) {
            $closeLists();
            $level = strlen($m[1]);
            $text = kb_inline($m[2]);
            $anchor = kb_slugify(strip_tags($text));
            $out[] = "<h{$level} id=\"{$anchor}\">{$text}</h{$level}>";
            $i++;
            continue;
        }

        // Tables: a header line with pipes, followed by a separator line of dashes/pipes.
        if (
            strpos($line, '|') !== false &&
            $i + 1 < $n &&
            preg_match('/^\s*\|?[\s\-:|]+\|[\s\-:|]+\s*$/', $lines[$i + 1])
        ) {
            $closeLists();
            $headerCells = kb_split_table_row($line);
            $i += 2; // skip header + separator
            $bodyRows = [];
            while ($i < $n && strpos($lines[$i], '|') !== false && trim($lines[$i]) !== '') {
                $bodyRows[] = kb_split_table_row($lines[$i]);
                $i++;
            }
            $out[] = '<div class="table-wrap"><table>';
            $out[] = '<thead><tr>' . implode('', array_map(
                fn($c) => '<th>' . kb_inline($c) . '</th>',
                $headerCells
            )) . '</tr></thead>';
            $out[] = '<tbody>';
            foreach ($bodyRows as $row) {
                $out[] = '<tr>' . implode('', array_map(
                    fn($c) => '<td>' . kb_inline($c) . '</td>',
                    $row
                )) . '</tr>';
            }
            $out[] = '</tbody></table></div>';
            continue;
        }

        // Ordered list item.
        if (preg_match('/^\s*\d+\.\s+(.+)$/', $line, $m)) {
            if ($inUl) { $out[] = '</ul>'; $inUl = false; }
            if (!$inOl) { $out[] = '<ol>'; $inOl = true; }
            $out[] = '<li>' . kb_inline($m[1]) . '</li>';
            $i++;
            continue;
        }

        // Unordered list item.
        if (preg_match('/^\s*[-*+]\s+(.+)$/', $line, $m)) {
            if ($inOl) { $out[] = '</ol>'; $inOl = false; }
            if (!$inUl) { $out[] = '<ul>'; $inUl = true; }
            $out[] = '<li>' . kb_inline($m[1]) . '</li>';
            $i++;
            continue;
        }

        // Blank line.
        if (trim($line) === '') {
            $closeLists();
            $i++;
            continue;
        }

        // Default: paragraph. Collect consecutive non-blank, non-special lines.
        $closeLists();
        $para = [$line];
        $i++;
        while (
            $i < $n &&
            trim($lines[$i]) !== '' &&
            !preg_match('/^(#{1,6}\s|---\s*$|```|\s*\d+\.\s|\s*[-*+]\s)/', $lines[$i]) &&
            !(strpos($lines[$i], '|') !== false && $i + 1 < $n && preg_match('/^\s*\|?[\s\-:|]+\|[\s\-:|]+\s*$/', $lines[$i + 1] ?? ''))
        ) {
            $para[] = $lines[$i];
            $i++;
        }
        $out[] = '<p>' . kb_inline(implode("\n", $para)) . '</p>';
    }

    $closeLists();
    return implode("\n", $out);
}

function kb_split_table_row(string $row): array
{
    $row = trim($row);
    // Strip leading/trailing pipes.
    $row = preg_replace('/^\||\|$/', '', $row);
    return array_map('trim', explode('|', $row));
}

function kb_inline(string $text): string
{
    // Escape HTML first to prevent injection from KB content.
    $text = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');

    // Inline code.
    $text = preg_replace_callback('/`([^`]+)`/', function ($m) {
        return '<code>' . $m[1] . '</code>';
    }, $text);

    // Links: [text](url) — url and text are already HTML-escaped.
    $text = preg_replace_callback('/\[([^\]]+)\]\(([^)]+)\)/', function ($m) {
        $url = $m[2];
        // Allow same-page anchors and http/https URLs only.
        if (!preg_match('/^(#|https?:\/\/|\/)/i', $url)) {
            return $m[0];
        }
        return '<a href="' . $url . '">' . $m[1] . '</a>';
    }, $text);

    // Bold (**text**).
    $text = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $text);

    // Italic (*text* or _text_) — only when bordered by non-word chars to avoid mangling identifiers.
    $text = preg_replace('/(?<![\w*])\*([^*\n]+)\*(?![\w*])/', '<em>$1</em>', $text);

    // Convert in-paragraph newlines to spaces for readability.
    $text = preg_replace('/\n+/', ' ', $text);

    return $text;
}

function kb_slugify(string $text): string
{
    $text = strtolower($text);
    $text = preg_replace('/[^a-z0-9\-\s]/', '', $text);
    $text = preg_replace('/[\s]+/', '-', trim($text));
    return $text;
}

$html = kb_md_to_html($markdown);
$generatedAt = gmdate('Y-m-d H:i') . ' UTC';

header('Content-Type: text/html; charset=utf-8');
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>PhoneBurner Dial Session Companion — Troubleshooting KB</title>
<meta name="description" content="Troubleshooting guide for the PhoneBurner Dial Session Companion Chrome extension.">
<style>
  :root {
    --bg: #ffffff;
    --text: #1a1a1a;
    --muted: #5a5a5a;
    --border: #e3e3e3;
    --code-bg: #f5f5f7;
    --link: #2855e0;
    --link-hover: #1a3fb8;
    --table-header-bg: #f7f8fa;
    --table-stripe: #fafbfc;
    --max-width: 900px;
  }
  * { box-sizing: border-box; }
  html { scroll-behavior: smooth; }
  body {
    margin: 0;
    padding: 0;
    background: var(--bg);
    color: var(--text);
    font: 16px/1.6 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
  }
  .wrap {
    max-width: var(--max-width);
    margin: 0 auto;
    padding: 32px 24px 64px;
  }
  .topbar {
    border-bottom: 1px solid var(--border);
    padding: 14px 24px;
    display: flex;
    gap: 16px;
    align-items: center;
    flex-wrap: wrap;
    background: #fafafa;
  }
  .topbar strong { font-size: 14px; }
  .topbar .meta {
    margin-left: auto;
    font-size: 12px;
    color: var(--muted);
  }
  .topbar a {
    font-size: 13px;
    color: var(--link);
    text-decoration: none;
    border: 1px solid var(--border);
    padding: 4px 10px;
    border-radius: 6px;
  }
  .topbar a:hover { color: var(--link-hover); border-color: var(--link); }

  h1, h2, h3, h4, h5, h6 {
    line-height: 1.25;
    margin: 1.6em 0 0.5em;
    scroll-margin-top: 80px;
  }
  h1 { font-size: 1.9rem; border-bottom: 1px solid var(--border); padding-bottom: 0.3em; }
  h2 { font-size: 1.45rem; border-bottom: 1px solid var(--border); padding-bottom: 0.2em; }
  h3 { font-size: 1.2rem; }
  h4 { font-size: 1.05rem; color: var(--muted); }

  p { margin: 0.7em 0; }
  ul, ol { margin: 0.7em 0; padding-left: 1.6em; }
  li { margin: 0.25em 0; }
  hr { border: 0; border-top: 1px solid var(--border); margin: 2em 0; }

  a { color: var(--link); text-decoration: underline; text-decoration-thickness: 1px; text-underline-offset: 2px; }
  a:hover { color: var(--link-hover); }

  code {
    font: 0.9em/1 ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
    background: var(--code-bg);
    padding: 0.12em 0.4em;
    border-radius: 4px;
  }
  pre {
    background: var(--code-bg);
    padding: 14px 16px;
    border-radius: 8px;
    overflow-x: auto;
    line-height: 1.4;
  }
  pre code {
    background: none;
    padding: 0;
    font-size: 0.88rem;
  }

  .table-wrap { overflow-x: auto; margin: 1em 0; }
  table {
    border-collapse: collapse;
    width: 100%;
    font-size: 0.95rem;
  }
  th, td {
    border: 1px solid var(--border);
    padding: 8px 12px;
    text-align: left;
    vertical-align: top;
  }
  th { background: var(--table-header-bg); font-weight: 600; }
  tbody tr:nth-child(even) { background: var(--table-stripe); }

  strong { font-weight: 600; }

  @media (max-width: 600px) {
    .wrap { padding: 20px 16px 48px; }
    h1 { font-size: 1.55rem; }
    h2 { font-size: 1.25rem; }
  }
</style>
</head>
<body>
<div class="topbar">
  <strong>PhoneBurner Dial Session Companion — Troubleshooting KB</strong>
  <a href="?format=md">Raw markdown</a>
  <span class="meta">Generated <?= htmlspecialchars($generatedAt, ENT_QUOTES, 'UTF-8') ?></span>
</div>
<main class="wrap">
<?= $html ?>
</main>
</body>
</html>
