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
 * Installs or upgrades a module zip in the demo shop. `ps-module publish demo` uploads this script and the release
 * zip by SFTP and runs it over SSH in the root of the shop:
 *
 *   php demo-install.php <zip> <module> <php-binary>
 *
 * 1. extracts the zip next to modules/<module> and checks it holds <module>/<module>.php;
 * 2. swaps the directories (the old one is kept until the end);
 * 3. runs `bin/console prestashop:module install|upgrade <module>` (install when the module is not installed yet);
 * 4. clears the cache; on a failure in 3 the old directory comes back.
 * Prints "DEMO-OK <installed version>" at the end; exit code 0 = done.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

[$zipFile, $module, $php] = array_slice($argv, 1, 3) + ['', '', 'php'];
if (!is_file($zipFile) || preg_match('/^[a-z][a-z0-9]{3,}$/', $module) !== 1 || !is_file('config/config.inc.php') || !is_dir('modules')) {
    fwrite(STDERR, "Usage (in the root of the shop): php demo-install.php <zip> <module> [php]\n");
    exit(2);
}

/**
 * Removes a directory with everything in it.
 */
function demo_remove(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($items as $item) {
        $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($dir);
}

/**
 * Runs bin/console with the given arguments; returns the exit code and prints the output.
 */
function demo_console(string $php, string $arguments): int
{
    passthru(escapeshellarg($php) . ' bin/console ' . $arguments . ' 2>&1', $code);

    return (int) $code;
}

$suffix = bin2hex(random_bytes(4));
$staging = "modules/.$module.new-$suffix";
$target = "modules/$module";
$backup = "modules/.$module.old-$suffix";

$zip = new ZipArchive();
if ($zip->open($zipFile) !== true || !$zip->extractTo($staging)) {
    fwrite(STDERR, "Cannot extract $zipFile.\n");
    exit(1);
}
$zip->close();
if (!is_file("$staging/$module/$module.php")) {
    demo_remove($staging);
    fwrite(STDERR, "The zip does not contain $module/$module.php.\n");
    exit(1);
}

// Installed already? Ask PrestaShop in a separate process, so this script does not keep the old classes loaded.
$check = 'require "config/config.inc.php"; echo Module::isInstalled(' . var_export($module, true) . ') ? "yes" : "no";';
$installed = trim((string) shell_exec(escapeshellarg($php) . ' -r ' . escapeshellarg($check) . ' 2>/dev/null')) === 'yes';

if (is_dir($target) && !rename($target, $backup)) {
    demo_remove($staging);
    fwrite(STDERR, "Cannot move $target aside.\n");
    exit(1);
}
rename("$staging/$module", $target);
demo_remove($staging);

$code = demo_console($php, 'prestashop:module ' . ($installed ? 'upgrade' : 'install') . ' ' . escapeshellarg($module));
if ($code !== 0) {
    demo_remove($target);
    if (is_dir($backup)) {
        rename($backup, $target);
    }
    fwrite(STDERR, sprintf("prestashop:module %s failed (exit %d): the previous version is back.\n", $installed ? 'upgrade' : 'install', $code));
    exit(1);
}
demo_remove($backup);
demo_console($php, 'cache:clear --env=prod --no-warmup');

// The version PrestaShop now has in ps_module (the installed one, not the one in the file).
// $module is checked above ([a-z0-9] only), so it is safe inside the SQL string.
$read = 'require "config/config.inc.php"; echo (string) Db::getInstance()->getValue("SELECT version FROM " . _DB_PREFIX_ . "module WHERE name = \'' . $module . '\'");';
$version = trim((string) shell_exec(escapeshellarg($php) . ' -r ' . escapeshellarg($read) . ' 2>/dev/null'));
echo "DEMO-OK $version\n";
exit(0);
