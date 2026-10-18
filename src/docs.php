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
 * Manuals, marketing texts and screenshots of a module, for every language in i18n.json.
 * Started by bin/ps-module as: php docs.php <module-dir> <command> [options], in the repo directory.
 *
 *   ps-module docs init                  create docs/docs.json and the content files of every language (keeps existing files)
 *   ps-module docs status [--json]       what is missing or out of date, per language, file and field
 *   ps-module docs check                 validate the content only (fast, no rendering)
 *   ps-module docs screenshots [--keep] [--only=<id,...>]
 *                                        screenshots in a Docker test shop (testing.json), per language
 *   ps-module docs manual [<iso>...] [--no-pdf]
 *                                        render docs/manual/<iso>/manual.html (+ PDF), also with open problems
 *   ps-module docs frames [<iso>...] [--only=<id,...>] [--force] [--preview]
 *                                        put the screenshots in the frame template of the module (docs/frame/):
 *                                        docs/framed/<iso>/<id>.png; --preview: pages to try template edits
 *   ps-module docs marketing [<iso>...]  render docs/marketing/<iso>/marketing.html, .md and .json
 *   ps-module docs build [--no-screenshots] [--screenshots] [--keep]
 *                                        definition of done: check + screenshots (when out of date) + framed
 *                                        screenshots + manuals + PDF + marketing + docs/index.html; records 'docs'
 *   ps-module docs mark-translated <iso|all> [manual|marketing|screenshots]
 *                                        record that a translation is up to date with the source language
 *   ps-module docs preview <iso> [--pages=1-3]
 *                                        PDF pages as PNG in .cache/docs/preview/ (to look at the result)
 *   ps-module docs doctor                which screenshot and PDF engines are available here
 *
 * Content (written by Claude): docs/content/<iso>/manual.md, docs/content/<iso>/marketing.json,
 * docs/content/<iso>/screenshots.json (captions on the frames), docs/docs.json > marketing (category, price,
 * benefits, multistore) and > frame (settings of the framed screenshots), docs/content/logo-suggestion.svg.
 * The module's frame template: docs/frame/frame.html + frame.css (+ images/fonts), created once by init and
 * then edited by hand only: no tool writes to docs/frame/.
 * Generated: docs/screenshots/<iso>/<id>.png, docs/framed/<iso>/<id>.png, docs/manual/<iso>/manual.html + readme_<iso>.pdf (the Addons name: ps-module build copies it to <module>/docs/),
 * docs/marketing/<iso>/marketing.{html,md,json}, docs/index.html, docs/build-manifest.json.
 */

declare(strict_types=1);

require_once __DIR__ . '/dod.php';
require_once __DIR__ . '/docs-markdown.php';
require_once __DIR__ . '/docs-render.php';
require_once __DIR__ . '/docs-shots.php';
require_once __DIR__ . '/docs-frames.php';

const DOCS_RESOURCES = __DIR__ . '/../resources';
const DOCS_TODO = '/\bTODO:/';
const DOCS_FRAME_HELP = "Settings of the framed screenshots. Every screenshot is rendered into the frame template of this module, docs/frame/frame.html + frame.css (the look: colours, fonts, device, layout; edited by hand, no tool changes it), with its caption from docs/content/<iso>/screenshots.json printed on the frame: docs/framed/<iso>/<id>.png (Addons product images; the manuals use them too). width/height: image size in px (Addons: at least 1000x1000); caption: 'top'|'bottom' (body class caption-top|bottom; portrait screenshots: next to the device). Optional: address (text in the browser address bar), logo (false: no module logo), counter (false: no '1 / 4'), manual (false: manuals use the raw screenshots), enabled (false: no frames), engines (['docker','chrome']). Try template edits: ps-module docs frames --preview.";
const DOCS_RENDER_PLACEHOLDERS = ['version', 'display_name', 'module_name', 'ps_min', 'ps_tested', 'php_min', 'author'];

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__)) {
    exit(docs_main(array_slice($argv, 1)));
}

/**
 * Entry point: parses the arguments and runs a command; returns the exit code.
 *
 * @param string[] $argv module directory, command and options
 */
function docs_main(array $argv): int
{
    $moduleDir = dod_norm((string) array_shift($argv));
    $command = array_shift($argv) ?? 'help';
    $flags = array_values(array_filter($argv, static fn (string $a): bool => str_starts_with($a, '--')));
    $args = array_values(array_filter($argv, static fn (string $a): bool => !str_starts_with($a, '--')));
    $repoDir = dod_repo_dir($moduleDir);
    if ($repoDir === null || !is_dir($moduleDir)) {
        fwrite(STDERR, "ps-module docs: no module repository found (module.spec.json).\n");

        return 2;
    }
    $ctx = docs_context(dod_norm($repoDir), $moduleDir);
    if ($command !== 'init' && $command !== 'doctor' && $command !== 'help' && $ctx['config'] === null) {
        fwrite(STDERR, "ps-module docs: docs/docs.json is missing. Create it with: vendor/bin/ps-module docs init\n");

        return 1;
    }

    switch ($command) {
        case 'init':
            return docs_cmd_init($ctx);
        case 'status':
            return docs_cmd_status($ctx, in_array('--json', $flags, true));
        case 'check':
            return docs_cmd_check($ctx);
        case 'screenshots':
            return docs_cmd_screenshots($ctx, $flags);
        case 'manual':
            return docs_cmd_manual($ctx, $args, !in_array('--no-pdf', $flags, true));
        case 'frames':
            return docs_cmd_frames($ctx, $args, $flags);
        case 'marketing':
            return docs_cmd_marketing($ctx, $args);
        case 'build':
            return docs_cmd_build($ctx, $flags);
        case 'mark-translated':
            return docs_cmd_mark_translated($ctx, $args);
        case 'preview':
            return docs_cmd_preview($ctx, $args, $flags);
        case 'doctor':
            return docs_cmd_doctor($ctx);
        default:
            $help = (string) file_get_contents(__FILE__);
            preg_match('#/\*\n(.*?)\*/#s', substr($help, (int) strpos($help, '/*', 10)), $m);
            echo trim((string) preg_replace('/^ \* ?/m', '', $m[1] ?? '')) . "\n";

            return $command === 'help' ? 0 : 2;
    }
}

// ---------------------------------------------------------------------------
// Context

/**
 * Everything the commands need about the module: paths, version, names, languages and docs.json.
 *
 * @return array<string, mixed>
 */
function docs_context(string $repoDir, string $moduleDir): array
{
    $name = basename($moduleDir);
    $main = (string) @file_get_contents("$moduleDir/$name.php");
    $spec = docs_read_json("$repoDir/module.spec.json") ?? [];
    $i18n = docs_read_json("$repoDir/i18n.json") ?? [];
    $testing = docs_read_json("$repoDir/testing.json");
    $catalog = docs_read_json(DOCS_RESOURCES . '/languages.json')['languages'] ?? [];
    preg_match('/\$this->version\s*=\s*[\'"]([^\'"]+)/', $main, $version);
    preg_match('/\$this->displayName\s*=\s*\$this->(?:trans|l)\(\s*[\'"]((?:[^\'"\\\\]|\\\\.)+)/', $main, $display);
    preg_match('/\'min\'\s*=>\s*[\'"]([\d.]+)/', $main, $psMin);
    $source = (string) ($i18n['source'] ?? 'en');
    $languages = array_values(array_unique(array_merge([$source], array_map('strval', (array) ($i18n['languages'] ?? [$source])))));
    $tested = array_map('strval', (array) ($testing['versions'] ?? []));
    usort($tested, 'version_compare');

    return [
        'repo' => $repoDir,
        'module_dir' => $moduleDir,
        'docs' => "$repoDir/docs",
        'module' => $name,
        'version' => $version[1] ?? '0.0.0',
        'display_name' => (string) ($spec['display_name'] ?? stripslashes($display[1] ?? $name)),
        'description' => (string) ($spec['description'] ?? ''),
        'author' => (string) ($spec['author'] ?? ''),
        'distribution' => (string) ($spec['distribution'] ?? ''),
        'ps_min' => $psMin[1] ?? (string) ($spec['ps_min'] ?? ''),
        'ps_tested' => $tested !== [] ? (string) end($tested) : ($psMin[1] ?? ''),
        'ps_oldest' => $tested !== [] ? (string) reset($tested) : ($psMin[1] ?? ''),
        'php_min' => (string) ($spec['php_min'] ?? ''),
        'source' => $source,
        'languages' => $languages,
        'catalog' => $catalog,
        'testing' => $testing,
        'config' => docs_read_json("$repoDir/docs/docs.json"),
        'config_error' => is_file("$repoDir/docs/docs.json") && docs_read_json("$repoDir/docs/docs.json") === null,
    ];
}

/**
 * Reads a JSON file as an array; null when it is missing or invalid.
 *
 * @return array<mixed>|null
 */
function docs_read_json(string $file): ?array
{
    if (!is_file($file)) {
        return null;
    }
    $data = json_decode((string) file_get_contents($file), true);

    return is_array($data) ? $data : null;
}

/**
 * Writes an array as pretty-printed JSON with a trailing newline.
 *
 * @param array<mixed> $data
 */
function docs_write_json(string $file, array $data): void
{
    if (!is_dir(dirname($file))) {
        mkdir(dirname($file), 0775, true);
    }
    file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
}

/**
 * Path of a content file of a language: manual.md or marketing.json.
 *
 * @param array<string, mixed> $ctx
 */
function docs_content_file(array $ctx, string $iso, string $kind): string
{
    return $ctx['docs'] . "/content/$iso/" . ($kind === 'manual' ? 'manual.md' : 'marketing.json');
}

/**
 * A path relative to the repository, for messages.
 *
 * @param array<string, mixed> $ctx
 */
function docs_rel(array $ctx, string $path): string
{
    $path = str_replace('\\', '/', $path);

    return str_starts_with($path, $ctx['repo'] . '/') ? substr($path, strlen($ctx['repo']) + 1) : $path;
}

/**
 * True when the language is written right to left.
 *
 * @param array<string, mixed> $ctx
 */
function docs_is_rtl(array $ctx, string $iso): bool
{
    return !empty($ctx['catalog'][$iso]['rtl']);
}

// ---------------------------------------------------------------------------
// Content: manual (Markdown with front matter) and marketing (JSON)

/**
 * Splits "---\nkey: value\n---\nbody" into the metadata and the body.
 *
 * @return array{0: array<string, string>, 1: string}
 */
function docs_front_matter(string $text): array
{
    $text = str_replace("\r\n", "\n", $text);
    if (str_starts_with($text, "\xEF\xBB\xBF")) {
        $text = substr($text, 3);
    }
    if (!preg_match('/^---\n(.*?)\n---[ \t]*\n?(.*)$/s', $text, $m)) {
        return [[], $text];
    }
    $meta = [];
    foreach (explode("\n", $m[1]) as $line) {
        if (preg_match('/^([a-z_]+):[ \t]*(.*)$/', $line, $kv)) {
            $value = trim($kv[2]);
            if (strlen($value) >= 2 && (($value[0] === '"' && str_ends_with($value, '"')) || ($value[0] === "'" && str_ends_with($value, "'")))) {
                $value = substr($value, 1, -1);
            }
            $meta[$kv[1]] = $value;
        }
    }

    return [$meta, $m[2]];
}

/**
 * Builds a Markdown file with front matter.
 *
 * @param array<string, string> $meta
 */
function docs_build_front_matter(array $meta, string $body): string
{
    $lines = ['---'];
    foreach ($meta as $key => $value) {
        $lines[] = "$key: $value";
    }
    $lines[] = '---';

    return implode("\n", $lines) . "\n" . ltrim($body, "\n");
}

/**
 * Loads the manual of a language: metadata, body and sections; null when the file does not exist.
 *
 * @param array<string, mixed> $ctx
 *
 * @return array{meta: array<string, string>, body: string, sections: array<int, array{title: string, text: string}>}|null
 */
function docs_load_manual(array $ctx, string $iso): ?array
{
    $file = docs_content_file($ctx, $iso, 'manual');
    if (!is_file($file)) {
        return null;
    }
    [$meta, $body] = docs_front_matter((string) file_get_contents($file));

    return ['meta' => $meta, 'body' => $body, 'sections' => docs_manual_sections($body)];
}

/**
 * The "## " sections of a manual body (text before the first section counts as section "(intro)" when it has content).
 *
 * @return array<int, array{title: string, text: string}>
 */
function docs_manual_sections(string $body): array
{
    $body = (string) preg_replace('/<!--.*?-->/s', '', $body);
    $parts = preg_split('/^(?=## )/m', $body) ?: [];
    $sections = [];
    foreach ($parts as $part) {
        if (str_starts_with($part, '## ')) {
            $title = trim(strtok(substr($part, 3), "\n") ?: '');
            $sections[] = ['title' => $title, 'text' => $part];
        } elseif (trim($part) !== '') {
            $sections[] = ['title' => '(intro)', 'text' => $part];
        }
    }

    return $sections;
}

/**
 * Short hash of a text, independent of whitespace and comments: used to see whether a translation is still up to date.
 */
function docs_hash(string $text): string
{
    $text = (string) preg_replace('/<!--.*?-->/s', '', $text);

    return substr(sha1(trim((string) preg_replace('/\s+/u', ' ', $text))), 0, 8);
}

/**
 * Hashes of the sections of a manual, in order.
 *
 * @param array{sections: array<int, array{title: string, text: string}>} $manual
 *
 * @return string[]
 */
function docs_manual_hashes(array $manual): array
{
    return array_map(static fn (array $s): string => docs_hash($s['text']), $manual['sections']);
}

/**
 * Loads marketing.json of a language; null when missing, ['_error' => ...] when invalid JSON.
 *
 * @param array<string, mixed> $ctx
 *
 * @return array<string, mixed>|null
 */
function docs_load_marketing(array $ctx, string $iso): ?array
{
    $file = docs_content_file($ctx, $iso, 'marketing');
    if (!is_file($file)) {
        return null;
    }
    $data = json_decode((string) file_get_contents($file), true);

    return is_array($data) ? $data : ['_error' => json_last_error_msg()];
}

/**
 * The marketing field definitions (resources/docs/marketing-fields.json).
 *
 * @return array<string, array<string, mixed>>
 */
function docs_marketing_fields(): array
{
    return docs_read_json(DOCS_RESOURCES . '/docs/marketing-fields.json')['fields'] ?? [];
}

/**
 * Hashes of the language fields of a marketing file.
 *
 * @param array<string, mixed> $marketing
 *
 * @return array<string, string>
 */
function docs_marketing_hashes(array $marketing): array
{
    $hashes = [];
    foreach (docs_marketing_fields() as $key => $field) {
        if ($field['scope'] === 'language') {
            $hashes[$key] = docs_hash((string) json_encode($marketing[$key] ?? null, JSON_UNESCAPED_UNICODE));
        }
    }

    return $hashes;
}

/**
 * Hash of all hand-written docs content: docs.json, everything in docs/content/ and the frame template docs/frame/.
 *
 * @param array<string, mixed> $ctx
 */
function docs_content_hash(array $ctx): string
{
    return dod_docs_hash(dirname((string) $ctx['docs']));
}

// ---------------------------------------------------------------------------
// Validation

/**
 * Validates all content: docs.json, every manual and marketing file, translations, version. Returns the problems.
 *
 * @param array<string, mixed> $ctx
 *
 * @return array<int, array{level: string, lang: string, file: string, message: string}>
 */
function docs_validate(array $ctx): array
{
    $issues = [];
    $add = static function (string $level, string $lang, string $file, string $message) use (&$issues): void {
        $issues[] = ['level' => $level, 'lang' => $lang, 'file' => $file, 'message' => $message];
    };
    if ($ctx['config_error']) {
        $add('error', '*', 'docs/docs.json', 'invalid JSON: ' . json_last_error_msg());

        return $issues;
    }
    $config = (array) $ctx['config'];
    foreach ($ctx['languages'] as $iso) {
        if (!isset($ctx['catalog'][$iso])) {
            $add('error', $iso, 'i18n.json', "unknown language '$iso' (see module-dev-tools resources/languages.json)");
        }
    }
    foreach (glob($ctx['docs'] . '/content/*', GLOB_ONLYDIR) ?: [] as $dir) {
        if (!in_array(basename($dir), $ctx['languages'], true)) {
            $add('warning', basename($dir), docs_rel($ctx, $dir), 'content for a language that is not in i18n.json (remove it or add the language)');
        }
    }
    docs_validate_config($ctx, $config, $add);
    docs_validate_frame($ctx, $add);
    docs_validate_marketing_common($ctx, (array) ($config['marketing'] ?? []), $add);

    $sourceManual = docs_load_manual($ctx, $ctx['source']);
    $sourceMarketing = docs_load_marketing($ctx, $ctx['source']);
    $sourceCaptions = docs_load_captions($ctx, $ctx['source']);
    foreach ($ctx['languages'] as $iso) {
        docs_validate_manual($ctx, $iso, $sourceManual, $add);
        docs_validate_marketing($ctx, $iso, $sourceMarketing, $add);
        docs_validate_captions($ctx, $iso, $sourceCaptions, $add);
    }

    return $issues;
}

/**
 * Validates docs.json: shop settings and the list of screenshots.
 *
 * @param array<string, mixed> $ctx
 * @param array<string, mixed> $config
 * @param callable(string, string, string, string): void $add
 */
function docs_validate_config(array $ctx, array $config, callable $add): void
{
    $file = 'docs/docs.json';
    $ids = [];
    foreach ((array) ($config['screenshots'] ?? []) as $n => $shot) {
        $label = 'screenshots[' . $n . ']';
        if (!is_array($shot)) {
            $add('error', '*', $file, "$label must be an object");
            continue;
        }
        $id = (string) ($shot['id'] ?? '');
        if (!preg_match('/^[a-z0-9][a-z0-9-]{0,40}$/', $id)) {
            $add('error', '*', $file, "$label.id '$id' must be lowercase letters, digits and dashes");
        } elseif (isset($ids[$id])) {
            $add('error', '*', $file, "$label.id '$id' is used twice");
        }
        $ids[$id] = true;
        if (!in_array($shot['area'] ?? '', ['bo', 'fo'], true)) {
            $add('error', '*', $file, "$label.area must be \"bo\" (back office) or \"fo\" (front office)");
        }
        if (trim((string) ($shot['path'] ?? '')) === '') {
            $add('error', '*', $file, "$label.path is empty (fo: e.g. \"/\"; bo: \"@configure\", \"@menu:1\", a Symfony route or index.php?controller=AdminX)");
        }
        $viewport = $shot['viewport'] ?? 'desktop';
        if (!(is_array($viewport) && isset($viewport['width'], $viewport['height'])) && !in_array($viewport, ['desktop', 'tablet', 'mobile'], true)) {
            $add('error', '*', $file, "$label.viewport must be desktop, tablet, mobile or {\"width\": .., \"height\": ..}");
        }
        $shotVersion = (string) ($shot['version'] ?? '');
        if (!in_array($shotVersion, ['', 'latest', 'oldest'], true) && !in_array($shotVersion, (array) ($ctx['testing']['versions'] ?? []), true)) {
            $add('error', '*', $file, "$label.version $shotVersion is not a test version in testing.json (" . implode(', ', (array) ($ctx['testing']['versions'] ?? [])) . ')');
        }
        foreach ((array) ($shot['languages'] ?? []) as $iso) {
            if (!in_array($iso, $ctx['languages'], true)) {
                $add('error', '*', $file, "$label.languages: '$iso' is not in i18n.json");
            }
        }
    }
    $version = (string) ($config['shop']['version'] ?? 'latest');
    if ($version !== 'latest' && is_array($ctx['testing']) && !in_array($version, (array) ($ctx['testing']['versions'] ?? []), true)) {
        $add('warning', '*', $file, "shop.version $version is not one of the test versions in testing.json");
    }
}

/**
 * Validates the language-independent marketing fields in docs.json > marketing.
 *
 * @param array<string, mixed> $ctx
 * @param array<string, mixed> $marketing
 * @param callable(string, string, string, string): void $add
 */
function docs_validate_marketing_common(array $ctx, array $marketing, callable $add): void
{
    $file = 'docs/docs.json';
    $fields = docs_marketing_fields();
    $categories = docs_read_json(DOCS_RESOURCES . '/addons/categories.json') ?? [];
    $benefits = docs_read_json(DOCS_RESOURCES . '/addons/benefits.json') ?? [];
    $multistore = docs_read_json(DOCS_RESOURCES . '/addons/multistore-options.json') ?? [];

    $category = (string) ($marketing['category'] ?? '');
    if (preg_match(DOCS_TODO, $category) || $category === '') {
        $add('error', '*', $file, 'marketing.category: choose the best-fitting category from resources/addons/categories.json');
    } elseif (!in_array($category, $categories, true)) {
        $close = docs_closest($category, $categories);
        $add('error', '*', $file, "marketing.category '$category' is not an Addons category" . ($close !== '' ? " (did you mean '$close'?)" : ''));
    }

    $price = $marketing['price'] ?? null;
    $min = (float) $fields['price']['min'];
    $max = (float) $fields['price']['max'];
    if (!is_int($price) && !is_float($price)) {
        $add('error', '*', $file, "marketing.price must be a number between $min and $max");
    } elseif ($price < $min || $price > $max) {
        $add('error', '*', $file, "marketing.price $price is outside $min - $max");
    } elseif (abs($price * 100 - round($price * 100)) > 1e-6) {
        $add('error', '*', $file, "marketing.price $price has more than two decimals");
    }

    $list = (array) ($marketing['benefits'] ?? []);
    $def = $fields['benefits'];
    if (count($list) < $def['min_items'] || count($list) > $def['max_items']) {
        $add('error', '*', $file, "marketing.benefits: choose {$def['min_items']}-{$def['max_items']} benefits");
    }
    $all = [];
    foreach ($benefits as $group => $items) {
        foreach ((array) $items as $item) {
            $all[] = "$group › $item";
        }
    }
    foreach ($list as $benefit) {
        $benefit = (string) $benefit;
        if (preg_match(DOCS_TODO, $benefit)) {
            $add('error', '*', $file, 'marketing.benefits: replace the TODO with "Group › Benefit" from resources/addons/benefits.json');
            continue;
        }
        [$group, $item] = array_map('trim', explode('›', $benefit, 2) + [1 => '']);
        if (!isset($benefits[$group])) {
            $add('error', '*', $file, "marketing.benefits: unknown group '$group' (groups: " . implode(', ', array_keys($benefits)) . ')');
        } elseif (!in_array($item, (array) $benefits[$group], true)) {
            $close = docs_closest($benefit, $all);
            $add('error', '*', $file, "marketing.benefits: '$item' is not a benefit of '$group'" . ($close !== '' ? " (did you mean '$close'?)" : ''));
        }
    }

    $required = (string) ($multistore['required'] ?? 'Fully');
    if (($marketing['multistore'] ?? null) !== $required) {
        $add('error', '*', $file, "marketing.multistore must be \"$required\": PrestaShopHeaven modules are always fully multistore compatible");
    }

    $svg = $ctx['docs'] . '/content/logo-suggestion.svg';
    if (is_file($svg)) {
        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string((string) file_get_contents($svg));
        libxml_use_internal_errors($previous);
        if ($xml === false || strtolower($xml->getName()) !== 'svg') {
            $add('error', '*', 'docs/content/logo-suggestion.svg', 'not a valid SVG document');
        }
    }
}

/**
 * Validates the manual of one language.
 *
 * @param array<string, mixed> $ctx
 * @param array{meta: array<string, string>, body: string, sections: array<int, array{title: string, text: string}>}|null $source
 * @param callable(string, string, string, string): void $add
 */
function docs_validate_manual(array $ctx, string $iso, ?array $source, callable $add): void
{
    $file = docs_rel($ctx, docs_content_file($ctx, $iso, 'manual'));
    $manual = docs_load_manual($ctx, $iso);
    if ($manual === null) {
        $add('error', $iso, $file, 'missing' . ($iso === $ctx['source'] ? ' (create it with: ps-module docs init)' : " (translate docs/content/{$ctx['source']}/manual.md; ps-module docs init creates a copy to translate)"));

        return;
    }
    if ($iso !== $ctx['source'] && $source !== null && (docs_hash($manual['body']) === docs_hash($source['body']) || trim($manual['meta']['translated_from'] ?? '') === '')) {
        $add('error', $iso, $file, "not translated yet: translate docs/content/{$ctx['source']}/manual.md into {$iso} (" . ($ctx['catalog'][$iso]['name'] ?? $iso) . ") in this file, keep the front matter (language: $iso), then: ps-module docs mark-translated $iso manual");

        return;
    }
    $meta = $manual['meta'];
    docs_validate_meta($ctx, $iso, $file, $meta, $add);
    foreach (['title', 'toc_title'] as $key) {
        if (trim($meta[$key] ?? '') === '') {
            $add('error', $iso, $file, "front matter '$key' is empty");
        }
    }
    $raw = $manual['body'];
    $todos = preg_match_all(DOCS_TODO, $raw);
    if ($todos > 0) {
        $add('error', $iso, $file, "$todos TODO marker(s) left: " . docs_todo_where($manual));
    }
    $body = (string) preg_replace('/<!--.*?-->/s', '', $raw);
    if (preg_match('/^# /m', $body)) {
        $add('error', $iso, $file, 'use "## " for sections: the title comes from the front matter');
    }
    $h2 = count(array_filter($manual['sections'], static fn (array $s): bool => $s['title'] !== '(intro)'));
    if ($h2 < 3) {
        $add('error', $iso, $file, "only $h2 sections (## ...): a manual needs at least introduction, installation and configuration");
    }
    $plain = trim((string) preg_replace('/\s+/u', ' ', strip_tags(docs_md_render($body, static fn (): array => ['src' => '']))));
    $cjk = in_array($iso, ['ja', 'zh', 'ko', 'th'], true);
    $size = $cjk ? mb_strlen($plain) : count(preg_split('/\s+/u', $plain) ?: []);
    if ($size < ($cjk ? 700 : 300)) {
        $add('error', $iso, $file, sprintf('too short (%d %s, minimum %d)', $size, $cjk ? 'characters' : 'words', $cjk ? 700 : 300));
    }
    preg_match_all('/\{\{\s*([A-Za-z_]+)\s*\}\}/', $body, $placeholders);
    foreach (array_unique($placeholders[1]) as $placeholder) {
        if (!in_array($placeholder, DOCS_RENDER_PLACEHOLDERS, true)) {
            $add('error', $iso, $file, "unknown placeholder {{{$placeholder}}} (known: " . implode(', ', DOCS_RENDER_PLACEHOLDERS) . ')');
        }
    }
    $contacts = docs_contact_details($body);
    if ($contacts !== []) {
        $add($ctx['distribution'] === 'addons' ? 'error' : 'warning', $iso, $file, 'external URL, e-mail address or phone number (not allowed in the Addons readme PDF, REL-06/REL-07; support goes through the Addons contact form): ' . implode(', ', $contacts));
    }
    $ids = array_column((array) ($ctx['config']['screenshots'] ?? []), 'id');
    $refs = docs_manual_screenshot_refs($body);
    foreach ($refs as $ref) {
        if (!in_array($ref, $ids, true)) {
            $add('error', $iso, $file, "screenshot:$ref is not in docs.json > screenshots");
        }
    }
    preg_match_all('/!\[[^\]]*\]\((?!screenshot:)([^)\s]+)\)/', $body, $images);
    foreach ($images[1] as $image) {
        if (!preg_match('#^https?://#', $image) && docs_content_image($ctx, $iso, $image) === null) {
            $add('error', $iso, $file, "image '$image' not found (relative to docs/content/$iso/ or docs/content/{$ctx['source']}/)");
        }
    }
    if ($iso === $ctx['source'] || $source === null) {
        return;
    }
    // Translation: same structure as the source, up to date with it, and really translated.
    if (count($source['sections']) !== count($manual['sections'])) {
        $add('error', $iso, $file, sprintf('%d sections, the %s manual has %d: keep the same structure', count($manual['sections']), $ctx['source'], count($source['sections'])));
    }
    $sourceRefs = docs_manual_screenshot_refs((string) preg_replace('/<!--.*?-->/s', '', $source['body']));
    if ($sourceRefs !== $refs) {
        $add('error', $iso, $file, 'screenshots differ from the ' . $ctx['source'] . ' manual (' . implode(', ', $sourceRefs) . ')');
    }
    $state = docs_translation_state_manual($ctx, $manual, $source);
    if ($state !== '') {
        $add('error', $iso, $file, $state);
    }
}

/**
 * Validates the shared metadata of a content file: module, version and language.
 *
 * @param array<string, mixed> $ctx
 * @param array<string, mixed> $meta
 * @param callable(string, string, string, string): void $add
 */
function docs_validate_meta(array $ctx, string $iso, string $file, array $meta, callable $add): void
{
    if (($meta['module'] ?? '') !== $ctx['module']) {
        $add('error', $iso, $file, "module is '" . ($meta['module'] ?? '') . "', expected '{$ctx['module']}'");
    }
    if (($meta['language'] ?? '') !== $iso) {
        $add('error', $iso, $file, "language is '" . ($meta['language'] ?? '') . "', expected '$iso'");
    }
    if (($meta['version'] ?? '') !== $ctx['version']) {
        $add('error', $iso, $file, "version is '" . ($meta['version'] ?? '') . "' but the module is {$ctx['version']}: review the text for this version, then set version: {$ctx['version']}");
    }
}

/**
 * Where the TODO markers of a manual are: the section titles.
 *
 * @param array{body: string} $manual
 */
function docs_todo_where(array $manual): string
{
    $where = [];
    $section = '(intro)';
    foreach (explode("\n", $manual['body']) as $line) {
        if (str_starts_with($line, '## ')) {
            $section = trim(substr($line, 3));
        }
        if (preg_match(DOCS_TODO, $line)) {
            $where[$section] = true;
        }
    }

    return 'sections ' . implode(', ', array_map(static fn (string $s): string => "\"$s\"", array_keys($where)));
}

/**
 * The screenshot ids referenced by a manual body, in order.
 *
 * @return string[]
 */
function docs_manual_screenshot_refs(string $body): array
{
    preg_match_all('/\]\(screenshot:([a-z0-9-]+)\)/', $body, $m);

    return $m[1];
}

/**
 * The file of an own image of the manual (relative to the language folder, else the source language folder).
 *
 * @param array<string, mixed> $ctx
 */
function docs_content_image(array $ctx, string $iso, string $path): ?string
{
    if (str_contains($path, '..')) {
        return null;
    }
    foreach ([$iso, $ctx['source']] as $lang) {
        $file = $ctx['docs'] . "/content/$lang/" . ltrim($path, '/');
        if (is_file($file)) {
            return $file;
        }
    }

    return null;
}

/**
 * Translation state of a manual compared with the source: '' when up to date, otherwise what to do.
 *
 * @param array<string, mixed> $ctx
 * @param array{meta: array<string, string>, body: string, sections: array<int, array{title: string, text: string}>} $manual
 * @param array{meta: array<string, string>, body: string, sections: array<int, array{title: string, text: string}>} $source
 */
function docs_translation_state_manual(array $ctx, array $manual, array $source): string
{
    $iso = $manual['meta']['language'] ?? '?';
    $mark = "then: ps-module docs mark-translated $iso manual";
    if (docs_hash($manual['body']) === docs_hash($source['body'])) {
        return "not translated (identical to {$ctx['source']}): translate it, $mark";
    }
    $recorded = array_values(array_filter(explode(' ', $manual['meta']['translated_from'] ?? '')));
    if ($recorded === []) {
        return "translation not recorded: check it against {$ctx['source']}, $mark";
    }
    $current = docs_manual_hashes($source);
    if ($recorded === $current) {
        return '';
    }
    $changed = [];
    foreach ($current as $n => $hash) {
        if (($recorded[$n] ?? '') !== $hash) {
            $changed[] = ($n + 1) . ' "' . $source['sections'][$n]['title'] . '"';
        }
    }
    if (count($recorded) > count($current)) {
        $changed[] = 'sections removed after ' . count($current);
    }

    return "out of date: the {$ctx['source']} text changed in section(s) " . implode(', ', $changed) . ". Update the translation, $mark";
}

/**
 * Validates marketing.json of one language: fields, lengths, TODOs, translation state, SEO keywords.
 *
 * @param array<string, mixed> $ctx
 * @param array<string, mixed>|null $source
 * @param callable(string, string, string, string): void $add
 */
function docs_validate_marketing(array $ctx, string $iso, ?array $source, callable $add): void
{
    $file = docs_rel($ctx, docs_content_file($ctx, $iso, 'marketing'));
    $data = docs_load_marketing($ctx, $iso);
    if ($data === null) {
        $add('error', $iso, $file, 'missing' . ($iso === $ctx['source'] ? ' (create it with: ps-module docs init)' : " (translate docs/content/{$ctx['source']}/marketing.json; ps-module docs init creates a copy to translate)"));

        return;
    }
    if (isset($data['_error'])) {
        $add('error', $iso, $file, 'invalid JSON: ' . $data['_error']);

        return;
    }
    if ($iso !== $ctx['source'] && is_array($source) && !isset($source['_error']) && (docs_marketing_hashes($data) === docs_marketing_hashes($source) || empty($data['_meta']['translated_from']))) {
        $add('error', $iso, $file, "not translated yet: translate the texts of docs/content/{$ctx['source']}/marketing.json into {$iso} (" . ($ctx['catalog'][$iso]['name'] ?? $iso) . ") in this file, then: ps-module docs mark-translated $iso marketing");

        return;
    }
    docs_validate_meta($ctx, $iso, $file, (array) ($data['_meta'] ?? []), $add);
    foreach (docs_marketing_fields() as $key => $field) {
        if ($field['scope'] !== 'language') {
            continue;
        }
        $value = $data[$key] ?? null;
        if ($field['type'] === 'text') {
            $text = is_string($value) ? trim($value) : '';
            $length = mb_strlen(docs_plain_text($text));
            if ($text === '') {
                $add('error', $iso, $file, "$key is empty ({$field['question']})");
            } elseif (preg_match(DOCS_TODO, $text)) {
                $add('error', $iso, $file, "$key still has a TODO");
            } elseif ($length < $field['min'] || $length > $field['max']) {
                $add('error', $iso, $file, "$key has $length characters (allowed {$field['min']}-{$field['max']})");
            }
            continue;
        }
        // Lists
        if (!is_array($value) || !array_is_list($value)) {
            $add('error', $iso, $file, "$key must be a list of texts");
            continue;
        }
        $count = count($value);
        if ($count < $field['min_items'] || $count > $field['max_items']) {
            $add('error', $iso, $file, "$key has $count item(s) (allowed {$field['min_items']}-{$field['max_items']})");
        }
        $total = 0;
        foreach ($value as $n => $item) {
            $item = is_string($item) ? trim($item) : '';
            $length = mb_strlen(docs_plain_text($item));
            $total += $length;
            if ($item === '') {
                $add('error', $iso, $file, "{$key}[$n] is empty");
            } elseif (preg_match(DOCS_TODO, $item)) {
                $add('error', $iso, $file, "{$key}[$n] still has a TODO");
            } elseif ($length < ($field['item_min'] ?? 1) || $length > $field['item_max']) {
                $add('error', $iso, $file, "{$key}[$n] has $length characters (allowed " . ($field['item_min'] ?? 1) . "-{$field['item_max']})");
            }
        }
        if (isset($field['max']) && $total > $field['max']) {
            $add('error', $iso, $file, "$key has $total characters in total (maximum {$field['max']})");
        }
        if (!empty($field['warn_if_not_empty']) && $count > 0) {
            $add('warning', $iso, $file, "$key is not empty: a module without prerequisites sells better; keep only real requirements");
        }
    }
    $contacts = [];
    foreach (docs_marketing_fields() as $key => $field) {
        if ($field['scope'] === 'language' && empty($field['internal'])) {
            $contacts = array_merge($contacts, docs_contact_details(implode(PHP_EOL, array_map('strval', (array) ($data[$key] ?? '')))));
        }
    }
    if ($contacts !== []) {
        $add($ctx['distribution'] === 'addons' ? 'error' : 'warning', $iso, $file, 'external URL, e-mail address or phone number in the texts (Addons does not allow them, REL-06/REL-07): ' . implode(', ', array_unique($contacts)));
    }
    // SEO: every keyword should occur in the description or the features.
    $haystack = mb_strtolower(docs_plain_text((string) ($data['description'] ?? '') . ' ' . implode(' ', array_map('strval', (array) ($data['features'] ?? [])))));
    foreach ((array) ($data['keywords'] ?? []) as $keyword) {
        if (is_string($keyword) && $keyword !== '' && !preg_match(DOCS_TODO, $keyword) && !str_contains($haystack, mb_strtolower($keyword))) {
            $add('warning', $iso, $file, "SEO keyword '$keyword' does not occur in the description or the features");
        }
    }
    if ($iso === $ctx['source'] || $source === null || isset($source['_error'])) {
        return;
    }
    $state = docs_translation_state_marketing($ctx, $iso, $data, $source);
    if ($state !== '') {
        $add('error', $iso, $file, $state);
    }
}

/**
 * Translation state of a marketing file compared with the source: '' when up to date, otherwise what to do.
 *
 * @param array<string, mixed> $ctx
 * @param array<string, mixed> $data
 * @param array<string, mixed> $source
 */
function docs_translation_state_marketing(array $ctx, string $iso, array $data, array $source): string
{
    $mark = "then: ps-module docs mark-translated $iso marketing";
    $identical = [];
    foreach (docs_marketing_fields() as $key => $field) {
        if ($field['scope'] === 'language' && $field['type'] === 'text' && ($data[$key] ?? null) === ($source[$key] ?? null) && mb_strlen((string) ($data[$key] ?? '')) > 40) {
            $identical[] = $key;
        }
    }
    if ($identical !== []) {
        return "not translated (identical to {$ctx['source']}): " . implode(', ', $identical) . ". Translate, $mark";
    }
    $recorded = (array) ($data['_meta']['translated_from'] ?? []);
    if ($recorded === []) {
        return "translation not recorded: check it against {$ctx['source']}, $mark";
    }
    $changed = [];
    foreach (docs_marketing_hashes($source) as $key => $hash) {
        if (($recorded[$key] ?? '') !== $hash) {
            $changed[] = $key;
        }
    }

    return $changed === [] ? '' : "out of date: the {$ctx['source']} text changed in " . implode(', ', $changed) . ". Update the translation, $mark";
}

/**
 * External URLs, e-mail addresses and phone numbers in a text (at most five, for the message).
 *
 * @return string[]
 */
function docs_contact_details(string $text): array
{
    $text = (string) preg_replace('/<!--.*?-->/s', '', $text);
    preg_match_all('~\b(?:https?://[a-z0-9][^\s)\]>"\'`]*|www\.[a-z0-9-]+\.[^\s)\]>"\'`]+)~i', $text, $urls);
    preg_match_all('~[\w.+-]+@[\w-]+(?:\.[\w-]+)+~', $text, $mails);
    preg_match_all('~(?<![\w/#:-]|\d[.,])\+?\(?\d{1,4}\)?(?:[ -]?\(?\d{1,4}\)?){2,6}(?![\w/:-]|[.,]\d)~', $text, $phones);
    $phones = array_filter($phones[0], static fn (string $p): bool => strlen((string) preg_replace('/\D/', '', $p)) >= 9);

    return array_slice(array_values(array_unique(array_map(static fn (string $m): string => rtrim(trim($m), '.,;:!?'), array_merge($urls[0], $mails[0], $phones)))), 0, 5);
}

/**
 * Addons product page: enough large screenshots per language (resources/docs/marketing-fields.json > screenshots).
 *
 * @param array<string, mixed> $ctx
 *
 * @return string[] warnings
 */
function docs_addons_screenshot_check(array $ctx): array
{
    $rule = docs_read_json(DOCS_RESOURCES . '/docs/marketing-fields.json')['screenshots'] ?? null;
    if (!is_array($rule)) {
        return [];
    }
    $warnings = [];
    foreach ($ctx['languages'] as $iso) {
        $large = [];
        foreach ((array) ($ctx['config']['screenshots'] ?? []) as $shot) {
            $framed = docs_framed_file($ctx, $iso, (string) ($shot['id'] ?? ''));
            $file = $framed !== '' ? $framed : docs_screenshot_file($ctx, $iso, (string) ($shot['id'] ?? ''));
            $size = $file !== null ? @getimagesize($file) : false;
            if ($size !== false && $size[0] >= $rule['min_width'] && $size[1] >= $rule['min_height']) {
                $large[] = $shot['id'];
            }
        }
        if (count($large) < $rule['min_count']) {
            $warnings[] = "$iso: " . count($large) . " screenshot(s) of at least {$rule['min_width']}x{$rule['min_height']} px, Addons wants {$rule['min_count']} per language (add screenshots to docs.json; desktop, tablet and mobile viewports are large enough, clipped ones usually not)";
        }
    }

    return $warnings;
}

/**
 * Plain text of a Markdown-ish value (for length checks).
 */
function docs_plain_text(string $text): string
{
    $text = (string) preg_replace('/!?\[([^\]]*)\]\([^)]*\)/', '$1', $text);

    return trim(str_replace(['**', '__', '`'], '', $text));
}

/**
 * The option that is most similar to a value ('' when nothing is close).
 *
 * @param string[] $options
 */
function docs_closest(string $value, array $options): string
{
    $best = '';
    $bestScore = 0.0;
    foreach ($options as $option) {
        similar_text(mb_strtolower($value), mb_strtolower($option), $score);
        if ($score > $bestScore) {
            [$best, $bestScore] = [$option, $score];
        }
    }

    return $bestScore >= 60 ? $best : '';
}

/**
 * Prints the problems grouped by language and file; returns the number of errors.
 *
 * @param array<int, array{level: string, lang: string, file: string, message: string}> $issues
 */
function docs_print_issues(array $issues): int
{
    $errors = 0;
    $byFile = [];
    foreach ($issues as $issue) {
        $byFile[$issue['file']][] = $issue;
        $errors += $issue['level'] === 'error' ? 1 : 0;
    }
    foreach ($byFile as $file => $list) {
        echo "  $file\n";
        foreach ($list as $issue) {
            echo '    ' . ($issue['level'] === 'error' ? 'ERROR  ' : 'warning') . ' ' . $issue['message'] . "\n";
        }
    }

    return $errors;
}

// ---------------------------------------------------------------------------
// Commands

/**
 * Creates docs/docs.json and the content files for every language that does not have them yet.
 * New translations start as a copy of the source language that still has to be translated.
 *
 * @param array<string, mixed> $ctx
 */
function docs_cmd_init(array $ctx): int
{
    $skeleton = DOCS_RESOURCES . '/docs/skeleton';
    $vars = [
        'MODULE_NAME' => $ctx['module'],
        'DISPLAY_NAME' => $ctx['display_name'],
        'DESCRIPTION' => $ctx['description'] !== '' ? $ctx['description'] : $ctx['display_name'],
        'VERSION' => $ctx['version'],
    ];
    $created = [];
    $files = ['docs.json' => 'docs.json', "content/{$ctx['source']}/manual.md" => 'content/en/manual.md', "content/{$ctx['source']}/marketing.json" => 'content/en/marketing.json'];
    foreach ($files as $target => $template) {
        $path = $ctx['docs'] . '/' . $target;
        if (is_file($path)) {
            continue;
        }
        $json = str_ends_with($target, '.json');
        $content = (string) preg_replace_callback('/\{\{([A-Z_]+)\}\}/', static function (array $m) use ($vars, $json): string {
            if (!isset($vars[$m[1]])) {
                return $m[0];
            }

            return $json ? substr((string) json_encode($vars[$m[1]], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 1, -1) : $vars[$m[1]];
        }, (string) file_get_contents("$skeleton/$template"));
        $content = str_replace(["language: en\n", '"language": "en"'], ["language: {$ctx['source']}\n", '"language": "' . $ctx['source'] . '"'], $content);
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }
        file_put_contents($path, $content);
        $created[] = docs_rel($ctx, $path);
    }
    $ctx['config'] = docs_read_json($ctx['docs'] . '/docs.json');
    // The frame template of the module (docs/frame/): created once, then it belongs to the module.
    if (is_array($ctx['config']) && (array) ($ctx['config']['screenshots'] ?? []) !== []) {
        $templateFiles = docs_frame_create_template($ctx);
        $created = array_merge($created, array_map(static fn (string $f): string => "$f (the frame template of this module: edit it by hand, no tool changes it)", $templateFiles));
        $config = $ctx['config'];
        $frameBlock = is_array($config['frame'] ?? null) ? $config['frame'] : null;
        $changed = false;
        if ($frameBlock === null) {
            $config = [];
            foreach ($ctx['config'] as $key => $value) {
                $config[$key] = $value;
                if ($key === 'screenshots') {
                    $config['frame'] = ['width' => DOCS_FRAME_DEFAULTS['width'], 'height' => DOCS_FRAME_DEFAULTS['height'], 'caption' => DOCS_FRAME_DEFAULTS['caption']];
                }
            }
            $changed = true;
            $created[] = 'docs/docs.json > frame (settings: width, height, caption)';
        } elseif ($templateFiles !== [] && array_intersect(DOCS_FRAME_LEGACY, array_keys($frameBlock)) !== []) {
            // The look moved from docs.json (1.7) into the new docs/frame/frame.css.
            $config['frame'] = array_diff_key($frameBlock, array_flip(DOCS_FRAME_LEGACY));
            $changed = true;
            $created[] = 'docs/docs.json > frame (colours moved to docs/frame/frame.css)';
        }
        if (is_array($config['_help'] ?? null) && ($config['_help']['frame'] ?? '') !== DOCS_FRAME_HELP) {
            $config['_help']['frame'] = DOCS_FRAME_HELP;
            $changed = true;
        }
        if ($changed) {
            docs_write_json($ctx['docs'] . '/docs.json', $config);
            $ctx['config'] = $config;
        }
    }
    $captionsFile = docs_captions_file($ctx, $ctx['source']);
    if ((array) ($ctx['config']['screenshots'] ?? []) !== []) {
        $existing = docs_load_captions($ctx, $ctx['source']);
        if ($existing === null || !isset($existing['_error'])) {
            $captions = docs_captions_skeleton($ctx, $existing ?? []);
            if ($existing === null || array_keys($captions) !== array_keys($existing)) {
                docs_write_json($captionsFile, $captions);
                $created[] = docs_rel($ctx, $captionsFile) . ($existing === null ? '' : ' (captions added for new screenshots)');
            }
        }
    }
    foreach ($ctx['languages'] as $iso) {
        if ($iso === $ctx['source']) {
            continue;
        }
        $captionsFile = docs_captions_file($ctx, $iso);
        $sourceCaptions = docs_load_captions($ctx, $ctx['source']);
        if (!is_file($captionsFile) && $sourceCaptions !== null && !isset($sourceCaptions['_error'])) {
            $sourceCaptions['_meta']['language'] = $iso;
            unset($sourceCaptions['_meta']['translated_from']);
            docs_write_json($captionsFile, $sourceCaptions);
            $created[] = docs_rel($ctx, $captionsFile) . ' (copy of ' . $ctx['source'] . ', to translate)';
        }
        $manualFile = docs_content_file($ctx, $iso, 'manual');
        $source = docs_load_manual($ctx, $ctx['source']);
        if (!is_file($manualFile) && $source !== null) {
            $meta = $source['meta'];
            $meta['language'] = $iso;
            unset($meta['translated_from']);
            @mkdir(dirname($manualFile), 0775, true);
            file_put_contents($manualFile, docs_build_front_matter($meta, $source['body']));
            $created[] = docs_rel($ctx, $manualFile) . ' (copy of ' . $ctx['source'] . ', to translate)';
        }
        $marketingFile = docs_content_file($ctx, $iso, 'marketing');
        $sourceMarketing = docs_load_marketing($ctx, $ctx['source']);
        if (!is_file($marketingFile) && $sourceMarketing !== null && !isset($sourceMarketing['_error'])) {
            $sourceMarketing['_meta']['language'] = $iso;
            unset($sourceMarketing['_meta']['translated_from']);
            docs_write_json($marketingFile, $sourceMarketing);
            $created[] = docs_rel($ctx, $marketingFile) . ' (copy of ' . $ctx['source'] . ', to translate)';
        }
    }
    echo $created === [] ? "ps-module docs: nothing to create, all files exist.\n" : "ps-module docs: created\n  " . implode("\n  ", $created) . "\n";
    echo "Next: write the content (see TODO markers), then: vendor/bin/ps-module docs status\n";

    return 0;
}

/**
 * Prints what is missing or out of date per language, compact, with the command or file to act on.
 *
 * @param array<string, mixed> $ctx
 */
function docs_cmd_status(array $ctx, bool $json): int
{
    $issues = docs_validate($ctx);
    $shots = docs_shots_state($ctx);
    $outputs = docs_outputs_state($ctx);
    $errors = count(array_filter($issues, static fn (array $i): bool => $i['level'] === 'error'));
    if ($json) {
        echo json_encode(['module' => $ctx['module'], 'version' => $ctx['version'], 'languages' => $ctx['languages'], 'issues' => $issues, 'screenshots' => $shots, 'outputs' => $outputs, 'complete' => $errors === 0 && $shots['state'] !== 'missing'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";

        return $errors === 0 ? 0 : 1;
    }
    echo "Docs of {$ctx['module']} {$ctx['version']} - languages: " . implode(' ', $ctx['languages']) . " (source: {$ctx['source']})\n";
    foreach (array_merge(['*'], $ctx['languages']) as $iso) {
        $list = array_values(array_filter($issues, static fn (array $i): bool => $i['lang'] === $iso));
        foreach ([['manual', 'manual.md'], ['marketing', 'marketing.json'], ['screenshots', 'screenshots.json']] as [$kind, $name]) {
            if ($iso === '*') {
                break;
            }
            if ($kind === 'screenshots' && !docs_frames_enabled($ctx)) {
                continue;
            }
            $mine = array_filter($list, static fn (array $i): bool => str_ends_with($i['file'], "/$iso/$name"));
            if ($mine === []) {
                printf("  %-3s %-15s ok\n", $iso, $name);
            }
        }
        foreach ($list as $issue) {
            printf("  %-3s %-15s %s%s\n", $iso === '*' ? '' : $iso, basename($issue['file']), $issue['level'] === 'error' ? '' : '(warning) ', $issue['message']);
        }
    }
    echo '  screenshots         ' . $shots['message'] . "\n";
    if (docs_frames_enabled($ctx)) {
        $frames = docs_frames_state($ctx);
        $frameConfig = docs_frame_config($ctx);
        echo '  framed screenshots  ' . ($frames['state'] === 'ok'
            ? 'ok (' . $frameConfig['width'] . 'x' . $frameConfig['height'] . ', template docs/frame/)'
            : 'missing: ' . implode(' ', array_slice($frames['missing'], 0, 8)) . (count($frames['missing']) > 8 ? ' ...' : '') . ' (run: ps-module docs frames)') . "\n";
    }
    echo '  generated output    ' . $outputs['message'] . "\n";
    $dod = dod_status($ctx['repo'])['steps']['docs'] ?? null;
    if ($dod !== null) {
        echo '  definition of done  docs: ' . $dod['state'] . ($dod['note'] !== '' ? " ({$dod['note']})" : '') . "\n";
    }
    if ($errors > 0) {
        echo "\n$errors problem(s). Fix them in the files above, then: vendor/bin/ps-module docs build\n";

        return 1;
    }
    echo "\nContent complete." . ($outputs['state'] === 'ok' && $shots['state'] === 'ok' ? '' : ' Run: vendor/bin/ps-module docs build') . "\n";

    return 0;
}

/**
 * Validates the content without rendering anything.
 *
 * @param array<string, mixed> $ctx
 */
function docs_cmd_check(array $ctx): int
{
    $issues = docs_validate($ctx);
    if ($issues === []) {
        echo "ps-module docs check: content of {$ctx['module']} {$ctx['version']} is complete and valid (" . implode(', ', $ctx['languages']) . ").\n";

        return 0;
    }
    echo "ps-module docs check:\n";
    $errors = docs_print_issues($issues);
    echo $errors > 0 ? "\n$errors error(s).\n" : "\nNo errors (only warnings).\n";

    return $errors > 0 ? 1 : 0;
}

/**
 * Takes the screenshots of docs.json in a Docker test shop.
 *
 * @param array<string, mixed> $ctx
 * @param string[] $flags
 */
function docs_cmd_screenshots(array $ctx, array $flags): int
{
    $only = [];
    foreach ($flags as $flag) {
        if (str_starts_with($flag, '--only=')) {
            $only = array_filter(explode(',', substr($flag, 7)));
        }
    }
    $result = docs_take_screenshots($ctx, in_array('--keep', $flags, true), $only);
    echo $result['message'] . "\n";

    return $result['ok'] ? 0 : 1;
}

/**
 * Renders the manuals (HTML and PDF) of the given languages (default: all), also when the content still has problems.
 *
 * @param array<string, mixed> $ctx
 * @param string[] $languages
 */
function docs_cmd_manual(array $ctx, array $languages, bool $pdf): int
{
    $languages = $languages === [] ? $ctx['languages'] : array_values(array_intersect($languages, $ctx['languages']));
    $issues = array_filter(docs_validate($ctx), static fn (array $i): bool => in_array($i['lang'], $languages, true) && str_ends_with($i['file'], 'manual.md'));
    if ($issues !== []) {
        echo "Content problems (rendered anyway, 'docs build' refuses them):\n";
        docs_print_issues(array_values($issues));
    }
    $manifest = docs_build_manifest($ctx);
    $rendered = docs_render_manuals($ctx, $languages, $manifest);
    foreach ($rendered as $iso => $file) {
        echo "  $iso  " . docs_rel($ctx, $file) . "\n";
    }
    $code = 0;
    if ($pdf) {
        $pdfs = docs_render_pdfs($ctx, $rendered, $manifest);
        $code = docs_print_pdf_results($ctx, $pdfs) ? 0 : 1;
    }
    docs_write_json($ctx['docs'] . '/build-manifest.json', $manifest);

    return $code;
}

/**
 * Renders the marketing sheets of the given languages (default: all).
 *
 * @param array<string, mixed> $ctx
 * @param string[] $languages
 */
function docs_cmd_marketing(array $ctx, array $languages): int
{
    $languages = $languages === [] ? $ctx['languages'] : array_values(array_intersect($languages, $ctx['languages']));
    $issues = array_filter(docs_validate($ctx), static fn (array $i): bool => (in_array($i['lang'], $languages, true) && str_ends_with($i['file'], 'marketing.json')) || ($i['lang'] === '*' && str_contains($i['message'], 'marketing')));
    if ($issues !== []) {
        echo "Content problems (rendered anyway, 'docs build' refuses them):\n";
        docs_print_issues(array_values($issues));
    }
    foreach (docs_render_marketing_all($ctx, $languages) as $iso => $file) {
        echo "  $iso  " . docs_rel($ctx, $file) . "\n";
    }

    return 0;
}

/**
 * Puts the screenshots of the given languages (default: all) into the frame of the module.
 *
 * @param array<string, mixed> $ctx
 * @param string[] $languages
 * @param string[] $flags
 */
function docs_cmd_frames(array $ctx, array $languages, array $flags): int
{
    $languages = $languages === [] ? $ctx['languages'] : array_values(array_intersect($languages, $ctx['languages']));
    $only = [];
    foreach ($flags as $flag) {
        if (str_starts_with($flag, '--only=')) {
            $only = array_values(array_filter(explode(',', substr($flag, 7))));
        }
    }
    if (in_array('--preview', $flags, true)) {
        $preview = docs_preview_frames($ctx, $languages === $ctx['languages'] ? [$ctx['source']] : $languages, $only);
        echo $preview['message'] . "\n";

        return $preview['ok'] ? 0 : 1;
    }
    $issues = array_filter(docs_validate($ctx), static fn (array $i): bool => (in_array($i['lang'], $languages, true) && str_ends_with($i['file'], 'screenshots.json')) || ($i['lang'] === '*' && (str_contains($i['message'], 'frame') || str_starts_with($i['file'], 'docs/frame/'))));
    if ($issues !== []) {
        echo "Content problems (rendered anyway, 'docs build' refuses them):\n";
        docs_print_issues(array_values($issues));
    }
    $result = docs_render_frames($ctx, $languages, $only, in_array('--force', $flags, true));
    echo $result['message'] . "\n";
    foreach (array_unique($result['warnings']) as $warning) {
        echo "  warning: $warning\n";
    }

    return $result['ok'] ? 0 : 1;
}

/**
 * The definition-of-done step: validate, screenshots when out of date, render everything, record the 'docs' stamp.
 *
 * @param array<string, mixed> $ctx
 * @param string[] $flags
 */
function docs_cmd_build(array $ctx, array $flags): int
{
    $fingerprint = dod_fingerprint($ctx['repo']);
    echo "== Docs of {$ctx['module']} {$ctx['version']}: " . implode(', ', $ctx['languages']) . " ==\n";
    $issues = docs_validate($ctx);
    $errors = array_filter($issues, static fn (array $i): bool => $i['level'] === 'error');
    if ($issues !== []) {
        docs_print_issues($issues);
    }
    if ($errors !== []) {
        echo "\nps-module docs build: " . count($errors) . " content problem(s). Nothing generated, 'docs' not recorded.\n";
        echo "Fix them (Claude writes the missing texts and translations), then run the build again. Overview: vendor/bin/ps-module docs status\n";

        return 1;
    }
    echo "Content: complete and valid.\n";
    $warnings = [];
    $notes = [];

    // Screenshots: retake when the module code or the screenshot settings changed.
    $shots = docs_shots_state($ctx);
    $configured = (array) ($ctx['config']['screenshots'] ?? []) !== [];
    if ($configured) {
        $force = in_array('--screenshots', $flags, true);
        if (in_array('--no-screenshots', $flags, true) && !$force) {
            if ($shots['state'] === 'missing') {
                echo "\nps-module docs build: screenshots are missing and --no-screenshots was given. Run without it (Docker needed).\n";

                return 1;
            }
            if ($shots['state'] !== 'ok') {
                $warnings[] = 'screenshots NOT updated (--no-screenshots): ' . $shots['message'];
            }
        } elseif ($force || $shots['state'] !== 'ok') {
            echo "\n-- Screenshots (" . $shots['message'] . ") --\n";
            $result = docs_take_screenshots($ctx, in_array('--keep', $flags, true), []);
            echo $result['message'] . "\n";
            if (!$result['ok']) {
                $after = docs_shots_state($ctx);
                if ($after['state'] === 'missing') {
                    echo "\nps-module docs build: screenshots could not be taken and there are none yet. 'docs' not recorded.\n";
                    echo "Fix the problem above, or record why it cannot run: vendor/bin/ps-module skip docs \"<reason>\"\n";

                    return 1;
                }
                $warnings[] = 'screenshots NOT updated (' . $result['short'] . '): the manuals use the screenshots of ' . ($after['taken'] ?? 'an earlier build');
            }
        } else {
            echo "Screenshots: " . $shots['message'] . ".\n";
        }
        $shots = docs_shots_state($ctx);
        $notes[] = 'screenshots PS ' . ($shots['ps_version'] ?? '?');
        foreach ($shots['fallbacks'] ?? [] as $iso => $reason) {
            $warnings[] = "screenshots for '$iso' show the shop in English: $reason";
        }
        foreach ($shots['failed'] ?? [] as $failure) {
            $warnings[] = "screenshot failed: $failure";
        }
        foreach (docs_addons_screenshot_check($ctx) as $warning) {
            $warnings[] = $warning;
        }
    }

    if (docs_frames_enabled($ctx)) {
        echo "\n-- Framed screenshots --\n";
        $framed = docs_render_frames($ctx, $ctx['languages']);
        echo $framed['message'] . "\n";
        $warnings = array_merge($warnings, $framed['warnings']);
        $frameState = docs_frames_state($ctx);
        $notes[] = $frameState['state'] === 'ok' ? 'frames ok' : 'frames missing ' . count($frameState['missing']);
    }

    echo "\n-- Manuals --\n";
    $manifest = docs_build_manifest($ctx);
    $rendered = docs_render_manuals($ctx, $ctx['languages'], $manifest);
    foreach ($rendered as $iso => $file) {
        echo "  $iso  " . docs_rel($ctx, $file) . "\n";
    }
    echo "\n-- PDF --\n";
    $pdfs = docs_render_pdfs($ctx, $rendered, $manifest);
    $pdfOk = docs_print_pdf_results($ctx, $pdfs);
    $engines = array_unique(array_filter(array_column($pdfs, 'engine')));
    if (!$pdfOk) {
        $failed = array_keys(array_filter($pdfs, static fn (array $p): bool => !$p['ok']));
        $warnings[] = 'PDF NOT generated for ' . implode(', ', $failed) . ': ' . (string) (reset($pdfs)['error'] ?? 'no PDF engine') . ' - the print-ready HTML is in docs/manual/<iso>/manual.html (open it in a browser and print to PDF)';
    }
    $notes[] = $pdfOk ? 'PDF via ' . implode('+', $engines) : 'PDF missing';
    foreach ($pdfs as $iso => $pdf) {
        foreach ($pdf['warnings'] ?? [] as $warning) {
            $warnings[] = "PDF $iso: $warning";
        }
    }

    echo "\n-- Marketing --\n";
    foreach (docs_render_marketing_all($ctx, $ctx['languages']) as $iso => $file) {
        echo "  $iso  " . docs_rel($ctx, $file) . "\n";
    }
    foreach ($issues as $issue) {
        $warnings[] = ($issue['lang'] !== '*' ? $issue['lang'] . ': ' : '') . $issue['message'];
    }
    $manifest['warnings'] = $warnings;
    $manifest['built'] = ['version' => $ctx['version'], 'fingerprint' => $fingerprint, 'content' => docs_content_hash($ctx), 'time' => date('c')];
    docs_write_json($ctx['docs'] . '/build-manifest.json', $manifest);
    $index = docs_render_index($ctx, $manifest);
    echo "\nOverview: " . docs_rel($ctx, $index) . "\n";

    // Review reminder: the code changed after the texts were last edited.
    $lastText = 0;
    foreach (glob($ctx['docs'] . '/content/*/*') ?: [] as $file) {
        $lastText = max($lastText, (int) filemtime($file));
    }
    if (dod_last_change($ctx['repo']) > $lastText) {
        echo "\nREVIEW: the module code changed after the docs content was last edited. Check that the manual and the marketing texts still describe the module (settings, features, screenshots) and update them if not.\n";
    }
    if ($warnings !== []) {
        echo "\nWarnings (tell the user):\n  - " . implode("\n  - ", array_unique($warnings)) . "\n";
    }
    if (dod_fingerprint($ctx['repo']) !== $fingerprint) {
        echo "\nps-module docs build: the module code changed during the build. Run it again.\n";

        return 1;
    }
    dod_stamp($ctx['repo'], 'docs', $fingerprint, implode('; ', $notes) . ($warnings !== [] ? '; ' . count(array_unique($warnings)) . ' warning(s)' : ''));
    // The content is not part of the module fingerprint: record its hash too, so a later text change can be detected.
    $stamp = docs_read_json($ctx['repo'] . '/.cache/dod/docs.json');
    if ($stamp !== null) {
        $stamp['content'] = $manifest['built']['content'];
        docs_write_json($ctx['repo'] . '/.cache/dod/docs.json', $stamp);
    }
    echo "\nps-module docs build: done; 'docs' is recorded for the current code.\n";

    return 0;
}

/**
 * Records that the translations of a language are up to date with the source language (after Claude translated them).
 *
 * @param array<string, mixed> $ctx
 * @param string[] $args language (or "all") and optionally "manual" or "marketing"
 */
function docs_cmd_mark_translated(array $ctx, array $args): int
{
    $target = $args[0] ?? '';
    $kinds = isset($args[1]) ? [$args[1]] : ['manual', 'marketing', 'screenshots'];
    if ($target === '' || array_diff($kinds, ['manual', 'marketing', 'screenshots']) !== []) {
        fwrite(STDERR, "Usage: ps-module docs mark-translated <iso|all> [manual|marketing|screenshots]\n");

        return 2;
    }
    $languages = $target === 'all' ? array_values(array_diff($ctx['languages'], [$ctx['source']])) : [$target];
    $code = 0;
    foreach ($languages as $iso) {
        if ($iso === $ctx['source'] || !in_array($iso, $ctx['languages'], true)) {
            fwrite(STDERR, "  $iso: not a translation language of this module.\n");
            $code = 1;
            continue;
        }
        if (in_array('manual', $kinds, true)) {
            $source = docs_load_manual($ctx, $ctx['source']);
            $manual = docs_load_manual($ctx, $iso);
            if ($source === null || $manual === null) {
                echo "  $iso manual.md: missing\n";
                $code = 1;
            } elseif (preg_match(DOCS_TODO, $manual['body']) || count($manual['sections']) !== count($source['sections']) || docs_hash($manual['body']) === docs_hash($source['body'])) {
                echo "  $iso manual.md: NOT recorded: it still has TODOs, a different number of sections or is identical to {$ctx['source']}\n";
                $code = 1;
            } else {
                $manual['meta']['translated_from'] = implode(' ', docs_manual_hashes($source));
                file_put_contents(docs_content_file($ctx, $iso, 'manual'), docs_build_front_matter($manual['meta'], $manual['body']));
                echo "  $iso manual.md: recorded as translated from the current {$ctx['source']} text\n";
            }
        }
        if (in_array('marketing', $kinds, true)) {
            $source = docs_load_marketing($ctx, $ctx['source']);
            $data = docs_load_marketing($ctx, $iso);
            if ($source === null || $data === null || isset($data['_error']) || isset($source['_error'])) {
                echo "  $iso marketing.json: missing or invalid\n";
                $code = 1;
                continue;
            }
            $state = docs_translation_state_marketing($ctx, $iso, $data, $source);
            if (docs_marketing_hashes($data) === docs_marketing_hashes($source) || str_starts_with($state, 'not translated') || preg_match(DOCS_TODO, (string) json_encode($data, JSON_UNESCAPED_UNICODE))) {
                echo "  $iso marketing.json: NOT recorded: it still has TODOs or texts identical to {$ctx['source']}\n";
                $code = 1;
                continue;
            }
            $data['_meta']['translated_from'] = docs_marketing_hashes($source);
            docs_write_json(docs_content_file($ctx, $iso, 'marketing'), $data);
            echo "  $iso marketing.json: recorded as translated from the current {$ctx['source']} text\n";
        }
        if (in_array('screenshots', $kinds, true) && docs_frames_enabled($ctx)) {
            $source = docs_load_captions($ctx, $ctx['source']);
            $data = docs_load_captions($ctx, $iso);
            if ($source === null || $data === null || isset($data['_error']) || isset($source['_error'])) {
                echo "  $iso screenshots.json: missing or invalid\n";
                $code = 1;
                continue;
            }
            $state = docs_translation_state_captions($ctx, $iso, $data, $source);
            $missing = array_diff(docs_caption_ids($ctx, $iso), array_keys($data));
            if (str_starts_with($state, 'not translated') || $missing !== [] || preg_match(DOCS_TODO, (string) json_encode(array_diff_key($data, ['_help' => 1]), JSON_UNESCAPED_UNICODE))) {
                echo "  $iso screenshots.json: NOT recorded: it still has TODOs, misses captions (" . implode(', ', $missing) . ") or has the {$ctx['source']} titles\n";
                $code = 1;
                continue;
            }
            $data['_meta']['translated_from'] = docs_caption_hashes($source);
            docs_write_json(docs_captions_file($ctx, $iso), $data);
            echo "  $iso screenshots.json: recorded as translated from the current {$ctx['source']} captions\n";
        }
    }

    return $code;
}

/**
 * Renders pages of a PDF manual to PNG (Docker), so the result can be looked at.
 *
 * @param array<string, mixed> $ctx
 * @param string[] $args
 * @param string[] $flags
 */
function docs_cmd_preview(array $ctx, array $args, array $flags): int
{
    $iso = $args[0] ?? $ctx['source'];
    $pages = '1-3';
    foreach ($flags as $flag) {
        if (str_starts_with($flag, '--pages=')) {
            $pages = substr($flag, 8);
        }
    }
    $result = docs_pdf_preview($ctx, $iso, $pages);
    echo $result['message'] . "\n";

    return $result['ok'] ? 0 : 1;
}

/**
 * Shows which engines for screenshots and PDF are available on this machine.
 *
 * @param array<string, mixed> $ctx
 */
function docs_cmd_doctor(array $ctx): int
{
    $docker = docs_docker_available();
    echo 'Docker:            ' . ($docker === '' ? 'running' : $docker) . "\n";
    echo 'Docs image:        ' . docs_image_tag() . ' ' . ($docker === '' ? (docs_image_exists() ? '(built)' : '(not built yet: built on first use, a few minutes)') : '') . "\n";
    echo 'Test shops:        ' . (is_array($ctx['testing']) ? 'testing.json, PrestaShop ' . implode(', ', (array) $ctx['testing']['versions']) : 'no testing.json: no screenshots') . "\n";
    $chrome = docs_find_chrome();
    echo 'Local Chrome/Edge: ' . ($chrome ?? 'not found') . "\n";
    echo 'wkhtmltopdf:       ' . (docs_find_executable('wkhtmltopdf') ?? 'not found') . "\n";
    echo 'dompdf:            ' . (docs_dompdf_available($ctx) ? 'available (Latin, Cyrillic, Greek only)' : 'not installed (optional: composer require --dev dompdf/dompdf)') . "\n";
    echo 'PDF engine order:  ' . implode(' > ', docs_pdf_engines($ctx)) . "\n";

    return 0;
}
