<?php
/**
 * The two builds of a module (update-system/PLAN.md §5), used by build-zip.php: the direct-sale build and the Addons
 * build (no updater notice, module_key from module.spec.json). Functions throw RuntimeException on failure.
 */

declare(strict_types=1);

require_once __DIR__ . '/verbose.php';

/**
 * Zips a stage with <module>/ as root. Returns [files, uncompressed bytes].
 *
 * @return array{int, int}
 */
function zipStage(string $stage, string $name, string $zipPath): array
{
    @unlink($zipPath);
    $zip = new ZipArchive();
    $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($stage, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    $files = 0;
    $bytes = 0;
    foreach ($it as $item) {
        $relative = $name . '/' . substr(str_replace('\\', '/', $item->getPathname()), strlen($stage) + 1);
        $item->isDir() ? $zip->addEmptyDir($relative) : $zip->addFile($item->getPathname(), $relative);
        if (!$item->isDir()) {
            ++$files;
            $bytes += (int) $item->getSize();
        }
    }
    $zip->close();

    return [$files, $bytes];
}

/** module.spec.json > distribution (addons when not set). */
function moduleDistribution(string $repoDir): string
{
    $spec = is_file("$repoDir/module.spec.json") ? json_decode((string) file_get_contents("$repoDir/module.spec.json"), true) : null;
    $distribution = is_array($spec) ? ($spec['distribution'] ?? 'addons') : 'addons';

    return is_string($distribution) ? $distribution : 'addons';
}

/** The Addons key from module.spec.json > module_key ('' when there is none yet, e.g. before the first submission). */
function readModuleKey(string $repoDir): string
{
    $spec = is_file("$repoDir/module.spec.json") ? json_decode((string) file_get_contents("$repoDir/module.spec.json"), true) : null;
    $key = is_array($spec) ? ($spec['module_key'] ?? '') : '';

    return is_string($key) && preg_match('/^[a-f0-9]{32}$/i', trim($key)) === 1 ? strtolower(trim($key)) : '';
}

/**
 * Sets $this->module_key in the main class: replaces an existing assignment, or adds one before
 * parent::__construct() when a key is given. An empty key and no assignment leaves the file alone.
 */
function setModuleKey(string $mainFile, string $key): void
{
    $code = (string) file_get_contents($mainFile);
    $assignment = "\$this->module_key = '$key';";
    $count = 0;
    $code = (string) preg_replace('/\$this->module_key\s*=\s*([\'"])[^\'"]*\1\s*;/', $assignment, $code, -1, $count);
    if ($count === 0 && $key !== '') {
        $code = (string) preg_replace('/^(\s*)parent::__construct\(\);/m', "\$1$assignment\n\$1parent::__construct();", $code, 1, $count);
        if ($count === 0) {
            throw new RuntimeException("Cannot add module_key: no parent::__construct(); in $mainFile");
        }
    }
    file_put_contents($mainFile, $code);
}

/**
 * The Addons stage: a copy of the direct stage without module-kit's updater notice (src/Updater, possibly scoped),
 * with the Addons module_key, and an autoloader that no longer knows the left-out classes. Fails when an
 * update-server URL is still in the code (REL-07: no PrestaHaven URLs in what Addons ships).
 */
function makeAddonsStage(string $stage, string $addonsStage, string $name, string $moduleKey, string $quiet): void
{
    @mkdir($addonsStage, 0775, true);
    copyTree($stage, $addonsStage);
    $removed = 0;
    foreach (glob("$addonsStage/vendor/*/module-kit/src/Updater", GLOB_ONLYDIR) ?: [] as $dir) {
        removeChannelTree($dir);
        ++$removed;
    }
    ps_vlog('build: Addons build: ' . ($removed > 0 ? 'updater notice left out' : 'no updater notice in this module-kit'));
    setModuleKey("$addonsStage/$name.php", $moduleKey);
    if (is_file("$addonsStage/composer.json")) {
        $cmd = 'composer dump-autoload --optimize --no-dev --no-interaction' . $quiet . ' --working-dir=' . escapeshellarg($addonsStage);
        ps_vcmd($cmd, (string) getcwd());
        passthru($cmd, $code);
        if ($code !== 0) {
            throw new RuntimeException('composer dump-autoload failed for the Addons build');
        }
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($addonsStage, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if ($file->isFile() && preg_match('/\.(php|js|tpl|twig|json|xml|yml)$/', $file->getFilename()) === 1
            && str_contains((string) file_get_contents($file->getPathname()), 'updates.prestahaven.com')) {
            throw new RuntimeException('The Addons build still mentions updates.prestahaven.com: ' . substr(str_replace('\\', '/', $file->getPathname()), strlen($addonsStage) + 1));
        }
    }
}

/** Removes a directory tree. */
function removeChannelTree(string $dir): void
{
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($dir);
}

/** Copies a directory tree (files and folders). */
function copyTree(string $source, string $target): void
{
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($it as $item) {
        $to = $target . '/' . substr(str_replace('\\', '/', $item->getPathname()), strlen($source) + 1);
        $item->isDir() ? @mkdir($to, 0775, true) : copy($item->getPathname(), $to);
    }
}

