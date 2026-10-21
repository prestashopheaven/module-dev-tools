<?php
/**
 * Definition-of-done stamps for a module repository.
 *
 * Every check that passes records a stamp in <repo>/.cache/dod/<step>.json with a
 * fingerprint of the module source. When the source changes afterwards, the stamp
 * is stale. The Stop hook of the PrestaShopHeaven project (.claude/hooks/) uses
 * `status` to see whether all required checks ran on the current code.
 *
 * Used through `vendor/bin/ps-module status|skip|fingerprint` and by the tools that
 * stamp a step when it passes. There is deliberately no command to stamp by hand.
 *
 * Steps: assets (if <module>/_dev/package.json or JavaScript in views/js exists: tested, TypeScript built),
 *        check, validate-src, validate-zip, shop-tests (if testing.json exists),
 *        validator-api (if PRESTASHOP_VALIDATOR_API_KEY is configured).
 *
 * Also used by the Stop hook of the PrestaShopHeaven project (.claude/hooks/).
 */

declare(strict_types=1);

// Verbose output (ps-module -v). Guarded: the Stop hook may load this file next to another copy of the package.
if (!function_exists('ps_verbose')) {
    require_once __DIR__ . '/verbose.php';
}

const DOD_EXCLUDE_DIRS = ['vendor', '.cache', 'node_modules', 'dist', '.git', '.idea'];

/** Normalises a path: absolute, forward slashes, no trailing slash, no "..". */
function dod_norm(string $path): string
{
    $real = realpath($path);

    return rtrim(strtr($real === false ? $path : $real, ['\\' => '/']), '/');
}

/** Finds the repository root (the directory that contains tools/ and the module dir). */
function dod_repo_dir(string $start): ?string
{
    $dir = rtrim(str_replace('\\', '/', (string) realpath($start)), '/');
    for ($i = 0; $i < 4 && $dir !== '' && $dir !== dirname($dir); ++$i, $dir = dirname($dir)) {
        if (is_file("$dir/module.spec.json")) {
            return $dir;
        }
    }

    return null;
}

/** The module directory inside a repository (the folder with <name>/<name>.php). */
function dod_module_dir(string $repoDir): ?string
{
    $spec = is_file("$repoDir/module.spec.json") ? json_decode((string) file_get_contents("$repoDir/module.spec.json"), true) : null;
    if (is_array($spec) && isset($spec['module_name']) && is_dir("$repoDir/{$spec['module_name']}")) {
        return "$repoDir/{$spec['module_name']}";
    }
    foreach (glob("$repoDir/*", GLOB_ONLYDIR) ?: [] as $dir) {
        if (is_file($dir . '/' . basename($dir) . '.php')) {
            return str_replace('\\', '/', $dir);
        }
    }

    return null;
}

/**
 * Files that make up "the code under test": the module (without vendor and caches)
 * and the repo-level Behat scenarios and test settings.
 *
 * @return string[] absolute paths
 */
function dod_source_files(string $repoDir): array
{
    $repoDir = dod_norm($repoDir);
    $moduleDir = dod_module_dir($repoDir);
    $roots = array_filter([$moduleDir, "$repoDir/tests/behat"], static fn (?string $d): bool => $d !== null && is_dir($d));
    $files = [];
    foreach ($roots as $root) {
        $it = new RecursiveIteratorIterator(
            new RecursiveCallbackFilterIterator(
                new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
                static fn (SplFileInfo $f): bool => !($f->isDir() && in_array($f->getFilename(), DOD_EXCLUDE_DIRS, true))
            )
        );
        foreach ($it as $file) {
            $path = str_replace('\\', '/', $file->getPathname());
            // Generated when the shop tests install Behat; not part of the code under test.
            if (str_ends_with($path, '/tests/behat/composer.lock')) {
                continue;
            }
            $files[] = $path;
        }
    }
    foreach (['testing.json', 'i18n.json'] as $extra) {
        if (is_file("$repoDir/$extra")) {
            $files[] = "$repoDir/$extra";
        }
    }
    // Translation catalogues: an edited translation must invalidate the stamps.
    foreach (glob("$repoDir/i18n/*.json") ?: [] as $catalogue) {
        $files[] = str_replace('\\', '/', $catalogue);
    }
    sort($files);

    return $files;
}

/**
 * Hash of the module source (paths and contents, line endings normalised): a stamp is only valid for this exact code.
 */
function dod_fingerprint(string $repoDir): string
{
    $repoDir = dod_norm($repoDir);
    $hash = hash_init('sha256');
    $files = dod_source_files($repoDir);
    foreach ($files as $file) {
        hash_update($hash, substr($file, strlen($repoDir)) . "\0");
        // Normalise line endings so a git checkout with CRLF gives the same fingerprint.
        hash_update($hash, str_replace("\r\n", "\n", (string) file_get_contents($file)) . "\0");
    }
    $fingerprint = hash_final($hash);
    ps_vlog('dod: fingerprint ' . substr($fingerprint, 0, 12) . ' of ' . count($files) . ' source file(s) in ' . $repoDir);

    return $fingerprint;
}

/**
 * Hash of the docs sources written by hand (docs/docs.json, docs/content/ and the frame template
 * docs/frame/): the docs stamp is only valid for this content, so an edited manual, marketing text or
 * frame template needs a new docs build.
 */
function dod_docs_hash(string $repoDir): string
{
    $docs = dod_norm($repoDir) . '/docs';
    $files = array_merge([$docs . '/docs.json'], glob($docs . '/content/*.*') ?: [], glob($docs . '/content/*/*') ?: [], glob($docs . '/frame/*') ?: [], glob($docs . '/frame/*/*') ?: []);
    $files = array_map(static fn (string $f): string => str_replace('\\', '/', $f), $files);
    sort($files);
    $hash = hash_init('sha256');
    foreach ($files as $file) {
        if (is_file($file)) {
            hash_update($hash, substr($file, strlen($docs)) . "\0" . str_replace("\r\n", "\n", (string) file_get_contents($file)) . "\0");
        }
    }

    return hash_final($hash);
}

/** Newest modification time of the source files (0 when there are none). */
function dod_last_change(string $repoDir): int
{
    $max = 0;
    foreach (dod_source_files($repoDir) as $file) {
        $max = max($max, (int) filemtime($file));
    }

    return $max;
}

/**
 * Own JavaScript files of the module in views/js/ (recursive), relative to the module directory and sorted:
 * every one of them must be tested (ps-module assets, TST-20..29). Third-party libraries in views/js/vendor/
 * are left out (TST-27: no tests, but a license file per library, checked by the conventions check).
 *
 * @return string[]
 */
function dod_module_scripts(string $moduleDir): array
{
    $dir = "$moduleDir/views/js";
    if (!is_dir($dir)) {
        return [];
    }
    $files = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        $relative = substr(str_replace('\\', '/', $file->getPathname()), strlen($moduleDir) + 1);
        if (strtolower($file->getExtension()) === 'js' && !str_starts_with($relative, 'views/js/vendor/')) {
            $files[] = $relative;
        }
    }
    sort($files, SORT_STRING);

    return $files;
}

/**
 * Steps the definition of done needs for this repository: shop tests only with testing.json, docs only with docs/docs.json,
 * the online validator only with an API key.
 *
 * @return string[]
 */
function dod_required_steps(string $repoDir): array
{
    $steps = ['check', 'validate-src', 'validate-zip'];
    // JavaScript of the module (tests with 100% coverage; TypeScript built into views/js): ps-module assets.
    $moduleDir = dod_module_dir($repoDir);
    if ($moduleDir !== null && (is_file("$moduleDir/_dev/package.json") || dod_module_scripts($moduleDir) !== [])) {
        array_unshift($steps, 'assets');
    }
    if (is_file("$repoDir/testing.json")) {
        $steps[] = 'shop-tests';
    }
    if (is_file("$repoDir/docs/docs.json")) {
        $steps[] = 'docs';
    }
    if (dod_validator_key($repoDir) !== '') {
        $steps[] = 'validator-api';
    }
    ps_vlog('dod: required steps ' . implode(', ', $steps) . ' (assets: _dev/package.json or views/js, shop-tests: testing.json, docs: docs/docs.json, validator-api: API key)');

    return $steps;
}

/**
 * The validator API key from the environment or the nearest .env file upwards, or an empty string.
 */
function dod_validator_key(string $dir): string
{
    if (getenv('PRESTASHOP_VALIDATOR_API_KEY')) {
        // Registered so verbose output never shows the key.
        ps_vsecret((string) getenv('PRESTASHOP_VALIDATOR_API_KEY'));

        return (string) getenv('PRESTASHOP_VALIDATOR_API_KEY');
    }
    for ($d = $dir; $d !== dirname($d); $d = dirname($d)) {
        if (is_file("$d/.env") && preg_match('/^\s*PRESTASHOP_VALIDATOR_API_KEY\s*=\s*["\']?([^"\'\s]+)/m', (string) file_get_contents("$d/.env"), $m)) {
            ps_vsecret($m[1]);

            return $m[1];
        }
    }

    return '';
}

/** Records a passed step. $fingerprint defaults to the current source fingerprint. */
function dod_stamp(string $repoDir, string $step, ?string $fingerprint = null, string $note = ''): void
{
    $repoDir = dod_norm($repoDir);
    $dir = "$repoDir/.cache/dod";
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $fingerprint ??= dod_fingerprint($repoDir);
    file_put_contents("$dir/$step.json", json_encode([
        'step' => $step,
        'status' => 'ok',
        'fingerprint' => $fingerprint,
        'time' => date('c'),
        'note' => $note,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    ps_vlog("dod: stamp '$step' ok (fingerprint " . substr($fingerprint, 0, 12) . ") written to $dir/$step.json" . ($note !== '' ? " ($note)" : ''));
}

/**
 * Records that a step cannot run here (e.g. no Docker), with the reason; the Stop hook reports it to the user.
 */
function dod_skip(string $repoDir, string $step, string $reason): void
{
    $repoDir = dod_norm($repoDir);
    $dir = "$repoDir/.cache/dod";
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    file_put_contents("$dir/$step.json", json_encode([
        'step' => $step,
        'status' => 'skipped',
        'fingerprint' => dod_fingerprint($repoDir),
        'time' => date('c'),
        'note' => $reason,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
    ps_vlog("dod: stamp '$step' skipped written to $dir/$step.json ($reason)");
}

/**
 * State of every required step for the current code: ok, stale (code changed since), missing or skipped.
 *
 * @return array{repo: string, module: string, fingerprint: string, complete: bool, steps: array<string, array{state: string, note: string, time: string}>}
 */
function dod_status(string $repoDir): array
{
    $repoDir = dod_norm($repoDir);
    $fingerprint = dod_fingerprint($repoDir);
    $steps = [];
    $complete = true;
    foreach (dod_required_steps($repoDir) as $step) {
        $file = "$repoDir/.cache/dod/$step.json";
        $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        if (!is_array($data)) {
            $state = 'missing';
        } elseif (($data['fingerprint'] ?? '') !== $fingerprint) {
            $state = 'stale';
        } elseif ($step === 'docs' && isset($data['content']) && $data['content'] !== dod_docs_hash($repoDir)) {
            $state = 'stale'; // manual or marketing text edited after the last docs build
        } else {
            $state = $data['status'] === 'skipped' ? 'skipped' : 'ok';
        }
        if (in_array($state, ['missing', 'stale'], true)) {
            $complete = false;
        }
        ps_vlog("dod: step $step: $state" . (is_array($data) ? ' (stamp ' . substr((string) ($data['fingerprint'] ?? ''), 0, 12) . ' from ' . ($data['time'] ?? '?') . ')' : " (no $file)"));
        $steps[$step] = ['state' => $state, 'note' => (string) ($data['note'] ?? ''), 'time' => (string) ($data['time'] ?? '')];
    }

    return [
        'repo' => $repoDir,
        'module' => basename((string) dod_module_dir($repoDir)),
        'fingerprint' => $fingerprint,
        'complete' => $complete,
        'steps' => $steps,
    ];
}

/** Command (run in the module directory) that performs a step and stamps it when it passes. */
function dod_command(string $step, string $module): string
{
    $command = [
        'assets' => 'vendor/bin/ps-module assets build',
        'check' => 'composer check',
        'validate-src' => 'vendor/bin/ps-module validate',
        'validate-zip' => 'vendor/bin/ps-module build && vendor/bin/ps-module validate-zip',
        'shop-tests' => 'vendor/bin/ps-module shops run',
        'docs' => 'vendor/bin/ps-module docs build',
        'validator-api' => 'vendor/bin/ps-module validator-api',
    ][$step] ?? $step;

    return "cd $module && $command";
}
