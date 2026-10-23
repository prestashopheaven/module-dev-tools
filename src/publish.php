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
 * Publishing a release of a module, after the definition of done: the checklist per version (REL-08).
 * Started by bin/ps-module as: php publish.php <module-dir> <command> [options], in the repo directory.
 *
 *   ps-module publish status [--check]  the checklist of the current version: addons, docs, demo (done or open);
 *                                       --check also asks the docs site which version it shows
 *   ps-module publish site              build dist/docs-site/<module>/: index.php + readme_<iso>.pdf of every language
 *   ps-module publish docs [--dry-run] [--force]
 *                                       build the site and upload it by SFTP to <DOCS_SITE_SFTP_PATH>/<module>/
 *                                       (stale files there are removed), then check <DOCS_SITE_URL>/<module>/index.php
 *   ps-module publish demo [--dry-run] [--force]
 *                                       put dist/<module>.zip in the demo shop (SSH, demo-site.sh deploy): install or
 *                                       upgrade only that module in the shop as it is (no restore), smoke test, new
 *                                       snapshot (files + database); on a failure the module and database go back
 *   ps-module demo-site check            what the server has and lacks (changes nothing)
 *   ps-module demo-site install-prestashop [<version>]   PrestaShop on the server (local cache + .db_credentials)
 *   ps-module demo-site setup|config|status|snapshot [note]|restore [<snapshot>]|test|nightly|settings|demo-mode on|off [--dry-run]
 *                                       the demo shop itself (one shop for all modules; works without a module repo):
 *                                       setup uploads the tools, writes demo-site.conf, turns on demo mode, makes the
 *                                       first snapshot and installs the nightly cron job (restore latest + test); the
 *                                       rest runs that command of demo-site.sh; config rewrites demo-site.conf from
 *                                       the settings and applies it (languages, login, cron) without a snapshot
 *   ps-module demo-site push <snapshot-dir>
 *                                       upload a snapshot made elsewhere (the local Docker copy: demo-site/server/
 *                                       snapshots/latest) and make it the server's latest: restore + test, back on a
 *                                       failure; then a VPS snapshot
 *   ps-module demo-site apply <dir> [note] [--no-snapshot] [--dry-run]
 *                                       upload a folder with apply.php (e.g. the test data of all modules, MODULES/
 *                                       test-site) and run it in the demo shop as it is: test before and after, the
 *                                       database back on a failure, else a new demo snapshot; then a VPS snapshot
 *   --env=<file>                        read the settings from this file first (e.g. demo-site/local.env)
 *   -v / --verbose (ps-module -v ...)   every setting read (credentials as ***), the SSH login, every upload, remote
 *                                       command (exit code, time) and HTTP request, and the steps of demo-site.sh on
 *                                       the server (VERBOSE=1 there)
 *   ps-module publish vps-snapshot [<description>] [--dry-run]
 *                                       TransIP snapshot of the whole demo/docs VPS (TRANSIP_*): disaster backup.
 *                                       `publish docs` and `publish demo` make one after a successful deploy (unless
 *                                       --no-snapshot). The API key is never on the VPS itself.
 *   ps-module publish shop [--dry-run] [--force]
 *                                       the module as a product in the own sales shop (webservice, SALES_SHOP_*), only
 *                                       on command: created once and always INACTIVE, with name, descriptions and
 *                                       features (Multistore: Yes) in every shop language, the cover and the framed
 *                                       English screenshots; an existing product (same reference) is not changed, a
 *                                       product with the same name stops it (--force: create anyway).
 *   ps-module publish shop --update [--dry-run]
 *                                       new version: replaces the texts (every shop language) and the images (new ones
 *                                       uploaded first, cover first, then the old ones removed) of the existing
 *                                       product and makes sure of its features; price, tax rule, categories, active
 *                                       state, stock, virtual file, visibility and reference stay. Exit 1 when there
 *                                       is no product with the module's reference. Needs products PUT, images DELETE.
 *                                       The zip goes in by hand: PrestaShop has no API for virtual product files.
 *                                       Details: src/publish-shop.php
 *   ps-module publish done addons|shop  record a manual step: dist/<module>.zip of this version is uploaded to
 *                                       PrestaShop Addons (no upload API) / put in the sales shop product by hand
 *   ps-module publish undo <step>       forget a recorded step of the current version
 *
 * docs and demo only publish a released version: tag v<version> exists (the release workflow makes it), or the
 * CI environment variable is set (the release workflow itself), or --force. demo also needs the zip of the current
 * code (definition-of-done step validate-zip), except in CI.
 *
 * Settings, from the environment or the nearest .env upwards (never in git; in CI: repository secrets):
 *   DOCS_SITE_URL                 public address of the docs site, e.g. https://modules.prestashopheaven.com
 *   DOCS_SITE_SFTP_HOST, DOCS_SITE_SFTP_PORT (22), DOCS_SITE_SFTP_USER,
 *   DOCS_SITE_SFTP_PASSWORD or DOCS_SITE_SFTP_KEY (private key file, optional DOCS_SITE_SFTP_KEY_PASSPHRASE),
 *   DOCS_SITE_SFTP_PATH           directory on the server that is the root of DOCS_SITE_URL
 *   DEMO_SITE_SSH_HOST, DEMO_SITE_SSH_PORT (22), DEMO_SITE_SSH_USER,
 *   DEMO_SITE_SSH_PASSWORD or DEMO_SITE_SSH_KEY (optional DEMO_SITE_SSH_KEY_PASSPHRASE),
 *   DEMO_SITE_TOOLS_DIR (demo-site) directory of demo-site.sh, its settings and snapshots on the server
 *   for `demo-site setup` (written to demo-site.conf): DEMO_SITE_PS_ROOT (root of the PrestaShop), DEMO_SITE_URL,
 *   DEMO_SITE_LANGUAGES ("en nl": the languages of the demo shop), DEMO_SITE_TEST_PATHS, DEMO_SITE_ADMIN_DIR,
 *   DEMO_SITE_SNAPSHOT_DIR, DEMO_SITE_KEEP (5), DEMO_SITE_PHP (php), DEMO_SITE_CRON ("30 3 * * *"),
 *   DEMO_SITE_CONNECT_TO (curl --connect-to for the smoke test; empty: found itself), DEMO_SITE_KEEP_PATHS (folders
 *   in the shop root that are not part of the demo; .well-known and docs folders are always kept), DEMO_SITE_DEMO_MODE (on), DEMO_SITE_EMAIL
 *   (demo@demo.com) + DEMO_SITE_PASSWORD (demodemo): the public demo login, DEMO_SITE_INTRO_PATH (start): intro page
 *   TRANSIP_LOGIN, TRANSIP_PRIVATE_KEY (file, or the key itself), TRANSIP_VPS_NAME (technical name, e.g. login-vps2), TRANSIP_SNAPSHOT_KEEP (2: the oldest is always kept),
 *   TRANSIP_GLOBAL_KEY (true; false = the key only works from whitelisted IPs)
 *   CLOUDFLARE_API_TOKEN (permission Zone > Cache Purge) + CLOUDFLARE_ZONE_ID: after `publish docs` / `publish demo`
 *   the changed files (PDFs, logo, views/) are purged from the Cloudflare cache; without them the addresses are listed
 *   SALES_SHOP_URL + SALES_SHOP_WEBSERVICE_KEY, optional SALES_SHOP_CATEGORY_ID (2) and SALES_SHOP_TAX_RULES_GROUP_ID:
 *   the own sales shop of `publish shop`
 * In CI the key can be given as text: DOCS_SITE_SFTP_KEY / DEMO_SITE_SSH_KEY may hold the key itself.
 * The recorded steps are in <repo>/.cache/publish/<version>.json (local, not in git).
 */

declare(strict_types=1);

require_once __DIR__ . '/dod.php';
require_once __DIR__ . '/verbose.php';
require_once __DIR__ . '/publish-shop.php';
require_once __DIR__ . '/lock.php';

const PUBLISH_STEPS = ['addons', 'docs', 'demo', 'shop'];

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__)) {
    exit(publish_main(array_slice($argv, 1)));
}

/**
 * Entry point: parses the arguments and runs a command; returns the exit code.
 *
 * @param string[] $argv module directory, command and options
 */
function publish_main(array $argv): int
{
    // Everything printed goes through the mask: host names, users, passwords and keys from the settings never show
    // up in the output (it may be pasted somewhere, or read by an assistant).
    ob_start('publish_mask', 1);
    $moduleDir = dod_norm((string) array_shift($argv));
    $command = array_shift($argv) ?? 'help';
    $flags = array_values(array_filter($argv, static fn (string $a): bool => str_starts_with($a, '--')));
    $args = array_values(array_filter($argv, static fn (string $a): bool => !str_starts_with($a, '--')));
    foreach ($flags as $flag) {
        if (str_starts_with($flag, '--env=')) {
            // Settings of another target (e.g. the local Docker demo shop): read before the environment and .env.
            $file = substr($flag, 6);
            if (!is_file($file)) {
                fwrite(STDERR, "ps-module publish: settings file $file not found.\n");

                return 2;
            }
            publish_env_file($file);
        }
    }
    $repoDir = is_dir($moduleDir) ? dod_repo_dir($moduleDir) : null;
    if ($repoDir === null && $command !== 'demo-site' && $command !== 'vps-snapshot') {
        fwrite(STDERR, "ps-module publish: no module repository found (module.spec.json).\n");

        return 2;
    }
    // The demo shop and the VPS are not part of a module: without a module repository the settings come from the
    // environment, --env and the .env files above the current directory.
    $ctx = $repoDir !== null ? publish_context(dod_norm($repoDir), $moduleDir) : publish_context_without_module($moduleDir);
    $dryRun = in_array('--dry-run', $flags, true);
    // Read every credential once before anything is printed, so the mask knows them all from the first line on
    // (a folder setting can contain the SSH user, for example).
    publish_register_secrets($ctx);
    ps_vlog("publish $command " . implode(' ', array_merge($args, $flags)) . ($ctx['module'] !== '' ? " (module {$ctx['module']} {$ctx['version']}, repository {$ctx['repo']})" : " (no module, settings found upwards from {$ctx['repo']})"));
    if (publish_env_file() !== []) {
        ps_vlog('settings file (--env): ' . count(publish_env_file()) . ' setting(s); server settings come only from it');
    }

    try {
        // Commands of other modules (other terminals, other Claude sessions) that use the same server wait here.
        $lock = publish_lock_name($command, $flags, $dryRun);
        if ($lock !== null) {
            ps_lock($lock, trim("publish $command " . implode(' ', $args)) . ($ctx['module'] !== '' ? " ({$ctx['module']} {$ctx['version']})" : ''), $ctx['repo']);
        }
        switch ($command) {
            case 'status':
                return publish_cmd_status($ctx, in_array('--check', $flags, true));
            case 'site':
                publish_build_site($ctx);

                return 0;
            case 'docs':
                $code = publish_cmd_docs($ctx, $dryRun, in_array('--force', $flags, true));

                return $code === 0 && !$dryRun && !in_array('--no-snapshot', $flags, true) ? publish_after_deploy($ctx, 'docs') : $code;
            case 'demo':
                $code = publish_cmd_demo($ctx, $dryRun, in_array('--force', $flags, true));

                return $code === 0 && !$dryRun && !in_array('--no-snapshot', $flags, true) ? publish_after_deploy($ctx, 'demo') : $code;
            case 'shop':
                return publish_cmd_shop($ctx, $dryRun, in_array('--force', $flags, true), in_array('--update', $flags, true));
            case 'demo-site':
                return publish_cmd_demo_site($ctx, $args, $dryRun, $flags);
            case 'vps-snapshot':
                return publish_cmd_vps_snapshot($ctx, (string) ($args[0] ?? 'manual snapshot'), $dryRun) ? 0 : 1;
            case 'done':
                return publish_cmd_done($ctx, (string) ($args[0] ?? ''));
            case 'undo':
                return publish_cmd_undo($ctx, (string) ($args[0] ?? ''));
            default:
                $help = (string) file_get_contents(__FILE__);
                preg_match('#/\*\n(.*?)\*/#s', substr($help, (int) strpos($help, '/*', 10)), $m);
                echo trim((string) preg_replace('/^ \* ?/m', '', $m[1] ?? '')) . "\n";

                return $command === 'help' ? 0 : 2;
        }
    } catch (RuntimeException $e) {
        fwrite(STDERR, 'ps-module publish: ' . publish_mask($e->getMessage()) . "\n");

        return 1;
    }
}

/**
 * The shared resource a command needs for itself (lock.php), or null: the demo server (docs site, demo shop, VPS
 * snapshot: one VPS) or the sales shop. A dry run changes nothing and does not wait. With --env=<file> the commands go
 * to another server (e.g. the local Docker demo shop): that one has its own lock.
 *
 * @param string[] $flags
 */
function publish_lock_name(string $command, array $flags, bool $dryRun): ?string
{
    if ($dryRun) {
        return null;
    }
    $resource = match ($command) {
        'docs', 'demo', 'demo-site', 'vps-snapshot' => 'demo-server',
        'shop' => 'sales-shop',
        default => null,
    };
    foreach ($flags as $flag) {
        if ($resource !== null && str_starts_with($flag, '--env=')) {
            $file = substr($flag, 6);
            $resource .= '-' . substr(sha1(str_replace('\\', '/', (string) (realpath($file) ?: $file))), 0, 8);
        }
    }

    return $resource;
}

/**
 * Replaces every secret setting read so far (hosts, users, passwords, keys, TransIP login) with ***.
 */
function publish_mask(string $text): string
{
    $secrets = publish_secrets();
    if ($secrets === []) {
        return $text;
    }
    // Longest first, so a value that contains another one is masked as a whole. Only whole words: a user "demo" must
    // not turn "demo-site.sh" into "***-site.sh".
    usort($secrets, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
    $words = array_map(static fn (string $v): string => preg_quote($v, '/'), $secrets);

    return (string) preg_replace('/(?<![A-Za-z0-9-])(?:' . implode('|', $words) . ')(?![A-Za-z0-9-])/', '***', $text);
}

/**
 * The secret setting values read so far; with an argument: registers one more (and each long line of a key).
 *
 * @return string[]
 */
function publish_secrets(?string $value = null): array
{
    static $values = [];
    // localhost and loopback addresses say nothing about a server: masking them would only hide useful output.
    if ($value !== null && strlen($value) >= 3 && !in_array(strtolower(trim($value)), ['localhost', '127.0.0.1', '::1'], true)) {
        $values[$value] = $value;
        foreach (preg_split('/\R/', $value) ?: [] as $line) {
            if (strlen(trim($line)) >= 8) {
                $values[trim($line)] = trim($line);
            }
        }
    }

    return array_values($values);
}

/**
 * Reads every credential setting (hosts, users, passwords, keys, TransIP login) and the database settings of
 * .db_credentials when present, so the mask hides them from the first printed line on. Nothing is printed here.
 *
 * @param array<string, mixed> $ctx
 */
function publish_register_secrets(array $ctx): void
{
    foreach (['DEMO_SITE_SSH', 'DOCS_SITE_SFTP'] as $prefix) {
        foreach (['_HOST', '_USER', '_PASSWORD', '_KEY', '_KEY_PASSPHRASE'] as $suffix) {
            publish_env_lookup_quiet($ctx, $prefix . $suffix);
        }
    }
    foreach (['TRANSIP_LOGIN', 'TRANSIP_PRIVATE_KEY', 'CLOUDFLARE_API_TOKEN', 'SALES_SHOP_WEBSERVICE_KEY'] as $key) {
        publish_env_lookup_quiet($ctx, $key);
    }
    try {
        publish_db_credentials($ctx);
    } catch (RuntimeException $e) {
        // No (complete) .db_credentials: only install-prestashop needs it, and it reports that itself.
    }
}

/**
 * Reads a setting so it is registered as a secret, without a verbose line about it.
 *
 * @param array<string, mixed> $ctx
 */
function publish_env_lookup_quiet(array $ctx, string $key): void
{
    $value = publish_env_lookup($ctx, $key);
    if ($value !== '') {
        publish_secrets($value);
    }
}

/**
 * What the commands need about the module: paths, version, names and languages.
 *
 * @return array{repo: string, module_dir: string, module: string, version: string, spec: array<string, mixed>, languages: string[], catalog: array<string, array<string, mixed>>, ps_min: string, ps_tested: string}
 */
function publish_context(string $repoDir, string $moduleDir): array
{
    $module = basename($moduleDir);
    $main = (string) @file_get_contents("$moduleDir/$module.php");
    preg_match('/\$this->version\s*=\s*[\'"]([^\'"]+)/', $main, $version);
    preg_match('/\'min\'\s*=>\s*[\'"]([\d.]+)/', $main, $psMin);
    $spec = publish_json("$repoDir/module.spec.json");
    $i18n = publish_json("$repoDir/i18n.json");
    $testing = publish_json("$repoDir/testing.json");
    $source = (string) ($i18n['source'] ?? 'en');
    $tested = array_map('strval', (array) ($testing['versions'] ?? []));
    usort($tested, 'version_compare');

    return [
        'repo' => $repoDir,
        'module_dir' => $moduleDir,
        'module' => $module,
        'version' => $version[1] ?? '0.0.0',
        'spec' => $spec,
        'languages' => array_values(array_unique(array_merge([$source], array_map('strval', (array) ($i18n['languages'] ?? []))))),
        'catalog' => (array) (publish_json(__DIR__ . '/../resources/languages.json')['languages'] ?? []),
        'ps_min' => $psMin[1] ?? (string) ($spec['ps_min'] ?? ''),
        'ps_tested' => $tested !== [] ? (string) end($tested) : ($psMin[1] ?? ''),
    ];
}

/**
 * Context for the commands that do not belong to a module (demo-site, vps-snapshot): the directory is only used to
 * find .env files upwards.
 *
 * @return array{repo: string, module_dir: string, module: string, version: string, spec: array<string, mixed>, languages: string[], catalog: array<string, array<string, mixed>>, ps_min: string, ps_tested: string}
 */
function publish_context_without_module(string $dir): array
{
    return ['repo' => dod_norm($dir), 'module_dir' => dod_norm($dir), 'module' => '', 'version' => '', 'spec' => [], 'languages' => [], 'catalog' => [], 'ps_min' => '', 'ps_tested' => ''];
}

/**
 * Reads a JSON file as an array; an empty array when it is missing or invalid.
 *
 * @return array<string, mixed>
 */
function publish_json(string $file): array
{
    $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;

    return is_array($data) ? $data : [];
}

// ---------------------------------------------------------------------------
// Checklist

/**
 * Prints the checklist of the current version; exit code 0 when every step is done.
 *
 * @param array<string, mixed> $ctx
 */
function publish_cmd_status(array $ctx, bool $check): int
{
    $state = publish_state($ctx);
    $how = [
        'addons' => 'upload ../dist/' . $ctx['module'] . '.zip in the Addons seller account, then: vendor/bin/ps-module publish done addons',
        'docs' => 'vendor/bin/ps-module publish docs' . (publish_env($ctx, 'DOCS_SITE_SFTP_HOST') === '' ? '   (needs DOCS_SITE_SFTP_* in .env)' : ''),
        'demo' => 'vendor/bin/ps-module publish demo' . (publish_env($ctx, 'DEMO_SITE_SSH_HOST') === '' ? '   (needs DEMO_SITE_SSH_* in .env)' : ''),
        'shop' => 'vendor/bin/ps-module publish shop (creates the product once, inactive; a later version: publish shop --update for texts and images, put the zip in it by hand, then publish done shop)'
            . (publish_env($ctx, 'SALES_SHOP_URL') === '' ? '   (needs SALES_SHOP_* in .env)' : ''),
    ];
    $labels = ['addons' => 'Addons upload', 'docs' => 'Docs site (PDFs)', 'demo' => 'Demo shop', 'shop' => 'Sales shop'];
    echo "Publish checklist for {$ctx['module']} {$ctx['version']}:\n";
    $open = 0;
    foreach (PUBLISH_STEPS as $step) {
        $done = $state[$step] ?? null;
        if ($done === null) {
            ++$open;
        }
        printf("  %-17s %-5s %s\n", $labels[$step], $done === null ? 'open' : 'done', $done === null ? $how[$step] : $done['time'] . ($done['note'] !== '' ? ' - ' . $done['note'] : ''));
    }
    if ($check && publish_env($ctx, 'DOCS_SITE_URL') !== '') {
        $live = publish_live_version(publish_docs_url($ctx));
        echo '  Live docs site:   ' . ($live === null ? 'not reachable or no module-version' : "shows $live") . ' (' . publish_docs_url($ctx) . ")\n";
    }
    echo $open === 0 ? "All published.\n" : "$open step(s) open.\n";

    return $open === 0 ? 0 : 1;
}

/**
 * Records a manual step (addons) as done for the current version.
 *
 * @param array<string, mixed> $ctx
 */
function publish_cmd_done(array $ctx, string $step): int
{
    if ($step !== 'addons' && $step !== 'shop') {
        fwrite(STDERR, "ps-module publish done: only 'addons' and 'shop' are manual steps (docs and demo record themselves).\n");

        return 2;
    }
    publish_record($ctx, $step, $step === 'addons' ? 'uploaded by hand' : 'zip put in the product by hand');
    echo $step === 'addons'
        ? "Recorded: {$ctx['module']} {$ctx['version']} is uploaded to PrestaShop Addons.\n"
        : "Recorded: the sales shop product of {$ctx['module']} has the zip of {$ctx['version']}.\n";

    return 0;
}

/**
 * Forgets a recorded step of the current version.
 *
 * @param array<string, mixed> $ctx
 */
function publish_cmd_undo(array $ctx, string $step): int
{
    if (!in_array($step, PUBLISH_STEPS, true)) {
        fwrite(STDERR, 'ps-module publish undo: step must be one of ' . implode(', ', PUBLISH_STEPS) . ".\n");

        return 2;
    }
    $state = publish_state($ctx);
    unset($state[$step]);
    publish_write_state($ctx, $state);
    echo "Forgot step '$step' of {$ctx['version']}.\n";

    return 0;
}

/**
 * The recorded steps of the current version.
 *
 * @param array<string, mixed> $ctx
 *
 * @return array<string, array{time: string, note: string}>
 */
function publish_state(array $ctx): array
{
    $state = [];
    foreach (publish_json(publish_state_file($ctx)) as $step => $data) {
        if (in_array($step, PUBLISH_STEPS, true) && is_array($data)) {
            $state[$step] = ['time' => (string) ($data['time'] ?? ''), 'note' => (string) ($data['note'] ?? '')];
        }
    }

    return $state;
}

/**
 * File with the recorded steps of the current version.
 *
 * @param array<string, mixed> $ctx
 */
function publish_state_file(array $ctx): string
{
    return $ctx['repo'] . '/.cache/publish/' . $ctx['version'] . '.json';
}

/**
 * Records a step of the current version as done, with a short note.
 *
 * @param array<string, mixed> $ctx
 */
function publish_record(array $ctx, string $step, string $note): void
{
    $state = publish_state($ctx);
    $state[$step] = ['time' => date('c'), 'note' => $note];
    publish_write_state($ctx, $state);
}

/**
 * Writes the recorded steps of the current version.
 *
 * @param array<string, mixed> $ctx
 * @param array<string, array{time: string, note: string}> $state
 */
function publish_write_state(array $ctx, array $state): void
{
    $file = publish_state_file($ctx);
    if (!is_dir(dirname($file))) {
        mkdir(dirname($file), 0775, true);
    }
    file_put_contents($file, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
}

// ---------------------------------------------------------------------------
// Docs site

/**
 * Builds dist/docs-site/<module>/: index.php (from resources/publish/index.php) and the PDF manual of every
 * language of i18n.json (docs/manual/<iso>/readme_<iso>.pdf, made by `ps-module docs build`). Returns the directory.
 *
 * @param array<string, mixed> $ctx
 *
 * @throws RuntimeException when a manual PDF is missing
 */
function publish_build_site(array $ctx): string
{
    $missing = [];
    foreach ($ctx['languages'] as $iso) {
        if (!is_file($ctx['repo'] . "/docs/manual/$iso/readme_$iso.pdf")) {
            $missing[] = "docs/manual/$iso/readme_$iso.pdf";
        }
    }
    if ($missing !== []) {
        throw new RuntimeException('manual PDF missing (run vendor/bin/ps-module docs build): ' . implode(', ', $missing));
    }
    $dir = $ctx['repo'] . '/dist/docs-site/' . $ctx['module'];
    if (is_dir($dir)) {
        foreach (glob("$dir/*") ?: [] as $old) {
            unlink($old);
        }
    } else {
        mkdir($dir, 0775, true);
    }
    $spec = $ctx['spec'];
    $translations = (array) ($spec['translations'] ?? []);
    $languages = [];
    foreach ($ctx['languages'] as $iso) {
        $pdf = "readme_$iso.pdf";
        copy($ctx['repo'] . "/docs/manual/$iso/$pdf", "$dir/$pdf");
        ps_vlog("docs site: docs/manual/$iso/$pdf (" . publish_size((int) filesize("$dir/$pdf")) . ')');
        $entry = (array) ($ctx['catalog'][$iso] ?? []);
        $languages[$iso] = [
            'native' => (string) ($entry['native'] ?? $entry['name'] ?? $iso),
            'name' => (string) ($entry['name'] ?? $iso),
            'rtl' => !empty($entry['rtl']),
            'display_name' => (string) ($translations[$iso]['display_name'] ?? $spec['display_name'] ?? $ctx['module']),
            'description' => (string) ($translations[$iso]['description'] ?? $spec['description'] ?? ''),
            'pdf' => $pdf,
            'size' => (int) filesize("$dir/$pdf"),
        ];
    }
    $site = [
        'module' => $ctx['module'],
        'display_name' => (string) ($spec['display_name'] ?? $ctx['module']),
        'version' => $ctx['version'],
        'ps_min' => $ctx['ps_min'],
        'ps_tested' => $ctx['ps_tested'],
        'built' => date('c'),
        'languages' => $languages,
    ];
    $template = (string) file_get_contents(__DIR__ . '/../resources/publish/index.php');
    $marker = "/* @site */ ['module' => '', 'display_name' => '', 'version' => '', 'ps_min' => '', 'ps_tested' => '', 'built' => '', 'languages' => []]";
    if (!str_contains($template, $marker)) {
        throw new RuntimeException('resources/publish/index.php has no /* @site */ marker.');
    }
    file_put_contents("$dir/index.php", str_replace($marker, var_export($site, true), $template));
    $size = array_sum(array_column($languages, 'size'));
    printf("Docs site built: %s (index.php + %d PDF(s), %.1f MB)\n", substr($dir, strlen($ctx['repo']) + 1), count($languages), $size / 1048576);

    return $dir;
}

/**
 * Builds the docs site and uploads it to <DOCS_SITE_SFTP_PATH>/<module>/; records step 'docs' when the live page
 * shows the new version (or when DOCS_SITE_URL is not set, after the upload).
 *
 * @param array<string, mixed> $ctx
 */
function publish_cmd_docs(array $ctx, bool $dryRun, bool $force): int
{
    publish_release_guard($ctx, $force);
    $dir = publish_build_site($ctx);
    $remote = rtrim(publish_require_env($ctx, 'DOCS_SITE_SFTP_PATH'), '/') . '/' . $ctx['module'];
    $files = array_map('basename', glob("$dir/*") ?: []);
    if ($dryRun) {
        echo "Dry run: would upload " . count($files) . " file(s) to $remote/ on the docs server and remove other files there.\n";

        return 0;
    }
    $sftp = publish_connect($ctx, 'DOCS_SITE_SFTP');
    if (!$sftp->is_dir($remote) && !$sftp->mkdir($remote, 0755, true)) {
        throw new RuntimeException("cannot create $remote on the docs server.");
    }
    foreach ($files as $file) {
        if (!$sftp->put("$remote/$file", "$dir/$file", \phpseclib3\Net\SFTP::SOURCE_LOCAL_FILE)) {
            throw new RuntimeException("upload of $file failed.");
        }
        echo "  uploaded $file\n";
    }
    // Marker: when the docs share a folder with the demo shop, its nightly reset leaves this folder alone.
    if (!$sftp->put("$remote/.docs-site", "docs of {$ctx['module']} (ps-module publish docs)\n")) {
        throw new RuntimeException('upload of .docs-site failed.');
    }
    foreach ($sftp->nlist($remote) ?: [] as $file) {
        if (!in_array($file, ['.', '..', '.docs-site'], true) && !in_array($file, $files, true) && $sftp->is_file("$remote/$file")) {
            $sftp->delete("$remote/$file");
            echo "  removed stale $file\n";
        }
    }
    $note = "uploaded to $remote";
    if (publish_env($ctx, 'DOCS_SITE_URL') !== '') {
        $url = publish_docs_url($ctx);
        $live = publish_live_version($url);
        if ($live !== $ctx['version']) {
            throw new RuntimeException("uploaded, but $url shows " . ($live ?? 'no module-version') . " instead of {$ctx['version']} (check DOCS_SITE_SFTP_PATH).");
        }
        $note = $url;
    }
    publish_record($ctx, 'docs', $note);
    echo "Docs site published: $note\n";
    if (publish_env($ctx, 'DOCS_SITE_URL') !== '') {
        $folder = rtrim(publish_env($ctx, 'DOCS_SITE_URL'), '/') . '/' . $ctx['module'] . '/';
        publish_cloudflare_purge($ctx, array_merge([$folder], array_map(static fn (string $f): string => $folder . rawurlencode($f), $files)));
    }
    publish_demo_refresh_intro($ctx);

    return 0;
}

/**
 * After publish docs: rewrite the intro page of the demo shop (when DEMO_SITE_SSH_HOST is set), so it links the new
 * docs site right away instead of after the nightly reset. Only the intro page changes; a failure is a warning.
 *
 * @param array<string, mixed> $ctx
 */
function publish_demo_refresh_intro(array $ctx): void
{
    if (publish_env($ctx, 'DEMO_SITE_SSH_HOST') === '') {
        ps_vlog('no DEMO_SITE_SSH_HOST: the intro page of the demo shop links the docs after its next reset');

        return;
    }
    try {
        echo "Demo shop: rewriting the intro page (link to the docs site)...\n";
        $tools = publish_demo_tools_dir($ctx);
        $ssh = publish_connect($ctx, 'DEMO_SITE_SSH');
        publish_demo_upload_tools($ssh, $tools, false);
        [, $exit] = publish_demo_run($ssh, $tools, 'settings intro');
        if ($exit !== 0) {
            throw new RuntimeException("demo-site.sh settings intro failed (exit $exit)");
        }
    } catch (RuntimeException $e) {
        fwrite(STDERR, 'Warning: the intro page of the demo shop was not rewritten (' . publish_mask($e->getMessage()) . "); it links the docs after the next nightly reset, or run: ps-module demo-site settings intro\n");
    }
}

/**
 * Address of the docs page of the module.
 *
 * @param array<string, mixed> $ctx
 */
function publish_docs_url(array $ctx): string
{
    return rtrim(publish_env($ctx, 'DOCS_SITE_URL'), '/') . '/' . $ctx['module'] . '/index.php';
}

/**
 * The version a docs page shows (meta module-version), or null when the page cannot be read.
 */
function publish_live_version(string $url): ?string
{
    $page = publish_http_get($url . '?v=' . time());
    if ($page === null || preg_match('/<meta name="module-version" content="([^"]+)"/', $page['body'], $m) !== 1) {
        return null;
    }

    return $page['status'] === 200 ? html_entity_decode($m[1]) : null;
}

// ---------------------------------------------------------------------------
// Demo shop

/**
 * Puts the release zip in the demo shop: uploads the zip and the demo tools (resources/publish/demo-site.sh,
 * demo-install.php) to DEMO_SITE_TOOLS_DIR, then runs `demo-site.sh deploy` there: install or upgrade only this module
 * in the shop as it is (no restore: the other files and settings stay), intro page, smoke test, new snapshot (files +
 * database). On a failure the server puts the module folder and the database of before back. Records step 'demo'.
 *
 * @param array<string, mixed> $ctx
 */
function publish_cmd_demo(array $ctx, bool $dryRun, bool $force): int
{
    publish_release_guard($ctx, $force);
    $zip = $ctx['repo'] . '/dist/' . $ctx['module'] . '.zip';
    if (!is_file($zip)) {
        throw new RuntimeException('dist/' . $ctx['module'] . '.zip is missing: vendor/bin/ps-module build');
    }
    if (getenv('CI') === false && (dod_status($ctx['repo'])['steps']['validate-zip']['state'] ?? '') !== 'ok') {
        throw new RuntimeException('the zip is not the checked zip of the current code: vendor/bin/ps-module build && vendor/bin/ps-module validate-zip');
    }
    $tools = publish_demo_tools_dir($ctx);
    ps_vlog(sprintf('zip: %s (%s, built %s), checked zip of the current code', $zip, publish_size((int) filesize($zip)), date('Y-m-d H:i', (int) filemtime($zip))));
    if ($dryRun) {
        echo "Dry run: would upload {$ctx['module']}.zip to the demo server:$tools/uploads/ and run demo-site.sh deploy (install/upgrade only this module, test, new snapshot; nothing else in the shop changes).\n";

        return 0;
    }
    $ssh = publish_connect($ctx, 'DEMO_SITE_SSH');
    publish_demo_upload_tools($ssh, $tools, false);
    $remoteZip = "$tools/uploads/{$ctx['module']}-{$ctx['version']}.zip";
    if (!$ssh->put($remoteZip, $zip, \phpseclib3\Net\SFTP::SOURCE_LOCAL_FILE)) {
        throw new RuntimeException("upload of the zip to $remoteZip failed.");
    }
    [$output, $exit] = publish_demo_run($ssh, $tools, 'deploy ' . escapeshellarg($remoteZip) . ' ' . escapeshellarg($ctx['module']));
    $ssh->delete($remoteZip);
    if ($exit !== 0 || preg_match_all('/^DEMO-OK (\S*)$/m', $output, $m) < 1) {
        // demo-site.sh says itself when it put the snapshot of before back (a failed install or test).
        throw new RuntimeException('the deploy failed on the server (exit ' . var_export($exit, true) . '): see the server output above.');
    }
    $version = (string) end($m[1]);
    if ($version !== $ctx['version']) {
        throw new RuntimeException("the demo shop reports version $version instead of {$ctx['version']}.");
    }
    $url = publish_env($ctx, 'DEMO_SITE_URL');
    publish_record($ctx, 'demo', "version $version" . ($url !== '' ? " on $url" : '') . ', new snapshot');
    echo "Demo shop has {$ctx['module']} $version and a new snapshot" . ($url !== '' ? " ($url)" : '') . ".\n";
    if ($url !== '') {
        publish_cloudflare_purge($ctx, publish_module_asset_urls($zip, $ctx['module'], $url));
    }

    return 0;
}

/**
 * The public addresses of the static files of a module in a shop (logo.*, views/**: images, CSS, JS, fonts), from its
 * release zip: what a CDN may still serve from an older version after an upgrade.
 *
 * @return list<string>
 */
function publish_module_asset_urls(string $zipFile, string $module, string $shopUrl): array
{
    $zip = new ZipArchive();
    if ($zip->open($zipFile) !== true) {
        return [];
    }
    $base = rtrim($shopUrl, '/') . '/modules/';
    $urls = [];
    for ($i = 0; $i < $zip->numFiles; ++$i) {
        $name = (string) $zip->getNameIndex($i);
        if (preg_match('#^' . preg_quote($module, '#') . '/(logo\.(png|gif|svg|jpg)|views/.+\.(png|gif|svg|jpe?g|webp|ico|css|js|woff2?|ttf|eot|map))$#i', $name) === 1) {
            $urls[] = $base . implode('/', array_map('rawurlencode', explode('/', $name)));
        }
    }
    $zip->close();
    ps_vlog(count($urls) . " static file address(es) of $module for the CDN purge");

    return $urls;
}

/**
 * The demo shop itself (not one module): `ps-module demo-site setup` uploads the tools and writes demo-site.conf
 * from the DEMO_SITE_* settings, makes the first snapshot, installs the nightly cron job and tests the shop.
 * status, snapshot, restore [<name>], test and nightly run demo-site.sh with that command.
 *
 * @param array<string, mixed> $ctx
 * @param string[] $args
 * @param string[] $flags
 */
function publish_cmd_demo_site(array $ctx, array $args, bool $dryRun, array $flags = []): int
{
    $command = (string) ($args[0] ?? 'status');
    if (!in_array($command, ['check', 'install-prestashop', 'setup', 'status', 'snapshot', 'restore', 'test', 'nightly', 'demo-mode', 'settings', 'config', 'push', 'apply', 'vps-snapshot'], true)) {
        fwrite(STDERR, "ps-module demo-site: check | install-prestashop | setup | status | snapshot [note] | restore [<snapshot>] | test | nightly | demo-mode on|off | settings | config | push <snapshot-dir> | apply <dir> [note] | vps-snapshot [<description>]\n");

        return 2;
    }
    // The database settings of the server are masked in all output too: publish_main() registered them
    // (publish_register_secrets()).
    if ($command === 'vps-snapshot') {
        return publish_cmd_vps_snapshot($ctx, (string) ($args[1] ?? 'manual snapshot ' . date('Y-m-d H:i')), $dryRun) ? 0 : 1;
    }
    if ($command === 'check') {
        // Before setup: what the server has and what is missing (commands, PHP extensions, web server, shop folder).
        $tools = publish_demo_tools_dir($ctx);
        $ssh = publish_connect($ctx, 'DEMO_SITE_SSH');
        if (!$ssh->is_dir($tools) && !$ssh->mkdir($tools, 0700, true)) {
            throw new RuntimeException("cannot create $tools.");
        }
        if (!$ssh->put("$tools/demo-server-check.sh", __DIR__ . '/../resources/publish/demo-server-check.sh', \phpseclib3\Net\SFTP::SOURCE_LOCAL_FILE)) {
            throw new RuntimeException('upload of demo-server-check.sh failed.');
        }
        $ssh->setTimeout(120);
        $output = (string) $ssh->exec((ps_verbose() ? 'VERBOSE=1 ' : '') . 'DEMO_SITE_PHP=' . escapeshellarg(publish_env($ctx, 'DEMO_SITE_PHP') ?: 'php') . ' bash '
            . escapeshellarg("$tools/demo-server-check.sh") . ' ' . escapeshellarg(publish_env($ctx, 'DEMO_SITE_PS_ROOT'))
            . ' ' . escapeshellarg(rtrim(publish_env($ctx, 'DEMO_SITE_URL'), '/')) . ' 2>&1');
        echo rtrim($output) . "\n";

        return 0;
    }
    if ($command === 'install-prestashop') {
        return publish_demo_install_prestashop($ctx, (string) ($args[1] ?? ''), $dryRun);
    }
    if ($command === 'push') {
        return publish_demo_push($ctx, (string) ($args[1] ?? ''), $dryRun);
    }
    if ($command === 'apply') {
        return publish_demo_apply($ctx, (string) ($args[1] ?? ''), (string) ($args[2] ?? ''), $dryRun, in_array('--no-snapshot', $flags, true));
    }
    if ($command === 'config') {
        // A changed setting (languages, URL, login, cron time): rewrite demo-site.conf and apply it, without a new
        // snapshot. Make one afterwards when the change should survive the nightly reset.
        $tools = publish_demo_tools_dir($ctx);
        $ssh = publish_connect($ctx, 'DEMO_SITE_SSH');
        publish_demo_upload_tools($ssh, $tools, false);
        if (!$ssh->put("$tools/demo-site.conf", publish_demo_conf($ctx))) {
            throw new RuntimeException("cannot write $tools/demo-site.conf.");
        }
        foreach (['settings', 'install-cron'] as $step) {
            [, $exit] = publish_demo_run($ssh, $tools, $step);
            if ($exit !== 0) {
                return 1;
            }
        }
        echo "Settings applied. Make a snapshot to keep them after the nightly reset: ps-module demo-site snapshot \"<note>\"\n";

        return 0;
    }
    $tools = publish_demo_tools_dir($ctx);
    if ($command === 'setup') {
        $conf = publish_demo_conf($ctx);
        if ($dryRun) {
            echo "Dry run: would upload the demo tools to $tools/ with this demo-site.conf, make a snapshot, install the cron job and test:\n$conf";

            return 0;
        }
        $ssh = publish_connect($ctx, 'DEMO_SITE_SSH');
        publish_demo_upload_tools($ssh, $tools, true);
        if (!$ssh->put("$tools/demo-site.conf", $conf)) {
            throw new RuntimeException("cannot write $tools/demo-site.conf.");
        }
        $steps = ['settings', 'snapshot ' . escapeshellarg('setup'), 'install-cron', 'test', 'status'];
        if (publish_env($ctx, 'DEMO_SITE_DEMO_MODE') !== 'off') {
            array_unshift($steps, 'demo-mode on');
        }
        foreach ($steps as $step) {
            [, $exit] = publish_demo_run($ssh, $tools, $step);
            if ($exit !== 0) {
                throw new RuntimeException("demo-site.sh $step failed (exit $exit).");
            }
        }
        echo "Demo shop ready: nightly reset + test, snapshots in the tools directory ($tools).\n";

        return 0;
    }
    $ssh = publish_connect($ctx, 'DEMO_SITE_SSH');
    publish_demo_upload_tools($ssh, $tools, false);
    $extra = implode(' ', array_map('escapeshellarg', array_slice($args, 1)));
    [, $exit] = publish_demo_run($ssh, $tools, $command . ($extra !== '' ? " $extra" : ''));

    return $exit === 0 ? 0 : 1;
}

/**
 * Directory of the demo tools on the server (DEMO_SITE_TOOLS_DIR, default demo-site in the home of the SSH user).
 *
 * @param array<string, mixed> $ctx
 */
function publish_demo_tools_dir(array $ctx): string
{
    return rtrim(publish_env($ctx, 'DEMO_SITE_TOOLS_DIR') ?: 'demo-site', '/');
}

/**
 * Uploads demo-site.sh and demo-install.php (always the version of this package). Without $setup the settings file
 * must already be there (made by `ps-module demo-site setup`).
 *
 * @throws RuntimeException when the upload fails or the demo shop is not set up
 */
function publish_demo_upload_tools(\phpseclib3\Net\SFTP $ssh, string $tools, bool $setup): void
{
    if (!$setup && !$ssh->is_file("$tools/demo-site.conf")) {
        throw new RuntimeException("the demo shop is not set up ($tools/demo-site.conf missing): vendor/bin/ps-module demo-site setup");
    }
    if (!$ssh->is_dir("$tools/uploads") && !$ssh->mkdir("$tools/uploads", 0700, true)) {
        throw new RuntimeException("cannot create $tools/uploads on the demo server.");
    }
    foreach (['demo-site.sh', 'demo-install.php', 'demo-settings.php'] as $file) {
        $local = str_replace("\r\n", "\n", (string) file_get_contents(__DIR__ . '/../resources/publish/' . $file));
        if (!$ssh->put("$tools/$file", $local)) {
            throw new RuntimeException("upload of $file failed.");
        }
    }
    // The brand of the workspace (branding/, found upwards from here): logo.svg for the back office and the intro
    // page, a PNG of it (brand.json > logo_png) for the shop logo and the login page. Kept on the server.
    foreach (publish_brand_files() as $name => $local) {
        if (!$ssh->put("$tools/$name", $local, \phpseclib3\Net\SFTP::SOURCE_LOCAL_FILE)) {
            throw new RuntimeException("upload of $name failed.");
        }
    }
    $ssh->chmod(0755, "$tools/demo-site.sh");
}

/**
 * The brand files for the demo shop from the workspace's branding/ (searched upwards from the current directory):
 * brand-logo.svg (branding/logo.svg) and brand-logo.png (brand.json > logo_png). Empty without branding/.
 *
 * @return array<string, string> name on the server => local file
 */
function publish_brand_files(): array
{
    for ($dir = (string) getcwd(), $i = 0; $i < 6 && $dir !== dirname($dir); $dir = dirname($dir), ++$i) {
        if (!is_file("$dir/branding/brand.json")) {
            continue;
        }
        $brand = json_decode((string) file_get_contents("$dir/branding/brand.json"), true);
        $files = [];
        $svg = "$dir/branding/" . (string) ($brand['logo'] ?? 'logo.svg');
        if (is_file($svg)) {
            $files['brand-logo.svg'] = $svg;
        }
        $png = "$dir/branding/" . (string) ($brand['logo_png'] ?? $brand['logo_original'] ?? '');
        if (($brand['logo_png'] ?? $brand['logo_original'] ?? '') !== '' && is_file($png) && strtolower(pathinfo($png, PATHINFO_EXTENSION)) === 'png') {
            $files['brand-logo.png'] = $png;
        }

        return $files;
    }

    return [];
}

/**
 * Runs demo-site.sh with a command on the server, streams nothing but prints the output indented afterwards;
 * returns [output, exit code].
 *
 * @return array{0: string, 1: int}
 */
function publish_demo_run(\phpseclib3\Net\SFTP $ssh, string $tools, string $command, bool $print = true): array
{
    $ssh->setTimeout(3600);
    // VERBOSE=1: demo-site.sh then reports every step too (ps-module -v).
    $output = (string) $ssh->exec((ps_verbose() ? 'VERBOSE=1 ' : '') . 'bash ' . escapeshellarg("$tools/demo-site.sh") . " $command 2>&1");
    $exit = $ssh->getExitStatus();
    if ($print) {
        echo preg_replace('/^/m', '  ', rtrim($output)) . "\n";
    }

    return [$output, is_int($exit) ? $exit : 1];
}

/**
 * The settings file of the demo tools, from the DEMO_SITE_* settings (shell syntax, values single-quoted).
 *
 * @param array<string, mixed> $ctx
 */
function publish_demo_conf(array $ctx): string
{
    $values = [
        'PS_ROOT' => publish_require_env($ctx, 'DEMO_SITE_PS_ROOT'),
        'URL' => publish_require_env($ctx, 'DEMO_SITE_URL'),
        'LANGUAGES' => publish_env($ctx, 'DEMO_SITE_LANGUAGES'),
        'TEST_PATHS' => publish_env($ctx, 'DEMO_SITE_TEST_PATHS'),
        'ADMIN_DIR' => publish_env($ctx, 'DEMO_SITE_ADMIN_DIR'),
        'SNAPSHOT_DIR' => publish_env($ctx, 'DEMO_SITE_SNAPSHOT_DIR'),
        'KEEP' => publish_env($ctx, 'DEMO_SITE_KEEP') ?: '5',
        'PHP' => publish_env($ctx, 'DEMO_SITE_PHP') ?: 'php',
        'CRON_TIME' => publish_env($ctx, 'DEMO_SITE_CRON') ?: '30 3 * * *',
        'CONNECT_TO' => publish_env($ctx, 'DEMO_SITE_CONNECT_TO'),
        'KEEP_PATHS' => publish_env($ctx, 'DEMO_SITE_KEEP_PATHS'),
        'CRON_INSTALL' => publish_env($ctx, 'DEMO_SITE_CRON_INSTALL') === 'no' ? 'no' : 'yes',
        'DEMO_EMAIL' => publish_env($ctx, 'DEMO_SITE_EMAIL') ?: 'demo@demo.com',
        'DEMO_PASSWORD' => publish_env($ctx, 'DEMO_SITE_PASSWORD') ?: 'demodemo',
        'INTRO_PATH' => publish_env($ctx, 'DEMO_SITE_INTRO_PATH') ?: 'start',
        'DOCS_URL' => publish_env($ctx, 'DOCS_SITE_URL'),
    ];
    $conf = "# Settings of demo-site.sh, written by `ps-module demo-site setup` from the DEMO_SITE_* settings.\n";
    foreach ($values as $key => $value) {
        if ($value !== '') {
            $conf .= $key . "='" . str_replace("'", "'\\''", $value) . "'\n";
        }
    }

    return $conf;
}

/**
 * Uploads a snapshot made elsewhere (db.sql.gz + files.tar.gz, e.g. from the local Docker demo shop) into the
 * server's snapshot directory and runs `demo-site.sh activate` (restore + test; back to the previous one on a
 * failure). Then a VPS snapshot when TRANSIP_* is set.
 *
 * @param array<string, mixed> $ctx
 *
 * @throws RuntimeException when the snapshot is incomplete or the upload fails
 */
function publish_demo_push(array $ctx, string $local, bool $dryRun): int
{
    $dir = $local !== '' ? realpath($local) : false;
    if ($dir === false || !is_file("$dir/db.sql.gz") || !is_file("$dir/files.tar.gz")) {
        throw new RuntimeException('usage: ps-module demo-site push <snapshot directory with db.sql.gz and files.tar.gz>');
    }
    // Named after the upload time, so the snapshot order (and KEEP) follows when it became the server's.
    $name = date('Ymd-His') . '-pushed-' . basename($dir);
    $tools = publish_demo_tools_dir($ctx);
    $size = (filesize("$dir/db.sql.gz") + filesize("$dir/files.tar.gz")) / 1048576;
    if ($dryRun) {
        printf("Dry run: would upload %s (%.1f MB) as snapshot %s to the demo server and activate it.\n", $dir, $size, $name);

        return 0;
    }
    $ssh = publish_connect($ctx, 'DEMO_SITE_SSH');
    publish_demo_upload_tools($ssh, $tools, false);
    [$paths] = publish_demo_run($ssh, $tools, 'paths', false);
    if (preg_match('/^SNAPSHOT_DIR=(.+)$/m', $paths, $m) !== 1) {
        throw new RuntimeException('demo-site.sh paths gave no SNAPSHOT_DIR.');
    }
    $remote = rtrim(trim($m[1]), '/') . '/' . $name;
    if (!$ssh->mkdir($remote, 0700, true) && !$ssh->is_dir($remote)) {
        throw new RuntimeException("cannot create $remote.");
    }
    foreach (['db.sql.gz', 'files.tar.gz', 'snapshot.json'] as $file) {
        if (is_file("$dir/$file") && !$ssh->put("$remote/$file", "$dir/$file", \phpseclib3\Net\SFTP::SOURCE_LOCAL_FILE)) {
            throw new RuntimeException("upload of $file failed.");
        }
    }
    printf("Uploaded snapshot %s (%.1f MB).\n", $name, $size);
    [, $exit] = publish_demo_run($ssh, $tools, 'activate ' . escapeshellarg($name));
    if ($exit !== 0) {
        return 1;
    }

    return publish_after_deploy($ctx, 'demo-site push');
}

/**
 * The files of a local folder for `demo-site apply`: path relative to the folder => absolute path, sorted. Skips
 * dot files and folders (.git, .DS_Store).
 *
 * @return array<string, string>
 *
 * @throws RuntimeException when the folder has no apply.php
 */
function publish_apply_files(string $local): array
{
    $dir = $local !== '' ? realpath($local) : false;
    if ($dir === false || !is_file("$dir/apply.php")) {
        throw new RuntimeException('usage: ps-module demo-site apply <folder with apply.php> [note]');
    }
    $dir = str_replace('\\', '/', $dir);
    $files = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        static fn (SplFileInfo $file): bool => !str_starts_with($file->getFilename(), '.')
    ));
    foreach ($iterator as $file) {
        $path = str_replace('\\', '/', $file->getPathname());
        $files[substr($path, strlen($dir) + 1)] = $path;
    }
    ksort($files);

    return $files;
}

/**
 * Uploads a folder with apply.php to the demo server (DEMO_SITE_TOOLS_DIR/uploads/apply-<time>/) and runs
 * `demo-site.sh apply` on it: apply.php in the shop as it is, test before and after, the database back on a failure,
 * else a new demo snapshot. The folder is removed from the server afterwards. Then a VPS snapshot (unless
 * --no-snapshot) when TRANSIP_* is set.
 *
 * @param array<string, mixed> $ctx
 *
 * @throws RuntimeException when the folder has no apply.php or an upload fails
 */
function publish_demo_apply(array $ctx, string $local, string $note, bool $dryRun, bool $noSnapshot): int
{
    $files = publish_apply_files($local);
    $note = $note !== '' ? $note : 'apply ' . basename((string) realpath($local));
    $size = array_sum(array_map('filesize', $files));
    $tools = publish_demo_tools_dir($ctx);
    $remote = "$tools/uploads/apply-" . date('Ymd-His');
    if ($dryRun) {
        printf("Dry run: would upload %d file(s) (%s) to the demo server and run demo-site.sh apply (\"%s\"): apply.php in the shop, test, new snapshot%s.\n", count($files), publish_size($size), $note, $noSnapshot ? '' : ', VPS snapshot');

        return 0;
    }
    $ssh = publish_connect($ctx, 'DEMO_SITE_SSH');
    publish_demo_upload_tools($ssh, $tools, false);
    foreach ($files as $relative => $path) {
        $target = "$remote/$relative";
        if (!$ssh->is_dir(dirname($target)) && !$ssh->mkdir(dirname($target), 0700, true)) {
            throw new RuntimeException('cannot create ' . dirname($target) . '.');
        }
        if (!$ssh->put($target, $path, \phpseclib3\Net\SFTP::SOURCE_LOCAL_FILE)) {
            throw new RuntimeException("upload of $relative failed.");
        }
    }
    ps_vlog(sprintf('uploaded %d file(s), %s, to %s', count($files), publish_size($size), $remote));
    [$output, $exit] = publish_demo_run($ssh, $tools, 'apply ' . escapeshellarg($remote) . ' ' . escapeshellarg($note));
    $ssh->delete($remote, true);
    if ($exit !== 0 || preg_match('/^APPLY-OK$/m', $output) !== 1) {
        throw new RuntimeException('apply failed on the server (exit ' . $exit . '): see the server output above.');
    }
    echo "Demo shop: \"$note\" applied, new snapshot.\n";

    return $noSnapshot ? 0 : publish_after_deploy($ctx, $note);
}

/**
 * After a successful deploy: a TransIP snapshot of the whole VPS when TRANSIP_LOGIN is set. A failing snapshot is
 * reported but does not undo the deploy; returns 0 when the deploy stands, 1 when the snapshot failed.
 *
 * @param array<string, mixed> $ctx
 */
function publish_after_deploy(array $ctx, string $what): int
{
    if (publish_env($ctx, 'TRANSIP_LOGIN') === '') {
        return 0;
    }
    $description = trim("$what {$ctx['module']} {$ctx['version']}") . ' ' . date('Y-m-d H:i');

    try {
        return publish_cmd_vps_snapshot($ctx, $description, false) ? 0 : 1;
    } catch (RuntimeException $e) {
        fwrite(STDERR, "ps-module publish: the deploy is done, but the VPS snapshot failed: " . publish_mask($e->getMessage()) . "\n"
            . "  Try again later: vendor/bin/ps-module publish vps-snapshot\n");

        return 1;
    }
}

/**
 * After an upload: the files of a new version keep their address (modules/<module>/logo.png, readme_<iso>.pdf), and a
 * CDN in front of the server (Cloudflare) keeps serving the old copy for as long as the server's Cache-Control allows
 * (10 years for static files on many servers). With CLOUDFLARE_API_TOKEN (permission Zone > Cache Purge) and
 * CLOUDFLARE_ZONE_ID the addresses are purged through the API, 30 per request; without them the addresses are listed
 * for a purge by hand. A failing purge is reported but does not undo the deploy.
 *
 * @param array<string, mixed> $ctx
 * @param list<string> $urls
 */
function publish_cloudflare_purge(array $ctx, array $urls): void
{
    $urls = array_values(array_unique(array_filter($urls, static fn (string $u): bool => preg_match('#^https?://#', $u) === 1)));
    if ($urls === []) {
        return;
    }
    $token = publish_env($ctx, 'CLOUDFLARE_API_TOKEN');
    $zone = publish_env($ctx, 'CLOUDFLARE_ZONE_ID');
    if ($token === '' || $zone === '') {
        echo "Cloudflare cache: not purged (no CLOUDFLARE_API_TOKEN + CLOUDFLARE_ZONE_ID). Visitors may see the old files until\n"
            . "  these addresses are purged (Cloudflare dashboard > Caching > Configuration > Custom Purge):\n";
        foreach ($urls as $url) {
            echo "  $url\n";
        }

        return;
    }
    if (!function_exists('curl_init')) {
        fwrite(STDERR, "ps-module publish: the PHP curl extension is needed to purge the Cloudflare cache.\n");

        return;
    }
    $purged = 0;
    foreach (array_chunk($urls, 30) as $chunk) {
        $ch = curl_init('https://api.cloudflare.com/client/v4/zones/' . rawurlencode($zone) . '/purge_cache');
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $token],
            CURLOPT_POSTFIELDS => (string) json_encode(['files' => $chunk]),
        ]);
        $start = microtime(true);
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        unset($ch);
        $body = is_string($raw) ? json_decode($raw, true) : null;
        ps_vlog(sprintf('Cloudflare purge of %d address(es): HTTP %d after %.1fs', count($chunk), $status, microtime(true) - $start));
        if (!is_array($body) || empty($body['success'])) {
            $message = is_array($body) ? implode('; ', array_map(static fn ($e): string => is_array($e) ? (string) ($e['message'] ?? '') : '', (array) ($body['errors'] ?? []))) : $error;
            fwrite(STDERR, "ps-module publish: the upload is done, but the Cloudflare purge failed (HTTP $status): $message\n"
                . "  Purge by hand: Cloudflare dashboard > Caching > Configuration > Custom Purge.\n");

            return;
        }
        $purged += count($chunk);
    }
    echo "Cloudflare cache purged: $purged address(es).\n";
}

/**
 * TransIP snapshot of the VPS (TRANSIP_VPS_NAME) through the REST API v6. The oldest snapshot is always kept: it is the
 * starting point to go back to. Before the new snapshot the most recent ones are removed until there is room within
 * TRANSIP_SNAPSHOT_KEEP (default 2, at least 2: the oldest + the newest), and again once when TransIP says its limit is
 * reached. Skipped when the VPS is busy (isLocked); after a removal it waits until the VPS is free again. The VPS keeps
 * running. Returns true when the snapshot was requested.
 *
 * @param array<string, mixed> $ctx
 *
 * @throws RuntimeException when the API refuses
 */
function publish_cmd_vps_snapshot(array $ctx, string $description, bool $dryRun): bool
{
    $vps = publish_require_env($ctx, 'TRANSIP_VPS_NAME');
    $keep = max(2, (int) (publish_env($ctx, 'TRANSIP_SNAPSHOT_KEEP') ?: 2));
    if ($dryRun) {
        // A real test of the API access with a read-only token: the VPS and its snapshots; nothing changes.
        $token = publish_transip_token($ctx, true);
        $info = publish_transip_vps($token, $vps);
        echo "TransIP API: logged in (read-only). VPS $vps: " . (string) ($info['status'] ?? '?') . (!empty($info['isLocked']) ? ', busy' : '') . "\n";
        $active = publish_transip_snapshots($token, $vps);
        foreach ($active as $i => $snapshot) {
            echo "  snapshot {$snapshot['name']} ({$snapshot['dateTimeCreate']})" . ($i === 0 ? ' - the oldest: always kept' : '') . "\n";
        }
        $remove = array_slice($active, 1, max(0, count($active) - ($keep - 1)));
        echo "Dry run: would " . ($remove !== [] ? 'remove ' . implode(', ', array_map(static fn (array $s): string => $s['name'], array_reverse($remove))) . ' (the most recent), then ' : '')
            . "create a TransIP snapshot of $vps (\"$description\"); kept: the oldest + the newest, $keep in all.\n";

        return true;
    }
    $token = publish_transip_token($ctx);
    if (!empty(publish_transip_vps($token, $vps)['isLocked'])) {
        fwrite(STDERR, "ps-module publish: VPS $vps is busy (another action, e.g. a snapshot, is running): no new snapshot. Try later: vendor/bin/ps-module demo-site vps-snapshot\n");

        return false;
    }
    $active = publish_transip_snapshots($token, $vps);
    $removed = false;
    while (count($active) > 1 && count($active) >= $keep) {
        $removed = publish_transip_remove_newest($token, $vps, $active) || $removed;
    }
    if ($removed) {
        publish_transip_wait($token, $vps);
    }
    $body = ['description' => substr($description, 0, 100), 'shouldStartVps' => true];
    $created = publish_transip($token, 'POST', "/vps/$vps/snapshots", $body, false);
    if ($created['status'] !== 201 && str_contains(strtolower((string) ($created['body']['error'] ?? '')), 'limit')) {
        if (count($active) <= 1) {
            throw new RuntimeException('TransIP\'s snapshot limit is reached and only the oldest snapshot is left; it is kept as the starting point. Raise the limit of the plan, or remove it by hand.');
        }
        echo "  snapshot limit of TransIP reached\n";
        publish_transip_remove_newest($token, $vps, $active);
        publish_transip_wait($token, $vps);
        $created = publish_transip($token, 'POST', "/vps/$vps/snapshots", $body, false);
    }
    if ($created['status'] !== 201) {
        throw new RuntimeException('TransIP refused the snapshot (HTTP ' . $created['status'] . '): ' . (string) ($created['body']['error'] ?? ''));
    }
    echo "TransIP snapshot of $vps requested: \"{$body['description']}\" (TransIP makes it in the background).\n";
    if ($active !== []) {
        echo "  kept: the oldest {$active[0]['name']} ({$active[0]['dateTimeCreate']})" . (count($active) > 1 ? ' and ' . (count($active) - 1) . ' more' : '') . "\n";
    }

    return true;
}

/**
 * The VPS as TransIP describes it (status, isLocked). A wrong TRANSIP_VPS_NAME (the description of the control panel
 * instead of the technical name) gets the names of the account's VPSs in the error.
 *
 * @return array<string, mixed>
 *
 * @throws RuntimeException when the VPS does not exist or the API refuses
 */
function publish_transip_vps(string $token, string $vps): array
{
    $info = publish_transip($token, 'GET', "/vps/$vps", null, false);
    if ($info['status'] === 404) {
        $names = [];
        foreach ((array) (publish_transip($token, 'GET', '/vps')['body']['vpss'] ?? []) as $item) {
            if (is_array($item)) {
                $names[] = (string) ($item['name'] ?? '?') . (($item['description'] ?? '') !== '' ? ' ("' . $item['description'] . '")' : '');
            }
        }
        throw new RuntimeException("TRANSIP_VPS_NAME: TransIP has no VPS named $vps. Use the technical name; the VPSs of this account: " . ($names !== [] ? implode(', ', $names) : 'none') . '.');
    }
    if ($info['status'] >= 400) {
        throw new RuntimeException("TransIP API GET /vps/$vps: HTTP {$info['status']} " . (string) ($info['body']['error'] ?? ''));
    }

    return (array) ($info['body']['vps'] ?? []);
}

/**
 * Removes the most recent snapshot of the list (never the oldest, the first one) and drops it from the list.
 *
 * @param list<array{name: string, dateTimeCreate: string}> $active oldest first
 */
function publish_transip_remove_newest(string $token, string $vps, array &$active): bool
{
    if (count($active) <= 1) {
        return false;
    }
    $newest = array_pop($active);
    publish_transip($token, 'DELETE', "/vps/$vps/snapshots/{$newest['name']}");
    echo "  removed the most recent snapshot {$newest['name']} ({$newest['dateTimeCreate']}); the oldest stays\n";

    return true;
}

/**
 * Waits until TransIP has finished with the VPS (isLocked false), at most 5 minutes; a removed snapshot keeps it busy
 * for a while and a new snapshot is refused meanwhile.
 *
 * @throws RuntimeException when the VPS is still busy after 5 minutes
 */
function publish_transip_wait(string $token, string $vps): void
{
    $start = time();
    while (!empty(publish_transip_vps($token, $vps)['isLocked'])) {
        if (time() - $start > 300) {
            throw new RuntimeException("VPS $vps is still busy after 5 minutes: try later (vendor/bin/ps-module demo-site vps-snapshot).");
        }
        ps_vlog('VPS busy (removing a snapshot): waiting 10 s');
        sleep(10);
    }
}

/**
 * The active snapshots of a VPS, oldest first.
 *
 * @return list<array{name: string, dateTimeCreate: string}>
 */
function publish_transip_snapshots(string $token, string $vps): array
{
    $list = [];
    foreach ((array) (publish_transip($token, 'GET', "/vps/$vps/snapshots")['body']['snapshots'] ?? []) as $snapshot) {
        if (is_array($snapshot) && ($snapshot['status'] ?? '') === 'active') {
            $list[] = ['name' => (string) $snapshot['name'], 'dateTimeCreate' => (string) ($snapshot['dateTimeCreate'] ?? '')];
        }
    }
    usort($list, static fn (array $a, array $b): int => strcmp($a['dateTimeCreate'], $b['dateTimeCreate']));

    return $list;
}

/**
 * An access token of the TransIP REST API: POST /auth with the request body signed (SHA-512, RSA) with the private key
 * of the API key pair, valid for 30 minutes.
 *
 * @param array<string, mixed> $ctx
 *
 * @throws RuntimeException when the key cannot be read or TransIP refuses the login
 */
function publish_transip_token(array $ctx, bool $readOnly = false): string
{
    $keySetting = publish_require_env($ctx, 'TRANSIP_PRIVATE_KEY');
    $pem = publish_normalize_pem(is_file($keySetting) ? (string) file_get_contents($keySetting) : $keySetting);
    $key = openssl_pkey_get_private($pem);
    if ($key === false) {
        throw new RuntimeException('TRANSIP_PRIVATE_KEY is not a readable private key: ' . (is_file($keySetting) ? 'the file exists, but ' . publish_key_problem((string) file_get_contents($keySetting)) : publish_key_problem($pem)) . '.');
    }
    $body = (string) json_encode([
        'login' => publish_require_env($ctx, 'TRANSIP_LOGIN'),
        'nonce' => bin2hex(random_bytes(16)),
        'read_only' => $readOnly,
        'expiration_time' => '30 minutes',
        'label' => 'ps-module publish ' . bin2hex(random_bytes(3)),
        'global_key' => publish_env($ctx, 'TRANSIP_GLOBAL_KEY') !== 'false',
    ]);
    if (!openssl_sign($body, $signature, $key, OPENSSL_ALGO_SHA512)) {
        throw new RuntimeException('signing the TransIP login failed.');
    }
    $auth = publish_transip('', 'POST', '/auth', $body, true, ['Signature: ' . base64_encode($signature)]);
    $token = (string) ($auth['body']['token'] ?? '');
    if ($token === '') {
        throw new RuntimeException('TransIP login failed (HTTP ' . $auth['status'] . '): ' . (string) ($auth['body']['error'] ?? ''));
    }

    return $token;
}

/**
 * A request to the TransIP REST API v6 (https://api.transip.nl/v6). $body is an array (sent as JSON) or a JSON string
 * that was signed. With $strict an HTTP status of 400 or higher throws.
 *
 * @param array<string, mixed>|string|null $body
 * @param string[] $headers
 *
 * @return array{status: int, body: array<string, mixed>}
 *
 * @throws RuntimeException when the API cannot be reached, or (strict) answers with an error
 */
function publish_transip(string $token, string $method, string $path, array|string|null $body = null, bool $strict = true, array $headers = []): array
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('the PHP curl extension is needed for the TransIP API.');
    }
    $ch = curl_init('https://api.transip.nl/v6' . $path);
    $headers[] = 'Content-Type: application/json';
    if ($token !== '') {
        $headers[] = 'Authorization: Bearer ' . $token;
    }
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_HTTPHEADER => $headers,
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, is_string($body) ? $body : (string) json_encode($body));
    }
    $start = microtime(true);
    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    // The token, the signature and the request body (the login) are never shown.
    ps_vlog(sprintf('TransIP API %s %s: HTTP %d after %.1fs%s', $method, $path, $status, microtime(true) - $start,
        is_string($raw) && $status >= 400 ? ' (' . substr($raw, 0, 300) . ')' : (is_string($raw) ? '' : ' (' . curl_error($ch) . ')')));
    unset($ch);
    if (!is_string($raw)) {
        throw new RuntimeException("TransIP API $method $path: no answer.");
    }
    $decoded = json_decode($raw, true);
    $result = ['status' => $status, 'body' => is_array($decoded) ? $decoded : []];
    if ($strict && $status >= 400) {
        throw new RuntimeException("TransIP API $method $path: HTTP $status " . (string) ($result['body']['error'] ?? ''));
    }

    return $result;
}

// ---------------------------------------------------------------------------
// Helpers

/**
 * Refuses to publish a version that is not released: tag v<version> must exist, unless in CI or with --force.
 *
 * @param array<string, mixed> $ctx
 *
 * @throws RuntimeException when the version has no release tag
 */
function publish_release_guard(array $ctx, bool $force): void
{
    if ($force || getenv('CI') !== false) {
        ps_vlog('release check skipped (' . ($force ? '--force' : 'CI') . ')');

        return;
    }
    $git = 'git -C ' . escapeshellarg($ctx['repo']) . ' tag -l ' . escapeshellarg('v' . $ctx['version']);
    ps_vcmd($git);
    $tag = trim((string) shell_exec($git));
    ps_vlog('release tag v' . $ctx['version'] . ': ' . ($tag !== '' ? 'found' : 'missing'));
    if ($tag === '') {
        throw new RuntimeException("version {$ctx['version']} is not released (no tag v{$ctx['version']}). Publish after the release, or use --force.");
    }
}

/**
 * A setting from the environment, or else from the nearest .env file upwards from the repository; '' when unset.
 *
 * @param array<string, mixed> $ctx
 */
function publish_env(array $ctx, string $key): string
{
    static $reported = [];
    $value = publish_env_lookup($ctx, $key);
    // DEMO_SITE_PASSWORD is the public demo login (shown on the intro page), not a secret.
    $secret = $key !== 'DEMO_SITE_PASSWORD' && preg_match('/_(HOST|USER|PASSWORD|PASS|KEY|PASSPHRASE|TOKEN|SECRET|LOGIN)$/', $key) === 1;
    if ($value !== '' && $secret) {
        publish_secrets($value);
    }
    if (!isset($reported[$key])) {
        $reported[$key] = true;
        ps_vlog("setting $key: " . ($value === '' ? '(not set)' : ($secret ? '*** (set)' : $value) . ' (from ' . publish_env_source() . ')'));
    }

    return $value;
}

/**
 * Looks a setting up: the --env file, the environment, then the nearest .env file upwards from the repository.
 *
 * @param array<string, mixed> $ctx
 */
function publish_env_lookup(array $ctx, string $key): string
{
    $file = publish_env_file();
    if ($file !== [] && (array_key_exists($key, $file) || preg_match('/^(DEMO_SITE|DOCS_SITE|TRANSIP|CLOUDFLARE|SALES_SHOP)_/', $key) === 1)) {
        // With --env the server settings come only from that file: a local test must never reach the real servers,
        // the TransIP account or the Cloudflare zone through a value in .env.
        publish_env_source('the --env file');

        return $file[$key] ?? '';
    }
    $value = getenv($key);
    if (is_string($value) && $value !== '') {
        publish_env_source('the environment');

        return $value;
    }
    for ($dir = $ctx['repo']; $dir !== dirname($dir); $dir = dirname($dir)) {
        if (is_file("$dir/.env") && preg_match('/^\s*' . preg_quote($key, '/') . '\s*=\s*(.*?)\s*$/m', $text = (string) file_get_contents("$dir/.env"), $m, PREG_OFFSET_CAPTURE)) {
            $found = publish_env_multiline($text, (string) $m[1][0], (int) $m[0][1] + strlen((string) $m[0][0]));
            if ($found !== '') {
                publish_env_source("$dir/.env");

                return $found;
            }
        }
    }
    publish_env_source('nowhere');

    return '';
}

/**
 * The value of a .env line, also when it goes on over the next lines: a quoted value without its closing quote on the
 * first line ("-----BEGIN ...\n...\n-----END ...-----"), or a pasted PEM key whose first line is "-----BEGIN ...-----"
 * (up to the "-----END ...-----" line). Otherwise the line itself, without surrounding quotes.
 */
function publish_env_multiline(string $text, string $first, int $offset): string
{
    $quote = $first !== '' && ($first[0] === '"' || $first[0] === "'") ? $first[0] : '';
    $openQuote = $quote !== '' && (strlen($first) === 1 || substr($first, -1) !== $quote);
    $pemStart = preg_match('/^["\']?-----BEGIN [A-Z0-9 ]+-----$/', $first) === 1;
    if (!$openQuote && !$pemStart) {
        return trim($first, "\"'");
    }
    $lines = [ltrim($first, "\"'")];
    foreach (preg_split('/\R/', substr($text, $offset)) ?: [] as $i => $line) {
        $line = rtrim($line);
        if ($line === '') {
            continue; // line breaks of the first line, \r\n counted twice; a key has no empty lines
        }
        if ($openQuote && str_ends_with($line, $quote)) {
            $lines[] = substr($line, 0, -1);
            break;
        }
        $lines[] = $line;
        if (!$openQuote && preg_match('/^-----END [A-Z0-9 ]+-----$/', trim($line)) === 1) {
            break;
        }
    }

    return trim(implode("\n", $lines));
}

/**
 * A PEM key in its standard layout, however it was pasted: "\n" written as text, line breaks lost or turned into
 * spaces (copied from a web page), \r\n line ends. The base64 body is taken without any white space and wrapped at 64
 * characters between its BEGIN and END lines. Text without such a block is returned as it is.
 */
function publish_normalize_pem(string $text): string
{
    $text = str_replace(['\r\n', '\n'], "\n", $text);
    if (preg_match('/-----BEGIN ([A-Z0-9 ]+)-----(.*?)-----END \1-----/s', $text, $m) !== 1) {
        return $text;
    }
    // A traditional encrypted key has header lines (Proc-Type, DEK-Info) that must stay: leave it as it is.
    if (str_contains($m[2], 'Proc-Type:')) {
        return $text;
    }
    $body = (string) preg_replace('/[^A-Za-z0-9+\/=]/', '', $m[2]);

    return "-----BEGIN {$m[1]}-----\n" . chunk_split($body, 64, "\n") . "-----END {$m[1]}-----\n";
}

/**
 * Why a private key setting cannot be used (never shows the value): a file path that does not exist, a key cut off
 * after its first line, or text that OpenSSL cannot read.
 */
function publish_key_problem(string $setting): string
{
    $setting = trim($setting);
    if (!str_contains($setting, '-----')) {
        return preg_match('#[/\\\\]|\.(pem|key)$#i', $setting) === 1
            ? 'it looks like a file path, but there is no file there (' . (is_dir(dirname($setting)) ? 'the folder exists, the file does not' : 'the folder does not exist either') . ')'
            : 'it is neither a file path nor a key (a key starts with -----BEGIN PRIVATE KEY-----)';
    }
    if (!str_contains($setting, '-----END')) {
        return 'only the start of the key arrived (no -----END line): put the key in a file and set its path, or put it on one line with \n between the lines';
    }
    if (str_contains($setting, 'PUBLIC KEY')) {
        return 'this is a public key; the private key is needed (-----BEGIN PRIVATE KEY-----)';
    }
    $error = '';
    while (($e = openssl_error_string()) !== false) {
        $error = $e;
    }

    return 'OpenSSL cannot read the key text' . ($error !== '' ? " ($error)" : '') . ': is it complete, and protected by a passphrase? (not supported)';
}

/**
 * Where the last setting came from (the --env file, the environment or a .env path), for verbose output; with an
 * argument: records it.
 */
function publish_env_source(?string $source = null): string
{
    static $last = '';
    if ($source !== null) {
        $last = $source;
    }

    return $last;
}

/**
 * The settings of the --env file (KEY=value lines), loaded once; without an argument: the loaded settings.
 *
 * @return array<string, string>
 */
function publish_env_file(?string $file = null): array
{
    static $values = [];
    if ($file !== null) {
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            if (preg_match('/^\s*([A-Z0-9_]+)\s*=\s*(.*?)\s*$/', $line, $m) === 1 && !str_starts_with(ltrim($line), '#')) {
                $values[$m[1]] = trim($m[2], "\"'");
            }
        }
    }

    return $values;
}

/**
 * A required setting; throws with the name of the missing key.
 *
 * @param array<string, mixed> $ctx
 *
 * @throws RuntimeException when the setting is missing
 */
function publish_require_env(array $ctx, string $key): string
{
    $value = publish_env($ctx, $key);
    if ($value === '') {
        throw new RuntimeException("$key is not set (environment or .env; see vendor/bin/ps-module publish help).");
    }

    return $value;
}

/**
 * Opens an SFTP connection (which can also run commands) with the settings <PREFIX>_HOST, _PORT, _USER and
 * _PASSWORD or _KEY (a file, or the key itself) with optional _KEY_PASSPHRASE. Uses phpseclib from the module's vendor/.
 *
 * @param array<string, mixed> $ctx
 *
 * @throws RuntimeException when phpseclib is missing or the login fails
 */
function publish_connect(array $ctx, string $prefix): \phpseclib3\Net\SFTP
{
    // phpseclib: from the vendor/ this package is installed in (a module), or from a checkout of this package.
    foreach ([__DIR__ . '/../../../autoload.php', __DIR__ . '/../vendor/autoload.php', $ctx['module_dir'] . '/vendor/autoload.php'] as $autoload) {
        if (!class_exists(\phpseclib3\Net\SFTP::class) && is_file($autoload)) {
            require_once $autoload;
        }
    }
    if (!class_exists(\phpseclib3\Net\SFTP::class)) {
        throw new RuntimeException('phpseclib is missing: composer update prestashopheaven/module-dev-tools (1.13+).');
    }
    require_once __DIR__ . '/publish-sftp.php';
    $host = publish_require_env($ctx, $prefix . '_HOST');
    $user = publish_require_env($ctx, $prefix . '_USER');
    $port = (int) (publish_env($ctx, $prefix . '_PORT') ?: 22);
    $key = publish_env($ctx, $prefix . '_KEY');
    $password = publish_env($ctx, $prefix . '_PASSWORD');
    $credential = null;
    if ($key !== '') {
        $passphrase = publish_env($ctx, $prefix . '_KEY_PASSPHRASE');
        $text = is_file($key) ? (string) file_get_contents($key) : str_replace('\n', "\n", $key);
        try {
            $credential = \phpseclib3\Crypt\PublicKeyLoader::load($text, $passphrase !== '' ? $passphrase : false);
        } catch (\Throwable $e) {
            if ($password === '') {
                throw new RuntimeException("{$prefix}_KEY is not a readable private key (a file path or the key itself).");
            }
            fwrite(STDERR, "ps-module publish: {$prefix}_KEY is not a readable private key; logging in with {$prefix}_PASSWORD.\n");
        }
    }
    if ($credential === null && $password !== '') {
        $credential = $password;
    }
    if ($credential === null) {
        throw new RuntimeException("{$prefix}_KEY or {$prefix}_PASSWORD is not set.");
    }
    // Messages never name the host or the user: they are credentials too, and output may be shared.
    $method = is_string($credential) ? 'password' : 'private key';
    ps_vlog("connecting to {$prefix}_HOST on port $port as {$prefix}_USER with a $method");
    $start = microtime(true);
    try {
        $sftp = new PublishSftp($host, $port, 20);
        $ok = $sftp->login($user, $credential);
    } catch (\Throwable $e) {
        ps_vlog('connection error: ' . get_class($e) . ': ' . $e->getMessage());
        throw new RuntimeException("cannot connect to {$prefix}_HOST on port $port (" . get_class($e) . ').');
    }
    if (!$ok) {
        ps_vlog('login refused; server messages: ' . implode(' | ', array_map('strval', (array) $sftp->getErrors())));
        throw new RuntimeException("login failed: check {$prefix}_USER and {$prefix}_PASSWORD (or {$prefix}_KEY), and that the server allows this login.");
    }
    ps_vdone("logged in ({$sftp->getServerIdentification()})", $start);

    return $sftp;
}

/**
 * A byte count in a readable unit (B, KB, MB).
 */
function publish_size(int $bytes): string
{
    return $bytes >= 1048576 ? sprintf('%.1f MB', $bytes / 1048576) : ($bytes >= 1024 ? sprintf('%.1f KB', $bytes / 1024) : $bytes . ' B');
}

/**
 * GET request with a timeout; null when the address cannot be reached.
 *
 * @return array{status: int, body: string}|null
 */
function publish_http_get(string $url): ?array
{
    if (!function_exists('curl_init')) {
        fwrite(STDERR, "ps-module publish: the PHP curl extension is needed to check $url.\n");

        return null;
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 15, CURLOPT_USERAGENT => 'ps-module publish']);
    $start = microtime(true);
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    ps_vlog(sprintf('GET %s: HTTP %d, %s%s after %.1fs', $url, $status, is_string($body) ? publish_size(strlen($body)) : 'no answer',
        curl_error($ch) !== '' ? ' (' . curl_error($ch) . ')' : '', microtime(true) - $start));
    unset($ch);

    return is_string($body) ? ['status' => $status, 'body' => $body] : null;
}


/**
 * Installs PrestaShop on the demo server, once, before `demo-site setup`: the files of <version> from the local cache
 * (../.cache/prestashop/<version>, fetch-prestashop.php), then PrestaShop's own CLI installer with the database from
 * .db_credentials. The database settings reach the server only in a private file that the runner deletes before the
 * installer starts, never on a command line, and are masked in all output.
 *
 * @param array<string, mixed> $ctx
 */
function publish_demo_install_prestashop(array $ctx, string $version, bool $dryRun): int
{
    $version = $version !== '' ? $version : (publish_env($ctx, 'DEMO_SITE_PS_VERSION') ?: '9.2.0');
    if (preg_match('/^\d+\.\d+\.\d+$/', $version) !== 1) {
        throw new RuntimeException("not a PrestaShop version: $version.");
    }
    $source = '';
    for ($dir = $ctx['repo']; $dir !== dirname($dir); $dir = dirname($dir)) {
        if (is_file("$dir/.cache/prestashop/$version/install/index_cli.php")) {
            $source = "$dir/.cache/prestashop/$version";
            break;
        }
    }
    if ($source === '') {
        throw new RuntimeException("no PrestaShop $version with its installer in .cache/prestashop/: php .claude/skills/ps-module/scripts/fetch-prestashop.php $version");
    }
    $db = publish_db_credentials($ctx);
    $root = rtrim(publish_require_env($ctx, 'DEMO_SITE_PS_ROOT'), '/');
    $url = rtrim(publish_require_env($ctx, 'DEMO_SITE_URL'), '/');
    $port = parse_url($url, PHP_URL_PORT);
    $domain = (string) parse_url($url, PHP_URL_HOST) . ($port ? ':' . $port : '');
    $base = rtrim((string) parse_url($url, PHP_URL_PATH), '/') . '/';
    $admin = publish_env($ctx, 'DEMO_SITE_ADMIN_DIR') ?: 'admin-dev';
    $php = publish_env($ctx, 'DEMO_SITE_PHP') ?: 'php';
    $tools = publish_demo_tools_dir($ctx);
    $args = [
        '--step=all', '--language=en', '--all_languages=0',
        '--timezone=' . (publish_env($ctx, 'DEMO_SITE_TIMEZONE') ?: 'Europe/Amsterdam'),
        '--base_uri=' . $base, '--domain=' . $domain, '--ssl=' . (str_starts_with($url, 'https://') ? '1' : '0'), '--rewrite=1',
        '--db_server=' . $db['host'] . ($db['port'] !== '' ? ':' . $db['port'] : ''), '--db_name=' . $db['name'],
        '--db_user=' . $db['user'], '--db_password=' . $db['password'], '--db_create=0', '--db_clear=1', '--prefix=ps_',
        '--name=' . (publish_env($ctx, 'DEMO_SITE_SHOP_NAME') ?: 'PrestaShopHeaven demo'),
        '--country=' . (publish_env($ctx, 'DEMO_SITE_COUNTRY') ?: 'nl'), '--firstname=Demo', '--lastname=Demo',
        '--email=' . (publish_env($ctx, 'DEMO_SITE_EMAIL') ?: 'demo@demo.com'),
        '--password=' . (publish_env($ctx, 'DEMO_SITE_PASSWORD') ?: 'demodemo'), '--newsletter=0', '--send_email=0',
        // No demo products: the demo content comes from the local demo (demo-site push), which replaces the database.
        '--fixtures=' . (publish_env($ctx, 'DEMO_SITE_FIXTURES') === '1' ? '1' : '0'),
    ];
    if ($dryRun) {
        $present = array_keys(array_filter($db, static fn (string $v): bool => $v !== ''));
        echo "Dry run: would upload PrestaShop $version (from $source) to DEMO_SITE_PS_ROOT on the demo server, rename admin/ to $admin/"
            . " and install it for $url with the database from .db_credentials (found: " . implode(', ', $present) . ").\n";

        return 0;
    }
    $ssh = publish_connect($ctx, 'DEMO_SITE_SSH');
    $ssh->setTimeout(3600);
    $q = static fn (string $v): string => escapeshellarg($v);
    // install/ is removed after a successful install: with it, an earlier attempt stopped half-way.
    $state = trim((string) $ssh->exec('if [ -f ' . $q("$root/install/index_cli.php") . ' ]; then echo unpacked; elif [ -f '
        . $q("$root/app/config/parameters.php") . ' ]; then echo installed; elif [ -d ' . $q($root)
        . ' ]; then find ' . $q($root) . ' -mindepth 1 -maxdepth 1 ! -name robots.txt ! -name index.html ! -name .well-known'
        . ' ! -name .htaccess ! -name favicon.ico | wc -l; else echo 0; fi'));
    if ($state === 'installed') {
        throw new RuntimeException('PrestaShop is already installed in DEMO_SITE_PS_ROOT (app/config/parameters.php exists). Next: ps-module demo-site setup');
    }
    if ($state !== '0' && $state !== 'unpacked') {
        throw new RuntimeException('DEMO_SITE_PS_ROOT is not empty (only robots.txt, index.html, .htaccess, favicon.ico and .well-known may be there): remove the rest first.');
    }
    if (!$ssh->is_dir($tools) && !$ssh->mkdir($tools, 0700, true)) {
        throw new RuntimeException("cannot create $tools.");
    }

    // Absolute: the installer runs from the install/ folder of the shop.
    $tools = (string) $ssh->realpath($tools);
    if ($state === 'unpacked') {
        echo "The files of an earlier attempt are there: running the installer again.\n";
    } else {
        echo "Packing PrestaShop $version ...\n";
        $archive = sys_get_temp_dir() . "/ps-demo-prestashop-$version.tar.gz";
        @unlink($archive);
        $tar = ['tar', '-czf', $archive, '--exclude=./var/cache/*', '--exclude=./var/logs/*', '-C', $source, '.'];
        ps_vcmd($tar);
        $process = proc_open($tar, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('cannot run tar.');
        }
        $tarError = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0 || !is_file($archive)) {
            throw new RuntimeException('tar failed: ' . trim($tarError));
        }
        printf("Uploading %.1f MB ...\n", filesize($archive) / 1048576);
        $remoteArchive = "$tools/prestashop-$version.tar.gz";
        if (!$ssh->put($remoteArchive, $archive, \phpseclib3\Net\SFTP::SOURCE_LOCAL_FILE)) {
            throw new RuntimeException('upload of the PrestaShop files failed.');
        }
        @unlink($archive);
        echo "Unpacking into DEMO_SITE_PS_ROOT ...\n";
        $output = (string) $ssh->exec('set -e; mkdir -p ' . $q($root) . '; tar --warning=no-timestamp --no-same-owner -xzf ' . $q($remoteArchive)
            . ' -C ' . $q($root) . '; rm -f ' . $q($remoteArchive) . '; if [ -d ' . $q("$root/admin") . ' ] && [ ' . $q($admin) . ' != admin ]; then mv '
            . $q("$root/admin") . ' ' . $q("$root/$admin") . '; fi; echo unpacked 2>&1');
        if (!str_contains($output, 'unpacked')) {
            throw new RuntimeException('unpacking failed: ' . trim($output));
        }
    }

    // Language packs from the local demo shop: the installer then does not download them. A download leaves the
    // database connection idle, and servers with a short wait_timeout (Hestia: 10 s) drop it ("server has gone away").
    $packs = [];
    for ($dir = $ctx['repo']; $packs === [] && $dir !== dirname($dir); $dir = dirname($dir)) {
        foreach (["$dir/shop/translations", "$dir/demo-site/shop/translations"] as $translations) {
            $packs = $packs ?: (glob("$translations/{sf,emails}-*.zip", GLOB_BRACE) ?: []);
        }
    }
    foreach ($packs as $pack) {
        if (!$ssh->put("$root/translations/" . basename($pack), $pack, \phpseclib3\Net\SFTP::SOURCE_LOCAL_FILE)) {
            throw new RuntimeException('upload of language pack ' . basename($pack) . ' failed.');
        }
    }
    if ($packs !== []) {
        echo 'Language packs from the local demo shop: ' . implode(' ', array_map('basename', $packs)) . "\n";
    }

    // The installer reads its arguments from $argv: a small runner fills that from a private file and deletes it first.
    $argsFile = "$tools/.install-args-" . bin2hex(random_bytes(6)) . '.json';
    $runner = "$tools/.install-runner.php";
    $runnerCode = "<?php\n"
        . "\$file = \$argv[1];\n"
        . "\$args = json_decode((string) file_get_contents(\$file), true);\n"
        . "unlink(\$file);\n"
        . "\$argv = array_merge(['index_cli.php'], is_array(\$args) ? \$args : []);\n"
        . "\$argc = count(\$argv);\n"
        . "\$_SERVER['argv'] = \$argv;\n"
        . "require 'index_cli.php';\n";
    if (!$ssh->put($runner, $runnerCode) || !$ssh->put($argsFile, '') || !$ssh->chmod(0600, $argsFile) || !$ssh->put($argsFile, (string) json_encode($args))) {
        $ssh->delete($argsFile);
        throw new RuntimeException('cannot write the installer settings on the server.');
    }
    echo "Installing PrestaShop $version (several minutes) ...\n";
    $output = (string) $ssh->exec('cd ' . $q("$root/install") . ' && ' . $q($php) . ' -d memory_limit=-1 -d max_execution_time=0 ' . $q($runner) . ' ' . $q($argsFile) . ' 2>&1');
    $exit = $ssh->getExitStatus();
    $ssh->delete($argsFile);
    $ssh->delete($runner);
    echo preg_replace('/^/m', '  ', rtrim($output)) . "\n";
    if ($exit !== 0 || !str_contains($output, 'Installation successful')) {
        throw new RuntimeException('the PrestaShop installer failed (see above). The files stay in DEMO_SITE_PS_ROOT; empty it before you try again.');
    }
    $ssh->exec('rm -rf ' . $q("$root/install") . ' ' . $q("$root/var/cache") . '/*');
    echo "PrestaShop $version installed (back office: $url/$admin/). Next: ps-module demo-site setup\n";

    return 0;
}

/**
 * The database of the demo shop from the .db_credentials file (nearest one upwards from the repository): KEY=value
 * or key: value lines (host/server, port, name/database, user/username, password, prefix) or PHP define()s. The values
 * are only used for the PrestaShop installer and are masked in all output; errors name fields, never values.
 *
 * @param array<string, mixed> $ctx
 *
 * @return array{host: string, port: string, name: string, user: string, password: string}
 */
function publish_db_credentials(array $ctx): array
{
    // DEMO_SITE_DB_CREDENTIALS: another file (e.g. a local test with --env).
    $file = publish_env($ctx, 'DEMO_SITE_DB_CREDENTIALS');
    if ($file !== '' && !is_file($file)) {
        throw new RuntimeException('DEMO_SITE_DB_CREDENTIALS: file not found.');
    }
    for ($dir = $ctx['repo']; $file === '' && $dir !== dirname($dir); $dir = dirname($dir)) {
        if (is_file("$dir/.db_credentials")) {
            $file = "$dir/.db_credentials";
            break;
        }
    }
    if ($file === '') {
        throw new RuntimeException('no .db_credentials file found (in the workspace root, next to .env).');
    }
    $fields = [
        'host' => ['host', 'server', 'hostname', 'dbhost', 'dbserver', 'dbhostname', 'databasehost', 'databaseserver', 'mysqlhost', 'mysqlserver'],
        'port' => ['port', 'dbport', 'databaseport', 'mysqlport'],
        'name' => ['name', 'dbname', 'database', 'databasename', 'db', 'schema', 'dbdatabase', 'mysqldatabase', 'mysqldb'],
        'user' => ['user', 'username', 'login', 'dbuser', 'dbusername', 'dblogin', 'databaseuser', 'databaseusername', 'databaselogin', 'mysqluser'],
        'password' => ['password', 'pass', 'pwd', 'passwd', 'dbpassword', 'dbpass', 'dbpwd', 'dbpasswd', 'databasepassword', 'databasepass', 'mysqlpassword', 'mysqlpwd'],
        'prefix' => ['prefix', 'dbprefix', 'tableprefix', 'databaseprefix'],
    ];
    $found = ['host' => '', 'port' => '', 'name' => '', 'user' => '', 'password' => '', 'prefix' => ''];
    $unknown = 0;
    foreach (preg_split('/\R/', (string) file_get_contents($file)) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || str_starts_with($line, '//') || str_starts_with($line, '<?php')) {
            continue;
        }
        if (preg_match('/^define\(\s*[\'"]([^\'"]+)[\'"]\s*,\s*[\'"](.*)[\'"]\s*\)\s*;?$/', $line, $m) !== 1
            && preg_match('/^(?:export\s+)?([A-Za-z][A-Za-z0-9_. -]*?)\s*[:=]\s*(.*)$/', $line, $m) !== 1) {
            ++$unknown;
            continue;
        }
        $key = strtolower((string) preg_replace('/[^A-Za-z0-9]/', '', $m[1]));
        $value = trim($m[2]);
        if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && substr($value, -1) === $value[0]) {
            $value = substr($value, 1, -1);
        }
        $field = null;
        foreach ($fields as $name => $keys) {
            if (in_array($key, $keys, true)) {
                $field = $name;
            }
        }
        if ($field === null) {
            ++$unknown;
            continue;
        }
        $found[$field] = $value;
        if ($field !== 'port' && $field !== 'prefix') {
            publish_secrets($value);
        }
    }
    $missing = array_keys(array_filter(['name' => $found['name'], 'user' => $found['user'], 'password' => $found['password']], static fn (string $v): bool => $v === ''));
    if ($missing !== []) {
        throw new RuntimeException('.db_credentials has no ' . implode(', ', $missing) . " ($unknown line(s) not recognised; use lines like DB_NAME=..., DB_USER=..., DB_PASSWORD=..., optional DB_HOST=... and DB_PORT=...).");
    }
    if ($found['prefix'] !== '' && $found['prefix'] !== 'ps_') {
        throw new RuntimeException('.db_credentials asks for another table prefix: the demo snapshots use ps_.');
    }
    if ($found['host'] !== '' && $found['port'] === '' && preg_match('/^(.+):(\d+)$/', $found['host'], $m) === 1) {
        [$found['host'], $found['port']] = [$m[1], $m[2]];
    }
    // Field names only, never values.
    ps_vlog('database settings: ' . $file . ': ' . implode(', ', array_keys(array_filter($found, static fn (string $v): bool => $v !== '')))
        . ($unknown > 0 ? " ($unknown line(s) not recognised)" : ''));

    return ['host' => $found['host'] ?: 'localhost', 'port' => $found['port'], 'name' => $found['name'], 'user' => $found['user'], 'password' => $found['password']];
}
