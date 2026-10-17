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
 * Runs INSIDE the PrestaShop container (copied there by ps-multistore.php), as www-data.
 * Turns the test shop into a multistore shop and runs the shop-side multistore checks.
 * Every command prints one JSON object with at least "ok" and "problems".
 *
 * Usage:
 *   php ps-multistore-shop.php setup                     enable multistore; "Shop 2" in group 1, "Shop 3" in a new
 *                                                        "Group 2" (virtual URIs /shop2/ and /shop3/); idempotent
 *   php ps-multistore-shop.php isolation <module>        per-shop and per-group values of every configuration key
 *                                                        stay in their shop/group (MS-03/MS-04); leaves the
 *                                                        markers in place (see msIsolation())
 *   php ps-multistore-shop.php reset <module>            removes the shop/group values again and restores the
 *                                                        global values saved by "isolation"
 *   php ps-multistore-shop.php keep-check <module>       after an upgrade: the values written by "isolation" are
 *                                                        unchanged (MS-03)
 *   php ps-multistore-shop.php delete-shop <module>      creates a temporary shop, gives it values and deletes it;
 *                                                        lists rows of the module that still point to it (MS-08)
 *   php ps-multistore-shop.php find-value <module> <v>   configuration rows of the module with value <v>, with
 *                                                        their scope ('global', 'g<id>', 's<id>')
 *   php ps-multistore-shop.php enable|disable <module> <id_shop>
 *                                                        enables/disables the module in one shop, as Module
 *                                                        Manager does in that shop context ("bin/console
 *                                                        --id_shop" is rejected on PrestaShop 9)
 */

$root = getenv('_PS_ROOT_DIR_') ?: '/var/www/html';
if (!defined('_PS_ADMIN_DIR_')) {
    define('_PS_ADMIN_DIR_', $root . '/admin-dev');
}
require_once $root . '/config/config.inc.php';

/** File in the container where "isolation" keeps what it wrote, for "reset" and "keep-check". */
const MS_STATE_FILE = '/tmp/ps-multistore-state.json';

$command = $argv[1] ?? '';
$module = $argv[2] ?? '';

switch ($command) {
    case 'setup':
        msOut(msSetup());
        break;
    case 'isolation':
        msOut(msIsolation($module));
        break;
    case 'reset':
        msOut(msReset());
        break;
    case 'keep-check':
        msOut(msKeepCheck());
        break;
    case 'delete-shop':
        msOut(msDeleteShop($module));
        break;
    case 'find-value':
        msOut(['ok' => true, 'problems' => [], 'rows' => msFindValue($module, $argv[3] ?? '')]);
        break;
    case 'enable':
    case 'disable':
        msOut(msToggleInShop($module, $command === 'enable', (int) ($argv[3] ?? 0)));
        break;
    default:
        msOut(['ok' => false, 'problems' => ["Unknown command '$command'."]]);
}

/**
 * Enables multistore and creates "Shop 2" (group 1, /shop2/) and "Shop 3" (new "Group 2", /shop3/),
 * with the same data import as "Shop Parameters > General > Multistore > Add new shop".
 *
 * @return array<string, mixed>
 */
function msSetup(): array
{
    $db = Db::getInstance();
    Configuration::updateGlobalValue('PS_MULTISHOP_FEATURE_ACTIVE', 1);

    $group2 = (int) $db->getValue('SELECT id_shop_group FROM `' . _DB_PREFIX_ . 'shop_group` WHERE name = \'Group 2\'');
    if (!$group2) {
        $group = new ShopGroup();
        $group->name = 'Group 2';
        $group->share_customer = false;
        $group->share_order = false;
        $group->share_stock = false;
        $group->active = true;
        $group->add();
        $group2 = (int) $group->id;
    }
    foreach ([['Shop 2', 1, 'shop2/'], ['Shop 3', $group2, 'shop3/']] as [$name, $idGroup, $virtual]) {
        if (Shop::getIdByName($name)) {
            continue;
        }
        $created = msCreateShop($name, $idGroup, $virtual, []);
        if (is_string($created)) {
            return ['ok' => false, 'problems' => [$created]];
        }
    }
    Search::indexation(true);
    $active = Shop::isFeatureActive();

    return [
        'ok' => $active,
        'problems' => $active ? [] : ['Multistore is not active after setup.'],
        'shops' => msShops(),
        'ids' => msIds(),
    ];
}

/**
 * Creates a shop with a main URL (same domain, own virtual URI) and copies the data of shop 1,
 * like the back office with "Import data" ticked (AdminShopController::processAdd()/afterAdd()).
 * Returns the new shop id or an error message.
 *
 * @param string[] $extraImport more "importData" keys, e.g. 'Module<Name>' (runs actionShopDataDuplication of that module)
 */
function msCreateShop(string $name, int $idGroup, string $virtualUri, array $extraImport): int|string
{
    $db = Db::getInstance();
    $main = $db->getRow('SELECT domain, domain_ssl, physical_uri FROM `' . _DB_PREFIX_ . 'shop_url` WHERE id_shop = 1 AND main = 1');
    // The back office sends the categories as "categoryBox"; copyShopData() reads that value too
    // (count() on false is a TypeError on PHP 8).
    $categories = array_map('intval', array_column($db->executeS('SELECT id_category FROM `' . _DB_PREFIX_ . 'category_shop` WHERE id_shop = 1') ?: [], 'id_category'));
    $_POST['categoryBox'] = $categories;

    $shop = new Shop();
    $shop->name = $name;
    $shop->id_shop_group = $idGroup;
    $shop->active = true;
    $shop->id_category = (int) Configuration::get('PS_HOME_CATEGORY');
    $shop->theme_name = (string) $db->getValue('SELECT theme_name FROM `' . _DB_PREFIX_ . 'shop` WHERE id_shop = 1');
    $shop->color = '';
    if (!$shop->add()) {
        return "Creating $name failed: " . $db->getMsgError();
    }

    $url = new ShopUrl();
    $url->id_shop = (int) $shop->id;
    $url->domain = $main['domain'];
    $url->domain_ssl = $main['domain_ssl'];
    $url->physical_uri = $main['physical_uri'];
    $url->virtual_uri = $virtualUri;
    $url->main = true;
    $url->active = true;
    if (!$url->add()) {
        return "Creating the URL of $name failed: " . $db->getMsgError();
    }

    // The "Import data" choices of the back office. 'group' copies group_shop (without it the shop
    // has no customer groups and shows no prices); 'module' and 'hook_module' copy module_shop and
    // hook_module of shop 1 (theme modules, and the tested module when it is installed).
    $import = array_fill_keys(array_merge([
        'attribute_group', 'carrier', 'category', 'cms', 'contact', 'country', 'currency', 'employee',
        'feature', 'group', 'hook_module', 'image', 'lang', 'manufacturer', 'meta_lang', 'module',
        'product', 'product_attribute', 'stock_available', 'store', 'supplier', 'tax_rules_group', 'zone',
    ], $extraImport), true);
    $shop->copyShopData(1, $import);
    $shop->associateSuperAdmins();
    Category::updateFromShop(array_values(array_unique(array_merge([(int) Configuration::get('PS_ROOT_CATEGORY')], $categories))), (int) $shop->id);

    Shop::resetStaticCache();
    Shop::cacheShops(true);
    Tools::generateHtaccess();

    return (int) $shop->id;
}

/**
 * The ids of the test shops, found by name: shop2 (in group 1), shop3 and its group group2.
 *
 * @return array{shop2: int, shop3: int, group2: int}
 */
function msIds(): array
{
    $shop3 = (int) Shop::getIdByName('Shop 3');

    return [
        'shop2' => (int) Shop::getIdByName('Shop 2'),
        'shop3' => $shop3,
        'group2' => (int) Db::getInstance()->getValue('SELECT id_shop_group FROM `' . _DB_PREFIX_ . 'shop` WHERE id_shop = ' . $shop3),
    ];
}

/**
 * Checks per configuration key that a value per shop and per group only applies there
 * (Configuration::get() falls back shop -> group -> global), and leaves these markers in place:
 * global "ms-gl-<KEY>." (string keys only), shop 2 "ms-s2-<KEY>.", group 2 "ms-g2-<KEY>.".
 * Keys with a non-string default get their current global value in shop 2 and group 2, so the
 * module keeps working; the rows exist all the same.
 *
 * @return array<string, mixed>
 */
function msIsolation(string $module): array
{
    $problems = [];
    $ids = msIds();
    $shopGroups = [1 => 1, $ids['shop2'] => 1, $ids['shop3'] => $ids['group2']];
    $state = ['ids' => $ids, 'keys' => []];

    foreach (msConfigurationKeys($module) as $key => $isString) {
        $global = Configuration::get($key, null, 0, 0);
        // Before: every shop inherits the global value.
        foreach ($shopGroups as $idShop => $idGroup) {
            $value = Configuration::get($key, null, $idGroup, $idShop);
            if ((string) $value !== (string) $global) {
                $problems[] = "$key: shop $idShop has its own value " . json_encode($value) . ' before any was set (expected the global value ' . json_encode($global) . ', MS-03).';
            }
        }

        $markers = $isString
            ? ['gl' => "ms-gl-$key.", 's2' => "ms-s2-$key.", 'g2' => "ms-g2-$key."]
            : ['gl' => (string) $global, 's2' => (string) $global, 'g2' => (string) $global];
        $state['keys'][$key] = ['string' => $isString, 'global' => $global, 'markers' => $markers];
        if ($isString) {
            Configuration::updateGlobalValue($key, $markers['gl']);
        }
        // Shop and group explicitly: with null, updateValue() takes the id from the context
        // (here shop 1), so (..., $group, null) would write a value for shop 1, not for the group.
        Configuration::updateValue($key, $markers['s2'], false, 1, $ids['shop2']);
        Configuration::updateValue($key, $markers['g2'], false, $ids['group2'], 0);
        Configuration::clearConfigurationCacheForTesting();

        $expected = [1 => $markers['gl'], $ids['shop2'] => $markers['s2'], $ids['shop3'] => $markers['g2']];
        foreach ($expected as $idShop => $want) {
            $got = (string) Configuration::get($key, null, $shopGroups[$idShop], $idShop);
            if ($got !== $want) {
                $problems[] = "$key: shop $idShop reads " . json_encode($got) . ', expected ' . json_encode($want) . '.';
            }
        }
        if (!msHasScope($key, 'global') || !msHasScope($key, 's' . $ids['shop2']) || !msHasScope($key, 'g' . $ids['group2'])) {
            $problems[] = "$key: the global, shop 2 and group 2 rows are not all in ps_configuration after writing them.";
        }
    }
    file_put_contents(MS_STATE_FILE, json_encode($state));

    return ['ok' => $problems === [], 'problems' => $problems, 'keys' => array_keys($state['keys'])];
}

/**
 * Removes the shop and group values written by "isolation" and restores the global values.
 *
 * @return array<string, mixed>
 */
function msReset(): array
{
    $state = msState();
    foreach ($state['keys'] ?? [] as $key => $info) {
        Configuration::deleteFromGivenContext($key, null, (int) $state['ids']['shop2']);
        Configuration::deleteFromGivenContext($key, (int) $state['ids']['group2'], 0);
        if ($info['string']) {
            Configuration::updateGlobalValue($key, $info['global']);
        }
    }

    return ['ok' => true, 'problems' => []];
}

/**
 * After an upgrade: the shop, group and global values written by "isolation" are still there, unchanged.
 *
 * @return array<string, mixed>
 */
function msKeepCheck(): array
{
    $state = msState();
    $problems = [];
    Configuration::clearConfigurationCacheForTesting();
    foreach ($state['keys'] ?? [] as $key => $info) {
        $s2 = Configuration::get($key, null, 1, (int) $state['ids']['shop2']);
        if (!msHasScope($key, 's' . $state['ids']['shop2']) || (string) $s2 !== $info['markers']['s2']) {
            $problems[] = "$key: the value of shop 2 changed or was removed by the upgrade (now " . json_encode($s2) . ', MS-03).';
        }
        $g2 = Configuration::get($key, null, (int) $state['ids']['group2'], 0);
        if (!msHasScope($key, 'g' . $state['ids']['group2']) || (string) $g2 !== $info['markers']['g2']) {
            $problems[] = "$key: the value of group 2 changed or was removed by the upgrade (now " . json_encode($g2) . ', MS-03).';
        }
        if ($info['string'] && (string) Configuration::get($key, null, 0, 0) !== $info['markers']['gl']) {
            $problems[] = "$key: the global value was overwritten by the upgrade (MS-03).";
        }
    }

    return ['ok' => $problems === [], 'problems' => $problems];
}

/**
 * Creates a temporary shop after the module was installed (as a merchant does, with the module
 * ticked under "Import data"), gives it its own configuration values and deletes it again like
 * the back office does. Lists what of the module still refers to the deleted shop: rows with that
 * id_shop in the module's own tables (MS-08, a failure) and configuration rows (only reported:
 * harmless, uninstall removes them).
 *
 * @return array<string, mixed>
 */
function msDeleteShop(string $module): array
{
    $db = Db::getInstance();
    $created = msCreateShop('Shop delete test', 1, 'shopdel/', ['Module' . ucfirst($module)]);
    if (is_string($created)) {
        return ['ok' => false, 'problems' => [$created]];
    }
    $idShop = $created;
    $problems = [];
    $instance = Module::getInstanceByName($module);
    if ($instance instanceof Module
        && !(bool) $db->getValue('SELECT 1 FROM `' . _DB_PREFIX_ . 'module_shop` WHERE id_module = ' . (int) $instance->id . ' AND id_shop = ' . $idShop)) {
        $problems[] = 'The module is not enabled in a shop created after the install (module_shop was not copied).';
    }
    foreach (msConfigurationKeys($module) as $key => $isString) {
        Configuration::updateValue($key, $isString ? "ms-del-$key." : Configuration::get($key, null, 0, 0), false, 1, $idShop);
    }

    $shop = new Shop($idShop);
    if (Shop::hasDependency($idShop) || !$shop->delete()) {
        return ['ok' => false, 'problems' => ["Deleting the temporary shop $idShop failed: " . $db->getMsgError()]];
    }
    Shop::resetStaticCache();
    Tools::generateHtaccess();

    foreach ($db->executeS('SHOW TABLES LIKE \'' . pSQL(str_replace('_', '\_', _DB_PREFIX_ . $module)) . '%\'') ?: [] as $row) {
        $table = (string) reset($row);
        $columns = array_column($db->executeS('SHOW COLUMNS FROM `' . bqSQL($table) . '`') ?: [], 'Field');
        if (in_array('id_shop', $columns, true)) {
            $left = (int) $db->getValue('SELECT COUNT(*) FROM `' . bqSQL($table) . '` WHERE id_shop = ' . $idShop);
            if ($left > 0) {
                $problems[] = "$table still has $left row(s) of the deleted shop $idShop (MS-08: clean up in hookActionObjectShopDeleteAfter).";
            }
        }
    }
    $core = [];
    foreach (['module_shop', 'hook_module'] as $table) {
        $count = (int) $db->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . $table . '` WHERE id_shop = ' . $idShop);
        if ($count > 0) {
            $core[] = "$table: $count";
        }
    }
    $configuration = array_column($db->executeS(
        'SELECT name FROM `' . _DB_PREFIX_ . 'configuration` WHERE id_shop = ' . $idShop
        . ' AND name LIKE \'' . pSQL(str_replace('_', '\_', strtoupper($module))) . '\\\\_%\''
    ) ?: [], 'name');

    return ['ok' => $problems === [], 'problems' => $problems, 'configuration_left' => $configuration, 'core_rows_left' => $core];
}

/**
 * Enables or disables the module in one shop only: Module::enable()/disable() work on the shops
 * of the context, like Module Manager in the back office with that shop selected.
 *
 * @return array<string, mixed>
 */
function msToggleInShop(string $module, bool $enable, int $idShop): array
{
    // Module::enable() loads the module translations through the Symfony container.
    global $kernel;
    if (!$kernel) {
        $class = is_file(_PS_ROOT_DIR_ . '/app/AdminKernel.php') ? 'AdminKernel' : 'AppKernel'; // PS 9 : PS 8
        require_once _PS_ROOT_DIR_ . '/app/' . $class . '.php';
        $kernel = new $class(_PS_ENV_, _PS_MODE_DEV_);
        $kernel->boot();
    }
    Shop::setContext(Shop::CONTEXT_SHOP, $idShop);
    $instance = Module::getInstanceByName($module);
    if (!$instance instanceof Module) {
        return ['ok' => false, 'problems' => ["Module $module cannot be loaded."]];
    }
    $ok = $enable ? $instance->enable() : $instance->disable();

    return ['ok' => (bool) $ok, 'problems' => $ok ? [] : [($enable ? 'Enabling' : 'Disabling') . " the module in shop $idShop failed: " . implode(' ', $instance->getErrors())]];
}

/**
 * Configuration rows of the module that hold a value, with their scope.
 *
 * @return array<int, array{name: string, scope: string}>
 */
function msFindValue(string $module, string $value): array
{
    $rows = [];
    foreach (Db::getInstance()->executeS(
        'SELECT name, id_shop_group, id_shop FROM `' . _DB_PREFIX_ . 'configuration`'
        . ' WHERE name LIKE \'' . pSQL(str_replace('_', '\_', strtoupper($module))) . '\\\\_%\''
        . ' AND value = \'' . pSQL($value) . '\' ORDER BY name'
    ) ?: [] as $row) {
        $rows[] = ['name' => $row['name'], 'scope' => msScopeOf($row)];
    }

    return $rows;
}

/**
 * The scope of a ps_configuration row: 's<id_shop>', 'g<id_shop_group>' or 'global'.
 *
 * @param array<string, mixed> $row
 */
function msScopeOf(array $row): string
{
    if ($row['id_shop'] !== null) {
        return 's' . (int) $row['id_shop'];
    }

    return $row['id_shop_group'] !== null ? 'g' . (int) $row['id_shop_group'] : 'global';
}

/**
 * The configuration keys of the module: from its definition (module-kit) when it has one,
 * otherwise every key with the module prefix. Value: true when the default is a string.
 *
 * @return array<string, bool>
 */
function msConfigurationKeys(string $module): array
{
    $instance = Module::getInstanceByName($module);
    if ($instance instanceof Module && method_exists($instance, 'getModuleDefinition')) {
        $keys = [];
        foreach ($instance->getModuleDefinition()->configuration() as $key => $default) {
            $keys[$key] = is_string($default);
        }

        return $keys;
    }
    $keys = [];
    foreach (Db::getInstance()->executeS(
        'SELECT DISTINCT name FROM `' . _DB_PREFIX_ . 'configuration` WHERE name LIKE \''
        . pSQL(str_replace('_', '\_', strtoupper($module))) . '\\\\_%\''
    ) ?: [] as $row) {
        $keys[$row['name']] = !is_numeric((string) Configuration::getGlobalValue($row['name']));
    }

    return $keys;
}

/**
 * True when ps_configuration has a row for the key in the scope ('global', 's<id>' or 'g<id>').
 */
function msHasScope(string $key, string $scope): bool
{
    $where = match (true) {
        $scope === 'global' => 'id_shop IS NULL AND id_shop_group IS NULL',
        $scope[0] === 's' => 'id_shop = ' . (int) substr($scope, 1),
        default => 'id_shop IS NULL AND id_shop_group = ' . (int) substr($scope, 1),
    };

    return (bool) Db::getInstance()->getValue('SELECT 1 FROM `' . _DB_PREFIX_ . 'configuration` WHERE name = \'' . pSQL($key) . '\' AND ' . $where);
}

/**
 * The state file written by "isolation".
 *
 * @return array<string, mixed>
 */
function msState(): array
{
    $state = is_file(MS_STATE_FILE) ? json_decode((string) file_get_contents(MS_STATE_FILE), true) : null;

    return is_array($state) ? $state : [];
}

/**
 * All shops with their group and URL.
 *
 * @return array<int, array<string, mixed>>
 */
function msShops(): array
{
    return Db::getInstance()->executeS(
        'SELECT s.id_shop, s.id_shop_group, s.name, s.active, CONCAT(u.physical_uri, u.virtual_uri) AS uri
         FROM `' . _DB_PREFIX_ . 'shop` s LEFT JOIN `' . _DB_PREFIX_ . 'shop_url` u ON u.id_shop = s.id_shop AND u.main = 1
         ORDER BY s.id_shop'
    ) ?: [];
}

/**
 * Prints the result as JSON.
 *
 * @param array<string, mixed> $result
 */
function msOut(array $result): void
{
    echo json_encode($result, JSON_UNESCAPED_SLASHES) . "\n";
}
