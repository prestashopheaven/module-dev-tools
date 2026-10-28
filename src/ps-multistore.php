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
 * Multistore round of "ps-module shops" (conventions MS-17). Loaded by ps-test.php, which provides
 * the Shop class and the helpers (out, check, curlRequest, hiddenInputs, httpGet, cleanCheck,
 * releaseTags, exportRelease, currentVersion, removeDir).
 *
 * The round turns the test shop into a multistore shop (ps-multistore-shop.php setup: shop 2 in
 * the default group, shop 3 in a new group 2, at /shop2/ and /shop3/) and checks, for the module:
 *   - an install from bin/console (context: the default shop) enables the module and its hooks in
 *     every shop and writes only global configuration values (MS-02, MS-03)
 *   - a value per shop or group only applies there, in PHP and in the front office (MS-04)
 *   - the configuration page is a multistore form: all shops = global value, shop context with the
 *     checkbox = value for that shop, without the checkbox = shop value removed (MS-05)
 *   - disabling the module in shop 2 leaves shops 1 and 3 alone (MS-09)
 *   - an upgrade keeps the values per shop and group (MS-03)
 *   - uninstall leaves nothing behind in any shop, and deleting a shop leaves no rows of it in the
 *     module's tables (MS-08, MS-16)
 */

require_once __DIR__ . '/verbose.php';

/** Marker that every configuration key gets in shop 2 / group 2 / globally (ps-multistore-shop.php). */
const MS_MARKER = '/ms-(gl|s2|g2)-([A-Z0-9_]+)\./';

/**
 * Whether the multistore round runs: "multistore": true in testing.json or --multistore, unless
 * --skip-multistore is given.
 *
 * @param array<string, mixed> $config testing.json
 * @param string[] $flags command-line flags
 */
function multistoreWanted(array $config, array $flags): bool
{
    if (in_array('--skip-multistore', $flags, true)) {
        return false;
    }

    return !empty($config['multistore']) || in_array('--multistore', $flags, true);
}

/**
 * Which back-office form test the module needs: 'form' (a configuration page with configuration keys: the multistore
 * form test), 'no-settings' (a configuration page, but the module has no configuration keys: a page that only
 * explains, nothing to save per shop) or 'none' (no configuration page).
 *
 * @param array<string, mixed> $snapshot ps-probe.php output: 'configurable' (getContent exists), 'configuration'
 *                                       (the module's configuration keys)
 */
function msConfigurationFormTest(array $snapshot): string
{
    if (empty($snapshot['configurable'])) {
        return 'none';
    }

    return empty($snapshot['configuration']) ? 'no-settings' : 'form';
}

/**
 * Runs the multistore round on a running shop. Afterwards the shop is a multistore shop with the
 * current code of the module installed.
 *
 * @param string[] $flags command-line flags (--skip-upgrade skips the upgrade part)
 *
 * @return array<string, array{ok: bool, output: string}>
 */
function multistoreRound(Shop $shop, array $flags = []): array
{
    global $config, $moduleDir;
    $name = $config['module'];
    $results = [];
    out("\n-- Multistore round (PrestaShop {$shop->version}) --");
    $roundStart = microtime(true);

    $shop->console('uninstall');
    $setup = msShopScript($shop, 'setup');
    $results['multistore: setup (3 shops, 2 groups)'] = msResult($setup);
    if (!$setup['ok']) {
        return $results;
    }
    $results['multistore: front office of every shop'] = msFrontOfficeOfShops($shop);

    // 1. Install from the CLI: the context is the default shop (the case that used to go wrong).
    $shop->deploy($moduleDir);
    $results['multistore: install from bin/console'] = $shop->console('install');
    $snapshot = $shop->snapshot();
    $allShops = msShopIds($setup);
    ps_vlog('multistore: shops ' . json_encode($allShops) . ', ids ' . json_encode($setup['ids'] ?? []));
    $results['multistore: install covers every shop'] = msInstalledEverywhere($snapshot, $allShops);
    // The integration suite again, now with multistore active (tests that skip without it run here).
    if (!empty($config['suites']['integration']) && !in_array('--skip-integration', $flags, true)) {
        $results['multistore: integration'] = $shop->integration();
    } else {
        ps_vlog('multistore: integration suite skipped (' . (empty($config['suites']['integration']) ? 'not in testing.json' : '--skip-integration') . ')');
    }

    // 2. Back office: the configuration page per shop context (before the markers, see below).
    $formTest = msConfigurationFormTest($snapshot);
    if ($formTest === 'form') {
        $results['multistore: configuration page per shop context'] = msBackOfficeForm($shop, (int) ($setup['ids']['shop2'] ?? 2), (int) ($setup['ids']['group2'] ?? 2));
    } elseif ($formTest === 'no-settings') {
        // A configure page without settings (an explanation page, e.g. heavenproductasnew): no form to test.
        ps_vlog('multistore: configuration page without configuration keys, back-office form test skipped');
    } else {
        ps_vlog('multistore: no configuration page (getContent), back-office form test skipped');
    }

    // 3. Values per shop and group, in PHP and in the front office.
    $isolation = msShopScript($shop, 'isolation', $name);
    $results['multistore: values per shop and group'] = msResult($isolation);
    msClearCache($shop);
    $results['multistore: front office shows the value of its own shop'] = msFrontOfficeMarkers($shop);

    // 4. Disabled in shop 2 only.
    $results += msDisableInOneShop($shop, $allShops, (int) ($setup['ids']['shop2'] ?? 2));
    msShopScript($shop, 'reset', $name);

    // 5. Upgrade from earlier releases keeps the values per shop and group.
    if (($config['upgrade_from'] ?? 'none') !== 'none' && !in_array('--skip-upgrade', $flags, true)) {
        $results += msUpgradeTests($shop, $allShops);
    } else {
        ps_vlog('multistore: upgrade part skipped (' . (($config['upgrade_from'] ?? 'none') === 'none' ? 'upgrade_from is none' : '--skip-upgrade') . ')');
    }

    // 6. Uninstall leaves nothing in any shop.
    $results['multistore: uninstall'] = $shop->console('uninstall');
    $results['multistore: uninstall leaves nothing in any shop'] = msNothingLeft($shop->snapshot());

    // 7. A shop created after the install, then deleted: no rows of it left in the module's tables.
    $shop->console('install');
    $results['multistore: new shop, then deleted'] = msResult(msShopScript($shop, 'delete-shop', $name));
    $results['multistore: uninstall after deleting a shop'] = $shop->console('uninstall');
    $results['multistore: nothing left after deleting a shop'] = msNothingLeft($shop->snapshot());

    // Leave the current version installed (as the normal round does).
    $shop->console('install');
    ps_vdone('multistore round', $roundStart);

    return $results;
}

/**
 * Copies ps-multistore-shop.php into the container and runs one of its commands.
 *
 * @return array<string, mixed> the JSON answer of the script (ok, problems, ...)
 */
function msShopScript(Shop $shop, string $command, string $arguments = ''): array
{
    ps_vlog("multistore: ps-multistore-shop.php $command" . ($arguments !== '' ? ' ' . $arguments : ''));
    $shop->upload(__DIR__ . '/ps-multistore-shop.php', '/tmp/ps-multistore-shop.php');
    $result = $shop->exec('php -d memory_limit=1G /tmp/ps-multistore-shop.php ' . $command . ($arguments !== '' ? ' ' . $arguments : '') . ' 2>&1');
    $lines = explode("\n", $result['output']);
    // Verbose lines of the script (STDERR, merged by the 2>&1 above): show them and keep them out of the answer.
    $verbose = preg_grep('/^\[v [\d:]+\] /', $lines) ?: [];
    if ($verbose !== []) {
        foreach ($verbose as $line) {
            ps_vlog('  container: ' . (string) preg_replace('/^\[v [\d:]+\] /', '', $line));
        }
        $lines = array_values(array_diff_key($lines, $verbose));
        $result['output'] = trim(implode("\n", $lines));
    }
    $data = json_decode((string) end($lines), true);
    if (!is_array($data)) {
        ps_vlog("multistore: ps-multistore-shop.php $command gave no JSON answer (exit {$result['code']})");

        return ['ok' => false, 'problems' => ["ps-multistore-shop.php $command failed:\n" . lastLines($result['output'], 20)]];
    }
    // Lines before the answer (PHP notices of the shop) are not shown otherwise.
    $extra = trim(implode("\n", array_slice($lines, 0, -1)));
    ps_vlog("multistore: $command answered " . json_encode($data, JSON_UNESCAPED_SLASHES) . ($extra !== '' ? "\n    other output:\n" . vIndent($extra) : ''));

    return $data;
}

/**
 * Turns an answer of ps-multistore-shop.php into a step result.
 *
 * @param array<string, mixed> $data
 *
 * @return array{ok: bool, output: string}
 */
function msResult(array $data): array
{
    $output = implode("\n", (array) ($data['problems'] ?? []));
    if (!empty($data['configuration_left'])) {
        $output = trim($output . "\nnote: configuration rows of the deleted shop remain (harmless, uninstall removes them): " . implode(', ', $data['configuration_left']));
    }

    return ['ok' => (bool) ($data['ok'] ?? false), 'output' => $output];
}

/**
 * The shop ids from the answer of "setup".
 *
 * @param array<string, mixed> $setup
 *
 * @return int[]
 */
function msShopIds(array $setup): array
{
    $ids = array_map('intval', array_column((array) ($setup['shops'] ?? []), 'id_shop'));
    sort($ids);

    return $ids;
}

/**
 * Empties the Symfony and Smarty caches of the shop, so the next page shows new configuration values.
 */
function msClearCache(Shop $shop): void
{
    ps_vlog('multistore: emptying the Symfony caches (var/cache/dev, var/cache/prod)');
    $shop->exec('rm -rf /var/www/html/var/cache/dev/* /var/www/html/var/cache/prod/*', 'root');
}

/**
 * Enables or disables the module in one shop only, like Module Manager with that shop selected.
 * Not via "bin/console prestashop:module ... --id_shop": PrestaShop 9 (Symfony 6.4) rejects that
 * option ("The "--id_shop" option does not exist"), PrestaShop 8 accepts it.
 *
 * @return array{ok: bool, output: string}
 */
function msToggleInShop(Shop $shop, string $action, int $idShop): array
{
    global $config;
    $result = msResult(msShopScript($shop, $action, $config['module'] . ' ' . $idShop));
    msClearCache($shop);

    return $result;
}

/**
 * Home and a category page of every shop answer 200, show products with prices and no PHP errors.
 *
 * @return array{ok: bool, output: string}
 */
function msFrontOfficeOfShops(Shop $shop): array
{
    $problems = [];
    foreach (['/', '/shop2/', '/shop3/'] as $base) {
        foreach (['', '3-clothes'] as $page) {
            $path = $base . $page;
            [$status, $body] = httpGet("http://localhost:{$shop->port}$path");
            if ($status !== 200) {
                $problems[] = "$path returned HTTP $status";
                continue;
            }
            $problems = array_merge($problems, msPhpErrors($path, $body));
            if ($page !== '' && !preg_match('/class="[^"]*\bprice\b[^"]*"[^>]*>\s*[^<\s]/', $body)) {
                $problems[] = "$path shows no product prices (missing group_shop or product_shop rows?)";
            }
        }
    }

    return ['ok' => $problems === [], 'output' => implode("\n", $problems)];
}

/**
 * PHP errors in a page.
 *
 * @return string[]
 */
function msPhpErrors(string $path, string $body): array
{
    $problems = [];
    foreach (['Fatal error', 'Parse error', 'Warning: ', 'Notice: ', 'Deprecated: ', 'Uncaught '] as $needle) {
        if (str_contains($body, $needle)) {
            $problems[] = "$path contains '" . trim($needle) . "'";
        }
    }

    return $problems;
}

/**
 * After an install in the default shop: module and hooks enabled in every shop, configuration only global.
 *
 * @param array<string, mixed> $snapshot
 * @param int[] $allShops
 *
 * @return array{ok: bool, output: string}
 */
function msInstalledEverywhere(array $snapshot, array $allShops): array
{
    $problems = [];
    if (($snapshot['module_shops'] ?? []) !== $allShops) {
        $problems[] = 'module enabled in shops ' . json_encode($snapshot['module_shops'] ?? []) . ', expected ' . json_encode($allShops)
            . ' (MS-02: install in the "all shops" context, module-kit ShopScope::runInAllShops())';
    }
    foreach ((array) ($snapshot['hook_shops'] ?? []) as $hook => $shops) {
        if ($shops !== $allShops) {
            $problems[] = "hook $hook registered for shops " . json_encode($shops) . ', expected ' . json_encode($allShops);
        }
    }
    foreach ((array) ($snapshot['configuration_scopes'] ?? []) as $key => $scopes) {
        if ($scopes !== ['global']) {
            $problems[] = "configuration $key has rows " . json_encode($scopes) . ', expected only a global value (MS-03: Configuration::updateGlobalValue())';
        }
    }

    return ['ok' => $problems === [], 'output' => implode("\n", $problems)];
}

/**
 * Front office: shop 1 shows only global values, shop 2 only its own and shop 3 only those of its group.
 * Only keys that the module shows somewhere are checked (the markers come from "isolation").
 *
 * @return array{ok: bool, output: string}
 */
function msFrontOfficeMarkers(Shop $shop): array
{
    $problems = [];
    $expected = ['/' => 'gl', '/shop2/' => 's2', '/shop3/' => 'g2'];
    $seen = [];
    foreach (array_keys($expected) as $base) {
        foreach (['', 'index.php?controller=cart'] as $page) {
            [$status, $body] = httpGet("http://localhost:{$shop->port}$base$page");
            if ($status !== 200) {
                $problems[] = "$base$page returned HTTP $status";
            }
            $problems = array_merge($problems, msPhpErrors($base . $page, $body));
            preg_match_all(MS_MARKER, $body, $m, PREG_SET_ORDER);
            foreach ($m as [, $scope, $key]) {
                $seen[$base][$key][$scope] = true;
            }
        }
    }
    foreach ($seen as $base => $keys) {
        foreach ($keys as $key => $scopes) {
            ps_vlog("multistore: $base shows the marker(s) " . implode(', ', array_keys($scopes)) . " of $key (expected {$expected[$base]})");
        }
    }
    foreach ($seen as $base => $keys) {
        foreach ($keys as $key => $scopes) {
            foreach (array_keys($scopes) as $scope) {
                if ($scope !== $expected[$base]) {
                    $problems[] = "$base shows the value of " . ['gl' => 'all shops', 's2' => 'shop 2', 'g2' => 'group 2'][$scope] . " for $key (MS-04: read the value of the current shop)";
                }
            }
        }
    }
    // A key shown in shop 1 must also be shown (with its own value) in shops 2 and 3.
    foreach (array_keys($seen['/'] ?? []) as $key) {
        foreach (['/shop2/', '/shop3/'] as $base) {
            if (empty($seen[$base][$key][$expected[$base]])) {
                $problems[] = "$base does not show its own value of $key, shop 1 does";
            }
        }
    }
    if ($problems === [] && $seen === []) {
        return ['ok' => true, 'output' => 'no configuration value is shown on home or cart (nothing to compare)'];
    }

    return ['ok' => $problems === [], 'output' => implode("\n", $problems)];
}

/**
 * Disables the module in shop 2 only: shops 1 and 3 keep it, the front
 * office of shop 2 no longer shows it and its front controllers redirect there. Then enables it again.
 *
 * @param int[] $allShops
 * @param int $shop2 id of "Shop 2"
 *
 * @return array<string, array{ok: bool, output: string}>
 */
function msDisableInOneShop(Shop $shop, array $allShops, int $shop2): array
{
    global $moduleDir, $config;
    $results = [];
    $results['multistore: disable in shop 2 only'] = msToggleInShop($shop, 'disable', $shop2);
    $snapshot = $shop->snapshot();
    $without2 = array_values(array_diff($allShops, [$shop2]));
    ps_vlog("multistore: disabled in shop $shop2; enabled in shops " . json_encode($snapshot['module_shops'] ?? []) . ', expected ' . json_encode($without2));
    $problems = [];
    if (($snapshot['module_shops'] ?? []) !== $without2) {
        $problems[] = 'module enabled in shops ' . json_encode($snapshot['module_shops'] ?? []) . ', expected ' . json_encode($without2);
    }
    [, $body2] = httpGet("http://localhost:{$shop->port}/shop2/");
    if (preg_match(MS_MARKER, $body2)) {
        $problems[] = '/shop2/ still shows output of the module while it is disabled there';
    }
    [, $body1] = httpGet("http://localhost:{$shop->port}/");
    [, $body3] = httpGet("http://localhost:{$shop->port}/shop3/");
    $markers = static fn (string $body): bool => preg_match(MS_MARKER, $body) === 1;
    if ($markers($body1) !== $markers($body3)) {
        $problems[] = 'shop 1 and shop 3 differ in showing the module output after disabling it in shop 2';
    }
    foreach (glob($moduleDir . '/controllers/front/*.php') ?: [] as $file) {
        $controller = basename($file, '.php');
        if ($controller === 'index') {
            continue;
        }
        $url = "http://localhost:{$shop->port}/shop2/index.php?fc=module&module={$config['module']}&controller=$controller";
        ps_vlog("multistore: front controller $controller in shop 2 must redirect (301/302/404)");
        [$status] = httpGet($url);
        if (!in_array($status, [301, 302, 404], true)) {
            $problems[] = "front controller $controller answers HTTP $status in shop 2 while the module is disabled there (expected a redirect)";
        }
    }
    $results['multistore: shops 1 and 3 keep the module'] = ['ok' => $problems === [], 'output' => implode("\n", $problems)];
    $results['multistore: enable in shop 2 again'] = msToggleInShop($shop, 'enable', $shop2);
    $after = $shop->snapshot();
    $results['multistore: enabled in every shop again'] = check(($after['module_shops'] ?? []) === $allShops, 'module enabled in shops ' . json_encode($after['module_shops'] ?? []));

    return $results;
}

/**
 * Configuration page of the module in the back office, per shop context (MS-05):
 *   - all shops: no multistore checkboxes; saving writes the global value
 *   - shop 2 (setShopContext=s-<id>) and group 2 (g-<id>): a checkbox per field; ticked + saved =
 *     own value for that shop/group only, untick + saved = own value removed again (inherits)
 * The first text field of the form is used; the expected rows are looked up by value. A field that
 * holds a number (an amount, a limit: Symfony NumberType/MoneyType render as type="text") gets numeric
 * markers, compared as numbers, so a field that only accepts numbers is tested too.
 *
 * @param int $shop2 id of "Shop 2"
 * @param int $group2 id of "Group 2"
 *
 * @return array{ok: bool, output: string}
 */
function msBackOfficeForm(Shop $shop, int $shop2, int $group2): array
{
    $session = msBackOfficeLogin($shop);
    if (is_string($session)) {
        return ['ok' => false, 'output' => $session];
    }
    [$jar, $configureUrl] = $session;
    $problems = [];

    // All shops.
    $page = msFormPage($jar, $configureUrl, '');
    if (is_string($page)) {
        @unlink($jar);

        return ['ok' => false, 'output' => $page];
    }
    if (preg_grep('/\[multistore_/', array_keys($page['checkboxes']))) {
        $problems[] = 'all shops context: the form shows multistore checkboxes';
    }
    $textField = msFirstTextField($page);
    if ($textField === null) {
        @unlink($jar);

        return ['ok' => true, 'output' => 'no text field in the configuration form (nothing to test)'];
    }
    $numeric = msIsNumber((string) ($page['fields'][$textField] ?? ''));
    $allMarker = msFormMarker('all', $numeric);
    ps_vlog("multistore form: testing field $textField (" . ($numeric ? 'numeric' : 'text') . "), all shops value $allMarker");
    $fields = $page['fields'];
    $fields[$textField] = $allMarker;
    $save = msSubmit($jar, $page, $fields);
    $rows = msRowsWithValue($shop, $allMarker);
    if ($save !== null) {
        $problems[] = "all shops context: saving failed: $save";
    } elseif (count($rows) !== 1 || $rows[0]['scope'] !== 'global') {
        $problems[] = 'all shops context: saving wrote ' . json_encode($rows) . ', expected one global value';
    }
    $key = $rows[0]['name'] ?? null;
    $checkbox = (string) preg_replace('/\[([^\[\]]+)\]$/', '[multistore_$1]', $textField);
    ps_vlog('multistore form: all shops saved ' . json_encode($rows) . ($save !== null ? " (error: $save)" : '') . '; configuration key ' . ($key ?? '(none)') . ", checkbox $checkbox");

    // Shop 2 and group 2: tick = own value, untick = inherit again.
    foreach (['shop 2' => ['s-' . $shop2, 's' . $shop2], 'group 2' => ['g-' . $group2, 'g' . $group2]] as $label => [$context, $scope]) {
        if ($key === null) {
            break;
        }
        $page = msFormPage($jar, $configureUrl, $context);
        if (is_string($page)) {
            $problems[] = $page;
            break;
        }
        if (!array_key_exists($checkbox, $page['checkboxes'])) {
            $problems[] = "$label context: no multistore checkbox $checkbox next to $textField (MS-05: getParent() = MultistoreConfigurationType, 'multistore_configuration_key' per field)";
            break;
        }
        $marker = msFormMarker($scope[0] === 's' ? 'shop' : 'group', $numeric, $scope);
        ps_vlog("multistore form: $label context ($context), checkbox ticked, value $marker");
        $fields = $page['fields'];
        $fields[$checkbox] = $page['checkboxes'][$checkbox];
        $fields[$textField] = $marker;
        $save = msSubmit($jar, $page, $fields);
        $rows = msRowsWithValue($shop, $marker);
        if ($save !== null) {
            $problems[] = "$label with checkbox: saving failed: $save";
            continue;
        }
        if (count($rows) !== 1 || $rows[0]['scope'] !== $scope || $rows[0]['name'] !== $key) {
            $problems[] = "$label with checkbox: saving wrote " . json_encode($rows) . ", expected one row of $key for $label";
        }
        if (msRowsWithValue($shop, $allMarker) === []) {
            $problems[] = "$label with checkbox: the global value was changed";
        }

        // The page now shows the own value, with the checkbox ticked and the field enabled.
        $page = msFormPage($jar, $configureUrl, $context);
        if (is_string($page)) {
            $problems[] = $page;
            continue;
        }
        if (!isset($page['fields'][$checkbox]) || !msSameValue((string) ($page['fields'][$textField] ?? ''), $marker, $numeric)) {
            $problems[] = "$label: after saving, the page does not show the own value with a ticked checkbox";
        }
        // Untick: the browser no longer sends the checkbox, and the field is disabled again (MultistoreConfigField).
        $fields = $page['fields'];
        unset($fields[$checkbox], $fields[$textField]);
        ps_vlog("multistore form: $label context, checkbox unticked");
        $save = msSubmit($jar, $page, $fields);
        if ($save !== null) {
            $problems[] = "$label without checkbox: saving failed: $save";
        } elseif (msRowsWithValue($shop, $marker) !== []) {
            $problems[] = "$label without checkbox: the own value is still there (expected deleteFromContext(), MS-05)";
        }
    }
    @unlink($jar);

    return ['ok' => $problems === [], 'output' => implode("\n", $problems)];
}

/**
 * Logs in to the back office and finds the URL of the module's configuration page.
 *
 * @return array{0: string, 1: string}|string [cookie jar, configuration page URL] or an error message
 */
function msBackOfficeLogin(Shop $shop): array|string
{
    global $config;
    $admin = "http://localhost:{$shop->port}/admin-dev/index.php";
    $jar = (string) tempnam(sys_get_temp_dir(), 'psms');
    ps_vlog('multistore form: logging in to the back office as demo@prestashop.com');
    [, $loginPage, $loginUrl] = curlRequest($admin . '?controller=AdminLogin', $jar);
    $fields = hiddenInputs($loginPage);
    $fields['email'] = 'demo@prestashop.com';
    $fields['passwd'] = 'prestashop_demo';
    $fields['submitLogin'] = '1';
    [$code, $dashboard, $dashboardUrl] = curlRequest($loginUrl, $jar, $fields);
    if (!preg_match('/_token=([A-Za-z0-9_\-.]+)/', $dashboard . $dashboardUrl, $m)) {
        @unlink($jar);

        return "Back-office login failed (HTTP $code, landed on $dashboardUrl).";
    }
    // Modules > Configure redirects (getContent()) to the module's own page.
    [$status, , $url] = curlRequest($admin . '/improve/modules/manage/action/configure/' . $config['module'] . '?_token=' . $m[1], $jar);
    if ($status !== 200) {
        @unlink($jar);

        return "Configuration page: HTTP $status ($url)";
    }

    return [$jar, $url];
}

/**
 * Opens the configuration page in a shop context ('' = all shops, 's-2' = shop 2) and reads its form.
 *
 * @return array{action: string, fields: array<string, string>, checkboxes: array<string, string>, text: string[]}|string
 */
function msFormPage(string $jar, string $url, string $context): array|string
{
    // setShopContext switches the context (stored in the employee cookie); the second request
    // then renders the page in that context.
    $switch = preg_replace('/([?&])setShopContext=[^&]*&?/', '$1', $url);
    $switch .= (str_contains((string) $switch, '?') ? '&' : '?') . 'setShopContext=' . $context;
    ps_vlog('multistore form: shop context ' . ($context === '' ? 'all shops' : $context));
    curlRequest((string) $switch, $jar);
    [$status, $html, $final] = curlRequest($url, $jar);
    if ($status !== 200) {
        return "configuration page ($context): HTTP $status ($final)";
    }
    // The settings form is the Symfony form with a CSRF field "<name>[_token]" (not the header search).
    preg_match_all('#<form\b([^>]*)>(.*?)</form>#is', $html, $forms, PREG_SET_ORDER);
    $form = null;
    foreach ($forms as $candidate) {
        if (preg_match('/name="[^"]+\[_token\]"/', $candidate[2])) {
            $form = $candidate;
            break;
        }
    }
    if ($form === null) {
        return 'configuration page (' . ($context === '' ? 'all shops' : $context) . '): no Symfony form found';
    }
    preg_match('/action="([^"]*)"/', $form[1], $action);
    $target = html_entity_decode($action[1] ?? $final);
    if (str_starts_with($target, '/')) {
        $target = (string) preg_replace('#^(https?://[^/]+).*$#', '$1', $final) . $target;
    }
    $page = ['action' => $target, 'fields' => [], 'checkboxes' => [], 'text' => []];
    preg_match_all('/<(input|textarea|select)\b([^>]*)>(.*?(?=<\/textarea>|<\/select>))?/is', $form[2], $tags, PREG_SET_ORDER);
    foreach ($tags as $tag) {
        $attrs = $tag[2];
        if (!preg_match('/\bname="([^"]+)"/', $attrs, $n)) {
            continue;
        }
        $fieldName = html_entity_decode($n[1]);
        preg_match('/\btype="([^"]+)"/', $attrs, $t);
        preg_match('/\bvalue="([^"]*)"/', $attrs, $v);
        $type = strtolower($t[1] ?? ($tag[1] === 'input' ? 'text' : $tag[1]));
        $value = html_entity_decode($v[1] ?? '');
        $disabled = preg_match('/\bdisabled\b/', $attrs) === 1;
        if ($type === 'checkbox') {
            $page['checkboxes'][$fieldName] = $value !== '' ? $value : '1';
            if (!preg_match('/\bchecked\b/', $attrs) || $disabled) {
                continue;
            }
        } elseif ($type === 'radio' && !preg_match('/\bchecked\b/', $attrs)) {
            continue;
        } elseif ($tag[1] === 'textarea') {
            $value = html_entity_decode(trim((string) ($tag[3] ?? '')));
        } elseif ($tag[1] === 'select') {
            preg_match('/<option[^>]*\bselected\b[^>]*value="([^"]*)"|<option[^>]*value="([^"]*)"[^>]*\bselected\b/i', (string) ($tag[3] ?? ''), $o);
            $value = html_entity_decode(($o[1] ?? '') !== '' ? $o[1] : ($o[2] ?? ''));
        }
        if ($type === 'text') {
            $page['text'][] = $fieldName; // also when disabled: in a shop context the checkbox enables it
        }
        if ($disabled) {
            continue; // browsers do not send disabled fields
        }
        $page['fields'][$fieldName] = $value;
    }

    return $page;
}

/**
 * Whether a form value is a plain number ("50", "49.90", "49,90"): then the field gets numeric markers.
 */
function msIsNumber(string $value): bool
{
    return preg_match('/^\s*-?\d+(?:[.,]\d+)?\s*$/', $value) === 1;
}

/**
 * The value the back-office test writes in a context: a text marker ("ms-bo-all.", "ms-bo-s2."), or
 * for a numeric field a number that is unlikely to be a default (71 = all shops, 72 = shop, 73 = group).
 *
 * @param string $context 'all', 'shop' or 'group'
 * @param string $scope scope label of the shop or group ('s2', 'g2') for the text marker
 */
function msFormMarker(string $context, bool $numeric, string $scope = 'all'): string
{
    if ($numeric) {
        return ['all' => '71', 'shop' => '72', 'group' => '73'][$context];
    }

    return 'ms-bo-' . $scope . '.';
}

/**
 * Whether the form shows the marker again: equal text, or for a numeric field the same number
 * (the form may show 72 as "72.00" or "72,00").
 */
function msSameValue(string $shown, string $marker, bool $numeric): bool
{
    if (!$numeric) {
        return $shown === $marker;
    }

    return msIsNumber($shown) && abs((float) str_replace(',', '.', trim($shown)) - (float) $marker) < 0.000001;
}

/**
 * The first text input of the form (the field used for the test), or null.
 *
 * @param array{text: string[]} $page
 */
function msFirstTextField(array $page): ?string
{
    return $page['text'][0] ?? null;
}

/**
 * Submits the form like a browser (POST; the hidden _method=PATCH is part of the fields). Returns
 * null on success or the error shown on the page.
 *
 * @param array{action: string} $page
 * @param array<string, string> $fields
 */
function msSubmit(string $jar, array $page, array $fields): ?string
{
    ps_vlog('multistore form: submitting ' . count($fields) . ' field(s) to ' . $page['action']);
    [$status, $html, $final] = curlRequest($page['action'], $jar, $fields);
    if ($status !== 200) {
        return "HTTP $status ($final)";
    }
    if (preg_match('/<div[^>]*class="[^"]*alert-danger[^"]*"[^>]*>(.*?)<\/div>/is', $html, $m)) {
        return trim((string) preg_replace('/\s+/', ' ', strip_tags($m[1])));
    }
    if (preg_match('/class="[^"]*invalid-feedback[^"]*"[^>]*>(.*?)<\//is', $html, $m) && trim(strip_tags($m[1])) !== '') {
        return 'field error: ' . trim(strip_tags($m[1]));
    }
    // Field errors of the PrestaShop UI kit form theme (form_errors_field): <div class="... text-danger ..." role="alert">.
    if (preg_match('/<div[^>]*class="[^"]*\btext-danger\b[^"]*"[^>]*role="alert"[^>]*>(.*?)<\/div>/is', $html, $m)) {
        $error = trim((string) preg_replace('/\s+/', ' ', strip_tags((string) preg_replace('/<i\b[^>]*>.*?<\/i>/is', '', $m[1]))));
        if ($error !== '') {
            return 'field error: ' . $error;
        }
    }

    return null;
}

/**
 * Configuration rows of the module with a given value, with their scope ('global', 'g<id>', 's<id>').
 *
 * @return array<int, array{name: string, scope: string}>
 */
function msRowsWithValue(Shop $shop, string $value): array
{
    global $config;

    return (array) (msShopScript($shop, 'find-value', $config['module'] . ' ' . $value)['rows'] ?? []);
}

/**
 * Upgrade from each earlier release in the multistore shop: values per shop and group stay, new
 * hooks reach every shop and new configuration keys are only global.
 *
 * @param int[] $allShops
 *
 * @return array<string, array{ok: bool, output: string}>
 */
function msUpgradeTests(Shop $shop, array $allShops): array
{
    global $config, $moduleDir;
    $name = $config['module'];
    $current = currentVersion($moduleDir);
    $tags = releaseTags($current);
    if (($config['upgrade_from'] ?? 'all') === 'previous') {
        $tags = array_slice($tags, -1, 1, true);
    }
    ps_vlog("multistore: upgrade tests to $current from " . ($tags === [] ? 'nothing (no earlier release tags)' : implode(', ', array_keys($tags))));
    if ($tags === []) {
        return ['multistore: upgrade' => ['ok' => true, 'output' => 'skipped: no earlier release tags']];
    }
    $results = [];
    foreach ($tags as $tag => $version) {
        $label = "multistore: upgrade $version -> $current keeps shop values";
        ps_vlog("$label: install $tag, write values per shop and group, upgrade, check they are kept");
        $shop->console('uninstall');
        $old = exportRelease($tag);
        if ($old === null) {
            $results[$label] = ['ok' => false, 'output' => "Could not export $tag"];
            continue;
        }
        $shop->deploy($old);
        removeDir(dirname($old));
        $install = $shop->console('install');
        if (!$install['ok']) {
            $results[$label] = ['ok' => false, 'output' => "Installing $version failed:\n" . $install['output']];
            continue;
        }
        $before = $shop->snapshot();
        msShopScript($shop, 'isolation', $name);
        $shop->deploy($moduleDir);
        $upgrade = $shop->console('upgrade');
        if (!$upgrade['ok']) {
            $results[$label] = ['ok' => false, 'output' => $upgrade['output']];
            continue;
        }
        $kept = msShopScript($shop, 'keep-check', $name);
        $after = $shop->snapshot();
        $problems = (array) ($kept['problems'] ?? []);
        if (!($kept['ok'] ?? false) && $problems === []) {
            $problems[] = 'keep-check failed';
        }
        if (($after['module_shops'] ?? []) !== $allShops) {
            $problems[] = 'after the upgrade the module is enabled in shops ' . json_encode($after['module_shops'] ?? []);
        }
        foreach ((array) ($after['hook_shops'] ?? []) as $hook => $shops) {
            if ($shops !== $allShops) {
                $problems[] = "after the upgrade hook $hook is registered for shops " . json_encode($shops);
            }
        }
        foreach ((array) ($after['configuration_scopes'] ?? []) as $key => $scopes) {
            if (!isset($before['configuration_scopes'][$key]) && $scopes !== ['global']) {
                $problems[] = "new configuration $key has rows " . json_encode($scopes) . ', expected only a global value';
            }
        }
        $results[$label] = ['ok' => $problems === [], 'output' => implode("\n", $problems)];
        msShopScript($shop, 'reset', $name);
        $shop->console('uninstall');
    }
    $shop->deploy($moduleDir);
    $shop->console('install');

    return $results;
}

/**
 * After uninstall: the normal clean check plus no module_shop/hook_module rows and no
 * configuration rows in any shop or group.
 *
 * @param array<string, mixed> $snapshot
 *
 * @return array{ok: bool, output: string}
 */
function msNothingLeft(array $snapshot): array
{
    $result = cleanCheck($snapshot);
    $left = $result['output'] !== '' ? [$result['output']] : [];
    if (!empty($snapshot['module_shops'])) {
        $left[] = 'module_shop rows for shops ' . json_encode($snapshot['module_shops']);
    }
    if (!empty($snapshot['hook_shops'])) {
        $left[] = 'hook_module rows: ' . json_encode($snapshot['hook_shops']);
    }
    if (!empty($snapshot['configuration_scopes'])) {
        $left[] = 'configuration rows: ' . json_encode($snapshot['configuration_scopes']);
    }

    return ['ok' => $left === [], 'output' => implode("\n", $left)];
}
