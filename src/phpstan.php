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
 * Runs PHPStan once per PrestaShop version in tests/phpstan/config.json, so
 * code that only works on PS 8 or only on PS 9 is caught.
 *
 * PrestaShop root per version, in this order:
 *   1. env _PS_ROOT_DIR_ (then only that one installation is analysed)
 *   2. <cache_dir>/<version>, with cache_dir from env PS_CACHE_DIR or config.json
 *
 * Usage: ps-module phpstan [<version> ...]   (run in the module or library directory)
 * Config: tests/phpstan/config.json {"cache_dir", "versions", optional "paths"}
 *         and tests/phpstan/phpstan.neon.
 */

require_once __DIR__ . '/verbose.php';

$moduleDir = rtrim(str_replace('\\', '/', (string) getcwd()), '/');
$config = json_decode((string) file_get_contents($moduleDir . '/tests/phpstan/config.json'), true);
$phpstan = $moduleDir . '/vendor/bin/phpstan';
$neon = $moduleDir . '/tests/phpstan/phpstan.neon';

$runs = [];
if (getenv('_PS_ROOT_DIR_')) {
    $runs['_PS_ROOT_DIR_'] = getenv('_PS_ROOT_DIR_');
} else {
    $cacheDir = getenv('PS_CACHE_DIR') ?: $config['cache_dir'];
    $versions = array_slice($argv, 1) ?: $config['versions'];
    foreach ($versions as $version) {
        $runs[$version] = rtrim($cacheDir, '/\\') . '/' . $version;
    }
}

$paths = array_filter(
    // tests/Unit and tests/Integration too: the tests are code as well (TST-04..12); Behat has its own vendor/.
    $config['paths'] ?? [basename($moduleDir) . '.php', 'src', 'controllers', 'classes', 'upgrade', 'override', 'tests/Unit', 'tests/Integration'],
    static fn (string $path): bool => file_exists($moduleDir . '/' . $path)
);
ps_vlog('phpstan: ' . (getenv('_PS_ROOT_DIR_') ? 'only _PS_ROOT_DIR_ (env)' : 'PrestaShop ' . implode(', ', array_keys($runs))
    . (array_slice($argv, 1) !== [] ? ' (from the command line)' : ' (from tests/phpstan/config.json)')
    . ', cache dir ' . (getenv('PS_CACHE_DIR') ? getenv('PS_CACHE_DIR') . ' (env PS_CACHE_DIR)' : ($config['cache_dir'] ?? '?') . ' (config.json)')));
ps_vlog('phpstan: paths ' . implode(', ', $paths) . (isset($config['paths']) ? ' (config.json)' : ' (default list, existing ones only)'));

chdir($moduleDir);
$failed = false;
foreach ($runs as $label => $root) {
    if (!is_file($root . '/config/config.inc.php') || !is_file($root . '/vendor/autoload.php')) {
        fwrite(STDERR, "PrestaShop $label not found in $root.\n"
            . "Download it (php .claude/skills/ps-module/scripts/fetch-prestashop.php $label) or set _PS_ROOT_DIR_.\n");
        $failed = true;
        continue;
    }
    echo "\n=== PHPStan against PrestaShop $label ===\n";
    putenv('_PS_ROOT_DIR_=' . $root);
    if (!is_file($phpstan)) {
        fwrite(STDERR, "vendor/bin/phpstan not found; run composer install.\n");
        exit(1);
    }
    // Own result cache per PrestaShop version: with one shared tmpDir the second run reused the results of
    // the first for unchanged files, so the PS 9 run was not independent of the PS 8 run.
    $tmpDir = $moduleDir . '/.cache/phpstan/' . preg_replace('/[^A-Za-z0-9._-]/', '_', (string) $label);
    @mkdir($tmpDir, 0775, true);
    $wrapper = $moduleDir . '/.cache/phpstan/' . preg_replace('/[^A-Za-z0-9._-]/', '_', (string) $label) . '.neon';
    file_put_contents($wrapper, "includes:\n    - " . str_replace('\\', '/', $neon) . "\nparameters:\n    tmpDir: " . str_replace('\\', '/', $tmpDir) . "\n");
    $cmd = escapeshellarg(PHP_BINARY) . ' -d memory_limit=2G ' . escapeshellarg($phpstan)
        . ' analyse --no-progress --configuration=' . escapeshellarg($wrapper)
        . ' ' . implode(' ', array_map('escapeshellarg', $paths));
    ps_vlog("phpstan: PrestaShop $label in $root, result cache $tmpDir, config $wrapper");
    ps_vcmd($cmd, $moduleDir);
    $start = microtime(true);
    passthru($cmd, $code);
    ps_vdone("phpstan (PrestaShop $label)", $start, $code);
    if ($code !== 0) {
        $failed = true;
    }
}

exit($failed ? 1 : 0);
