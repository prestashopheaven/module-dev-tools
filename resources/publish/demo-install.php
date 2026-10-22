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
 * 4. gives the limited demo profile(s) the rights on the module's pages, not the right to uninstall it (--rights);
 * 5. clears the cache; on a failure in 3 the old directory comes back.
 * Prints "DEMO-OK <installed version>" at the end; exit code 0 = done.
 *
 *   php demo-install.php --rights <module>   only step 4 (also by hand, for a module that is installed already)
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

if (($argv[1] ?? '') === '--rights') {
    $module = (string) ($argv[2] ?? '');
    if (preg_match('/^[a-z][a-z0-9]{3,}$/', $module) !== 1 || !is_file('config/config.inc.php')) {
        fwrite(STDERR, "Usage (in the root of the shop): php demo-install.php --rights <module>\n");
        exit(2);
    }
    require 'config/config.inc.php';
    demo_rights($module);
    exit(0);
}

/**
 * Rights of the limited demo profile(s) on a module. On install PrestaShop gives the module's own rights (configure,
 * uninstall: ps_module_access) to every profile that may manage modules, but the rights on the module's back-office
 * pages (its tabs: ps_access) only to SuperAdmin. A demo visitor could then uninstall the module but not open it. So
 * every profile other than SuperAdmin that has a right on this module gets every right on its tabs and loses the right
 * to uninstall it. Runs again on every upgrade (new tabs); a profile without any right on the module (taken away by
 * hand on purpose) is left alone. Prints one line per profile.
 */
function demo_rights(string $module): void
{
    $db = Db::getInstance();
    $p = _DB_PREFIX_;
    $role = static fn (string $slug): int => (int) $db->getValue("SELECT id_authorization_role FROM {$p}authorization_role WHERE slug = '" . pSQL($slug) . "'");
    $actions = ['CREATE', 'READ', 'UPDATE', 'DELETE'];
    $moduleRoles = [];
    foreach ($actions as $action) {
        $moduleRoles[$action] = $role('ROLE_MOD_MODULE_' . strtoupper($module) . '_' . $action);
    }
    $known = implode(',', array_filter($moduleRoles)) ?: '0';
    $rows = $db->executeS("SELECT DISTINCT id_profile FROM {$p}module_access WHERE id_authorization_role IN ($known) AND id_profile <> " . (int) _PS_ADMIN_PROFILE_);
    $profiles = array_map('intval', array_column(is_array($rows) ? $rows : [], 'id_profile'));
    $tabRoles = [];
    $tabs = $db->executeS("SELECT class_name FROM {$p}tab WHERE module = '" . pSQL($module) . "'");
    foreach (is_array($tabs) ? $tabs : [] as $tab) {
        foreach ($actions as $action) {
            $id = $role('ROLE_MOD_TAB_' . strtoupper((string) $tab['class_name']) . '_' . $action);
            if ($id > 0) {
                $tabRoles[] = $id;
            }
        }
    }
    $delete = $moduleRoles['DELETE'];
    foreach ($profiles as $profile) {
        $added = 0;
        foreach ($tabRoles as $id) {
            if (!$db->getValue("SELECT 1 FROM {$p}access WHERE id_profile = $profile AND id_authorization_role = $id")) {
                $db->execute("INSERT INTO {$p}access (id_profile, id_authorization_role) VALUES ($profile, $id)");
                ++$added;
            }
        }
        $revoked = $delete > 0 && (bool) $db->getValue("SELECT 1 FROM {$p}module_access WHERE id_profile = $profile AND id_authorization_role = $delete")
            && $db->delete('module_access', "id_profile = $profile AND id_authorization_role = $delete");
        $name = (string) $db->getValue("SELECT name FROM {$p}profile_lang WHERE id_profile = $profile AND id_lang = " . (int) Configuration::get('PS_LANG_DEFAULT'));
        echo "Rights of profile $profile ($name): $added of " . count($tabRoles) . ' right(s) on the module pages added, right to uninstall '
            . ($revoked ? 'removed' : 'not there') . "\n";
    }
    if ($profiles === []) {
        echo "Rights: no profile other than SuperAdmin has rights on $module, nothing to do.\n";
    }
}

[$zipFile, $module, $php] = array_slice($argv, 1, 3) + ['', '', 'php'];
$problem = !is_file($zipFile) ? "zip not found: $zipFile (in " . getcwd() . ')'
    : (preg_match('/^[a-z][a-z0-9]{3,}$/', $module) !== 1 ? "not a module name: $module"
    : (!is_file('config/config.inc.php') || !is_dir('modules') ? 'not the root of a shop: ' . getcwd() : ''));
if ($problem !== '') {
    fwrite(STDERR, "Usage (in the root of the shop): php demo-install.php <zip> <module> [php]\n$problem\n");
    exit(2);
}

/**
 * One verbose line (VERBOSE=1, set by demo-site.sh -v / ps-module -v), on the output demo-site.sh shows.
 */
function demo_vlog(string $message): void
{
    if (getenv('VERBOSE') === '1') {
        echo '  [v] demo-install: ' . $message . "\n";
    }
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
    demo_vlog("\$ $php bin/console $arguments");
    $start = microtime(true);
    passthru(escapeshellarg($php) . ' bin/console ' . $arguments . ' 2>&1', $code);
    demo_vlog(sprintf('bin/console: exit %d after %.1fs', $code, microtime(true) - $start));

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
demo_vlog(sprintf('zip %s: %d file(s), %.1f MB, extracted to %s', $zipFile, $zip->numFiles, filesize($zipFile) / 1048576, $staging));
$zip->close();
if (!is_file("$staging/$module/$module.php")) {
    demo_remove($staging);
    fwrite(STDERR, "The zip does not contain $module/$module.php.\n");
    exit(1);
}

// Installed already? Ask PrestaShop in a separate process, so this script does not keep the old classes loaded.
$check = 'require "config/config.inc.php"; echo Module::isInstalled(' . var_export($module, true) . ') ? "yes" : "no";';
$installed = trim((string) shell_exec(escapeshellarg($php) . ' -r ' . escapeshellarg($check) . ' 2>/dev/null')) === 'yes';
demo_vlog("$module is " . ($installed ? 'installed: upgrade' : 'not installed: install'));

if (is_dir($target) && !rename($target, $backup)) {
    demo_remove($staging);
    fwrite(STDERR, "Cannot move $target aside.\n");
    exit(1);
}
rename("$staging/$module", $target);
demo_remove($staging);
demo_vlog("new files in $target" . (is_dir($backup) ? " (previous version kept in $backup until the install succeeds)" : ''));

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
// The rights of the limited demo profile(s), in a separate process (it loads PrestaShop): see demo_rights().
passthru(escapeshellarg($php) . ' ' . escapeshellarg(__FILE__) . ' --rights ' . escapeshellarg($module) . ' 2>&1', $rightsCode);
if ($rightsCode !== 0) {
    echo "The rights of the demo profile(s) could not be set (exit $rightsCode): grant them by hand.\n";
}
demo_console($php, 'cache:clear --env=prod --no-warmup');

// The version PrestaShop now has in ps_module (the installed one, not the one in the file).
// $module is checked above ([a-z0-9] only), so it is safe inside the SQL string.
$read = 'require "config/config.inc.php"; echo (string) Db::getInstance()->getValue("SELECT version FROM " . _DB_PREFIX_ . "module WHERE name = \'' . $module . '\'");';
$version = trim((string) shell_exec(escapeshellarg($php) . ' -r ' . escapeshellarg($read) . ' 2>/dev/null'));
echo "DEMO-OK $version\n";
exit(0);
