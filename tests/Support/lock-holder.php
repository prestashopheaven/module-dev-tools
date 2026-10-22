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
 * Helper process of LockTest: php lock-holder.php hold <seconds> [child] | take
 *   hold   takes the lock "demo-server", prints "held", optionally starts "take" as a child, then sleeps
 *   take   takes the lock and prints "took after <s>s", or "gave up: <message>" (exit 3)
 * The lock folder comes from PS_MODULE_LOCK_DIR (set by the test).
 */

declare(strict_types=1);

require __DIR__ . '/../../src/lock.php';

$mode = (string) ($argv[1] ?? '');
if ($mode === 'hold') {
    ps_lock('demo-server', 'publish demo heavenfoo 1.0.0', __DIR__);
    echo "held\n";
    if (($argv[3] ?? '') === 'child') {
        passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' take');
    }
    sleep((int) ($argv[2] ?? 5));
    exit(0);
}
$start = microtime(true);
try {
    ps_lock('demo-server', 'publish docs heavenbar 2.0.0', __DIR__);
    printf("took after %.1fs\n", microtime(true) - $start);
    exit(0);
} catch (RuntimeException $e) {
    echo 'gave up: ' . $e->getMessage() . "\n";
    exit(3);
}
