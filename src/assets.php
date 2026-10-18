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
 * The JavaScript of a module: tested, and for TypeScript built (ps-module skill: optional part js-tests,
 * recipe typescript; conventions TPL-25..29, TST-20..29).
 *
 * <module>/_dev/ holds package.json, build.mjs, vitest.config.mjs and the tests in _dev/tests/; with the
 * recipe typescript also the sources (front/*.ts, back/*.ts), which the build bundles into
 * views/js/<entry>.js. Without TypeScript the scripts in views/js/ are written by hand. `npm run build`
 * lints, runs the tests with 100% coverage per file (lines, statements, functions, branches), bundles,
 * and only then writes _dev/build-manifest.json: a hash of everything in _dev/ (sources and tests) and of
 * every JavaScript file in views/js/. `check` compares it with the files in PHP only, so it also runs where
 * Node is missing (CI checks job, the Stop hook). It fails when sources, tests or scripts changed after the
 * last passing run, when a script in views/js/ was not part of it, and when the module has JavaScript in
 * views/js/ but no _dev/package.json (untested JavaScript).
 *
 * Usage (in the module directory):
 *   ps-module assets [status]   show whether the JavaScript matches the last passing run
 *   ps-module assets check      same, exit 1 when not; stamps the definition-of-done step "assets"
 *   ps-module assets build      npm ci (or npm install without a lock file), npm run build (typecheck,
 *                               lint, tests + coverage, bundle, manifest), then check
 *
 * Modules without JavaScript in views/js/ and without _dev/package.json: every command is a no-op.
 */

declare(strict_types=1);

require_once __DIR__ . '/dod.php';

// Vitest 5, jsdom 29 and ESLint 10 need Node 22.13+ (or 24+).
const ASSETS_MIN_NODE = '22.13.0';

$moduleDir = dod_norm((string) ($argv[1] ?? ''));
$command = $argv[2] ?? 'status';
if ($moduleDir === '' || !is_dir($moduleDir)) {
    fwrite(STDERR, "Usage: php assets.php <module-dir> [status|check|build]\n");
    exit(2);
}
$devDir = "$moduleDir/_dev";
if (!is_file("$devDir/package.json")) {
    $scripts = assets_scripts($moduleDir);
    if ($scripts === []) {
        echo "ps-module assets: no JavaScript in views/js/ and no _dev/package.json: nothing to test or build.\n";
        exit(0);
    }
    $message = "ps-module assets: views/js/ has JavaScript without tests (" . implode(', ', $scripts) . ").\n"
        . "Every script needs tests with 100% coverage (TST-20..29). Add the test setup: copy\n"
        . "  .claude/skills/ps-module/templates/optional/js-tests/module/_dev/   (plain JavaScript)\n"
        . "into the module as _dev/ (fill in the placeholders, see the recipe front-assets), write the tests in\n"
        . "_dev/tests/, then run vendor/bin/ps-module assets build. For TypeScript use the recipe typescript.\n";
    if ($command === 'status') {
        echo $message;
        exit(0);
    }
    fwrite(STDERR, $message);
    exit(1);
}

switch ($command) {
    case 'status':
    case 'check':
        $problems = assets_problems($moduleDir);
        assets_report($problems);
        if ($problems !== []) {
            exit($command === 'check' ? 1 : 0);
        }
        if ($command === 'check') {
            assets_stamp($moduleDir);
        }
        exit(0);

    case 'build':
        $code = assets_build($devDir);
        if ($code !== 0) {
            exit($code);
        }
        $problems = assets_problems($moduleDir);
        assets_report($problems);
        if ($problems !== []) {
            exit(1);
        }
        assets_stamp($moduleDir);
        exit(0);

    default:
        fwrite(STDERR, "ps-module assets: unknown command '$command'. Use status, check or build.\n");
        exit(2);
}

// ---------------------------------------------------------------------------

/**
 * Everything that makes the committed JS differ from a build of the current sources, as messages.
 * Empty when the manifest matches the sources and every output file.
 *
 * @return string[]
 */
function assets_problems(string $moduleDir): array
{
    $manifestFile = "$moduleDir/_dev/build-manifest.json";
    if (!is_file($manifestFile)) {
        return ['_dev/build-manifest.json is missing: lint, tests and build have never passed.'];
    }
    $manifest = json_decode((string) file_get_contents($manifestFile), true);
    if (!is_array($manifest) || !isset($manifest['sources']) || !is_array($manifest['outputs'] ?? null)) {
        return ['_dev/build-manifest.json is not valid: rebuild it.'];
    }
    $typescript = ($manifest['mode'] ?? 'typescript') === 'typescript';
    $problems = [];
    if (($manifest['tests']['passed'] ?? false) !== true) {
        $problems[] = 'The last run has no passing tests with 100% coverage (manifest of an older build.mjs?): update _dev/ from the skill template and rebuild (TST-20..29).';
    }
    if ($manifest['sources'] !== assets_hash(assets_source_files($moduleDir), "$moduleDir/_dev")) {
        $problems[] = 'The sources or tests in _dev/ (or .license-header.txt) changed after the last passing run.';
    }
    if ($typescript && $manifest['outputs'] === []) {
        $problems[] = 'The last build produced no output: add an entry (_dev/front/*.ts or _dev/back/*.ts) or remove the TypeScript.';
    }
    foreach ($manifest['outputs'] as $relative => $hash) {
        $file = "$moduleDir/$relative";
        if (!is_file($file)) {
            $problems[] = "$relative is missing.";
        } elseif ($hash !== assets_hash([$file], $moduleDir)) {
            $problems[] = $typescript
                ? "$relative differs from the build output (edited by hand or by another tool? Change the TypeScript instead)."
                : "$relative changed after the last passing test run.";
        }
    }
    // A script that was not part of the last run is untested (or, with TypeScript, not built from _dev/).
    foreach (assets_scripts($moduleDir) as $relative) {
        if (!array_key_exists($relative, $manifest['outputs'])) {
            $problems[] = $typescript
                ? "$relative is not built from _dev/: write it as TypeScript in _dev/front/ or _dev/back/ (TPL-25)."
                : "$relative is new: it was not part of the last passing test run (write its tests in _dev/tests/).";
        }
    }

    return $problems;
}

/**
 * Every JavaScript file in views/js/ (recursive), relative to the module directory, sorted.
 *
 * @return string[] e.g. ['views/js/admin/item-grid.js', 'views/js/front.js']
 */
function assets_scripts(string $moduleDir): array
{
    return dod_module_scripts($moduleDir);
}

/**
 * Source files of the build, the same set as _dev/build.mjs uses: everything in _dev/ except
 * node_modules/, .cache/ and the manifest, plus the license header that becomes the banner.
 *
 * @return string[] absolute paths, sorted by their path relative to _dev/
 */
function assets_source_files(string $moduleDir): array
{
    $devDir = "$moduleDir/_dev";
    $files = [];
    $it = new RecursiveIteratorIterator(
        new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($devDir, FilesystemIterator::SKIP_DOTS),
            static fn (SplFileInfo $f): bool => !($f->isDir() && in_array($f->getFilename(), ['node_modules', '.cache'], true))
        )
    );
    foreach ($it as $file) {
        $path = str_replace('\\', '/', $file->getPathname());
        if ($path !== "$devDir/build-manifest.json") {
            $files[] = $path;
        }
    }
    $files[] = "$moduleDir/.license-header.txt";
    usort($files, static fn (string $a, string $b): int => strcmp(assets_relative($a, $devDir), assets_relative($b, $devDir)));

    return $files;
}

/**
 * Hash of files: their path relative to $baseDir ("/" separators) and their content with CRLF as LF,
 * each followed by "\0". Must stay equal to hashFiles() in _dev/build.mjs.
 *
 * @param string[] $files
 */
function assets_hash(array $files, string $baseDir): string
{
    $hash = hash_init('sha256');
    foreach ($files as $file) {
        hash_update($hash, assets_relative($file, $baseDir) . "\0");
        hash_update($hash, str_replace("\r\n", "\n", is_file($file) ? (string) file_get_contents($file) : '') . "\0");
    }

    return hash_final($hash);
}

/**
 * Path of $file relative to $baseDir, like Node's path.relative() for the two cases the build needs: a
 * file below $baseDir, or a file in its parent directory ("../.license-header.txt").
 */
function assets_relative(string $file, string $baseDir): string
{
    $file = str_replace('\\', '/', $file);
    $baseDir = rtrim(str_replace('\\', '/', $baseDir), '/');
    if (str_starts_with($file, $baseDir . '/')) {
        return substr($file, strlen($baseDir) + 1);
    }
    if (str_starts_with($file, dirname($baseDir) . '/')) {
        return '../' . substr($file, strlen(dirname($baseDir)) + 1);
    }

    return $file;
}

/**
 * Prints the result of the comparison and what to do about it.
 *
 * @param string[] $problems
 */
function assets_report(array $problems): void
{
    if ($problems === []) {
        echo "ps-module assets: views/js and _dev/ match the last run where lint, tests (100% coverage) and build passed.\n";

        return;
    }
    echo "ps-module assets: the JavaScript is not tested (and built) in its current state:\n";
    foreach ($problems as $problem) {
        echo "  - $problem\n";
    }
    echo "Run: vendor/bin/ps-module assets build   (needs Node.js " . ASSETS_MIN_NODE . "+: lint, tests with 100% coverage, build;\n"
        . "     commit views/js/, _dev/ and _dev/build-manifest.json together)\n";
}

/**
 * Records the definition-of-done step "assets" for the current code.
 */
function assets_stamp(string $moduleDir): void
{
    $repoDir = dod_repo_dir($moduleDir);
    if ($repoDir !== null) {
        dod_stamp($repoDir, 'assets');
        echo "ps-module: 'assets' passed and is recorded for the current code.\n";
    }
}

/**
 * Installs the npm dependencies when needed and runs npm run build in _dev/. Returns the exit code.
 */
function assets_build(string $devDir): int
{
    $node = trim((string) shell_exec('node -v 2>' . (PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null')));
    $npm = trim((string) shell_exec('npm -v 2>' . (PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null')));
    if ($node === '' || $npm === '') {
        fwrite(STDERR, "ps-module assets: Node.js and npm are needed to build the TypeScript (Node " . ASSETS_MIN_NODE . "+), but "
            . ($node === '' ? 'node' : 'npm') . " was not found on the PATH.\n"
            . "Install Node.js LTS (https://nodejs.org) and run this again. If that is not possible here, record it:\n"
            . "  vendor/bin/ps-module skip assets \"<reason>\"   (the user sees it: the shipped JS then does not match the TypeScript)\n");

        return 1;
    }
    if (version_compare(ltrim($node, 'v'), ASSETS_MIN_NODE, '<')) {
        fwrite(STDERR, "ps-module assets: Node $node is too old; the build tools need Node " . ASSETS_MIN_NODE . " or newer.\n");

        return 1;
    }

    $lock = "$devDir/package-lock.json";
    $installed = "$devDir/node_modules/.package-lock.json";
    if (!is_file($lock)) {
        echo "ps-module assets: no package-lock.json yet: npm install (commit the lock file).\n";
        $code = assets_run('npm install --no-audit --no-fund', $devDir);
    } elseif (!is_file($installed) || filemtime($installed) < filemtime($lock)) {
        echo "ps-module assets: npm ci\n";
        $code = assets_run('npm ci --no-audit --no-fund', $devDir);
    } else {
        $code = 0;
    }
    if ($code !== 0) {
        fwrite(STDERR, "ps-module assets: installing the npm dependencies failed.\n");

        return $code;
    }
    $code = assets_run('npm run build', $devDir);
    if ($code !== 0) {
        fwrite(STDERR, "ps-module assets: npm run build failed (typecheck, lint, tests, coverage below 100% or bundle; see above). Fix the code or write the missing tests, and run it again.\n");
    }

    return $code;
}

/**
 * Runs a shell command in a directory, streaming its output, and returns the exit code.
 */
function assets_run(string $command, string $cwd): int
{
    $previous = getcwd();
    chdir($cwd);
    passthru($command, $code);
    if ($previous !== false) {
        chdir($previous);
    }

    return (int) $code;
}
