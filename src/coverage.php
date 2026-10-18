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
 * Coverage gate of a module (conventions TST-08): the unit tests execute every line of src/Domain,
 * src/Application and src/Presenter, so every method, branch and fault path (throw) runs in a test.
 *
 * Usage: php coverage.php <module-dir>
 * Needs a coverage driver: PCOV (preferred, fast) or Xdebug. Runs vendor/bin/phpunit --testsuite unit with
 * a Clover report in .cache/coverage/ and checks every statement line of those folders.
 * Exit code: 0 = complete, 1 = lines not executed or tests failed, 2 = no driver / usage error.
 */

declare(strict_types=1);

const COVERAGE_REQUIRED = ['src/Domain/', 'src/Application/', 'src/Presenter/'];

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__)) {
    exit(coverageMain($argv[1] ?? ''));
}

/**
 * Runs the unit suite with coverage and reports every statement of the required folders that did not run.
 */
function coverageMain(string $moduleDir): int
{
    $moduleDir = rtrim(str_replace('\\', '/', (string) realpath($moduleDir)), '/');
    if ($moduleDir === '' || !is_file("$moduleDir/vendor/bin/phpunit")) {
        fwrite(STDERR, "Usage: php coverage.php <module-dir> (with vendor/bin/phpunit installed)\n");

        return 2;
    }
    if (!is_dir("$moduleDir/tests/Unit")) {
        echo "Coverage: no tests/Unit (TST-04: every module has unit tests).\n";

        return 1;
    }
    $driver = coverageDriver();
    if ($driver === '') {
        fwrite(STDERR, "Coverage: no coverage driver in this PHP (" . PHP_BINARY . "). Install PCOV (pecl install pcov, or the\n"
            . "php_pcov DLL on Windows) or enable Xdebug; in CI: shivammathur/setup-php with coverage: pcov.\n"
            . "Only when that is impossible: vendor/bin/ps-module skip check \"<reason>\" (the user sees it).\n");

        return 2;
    }
    @mkdir("$moduleDir/.cache/coverage", 0775, true);
    $clover = "$moduleDir/.cache/coverage/clover.xml";
    @unlink($clover);
    $ini = $driver === 'pcov' ? ' -d pcov.enabled=1 -d pcov.directory=' . escapeshellarg("$moduleDir/src") : '';
    if ($driver === 'xdebug') {
        putenv('XDEBUG_MODE=coverage');
    }
    $cmd = escapeshellarg(PHP_BINARY) . ' -d memory_limit=2G' . $ini . ' ' . escapeshellarg("$moduleDir/vendor/bin/phpunit")
        . ' --testsuite unit --colors=never --coverage-filter ' . escapeshellarg("$moduleDir/src")
        . ' --coverage-clover ' . escapeshellarg($clover);
    $cwd = getcwd();
    chdir($moduleDir);
    exec($cmd . ' 2>&1', $output, $code);
    chdir((string) $cwd);
    if ($code !== 0 || !is_file($clover)) {
        echo implode("\n", array_slice($output, -40)) . "\n";
        echo "Coverage: the unit tests failed (or wrote no report); fix them first.\n";

        return 1;
    }
    $missing = coverageMissing($moduleDir, (string) file_get_contents($clover));
    if ($missing === []) {
        echo "Coverage OK ($driver): every line of " . implode(', ', COVERAGE_REQUIRED) . " runs in the unit tests.\n";

        return 0;
    }
    echo 'Coverage found ' . count($missing) . " line(s) the unit tests never execute (TST-08):\n  " . implode("\n  ", $missing) . "\n"
        . "Write the missing tests: the normal case, every branch and every fault (expectException with the faulty input).\n";

    return 1;
}

/**
 * The available coverage driver: pcov, xdebug or ''.
 */
function coverageDriver(): string
{
    exec(escapeshellarg(PHP_BINARY) . ' -m', $modules);
    $modules = array_map('strtolower', array_map('trim', $modules));

    return in_array('pcov', $modules, true) ? 'pcov' : (in_array('xdebug', $modules, true) ? 'xdebug' : '');
}

/**
 * Statements of the required folders with count 0 in the Clover report, as "path:line in method()".
 *
 * @return string[]
 */
function coverageMissing(string $moduleDir, string $xml): array
{
    $doc = @simplexml_load_string($xml);
    if ($doc === false) {
        return ['.cache/coverage/clover.xml: unreadable report'];
    }
    $missing = [];
    foreach ($doc->xpath('//file') ?: [] as $file) {
        $path = str_replace('\\', '/', (string) $file['name']);
        $relative = str_starts_with($path, $moduleDir . '/') ? substr($path, strlen($moduleDir) + 1) : $path;
        $required = false;
        foreach (COVERAGE_REQUIRED as $prefix) {
            $required = $required || str_starts_with($relative, $prefix);
        }
        if (!$required || basename($relative) === 'index.php') {
            continue;
        }
        // The exit; of the _PS_VERSION_ guard (PHP-02) never runs when PrestaShop or the test bootstrap loads the file.
        $source = is_file("$moduleDir/$relative") ? (array) file("$moduleDir/$relative") : [];
        // Its "if" line is not counted either: a class first loaded in a data provider (before coverage starts)
        // shows it as never run; whether the class is tested at all is TST-04.
        $guard = static function (int $num) use ($source): bool {
            $line = trim((string) ($source[$num - 1] ?? ''));

            return ($line === 'exit;' && str_contains((string) ($source[$num - 2] ?? ''), '_PS_VERSION_'))
                || str_starts_with($line, "if (!defined('_PS_VERSION_'))");
        };
        $method = '';
        foreach ($file->line as $line) {
            if ((string) $line['type'] === 'method') {
                $method = (string) $line['name'];
                if ((int) $line['count'] === 0) {
                    $missing[] = "$relative:{$line['num']} $method() never runs";
                }
                continue;
            }
            if ((string) $line['type'] === 'stmt' && (int) $line['count'] === 0 && !$guard((int) $line['num'])) {
                $missing[] = "$relative:{$line['num']}" . ($method !== '' ? " in $method()" : '');
            }
        }
    }

    return array_values(array_unique($missing));
}
