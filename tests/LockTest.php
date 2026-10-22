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

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * src/lock.php with real processes (tests/Support/lock-holder.php): a second process waits for the holder and says who
 * holds it, gives up after the timeout, finds the lock free after the holder was killed, and a child of the holder
 * does not wait for its parent.
 */
final class LockTest extends TestCase
{
    private string $dir = '';

    /**
     * A lock folder of its own for every test.
     */
    protected function setUp(): void
    {
        $this->dir = str_replace('\\', '/', sys_get_temp_dir()) . '/ps-lock-test-' . bin2hex(random_bytes(4));
    }

    /**
     * Removes the lock folder.
     */
    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*') ?: []);
        @rmdir($this->dir);
    }

    /**
     * The second process waits until the holder ends, with a message that names the holder.
     */
    public function testWaitsForTheHolder(): void
    {
        $holder = $this->start(['hold', '4']);
        [$code, $out] = $this->runHelper(['take']);
        proc_close($holder);

        self::assertSame(0, $code, $out);
        self::assertMatchesRegularExpression('/took after [2-9]\.\ds/', $out);
        self::assertStringContainsString('Waiting for demo-server: in use by publish demo heavenfoo 1.0.0 (process ', $out);
        self::assertStringContainsString('demo-server is free: going on.', $out);
    }

    /**
     * A wait longer than PS_MODULE_LOCK_TIMEOUT gives up with a message.
     */
    public function testGivesUpAfterTheTimeout(): void
    {
        $holder = $this->start(['hold', '6']);
        [$code, $out] = $this->runHelper(['take'], ['PS_MODULE_LOCK_TIMEOUT' => '2']);
        proc_terminate($holder);
        proc_close($holder);

        self::assertSame(3, $code, $out);
        self::assertStringContainsString('demo-server is still in use after', $out);
    }

    /**
     * The operating system releases the lock of a process that is killed.
     */
    public function testKilledHolderLeavesNoLock(): void
    {
        $holder = $this->start(['hold', '30']);
        proc_terminate($holder);
        proc_close($holder);
        [$code, $out] = $this->runHelper(['take'], ['PS_MODULE_LOCK_TIMEOUT' => '10']);

        self::assertSame(0, $code, $out);
        self::assertStringNotContainsString('Waiting', $out);
    }

    /**
     * A process started by the holder (a ps-module command started by another one) does not wait for it.
     */
    public function testChildOfTheHolderDoesNotWait(): void
    {
        [$code, $out] = $this->runHelper(['hold', '0', 'child'], ['PS_MODULE_LOCK_TIMEOUT' => '5']);

        self::assertSame(0, $code, $out);
        self::assertStringContainsString('took after 0.', $out);
    }

    /**
     * Starts the helper and waits until it holds the lock.
     *
     * @param string[] $args
     *
     * @return resource
     */
    private function start(array $args)
    {
        $process = proc_open(array_merge([PHP_BINARY, __DIR__ . '/Support/lock-holder.php'], $args), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $this->env());
        self::assertIsResource($process);
        self::assertSame("held\n", fgets($pipes[1]));

        return $process;
    }

    /**
     * Runs the helper to the end; returns the exit code and all output.
     *
     * @param string[] $args
     * @param array<string, string> $env
     *
     * @return array{0: int, 1: string}
     */
    private function runHelper(array $args, array $env = []): array
    {
        $process = proc_open(array_merge([PHP_BINARY, __DIR__ . '/Support/lock-holder.php'], $args), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env + $this->env());
        self::assertIsResource($process);
        $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);

        return [proc_close($process), $out];
    }

    /**
     * The environment of the helpers: this test's lock folder, no inherited locks.
     *
     * @return array<string, string>
     */
    private function env(): array
    {
        $env = getenv();
        unset($env['PS_MODULE_LOCKS']);

        return ['PS_MODULE_LOCK_DIR' => $this->dir] + $env;
    }
}
