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
 * Docker side of ps-module docs (used by docs.php and docs-render.php):
 *
 * - the docs-tools image (resources/docs/Dockerfile: Playwright + Chromium + Noto fonts + poppler),
 *   built on first use and tagged prestashopheaven/docs-tools:<hash of the Dockerfile>;
 * - screenshots in the test shop of testing.json (the same shop as `ps-module shops`: port
 *   base_port + major*100 + minor*10 + patch, compose project <module>-ps<version>). A running shop
 *   gets the current code (`shops deploy`), otherwise the shop is started (`shops up`) and stopped
 *   again afterwards (unless --keep). Missing languages are installed in the shop; when that is
 *   not possible the screenshots of that language show the shop in the source language.
 *
 * The browser container joins the Docker network of the shop and forwards localhost:<port> to the
 * shop container (runner.cjs), so the shop sees the host name it was installed with.
 */

declare(strict_types=1);

require_once __DIR__ . '/verbose.php';
require_once __DIR__ . '/lock.php';

/**
 * Runs a command (an argument list, no shell) with a timeout; returns the exit code and the output.
 * With $stream the output goes straight to the console (and the returned output is empty).
 *
 * @param string[] $command
 *
 * @return array{0: int, 1: string}
 */
function docs_exec(array $command, int $timeout, ?string $cwd = null, bool $stream = false): array
{
    $out = tempnam(sys_get_temp_dir(), 'psdocs');
    $descriptors = $stream
        ? [0 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'], 1 => STDOUT, 2 => STDERR]
        : [0 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'], 1 => ['file', $out, 'w'], 2 => ['file', $out, 'a']];
    ps_vcmd($command, $cwd);
    $start = microtime(true);
    $process = @proc_open($command, $descriptors, $pipes, $cwd);
    if (!is_resource($process)) {
        @unlink($out);
        ps_vdone('could not start ' . $command[0], $start, 127);

        return [127, 'could not start ' . $command[0]];
    }
    $deadline = time() + $timeout;
    $code = -1;
    while (true) {
        $status = proc_get_status($process);
        if (!$status['running']) {
            $code = (int) $status['exitcode'];
            break;
        }
        if (time() > $deadline) {
            proc_terminate($process, 9);
            $code = 124;
            break;
        }
        usleep(200000);
    }
    proc_close($process);
    $output = $stream ? '' : (string) @file_get_contents($out);
    @unlink($out);
    if ($code === 124) {
        $output .= "\n(timed out after {$timeout}s)";
    }
    ps_vdone(basename((string) $command[0]) . ($code === 124 ? " (timed out after {$timeout}s)" : ''), $start, $code);
    docs_voutput($output);

    return [$code, $output];
}

/**
 * Verbose: shows the captured output of a program (the last 60 lines, at most 6000 characters).
 */
function docs_voutput(string $output): void
{
    $output = trim($output);
    if (!ps_verbose() || $output === '') {
        return;
    }
    $lines = preg_split('/\R/', $output) ?: [];
    $cut = count($lines) > 60 ? '(' . (count($lines) - 60) . " earlier lines left out)\n" : '';
    $text = implode("\n", array_slice($lines, -60));
    if (strlen($text) > 6000) {
        $text = '...' . substr($text, -6000);
    }
    ps_vlog("output:\n" . $cut . $text);
}

/**
 * Verbose: describes the tasks of a docs container job, item by item (screenshot URL, viewport, selector, files).
 *
 * @param array<int, array<string, mixed>> $tasks
 */
function docs_vtasks(array $tasks): void
{
    if (!ps_verbose()) {
        return;
    }
    foreach ($tasks as $task) {
        $type = (string) ($task['type'] ?? '?');
        if ($type === 'screenshots') {
            $hide = array_map('strval', (array) ($task['hide'] ?? []));
            ps_vlog('task screenshots: ' . count((array) ($task['shots'] ?? [])) . ' in ' . ($task['base'] ?? '?') . ', hidden elements: ' . ($hide === [] ? 'none' : implode(', ', $hide)));
            foreach ((array) ($task['shots'] ?? []) as $shot) {
                $viewport = $shot['viewport'] ?? 'desktop';
                $selector = $shot['selector'] ?? '';
                ps_vlog(sprintf(
                    '  shot %s/%s: %s %s, viewport %s%s -> %s',
                    $shot['language'] ?? '?',
                    $shot['id'] ?? '?',
                    strtoupper((string) ($shot['area'] ?? '?')),
                    ($shot['area'] ?? '') === 'fo' ? ($task['base'] ?? '') . ($shot['path'] ?? '') : (string) ($shot['path'] ?? ''),
                    is_array($viewport) ? ($viewport['width'] ?? '?') . 'x' . ($viewport['height'] ?? '?') : (string) $viewport,
                    $selector !== '' && $selector !== [] ? ', selector ' . (is_string($selector) ? $selector : (string) json_encode($selector)) : '',
                    $shot['out'] ?? '?'
                ));
            }
            continue;
        }
        ps_vlog("task $type: " . count((array) ($task['items'] ?? [])) . ' item(s)');
        foreach ((array) ($task['items'] ?? []) as $item) {
            $size = isset($item['width']) ? ' (' . $item['width'] . 'x' . ($item['height'] ?? '?') . (!empty($item['transparent']) ? ', transparent' : '') . ')' : (isset($item['size']) ? ' (' . $item['size'] . ' px)' : '');
            ps_vlog('  ' . ($item['html'] ?? $item['in'] ?? '?') . ' -> ' . ($item['out'] ?? '?') . $size);
        }
    }
}

/**
 * '' when Docker is installed and running, otherwise the reason.
 */
function docs_docker_available(): string
{
    static $state = null;
    if ($state === null) {
        [$code, $output] = docs_exec(['docker', 'info', '--format', '{{.ServerVersion}}'], 60);
        if ($code === 127) {
            $state = 'Docker is not installed';
        } elseif ($code !== 0 || !preg_match('/^\d+\.\d+/', trim($output))) {
            $state = 'Docker is not running (start Docker Desktop)';
        } else {
            $state = '';
        }
        ps_vlog('Docker: ' . ($state === '' ? 'running (server ' . trim($output) . ')' : $state));
    }

    return $state;
}

/**
 * Tag of the docs-tools image: changes with the Dockerfile.
 */
function docs_image_tag(): string
{
    $dockerfile = str_replace("\r\n", "\n", (string) file_get_contents(DOCS_RESOURCES . '/docs/Dockerfile'));

    return 'prestashopheaven/docs-tools:' . substr(sha1($dockerfile), 0, 12);
}

/**
 * True when the docs-tools image for the current Dockerfile exists locally.
 */
function docs_image_exists(): bool
{
    return docs_exec(['docker', 'image', 'inspect', docs_image_tag(), '--format', '{{.Id}}'], 60)[0] === 0;
}

/**
 * Builds the docs-tools image when it does not exist yet (first run: a few minutes).
 *
 * @return array{error: string}
 */
function docs_ensure_image(): array
{
    static $result = null;
    if ($result !== null) {
        return $result;
    }
    if (docs_image_exists()) {
        ps_vlog('docs image ' . docs_image_tag() . ': present');

        return $result = ['error' => ''];
    }
    ps_vlog('docs image ' . docs_image_tag() . ': not present, building it from ' . DOCS_RESOURCES . '/docs/Dockerfile');
    // Two modules building the same image at the same time: the second waits and then finds it.
    try {
        ps_lock('docs-image', 'docker build ' . docs_image_tag(), (string) getcwd());
    } catch (RuntimeException $e) {
        return $result = ['error' => $e->getMessage()];
    }
    if (docs_image_exists()) {
        ps_unlock('docs-image');

        return $result = ['error' => ''];
    }
    echo 'Building the docs image ' . docs_image_tag() . " (Chromium + Noto fonts; only the first time, a few minutes)...\n";
    [$code] = docs_exec(['docker', 'build', '-t', docs_image_tag(), '-f', DOCS_RESOURCES . '/docs/Dockerfile', DOCS_RESOURCES . '/docs'], 1800, null, true);
    ps_unlock('docs-image');

    return $result = ['error' => $code === 0 ? '' : 'building the docs image failed (docker build, exit code ' . $code . ')'];
}

/**
 * The path of a repository file inside the docs container (/docs = docs/, /cache = .cache/docs/).
 *
 * @param array<string, mixed> $ctx
 */
function docs_container_path(array $ctx, string $file): string
{
    $file = str_replace('\\', '/', $file);
    foreach ([$ctx['docs'] => '/docs', $ctx['repo'] . '/.cache/docs' => '/cache'] as $host => $container) {
        if (strncasecmp($file, $host . '/', strlen($host) + 1) === 0) {
            return $container . substr($file, strlen($host));
        }
    }

    return $file;
}

/**
 * docker run arguments for the mounts (docs/, .cache/docs/, the runner and optionally the job directory) and the user.
 *
 * @param array<string, mixed> $ctx
 *
 * @return string[]
 */
function docs_mount_args(array $ctx, ?string $jobDir): array
{
    $cache = $ctx['repo'] . '/.cache/docs';
    @mkdir($cache, 0775, true);
    @mkdir($ctx['docs'], 0775, true);
    $args = [
        '--mount', 'type=bind,source=' . $ctx['docs'] . ',target=/docs',
        '--mount', 'type=bind,source=' . $cache . ',target=/cache',
        '--mount', 'type=bind,source=' . dod_norm(DOCS_RESOURCES . '/docs') . ',target=/runner,readonly',
        '-e', 'HOME=/tmp',
    ];
    if ($jobDir !== null) {
        array_push($args, '--mount', 'type=bind,source=' . $jobDir . ',target=/job');
    }
    if (PHP_OS_FAMILY === 'Linux' && function_exists('posix_getuid')) {
        array_push($args, '--user', posix_getuid() . ':' . posix_getgid());
    }

    return $args;
}

/**
 * Runs tasks in the docs-tools container (runner.cjs) and returns their results.
 *
 * @param array<string, mixed> $ctx
 * @param array<int, array<string, mixed>> $tasks
 *
 * @return array{error: string, results: array<int, array<string, mixed>>}
 */
function docs_run_container(array $ctx, array $tasks, ?string $network): array
{
    $jobDir = $ctx['repo'] . '/.cache/docs/job-' . uniqid();
    @mkdir($jobDir, 0775, true);
    file_put_contents("$jobDir/job.json", json_encode(['tasks' => $tasks], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    ps_vlog('docs container job ' . $jobDir . ($network !== null ? ', network ' . $network : ', no network'));
    docs_vtasks($tasks);
    $command = array_merge(['docker', 'run', '--rm', '--shm-size=1g'], $network !== null ? ['--network', $network] : [], docs_mount_args($ctx, $jobDir), [docs_image_tag(), 'node', '/runner/runner.cjs', '/job/job.json']);
    [$code, $output] = docs_exec($command, 1800);
    $results = docs_read_json("$jobDir/result.json");
    docs_remove_dir($jobDir);
    foreach ($results ?? [] as $result) {
        ps_vlog('  result ' . (!empty($result['ok']) ? 'ok   ' : 'FAIL ') . ($result['out'] ?? $result['id'] ?? '?') . (!empty($result['ok']) ? '' : ': ' . ($result['error'] ?? '?')));
    }
    if ($results === null) {
        ps_vlog('docs container job: no result.json (exit code ' . $code . ')');
        return ['error' => 'the docs container failed (exit code ' . $code . '): ' . substr(trim($output), -600), 'results' => []];
    }

    return ['error' => '', 'results' => $results];
}

/**
 * Deletes a directory with everything in it.
 */
function docs_remove_dir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $item) {
        $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }
    @rmdir($dir);
}

// ---------------------------------------------------------------------------
// Test shop

/**
 * The PrestaShop version used for screenshots: docs.json > shop.version, "latest" = newest of testing.json.
 *
 * @param array<string, mixed> $ctx
 */
function docs_shop_version(array $ctx): string
{
    $version = (string) ($ctx['config']['shop']['version'] ?? 'latest');

    return $version === 'latest' ? (string) $ctx['ps_tested'] : $version;
}

/**
 * Port of the test shop of a version (the same formula as the Shop class of ps-test.php).
 *
 * @param array<string, mixed> $ctx
 */
function docs_shop_port(array $ctx, string $version): int
{
    $parts = array_map('intval', explode('.', $version));

    return (int) ($ctx['testing']['base_port'] ?? 8000) + $parts[0] * 100 + ($parts[1] ?? 0) * 10 + ($parts[2] ?? 0);
}

/**
 * Container id of the PrestaShop service of a compose project ('' when not running).
 */
function docs_shop_container(string $project): string
{
    [$code, $output] = docs_exec(['docker', 'ps', '-q', '--filter', 'label=com.docker.compose.project=' . $project, '--filter', 'label=com.docker.compose.service=prestashop'], 60);

    return $code === 0 ? trim(strtok(trim($output), "\n") ?: '') : '';
}

/**
 * The Docker network of a container (the compose network of the shop).
 */
function docs_shop_network(string $container): string
{
    [, $output] = docs_exec(['docker', 'inspect', '-f', '{{range $k, $v := .NetworkSettings.Networks}}{{$k}} {{end}}', $container], 60);

    return trim(strtok(trim($output), ' ') ?: '');
}

/**
 * Runs shop-probe.php in the shop container and returns its JSON answer.
 *
 * @param string[] $args
 *
 * @return array<string, mixed>
 */
function docs_shop_probe(string $container, array $args): array
{
    [, $output] = docs_exec(array_merge(['docker', 'exec', '-u', 'www-data', $container, 'php', '-d', 'memory_limit=1G', '/tmp/docs-probe.php'], $args), 600);
    $start = strpos($output, '{"ok"');
    $data = $start === false ? null : json_decode(substr($output, $start), true);

    return is_array($data) ? $data : ['ok' => false, 'message' => substr(trim($output), -400)];
}

/**
 * Runs a ps-module shops command (ps-test.php) for this repository, with its output on the console.
 *
 * @param array<string, mixed> $ctx
 * @param string[] $args
 */
function docs_ps_test(array $ctx, array $args): int
{
    return docs_exec(array_merge([PHP_BINARY, __DIR__ . '/ps-test.php'], $args), 3600, $ctx['repo'], true)[0];
}

/**
 * HTTP status of a URL (0 when there is no answer).
 */
function docs_http_status(string $url): int
{
    $context = stream_context_create(['http' => ['timeout' => 20, 'ignore_errors' => true, 'follow_location' => 0]]);
    $start = microtime(true);
    @file_get_contents($url, false, $context);
    $status = 0;
    foreach ($http_response_header ?? [] as $header) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $m)) {
            $status = (int) $m[1];
        }
    }
    ps_vdone("GET $url: HTTP " . ($status === 0 ? 'no answer' : $status), $start);

    return $status;
}

// ---------------------------------------------------------------------------
// Screenshots

/**
 * Hash of the settings that determine the screenshots (shop block and screenshot list of docs.json, and the
 * content of the shop.setup script: changed demo data means new screenshots).
 *
 * @param array<string, mixed> $ctx
 */
function docs_shots_config_hash(array $ctx): string
{
    $setup = docs_shop_setup_file($ctx);
    $setupHash = $setup !== null && is_file($setup) ? sha1((string) file_get_contents($setup)) : '';

    return substr(sha1((string) json_encode([$ctx['config']['shop'] ?? [], $ctx['config']['screenshots'] ?? [], $ctx['languages'], $setupHash])), 0, 12);
}

/**
 * The shop.setup script of docs.json as a path in docs/, or null when there is none. The script prepares the test
 * shop for the screenshots (demo data); docs_shots_on_shop() runs it after the module is installed.
 *
 * @param array<string, mixed> $ctx
 */
function docs_shop_setup_file(array $ctx): ?string
{
    $setup = trim((string) ($ctx['config']['shop']['setup'] ?? ''));
    if ($setup === '') {
        return null;
    }

    return $ctx['docs'] . '/' . ltrim(str_replace('\\', '/', $setup), '/');
}

/**
 * Runs the shop.setup script of docs.json in the test shop (with PrestaShop loaded, see resources/docs/shop-probe.php
 * "setup"). Returns null when it ran or there is none, else the reason it failed.
 *
 * @param array<string, mixed> $ctx
 */
function docs_run_shop_setup(array $ctx, string $container): ?string
{
    $file = docs_shop_setup_file($ctx);
    if ($file === null) {
        return null;
    }
    ps_vlog("shop.setup: $file in container $container");
    if (!is_file($file) || !str_ends_with($file, '.php')) {
        return 'docs.json > shop.setup: ' . $ctx['config']['shop']['setup'] . ' is not a PHP file in docs/';
    }
    echo '  setup: running docs/' . ltrim((string) $ctx['config']['shop']['setup'], '/') . "...\n";
    docs_exec(['docker', 'cp', $file, "$container:/tmp/docs-setup.php"], 60);
    docs_exec(['docker', 'exec', '-u', 'root', $container, 'chmod', '644', '/tmp/docs-setup.php'], 60);
    $result = docs_shop_probe($container, ['setup', '/tmp/docs-setup.php']);
    if (empty($result['ok'])) {
        return 'docs.json > shop.setup failed: ' . ($result['message'] ?? '?');
    }
    if (($result['message'] ?? '') !== '') {
        echo '  setup: ' . $result['message'] . "\n";
    }

    return null;
}

/**
 * Are the screenshots there and taken from the current code and settings?
 *
 * @param array<string, mixed> $ctx
 *
 * @return array{state: string, message: string, ps_version?: string, taken?: string, fallbacks?: array<string, string>, failed?: string[]}
 */
function docs_shots_state(array $ctx): array
{
    $configured = (array) ($ctx['config']['screenshots'] ?? []);
    if ($configured === []) {
        return ['state' => 'ok', 'message' => 'none configured in docs.json'];
    }
    $manifest = docs_read_json($ctx['docs'] . '/screenshots/manifest.json');
    $missing = [];
    foreach ($configured as $shot) {
        if (docs_screenshot_file($ctx, $ctx['source'], (string) ($shot['id'] ?? '')) === null) {
            $missing[] = (string) ($shot['id'] ?? '?');
        }
    }
    ps_vlog('screenshots: manifest ' . ($manifest === null ? 'missing' : 'found') . ', ' . count($configured) . ' configured, missing in ' . $ctx['source'] . ': ' . ($missing === [] ? 'none' : implode(', ', $missing)));
    if ($manifest === null || $missing !== []) {
        return ['state' => 'missing', 'message' => 'missing: ' . ($missing === [] ? 'all' : implode(', ', $missing)) . ' (run: ps-module docs screenshots, needs Docker)'];
    }
    $info = ['ps_version' => (string) ($manifest['ps_version'] ?? ''), 'taken' => substr((string) ($manifest['time'] ?? ''), 0, 16), 'fallbacks' => (array) ($manifest['fallbacks'] ?? []), 'failed' => (array) ($manifest['failed'] ?? [])];
    ps_vlog('screenshots: settings hash ' . ($manifest['config'] ?? '-') . ' (now ' . docs_shots_config_hash($ctx) . '), code fingerprint ' . substr((string) ($manifest['fingerprint'] ?? '-'), 0, 12) . ' (now ' . substr(dod_fingerprint($ctx['repo']), 0, 12) . ')');
    if (($manifest['config'] ?? '') !== docs_shots_config_hash($ctx)) {
        return ['state' => 'stale', 'message' => 'docs.json screenshot settings or languages changed since ' . $info['taken']] + $info;
    }
    if (($manifest['fingerprint'] ?? '') !== dod_fingerprint($ctx['repo'])) {
        return ['state' => 'stale', 'message' => 'module code changed since ' . $info['taken']] + $info;
    }

    return ['state' => 'ok', 'message' => 'up to date (PrestaShop ' . $info['ps_version'] . ', ' . $info['taken'] . ')'] + $info;
}

/**
 * Takes the screenshots of docs.json for every language. Screenshots with their own "version" are taken in
 * the test shop of that PrestaShop version (e.g. Classic on 8.x and Hummingbird on 9.x), the others in the
 * shop of docs.json > shop.version.
 *
 * @param array<string, mixed> $ctx
 * @param string[] $only screenshot ids (empty = all)
 *
 * @return array{ok: bool, message: string, short: string}
 */
function docs_take_screenshots(array $ctx, bool $keep, array $only): array
{
    $fail = static fn (string $short): array => ['ok' => false, 'short' => $short, 'message' => "Screenshots NOT taken: $short"];
    $shots = array_values(array_filter((array) ($ctx['config']['screenshots'] ?? []), static fn ($s): bool => is_array($s) && ($only === [] || in_array($s['id'] ?? '', $only, true))));
    ps_vlog('screenshots to take: ' . ($shots === [] ? 'none' : implode(', ', array_map(static fn (array $s): string => (string) ($s['id'] ?? '?'), $shots))) . ($only !== [] ? ' (--only=' . implode(',', $only) . ')' : '') . '; languages: ' . implode(' ', $ctx['languages']) . ($keep ? '; --keep' : ''));
    if ($shots === []) {
        return ['ok' => true, 'short' => '', 'message' => 'No screenshots configured in docs/docs.json.'];
    }
    if (!is_array($ctx['testing'])) {
        return $fail('no testing.json (screenshots need the Docker test shops of ps-module shops)');
    }
    $problem = docs_docker_available();
    if ($problem !== '') {
        return $fail($problem);
    }
    $image = docs_ensure_image();
    if ($image['error'] !== '') {
        return $fail($image['error']);
    }
    $default = docs_shop_version($ctx);
    ps_vlog("default shop version: $default (docs.json > shop.version: " . ($ctx['config']['shop']['version'] ?? 'latest') . '; "latest" = the newest of testing.json)');
    $groups = [];
    foreach ($shots as $shot) {
        $groups[docs_shot_version($ctx, $shot)][] = $shot;
    }
    foreach ($groups as $version => $list) {
        ps_vlog("PrestaShop $version: " . implode(', ', array_map(static fn (array $s): string => (string) ($s['id'] ?? '?'), $list)) . ((string) $version === $default ? ' (the default shop version)' : ' (their own "version")'));
    }
    $results = [];
    $fallbacks = [];
    $notes = [];
    $languages = [];
    foreach ($groups as $version => $list) {
        $run = docs_shots_on_shop($ctx, (string) $version, $list, $keep);
        if (isset($run['error'])) {
            foreach ($list as $shot) {
                $results[] = ['ok' => false, 'id' => $shot['id'] ?? '?', 'language' => '*', 'error' => $run['error']];
            }
            continue;
        }
        $results = array_merge($results, $run['results']);
        $fallbacks += $run['fallbacks'];
        $notes = array_merge($notes, $run['notes']);
        if ((string) $version === $default || $languages === []) {
            $languages = $run['languages'];
        }
    }

    $failed = [];
    $lines = [];
    foreach ($results as $result) {
        $label = ($result['language'] ?? '?') . '/' . ($result['id'] ?? '?');
        if (!empty($result['ok'])) {
            $lines[] = "  ok    $label  " . docs_rel($ctx, $ctx['docs'] . substr((string) $result['out'], 5));
        } else {
            $failed[] = "$label: " . ($result['error'] ?? '?');
            $lines[] = "  FAIL  $label  " . ($result['error'] ?? '?');
        }
    }
    foreach ($fallbacks as $iso => $reason) {
        $lines[] = "  note  $iso: uses the {$ctx['source']} screenshots ($reason)";
    }
    foreach (array_unique($notes) as $note) {
        $lines[] = "  note  $note";
    }
    $manifest = docs_read_json($ctx['docs'] . '/screenshots/manifest.json') ?? [];
    $complete = $failed === [] && $only === [];
    ps_vlog('screenshots manifest: ' . ($complete ? 'complete run, recorded as up to date for this code' : 'incomplete run (failures or --only): the previous fingerprint is kept'));
    docs_write_json($ctx['docs'] . '/screenshots/manifest.json', [
        '_comment' => 'Generated by ps-module docs screenshots. Do not edit.',
        'ps_version' => $only === [] ? implode(', ', array_keys($groups)) : (string) ($manifest['ps_version'] ?? implode(', ', array_keys($groups))),
        'time' => date('c'),
        // Only a complete run makes the screenshots up to date for this code.
        'fingerprint' => $complete ? dod_fingerprint($ctx['repo']) : (string) ($manifest['fingerprint'] ?? ''),
        'config' => $complete ? docs_shots_config_hash($ctx) : (string) ($manifest['config'] ?? ''),
        'languages' => $languages,
        'fallbacks' => $fallbacks,
        'notes' => array_values(array_unique($notes)),
        'failed' => $failed,
    ]);
    $summary = 'Screenshots (PrestaShop ' . implode(', ', array_keys($groups)) . '): ' . (count($results) - count($failed)) . ' taken, ' . count($failed) . " failed\n" . implode("\n", $lines);

    return ['ok' => $failed === [], 'short' => $failed === [] ? '' : count($failed) . ' screenshot(s) failed', 'message' => $summary];
}

/**
 * The PrestaShop version a screenshot is taken in: its own "version" ("latest"/"oldest" = newest/oldest of
 * testing.json, typically Hummingbird on 9.x and Classic on 8.x), else shop.version.
 *
 * @param array<string, mixed> $ctx
 * @param array<string, mixed> $shot
 */
function docs_shot_version(array $ctx, array $shot): string
{
    $version = (string) ($shot['version'] ?? '');

    return match ($version) {
        '' => docs_shop_version($ctx),
        'latest' => (string) $ctx['ps_tested'],
        'oldest' => (string) $ctx['ps_oldest'],
        default => $version,
    };
}

/**
 * PrestaShop's own ISO code of a language (ps_lang.iso_code, also the language in shop URLs). It differs from
 * the code in i18n.json for Slovenian (si) and Vietnamese (vn); without it those languages were never installed
 * in the test shop and got the screenshots of the source language.
 *
 * @param array<string, mixed> $ctx
 */
function docs_shop_iso(array $ctx, string $iso): string
{
    return (string) ($ctx['catalog'][$iso]['ps_iso'] ?? $iso);
}

/**
 * Takes screenshots in the test shop of one PrestaShop version: starts it (or deploys the current code
 * into the running one), installs the languages, sets the configuration, shoots every language.
 *
 * @param array<string, mixed> $ctx
 * @param array<int, array<string, mixed>> $shots
 *
 * @return array{results: array<int, array<string, mixed>>, fallbacks: array<string, string>, notes: string[], languages: array<string, array{shop_language: string}>}|array{error: string}
 */
function docs_shots_on_shop(array $ctx, string $version, array $shots, bool $keep): array
{
    // The same shops as `ps-module shops`: another command on this module's shops waits (and the other way round).
    // The shops commands started from here see that this process holds the lock (lock.php) and do not wait for it.
    $held = ps_lock_held('shops-' . $ctx['module']);

    try {
        ps_lock('shops-' . $ctx['module'], "docs screenshots on PrestaShop $version", $ctx['repo']);
    } catch (RuntimeException $e) {
        return ['error' => $e->getMessage()];
    }

    try {
        return docs_shots_on_locked_shop($ctx, $version, $shots, $keep);
    } finally {
        if (!$held) {
            ps_unlock('shops-' . $ctx['module']);
        }
    }
}

/**
 * docs_shots_on_shop() once the shops of the module are locked for this process.
 *
 * @param array<string, mixed> $ctx
 * @param array<int, array<string, mixed>> $shots
 *
 * @return array{results: array<int, array<string, mixed>>, fallbacks: array<string, string>, notes: string[], languages: array<string, array{shop_language: string}>}|array{error: string}
 */
function docs_shots_on_locked_shop(array $ctx, string $version, array $shots, bool $keep): array
{
    $port = docs_shop_port($ctx, $version);
    $project = $ctx['module'] . '-ps' . str_replace('.', '', $version);
    $container = docs_shop_container($project);
    ps_vlog("shop PrestaShop $version: compose project $project, port $port, container " . ($container !== '' ? $container : '(none)'));
    $running = $container !== '' && in_array(docs_http_status("http://localhost:$port/"), [200, 301, 302], true);
    echo "Shop: PrestaShop $version on http://localhost:$port/ (" . ($running ? 'running: deploying the current code' : 'starting it') . ")\n";
    if (docs_ps_test($ctx, [$running ? 'deploy' : 'up', $version]) !== 0) {
        return ['error' => "the test shop PrestaShop $version could not be " . ($running ? 'updated' : 'started') . ' (see the output above)'];
    }
    $container = docs_shop_container($project);
    $network = $container !== '' ? docs_shop_network($container) : '';
    ps_vlog("shop container $container, network " . ($network !== '' ? $network : '(none)'));
    if ($network === '') {
        return ['error' => "no container/network found for the shop (compose project $project)"];
    }
    docs_exec(['docker', 'cp', DOCS_RESOURCES . '/docs/shop-probe.php', "$container:/tmp/docs-probe.php"], 60);

    // Languages: install the missing ones (when allowed); otherwise fall back to the source language.
    $installed = (array) (docs_shop_probe($container, ['languages'])['languages'] ?? []);
    $failures = [];
    $notes = [];
    $installLanguages = (bool) ($ctx['config']['shop']['install_languages'] ?? true);
    ps_vlog('languages in the shop: ' . ($installed === [] ? 'none found' : implode(' ', array_keys($installed))) . '; install missing ones: ' . ($installLanguages ? 'yes' : 'no (docs.json > shop.install_languages)'));
    foreach ($ctx['languages'] as $iso) {
        $psIso = docs_shop_iso($ctx, $iso);
        if ($iso === $ctx['source'] && isset($installed[$psIso])) {
            continue;
        }
        if (!$installLanguages) {
            $failures[$iso] = 'the shop does not have this language (docs.json > shop.install_languages is false)';
            continue;
        }
        echo "  language $iso: " . (isset($installed[$psIso]) ? 'checking the translation pack' : 'installing it in the shop') . "...\n";
        $result = docs_shop_probe($container, ['install', $psIso]);
        if (empty($result['ok'])) {
            $failures[$iso] = "installing the language in PrestaShop $version failed: " . ($result['message'] ?? '?');
        } elseif (empty($result['pack'])) {
            $notes[] = "$iso (PrestaShop $version): " . $result['message'] . ' - PrestaShop texts on the screenshots stay in English, the texts of the module are translated';
        }
    }
    docs_exec(['docker', 'exec', '-u', 'root', $container, 'sh', '-c', 'rm -rf /var/www/html/var/cache/dev/* /var/www/html/var/cache/prod/*'], 120);
    $installed = (array) (docs_shop_probe($container, ['languages'])['languages'] ?? []);
    $configuration = (array) ($ctx['config']['shop']['configuration'] ?? []);
    ps_vlog('languages after install: ' . implode(' ', array_keys($installed)) . '; shop.configuration: ' . ($configuration === [] ? 'none' : implode(', ', array_keys($configuration))));
    if ($configuration !== []) {
        $result = docs_shop_probe($container, ['config', base64_encode((string) json_encode($configuration))]);
        if (empty($result['ok'])) {
            $notes[] = 'setting docs.json > shop.configuration failed: ' . ($result['message'] ?? '?');
        }
    }
    // Demo data for the screenshots (after the install and the languages, so the script sees every language).
    $setupError = docs_run_shop_setup($ctx, $container);
    if ($setupError !== null) {
        return ['error' => $setupError];
    }
    $multiLanguage = count(array_filter($installed, static fn (array $l): bool => !empty($l['active']))) > 1;

    // Per language: back-office language of the employee, front-office URL with the language.
    $results = [];
    $fallbacks = [];
    $languages = [];
    foreach ($ctx['languages'] as $iso) {
        $psIso = docs_shop_iso($ctx, $iso);
        $shopLanguage = isset($installed[$psIso]) ? $iso : $ctx['source'];
        $languages[$iso] = ['shop_language' => $shopLanguage];
        if ($shopLanguage !== $iso) {
            $fallbacks[$iso] = $failures[$iso] ?? "PrestaShop $version does not have this language";
            ps_vlog("$iso: uses the {$ctx['source']} screenshots ({$fallbacks[$iso]})");
            continue;
        }
        $list = array_values(array_filter($shots, static fn (array $s): bool => empty($s['languages']) || in_array($iso, (array) $s['languages'], true)));
        if ($list === []) {
            ps_vlog("$iso: no screenshots for this language");
            continue;
        }
        ps_vlog("$iso: shop language $psIso (id " . ($installed[$psIso]['id'] ?? '?') . '), locale ' . ($ctx['catalog'][$iso]['locale'] ?? 'en-US'));
        docs_shop_probe($container, ['employee-lang', $psIso]);
        $jobShots = [];
        foreach ($list as $shot) {
            $path = (string) $shot['path'];
            if (($shot['area'] ?? '') === 'fo') {
                $path = '/' . ltrim($path, '/');
                if (str_contains($path, '?') || str_starts_with($path, '/index.php')) {
                    $path .= (str_contains($path, '?') ? '&' : '?') . 'id_lang=' . (int) $installed[$psIso]['id'];
                } elseif ($multiLanguage && !preg_match('#^/[a-z]{2}(/|$)#', $path)) {
                    $path = '/' . $psIso . $path;
                }
            }
            @mkdir($ctx['docs'] . "/screenshots/$iso", 0775, true);
            $jobShots[] = array_merge($shot, [
                'path' => $path,
                'language' => $iso,
                'locale' => (string) ($ctx['catalog'][$iso]['locale'] ?? 'en-US'),
                'out' => "/docs/screenshots/$iso/{$shot['id']}.png",
            ]);
        }
        echo "  $iso: " . count($jobShots) . " screenshot(s) in PrestaShop $version...\n";
        $run = docs_run_container($ctx, [[
            'type' => 'screenshots',
            'base' => "http://localhost:$port",
            'admin' => '/admin-dev/index.php',
            'login' => ['email' => 'demo@prestashop.com', 'password' => 'prestashop_demo'],
            'module' => $ctx['module'],
            'proxy' => ['port' => $port, 'host' => 'prestashop', 'target_port' => 80],
            'hide' => (array) ($ctx['config']['shop']['hide'] ?? []),
            'shots' => $jobShots,
        ]], $network);
        if ($run['error'] !== '') {
            $results[] = ['ok' => false, 'id' => '*', 'language' => $iso, 'error' => $run['error']];
            continue;
        }
        $results = array_merge($results, $run['results']);
    }
    docs_shop_probe($container, ['employee-lang', docs_shop_iso($ctx, $ctx['source'])]);
    if ($running || $keep) {
        ps_vlog("shop PrestaShop $version left running (" . ($running ? 'it was already running' : '--keep') . ')');
    }
    if (!$running && !$keep) {
        echo "Stopping the shop PrestaShop $version (it was started for the screenshots; --keep leaves it running).\n";
        docs_ps_test($ctx, ['down', $version]);
    }

    return ['results' => $results, 'fallbacks' => $fallbacks, 'notes' => $notes, 'languages' => $languages];
}
