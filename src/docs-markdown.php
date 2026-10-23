<?php
/**
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License version 3.0
 * that is bundled with this package in the file LICENSE.md.
 * It is also available through the world-wide-web at this URL:
 * https://opensource.org/licenses/AFL-3.0
 *
 * @author    George van Engers | PrestaHaven.com
 * @copyright Since 2026 George van Engers
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 */

/*
 * A small, dependency-free Markdown renderer for the module manuals (ps-module docs).
 * All HTML in the source is escaped; only the subset below is supported:
 *
 *   ## Heading .. #### Heading          (ids for the table of contents)
 *   paragraphs, hard breaks (two trailing spaces or a trailing backslash)
 *   - / * / + lists and 1. lists, nested by indentation (ordered top-level lists are "steps")
 *   > quotes and callouts: > [!NOTE] / [!TIP] / [!IMPORTANT] / [!WARNING] / [!CAUTION]
 *   ``` fenced code ```, `inline code`, **bold**, *italic* / _italic_, ~~strike~~, <kbd>Ctrl</kbd>
 *   [links](https://…), <https://autolinks>, ![caption](screenshot:<id>) or ![caption](images/x.png)
 *     (an image alone on a line becomes a figure with the caption)
 *   | tables | with | a | --- | separator row (alignment with :---:) |
 *   --- horizontal rule;  <!-- comments --> are removed.
 */

declare(strict_types=1);

/**
 * Renders Markdown to HTML.
 *
 * @param callable(string): (array{src: string, class?: string}|null) $images resolves an image reference; null = missing
 * @param array<int, array{level: int, text: string, id: string}> $headings filled with the h2-h4 headings, in order
 */
function docs_md_render(string $markdown, callable $images, array &$headings = []): string
{
    $markdown = str_replace(["\r\n", "\r", "\t"], ["\n", "\n", '    '], $markdown);
    $markdown = (string) preg_replace('/<!--.*?-->/s', '', $markdown);
    $usedIds = [];

    return docs_md_blocks(explode("\n", $markdown), $images, $headings, $usedIds, 0);
}

/**
 * Renders a list of lines as block elements.
 *
 * @param string[] $lines
 * @param callable(string): (array{src: string, class?: string}|null) $images
 * @param array<int, array{level: int, text: string, id: string}> $headings
 * @param array<string, int> $usedIds
 */
function docs_md_blocks(array $lines, callable $images, array &$headings, array &$usedIds, int $depth): string
{
    $html = '';
    $count = count($lines);
    $i = 0;
    while ($i < $count) {
        $line = $lines[$i];
        if (trim($line) === '') {
            ++$i;
            continue;
        }
        // Fenced code block.
        if (preg_match('/^\s*(```|~~~)\s*([\w+-]*)\s*$/', $line, $m)) {
            $code = [];
            for (++$i; $i < $count && !preg_match('/^\s*' . preg_quote($m[1], '/') . '\s*$/', $lines[$i]); ++$i) {
                $code[] = $lines[$i];
            }
            ++$i;
            $class = $m[2] !== '' ? ' class="language-' . docs_md_attr($m[2]) . '"' : '';
            $html .= '<pre><code' . $class . '>' . htmlspecialchars(implode("\n", $code), ENT_QUOTES, 'UTF-8') . "</code></pre>\n";
            continue;
        }
        // Heading.
        if (preg_match('/^(#{1,6})\s+(.+?)\s*#*\s*$/', $line, $m)) {
            $level = strlen($m[1]);
            $text = trim($m[2]);
            $id = docs_md_slug($text, $usedIds);
            if ($level >= 2 && $level <= 4 && $depth === 0) {
                $headings[] = ['level' => $level, 'text' => docs_md_plain($text), 'id' => $id];
            }
            $html .= "<h$level id=\"$id\">" . docs_md_inline($text, $images) . "</h$level>\n";
            ++$i;
            continue;
        }
        // Horizontal rule.
        if (preg_match('/^\s*([-*_])(\s*\1){2,}\s*$/', $line)) {
            $html .= "<hr>\n";
            ++$i;
            continue;
        }
        // Table: a row with pipes followed by a separator row.
        if (str_contains($line, '|') && isset($lines[$i + 1]) && str_contains($lines[$i + 1], '|') && preg_match('/^\s*\|?\s*:?-+:?\s*(\|\s*:?-+:?\s*)*\|?\s*$/', $lines[$i + 1])) {
            $rows = [];
            $align = array_map(static function (string $c): string {
                $c = trim($c);

                return str_starts_with($c, ':') && str_ends_with($c, ':') ? 'center' : (str_ends_with($c, ':') ? 'end' : '');
            }, docs_md_cells($lines[$i + 1]));
            $header = docs_md_cells($line);
            for ($i += 2; $i < $count && trim($lines[$i]) !== '' && str_contains($lines[$i], '|'); ++$i) {
                $rows[] = docs_md_cells($lines[$i]);
            }
            $cell = static function (string $tag, string $text, int $n) use ($align, $images): string {
                $style = ($align[$n] ?? '') !== '' ? ' style="text-align:' . $align[$n] . '"' : '';

                return "<$tag$style>" . docs_md_inline(trim($text), $images) . "</$tag>";
            };
            $html .= "<table>\n<thead><tr>";
            foreach ($header as $n => $text) {
                $html .= $cell('th', $text, $n);
            }
            $html .= "</tr></thead>\n<tbody>\n";
            foreach ($rows as $row) {
                $html .= '<tr>';
                foreach (array_keys($header) as $n) {
                    $html .= $cell('td', $row[$n] ?? '', $n);
                }
                $html .= "</tr>\n";
            }
            $html .= "</tbody>\n</table>\n";
            continue;
        }
        // Blockquote / callout.
        if (preg_match('/^\s*>/', $line)) {
            $quote = [];
            for (; $i < $count && preg_match('/^\s*>\s?(.*)$/', $lines[$i], $m); ++$i) {
                $quote[] = $m[1];
            }
            if (preg_match('/^\[!(NOTE|TIP|IMPORTANT|WARNING|CAUTION)\]\s*(.*)$/i', trim($quote[0] ?? ''), $m)) {
                $type = strtolower($m[1]);
                $title = trim($m[2]);
                array_shift($quote);
                $inner = docs_md_blocks($quote, $images, $headings, $usedIds, $depth + 1);
                $titleHtml = $title !== '' ? '<span class="callout-title">' . docs_md_inline($title, $images) . '</span>' : '';
                $html .= "<blockquote class=\"callout callout-$type\">$titleHtml$inner</blockquote>\n";
            } else {
                $html .= '<blockquote>' . docs_md_blocks($quote, $images, $headings, $usedIds, $depth + 1) . "</blockquote>\n";
            }
            continue;
        }
        // List.
        if (preg_match('/^(\s*)([-*+]|\d{1,3}[.)])\s+/', $line)) {
            [$listHtml, $i] = docs_md_list($lines, $i, $images, $headings, $usedIds, $depth);
            $html .= $listHtml;
            continue;
        }
        // Figure: an image alone on a line.
        if (preg_match('/^\s*!\[([^\]]*)\]\(([^)\s]+)(?:\s+"([^"]*)")?\)\s*$/', $line, $m)) {
            $html .= docs_md_figure($m[1], $m[2], $images);
            ++$i;
            continue;
        }
        // Paragraph.
        $para = [];
        for (; $i < $count && trim($lines[$i]) !== '' && !docs_md_starts_block($lines[$i], $lines[$i + 1] ?? ''); ++$i) {
            $para[] = $lines[$i];
        }
        if ($para === []) {
            // A line that starts a block but was not handled above: render it as text.
            $para[] = $lines[$i];
            ++$i;
        }
        $html .= '<p>' . docs_md_paragraph($para, $images) . "</p>\n";
    }

    return $html;
}

/**
 * True when a line starts a block other than a paragraph (heading, list, quote, code, rule, table, figure).
 */
function docs_md_starts_block(string $line, string $next): bool
{
    return (bool) preg_match('/^(#{1,6}\s|\s*(```|~~~)|\s*>|(\s*)([-*+]|\d{1,3}[.)])\s+|\s*!\[[^\]]*\]\([^)]+\)\s*$)/', $line)
        || (bool) preg_match('/^\s*([-*_])(\s*\1){2,}\s*$/', $line)
        || (str_contains($line, '|') && str_contains($next, '|') && (bool) preg_match('/^\s*\|?\s*:?-+:?\s*(\|\s*:?-+:?\s*)*\|?\s*$/', $next));
}

/**
 * Renders the lines of a paragraph with hard line breaks.
 *
 * @param string[] $lines
 * @param callable(string): (array{src: string, class?: string}|null) $images
 */
function docs_md_paragraph(array $lines, callable $images): string
{
    $parts = [];
    foreach ($lines as $n => $line) {
        $break = $n < count($lines) - 1 && (str_ends_with($line, '  ') || str_ends_with(rtrim($line), '\\'));
        $text = trim($line);
        if ($break && str_ends_with($text, '\\')) {
            $text = substr($text, 0, -1);
        }
        $parts[] = docs_md_inline($text, $images) . ($break ? '<br>' : '');
    }

    return implode("\n", $parts);
}

/**
 * Renders a (nested) list starting at line $start; returns the HTML and the index of the first line after it.
 *
 * @param string[] $lines
 * @param callable(string): (array{src: string, class?: string}|null) $images
 * @param array<int, array{level: int, text: string, id: string}> $headings
 * @param array<string, int> $usedIds
 *
 * @return array{0: string, 1: int}
 */
function docs_md_list(array $lines, int $start, callable $images, array &$headings, array &$usedIds, int $depth): array
{
    preg_match('/^(\s*)([-*+]|\d{1,3}[.)])\s+/', $lines[$start], $m);
    $indent = strlen($m[1]);
    $ordered = !in_array($m[2], ['-', '*', '+'], true);
    $items = [];
    $i = $start;
    $count = count($lines);
    $loose = false;
    while ($i < $count) {
        if (!preg_match('/^(\s*)([-*+]|\d{1,3}[.)])\s+(.*)$/', $lines[$i], $m) || strlen($m[1]) !== $indent
            || $ordered === in_array($m[2], ['-', '*', '+'], true)) {
            break;
        }
        $contentIndent = strlen($m[1]) + strlen($m[2]) + 1;
        $item = [$m[3]];
        for (++$i; $i < $count; ++$i) {
            $line = $lines[$i];
            if (trim($line) === '') {
                // A blank line continues the item only when indented content follows.
                $next = $lines[$i + 1] ?? '';
                if (trim($next) !== '' && strlen($next) - strlen(ltrim($next)) > $indent) {
                    $item[] = '';
                    $loose = true;
                    continue;
                }
                break;
            }
            $lineIndent = strlen($line) - strlen(ltrim($line));
            if ($lineIndent <= $indent && preg_match('/^(\s*)([-*+]|\d{1,3}[.)])\s+/', $line)) {
                break;
            }
            if ($lineIndent <= $indent && docs_md_starts_block($line, $lines[$i + 1] ?? '')) {
                break;
            }
            $item[] = $lineIndent >= $contentIndent ? substr($line, $contentIndent) : ltrim($line);
        }
        $items[] = $item;
        // Blank line between items: skip it when the list continues.
        if ($i < $count && trim($lines[$i]) === '' && isset($lines[$i + 1]) && preg_match('/^(\s*)([-*+]|\d{1,3}[.)])\s+/', $lines[$i + 1], $n) && strlen($n[1]) === $indent) {
            ++$i;
        }
    }
    $tag = $ordered ? 'ol' : 'ul';
    $class = $ordered && $depth === 0 && $indent === 0 ? ' class="steps"' : '';
    $html = "<$tag$class>\n";
    foreach ($items as $item) {
        $inner = docs_md_blocks($item, $images, $headings, $usedIds, $depth + 1);
        if (!$loose) {
            // Tight list: the first paragraph is not wrapped in <p>.
            $inner = (string) preg_replace('#^<p>(.*?)</p>\n#s', '$1', $inner, 1);
        }
        $html .= '<li>' . rtrim($inner) . "</li>\n";
    }

    return [$html . "</$tag>\n", $i];
}

/**
 * Splits a table row into cells.
 *
 * @return string[]
 */
function docs_md_cells(string $row): array
{
    $row = trim($row);
    $row = (string) preg_replace('/^\||\|$/', '', $row);
    $cells = preg_split('/(?<!\\\\)\|/', $row) ?: [];

    return array_map(static fn (string $c): string => str_replace('\\|', '|', $c), $cells);
}

/**
 * Renders an image on its own line as a figure with caption.
 *
 * @param callable(string): (array{src: string, class?: string}|null) $images
 */
function docs_md_figure(string $alt, string $src, callable $images): string
{
    $image = $images($src);
    if ($image === null) {
        return '<p class="note-missing">[missing image: ' . htmlspecialchars($src, ENT_QUOTES, 'UTF-8') . "]</p>\n";
    }
    $class = trim('figure ' . ($image['class'] ?? ''));
    $caption = trim($alt) !== '' && ($image['caption'] ?? true) !== false ? '<figcaption>' . docs_md_inline($alt, $images) . '</figcaption>' : '';

    return '<figure class="' . docs_md_attr($class) . '"><img src="' . docs_md_attr($image['src']) . '" alt="' . docs_md_attr(docs_md_plain($alt)) . "\">$caption</figure>\n";
}

/**
 * Renders inline Markdown: code, images, links, autolinks, bold, italic, strike, kbd. All other HTML is escaped.
 *
 * @param callable(string): (array{src: string, class?: string}|null) $images
 */
function docs_md_inline(string $text, callable $images): string
{
    $slots = [];
    $keep = static function (string $html) use (&$slots): string {
        $slots[] = $html;

        return "\x1A" . (count($slots) - 1) . "\x1A";
    };
    $text = (string) preg_replace_callback('/`([^`]+)`/', static fn (array $m): string => $keep('<code>' . htmlspecialchars($m[1], ENT_QUOTES, 'UTF-8') . '</code>'), $text);
    $text = (string) preg_replace_callback('/!\[([^\]]*)\]\(([^)\s]+)\)/', static function (array $m) use ($keep, $images): string {
        $image = $images($m[2]);

        return $keep($image === null
            ? '<span class="note-missing">[missing image: ' . htmlspecialchars($m[2], ENT_QUOTES, 'UTF-8') . ']</span>'
            : '<img class="inline" src="' . docs_md_attr($image['src']) . '" alt="' . docs_md_attr($m[1]) . '">');
    }, $text);
    $text = (string) preg_replace_callback('/\[([^\]]+)\]\(([^)\s]+)\)/', static function (array $m) use ($keep, $images): string {
        return $keep('<a href="' . docs_md_attr(docs_md_safe_url($m[2])) . '">' . docs_md_inline($m[1], $images) . '</a>');
    }, $text);
    $text = (string) preg_replace_callback('/<(https?:\/\/[^>\s]+)>/', static fn (array $m): string => $keep('<a href="' . docs_md_attr($m[1]) . '">' . htmlspecialchars($m[1], ENT_QUOTES, 'UTF-8') . '</a>'), $text);
    $text = (string) preg_replace_callback('/<kbd>([^<]{1,40})<\/kbd>/', static fn (array $m): string => $keep('<kbd>' . htmlspecialchars($m[1], ENT_QUOTES, 'UTF-8') . '</kbd>'), $text);
    $text = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    $text = (string) preg_replace('/\*\*(?=\S)(.+?)(?<=\S)\*\*/u', '<strong>$1</strong>', $text);
    $text = (string) preg_replace('/__(?=\S)(.+?)(?<=\S)__/u', '<strong>$1</strong>', $text);
    $text = (string) preg_replace('/(?<![\w*])\*(?=\S)(.+?)(?<=\S)\*(?![\w*])/u', '<em>$1</em>', $text);
    $text = (string) preg_replace('/(?<![\w_])_(?=\S)(.+?)(?<=\S)_(?![\w_])/u', '<em>$1</em>', $text);
    $text = (string) preg_replace('/~~(?=\S)(.+?)(?<=\S)~~/u', '<del>$1</del>', $text);

    for ($n = 0; $n < 3 && str_contains($text, "\x1A"); ++$n) {
        $text = (string) preg_replace_callback("/\x1A(\\d+)\x1A/", static fn (array $m): string => $slots[(int) $m[1]], $text);
    }

    return $text;
}

/**
 * Keeps only safe link targets (http, https, mailto, anchors and relative paths).
 */
function docs_md_safe_url(string $url): string
{
    return preg_match('/^(https?:|mailto:|#|\.{0,2}\/|[\w.-]+(\/|$|#|\?))/i', $url) ? $url : '#';
}

/**
 * Escapes a value for an HTML attribute.
 */
function docs_md_attr(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

/**
 * Plain text of inline Markdown (for alt texts and the table of contents).
 */
function docs_md_plain(string $text): string
{
    $text = (string) preg_replace('/!?\[([^\]]*)\]\([^)]*\)/', '$1', $text);
    $text = (string) preg_replace('/<\/?kbd>/', '', $text);

    return trim(str_replace(['**', '__', '`', '~~'], '', $text));
}

/**
 * A unique id for a heading: ASCII slug, or "section-<n>" for scripts without Latin letters.
 *
 * @param array<string, int> $used
 */
function docs_md_slug(string $text, array &$used): string
{
    $plain = docs_md_plain($text);
    if (function_exists('transliterator_transliterate')) {
        $plain = (string) transliterator_transliterate('Any-Latin; Latin-ASCII', $plain);
    }
    $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($plain)), '-');
    if ($slug === '') {
        $slug = 'section';
    }
    $slug = substr($slug, 0, 60);
    $used[$slug] = ($used[$slug] ?? 0) + 1;

    return $used[$slug] > 1 ? $slug . '-' . $used[$slug] : $slug;
}
