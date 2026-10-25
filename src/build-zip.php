<?php
/**
 * Builds the installable module zips (update-system/PLAN.md §5), from one stage:
 *  - <repo>/dist/<module>.zip         the direct-sale build: sold on prestahaven.com and served by the update server
 *                                     (signed in CI); module-kit's updater notice included, module_key empty
 *  - <repo>/dist/addons/<module>.zip  the Addons build: the updater notice (module-kit src/Updater) left out, no
 *                                     update-server URL, module_key from module.spec.json > module_key (when set).
 *                                     Same file name, because the Addons validator requires <module>.zip.
 *
 * Usage: ps-module build   (or: php build-zip.php <module-dir>)
 *
 * Steps:
 *  1. copy the module without dev files into a temporary stage
 *  2. composer install --no-dev -o (path repositories such as ../../libraries/* are
 *     rewritten to absolute paths, so they still resolve from the stage)
 *  3. scope shared packages: every namespace listed in composer.json
 *     extra.prestashopheaven.scope is prefixed with extra.prestashopheaven.scope-prefix,
 *     in the module code and in vendor/. Two modules with different versions of the same
 *     package can then run side by side in one shop.
 *  4. add the manual of docs.json > zip_manuals (default: English) as docs/readme_<iso>.pdf; the manuals of all
 *     languages go on the docs site (ps-module publish docs), so the zip stays small for Addons
 *  5. add index.php to every directory (also vendor/), zip with <module>/ as root
 */

declare(strict_types=1);

require_once __DIR__ . '/composer-paths.php';
require_once __DIR__ . '/verbose.php';
require_once __DIR__ . '/build-channels.php';

const EXCLUDE = [
    // directories (matched against path segments)
    'dirs' => ['vendor', 'tests', 'node_modules', '.git', '.github', '.gitea', '.idea', '.vscode', '.cache', 'dist', '_dev'],
    // file name patterns (fnmatch)
    'files' => [
        '.php-cs-fixer*', 'phpstan*.neon', 'phpstan*.neon.dist', 'phpcs.xml*', 'phpunit.xml*', '.phpunit*',
        '.gitignore', '.gitattributes', '.editorconfig', '.DS_Store', 'Thumbs.db', '*.log', '*.zip', 'Makefile',
        'package.json', 'package-lock.json', 'tsconfig.json', 'webpack.config.js', '.env*', '.license-header.txt',
        'config.xml', 'config_*.xml',
        // JS tooling outside _dev/ (recipe typescript keeps it in _dev/, which is left out as a whole):
        // TypeScript sources, source maps and the configs of npm, TypeScript, esbuild, ESLint, Prettier.
        '*.ts', '*.map', 'tsconfig*.json', 'esbuild.config.*', 'build.mjs', 'eslint.config.*', '.eslintrc*', '.prettierrc*',
        '.nvmrc', '.npmrc', 'yarn.lock', 'pnpm-lock.yaml',
    ],
];

$moduleDir = rtrim(str_replace('\\', '/', (string) realpath($argv[1] ?? '')), '/');
if ($moduleDir === '' || !is_dir($moduleDir)) {
    fwrite(STDERR, "Usage: php build-zip.php <module-dir>\n");
    exit(1);
}
$name = basename($moduleDir);
$mainFile = "$moduleDir/$name.php";
if (!is_file($mainFile) || !preg_match('/\$this->version\s*=\s*[\'"]([^\'"]+)[\'"]/', (string) file_get_contents($mainFile), $m)) {
    fwrite(STDERR, "Cannot read \$this->version from $mainFile\n");
    exit(1);
}
$version = $m[1];

$work = str_replace('\\', '/', sys_get_temp_dir()) . '/ps_build_' . uniqid();
$stage = "$work/$name";
mkdir($stage, 0775, true);
ps_vlog("build: $name $version from $moduleDir, stage $stage");

$buildStart = microtime(true);
copyFiltered($moduleDir, $stage);
ps_vdone('build: copy to the stage', $buildStart);

if (is_file("$stage/composer.json")) {
    rewritePathRepositories($moduleDir, $stage);
    if (!is_file("$stage/composer.lock")) {
        // Without a lock file composer resolves require-dev too, even with --no-dev, and broken
        // or unstable dev tools would block the build. They are never shipped: drop them here.
        $composer = json_decode((string) file_get_contents("$stage/composer.json"), true);
        unset($composer['require-dev'], $composer['autoload-dev']);
        file_put_contents("$stage/composer.json", json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "
");
        fwrite(STDERR, "No composer.lock: building without require-dev.
");
    }
    // Verbose: composer's own output too (no --quiet).
    $quiet = ps_verbose() ? '' : ' --quiet';
    $cmd = 'composer install --no-dev --optimize-autoloader --no-interaction --no-progress' . $quiet . ' --working-dir=' . escapeshellarg($stage);
    ps_vcmd($cmd, (string) getcwd());
    $start = microtime(true);
    passthru($cmd, $code);
    ps_vdone('composer install --no-dev', $start, $code);
    if ($code !== 0) {
        fwrite(STDERR, "composer install --no-dev failed\n");
        exit(1);
    }

    $composer = json_decode((string) file_get_contents("$stage/composer.json"), true);
    $scopes = $composer['extra']['prestashopheaven']['scope'] ?? [];
    $prefix = (string) ($composer['extra']['prestashopheaven']['scope-prefix'] ?? '');
    ps_vlog('build: scope ' . ($scopes !== [] && $prefix !== '' ? implode(', ', $scopes) . " under $prefix" : 'none (no extra.prestashopheaven.scope / scope-prefix in composer.json)'));
    if ($scopes !== [] && $prefix !== '') {
        $changed = scopeNamespaces($stage, $prefix, $scopes);
        fwrite(STDERR, "Scoped " . implode(', ', $scopes) . " under $prefix ($changed files)\n");
        $cmd = 'composer dump-autoload --optimize --no-dev --no-interaction' . $quiet . ' --working-dir=' . escapeshellarg($stage);
        ps_vcmd($cmd, (string) getcwd());
        $start = microtime(true);
        passthru($cmd, $code);
        ps_vdone('composer dump-autoload', $start, $code);
        if ($code !== 0) {
            fwrite(STDERR, "composer dump-autoload failed after scoping\n");
            exit(1);
        }
    }
}

// Direct build: module_key always empty (a key belongs to the Addons build only).
// Addons build: split off now, while composer.json still exists (the autoloader must forget the left-out classes).
// Modules for own clients only (module.spec.json > distribution: own-clients, e.g. the updater) have no Addons build.
$addonsStage = null;
try {
    setModuleKey("$stage/$name.php", '');
    if (moduleDistribution(dirname($moduleDir)) !== 'own-clients') {
        $addonsStage = "$work/addons/$name";
        makeAddonsStage($stage, $addonsStage, $name, readModuleKey(dirname($moduleDir)), $quiet ?? ' --quiet');
    } else {
        ps_vlog('build: distribution own-clients: no Addons build');
    }
} catch (RuntimeException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    removeTree($work);
    exit(1);
}
$stages = array_values(array_filter([$stage, $addonsStage]));

// Same as PrestaShop's own release build: composer files are not shipped.
foreach ($stages as $dir) {
    @unlink("$dir/composer.json");
    @unlink("$dir/composer.lock");
}

// Manual for Addons (REL-06): docs/readme_<iso>.pdf, made by `ps-module docs build`. Only the languages of
// docs.json > zip_manuals (default English, else the source language): every language in the zip made it too big
// for uploads (33 languages: 45 MB). All languages are on the docs site (ps-module publish docs).
$repoRoot = dirname($moduleDir);
$docsConfig = is_file("$repoRoot/docs/docs.json") ? json_decode((string) file_get_contents("$repoRoot/docs/docs.json"), true) : null;
$i18nConfig = is_file("$repoRoot/i18n.json") ? json_decode((string) file_get_contents("$repoRoot/i18n.json"), true) : null;
$zipManuals = is_array($docsConfig) && isset($docsConfig['zip_manuals']) && is_array($docsConfig['zip_manuals'])
    ? array_map('strval', $docsConfig['zip_manuals'])
    : [is_file("$repoRoot/docs/manual/en/readme_en.pdf") ? 'en' : (string) ($i18nConfig['source'] ?? 'en')];
foreach ($zipManuals as $iso) {
    $pdf = "$repoRoot/docs/manual/$iso/readme_$iso.pdf";
    if (is_file($pdf)) {
        foreach ($stages as $dir) {
            @mkdir("$dir/docs", 0775, true);
            copy($pdf, "$dir/docs/readme_$iso.pdf");
        }
        ps_vlog("build: manual $pdf -> docs/readme_$iso.pdf (" . round(filesize($pdf) / 1024) . ' KB)');
    } else {
        ps_vlog("build: no manual $pdf (made by ps-module docs build), left out");
    }
}

foreach ($stages as $dir) {
    addIndexFiles($dir, (string) file_get_contents("$moduleDir/index.php"));
}

$distDir = dirname($moduleDir) . '/dist';
@mkdir($distDir, 0775, true);
// The validator requires the zip to be named after the module, without a version.
$zipPath = "$distDir/$name.zip";
$addonsZipPath = "$distDir/addons/$name.zip";
@unlink($addonsZipPath);
@unlink($addonsZipPath . '.json');
fwrite(STDERR, "Building $name $version" . ($addonsStage !== null ? ' (direct and Addons build)' : '') . "\n");
[$zipFiles, $zipBytes] = zipStage($stage, $name, $zipPath);
if ($addonsStage !== null) {
    @mkdir("$distDir/addons", 0775, true);
    zipStage($addonsStage, $name, $addonsZipPath);
}
if (ps_verbose()) {
    $packages = array_map(static fn (string $d): string => substr(str_replace('\\', '/', $d), strlen("$stage/vendor/")), glob("$stage/vendor/*/*", GLOB_ONLYDIR) ?: []);
    $packages = array_values(array_filter($packages, static fn (string $p): bool => !str_starts_with($p, 'composer/')));
    ps_vlog(sprintf('build: %s: %d file(s), %.1f MB uncompressed, %.1f MB zipped', $zipPath, $zipFiles, $zipBytes / 1048576, ((int) filesize($zipPath)) / 1048576));
    ps_vlog('build: vendor packages: ' . ($packages !== [] ? implode(', ', $packages) : 'none'));
    ps_vlog('build: scoped: ' . (isset($scopes, $prefix) && $scopes !== [] && $prefix !== '' ? implode(', ', $scopes) . " under $prefix" : 'nothing'));
}

removeTree($work);

// Remember which source this zip was built from (definition-of-done stamps).
if (is_file(__DIR__ . '/dod.php')) {
    require_once __DIR__ . '/dod.php';
    $repoDir = dod_repo_dir($moduleDir);
    if ($repoDir !== null) {
        foreach (array_filter([$zipPath, $addonsStage !== null ? $addonsZipPath : null]) as $built) {
            file_put_contents($built . '.json', json_encode(['fingerprint' => dod_fingerprint($repoDir), 'version' => $version, 'built' => date('c')], JSON_PRETTY_PRINT) . "\n");
        }
        ps_vlog("build: source fingerprint written next to both zips (validate-zip and validator-api check the zip against it)");
    }
}

echo $zipPath . "\n";

// ---------------------------------------------------------------------------

/**
 * Prefixes the given namespaces with $prefix in every code and config file of the stage
 * (module code and vendor/), including the escaped form used in JSON and PHP strings.
 * vendor/composer is regenerated afterwards by dump-autoload, except installed.json,
 * which is rewritten so the PSR-4 map uses the new namespaces.
 *
 * @param string[] $scopes namespaces to scope, e.g. ['PrestaShopHeaven\\ModuleKit']
 *
 * @return int number of changed files
 */
function scopeNamespaces(string $stage, string $prefix, array $scopes): int
{
    $patterns = [];
    foreach ($scopes as $namespace) {
        $namespace = trim($namespace, '\\');
        $single = preg_quote($namespace, '#');
        $double = preg_quote(str_replace('\\', '\\\\', $namespace), '#');
        // Not part of a longer name: no identifier character (or "x\\") right before it.
        $before = '(?<![A-Za-z0-9_])(?<![A-Za-z0-9_]\\\\)(?<![A-Za-z0-9_]\\\\\\\\)';
        $after = '(?![A-Za-z0-9_])';
        $patterns['#' . $before . $double . $after . '#'] = str_replace('\\', '\\\\', $prefix . '\\' . $namespace);
        $patterns['#' . $before . $single . $after . '#'] = $prefix . '\\' . $namespace;
    }

    $changed = 0;
    $perPart = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($stage, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        $path = str_replace('\\', '/', $file->getPathname());
        $relative = substr($path, strlen($stage) + 1);
        if (str_starts_with($relative, 'vendor/composer/') && $relative !== 'vendor/composer/installed.json') {
            continue;
        }
        if (!preg_match('/\.(php|ya?ml|json|xml|neon|twig)$/i', $path)) {
            continue;
        }
        $content = (string) file_get_contents($path);
        $new = $content;
        foreach ($patterns as $pattern => $replacement) {
            $new = (string) preg_replace_callback($pattern, static fn (): string => $replacement, $new);
        }
        if ($new !== $content) {
            if (str_ends_with($path, '.php') && !str_starts_with($relative, 'vendor/')) {
                $new = sortImportBlocks($new);
            }
            file_put_contents($path, $new);
            ++$changed;
            // Verbose summary per package (vendor/<a>/<b>), or "module" for the module's own files.
            $where = str_starts_with($relative, 'vendor/') ? implode('/', array_slice(explode('/', $relative), 0, 3)) : 'module';
            $perPart[$where] = ($perPart[$where] ?? 0) + 1;
        }
    }
    foreach ($perPart as $where => $count) {
        ps_vlog("build: scoped $count file(s) in $where");
    }

    return $changed;
}

/**
 * Sorts each block of consecutive class imports ("use A\B;") the way php-cs-fixer's
 * ordered_imports does (case-insensitive, "\" as a space). Scoping changes the names, and the
 * online validator reports unsorted imports in the module's own files.
 */
function sortImportBlocks(string $code): string
{
    return (string) preg_replace_callback(
        '/^(?:use [A-Za-z0-9_\\\\]+(?: as [A-Za-z0-9_]+)?;\R)+/m',
        static function (array $m): string {
            $lines = preg_split('/\R/', rtrim($m[0])) ?: [];
            $key = static fn (string $line): string => str_replace('\\', ' ', (string) preg_replace('/^use |;$/', '', $line));
            usort($lines, static fn (string $a, string $b): int => strcasecmp($key($a), $key($b)));
            $eol = str_contains($m[0], "\r\n") ? "\r\n" : "\n";

            return implode($eol, $lines) . $eol;
        },
        $code
    );
}

/**
 * True for files and directories that are not shipped (dev tooling, caches, VCS).
 */
function isExcluded(string $relative): bool
{
    $segments = explode('/', $relative);
    foreach (array_slice($segments, 0, -1) as $dir) {
        if (in_array($dir, EXCLUDE['dirs'], true)) {
            return true;
        }
    }
    $last = end($segments);
    if (in_array($last, EXCLUDE['dirs'], true)) {
        return true;
    }
    foreach (EXCLUDE['files'] as $pattern) {
        if (fnmatch($pattern, $last)) {
            return true;
        }
    }

    return false;
}

/**
 * Copies the module into the stage, leaving out everything isExcluded() rejects.
 */
function copyFiltered(string $source, string $target): void
{
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    $copied = 0;
    $left = [];
    foreach ($it as $item) {
        $relative = substr(str_replace('\\', '/', $item->getPathname()), strlen($source) + 1);
        if (isExcluded($relative)) {
            // Verbose: only the top of what is left out, not every file inside an excluded folder.
            if (dirname($relative) === '.' || !isExcluded(dirname($relative))) {
                $left[] = $relative . ($item->isDir() ? '/' : '');
            }
            continue;
        }
        $copied += $item->isDir() ? 0 : 1;
        $dest = "$target/$relative";
        if ($item->isDir()) {
            @mkdir($dest, 0775, true);
        } else {
            @mkdir(dirname($dest), 0775, true);
            copy($item->getPathname(), $dest);
        }
    }
    ps_vlog("build: copied $copied file(s); left out: " . ($left !== [] ? implode(', ', $left) : 'nothing'));
}

/**
 * Puts the module's index.php in every directory of the stage that has none (validator rule).
 */
function addIndexFiles(string $root, string $content): void
{
    $dirs = [$root];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($it as $item) {
        if ($item->isDir()) {
            $dirs[] = $item->getPathname();
        }
    }
    $added = 0;
    foreach ($dirs as $dir) {
        if (!is_file("$dir/index.php")) {
            file_put_contents("$dir/index.php", $content);
            ++$added;
        }
    }
    ps_vlog('build: index.php added to ' . $added . ' of ' . count($dirs) . ' folder(s)');
}

/**
 * Deletes a directory with everything in it.
 */
function removeTree(string $dir): void
{
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($dir);
}
