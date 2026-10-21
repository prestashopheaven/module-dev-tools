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
 * - Template of the module: docs/frame/frame.html + frame.css (+ images and fonts next to them). 'ps-module
 *   docs init' creates it once from resources/docs/frame/ with a starting style of styles.json (chosen by
 *   the module name). After that it belongs to the module (edited by hand): no tool writes to docs/frame/.
 * - Settings: docs/docs.json > frame (size, caption position, logo, counter, engines).
 * - Captions: docs/content/<iso>/screenshots.json, {"<id>": {"title": "..", "text": ".."}}, written
 *   and translated by Claude like the manual (ps-module docs mark-translated <iso> screenshots).
 * - Output: docs/framed/<iso>/<id>.png (the Addons product images; the manuals use them as well),
 *   rendered in the docs-tools image, else with a local Chrome/Edge; docs/framed/manifest.json.
 */

declare(strict_types=1);

require_once __DIR__ . '/verbose.php';

const DOCS_FRAME_DEFAULTS = [
    'enabled' => true,
    'width' => 1600,
    'height' => 1200,
    'caption' => 'top',
    'address' => '',
    'logo' => true,
    'counter' => true,
    'manual' => true,
    'engines' => ['docker', 'chrome'],
];
/** docs.json > frame keys of module-dev-tools 1.7 that are now in docs/frame/frame.css. */
const DOCS_FRAME_LEGACY = ['style', 'background', 'frame', 'text', 'accent', 'device', 'radius', 'font'];
const DOCS_FRAME_ASSETS = [
    'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp',
    'svg' => 'image/svg+xml', 'woff2' => 'font/woff2', 'woff' => 'font/woff', 'ttf' => 'font/ttf', 'otf' => 'font/otf',
];
const DOCS_CAPTION_TITLE = [3, 80];
const DOCS_CAPTION_TEXT = [0, 220];

/**
 * The frame settings of the module: defaults + docs.json > frame.
 *
 * @param array<string, mixed> $ctx
 *
 * @return array<string, mixed>
 */
function docs_frame_config(array $ctx): array
{
    return array_merge(DOCS_FRAME_DEFAULTS, (array) ($ctx['config']['frame'] ?? []));
}

/**
 * The folder with the module's own frame template (docs/frame/).
 *
 * @param array<string, mixed> $ctx
 */
function docs_frame_dir(array $ctx): string
{
    return $ctx['docs'] . '/frame';
}

/**
 * Loads the module's frame template (frame.html without its leading comment, frame.css); error when missing.
 *
 * @param array<string, mixed> $ctx
 *
 * @return array{html: string, css: string, error: string}
 */
function docs_frame_template(array $ctx): array
{
    $dir = docs_frame_dir($ctx);
    $missing = array_values(array_filter(['frame.html', 'frame.css'], static fn (string $f): bool => !is_file("$dir/$f")));
    if ($missing !== []) {
        return ['html' => '', 'css' => '', 'error' => 'docs/frame/' . implode(' and docs/frame/', $missing) . ' missing: the frame template of this module (create it once with: ps-module docs init)'];
    }
    $html = (string) preg_replace('/^\s*(<!doctype[^>]*>\s*)?<!--.*?-->\s*/is', '$1', (string) file_get_contents("$dir/frame.html"), 1);

    return ['html' => $html, 'css' => (string) file_get_contents("$dir/frame.css"), 'error' => ''];
}

/**
 * The relative file references of a template text: url(..) in CSS, src=".." in HTML (no data:, http, /, #, {{..}}).
 *
 * @return array<int, array{0: string, 1: string}> [full match, reference]
 */
function docs_frame_references(string $text, bool $css): array
{
    $pattern = $css ? '/url\(\s*([\'"]?)([^\'")]+)\1\s*\)/i' : '/\bsrc\s*=\s*([\'"])([^\'"]+)\1/i';
    preg_match_all($pattern, $text, $matches, PREG_SET_ORDER);
    $refs = [];
    foreach ($matches as $m) {
        $ref = trim($m[2]);
        if (!preg_match('~^(data:|[a-z][a-z0-9+.-]*:|//|/|#|\{\{)~i', $ref) && $ref !== '') {
            $refs[] = [$m[0], $ref];
        }
    }

    return $refs;
}

/**
 * The file in docs/frame/ that a relative reference points to, or '' when it is not there (or outside it).
 *
 * @param array<string, mixed> $ctx
 */
function docs_frame_asset(array $ctx, string $ref): string
{
    $dir = realpath(docs_frame_dir($ctx));
    $file = $dir !== false ? realpath($dir . '/' . rawurldecode((string) preg_replace('/[?#].*$/', '', $ref))) : false;
    $ext = strtolower(pathinfo((string) $file, PATHINFO_EXTENSION));
    if ($dir === false || $file === false || !is_file($file) || !str_starts_with($file, $dir . DIRECTORY_SEPARATOR) || !isset(DOCS_FRAME_ASSETS[$ext])) {
        return '';
    }

    return $file;
}

/**
 * Replaces the relative image/font references of the template by data: URIs (or file: URLs for a preview).
 *
 * @param array<string, mixed> $ctx
 */
function docs_frame_embed(array $ctx, string $text, bool $css, bool $preview = false): string
{
    foreach (docs_frame_references($text, $css) as [$match, $ref]) {
        $file = docs_frame_asset($ctx, $ref);
        if ($file === '') {
            continue;
        }
        $type = DOCS_FRAME_ASSETS[strtolower(pathinfo($file, PATHINFO_EXTENSION))];
        $uri = $preview ? docs_file_url($file) : "data:$type;base64," . base64_encode((string) file_get_contents($file));
        $text = str_replace($match, $css ? 'url("' . $uri . '")' : 'src="' . $uri . '"', $text);
    }

    return $text;
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
 * @return array{style: string, background: string, frame: string, text: string, accent: string, device: string}
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
    ];
}

/**
 * Creates the module's frame template in docs/frame/ (only the files that are missing; never overwrites).
 * Takes the colours of a 1.7 docs.json > frame block and a 1.7 docs/content/frame.html / frame.css along.
 *
 * @param array<string, mixed> $ctx
 *
 * @return string[] the files created
 */
function docs_frame_create_template(array $ctx): array
{
    $dir = docs_frame_dir($ctx);
    $created = [];
    // The master frame of the workspace (branding/frame/, brand colours and fonts) wins over the built-in styles.
    $master = docs_frame_master($ctx);
    if ($master !== null && !is_file("$dir/frame.html") && !is_file("$dir/frame.css")) {
        ps_vlog("frame template: copying the master frame $master into $dir");

        return docs_frame_copy_master($ctx, $master, $dir);
    }
    $old = (array) ($ctx['config']['frame'] ?? []);
    $style = docs_frame_style_for((string) $ctx['module']);
    ps_vlog('frame template: ' . (is_file("$dir/frame.html") && is_file("$dir/frame.css") ? "$dir exists, kept" : 'built-in template, starting style "' . $style['style'] . '"' . ($master !== null ? " (master frame $master not used: docs/frame/ is partly there)" : ' (no branding/frame/ found)')));
    foreach (array_keys($style) as $key) {
        if (is_string($old[$key] ?? null) && trim($old[$key]) !== '' && !preg_match('/[<>{};]|url\s*\(/i', $old[$key])) {
            $style[$key] = trim($old[$key]);
        }
    }
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    if (!is_file("$dir/frame.html")) {
        $legacy = $ctx['docs'] . '/content/frame.html';
        copy(is_file($legacy) ? $legacy : DOCS_RESOURCES . '/docs/frame/frame.html', "$dir/frame.html");
        $created[] = 'docs/frame/frame.html' . (is_file($legacy) ? ' (from docs/content/frame.html)' : '');
    }
    if (!is_file("$dir/frame.css")) {
        $css = strtr((string) file_get_contents(DOCS_RESOURCES . '/docs/frame/frame.css'), [
            '%%module%%' => (string) $ctx['module'],
            '%%style%%' => $style['style'],
            '%%background%%' => $style['background'],
            '%%frame%%' => $style['frame'],
            '%%text%%' => $style['text'],
            '%%accent%%' => $style['accent'],
            '%%device%%' => $style['device'],
        ]);
        if (is_int($old['radius'] ?? null)) {
            $css = str_replace('--radius: 28px;', '--radius: ' . $old['radius'] . 'px;', $css);
        }
        if (is_string($old['font'] ?? null) && trim($old['font']) !== '' && !preg_match('/[<>{};"]/', $old['font'])) {
            $css = str_replace('--font: var(--lang-fonts);', '--font: "' . trim($old['font']) . '", var(--lang-fonts);', $css);
        }
        $legacy = $ctx['docs'] . '/content/frame.css';
        if (is_file($legacy)) {
            $css .= "\n/* From docs/content/frame.css */\n" . (string) file_get_contents($legacy);
        }
        file_put_contents("$dir/frame.css", $css);
        $created[] = 'docs/frame/frame.css (starting style "' . $style['style'] . '")';
    }

    return $created;
}

/**
 * The master frame of the workspace: branding/frame/ (frame.html + frame.css) in a parent directory of the
 * repository; null when absent.
 *
 * @param array<string, mixed> $ctx
 */
function docs_frame_master(array $ctx): ?string
{
    for ($dir = (string) $ctx['repo'], $i = 0; $i < 5 && $dir !== dirname($dir); $dir = dirname($dir), ++$i) {
        if (is_file("$dir/branding/frame/frame.html") && is_file("$dir/branding/frame/frame.css")) {
            return str_replace('\\', '/', "$dir/branding/frame");
        }
    }

    return null;
}

/**
 * Copies the master frame into docs/frame/: frame.html, frame.css (%%module%% filled in) and every file frame.css or
 * frame.html refers to (fonts, images; also outside the master folder, e.g. ../font/Poppins/*.ttf), which land in
 * docs/frame/assets/ with the references rewritten. Returns the created files.
 *
 * @param array<string, mixed> $ctx
 *
 * @return string[]
 */
function docs_frame_copy_master(array $ctx, string $master, string $dir): array
{
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    $created = [];
    foreach (['frame.html' => false, 'frame.css' => true] as $file => $css) {
        $text = str_replace('%%module%%', (string) $ctx['module'], (string) file_get_contents("$master/$file"));
        foreach (docs_frame_references($text, $css) as [$match, $ref]) {
            $source = realpath("$master/$ref");
            if ($source === false || !is_file($source)) {
                continue;
            }
            if (!is_dir("$dir/assets")) {
                mkdir("$dir/assets", 0775, true);
            }
            copy($source, "$dir/assets/" . basename($source));
            $text = str_replace($match, str_replace($ref, 'assets/' . basename($source), $match), $text);
            $created[] = 'docs/frame/assets/' . basename($source);
        }
        file_put_contents("$dir/$file", $text);
        $created[] = "docs/frame/$file (from the master frame " . $master . ')';
    }

    return array_values(array_unique($created));
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
    $raw = $ctx['config']['frame'] ?? [];
    if (!is_array($raw)) {
        $add('error', '*', $file, 'frame must be an object with the settings of the framed screenshots (width, height, caption, ...)');

        return;
    }
    if (($raw['enabled'] ?? true) === false) {
        $add('warning', '*', $file, 'frame.enabled is false: the screenshots are not framed (Addons product images and manuals use the raw screenshots)');

        return;
    }
    $legacy = array_values(array_intersect(DOCS_FRAME_LEGACY, array_keys($raw)));
    if ($legacy !== []) {
        $add('warning', '*', $file, 'frame.' . implode(', frame.', $legacy) . ': not used any more, the look of the frame is in docs/frame/frame.css (remove ' . (count($legacy) > 1 ? 'them' : 'it') . ' here)');
    }
    $frame = docs_frame_config($ctx);
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
    if (!is_string($frame['address']) || preg_match('/[<>]/', $frame['address'])) {
        $add('error', '*', $file, 'frame.address must be plain text (shown in the browser address bar)');
    }
    foreach ((array) $frame['engines'] as $engine) {
        if (!in_array($engine, ['docker', 'chrome'], true)) {
            $add('error', '*', $file, "frame.engines: unknown engine '$engine' (docker, chrome)");
        }
    }
    foreach (['frame.html', 'frame.css'] as $old) {
        if (is_file($ctx['docs'] . "/content/$old")) {
            $add('warning', '*', "docs/content/$old", "not used any more: the frame template of the module is docs/frame/$old (docs init took it over when it created docs/frame/; remove this file)");
        }
    }
    $template = docs_frame_template($ctx);
    if ($template['error'] !== '') {
        $add('error', '*', 'docs/frame/', $template['error']);

        return;
    }
    foreach (['{{css}}', '{{image}}', '{{title}}'] as $placeholder) {
        if (!str_contains($template['html'], $placeholder)) {
            $add('error', '*', 'docs/frame/frame.html', "the template has no $placeholder (compare with module-dev-tools resources/docs/frame/frame.html)");
        }
    }
    if (!str_contains($template['html'], 'dataset.ready')) {
        $add('error', '*', 'docs/frame/frame.html', "the template must set document.body.dataset.ready = '1' when the screenshot is placed (keep the script of resources/docs/frame/frame.html)");
    }
    foreach (['frame.html' => $template['html'], 'frame.css' => $template['css']] as $name => $text) {
        // Comments (the explanations in the template) do not count.
        $text = (string) preg_replace($name === 'frame.css' ? '~/\*.*?\*/~s' : '~<!--.*?-->~s', '', $text);
        if (preg_match('/@import|url\s*\(\s*[\'"]?\s*(?:https?:)?\/\/|\b(?:src|href)\s*=\s*[\'"]\s*(?:https?:)?\/\//i', $text)) {
            $add('error', '*', "docs/frame/$name", 'no external files (@import, http..., //...): the frames are rendered offline; put images and fonts in docs/frame/');
        }
        foreach (docs_frame_references($text, $name === 'frame.css') as [, $ref]) {
            if (docs_frame_asset($ctx, $ref) === '') {
                $add('error', '*', "docs/frame/$name", "'$ref' not found in docs/frame/ (images: " . implode(', ', array_keys(DOCS_FRAME_ASSETS)) . ')');
            }
        }
    }
    // Readability of the caption, when the colours are plain #rrggbb variables.
    $css = (string) preg_replace('~/\*.*?\*/~s', '', $template['css']);
    $var = static fn (string $name): string => preg_match('/--' . $name . '\s*:\s*(#[0-9a-fA-F]{6})\s*;/', $css, $m) ? $m[1] : '';
    if ($var('frame') !== '' && $var('text') !== '') {
        $ratio = docs_contrast($var('frame'), $var('text'));
        if ($ratio < 4.5) {
            $add('warning', '*', 'docs/frame/frame.css', sprintf('--text on --frame has a contrast of %.1f:1; at least 4.5:1 is needed to read the caption easily', $ratio));
        }
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
 * The CSS of a frame: the variables set per image (size, title size, fonts of the language), then the
 * module's docs/frame/frame.css (its images and fonts embedded; for a preview: linked to the live file).
 *
 * @param array<string, mixed> $ctx
 * @param array<string, mixed> $frame
 * @param array{html: string, css: string, error: string} $template
 */
function docs_frame_css(array $ctx, string $iso, array $frame, string $title, array $template, bool $preview = false): string
{
    // Longer titles (or wide scripts) get a smaller font, so a caption stays at two or three lines.
    $wide = preg_match_all('/[\x{3000}-\x{9fff}\x{ac00}-\x{d7af}\x{ff00}-\x{ffef}]/u', $title);
    $length = mb_strlen($title) + 0.9 * $wide;
    $size = $length <= 38 ? 54 : ($length <= 56 ? 48 : 42);
    $vars = [
        '--canvas-w' => (int) $frame['width'] . 'px',
        '--canvas-h' => (int) $frame['height'] . 'px',
        '--title-size' => $size . 'px',
        '--lang-fonts' => docs_font_stack($iso),
    ];
    $root = "/* Set by ps-module for this image */\n:root {\n";
    foreach ($vars as $name => $value) {
        $root .= "    $name: $value;\n";
    }
    $root .= "}\n";
    if ($preview) {
        // Preview: the live docs/frame/frame.css, so an edit shows after a browser refresh.
        return $root . '</style><link rel="stylesheet" href="' . docs_e(docs_file_url(docs_frame_dir($ctx) . '/frame.css')) . '"><style>';
    }

    return $root . "\n/* docs/frame/frame.css */\n" . docs_frame_embed($ctx, (string) preg_replace('~/\*.*?\*/~s', '', $template['css']), true);
}

/**
 * The filled frame page of one screenshot in one language (from the module's template docs/frame/).
 *
 * @param array<string, mixed> $ctx
 * @param array<string, mixed> $shot
 * @param array{title: string, text: string} $caption
 * @param array<string, mixed> $frame
 * @param array{html: string, css: string, error: string} $template
 */
function docs_frame_html(array $ctx, string $iso, array $shot, string $image, array $caption, int $number, int $count, array $frame, array $template, bool $preview = false): string
{
    $size = @getimagesize($image) ?: [1, 1];
    $device = docs_frame_device($shot);
    $portrait = in_array($device, ['mobile', 'tablet'], true) ? $size[1] > $size[0] : ($device !== 'desktop' && $size[1] > $size[0] * 1.1);
    $logoFile = $ctx['module_dir'] . '/logo.png';
    $uri = static fn (string $file): string => $preview ? docs_file_url($file) : docs_data_uri($file);
    $values = [
        '{{css}}' => docs_frame_css($ctx, $iso, $frame, $caption['title'], $template, $preview),
        '{{image}}' => $uri($image),
        '{{text_html}}' => $caption['text'] !== '' ? '<p class="text">' . docs_e($caption['text']) . '</p>' : '',
        '{{logo_html}}' => $frame['logo'] !== false && is_file($logoFile) ? '<img class="brand-logo" src="' . $uri($logoFile) . '" alt="">' : '',
        '{{lang}}' => docs_e($iso),
        '{{dir}}' => docs_is_rtl($ctx, $iso) ? 'rtl' : 'ltr',
        '{{title}}' => docs_e($caption['title']),
        '{{device}}' => $device,
        '{{layout}}' => $portrait ? 'portrait' : 'landscape',
        '{{caption}}' => $frame['caption'] === 'bottom' ? 'bottom' : 'top',
        '{{area}}' => docs_e((string) ($shot['area'] ?? '')),
        '{{display_name}}' => docs_e((string) $ctx['display_name']),
        '{{number}}' => $frame['counter'] !== false ? (string) $number : '',
        '{{count}}' => $frame['counter'] !== false ? (string) $count : '',
        '{{address}}' => docs_e((string) $frame['address']),
        '{{id}}' => docs_e((string) ($shot['id'] ?? '')),
    ];
    $html = strtr(docs_frame_embed($ctx, $template['html'], false, $preview), $values);
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
    $template = docs_frame_template($ctx);
    if ($template['error'] !== '') {
        return ['ok' => false, 'message' => 'Framed screenshots NOT made: ' . $template['error'], 'warnings' => ['framed screenshots NOT made: ' . $template['error']], 'rendered' => 0];
    }
    $manifestFile = $ctx['docs'] . '/framed/manifest.json';
    $manifest = docs_read_json($manifestFile) ?? [];
    $items = (array) ($manifest['items'] ?? []);
    $shots = array_values(array_filter((array) ($ctx['config']['screenshots'] ?? []), 'is_array'));
    $jobDir = $ctx['repo'] . '/.cache/docs/frames';
    ps_vlog('frames: ' . $frame['width'] . 'x' . $frame['height'] . ', caption ' . $frame['caption'] . ', engines ' . implode(' > ', (array) $frame['engines']) . '; languages ' . implode(' ', $languages) . ($only !== [] ? '; only ' . implode(',', $only) : '') . ($force ? '; --force' : ''));
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
            $html = docs_frame_html($local, $iso, $shot, $image, $caption, $n + 1, count($list), $frame, $template);
            $hash = substr(sha1($html . '|' . $frame['width'] . 'x' . $frame['height']), 0, 16);
            $out = $ctx['docs'] . "/framed/$iso/$id.png";
            if (!$force && ($items[$key] ?? '') === $hash && is_file($out)) {
                ++$unchanged;
                ps_vlog("frame $key: unchanged, skipped ($out)");
                continue;
            }
            ps_vlog("frame $key: to render from $image" . ($caption['fallback'] !== '' ? " (caption of {$caption['fallback']})" : '') . ', ' . ($force ? '--force' : (is_file($out) ? 'input changed' : 'not there yet')));
            $htmlFile = "$jobDir/" . str_replace('/', '-', $key) . '.html';
            file_put_contents($htmlFile, $html);
            $todo[$key] = ['html' => $htmlFile, 'out' => $out, 'hash' => $hash];
        }
    }
    // Frames of screenshots or languages that no longer exist.
    foreach (glob($ctx['docs'] . '/framed/*/*.png') ?: [] as $old) {
        $key = basename(dirname($old)) . '/' . basename($old, '.png');
        if (!isset($current[$key]) && $only === []) {
            ps_vlog("frame $key: removed (no such screenshot or language any more)");
            @unlink($old);
            unset($items[$key]);
        }
    }
    $engine = '';
    $error = '';
    if ($todo !== []) {
        foreach ((array) $frame['engines'] as $candidate) {
            ps_vlog("frames engine $candidate: trying for " . count($todo) . ' frame(s)');
            $start = microtime(true);
            $result = $candidate === 'docker' ? docs_frames_docker($ctx, $todo, $frame) : docs_frames_chrome($todo, $frame);
            if (isset($result['error'])) {
                $error .= ($error !== '' ? '; ' : '') . "$candidate: {$result['error']}";
                ps_vlog("frames engine $candidate: not usable, next engine ({$result['error']})");
                continue;
            }
            ps_vdone("frames engine $candidate", $start);
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
        '_comment' => 'Generated by ps-module docs: the screenshots in the frame template of the module (docs/frame/, settings in docs.json > frame, captions in docs/content/<iso>/screenshots.json). Do not edit.',
        'size' => $frame['width'] . 'x' . $frame['height'],
        'engine' => $engine !== '' ? $engine : (string) ($manifest['engine'] ?? ''),
        'items' => $items,
    ]);
    $done = count(array_filter($lines, static fn (string $l): bool => str_starts_with($l, '  ok')));
    $message = 'Framed screenshots (' . $frame['width'] . 'x' . $frame['height'] . ($engine !== '' ? ", $engine" : '') . "): $done rendered, $unchanged unchanged" . ($lines !== [] ? "\n" . implode("\n", $lines) : '');

    return ['ok' => !str_contains(implode("\n", $lines), 'FAIL'), 'message' => $message, 'warnings' => $warnings, 'rendered' => $done];
}

/**
 * Writes preview pages of the frames (.cache/docs/frame-preview/<iso>-<id>.html) that load the live
 * docs/frame/frame.css: open one in Chrome, edit frame.css, refresh. Nothing in docs/ is written.
 *
 * @param array<string, mixed> $ctx
 * @param string[] $languages
 * @param string[] $only screenshot ids (empty = all)
 *
 * @return array{ok: bool, message: string, files: string[]}
 */
function docs_preview_frames(array $ctx, array $languages, array $only = []): array
{
    $template = docs_frame_template($ctx);
    if ($template['error'] !== '') {
        return ['ok' => false, 'message' => $template['error'], 'files' => []];
    }
    $frame = docs_frame_config($ctx);
    $dir = $ctx['repo'] . '/.cache/docs/frame-preview';
    @mkdir($dir, 0775, true);
    array_map('unlink', glob("$dir/*.html") ?: []);
    $shots = array_values(array_filter((array) ($ctx['config']['screenshots'] ?? []), 'is_array'));
    $files = [];
    $skipped = [];
    foreach ($languages as $iso) {
        $local = docs_localize($ctx, $iso);
        $list = array_values(array_filter($shots, static fn (array $s): bool => empty($s['languages']) || in_array($iso, (array) $s['languages'], true)));
        foreach ($list as $n => $shot) {
            $id = (string) ($shot['id'] ?? '');
            if ($only !== [] && !in_array($id, $only, true)) {
                continue;
            }
            $image = docs_screenshot_file($ctx, $iso, $id);
            if ($image === null) {
                $skipped[] = "$iso/$id";
                continue;
            }
            $file = "$dir/$iso-$id.html";
            file_put_contents($file, docs_frame_html($local, $iso, $shot, $image, docs_caption($local, $iso, $shot), $n + 1, count($list), $frame, $template, true));
            ps_vlog("frame preview $iso/$id: wrote $file");
            $files[] = $file;
        }
    }
    $message = 'Frame previews (' . count($files) . '): open one in Chrome or Edge, edit docs/frame/frame.css, refresh. After an edit of frame.html run this again. Real images: ps-module docs frames'
        . ($files !== [] ? "\n  " . implode("\n  ", array_map('docs_file_url', $files)) : '')
        . ($skipped !== [] ? "\n  no screenshot yet (ps-module docs screenshots): " . implode(' ', $skipped) : '');

    return ['ok' => $files !== [], 'message' => $message, 'files' => $files];
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
        $items[] = ['html' => docs_container_path($ctx, $item['html']), 'out' => docs_container_path($ctx, $item['out']), 'width' => (int) $frame['width'], 'height' => (int) $frame['height'], 'transparent' => !empty($frame['transparent'])];
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
        ps_vlog("frame $key (chrome): {$item['html']} -> {$item['out']}");
        [$code, $output] = docs_exec([
            $chrome, '--headless=new', '--disable-gpu', '--no-first-run', '--no-default-browser-check', '--disable-extensions',
            '--hide-scrollbars', '--force-device-scale-factor=1', '--user-data-dir=' . $profile, '--virtual-time-budget=10000',
            ...(!empty($frame['transparent']) ? ['--default-background-color=00000000'] : []),
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
    $ok = $size !== false && $size[0] === (int) $frame['width'] && $size[1] === (int) $frame['height'];
    ps_vlog("$file: " . ($size === false ? 'no image' : $size[0] . 'x' . $size[1] . ($ok ? ', ok' : ', expected ' . (int) $frame['width'] . 'x' . (int) $frame['height'])));

    return $ok;
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
