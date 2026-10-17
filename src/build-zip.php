<?php
/**
 * Builds the installable module zip: <repo>/dist/<module>.zip
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
 *  4. add index.php to every directory (also vendor/), zip with <module>/ as root
 */

declare(strict_types=1);

require_once __DIR__ . '/composer-paths.php';

const EXCLUDE = [
    // directories (matched against path segments)
    'dirs' => ['vendor', 'tests', 'node_modules', '.git', '.github', '.gitea', '.idea', '.vscode', '.cache', 'dist', '_dev'],
    // file name patterns (fnmatch)
    'files' => [
        '.php-cs-fixer*', 'phpstan*.neon', 'phpstan*.neon.dist', 'phpcs.xml*', 'phpunit.xml*', '.phpunit*',
        '.gitignore', '.gitattributes', '.editorconfig', '.DS_Store', 'Thumbs.db', '*.log', '*.zip', 'Makefile',
        'package.json', 'package-lock.json', 'tsconfig.json', 'webpack.config.js', '.env*', '.license-header.txt',
        'config.xml', 'config_*.xml',
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

copyFiltered($moduleDir, $stage);

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
    $cmd = 'composer install --no-dev --optimize-autoloader --no-interaction --no-progress --quiet --working-dir=' . escapeshellarg($stage);
    passthru($cmd, $code);
    if ($code !== 0) {
        fwrite(STDERR, "composer install --no-dev failed\n");
        exit(1);
    }

    $composer = json_decode((string) file_get_contents("$stage/composer.json"), true);
    $scopes = $composer['extra']['prestashopheaven']['scope'] ?? [];
    $prefix = (string) ($composer['extra']['prestashopheaven']['scope-prefix'] ?? '');
    if ($scopes !== [] && $prefix !== '') {
        $changed = scopeNamespaces($stage, $prefix, $scopes);
        fwrite(STDERR, "Scoped " . implode(', ', $scopes) . " under $prefix ($changed files)\n");
        passthru('composer dump-autoload --optimize --no-dev --no-interaction --quiet --working-dir=' . escapeshellarg($stage), $code);
        if ($code !== 0) {
            fwrite(STDERR, "composer dump-autoload failed after scoping\n");
            exit(1);
        }
    }
}

// Same as PrestaShop's own release build: composer files are not shipped.
@unlink("$stage/composer.json");
@unlink("$stage/composer.lock");

addIndexFiles($stage, (string) file_get_contents("$moduleDir/index.php"));

$distDir = dirname($moduleDir) . '/dist';
@mkdir($distDir, 0775, true);
// The validator requires the zip to be named after the module, without a version.
$zipPath = "$distDir/$name.zip";
fwrite(STDERR, "Building $name $version\n");
@unlink($zipPath);

$zip = new ZipArchive();
$zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($stage, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
foreach ($it as $item) {
    $relative = $name . '/' . substr(str_replace('\\', '/', $item->getPathname()), strlen($stage) + 1);
    $item->isDir() ? $zip->addEmptyDir($relative) : $zip->addFile($item->getPathname(), $relative);
}
$zip->close();

removeTree($work);

// Remember which source this zip was built from (definition-of-done stamps).
if (is_file(__DIR__ . '/dod.php')) {
    require_once __DIR__ . '/dod.php';
    $repoDir = dod_repo_dir($moduleDir);
    if ($repoDir !== null) {
        file_put_contents($zipPath . '.json', json_encode(['fingerprint' => dod_fingerprint($repoDir), 'version' => $version, 'built' => date('c')], JSON_PRETTY_PRINT) . "\n");
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
            file_put_contents($path, $new);
            ++$changed;
        }
    }

    return $changed;
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
    foreach ($it as $item) {
        $relative = substr(str_replace('\\', '/', $item->getPathname()), strlen($source) + 1);
        if (isExcluded($relative)) {
            continue;
        }
        $dest = "$target/$relative";
        if ($item->isDir()) {
            @mkdir($dest, 0775, true);
        } else {
            @mkdir(dirname($dest), 0775, true);
            copy($item->getPathname(), $dest);
        }
    }
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
    foreach ($dirs as $dir) {
        if (!is_file("$dir/index.php")) {
            file_put_contents("$dir/index.php", $content);
        }
    }
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
