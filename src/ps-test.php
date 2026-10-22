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
 *        options: --keep (leave the shops running), --skip-upgrade, --skip-behat, --skip-integration,
 *                 --multistore / --skip-multistore (default: "multistore" in testing.json)
 *   ps-module shops up <version>           start a shop, install the current module, print URLs
 *   ps-module shops multistore <version>   multistore round only (turns the shop into 3 shops; ps-multistore.php)
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
 *                                            9. multistore round (testing.json "multistore": true)
 */

require_once __DIR__ . '/dod.php';
require_once __DIR__ . '/composer-paths.php';
require_once __DIR__ . '/verbose.php';
require_once __DIR__ . '/ps-multistore.php';
require_once __DIR__ . '/lock.php';

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
// -v / --verbose also works when this script is started directly (ps-module strips it and sets the variable).
if (array_intersect($argvRest, ['-v', '--verbose']) !== []) {
    $argvRest = array_values(array_diff($argvRest, ['-v', '--verbose']));
    putenv('PS_MODULE_VERBOSE=1');
}
$flags = array_values(array_filter($argvRest, static fn (string $a): bool => str_starts_with($a, '--')));
$args = array_values(array_filter($argvRest, static fn (string $a): bool => !str_starts_with($a, '--')));
$command = array_shift($args) ?? 'help';
ps_vlog("shops $command: arguments " . json_encode($args) . ', flags ' . json_encode($flags) . ', repository ' . ROOT);
ps_vlog('testing.json: module ' . $module . ', versions ' . implode(', ', (array) $config['versions'])
    . ', suites ' . json_encode($config['suites'] ?? []) . ', upgrade_from ' . ($config['upgrade_from'] ?? 'none')
    . ', multistore ' . json_encode($config['multistore'] ?? false) . ', db_image ' . ($config['db_image'] ?? 'mysql:8.0')
    . ', base_port ' . ($config['base_port'] ?? 8000));

// The shops of this module are used by one command at a time: a second terminal or Claude session working on the same
// module waits here instead of stopping the shops under the first one (lock.php). Other modules have their own shops.
if (!in_array($command, ['doctor', 'help'], true)) {
    try {
        ps_lock('shops-' . $module, "shops $command " . implode(' ', $args), ROOT);
    } catch (RuntimeException $e) {
        fail($e->getMessage());
    }
}

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
            && array_intersect($flags, ['--skip-upgrade', '--skip-behat', '--skip-integration', '--skip-multistore']) === [];
        if ($code === 0 && $full) {
            dod_stamp(ROOT, 'shop-tests', null, 'PrestaShop ' . implode(', ', $args ?: $config['versions']));
        }
        ps_vlog('definition-of-done stamp shop-tests: ' . ($code === 0 && $full ? 'written' : ($code !== 0 ? 'not written (a step failed)' : 'not written (not every version, or a suite was skipped)')));
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
    case 'multistore':
        requireDocker();
        $shop = new Shop(requireVersion($args), $config);
        $shop->up();
        $results = multistoreRound($shop, $flags);
        printResults($results);
        exit(allOk($results) ? 0 : 1);
    case 'down':
        requireDocker();
        $versions = ($args[0] ?? 'all') === 'all' ? $config['versions'] : [$args[0]];
        ps_vlog('stopping the shops of PrestaShop ' . implode(', ', $versions));
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

/**
 * One PrestaShop test shop (MySQL + PrestaShop container) for one version, managed with docker compose.
 */
final class Shop
{
    public readonly int $port;
    private readonly string $project;

    /**
     * Derives the port and the compose project name from the PrestaShop version.
     *
     * @param array<string, mixed> $config
     */
    public function __construct(public readonly string $version, private readonly array $config)
    {
        $parts = array_map('intval', explode('.', $version));
        $this->port = (int) ($config['base_port'] ?? 8000) + $parts[0] * 100 + $parts[1] * 10 + ($parts[2] ?? 0);
        $this->project = $config['module'] . '-ps' . str_replace('.', '', $version);
        ps_vlog("shop PrestaShop $version: port {$this->port}, compose project {$this->project}, database image "
            . ($config['db_image'] ?? 'mysql:8.0') . ', compose file ' . COMPOSE_FILE);
    }

    /**
     * Starts the shop if it is not running and waits until the automatic installation has finished.
     */
    public function up(): void
    {
        out("\n== PrestaShop {$this->version} on port {$this->port} ==");
        if ($this->isReady()) {
            out('Shop is already running.');

            return;
        }
        // Another module's shop (or another program) on this port would answer instead of this shop.
        $other = $this->portTakenBy();
        if ($other !== '') {
            fail("Port {$this->port} is already used by $other. Give this module its own range (testing.json > base_port, "
                . 'ports base_port + 100 * major + 10 * minor + patch), or stop that container.');
        }
        $this->compose('up -d', true);
        out('Waiting for the PrestaShop installation (first run downloads the image; can take several minutes)...');
        $start = microtime(true);
        $deadline = time() + 1200;
        $attempt = 0;
        while (time() < $deadline) {
            ++$attempt;
            ps_vlog(sprintf('waiting for PrestaShop %s: check %d (%ds of at most 1200s)', $this->version, $attempt, (int) (microtime(true) - $start)));
            if ($this->isReady()) {
                ps_vdone("PrestaShop {$this->version} installation", $start);
                out('Shop is ready.');

                return;
            }
            sleep(5);
        }
        fail("PrestaShop {$this->version} did not become ready. Check: docker compose -p {$this->project} logs prestashop");
    }

    /**
     * What else listens on the port of this shop: a container of another compose project (its name), "a program
     * outside Docker" when the port answers without a container of this project, or '' when the port is free or ours.
     */
    public function portTakenBy(): string
    {
        $names = trim(shellOutput('docker ps --filter ' . escapeshellarg('publish=' . $this->port) . ' --format "{{.Names}}"'));
        if ($names === '') {
            return httpStatus("http://localhost:{$this->port}/") > 0 && trim(shellOutput($this->composeCmd('ps -q'))) === ''
                ? 'a program outside Docker' : '';
        }
        foreach (preg_split('/\R/', $names) ?: [] as $name) {
            if ($name !== '' && !str_starts_with($name, $this->project . '-') && !str_starts_with($name, $this->project . '_')) {
                return "the Docker container $name";
            }
        }

        return '';
    }

    /**
     * Stops the shop and removes its containers and volumes.
     */
    public function down(): void
    {
        $this->compose('down -v --remove-orphans', true);
    }

    /**
     * True when the front page answers and the installation has written its configuration.
     */
    public function isReady(): bool
    {
        $status = httpStatus("http://localhost:{$this->port}/");
        if (!in_array($status, [200, 301, 302], true)) {
            ps_vlog("not ready: the front page answers HTTP $status");

            return false;
        }
        $installed = $this->exec('test -f /var/www/html/app/config/parameters.php && echo ok')['output'] === 'ok';
        ps_vlog($installed ? 'ready: front page answers and app/config/parameters.php exists' : 'not ready: app/config/parameters.php does not exist yet');

        return $installed;
    }

    /** Copies a module directory into the shop (replacing what is there). */
    public function deploy(string $sourceDir): void
    {
        $name = $this->config['module'];
        $stage = sys_get_temp_dir() . '/ps-test-' . uniqid() . '/' . $name;
        $start = microtime(true);
        ps_vlog("deploy to PrestaShop {$this->version}: copying $sourceDir to $stage (without .git, .cache, node_modules, dist)");
        copyDir($sourceDir, $stage, ['.git', '.cache', 'node_modules', 'dist']);
        ps_vlog("deploy: replacing /var/www/html/modules/$name in the container");
        $this->exec("rm -rf /var/www/html/modules/$name", 'root');
        $this->compose('cp ' . escapeshellarg($stage) . ' ' . escapeshellarg("prestashop:/var/www/html/modules/$name"));
        $this->exec("chown -R www-data:www-data /var/www/html/modules/$name", 'root');
        removeDir(dirname($stage));
        ps_vdone("deploy of $name", $start);
    }

    /**
     * Runs "bin/console prestashop:module <action>" for the module and empties the cache afterwards.
     *
     * @return array{ok: bool, output: string}
     */
    public function console(string $action): array
    {
        $name = $this->config['module'];
        ps_vlog("module $action: $name on PrestaShop {$this->version}");
        $result = $this->exec("cd /var/www/html && php -d memory_limit=1G bin/console prestashop:module $action $name 2>&1");
        // PS 8.1: right after the first install in a fresh shop, the compiled containers can
        // still miss the module's services. Empty the cache; the next request rebuilds it.
        $this->exec('rm -rf /var/www/html/var/cache/dev/* /var/www/html/var/cache/prod/*', 'root');
        // PrestaShop prints "<Action> action on module <name> succeeded." on success. Other
        // output (e.g. PHP warnings from the Docker image in dev mode) is not a failure.
        $ok = $result['code'] === 0 && preg_match('/\bsucceeded\b/i', $result['output']) === 1;
        ps_vlog("module $action " . ($ok ? 'succeeded' : 'FAILED') . ', bin/console said:' . "\n" . vIndent(lastLines($result['output'], 30)));

        return ['ok' => $ok, 'output' => $result['output']];
    }

    /**
     * Everything the module left in the shop (module row, hooks, configuration, tabs, overrides, tables), via ps-probe.php.
     *
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        ps_vlog('snapshot: copying ps-probe.php into the container (/tmp/ps-probe.php) and running it');
        $this->compose('cp ' . escapeshellarg(__DIR__ . '/ps-probe.php') . ' prestashop:/tmp/ps-probe.php');
        $result = $this->exec('php /tmp/ps-probe.php ' . escapeshellarg($this->config['module']) . ' ' . escapeshellarg(strtoupper($this->config['module'])));
        $data = json_decode($result['output'], true);
        if (!is_array($data)) {
            fail("Snapshot failed:\n" . $result['output']);
        }
        ps_vlog(sprintf(
            'snapshot: installed %s, version %s, active %s, %d hook(s), %d configuration key(s), %d tab(s), %d override(s), %d table(s), enabled in shops %s',
            json_encode($data['installed'] ?? null),
            json_encode($data['version'] ?? null),
            json_encode($data['active'] ?? null),
            count((array) ($data['hooks'] ?? [])),
            count((array) ($data['configuration'] ?? [])),
            count((array) ($data['tabs'] ?? [])),
            count((array) ($data['overrides'] ?? [])),
            count((array) ($data['tables'] ?? [])),
            json_encode($data['module_shops'] ?? [])
        ));

        return $data;
    }

    /**
     * Opens the main front-office pages and fails on non-200 answers or PHP errors in the HTML.
     *
     * @return array{ok: bool, output: string}
     */
    public function smoke(): array
    {
        $problems = [];
        ps_vlog("front-office smoke test of PrestaShop {$this->version}: home, cart, category 3-clothes");
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

    /**
     * Logs in to the back office and opens the pages a module most often breaks: the
     * dashboard, Module Manager (a broken Symfony container fails here), the module's
     * configuration page, every back-office link of the module in the menu, and a product
     * edit page (product form extensions). Each must answer 200 without PHP errors.
     *
     * @param bool $configurable whether the module has a configuration page (getContent)
     *
     * @return array{ok: bool, output: string}
     */
    public function backOfficeSmoke(bool $configurable): array
    {
        $name = $this->config['module'];
        $base = "http://localhost:{$this->port}";
        $admin = "$base/admin-dev/index.php";
        $jar = tempnam(sys_get_temp_dir(), 'psbo');
        $problems = [];
        ps_vlog("back-office smoke test of PrestaShop {$this->version}: logging in as demo@prestashop.com (configuration page: " . ($configurable ? 'yes' : 'no') . ')');

        [, $loginPage, $loginUrl] = curlRequest($admin . '?controller=AdminLogin', $jar);
        $fields = hiddenInputs($loginPage);
        $fields['email'] = 'demo@prestashop.com';
        $fields['passwd'] = 'prestashop_demo';
        $fields['submitLogin'] = '1';
        [$code, $dashboard, $dashboardUrl] = curlRequest($loginUrl, $jar, $fields);
        if (!preg_match('/_token=([A-Za-z0-9_\-.]+)/', $dashboard . $dashboardUrl, $m)) {
            @unlink($jar);

            return ['ok' => false, 'output' => "Back-office login failed (HTTP $code, landed on $dashboardUrl)."];
        }
        $token = $m[1];
        $symfony = static fn (string $path): string => $admin . $path . (str_contains($path, '?') ? '&' : '?') . '_token=' . $token;

        $pages = ['dashboard' => null, 'Module Manager' => $symfony('/improve/modules/manage')];
        if ($configurable) {
            $pages['module configuration'] = $symfony('/improve/modules/manage/action/configure/' . $name);
        }
        // Menu links of the module (Symfony routes are prefixed with /modules/<name>, legacy
        // controllers are called by class name).
        preg_match_all('/href="([^"]*(?:\/modules\/' . preg_quote($name, '/') . '\/|controller=Admin' . preg_quote(ucfirst($name), '/') . ')[^"]*)"/i', $dashboard, $links);
        foreach (array_unique($links[1]) as $i => $link) {
            $link = html_entity_decode($link);
            $pages['menu link ' . ($i + 1)] = str_starts_with($link, 'http') ? $link : $base . $link;
        }
        // Product edit page: /products-v2/ on PS 8, /products/ on PS 9.
        $pages['product edit page'] = [$symfony('/sell/catalog/products-v2/1/edit'), $symfony('/sell/catalog/products/1/edit')];
        ps_vlog('back office: logged in; pages to check: ' . implode(', ', array_keys($pages)));

        foreach ($pages as $label => $urls) {
            if ($urls === null) {
                $result = [$code, $dashboard, $dashboardUrl];
            } else {
                foreach ((array) $urls as $url) {
                    $result = curlRequest($url, $jar);
                    if ($result[0] !== 404) {
                        break;
                    }
                }
            }
            [$status, $body, $finalUrl] = $result;
            if ($status !== 200) {
                $problems[] = "$label: HTTP $status ($finalUrl)";
                continue;
            }
            foreach (['Fatal error', 'Parse error', 'Warning: ', 'Notice: ', 'Uncaught ', 'Stack trace', 'Invalid token'] as $needle) {
                if (stripos($body, $needle) !== false) {
                    $pos = (int) stripos($body, $needle);
                    $problems[] = "$label contains '" . trim($needle) . "': " . substr(trim((string) preg_replace('/\s+/', ' ', strip_tags(substr($body, max(0, $pos - 100), 400)))), 0, 300);
                    break;
                }
            }
        }
        @unlink($jar);

        return ['ok' => $problems === [], 'output' => implode("\n", $problems)];
    }

    /**
     * Runs the PHPUnit integration suite inside the shop container.
     *
     * @return array{ok: bool, output: string}
     */
    public function integration(): array
    {
        $name = $this->config['module'];
        ps_vlog("integration suite (PHPUnit, testsuite integration) in /var/www/html/modules/$name of PrestaShop {$this->version}");
        $result = $this->exec("cd /var/www/html/modules/$name && _PS_ROOT_DIR_=/var/www/html php vendor/bin/phpunit --bootstrap tests/Integration/bootstrap.php --testsuite integration --colors=never 2>&1");
        out($result['output']);

        return ['ok' => $result['code'] === 0, 'output' => lastLines($result['output'], 15)];
    }

    /**
     * Runs the Behat scenarios from the repository against this shop over HTTP.
     *
     * @return array{ok: bool, output: string}
     */
    public function behat(): array
    {
        $dir = ROOT . '/tests/behat';
        if (!is_dir("$dir/vendor")) {
            ps_vlog("behat: $dir/vendor is missing, installing the Behat dependencies");
            if (runShown('composer install --no-interaction --no-progress --working-dir=' . escapeshellarg($dir)) === 0) {
                behatAutoloadRecord($dir);
            }
        } elseif (behatAutoloadOutdated($dir)) {
            // composer.json changed after the last install (e.g. a renamed namespace): the old class map would not
            // find the contexts ("FeatureContext context class not found"). The lock file there is local only.
            out('tests/behat/composer.json changed since the last install: updating the Behat dependencies and autoloader.');
            if (runShown('composer update --no-interaction --no-progress --working-dir=' . escapeshellarg($dir)) === 0) {
                behatAutoloadRecord($dir);
            }
        }
        $baseUrl = "http://localhost:{$this->port}";
        ps_vlog("behat: $dir/behat.yml against $baseUrl (PS_BASE_URL and BEHAT_PARAMS base_url)");
        putenv("PS_BASE_URL=$baseUrl");
        // Overrides base_url from behat.yml for every context.
        putenv('BEHAT_PARAMS=' . json_encode(['extensions' => ['Behat\\MinkExtension' => ['base_url' => $baseUrl]]]));
        $code = runCaptured(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg("$dir/vendor/bin/behat") . ' --config=' . escapeshellarg("$dir/behat.yml") . ' --no-colors 2>&1', $lines);
        out(implode("\n", $lines));

        return ['ok' => $code === 0, 'output' => lastLines(implode("\n", $lines), 15)];
    }

    /**
     * Copies a local file into the PrestaShop container (e.g. a script for ps-multistore.php).
     */
    public function upload(string $localFile, string $containerPath): void
    {
        ps_vlog("upload: $localFile -> container $containerPath");
        $this->compose('cp ' . escapeshellarg($localFile) . ' ' . escapeshellarg('prestashop:' . $containerPath));
    }

    /**
     * Runs a shell command inside the PrestaShop container and returns exit code and output.
     * In verbose mode the container gets PS_MODULE_VERBOSE=1 too; the verbose lines of the scripts
     * there (on their STDERR) are shown and kept out of the output.
     *
     * @return array{code: int, output: string}
     */
    public function exec(string $shell, string $user = 'www-data'): array
    {
        if (!ps_verbose()) {
            $cmd = $this->composeCmd('exec -T -u ' . $user . ' prestashop sh -c ' . escapeshellarg($shell));
            exec($cmd . ' 2>&1', $lines, $code);

            return ['code' => $code, 'output' => trim(implode("\n", $lines))];
        }
        $cmd = $this->composeCmd('exec -T -e PS_MODULE_VERBOSE=1 -u ' . $user . ' prestashop sh -c ' . escapeshellarg($shell));
        // STDERR to a file instead of 2>&1: then a verbose line can never end up in the middle of the output.
        $errFile = (string) tempnam(sys_get_temp_dir(), 'psexec');
        ps_vcmd($cmd);
        $start = microtime(true);
        exec($cmd . ' 2>' . escapeshellarg($errFile), $lines, $code);
        $other = [];
        foreach (preg_split('/\R/', (string) @file_get_contents($errFile)) ?: [] as $line) {
            if (preg_match('/^\[v [\d:]+\] (.*)$/', $line, $m)) {
                ps_vlog('  container: ' . $m[1]);
            } elseif ($line !== '') {
                $other[] = $line;
            }
        }
        @unlink($errFile);
        ps_vdone('docker compose exec', $start, $code);
        $output = trim(implode("\n", array_merge($lines, $other)));
        if ($code !== 0 && $output !== '') {
            ps_vlog("output:\n" . vIndent(lastLines($output, 20)));
        }

        return ['code' => $code, 'output' => $output];
    }

    /**
     * Runs a docker compose command for this shop and stops the runner when it fails.
     */
    private function compose(string $args, bool $show = false): void
    {
        $cmd = $this->composeCmd($args);
        if ($show) {
            $code = runShown($cmd);
        } else {
            $code = runCaptured($cmd . ' 2>&1', $lines);
            if ($lines !== []) {
                ps_vlog("output:\n" . vIndent(implode("\n", $lines)));
            }
        }
        if ($code !== 0) {
            fail("docker compose $args failed" . (isset($lines) ? ":\n" . implode("\n", $lines) : '.'));
        }
    }

    /**
     * Builds a docker compose command line for this shop, with its version, port and project name.
     */
    private function composeCmd(string $args): string
    {
        putenv('PS_VERSION=' . $this->version);
        putenv('PS_PORT=' . $this->port);
        putenv('DB_IMAGE=' . ($this->config['db_image'] ?? 'mysql:8.0'));

        return 'docker compose -p ' . escapeshellarg($this->project) . ' -f ' . escapeshellarg(COMPOSE_FILE) . ' ' . $args;
    }
}

/**
 * True when tests/behat/composer.json changed since the Behat dependencies were installed: its hash differs from the
 * one recorded after the last install (behatAutoloadRecord()), or, without a record (older installs), it names a PSR-4
 * namespace the autoloader does not know. Composer only rewrites its autoload files when they change, so file times
 * say nothing here.
 */
function behatAutoloadOutdated(string $dir): bool
{
    $autoload = "$dir/vendor/composer/autoload_psr4.php";
    if (!is_file($autoload) || !is_file("$dir/composer.json")) {
        return is_file("$dir/composer.json");
    }
    $record = "$dir/vendor/.ps-module-composer-json.sha1";
    if (is_file($record)) {
        return trim((string) file_get_contents($record)) !== sha1_file("$dir/composer.json");
    }
    $composer = json_decode((string) file_get_contents("$dir/composer.json"), true);
    $known = (string) file_get_contents($autoload);
    foreach (array_keys(array_merge((array) ($composer['autoload']['psr-4'] ?? []), (array) ($composer['autoload-dev']['psr-4'] ?? []))) as $namespace) {
        if (!str_contains($known, var_export((string) $namespace, true))) {
            ps_vlog("behat: namespace $namespace of composer.json is not in $autoload");

            return true;
        }
    }

    return false;
}

/**
 * Records the hash of tests/behat/composer.json after a successful install or update (see behatAutoloadOutdated()).
 */
function behatAutoloadRecord(string $dir): void
{
    if (is_dir("$dir/vendor") && is_file("$dir/composer.json")) {
        @file_put_contents("$dir/vendor/.ps-module-composer-json.sha1", sha1_file("$dir/composer.json") . "\n");
    }
}

/**
 * Runs every step for each PrestaShop version and prints the result table; returns the exit code.
 *
 * @param string[] $versions
 * @param string[] $flags
 */
function runMatrix(array $versions, array $flags): int
{
    global $config, $moduleDir;
    $suites = $config['suites'];
    $all = [];

    ps_vlog('run: PrestaShop ' . implode(', ', $versions) . '; integration ' . vWhy(!empty($suites['integration']), '--skip-integration', $flags)
        . ', behat ' . vWhy(!empty($suites['behat']), '--skip-behat', $flags)
        . ', upgrade ' . vWhy(($config['upgrade_from'] ?? 'none') !== 'none', '--skip-upgrade', $flags)
        . ', multistore ' . (multistoreWanted($config, $flags) ? 'yes' : 'no')
        . ', shops ' . (in_array('--keep', $flags, true) ? 'kept running (--keep)' : 'removed afterwards'));

    // The release zip is what merchants install: test it too (scoped vendor/, no dev files).
    $zip = buildReleaseZip($moduleDir);

    foreach ($versions as $version) {
        $versionStart = microtime(true);
        $shop = new Shop($version, $config);
        $results = [];
        $shop->up();

        $shop->deploy($moduleDir);
        $results['install'] = $shop->console('install');
        $fresh = $shop->snapshot();
        $results['installed state'] = check($fresh['installed'] && $fresh['active'], 'Module not installed/active after install.');
        $results['smoke (front pages)'] = $shop->smoke();
        $results['smoke (back office)'] = $shop->backOfficeSmoke((bool) ($fresh['configurable'] ?? false));

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
        if (multistoreWanted($config, $flags)) {
            $results += multistoreRound($shop, $flags);
        }

        if (!in_array('--keep', $flags, true)) {
            $shop->down();
        }
        $all[$version] = $results;
        ps_vdone("PrestaShop $version (" . count(array_filter(array_column($results, 'ok'))) . ' of ' . count($results) . ' steps OK)', $versionStart);
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
 * Installs each earlier release tag, upgrades it to the current code and compares with a fresh install.
 *
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
    ps_vlog("upgrade tests to $current (upgrade_from " . ($config['upgrade_from'] ?? 'all') . '): from ' . ($tags === [] ? 'nothing (no earlier release tags)' : implode(', ', array_keys($tags))));
    if ($tags === []) {
        return ['upgrade' => ['ok' => true, 'output' => 'skipped: no earlier release tags']];
    }

    foreach ($tags as $tag => $version) {
        $label = "upgrade $version -> $current";
        ps_vlog("$label: uninstall, install $tag, deploy the current code, upgrade, compare with a fresh install");
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
    ps_vlog('building the release zip (build-zip.php) for the release-zip test');
    $code = runCaptured(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/build-zip.php') . ' ' . escapeshellarg($moduleDir) . ' 2>&1', $lines);
    // build-zip.php inherits PS_MODULE_VERBOSE: its verbose lines (2>&1) are passed on as they are and
    // kept out of the output, whose last line is the path of the zip.
    $plain = [];
    foreach ($lines as $line) {
        if (preg_match('/^\[v [\d:]+\] /', $line)) {
            fwrite(STDERR, $line . "\n");
        } else {
            $plain[] = $line;
        }
    }
    ps_vlog("build-zip.php output:\n" . vIndent(implode("\n", $plain)));
    $zip = trim((string) end($plain));
    ps_vlog('release zip: ' . ($code === 0 && is_file($zip) ? $zip : 'build FAILED'));

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
    ps_vlog("release zip test: extracting $zip to $work");
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
    $results['release zip: back office'] = $shop->backOfficeSmoke((bool) ($fresh['configurable'] ?? false));
    $results['release zip: uninstall'] = $shop->console('uninstall');
    $results['release zip: leaves nothing'] = cleanCheck($shop->snapshot());
    removeDir($work);
    ps_vlog('release zip test done; installing the working copy again');

    $shop->deploy($moduleDir);
    $shop->console('install');

    return $results;
}

/**
 * Reinstalls the current code and returns the snapshot of that fresh install.
 *
 * @return array<string, mixed>
 */
function freshSnapshot(Shop $shop): array
{
    global $moduleDir;
    $shop->console('uninstall');
    $shop->deploy($moduleDir);
    $shop->console('install');

    return $shop->snapshot();
}

/**
 * Release tags (vX.Y.Z) older than the current version, oldest first.
 *
 * @return array<string, string> tag => version, ascending, only versions below $current
 */
function releaseTags(string $current): array
{
    $code = runCaptured('git -C ' . escapeshellarg(ROOT) . ' tag --list 2>&1', $lines);
    if ($code !== 0) {
        ps_vlog("git tag failed:\n" . vIndent(implode("\n", $lines)));

        return [];
    }
    $tags = [];
    foreach ($lines as $tag) {
        if (preg_match('/^v?(\d+\.\d+\.\d+)$/', trim($tag), $m) && version_compare($m[1], $current, '<')) {
            $tags[trim($tag)] = $m[1];
        }
    }
    uasort($tags, 'version_compare');
    ps_vlog('git tags: ' . ($lines === [] ? '(none)' : implode(', ', array_map('trim', $lines))) . "; release tags below $current: " . ($tags === [] ? '(none)' : implode(', ', array_keys($tags))));

    return $tags;
}

/** Exports <module>/ from a git tag and installs its production dependencies. */
function exportRelease(string $tag): ?string
{
    global $module;
    $work = str_replace('\\', '/', sys_get_temp_dir()) . '/ps-release-' . uniqid();
    mkdir($work, 0775, true);
    $zip = "$work/release.zip";
    ps_vlog("exporting $module/ of $tag to $work");
    $code = runCaptured('git -C ' . escapeshellarg(ROOT) . ' archive --format=zip -o ' . escapeshellarg($zip) . ' ' . escapeshellarg($tag) . ' ' . escapeshellarg($module) . ' 2>&1', $lines);
    if ($code !== 0) {
        ps_vlog("git archive failed:\n" . vIndent(implode("\n", $lines)));

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
        // --quiet only without verbose output; in verbose mode composer's output is shown.
        $code = runCaptured('composer install --no-dev --no-interaction --no-progress' . (ps_verbose() ? '' : ' --quiet') . ' --working-dir=' . escapeshellarg($dir) . ' 2>&1', $out);
        ps_vlog("composer install of $tag:\n" . vIndent(implode("\n", $out)));
        if ($code !== 0) {
            return null;
        }
    } else {
        ps_vlog("$tag has no composer.json: no dependencies to install");
    }

    return $dir;
}

/**
 * Reads $this->version from the main module file.
 */
function currentVersion(string $moduleDir): string
{
    $code = (string) file_get_contents($moduleDir . '/' . basename($moduleDir) . '.php');
    preg_match('/\$this->version\s*=\s*[\'"]([^\'"]+)/', $code, $m);

    return $m[1] ?? '0.0.0';
}

/**
 * Differences between two snapshots (version, hooks, configuration, tabs, overrides, table schemas).
 *
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
 * Checks that an uninstall left nothing behind.
 *
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

/**
 * Turns a condition into a step result with a message when it fails.
 *
 * @return array{ok: bool, output: string}
 */
function check(bool $ok, string $message): array
{
    return ['ok' => $ok, 'output' => $ok ? '' : $message];
}

/**
 * Prints the OK/FAIL lines of a result list, with the details of failures.
 *
 * @param array<string, array{ok: bool, output: string}> $results
 */
function printResults(array $results): void
{
    foreach ($results as $step => $result) {
        out(sprintf('  [%s] %s', $result['ok'] ? ' OK ' : 'FAIL', $step));
        if (!$result['ok'] && $result['output'] !== '') {
            out('         ' . str_replace("\n", "\n         ", $result['output']));
        }
    }
}

/**
 * True when every step in the result list passed.
 *
 * @param array<string, array{ok: bool, output: string}> $results
 */
function allOk(array $results): bool
{
    return !in_array(false, array_column($results, 'ok'), true);
}

/**
 * Stops with a clear message when Docker, the Docker daemon or docker compose is missing.
 */
function requireDocker(): void
{
    $cli = shellOutput('docker --version 2>&1');
    if (stripos($cli, 'Docker version') === false) {
        fail("Docker is not installed. These tests start PrestaShop shops in Docker.\nInstall Docker Desktop: https://www.docker.com/products/docker-desktop/");
    }
    $server = trim(shellOutput('docker info --format "{{.ServerVersion}}" 2>&1'));
    if (!preg_match('/^\d+\.\d+/', $server)) {
        fail('Docker is installed but not running. Start Docker Desktop and try again.');
    }
    if (stripos(shellOutput('docker compose version 2>&1'), 'version') === false) {
        fail('The Docker compose plugin is missing ("docker compose"). Update Docker Desktop.');
    }
    ps_vlog('Docker is installed and running, with docker compose');
}

/**
 * Output of a short command (shell_exec), reported with its time and output in verbose mode.
 */
function shellOutput(string $cmd): string
{
    ps_vcmd($cmd);
    $start = microtime(true);
    $output = (string) @shell_exec($cmd);
    ps_vdone('-> ' . trim($output), $start);

    return $output;
}

/**
 * Runs a command with its output on the console (passthru) and returns the exit code; in verbose
 * mode the command, exit code and time are reported.
 */
function runShown(string $cmd): int
{
    ps_vcmd($cmd);
    $start = microtime(true);
    passthru($cmd, $code);
    ps_vdone(vLabel($cmd), $start, $code);

    return $code;
}

/**
 * Runs a command with its output captured (exec) and returns the exit code; in verbose mode the
 * command, exit code and time are reported.
 *
 * @param string[]|null $lines receives the output lines
 */
function runCaptured(string $cmd, ?array &$lines): int
{
    $lines = [];
    ps_vcmd($cmd);
    $start = microtime(true);
    exec($cmd, $lines, $code);
    ps_vdone(vLabel($cmd), $start, $code);

    return $code;
}

/**
 * Short name of a command for the verbose "exit code" line: the program and its subcommand ("docker compose").
 */
function vLabel(string $cmd): string
{
    preg_match('/^\s*(?:"([^"]+)"|\'([^\']+)\'|(\S+))(?:\s+([a-z][\w-]*))?/i', $cmd, $m);
    $program = basename(str_replace('\\', '/', ($m[1] ?? '') . ($m[2] ?? '') . ($m[3] ?? '')));

    return trim($program . ' ' . ($m[4] ?? ''));
}

/**
 * Indents captured output for a verbose line ("(no output)" when empty).
 */
function vIndent(string $text): string
{
    $text = trim($text);

    return $text === '' ? '    (no output)' : '    | ' . str_replace("\n", "\n    | ", $text);
}

/**
 * For the verbose summary of "run": whether a suite runs, and if not, why.
 *
 * @param string[] $flags
 */
function vWhy(bool $configured, string $skipFlag, array $flags): string
{
    if (!$configured) {
        return 'no (not in testing.json)';
    }

    return in_array($skipFlag, $flags, true) ? "no ($skipFlag)" : 'yes';
}

/**
 * Returns the PrestaShop version from the arguments or stops with usage help.
 *
 * @param string[] $args
 */
function requireVersion(array $args): string
{
    if (!isset($args[0]) || !preg_match('/^\d+\.\d+\.\d+$/', $args[0])) {
        fail('Give a PrestaShop version, e.g. 8.1.0');
    }

    return $args[0];
}

/**
 * HTTP status code of a GET request.
 */
function httpStatus(string $url): int
{
    return httpGet($url)[0];
}

/**
 * GET request without following redirects; returns status code and body.
 *
 * @return array{0: int, 1: string}
 */
function httpGet(string $url): array
{
    $context = stream_context_create(['http' => ['timeout' => 30, 'ignore_errors' => true, 'follow_location' => 0]]);
    $start = microtime(true);
    $body = @file_get_contents($url, false, $context);
    $status = 0;
    foreach ($http_response_header ?? [] as $header) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $m)) {
            $status = (int) $m[1];
        }
    }
    ps_vlog(sprintf('GET %s -> HTTP %d (%d bytes, %.1fs)', $url, $status, strlen((string) $body), microtime(true) - $start));

    return [$status, (string) $body];
}

/**
 * HTTP request with a cookie jar (back-office session), following redirects.
 *
 * @param array<string, string>|null $post form fields for a POST request
 *
 * @return array{0: int, 1: string, 2: string} [status, body, final URL]
 */
function curlRequest(string $url, string $jar, ?array $post = null): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_COOKIEJAR => $jar,
        CURLOPT_COOKIEFILE => $jar,
        CURLOPT_TIMEOUT => 180,
    ]);
    if ($post !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $start = microtime(true);
    $body = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $final = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    // Only the field names of a POST: the login form holds the (demo) password.
    ps_vlog(sprintf(
        '%s %s -> HTTP %d%s (%d bytes, %.1fs)',
        $post !== null ? 'POST' : 'GET',
        $url,
        $status,
        $final !== $url ? ", ended at $final" : '',
        strlen($body),
        microtime(true) - $start
    ) . ($post !== null ? "\n    fields: " . implode(', ', array_keys($post)) : ''));

    return [$status, $body, $final];
}

/**
 * Hidden input fields of the first form in the HTML (login form tokens).
 *
 * @return array<string, string>
 */
function hiddenInputs(string $html): array
{
    $fields = [];
    preg_match_all('/<input[^>]*type="hidden"[^>]*>/i', $html, $inputs);
    foreach ($inputs[0] as $input) {
        if (preg_match('/name="([^"]+)"/', $input, $n)) {
            preg_match('/value="([^"]*)"/', $input, $v);
            $fields[html_entity_decode($n[1])] = html_entity_decode($v[1] ?? '');
        }
    }

    return $fields;
}

/**
 * Copies a directory recursively, skipping directories with the given names.
 *
 * @param string[] $exclude directory names to skip
 */
function copyDir(string $source, string $target, array $exclude): void
{
    $source = rtrim(str_replace('\\', '/', $source), '/');
    @mkdir($target, 0775, true);
    $it = new RecursiveIteratorIterator(
        new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS | FilesystemIterator::FOLLOW_SYMLINKS),
            static fn (SplFileInfo $f): bool => !($f->isDir() && in_array($f->getFilename(), $exclude, true))
        ),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($it as $item) {
        $dest = $target . '/' . substr(str_replace('\\', '/', $item->getPathname()), strlen($source) + 1);
        // Inside the iterator, is_dir() is false for a Windows junction (filetype "unknown", seen on
        // PHP 8.5), so a linked library would be skipped: check its real path as well.
        $real = realpath($item->getPathname());
        if (is_dir($item->getPathname()) || ($real !== false && is_dir($real))) {
            @mkdir($dest, 0775, true);
            // On Windows the iterator does not descend into junctions (ps-module link): copy them by their real path.
            if (!$it->callHasChildren()) {
                copyDir((string) realpath($item->getPathname()), $dest, $exclude);
            }
        } else {
            copy($item->getPathname(), $dest);
        }
    }
}

/**
 * Deletes a directory with everything in it.
 */
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

/**
 * The last $n lines of a text.
 */
function lastLines(string $text, int $n): string
{
    return implode("\n", array_slice(explode("\n", trim($text)), -$n));
}

/**
 * Prints a line to standard output.
 */
function out(string $message): void
{
    echo $message . "\n";
}

/**
 * Prints an error and stops the runner with exit code 1.
 */
function fail(string $message): void
{
    fwrite(STDERR, "ERROR: $message\n");
    exit(1);
}
