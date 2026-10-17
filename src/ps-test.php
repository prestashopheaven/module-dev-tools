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
 * Tests the module on real PrestaShop shops in Docker.
 * Settings: testing.json in the repository root.
 *
 *   ps-module shops doctor                 check Docker
 *   ps-module shops run [<version>...]     full matrix (default: all versions in testing.json)
 *        options: --keep (leave the shops running), --skip-upgrade, --skip-behat, --skip-integration
 *   ps-module shops up <version>           start a shop, install the current module, print URLs
 *   ps-module shops deploy <version>       put the current code in a running shop: uninstall, copy, install
 *   ps-module shops integration <version>  run the PHPUnit integration suite in a running shop
 *   ps-module shops behat <version>        run Behat against a running shop
 *   ps-module shops upgrade <version>      upgrade tests only (from the git tags in testing.json)
 *   ps-module shops down [<version>|all]   stop and remove shops
 *
 * Steps of "run" per version:
 *   1. start shop (MySQL 8, dev mode)        5. uninstall: nothing may be left behind
 *   2. install the module + smoke test       6. install again (re-install must work)
 *   3. PHPUnit integration suite             7. install the real release zip (scoped vendor/)
 *   4. Behat scenarios                       8. upgrade from earlier releases (git tags) and
 *                                               compare with a fresh install
 */

require_once __DIR__ . '/dod.php';
require_once __DIR__ . '/composer-paths.php';

$repoRoot = dod_repo_dir((string) getcwd());
if ($repoRoot === null) {
    fwrite(STDERR, "ERROR: run this inside a module repository (no module.spec.json found).\n");
    exit(1);
}
define('ROOT', dod_norm($repoRoot));
const COMPOSE_FILE = __DIR__ . '/../resources/docker/compose.yml';

$config = json_decode((string) file_get_contents(ROOT . '/testing.json'), true);
if (!is_array($config)) {
    fail('testing.json is missing or invalid.');
}
$module = $config['module'];
$moduleDir = ROOT . '/' . $module;
$configPrefix = strtoupper($module);

$argvRest = array_slice($argv, 1);
$flags = array_values(array_filter($argvRest, static fn (string $a): bool => str_starts_with($a, '--')));
$args = array_values(array_filter($argvRest, static fn (string $a): bool => !str_starts_with($a, '--')));
$command = array_shift($args) ?? 'help';

switch ($command) {
    case 'doctor':
        requireDocker();
        out('Docker OK.');
        exit(0);
    case 'run':
        requireDocker();
        $code = runMatrix($args ?: $config['versions'], $flags);
        // Definition-of-done stamp: only for a full run (all versions, nothing skipped) that passed.
        $full = array_diff($config['versions'], $args ?: $config['versions']) === []
            && array_intersect($flags, ['--skip-upgrade', '--skip-behat', '--skip-integration']) === [];
        if ($code === 0 && $full) {
            dod_stamp(ROOT, 'shop-tests', null, 'PrestaShop ' . implode(', ', $args ?: $config['versions']));
        }
        exit($code);
    case 'up':
        requireDocker();
        $version = requireVersion($args);
        $shop = new Shop($version, $config);
        $shop->up();
        $shop->deploy($moduleDir);
        $result = $shop->console('install');
        out($result['ok'] ? 'Module installed.' : "Install FAILED:\n" . $result['output']);
        out("Shop:  http://localhost:{$shop->port}/");
        out("Admin: http://localhost:{$shop->port}/admin-dev/  (demo@prestashop.com / prestashop_demo)");
        exit($result['ok'] ? 0 : 1);
    case 'deploy':
        requireDocker();
        $shop = new Shop(requireVersion($args), $config);
        if (!$shop->isReady()) {
            fail("No running shop for PrestaShop {$shop->version}. Start one with: ps-module shops up {$shop->version}");
        }
        $shop->console('uninstall');
        $shop->deploy($moduleDir);
        $result = $shop->console('install');
        out($result['ok'] ? "Deployed and installed on http://localhost:{$shop->port}/" : "Install FAILED:
" . $result['output']);
        exit($result['ok'] ? 0 : 1);
    case 'integration':
        requireDocker();
        $shop = new Shop(requireVersion($args), $config);
        exit($shop->integration()['ok'] ? 0 : 1);
    case 'behat':
        requireDocker();
        $shop = new Shop(requireVersion($args), $config);
        exit($shop->behat()['ok'] ? 0 : 1);
    case 'upgrade':
        requireDocker();
        $shop = new Shop(requireVersion($args), $config);
        $shop->up();
        $results = upgradeTests($shop, freshSnapshot($shop));
        printResults($results);
        exit(allOk($results) ? 0 : 1);
    case 'down':
        requireDocker();
        $versions = ($args[0] ?? 'all') === 'all' ? $config['versions'] : [$args[0]];
        foreach ($versions as $version) {
            (new Shop($version, $config))->down();
        }
        exit(0);
    default:
        $help = (string) file_get_contents(__FILE__);
        preg_match('#/\*(.*?)\*/#s', substr($help, strpos($help, '/*', 10)), $m);
        out(trim(preg_replace('/^ \* ?/m', '', $m[1] ?? '')));
        exit($command === 'help' ? 0 : 1);
}

// ---------------------------------------------------------------------------

final class Shop
{
    public readonly int $port;
    private readonly string $project;

    /** @param array<string, mixed> $config */
    public function __construct(public readonly string $version, private readonly array $config)
    {
        $parts = array_map('intval', explode('.', $version));
        $this->port = (int) ($config['base_port'] ?? 8000) + $parts[0] * 100 + $parts[1] * 10 + ($parts[2] ?? 0);
        $this->project = $config['module'] . '-ps' . str_replace('.', '', $version);
    }

    public function up(): void
    {
        out("\n== PrestaShop {$this->version} on port {$this->port} ==");
        if ($this->isReady()) {
            out('Shop is already running.');

            return;
        }
        $this->compose('up -d', true);
        out('Waiting for the PrestaShop installation (first run downloads the image; can take several minutes)...');
        $deadline = time() + 1200;
        while (time() < $deadline) {
            if ($this->isReady()) {
                out('Shop is ready.');

                return;
            }
            sleep(5);
        }
        fail("PrestaShop {$this->version} did not become ready. Check: docker compose -p {$this->project} logs prestashop");
    }

    public function down(): void
    {
        $this->compose('down -v --remove-orphans', true);
    }

    public function isReady(): bool
    {
        $status = httpStatus("http://localhost:{$this->port}/");

        return in_array($status, [200, 301, 302], true)
            && $this->exec('test -f /var/www/html/app/config/parameters.php && echo ok')['output'] === 'ok';
    }

    /** Copies a module directory into the shop (replacing what is there). */
    public function deploy(string $sourceDir): void
    {
        $name = $this->config['module'];
        $stage = sys_get_temp_dir() . '/ps-test-' . uniqid() . '/' . $name;
        copyDir($sourceDir, $stage, ['.git', '.cache', 'node_modules', 'dist']);
        $this->exec("rm -rf /var/www/html/modules/$name", 'root');
        $this->compose('cp ' . escapeshellarg($stage) . ' ' . escapeshellarg("prestashop:/var/www/html/modules/$name"));
        $this->exec("chown -R www-data:www-data /var/www/html/modules/$name", 'root');
        removeDir(dirname($stage));
    }

    /** @return array{ok: bool, output: string} */
    public function console(string $action): array
    {
        $name = $this->config['module'];
        $result = $this->exec("cd /var/www/html && php -d memory_limit=1G bin/console prestashop:module $action $name 2>&1");
        // PS 8.1: right after the first install in a fresh shop, the compiled containers can
        // still miss the module's services. Empty the cache; the next request rebuilds it.
        $this->exec('rm -rf /var/www/html/var/cache/dev/* /var/www/html/var/cache/prod/*', 'root');
        // PrestaShop prints "<Action> action on module <name> succeeded." on success. Other
        // output (e.g. PHP warnings from the Docker image in dev mode) is not a failure.
        $ok = $result['code'] === 0 && preg_match('/\bsucceeded\b/i', $result['output']) === 1;

        return ['ok' => $ok, 'output' => $result['output']];
    }

    /** @return array<string, mixed> */
    public function snapshot(): array
    {
        $this->compose('cp ' . escapeshellarg(__DIR__ . '/ps-probe.php') . ' prestashop:/tmp/ps-probe.php');
        $result = $this->exec('php /tmp/ps-probe.php ' . escapeshellarg($this->config['module']) . ' ' . escapeshellarg(strtoupper($this->config['module'])));
        $data = json_decode($result['output'], true);
        if (!is_array($data)) {
            fail("Snapshot failed:\n" . $result['output']);
        }

        return $data;
    }

    /** @return array{ok: bool, output: string} */
    public function smoke(): array
    {
        $problems = [];
        // Home, cart and a category of the demo data. Use the friendly URL: in dev mode a
        // non-canonical URL (index.php?id_category=...) only returns a "page has moved" stub.
        foreach (['/', '/index.php?controller=cart', '/3-clothes'] as $path) {
            [$status, $body] = httpGet("http://localhost:{$this->port}$path");
            if ($status !== 200) {
                $problems[] = "$path returned HTTP $status";
            }
            foreach (['Fatal error', 'Parse error', 'Warning: ', 'Notice: ', 'Deprecated: ', 'Uncaught '] as $needle) {
                if (str_contains($body, $needle)) {
                    $problems[] = "$path contains '" . trim($needle) . "'";
                }
            }
        }

        return ['ok' => $problems === [], 'output' => implode("\n", $problems)];
    }

    /** @return array{ok: bool, output: string} */
    public function integration(): array
    {
        $name = $this->config['module'];
        $result = $this->exec("cd /var/www/html/modules/$name && _PS_ROOT_DIR_=/var/www/html php vendor/bin/phpunit --bootstrap tests/Integration/bootstrap.php --testsuite integration --colors=never 2>&1");
        out($result['output']);

        return ['ok' => $result['code'] === 0, 'output' => lastLines($result['output'], 15)];
    }

    /** @return array{ok: bool, output: string} */
    public function behat(): array
    {
        $dir = ROOT . '/tests/behat';
        if (!is_dir("$dir/vendor")) {
            passthru('composer install --no-interaction --no-progress --working-dir=' . escapeshellarg($dir), $code);
        }
        $baseUrl = "http://localhost:{$this->port}";
        putenv("PS_BASE_URL=$baseUrl");
        // Overrides base_url from behat.yml for every context.
        putenv('BEHAT_PARAMS=' . json_encode(['extensions' => ['Behat\\MinkExtension' => ['base_url' => $baseUrl]]]));
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg("$dir/vendor/bin/behat") . ' --config=' . escapeshellarg("$dir/behat.yml") . ' --no-colors 2>&1', $lines, $code);
        out(implode("\n", $lines));

        return ['ok' => $code === 0, 'output' => lastLines(implode("\n", $lines), 15)];
    }

    /** @return array{code: int, output: string} */
    public function exec(string $shell, string $user = 'www-data'): array
    {
        $cmd = $this->composeCmd('exec -T -u ' . $user . ' prestashop sh -c ' . escapeshellarg($shell));
        exec($cmd . ' 2>&1', $lines, $code);

        return ['code' => $code, 'output' => trim(implode("\n", $lines))];
    }

    private function compose(string $args, bool $show = false): void
    {
        $cmd = $this->composeCmd($args);
        if ($show) {
            passthru($cmd, $code);
        } else {
            exec($cmd . ' 2>&1', $lines, $code);
        }
        if ($code !== 0) {
            fail("docker compose $args failed" . (isset($lines) ? ":\n" . implode("\n", $lines) : '.'));
        }
    }

    private function composeCmd(string $args): string
    {
        putenv('PS_VERSION=' . $this->version);
        putenv('PS_PORT=' . $this->port);
        putenv('DB_IMAGE=' . ($this->config['db_image'] ?? 'mysql:8.0'));

        return 'docker compose -p ' . escapeshellarg($this->project) . ' -f ' . escapeshellarg(COMPOSE_FILE) . ' ' . $args;
    }
}

/**
 * @param string[] $versions
 * @param string[] $flags
 */
function runMatrix(array $versions, array $flags): int
{
    global $config, $moduleDir;
    $suites = $config['suites'];
    $all = [];

    // The release zip is what merchants install: test it too (scoped vendor/, no dev files).
    $zip = buildReleaseZip($moduleDir);

    foreach ($versions as $version) {
        $shop = new Shop($version, $config);
        $results = [];
        $shop->up();

        $shop->deploy($moduleDir);
        $results['install'] = $shop->console('install');
        $fresh = $shop->snapshot();
        $results['installed state'] = check($fresh['installed'] && $fresh['active'], 'Module not installed/active after install.');
        $results['smoke (front pages)'] = $shop->smoke();

        if (!empty($suites['integration']) && !in_array('--skip-integration', $flags, true)) {
            $results['integration'] = $shop->integration();
        }
        if (!empty($suites['behat']) && !in_array('--skip-behat', $flags, true)) {
            $results['behat'] = $shop->behat();
        }

        $results['uninstall'] = $shop->console('uninstall');
        $results['uninstall leaves nothing'] = cleanCheck($shop->snapshot());
        $results['re-install'] = $shop->console('install');
        $results['re-install state'] = compareSnapshots($fresh, $shop->snapshot());
        $results += releaseZipTest($shop, $zip, $fresh);

        if (($config['upgrade_from'] ?? 'none') !== 'none' && !in_array('--skip-upgrade', $flags, true)) {
            $results += upgradeTests($shop, $fresh);
        }

        if (!in_array('--keep', $flags, true)) {
            $shop->down();
        }
        $all[$version] = $results;
    }

    out("\n================ RESULT ================");
    $ok = true;
    foreach ($all as $version => $results) {
        out("\nPrestaShop $version");
        printResults($results);
        $ok = $ok && allOk($results);
    }

    return $ok ? 0 : 1;
}

/**
 * @param array<string, mixed> $fresh snapshot of a fresh install of the current code
 *
 * @return array<string, array{ok: bool, output: string}>
 */
function upgradeTests(Shop $shop, array $fresh): array
{
    global $config, $moduleDir;
    $results = [];
    $current = currentVersion($moduleDir);
    $tags = releaseTags($current);
    if (($config['upgrade_from'] ?? 'all') === 'previous') {
        $tags = array_slice($tags, -1, 1, true);
    }
    if ($tags === []) {
        return ['upgrade' => ['ok' => true, 'output' => 'skipped: no earlier release tags']];
    }

    foreach ($tags as $tag => $version) {
        $label = "upgrade $version -> $current";
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
        $shop->deploy($moduleDir);
        $upgrade = $shop->console('upgrade');
        if (!$upgrade['ok']) {
            $results[$label] = ['ok' => false, 'output' => $upgrade['output']];
            continue;
        }
        $results[$label] = compareSnapshots($fresh, $shop->snapshot());
        $shop->console('uninstall');
    }
    // Leave the current version installed.
    $shop->deploy($moduleDir);
    $shop->console('install');

    return $results;
}

/**
 * Builds dist/<module>.zip with build-zip.php and returns its path (null on failure).
 */
function buildReleaseZip(string $moduleDir): ?string
{
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/build-zip.php') . ' ' . escapeshellarg($moduleDir) . ' 2>&1', $lines, $code);
    $zip = trim((string) end($lines));

    return $code === 0 && is_file($zip) ? $zip : null;
}

/**
 * Installs the release zip instead of the working copy, compares the result with a
 * fresh install of the working copy, runs the smoke test and uninstalls again.
 * Afterwards the working copy is deployed and installed again.
 *
 * @param array<string, mixed> $fresh snapshot of a fresh install of the working copy
 *
 * @return array<string, array{ok: bool, output: string}>
 */
function releaseZipTest(Shop $shop, ?string $zip, array $fresh): array
{
    global $moduleDir, $module;
    if ($zip === null) {
        return ['release zip' => ['ok' => false, 'output' => 'Building the release zip failed (ps-module build).']];
    }
    $work = str_replace('\\', '/', sys_get_temp_dir()) . '/ps-zip-' . uniqid();
    $za = new ZipArchive();
    $za->open($zip);
    $za->extractTo($work);
    $za->close();

    $results = [];
    $shop->console('uninstall');
    $shop->deploy("$work/$module");
    $results['release zip: install'] = $shop->console('install');
    $results['release zip: state'] = compareSnapshots($fresh, $shop->snapshot());
    $results['release zip: smoke'] = $shop->smoke();
    $results['release zip: uninstall'] = $shop->console('uninstall');
    $results['release zip: leaves nothing'] = cleanCheck($shop->snapshot());
    removeDir($work);

    $shop->deploy($moduleDir);
    $shop->console('install');

    return $results;
}

/** @return array<string, mixed> */
function freshSnapshot(Shop $shop): array
{
    global $moduleDir;
    $shop->console('uninstall');
    $shop->deploy($moduleDir);
    $shop->console('install');

    return $shop->snapshot();
}

/** @return array<string, string> tag => version, ascending, only versions below $current */
function releaseTags(string $current): array
{
    exec('git -C ' . escapeshellarg(ROOT) . ' tag --list 2>&1', $lines, $code);
    if ($code !== 0) {
        return [];
    }
    $tags = [];
    foreach ($lines as $tag) {
        if (preg_match('/^v?(\d+\.\d+\.\d+)$/', trim($tag), $m) && version_compare($m[1], $current, '<')) {
            $tags[trim($tag)] = $m[1];
        }
    }
    uasort($tags, 'version_compare');

    return $tags;
}

/** Exports <module>/ from a git tag and installs its production dependencies. */
function exportRelease(string $tag): ?string
{
    global $module;
    $work = str_replace('\\', '/', sys_get_temp_dir()) . '/ps-release-' . uniqid();
    mkdir($work, 0775, true);
    $zip = "$work/release.zip";
    exec('git -C ' . escapeshellarg(ROOT) . ' archive --format=zip -o ' . escapeshellarg($zip) . ' ' . escapeshellarg($tag) . ' ' . escapeshellarg($module) . ' 2>&1', $lines, $code);
    if ($code !== 0) {
        return null;
    }
    $za = new ZipArchive();
    $za->open($zip);
    $za->extractTo($work);
    $za->close();
    $dir = "$work/$module";
    if (is_file("$dir/composer.json")) {
        global $moduleDir;
        rewritePathRepositories($moduleDir, $dir);
        exec('composer install --no-dev --no-interaction --no-progress --quiet --working-dir=' . escapeshellarg($dir) . ' 2>&1', $out, $code);
        if ($code !== 0) {
            return null;
        }
    }

    return $dir;
}

function currentVersion(string $moduleDir): string
{
    $code = (string) file_get_contents($moduleDir . '/' . basename($moduleDir) . '.php');
    preg_match('/\$this->version\s*=\s*[\'"]([^\'"]+)/', $code, $m);

    return $m[1] ?? '0.0.0';
}

/**
 * @param array<string, mixed> $expected
 * @param array<string, mixed> $actual
 *
 * @return array{ok: bool, output: string}
 */
function compareSnapshots(array $expected, array $actual): array
{
    $diff = [];
    foreach (['installed', 'version', 'active', 'hooks', 'configuration', 'tabs', 'overrides'] as $key) {
        if (($expected[$key] ?? null) != ($actual[$key] ?? null)) {
            $diff[] = "$key: expected " . json_encode($expected[$key]) . ', got ' . json_encode($actual[$key]);
        }
    }
    $tables = array_unique(array_merge(array_keys($expected['tables']), array_keys($actual['tables'])));
    foreach ($tables as $table) {
        $e = $expected['tables'][$table] ?? null;
        $a = $actual['tables'][$table] ?? null;
        if ($e === null || $a === null) {
            $diff[] = "table $table: " . ($e === null ? 'unexpected' : 'missing');
            continue;
        }
        foreach (['columns', 'keys'] as $part) {
            $missing = array_diff($e[$part], $a[$part]);
            $extra = array_diff($a[$part], $e[$part]);
            if ($missing || $extra) {
                $diff[] = "table $table $part: missing " . json_encode(array_values($missing)) . ', extra ' . json_encode(array_values($extra));
            }
        }
    }

    return ['ok' => $diff === [], 'output' => implode("\n", $diff)];
}

/**
 * @param array<string, mixed> $snapshot
 *
 * @return array{ok: bool, output: string}
 */
function cleanCheck(array $snapshot): array
{
    $left = [];
    if ($snapshot['installed']) {
        $left[] = 'module is still installed';
    }
    foreach (['hooks', 'configuration', 'tabs', 'overrides'] as $key) {
        if (!empty($snapshot[$key])) {
            $left[] = "$key: " . implode(', ', $snapshot[$key]);
        }
    }
    if ($snapshot['tables']) {
        $left[] = 'tables: ' . implode(', ', array_keys($snapshot['tables']));
    }

    return ['ok' => $left === [], 'output' => implode("\n", $left)];
}

/** @return array{ok: bool, output: string} */
function check(bool $ok, string $message): array
{
    return ['ok' => $ok, 'output' => $ok ? '' : $message];
}

/** @param array<string, array{ok: bool, output: string}> $results */
function printResults(array $results): void
{
    foreach ($results as $step => $result) {
        out(sprintf('  [%s] %s', $result['ok'] ? ' OK ' : 'FAIL', $step));
        if (!$result['ok'] && $result['output'] !== '') {
            out('         ' . str_replace("\n", "\n         ", $result['output']));
        }
    }
}

/** @param array<string, array{ok: bool, output: string}> $results */
function allOk(array $results): bool
{
    return !in_array(false, array_column($results, 'ok'), true);
}

function requireDocker(): void
{
    $cli = (string) @shell_exec('docker --version 2>&1');
    if (stripos($cli, 'Docker version') === false) {
        fail("Docker is not installed. These tests start PrestaShop shops in Docker.\nInstall Docker Desktop: https://www.docker.com/products/docker-desktop/");
    }
    $server = trim((string) @shell_exec('docker info --format "{{.ServerVersion}}" 2>&1'));
    if (!preg_match('/^\d+\.\d+/', $server)) {
        fail('Docker is installed but not running. Start Docker Desktop and try again.');
    }
    if (stripos((string) @shell_exec('docker compose version 2>&1'), 'version') === false) {
        fail('The Docker compose plugin is missing ("docker compose"). Update Docker Desktop.');
    }
}

/** @param string[] $args */
function requireVersion(array $args): string
{
    if (!isset($args[0]) || !preg_match('/^\d+\.\d+\.\d+$/', $args[0])) {
        fail('Give a PrestaShop version, e.g. 8.1.0');
    }

    return $args[0];
}

function httpStatus(string $url): int
{
    return httpGet($url)[0];
}

/** @return array{0: int, 1: string} */
function httpGet(string $url): array
{
    $context = stream_context_create(['http' => ['timeout' => 30, 'ignore_errors' => true, 'follow_location' => 0]]);
    $body = @file_get_contents($url, false, $context);
    $status = 0;
    foreach ($http_response_header ?? [] as $header) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $m)) {
            $status = (int) $m[1];
        }
    }

    return [$status, (string) $body];
}

/** @param string[] $exclude directory names to skip */
function copyDir(string $source, string $target, array $exclude): void
{
    $source = rtrim(str_replace('\\', '/', $source), '/');
    @mkdir($target, 0775, true);
    $it = new RecursiveIteratorIterator(
        new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
            static fn (SplFileInfo $f): bool => !($f->isDir() && in_array($f->getFilename(), $exclude, true))
        ),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($it as $item) {
        $dest = $target . '/' . substr(str_replace('\\', '/', $item->getPathname()), strlen($source) + 1);
        $item->isDir() ? @mkdir($dest, 0775, true) : copy($item->getPathname(), $dest);
    }
}

function removeDir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($dir);
}

function lastLines(string $text, int $n): string
{
    return implode("\n", array_slice(explode("\n", trim($text)), -$n));
}

function out(string $message): void
{
    echo $message . "\n";
}

function fail(string $message): void
{
    fwrite(STDERR, "ERROR: $message\n");
    exit(1);
}
