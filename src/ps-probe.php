<?php
/**
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License version 3.0
 * that is bundled with this package in the file LICENSE.md.
 * It is also available through the world-wide-web at this URL:
 * https://opensource.org/licenses/AFL-3.0
 *
 * @author    George van Engers | PrestaHaven.com
 * @copyright Since 2026 George van Engers
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 */

/*
 * Runs INSIDE the PrestaShop container (copied there by ps-test.php).
 * Prints a JSON snapshot of everything the module left in the shop:
 * module row, hooks, configuration keys, tabs and its own tables (schema), and per shop:
 * module_shops (shops where the module is enabled), hook_shops (hook => shops) and
 * configuration_scopes (key => 'global', 'g<id_shop_group>' or 's<id_shop>' rows).
 *
 * Usage: php ps-probe.php <module_name> <CONFIG_PREFIX>
 * With PS_MODULE_VERBOSE=1 (passed in by ps-test.php) it reports what it found on STDERR.
 */

$root = getenv('_PS_ROOT_DIR_') ?: '/var/www/html';
if (!defined('_PS_ADMIN_DIR_')) {
    define('_PS_ADMIN_DIR_', $root . '/admin-dev');
}
require_once $root . '/config/config.inc.php';

$name = $argv[1];
$prefix = $argv[2];
$db = Db::getInstance();
probeLog("shop $root, module $name, configuration prefix {$prefix}_, table prefix " . _DB_PREFIX_);

$instance = Module::getInstanceByName($name);
$configurable = $instance instanceof Module && method_exists($instance, 'getContent');

$module = $db->getRow('SELECT id_module, version, active FROM `' . _DB_PREFIX_ . 'module` WHERE name = \'' . pSQL($name) . '\'');
$idModule = $module ? (int) $module['id_module'] : 0;
probeLog($module ? "module row: id $idModule, version {$module['version']}, active {$module['active']}" : 'no module row (not installed)');

$hooks = $idModule ? array_column($db->executeS(
    'SELECT DISTINCT h.name FROM `' . _DB_PREFIX_ . 'hook_module` hm
     JOIN `' . _DB_PREFIX_ . 'hook` h ON h.id_hook = hm.id_hook
     WHERE hm.id_module = ' . $idModule . ' ORDER BY h.name'
) ?: [], 'name') : [];

$config = array_column($db->executeS(
    'SELECT DISTINCT name FROM `' . _DB_PREFIX_ . 'configuration`
     WHERE name LIKE \'' . pSQL(str_replace('_', '\_', $prefix)) . '\\\\_%\' ORDER BY name'
) ?: [], 'name');

// Multistore: in which shops the module and its hooks are enabled, and at which level
// (global, shop group, shop) each configuration key has a value.
$moduleShops = $idModule ? array_map('intval', array_column($db->executeS(
    'SELECT id_shop FROM `' . _DB_PREFIX_ . 'module_shop` WHERE id_module = ' . $idModule . ' ORDER BY id_shop'
) ?: [], 'id_shop')) : [];

$hookShops = [];
foreach ($idModule ? $db->executeS(
    'SELECT DISTINCT h.name, hm.id_shop FROM `' . _DB_PREFIX_ . 'hook_module` hm
     JOIN `' . _DB_PREFIX_ . 'hook` h ON h.id_hook = hm.id_hook
     WHERE hm.id_module = ' . $idModule . ' ORDER BY h.name, hm.id_shop'
) ?: [] : [] as $row) {
    $hookShops[$row['name']][] = (int) $row['id_shop'];
}

$configurationScopes = [];
foreach ($db->executeS(
    'SELECT DISTINCT name, id_shop_group, id_shop FROM `' . _DB_PREFIX_ . 'configuration`
     WHERE name LIKE \'' . pSQL(str_replace('_', '\_', $prefix)) . '\\\\_%\' ORDER BY name, id_shop_group, id_shop'
) ?: [] as $row) {
    $configurationScopes[$row['name']][] = $row['id_shop'] !== null ? 's' . (int) $row['id_shop']
        : ($row['id_shop_group'] !== null ? 'g' . (int) $row['id_shop_group'] : 'global');
}

$tabs = array_column($db->executeS(
    'SELECT class_name FROM `' . _DB_PREFIX_ . 'tab` WHERE module = \'' . pSQL($name) . '\' ORDER BY class_name'
) ?: [], 'class_name');

// Override files that still carry this module's marker ("module: <name>").
$overrides = [];
if (is_dir($root . '/override')) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/override', FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if (str_ends_with($file->getFilename(), '.php')
            && preg_match('/module:\s*' . preg_quote($name, '/') . '\b/', (string) file_get_contents($file->getPathname()))) {
            $overrides[] = substr(str_replace('\\', '/', $file->getPathname()), strlen($root) + 1);
        }
    }
    sort($overrides);
}

$tables = [];
foreach ($db->executeS('SHOW TABLES LIKE \'' . pSQL(str_replace('_', '\_', _DB_PREFIX_ . $name)) . '%\'') ?: [] as $row) {
    $table = (string) reset($row);
    // getRow() appends LIMIT 1, which SHOW CREATE TABLE does not accept.
    $create = ($db->executeS('SHOW CREATE TABLE `' . bqSQL($table) . '`') ?: [[]])[0];
    $lines = array_map('trim', explode("\n", (string) ($create['Create Table'] ?? '')));
    $columns = [];
    $keys = [];
    foreach ($lines as $line) {
        $line = rtrim($line, ',');
        if (str_starts_with($line, '`')) {
            $columns[] = $line;
        } elseif (preg_match('/^(PRIMARY KEY|UNIQUE KEY|KEY|FULLTEXT KEY|CONSTRAINT)/', $line)) {
            $keys[] = $line;
        } elseif (str_starts_with($line, ')')) {
            $keys[] = 'OPTIONS ' . preg_replace('/\s*AUTO_INCREMENT=\d+/', '', $line);
        }
    }
    sort($columns);
    sort($keys);
    $tables[$table] = ['columns' => $columns, 'keys' => $keys];
}

probeLog(sprintf(
    '%d hook(s), %d configuration key(s), %d tab(s), %d override(s), table(s): %s',
    count($hooks),
    count($config),
    count($tabs),
    count($overrides),
    $tables === [] ? '(none)' : implode(', ', array_keys($tables))
));

echo json_encode([
    'installed' => (bool) $module,
    'version' => $module['version'] ?? null,
    'active' => isset($module['active']) ? (bool) $module['active'] : null,
    'hooks' => $hooks,
    'configuration' => $config,
    'tabs' => $tabs,
    'overrides' => $overrides,
    'configurable' => $configurable,
    'module_shops' => $moduleShops,
    'hook_shops' => $hookShops,
    'configuration_scopes' => $configurationScopes,
    'tables' => $tables,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

/**
 * Prints a verbose line on STDERR when PS_MODULE_VERBOSE is set. Local copy of ps_vlog(): this
 * script runs in the container, where src/verbose.php is not available.
 */
function probeLog(string $message): void
{
    if (in_array(strtolower((string) getenv('PS_MODULE_VERBOSE')), ['1', 'true', 'yes', 'on'], true)) {
        fwrite(STDERR, '[v ' . gmdate('H:i:s') . '] ps-probe: ' . $message . "\n");
    }
}
