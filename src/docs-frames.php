<?php
/**
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License version 3.0
 * that is bundled with this package in the file LICENSE.md.
 * It is also available through the world-wide-web at this URL:
 * https://opensource.org/licenses/AFL-3.0
 *
 * @author    George van Engers | PrestaShopHeaven.com
 * @copyright Since 2026 George van Engers
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 */

/*
 * Framed screenshots (used by docs.php and docs-render.php): every screenshot of docs.json is put
 * into the frame template of the module, with its caption in the language printed on the frame.
 *
 * - Template: resources/docs/frame/frame.html + frame.css (the same for every module); a module may
 *   replace the template with docs/content/frame.html and add CSS in docs/content/frame.css.
 * - Style of the module: docs/docs.json > frame (colours, background, size, caption position).
 *   'ps-module docs init' gives each module a starting style from resources/docs/frame/styles.json.
 * - Captions: docs/content/<iso>/screenshots.json, {"<id>": {"title": "..", "text": ".."}}, written
 *   and translated by Claude like the manual (ps-module docs mark-translated <iso> screenshots).
 * - Output: docs/framed/<iso>/<id>.png (the Addons product images; the manuals use them as well),
 *   rendered in the docs-tools image, else with a local Chrome/Edge; docs/framed/manifest.json.
 */

declare(strict_types=1);

const DOCS_FRAME_DEFAULTS = [
    'enabled' => true,
    'width' => 1600,
    'height' => 1200,
    'caption' => 'top',
    'radius' => 28,
    'font' => '',
    'address' => '',
    'logo' => true,
    'counter' => true,
    'manual' => true,
    'engines' => ['docker', 'chrome'],
];
const DOCS_FRAME_COLOURS = ['frame', 'text', 'accent', 'device'];
const DOCS_CAPTION_TITLE = [3, 80];
const DOCS_CAPTION_TEXT = [0, 220];

/**
 * The frame settings of the module: defaults + docs.json > frame (without the style when it is missing).
 *
 * @param array<string, mixed> $ctx
 *
 * @return array<string, mixed>
 */
function docs_frame_config(array $ctx): array
{
    $frame = (array) ($ctx['config']['frame'] ?? []);
    $style = docs_frame_styles()[0] ?? [];

    return array_merge(DOCS_FRAME_DEFAULTS, array_intersect_key($style, array_flip(['background', 'frame', 'text', 'accent', 'device'])), $frame);
}

/**
 * True when screenshots are framed (there are screenshots and docs.json > frame.enabled is not false).
 *
 * @param array<string, mixed> $ctx
 */
function docs_frames_enabled(array $ctx): bool
{
    return (array) ($ctx['config']['screenshots'] ?? []) !== [] && docs_frame_config($ctx)['enabled'] !== false;
}

/**
 * The starting styles of resources/docs/frame/styles.json.
 *
 * @return array<int, array<string, string>>
 */
function docs_frame_styles(): array
{
    return (array) (docs_read_json(DOCS_RESOURCES . '/docs/frame/styles.json')['styles'] ?? []);
}

/**
 * The starting style of a module: one of styles.json, chosen by the module name (so modules differ).
 *
 * @return array<string, mixed>
 */
function docs_frame_style_for(string $module): array
{
    $styles = docs_frame_styles();
    $style = $styles === [] ? [] : $styles[crc32($module) % count($styles)];

    return [
        'style' => (string) ($style['name'] ?? 'custom'),
        'background' => (string) ($style['background'] ?? '#1f4fd1'),
        'frame' => (string) ($style['frame'] ?? '#ffffff'),
        'text' => (string) ($style['text'] ?? '#101828'),
        'accent' => (string) ($style['accent'] ?? '#1f4fd1'),
        'device' => (string) ($style['device'] ?? '#111827'),
        'width' => DOCS_FRAME_DEFAULTS['width'],
        'height' => DOCS_FRAME_DEFAULTS['height'],
        'caption' => DOCS_FRAME_DEFAULTS['caption'],
        'radius' => DOCS_FRAME_DEFAULTS['radius'],
    ];
}

/**
 * Contrast ratio (WCAG) of two #rrggbb colours.
 */
function docs_contrast(string $a, string $b): float
{
    $luminance = static function (string $hex): float {
        $c = array_map(static fn (string $p): float => hexdec($p) / 255, str_split(substr($hex, 1, 6), 2));
        $c = array_map(static fn (float $v): float => $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4, $c);

        return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
    };
    [$l1, $l2] = [$luminance($a), $luminance($b)];

    return (max($l1, $l2) + 0.05) / (min($l1, $l2) + 0.05);
}

// ---------------------------------------------------------------------------
// Captions

/**
 * Path of the captions file of a language.
 *
 * @param array<string, mixed> $ctx
 */
function docs_captions_file(array $ctx, string $iso): string
{
    return $ctx['docs'] . "/content/$iso/screenshots.json";
}

/**
 * Loads screenshots.json of a language; null when missing, ['_error' => ...] when invalid JSON.
 *
 * @param array<string, mixed> $ctx
 *
 * @return array<string, mixed>|null
 */
function docs_load_captions(array $ctx, string $iso): ?array
{
    $file = docs_captions_file($ctx, $iso);
    if (!is_file($file)) {
        return null;
    }
    $data = json_decode((string) file_get_contents($file), true);

    return is_array($data) ? $data : ['_error' => json_last_error_msg()];
}

/**
 * The screenshot ids of docs.json that are shown in a language.
 *
 * @param array<string, mixed> $ctx
 *
 * @return string[]
 */
function docs_caption_ids(array $ctx, string $iso): array
{
    $ids = [];
    foreach ((array) ($ctx['config']['screenshots'] ?? []) as $shot) {
        if (is_array($shot) && (empty($shot['languages']) || in_array($iso, (array) $shot['languages'], true))) {
            $ids[] = (string) ($shot['id'] ?? '');
        }
    }

    return $ids;
}

/**
 * Hashes of the captions (per screenshot id), to see whether a translation is still up to date.
 *
 * @param array<string, mixed> $data
 *
 * @return array<string, string>
 */
function docs_caption_hashes(array $data): array
{
    $hashes = [];
    foreach ($data as $id => $caption) {
        if ($id !== '_meta' && $id !== '_help' && is_array($caption)) {
            $hashes[(string) $id] = docs_hash((string) json_encode([$caption['title'] ?? '', $caption['text'] ?? ''], JSON_UNESCAPED_UNICODE));
        }
    }
    ksort($hashes);

    return $hashes;
}

/**
 * A new captions file for the source language: one TODO caption per screenshot of docs.json.
 *
 * @param array<string, mixed> $ctx
 * @param array<string, mixed> $existing captions to keep
 *
 * @return array<string, mixed>
 */
function docs_captions_skeleton(array $ctx, array $existing = []): array
{
    $data = [
        '_meta' => (array) ($existing['_meta'] ?? []) + ['module' => $ctx['module'], 'version' => $ctx['version'], 'language' => $ctx['source']],
        '_help' => 'Caption printed on the frame of each screenshot (docs/framed/<iso>/<id>.png): title ' . DOCS_CAPTION_TITLE[0] . '-' . DOCS_CAPTION_TITLE[1] . ' characters, benefit first, what the merchant or the customer sees; text optional, at most ' . DOCS_CAPTION_TEXT[1] . ' characters. One entry per screenshot id of docs/docs.json.',
    ];
    foreach ((array) ($ctx['config']['screenshots'] ?? []) as $shot) {
        $id = (string) ($shot['id'] ?? '');
        if ($id === '') {
            continue;
        }
        $data[$id] = is_array($existing[$id] ?? null) ? $existing[$id] : [
            'title' => 'TODO: short caption for "' . (string) ($shot['title'] ?? $id) . '"',
            'text' => 'TODO: one sentence, or empty',
        ];
    }
    foreach ($existing as $id => $caption) {
        if (!isset($data[$id])) {
            $data[$id] = $caption;
        }
    }

    return $data;
}

/**
 * Validates screenshots.json of one language: an entry per screenshot, lengths, TODOs, translation state.
 *
 * @param array<string, mixed> $ctx
 * @param array<string, mixed>|null $source
 * @param callable(string, string, string, string): void $add
 */
function docs_validate_captions(array $ctx, string $iso, ?array $source, callable $add): void
{
    if (!docs_frames_enabled($ctx)) {
        return;
    }
    $file = docs_rel($ctx, docs_captions_file($ctx, $iso));
    $data = docs_load_captions($ctx, $iso);
    if ($data === null) {
        $add('error', $iso, $file, 'missing: the captions printed on the framed screenshots' . ($iso === $ctx['source'] ? ' (create it with: ps-module docs init)' : " (translate docs/content/{$ctx['source']}/screenshots.json; ps-module docs init creates a copy to translate)"));

        return;
    }
    if (isset($data['_error'])) {
        $add('error', $iso, $file, 'invalid JSON: ' . $data['_error']);

        return;
    }
    if ($iso !== $ctx['source'] && is_array($source) && !isset($source['_error']) && (docs_caption_hashes($data) === docs_caption_hashes($source) || empty($data['_meta']['translated_from']))) {
        $add('error', $iso, $file, "not translated yet: translate the captions of docs/content/{$ctx['source']}/screenshots.json into {$iso} (" . ($ctx['catalog'][$iso]['name'] ?? $iso) . ") in this file, then: ps-module docs mark-translated $iso screenshots");

        return;
    }
    docs_validate_meta($ctx, $iso, $file, (array) ($data['_meta'] ?? []), $add);
    $ids = docs_caption_ids($ctx, $iso);
    foreach ($ids as $id) {
        $caption = $data[$id] ?? null;
        if (!is_array($caption)) {
            $add('error', $iso, $file, "no caption for screenshot '$id' (add {\"$id\": {\"title\": \"..\", \"text\": \"..\"}})");
            continue;
        }
        $title = trim((string) ($caption['title'] ?? ''));
        $text = trim((string) ($caption['text'] ?? ''));
        foreach (['title' => [$title, DOCS_CAPTION_TITLE], 'text' => [$text, DOCS_CAPTION_TEXT]] as $key => [$value, [$min, $max]]) {
            $length = mb_strlen($value);
            if (preg_match(DOCS_TODO, $value)) {
                $add('error', $iso, $file, "$id.$key still has a TODO");
            } elseif ($length < $min || $length > $max) {
                $add('error', $iso, $file, "$id.$key has $length characters (allowed $min-$max)");
            }
        }
        $contacts = docs_contact_details("$title\n$text");
        if ($contacts !== []) {
            $add($ctx['distribution'] === 'addons' ? 'error' : 'warning', $iso, $file, "$id: external URL, e-mail address or phone number in the caption (REL-07): " . implode(', ', $contacts));
        }
    }
    foreach (array_keys($data) as $id) {
        if ($id !== '_meta' && $id !== '_help' && !in_array($id, $ids, true)) {
            $add('warning', $iso, $file, "caption '$id' has no screenshot in docs.json (remove it)");
        }
    }
    if ($iso === $ctx['source'] || $source === null || isset($source['_error'])) {
        return;
    }
    $state = docs_translation_state_captions($ctx, $iso, $data, $source);
    if ($state !== '') {
        $add('error', $iso, $file, $state);
    }
}

/**
 * Translation state of the captions compared with the source: '' when up to date, otherwise what to do.
 *
 * @param array<string, mixed> $ctx
 * @param array<string, mixed> $data
 * @param array<string, mixed> $source
 */
function docs_translation_state_captions(array $ctx, string $iso, array $data, array $source): string
{
    $mark = "then: ps-module docs mark-translated $iso screenshots";
    $titles = static fn (array $d): array => array_map(static fn ($c): string => is_array($c) ? (string) ($c['title'] ?? '') : '', array_diff_key($d, ['_meta' => 1, '_help' => 1]));
    if ($titles($data) === $titles($source) && $titles($source) !== []) {
        return "not translated (the titles are identical to {$ctx['source']}): translate them, $mark";
    }
    $recorded = (array) ($data['_meta']['translated_from'] ?? []);
    if ($recorded === []) {
        return "translation not recorded: check it against {$ctx['source']}, $mark";
    }
    $changed = [];
    foreach (docs_caption_hashes($source) as $id => $hash) {
        if (($recorded[$id] ?? '') !== $hash) {
            $changed[] = $id;
        }
    }

    return $changed === [] ? '' : "out of date: the {$ctx['source']} caption changed for " . implode(', ', $changed) . ". Update the translation, $mark";
}

/**
 * Validates docs.json > frame and the module's own template files.
 *
 * @param array<string, mixed> $ctx
 * @param callable(string, string, string, string): void $add
 */
function docs_validate_frame(array $ctx, callable $add): void
{
    $file = 'docs/docs.json';
    if ((array) ($ctx['config']['screenshots'] ?? []) === []) {
        return;
    }
    $raw = $ctx['config']['frame'] ?? null;
    if (!is_array($raw)) {
        $add('error', '*', $file, 'frame is missing: the style of the framed screenshots of this module (run: ps-module docs init, it adds a starting style; then adapt the colours to the module)');

        return;
    }
    if (($raw['enabled'] ?? true) === false) {
        $add('warning', '*', $file, 'frame.enabled is false: the screenshots are not framed (Addons product images and manuals use the raw screenshots)');

        return;
    }
    $frame = docs_frame_config($ctx);
    foreach (DOCS_FRAME_COLOURS as $key) {
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', (string) $frame[$key])) {
            $add('error', '*', $file, "frame.$key must be a colour like #1f4fd1");
        }
    }
    $background = (string) $frame['background'];
    if (trim($background) === '' || preg_match('/url\s*\(|@import|[<>{};]/i', $background)) {
        $add('error', '*', $file, 'frame.background must be a CSS colour or gradient (no url(), no ; or braces)');
    }
    if (preg_match('/^#[0-9a-fA-F]{6}$/', (string) $frame['frame']) && preg_match('/^#[0-9a-fA-F]{6}$/', (string) $frame['text'])) {
        $ratio = docs_contrast((string) $frame['frame'], (string) $frame['text']);
        if ($ratio < 4.5) {
            $add('error', '*', $file, sprintf('frame.text on frame.frame has a contrast of %.1f:1, at least 4.5:1 is needed to read the caption', $ratio));
        }
    }
    foreach (['width', 'height'] as $key) {
        $value = $frame[$key];
        if (!is_int($value) || $value < 600 || $value > 4000) {
            $add('error', '*', $file, "frame.$key must be a whole number of pixels between 600 and 4000");
        } elseif ($value < 1000) {
            $add('warning', '*', $file, "frame.$key is $value px: Addons wants product images of at least 1000 x 1000 px");
        }
    }
    if (!in_array($frame['caption'], ['top', 'bottom'], true)) {
        $add('error', '*', $file, 'frame.caption must be "top" or "bottom"');
    }
    if (!is_int($frame['radius']) || $frame['radius'] < 0 || $frame['radius'] > 120) {
        $add('error', '*', $file, 'frame.radius must be 0-120 (pixels)');
    }
    if (preg_match('/[<>{};"]/', (string) $frame['font'])) {
        $add('error', '*', $file, 'frame.font must be a font family name');
    }
    foreach ((array) $frame['engines'] as $engine) {
        if (!in_array($engine, ['docker', 'chrome'], true)) {
            $add('error', '*', $file, "frame.engines: unknown engine '$engine' (docker, chrome)");
        }
    }
    $template = $ctx['docs'] . '/content/frame.html';
    if (is_file($template)) {
        $html = (string) file_get_contents($template);
        foreach (['{{css}}', '{{image}}', '{{title}}'] as $placeholder) {
            if (!str_contains($html, $placeholder)) {
                $add('error', '*', 'docs/content/frame.html', "the template has no $placeholder (start from module-dev-tools resources/docs/frame/frame.html)");
            }
        }
        if (!str_contains($html, 'dataset.ready')) {
            $add('error', '*', 'docs/content/frame.html', 'the template must set document.body.dataset.ready = "1" when the screenshot is placed (see resources/docs/frame/frame.html)');
        }
    }
    $css = $ctx['docs'] . '/content/frame.css';
    if (is_file($css) && preg_match('/@import|url\s*\(\s*[\'"]?(?:https?:)?\/\//i', (string) file_get_contents($css))) {
        $add('error', '*', 'docs/content/frame.css', 'no external files (@import, url(http..)): the frames are rendered offline');
    }
}

// ---------------------------------------------------------------------------
// Rendering

/**
 * The framed screenshot of a language ('' when it is not there).
 *
 * @param array<string, mixed> $ctx
 */
function docs_framed_file(array $ctx, string $iso, string $id): string
{
    $file = $ctx['docs'] . "/framed/$iso/$id.png";

    return is_file($file) ? $file : '';
}

/**
 * The caption of a screenshot in a language: its own, else the source language, else the docs.json title.
 *
 * @param array<string, mixed> $ctx
 * @param array<string, mixed> $shot
 *
 * @return array{title: string, text: string, fallback: string}
 */
function docs_caption(array $ctx, string $iso, array $shot): array
{
    $id = (string) ($shot['id'] ?? '');
    foreach (array_unique([$iso, $ctx['source']]) as $lang) {
        $caption = (docs_load_captions($ctx, $lang) ?? [])[$id] ?? null;
        $title = is_array($caption) ? trim((string) ($caption['title'] ?? '')) : '';
        if ($title !== '' && !preg_match(DOCS_TODO, $title)) {
            $text = trim((string) ($caption['text'] ?? ''));

            return ['title' => docs_fill($ctx, $title), 'text' => preg_match(DOCS_TODO, $text) ? '' : docs_fill($ctx, $text), 'fallback' => $lang === $iso ? '' : $lang];
        }
    }

    return ['title' => (string) ($shot['title'] ?? $id), 'text' => '', 'fallback' => 'docs.json'];
}

/**
 * The device drawn around a screenshot: desktop, tablet, mobile, element (clipped) or custom.
 *
 * @param array<string, mixed> $shot
 */
function docs_frame_device(array $shot): string
{
    if (!empty($shot['selector'])) {
        return 'element';
    }
    $viewport = $shot['viewport'] ?? 'desktop';

    return is_string($viewport) && in_array($viewport, ['desktop', 'tablet', 'mobile'], true) ? $viewport : 'custom';
}

/**
 * The CSS of a frame: frame.css, the variables of docs.json > frame, docs/content/frame.css.
 *
 * @param array<string, mixed> $ctx
 * @param array<string, mixed> $frame
 */
function docs_frame_css(array $ctx, string $iso, array $frame, string $title): string
{
    $font = trim((string) $frame['font']);
    $stack = ($font !== '' ? '"' . $font . '", ' : '') . docs_font_stack($iso);
    // Longer titles (or wide scripts) get a smaller font, so a caption stays at two or three lines.
    $wide = preg_match_all('/[\x{3000}-\x{9fff}\x{ac00}-\x{d7af}\x{ff00}-\x{ffef}]/u', $title);
    $length = mb_strlen($title) + 0.9 * $wide;
    $size = $length <= 38 ? 54 : ($length <= 56 ? 48 : 42);
    $vars = [
        '--canvas-w' => (int) $frame['width'] . 'px',
        '--canvas-h' => (int) $frame['height'] . 'px',
        '--bg' => (string) $frame['background'],
        '--frame' => (string) $frame['frame'],
        '--text' => (string) $frame['text'],
        '--accent' => (string) $frame['accent'],
        '--device' => (string) $frame['device'],
        '--radius' => (int) $frame['radius'] . 'px',
        '--font' => $stack,
        '--title-size' => $size . 'px',
    ];
    $root = ":root {\n";
    foreach ($vars as $name => $value) {
        $root .= "    $name: $value;\n";
    }
    $root .= "}\n";
    if ($frame['caption'] === 'bottom') {
        $root .= ".layout-landscape .caption { order: 2; margin: 4px 0 22px; } .layout-landscape .stage { order: 1; margin: 6px 0 26px; } .layout-landscape .brand { order: 3; }\n";
    }
    $own = $ctx['docs'] . '/content/frame.css';

    return (string) file_get_contents(DOCS_RESOURCES . '/docs/frame/frame.css') . "\n/* docs/docs.json > frame */\n" . $root
        . (is_file($own) ? "\n/* docs/content/frame.css */\n" . (string) file_get_contents($own) : '');
}

/**
 * The filled frame page of one screenshot in one language.
 *
 * @param array<string, mixed> $ctx
 * @param array<string, mixed> $shot
 * @param array{title: string, text: string} $caption
 * @param array<string, mixed> $frame
 */
function docs_frame_html(array $ctx, string $iso, array $shot, string $image, array $caption, int $number, int $count, array $frame): string
{
    $own = $ctx['docs'] . '/content/frame.html';
    $template = (string) file_get_contents(is_file($own) ? $own : DOCS_RESOURCES . '/docs/frame/frame.html');
    $template = (string) preg_replace('/<!--.*?-->\s*/s', '', $template, 1);
    $size = @getimagesize($image) ?: [1, 1];
    $device = docs_frame_device($shot);
    $portrait = in_array($device, ['mobile', 'tablet'], true) ? $size[1] > $size[0] : ($device !== 'desktop' && $size[1] > $size[0] * 1.1);
    $logoFile = $ctx['module_dir'] . '/logo.png';
    $values = [
        '{{css}}' => docs_frame_css($ctx, $iso, $frame, $caption['title']),
        '{{image}}' => docs_data_uri($image),
        '{{text_html}}' => $caption['text'] !== '' ? '<p class="text">' . docs_e($caption['text']) . '</p>' : '',
        '{{logo_html}}' => $frame['logo'] !== false && is_file($logoFile) ? '<img class="brand-logo" src="' . docs_data_uri($logoFile) . '" alt="">' : '',
        '{{lang}}' => docs_e($iso),
        '{{dir}}' => docs_is_rtl($ctx, $iso) ? 'rtl' : 'ltr',
        '{{title}}' => docs_e($caption['title']),
        '{{device}}' => $device,
        '{{layout}}' => $portrait ? 'portrait' : 'landscape',
        '{{area}}' => docs_e((string) ($shot['area'] ?? '')),
        '{{display_name}}' => docs_e((string) $ctx['display_name']),
        '{{number}}' => $frame['counter'] !== false ? (string) $number : '',
        '{{count}}' => $frame['counter'] !== false ? (string) $count : '',
        '{{address}}' => docs_e((string) $frame['address']),
        '{{id}}' => docs_e((string) ($shot['id'] ?? '')),
    ];
    $html = strtr($template, $values);
    if ($frame['counter'] === false) {
        $html = str_replace('<span class="counter"> / </span>', '', $html);
    }

    return $html;
}

/**
 * Renders the framed screenshots of the given languages (only the ones whose input changed).
 *
 * @param array<string, mixed> $ctx
 * @param string[] $languages
 * @param string[] $only screenshot ids (empty = all)
 *
 * @return array{ok: bool, message: string, warnings: string[], rendered: int}
 */
function docs_render_frames(array $ctx, array $languages, array $only = [], bool $force = false): array
{
    if (!docs_frames_enabled($ctx)) {
        return ['ok' => true, 'message' => 'Framed screenshots: off (no screenshots, or docs.json > frame.enabled is false).', 'warnings' => [], 'rendered' => 0];
    }
    $frame = docs_frame_config($ctx);
    $manifestFile = $ctx['docs'] . '/framed/manifest.json';
    $manifest = docs_read_json($manifestFile) ?? [];
    $items = (array) ($manifest['items'] ?? []);
    $shots = array_values(array_filter((array) ($ctx['config']['screenshots'] ?? []), 'is_array'));
    $jobDir = $ctx['repo'] . '/.cache/docs/frames';
    @mkdir($jobDir, 0775, true);
    $todo = [];
    $warnings = [];
    $lines = [];
    $current = [];
    $unchanged = 0;
    foreach ($languages as $iso) {
        $local = docs_localize($ctx, $iso);
        $list = array_values(array_filter($shots, static fn (array $s): bool => empty($s['languages']) || in_array($iso, (array) $s['languages'], true)));
        foreach ($list as $n => $shot) {
            $id = (string) ($shot['id'] ?? '');
            $key = "$iso/$id";
            $current[$key] = true;
            if ($only !== [] && !in_array($id, $only, true)) {
                continue;
            }
            $image = docs_screenshot_file($ctx, $iso, $id);
            if ($image === null) {
                $warnings[] = "framed screenshot $key NOT made: there is no screenshot yet (ps-module docs screenshots)";
                $lines[] = "  skip  $key  no screenshot";
                continue;
            }
            $caption = docs_caption($local, $iso, $shot);
            if ($caption['fallback'] !== '') {
                $warnings[] = "framed screenshot $key: no caption in $iso, uses the one of {$caption['fallback']} (docs/content/$iso/screenshots.json)";
            }
            $html = docs_frame_html($local, $iso, $shot, $image, $caption, $n + 1, count($list), $frame);
            $hash = substr(sha1($html . '|' . $frame['width'] . 'x' . $frame['height']), 0, 16);
            $out = $ctx['docs'] . "/framed/$iso/$id.png";
            if (!$force && ($items[$key] ?? '') === $hash && is_file($out)) {
                ++$unchanged;
                continue;
            }
            $htmlFile = "$jobDir/" . str_replace('/', '-', $key) . '.html';
            file_put_contents($htmlFile, $html);
            $todo[$key] = ['html' => $htmlFile, 'out' => $out, 'hash' => $hash];
        }
    }
    // Frames of screenshots or languages that no longer exist.
    foreach (glob($ctx['docs'] . '/framed/*/*.png') ?: [] as $old) {
        $key = basename(dirname($old)) . '/' . basename($old, '.png');
        if (!isset($current[$key]) && $only === []) {
            @unlink($old);
            unset($items[$key]);
        }
    }
    $engine = '';
    $error = '';
    if ($todo !== []) {
        foreach ((array) $frame['engines'] as $candidate) {
            $result = $candidate === 'docker' ? docs_frames_docker($ctx, $todo, $frame) : docs_frames_chrome($todo, $frame);
            if (isset($result['error'])) {
                $error .= ($error !== '' ? '; ' : '') . "$candidate: {$result['error']}";
                continue;
            }
            $engine = (string) $candidate;
            foreach ($result as $key => $state) {
                if ($state['ok']) {
                    $items[$key] = $todo[$key]['hash'];
                    $lines[] = "  ok    $key  " . docs_rel($ctx, $todo[$key]['out']);
                } else {
                    unset($items[$key]);
                    $warnings[] = "framed screenshot $key NOT made ($candidate): {$state['error']}";
                    $lines[] = "  FAIL  $key  {$state['error']}";
                }
            }
            break;
        }
        if ($engine === '') {
            $warnings[] = 'framed screenshots NOT made (' . count($todo) . "): no engine worked ($error); the manuals and sheets use the raw screenshots";
            $lines[] = "  FAIL  no engine: $error";
        }
    }
    foreach (array_keys($todo) as $key) {
        @unlink($todo[$key]['html']);
    }
    ksort($items);
    docs_write_json($manifestFile, [
        '_comment' => 'Generated by ps-module docs: the screenshots in the frame of the module (docs.json > frame, captions in docs/content/<iso>/screenshots.json). Do not edit.',
        'size' => $frame['width'] . 'x' . $frame['height'],
        'engine' => $engine !== '' ? $engine : (string) ($manifest['engine'] ?? ''),
        'items' => $items,
    ]);
    $done = count(array_filter($lines, static fn (string $l): bool => str_starts_with($l, '  ok')));
    $message = 'Framed screenshots (' . $frame['width'] . 'x' . $frame['height'] . ($engine !== '' ? ", $engine" : '') . "): $done rendered, $unchanged unchanged" . ($lines !== [] ? "\n" . implode("\n", $lines) : '');

    return ['ok' => !str_contains(implode("\n", $lines), 'FAIL'), 'message' => $message, 'warnings' => $warnings, 'rendered' => $done];
}

/**
 * Engine "docker": the docs-tools image (Chromium with Noto fonts for every script).
 *
 * @param array<string, mixed> $ctx
 * @param array<string, array{html: string, out: string, hash: string}> $todo
 * @param array<string, mixed> $frame
 *
 * @return array<string, array{ok: bool, error: string}>|array{error: string}
 */
function docs_frames_docker(array $ctx, array $todo, array $frame): array
{
    $problem = docs_docker_available();
    if ($problem !== '') {
        return ['error' => $problem];
    }
    $image = docs_ensure_image();
    if ($image['error'] !== '') {
        return ['error' => $image['error']];
    }
    $items = [];
    foreach ($todo as $item) {
        @mkdir(dirname($item['out']), 0775, true);
        $items[] = ['html' => docs_container_path($ctx, $item['html']), 'out' => docs_container_path($ctx, $item['out']), 'width' => (int) $frame['width'], 'height' => (int) $frame['height']];
    }
    $run = docs_run_container($ctx, [['type' => 'frames', 'items' => $items]], null);
    if ($run['error'] !== '') {
        return ['error' => $run['error']];
    }
    $byOut = [];
    foreach ($run['results'] as $result) {
        $byOut[$result['out'] ?? ''] = $result;
    }
    $states = [];
    foreach ($todo as $key => $item) {
        $result = $byOut[docs_container_path($ctx, $item['out'])] ?? ['ok' => false, 'error' => 'no result'];
        $ok = !empty($result['ok']) && docs_frame_size_ok($item['out'], $frame);
        $states[$key] = ['ok' => $ok, 'error' => $ok ? '' : (string) (($result['error'] ?? '') !== '' ? $result['error'] : 'wrong image size')];
    }

    return $states;
}

/**
 * Engine "chrome": a local Chrome, Edge or Chromium (fonts of this machine: check CJK, Arabic and Thai).
 *
 * @param array<string, array{html: string, out: string, hash: string}> $todo
 * @param array<string, mixed> $frame
 *
 * @return array<string, array{ok: bool, error: string}>|array{error: string}
 */
function docs_frames_chrome(array $todo, array $frame): array
{
    $chrome = docs_find_chrome();
    if ($chrome === null) {
        return ['error' => 'no local Chrome, Edge or Chromium found (set PS_DOCS_CHROME to its path)'];
    }
    $states = [];
    foreach ($todo as $key => $item) {
        @mkdir(dirname($item['out']), 0775, true);
        @unlink($item['out']);
        $profile = sys_get_temp_dir() . '/ps-docs-chrome-' . uniqid();
        [$code, $output] = docs_exec([
            $chrome, '--headless=new', '--disable-gpu', '--no-first-run', '--no-default-browser-check', '--disable-extensions',
            '--hide-scrollbars', '--force-device-scale-factor=1', '--user-data-dir=' . $profile, '--virtual-time-budget=10000',
            '--window-size=' . (int) $frame['width'] . ',' . (int) $frame['height'], '--screenshot=' . $item['out'], docs_file_url($item['html']),
        ], 120);
        docs_remove_dir($profile);
        $ok = docs_frame_size_ok($item['out'], $frame);
        $states[$key] = ['ok' => $ok, 'error' => $ok ? '' : (is_file($item['out']) ? 'the image has the wrong size' : 'exit code ' . $code . ': ' . substr(trim($output), 0, 200))];
    }

    return $states;
}

/**
 * True when a rendered frame exists and has the canvas size.
 *
 * @param array<string, mixed> $frame
 */
function docs_frame_size_ok(string $file, array $frame): bool
{
    clearstatcache(true, $file);
    $size = is_file($file) ? @getimagesize($file) : false;

    return $size !== false && $size[0] === (int) $frame['width'] && $size[1] === (int) $frame['height'];
}

/**
 * Are the framed screenshots there for every language and screenshot, and made from the current input?
 *
 * @param array<string, mixed> $ctx
 *
 * @return array{state: string, missing: string[]}
 */
function docs_frames_state(array $ctx): array
{
    if (!docs_frames_enabled($ctx)) {
        return ['state' => 'ok', 'missing' => []];
    }
    $missing = [];
    foreach ($ctx['languages'] as $iso) {
        foreach (docs_caption_ids($ctx, $iso) as $id) {
            if (docs_screenshot_file($ctx, $iso, $id) !== null && docs_framed_file($ctx, $iso, $id) === '') {
                $missing[] = "$iso/$id";
            }
        }
    }

    return ['state' => $missing === [] ? 'ok' : 'missing', 'missing' => $missing];
}
