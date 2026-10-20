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
 *                                       put dist/<module>.zip in the demo shop (SSH, demo-site.sh deploy): back to the
 *                                       latest snapshot, install or upgrade, smoke test, new snapshot (files + database)
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
 *   --env=<file>                        read the settings from this file first (e.g. demo-site/local.env)
 *   ps-module publish vps-snapshot [<description>] [--dry-run]
 *                                       TransIP snapshot of the whole demo/docs VPS (TRANSIP_*): disaster backup.
 *                                       `publish docs` and `publish demo` make one after a successful deploy (unless
 *                                       --no-snapshot). The API key is never on the VPS itself.
 *   ps-module publish done addons       record that dist/<module>.zip of this version is uploaded to PrestaShop Addons
 *                                       (by hand in the seller account: Addons has no upload API)
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
 *   DEMO_SITE_CONNECT_TO (curl --connect-to for the smoke test), DEMO_SITE_DEMO_MODE (on), DEMO_SITE_EMAIL
 *   (demo@demo.com) + DEMO_SITE_PASSWORD (demodemo): the public demo login, DEMO_SITE_INTRO_PATH (start): intro page
 *   TRANSIP_LOGIN, TRANSIP_PRIVATE_KEY (file, or the key itself), TRANSIP_VPS_NAME, TRANSIP_SNAPSHOT_KEEP (1),
 *   TRANSIP_GLOBAL_KEY (true; false = the key only works from whitelisted IPs)
 * In CI the key can be given as text: DOCS_SITE_SFTP_KEY / DEMO_SITE_SSH_KEY may hold the key itself.
 * The recorded steps are in <repo>/.cache/publish/<version>.json (local, not in git).
 */

declare(strict_types=1);

require_once __DIR__ . '/dod.php';

const PUBLISH_STEPS = ['addons', 'docs', 'demo'];

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

    try {
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
            case 'demo-site':
                return publish_cmd_demo_site($ctx, $args, $dryRun);
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
        fwrite(STDERR, 'ps-module publish: ' . $e->getMessage() . "\n");

        return 1;
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
    ];
    $labels = ['addons' => 'Addons upload', 'docs' => 'Docs site (PDFs)', 'demo' => 'Demo shop'];
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
    if ($step !== 'addons') {
        fwrite(STDERR, "ps-module publish done: only 'addons' is a manual step (docs and demo record themselves).\n");

        return 2;
    }
    publish_record($ctx, 'addons', 'uploaded by hand');
    echo "Recorded: {$ctx['module']} {$ctx['version']} is uploaded to PrestaShop Addons.\n";

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
        echo "Dry run: would upload " . count($files) . " file(s) to $remote/ on " . publish_require_env($ctx, 'DOCS_SITE_SFTP_HOST') . " and remove other files there.\n";

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
    foreach ($sftp->nlist($remote) ?: [] as $file) {
        if (!in_array($file, ['.', '..'], true) && !in_array($file, $files, true) && $sftp->is_file("$remote/$file")) {
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

    return 0;
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
 * demo-install.php) to DEMO_SITE_TOOLS_DIR, then runs `demo-site.sh deploy` there: back to the latest snapshot (a
 * clean shop), install or upgrade, smoke test, new snapshot (files + database). On a failure the server puts the
 * snapshot of before back. Records step 'demo'.
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
    if ($dryRun) {
        echo "Dry run: would upload {$ctx['module']}.zip to " . publish_require_env($ctx, 'DEMO_SITE_SSH_HOST') . ":$tools/uploads/ and run demo-site.sh deploy (restore latest snapshot, install/upgrade, test, new snapshot).\n";

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
        throw new RuntimeException('deploying to the demo shop failed (exit ' . var_export($exit, true) . '); the server put the previous snapshot back.');
    }
    $version = (string) end($m[1]);
    if ($version !== $ctx['version']) {
        throw new RuntimeException("the demo shop reports version $version instead of {$ctx['version']}.");
    }
    $url = publish_env($ctx, 'DEMO_SITE_URL');
    publish_record($ctx, 'demo', "version $version" . ($url !== '' ? " on $url" : '') . ', new snapshot');
    echo "Demo shop has {$ctx['module']} $version and a new snapshot" . ($url !== '' ? " ($url)" : '') . ".\n";

    return 0;
}

/**
 * The demo shop itself (not one module): `ps-module demo-site setup` uploads the tools and writes demo-site.conf
 * from the DEMO_SITE_* settings, makes the first snapshot, installs the nightly cron job and tests the shop.
 * status, snapshot, restore [<name>], test and nightly run demo-site.sh with that command.
 *
 * @param array<string, mixed> $ctx
 * @param string[] $args
 */
function publish_cmd_demo_site(array $ctx, array $args, bool $dryRun): int
{
    $command = (string) ($args[0] ?? 'status');
    if (!in_array($command, ['setup', 'status', 'snapshot', 'restore', 'test', 'nightly', 'demo-mode', 'settings', 'config', 'push', 'vps-snapshot'], true)) {
        fwrite(STDERR, "ps-module demo-site: setup | status | snapshot [note] | restore [<snapshot>] | test | nightly | demo-mode on|off | settings | config | push <snapshot-dir> | vps-snapshot [<description>]\n");

        return 2;
    }
    if ($command === 'vps-snapshot') {
        return publish_cmd_vps_snapshot($ctx, (string) ($args[1] ?? 'manual snapshot ' . date('Y-m-d H:i')), $dryRun) ? 0 : 1;
    }
    if ($command === 'push') {
        return publish_demo_push($ctx, (string) ($args[1] ?? ''), $dryRun);
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
    $output = (string) $ssh->exec('bash ' . escapeshellarg("$tools/demo-site.sh") . " $command 2>&1");
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
        printf("Dry run: would upload %s (%.1f MB) as snapshot %s to %s and activate it.\n", $dir, $size, $name, publish_require_env($ctx, 'DEMO_SITE_SSH_HOST'));

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
        fwrite(STDERR, "ps-module publish: the deploy is done, but the VPS snapshot failed: {$e->getMessage()}\n"
            . "  Try again later: vendor/bin/ps-module publish vps-snapshot\n");

        return 1;
    }
}

/**
 * TransIP snapshot of the VPS (TRANSIP_VPS_NAME) through the REST API v6: skipped when the VPS is busy (isLocked);
 * creates the snapshot (the VPS keeps running), and when the limit is reached removes the oldest active snapshot and
 * tries once more; then keeps the newest TRANSIP_SNAPSHOT_KEEP. Returns true when the snapshot was requested.
 *
 * @param array<string, mixed> $ctx
 *
 * @throws RuntimeException when the API refuses
 */
function publish_cmd_vps_snapshot(array $ctx, string $description, bool $dryRun): bool
{
    $vps = publish_require_env($ctx, 'TRANSIP_VPS_NAME');
    $keep = max(1, (int) (publish_env($ctx, 'TRANSIP_SNAPSHOT_KEEP') ?: 1));
    if ($dryRun) {
        echo "Dry run: would create a TransIP snapshot of $vps (\"$description\") and keep the newest $keep.\n";

        return true;
    }
    $token = publish_transip_token($ctx);
    $info = publish_transip($token, 'GET', "/vps/$vps");
    if (!empty($info['body']['vps']['isLocked'])) {
        fwrite(STDERR, "ps-module publish: VPS $vps is busy (another action, e.g. a snapshot, is running): no new snapshot. Try later: vendor/bin/ps-module publish vps-snapshot\n");

        return false;
    }
    $body = ['description' => substr($description, 0, 100), 'shouldStartVps' => true];
    $created = publish_transip($token, 'POST', "/vps/$vps/snapshots", $body, false);
    if ($created['status'] !== 201 && str_contains(strtolower((string) ($created['body']['error'] ?? '')), 'limit')) {
        $oldest = publish_transip_snapshots($token, $vps)[0] ?? null;
        if ($oldest === null) {
            throw new RuntimeException('snapshot limit reached and no active snapshot to remove.');
        }
        echo "  snapshot limit reached: removing the oldest ({$oldest['name']}, {$oldest['dateTimeCreate']})\n";
        publish_transip($token, 'DELETE', "/vps/$vps/snapshots/{$oldest['name']}");
        $created = publish_transip($token, 'POST', "/vps/$vps/snapshots", $body, false);
    }
    if ($created['status'] !== 201) {
        throw new RuntimeException('TransIP refused the snapshot (HTTP ' . $created['status'] . '): ' . (string) ($created['body']['error'] ?? ''));
    }
    echo "TransIP snapshot of $vps requested: \"{$body['description']}\" (TransIP makes it in the background).\n";
    // Keep the newest $keep: the new one is still "creating" and not in the list of active snapshots.
    $active = publish_transip_snapshots($token, $vps);
    foreach (array_slice($active, 0, max(0, count($active) - ($keep - 1))) as $old) {
        publish_transip($token, 'DELETE', "/vps/$vps/snapshots/{$old['name']}");
        echo "  removed old snapshot {$old['name']} ({$old['dateTimeCreate']})\n";
    }

    return true;
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
function publish_transip_token(array $ctx): string
{
    $keySetting = publish_require_env($ctx, 'TRANSIP_PRIVATE_KEY');
    $pem = is_file($keySetting) ? (string) file_get_contents($keySetting) : str_replace('\n', "\n", $keySetting);
    $key = openssl_pkey_get_private($pem);
    if ($key === false) {
        throw new RuntimeException('TRANSIP_PRIVATE_KEY is not a readable private key.');
    }
    $body = (string) json_encode([
        'login' => publish_require_env($ctx, 'TRANSIP_LOGIN'),
        'nonce' => bin2hex(random_bytes(16)),
        'read_only' => false,
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
    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
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
        return;
    }
    $tag = trim((string) shell_exec('git -C ' . escapeshellarg($ctx['repo']) . ' tag -l ' . escapeshellarg('v' . $ctx['version'])));
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
    $file = publish_env_file();
    if ($file !== [] && (array_key_exists($key, $file) || preg_match('/^(DEMO_SITE|DOCS_SITE|TRANSIP)_/', $key) === 1)) {
        // With --env the server settings come only from that file: a local test must never reach the real servers
        // or the TransIP account through a value in .env.
        return $file[$key] ?? '';
    }
    $value = getenv($key);
    if (is_string($value) && $value !== '') {
        return $value;
    }
    for ($dir = $ctx['repo']; $dir !== dirname($dir); $dir = dirname($dir)) {
        if (is_file("$dir/.env") && preg_match('/^\s*' . preg_quote($key, '/') . '\s*=\s*(.*?)\s*$/m', (string) file_get_contents("$dir/.env"), $m)) {
            $found = trim($m[1], "\"'");
            if ($found !== '') {
                return $found;
            }
        }
    }

    return '';
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
    $host = publish_require_env($ctx, $prefix . '_HOST');
    $user = publish_require_env($ctx, $prefix . '_USER');
    $port = (int) (publish_env($ctx, $prefix . '_PORT') ?: 22);
    $key = publish_env($ctx, $prefix . '_KEY');
    $password = publish_env($ctx, $prefix . '_PASSWORD');
    if ($key !== '') {
        $passphrase = publish_env($ctx, $prefix . '_KEY_PASSPHRASE');
        $text = is_file($key) ? (string) file_get_contents($key) : str_replace('\n', "\n", $key);
        $credential = \phpseclib3\Crypt\PublicKeyLoader::load($text, $passphrase !== '' ? $passphrase : false);
    } elseif ($password !== '') {
        $credential = $password;
    } else {
        throw new RuntimeException("{$prefix}_KEY or {$prefix}_PASSWORD is not set.");
    }
    $sftp = new \phpseclib3\Net\SFTP($host, $port, 20);
    if (!$sftp->login($user, $credential)) {
        throw new RuntimeException("login as $user on $host:$port failed.");
    }

    return $sftp;
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
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    unset($ch);

    return is_string($body) ? ['status' => $status, 'body' => $body] : null;
}
