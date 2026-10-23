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
 * Locks for resources that several modules share, so commands of different modules (other terminals, other Claude
 * sessions) wait for each other instead of getting in each other's way:
 *
 *   demo-server   the demo VPS: publish docs, publish demo, demo-site <command>, publish vps-snapshot
 *   sales-shop    the own sales shop: publish shop
 *   shops-<name>  the Docker test shops of one module (shops run/up/deploy/..., the docs screenshots)
 *   docs-image    building the docs-tools Docker image
 *
 * A lock is an flock() on <workspace>/.cache/locks/<name>.lock (the workspace: the nearest folder upwards with
 * libraries/module-dev-tools or a .env; otherwise the system temp folder). The operating system releases it when the
 * process ends, also after a crash, so a lock is never left behind. Who holds it (module, command, process, since) is
 * in <name>.json next to it, for the waiting message. Child processes inherit a held lock through the environment
 * (PS_MODULE_LOCKS), so a command that starts another ps-module command does not wait for itself.
 *
 *   ps_lock('demo-server', 'publish demo heavenfoo', $repoDir);   // waits (message every minute), then holds it
 *
 * Settings: PS_MODULE_LOCK_TIMEOUT (seconds, default 3600) = how long to wait before giving up; PS_MODULE_LOCK_DIR =
 * another folder for the locks.
 */

declare(strict_types=1);

if (!function_exists('ps_verbose')) {
    require_once __DIR__ . '/verbose.php';
}

/**
 * Takes the lock on a shared resource, waiting while another process holds it. Returns at once when this process or a
 * parent already holds it. Throws a RuntimeException when the wait takes longer than the timeout.
 *
 * @param string $name    resource: [a-z0-9-]+
 * @param string $purpose what this process does with it (shown to the ones that wait)
 * @param string $fromDir where to look upwards for the workspace (the repo or module folder)
 */
function ps_lock(string $name, string $purpose, string $fromDir, ?int $timeout = null): void
{
    if (preg_match('/^[a-z0-9][a-z0-9-]*$/', $name) !== 1) {
        throw new InvalidArgumentException("invalid lock name: $name");
    }
    if (ps_lock_held($name)) {
        ps_vlog("lock $name: already held by this process or a parent");

        return;
    }
    $dir = ps_lock_dir($fromDir);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        ps_vlog("lock $name: cannot create $dir, going on without a lock");

        return;
    }
    $file = "$dir/$name.lock";
    $handle = @fopen($file, 'c');
    if ($handle === false) {
        ps_vlog("lock $name: cannot open $file, going on without a lock");

        return;
    }
    $timeout ??= (int) (getenv('PS_MODULE_LOCK_TIMEOUT') ?: 3600);
    $start = time();
    $lastMessage = 0;
    while (!flock($handle, LOCK_EX | LOCK_NB)) {
        $waited = time() - $start;
        if ($waited >= $timeout) {
            fclose($handle);
            throw new RuntimeException("$name is still in use after {$waited}s (" . ps_lock_holder_text($dir, $name) . '); try again later');
        }
        if ($lastMessage === 0 || time() - $lastMessage >= 60) {
            fwrite(STDERR, "Waiting for $name: in use by " . ps_lock_holder_text($dir, $name) . ($waited > 0 ? " (waited {$waited}s)" : '') . "...\n");
            $lastMessage = time();
        }
        sleep(2);
    }
    if (time() - $start > 0) {
        fwrite(STDERR, "$name is free: going on.\n");
    }
    @file_put_contents("$dir/$name.json", json_encode([
        'purpose' => $purpose,
        'pid' => getmypid(),
        'since' => date('c'),
        'cwd' => str_replace('\\', '/', (string) getcwd()),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
    // Kept open (and so locked) until the process ends; ps_unlock() releases it earlier.
    $GLOBALS['ps_lock_handles'][$name] = $handle;
    $held = array_filter(explode(',', (string) getenv('PS_MODULE_LOCKS')));
    $held[] = $name . ':' . getmypid();
    putenv('PS_MODULE_LOCKS=' . implode(',', array_unique($held)));
    ps_vlog("lock $name: taken ($file) for $purpose");
}

/**
 * Releases a lock this process took (it is released anyway when the process ends).
 */
function ps_unlock(string $name): void
{
    $handle = $GLOBALS['ps_lock_handles'][$name] ?? null;
    if ($handle === null) {
        return;
    }
    flock($handle, LOCK_UN);
    fclose($handle);
    unset($GLOBALS['ps_lock_handles'][$name]);
    $held = array_filter(explode(',', (string) getenv('PS_MODULE_LOCKS')), static fn (string $h): bool => !str_starts_with($h, "$name:"));
    putenv('PS_MODULE_LOCKS=' . implode(',', $held));
    ps_vlog("lock $name: released");
}

/**
 * True when this process holds the lock, or a parent process that started this one (PS_MODULE_LOCKS).
 */
function ps_lock_held(string $name): bool
{
    if (isset($GLOBALS['ps_lock_handles'][$name])) {
        return true;
    }
    foreach (array_filter(explode(',', (string) getenv('PS_MODULE_LOCKS'))) as $held) {
        if (explode(':', $held)[0] === $name) {
            return true;
        }
    }

    return false;
}

/**
 * Folder of the lock files: PS_MODULE_LOCK_DIR, else <workspace>/.cache/locks for the nearest folder upwards from
 * $fromDir with libraries/module-dev-tools or a .env, else the system temp folder.
 */
function ps_lock_dir(string $fromDir): string
{
    if (getenv('PS_MODULE_LOCK_DIR')) {
        return rtrim(str_replace('\\', '/', (string) getenv('PS_MODULE_LOCK_DIR')), '/');
    }
    $dir = rtrim(str_replace('\\', '/', (string) (realpath($fromDir) ?: $fromDir)), '/');
    for ($i = 0; $i < 8 && $dir !== '' && $dir !== dirname($dir); ++$i, $dir = dirname($dir)) {
        if (is_file("$dir/libraries/module-dev-tools/composer.json") || is_file("$dir/.env")) {
            return "$dir/.cache/locks";
        }
    }

    return rtrim(str_replace('\\', '/', sys_get_temp_dir()), '/') . '/prestashopheaven-locks';
}

/**
 * Who holds a lock, for a message: "publish demo heavenfoo (process 1234, since 14:03)".
 */
function ps_lock_holder_text(string $dir, string $name): string
{
    $info = json_decode((string) @file_get_contents("$dir/$name.json"), true);
    if (!is_array($info)) {
        return 'another process';
    }
    $since = strtotime((string) ($info['since'] ?? '')) ?: 0;

    return ($info['purpose'] ?? 'another process') . ' (process ' . ($info['pid'] ?? '?') . ($since > 0 ? ', since ' . date('H:i', $since) : '') . ')';
}
