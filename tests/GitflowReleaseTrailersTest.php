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

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/src/gitflow.php';
require_once dirname(__DIR__) . '/src/update-sign.php';

/**
 * `ps-module git pr --release X.Y.Z --publish`: the Update-* trailers from testing.json and module.spec.json, how they
 * travel through the PR body to the squash commit, and that `ps-module sign --trailers-from` reads them back.
 */
final class GitflowReleaseTrailersTest extends TestCase
{
    private string $repo = '';

    protected function setUp(): void
    {
        $this->repo = sys_get_temp_dir() . '/ps-module-trailers-' . getmypid() . '-' . uniqid();
        mkdir($this->repo);
        file_put_contents("{$this->repo}/testing.json", json_encode(['versions' => ['8.1.0', '8.2.8', '9.0.3', '9.1.5', '9.2.0', '7.0.0']]));
        file_put_contents("{$this->repo}/module.spec.json", json_encode(['php_min' => '8.1']));
    }

    /**
     * Run as a script (ps-module git pr), the code above the constants executes first: they must come before it.
     */
    public function testTheConstantsAreDeclaredBeforeTheCommandLineRuns(): void
    {
        $code = (string) file_get_contents(dirname(__DIR__) . '/src/gitflow.php');
        $cli = strpos($code, 'exit(gitflow_cli(');
        self::assertNotFalse($cli);
        foreach (['GITFLOW_TRAILERS_START', 'GITFLOW_TRAILERS_END', 'GITFLOW_PS_LINES', 'GITFLOW_PHP_MAX'] as $constant) {
            $declared = strpos($code, 'const ' . $constant . ' ');
            self::assertNotFalse($declared, $constant);
            self::assertLessThan($cli, $declared, $constant . ' is declared before the command line runs');
        }
    }

    protected function tearDown(): void
    {
        @unlink("{$this->repo}/testing.json");
        @unlink("{$this->repo}/module.spec.json");
        @rmdir($this->repo);
    }

    public function testTrailersFromTheModuleMetadata(): void
    {
        self::assertSame(
            "Update-Publish: accept\nUpdate-PrestaShop: 8.1, 8.2, 9.0, 9.1, 9.2\nUpdate-PHP: 8.1-8.5\nUpdate-Channel: stable\nUpdate-Rollout: 100",
            gitflow_release_trailers($this->repo, ['publish' => '1']),
        );
    }

    public function testOptionsOverrideEverything(): void
    {
        $trailers = gitflow_release_trailers($this->repo, ['publish' => '1', 'no-accept' => '1', 'prestashop' => '9.2', 'php' => '8.3', 'channel' => 'beta', 'rollout' => '10', 'min-updater' => '1.1.0']);
        self::assertSame("Update-Publish: pending\nUpdate-PrestaShop: 9.2\nUpdate-PHP: 8.3\nUpdate-Channel: beta\nUpdate-Rollout: 10\nUpdate-Min-Updater: 1.1.0", $trailers);
    }

    public function testMissingTestingDataIsAnError(): void
    {
        unlink("{$this->repo}/testing.json");
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('testing.json');
        gitflow_release_trailers($this->repo, ['publish' => '1']);
    }

    public function testRoundTripThroughThePrBodyAndTheSigner(): void
    {
        $trailers = gitflow_release_trailers($this->repo, ['publish' => '1']);
        $body = "Changes:\n- x\n\n" . GITFLOW_TRAILERS_START . "\n$trailers\n" . GITFLOW_TRAILERS_END . "\n";
        $fromBody = gitflow_trailers_from_body($body);
        self::assertSame($trailers, $fromBody);

        // The squash commit: subject + the trailers as body. The release workflow signs with them.
        $publish = update_normalise_publish(update_publish_from_trailers("Release 1.2.3 - Fix\n\n$fromBody\n"));
        self::assertSame('accept', $publish['mode']);
        self::assertSame(['8.1', '8.2', '9.0', '9.1', '9.2'], $publish['prestashop']);
        self::assertSame(['8.1', '8.2', '8.3', '8.4', '8.5'], $publish['php']);
    }

    public function testBodyWithoutTrailers(): void
    {
        self::assertSame('', gitflow_trailers_from_body('Changes: nothing special'));
    }

    public function testOptionsParsing(): void
    {
        self::assertSame(['release' => '1.2.3', 'notes' => 'Fix the price check', 'publish' => '1'], gitflow_options(['--release=1.2.3', '--notes=Fix the price check', '--publish', 'stray']));
    }
}
