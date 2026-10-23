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

/**
 * `ps-module demo-site apply <dir>`: the files that go to the server (publish_apply_files()), the refusal of a folder
 * without apply.php, the dry run, and the server script's usage line and command.
 */
final class PublishDemoApplyTest extends TestCase
{
    private string $dir = '';

    /**
     * A bundle folder with apply.php, a nested file and dot files that must stay behind.
     */
    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/ps-module-apply-' . getmypid() . '-' . uniqid();
        mkdir($this->dir . '/modules/heavenexample/test-site', 0777, true);
        mkdir($this->dir . '/.git', 0777, true);
        file_put_contents($this->dir . '/apply.php', "<?php echo 'APPLY-OK';\n");
        file_put_contents($this->dir . '/manifest.json', '{}');
        file_put_contents($this->dir . '/modules/heavenexample/test-site/setup.php', "<?php return [];\n");
        file_put_contents($this->dir . '/.DS_Store', 'x');
        file_put_contents($this->dir . '/.git/HEAD', 'x');
    }

    /**
     * Removes the bundle folder.
     */
    protected function tearDown(): void
    {
        $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->dir);
    }

    /**
     * Every file with its path relative to the folder, sorted; dot files and dot folders are left out.
     */
    public function testFilesAreRelativeSortedAndWithoutDotFiles(): void
    {
        $files = publish_apply_files($this->dir);

        self::assertSame(['apply.php', 'manifest.json', 'modules/heavenexample/test-site/setup.php'], array_keys($files));
        self::assertFileExists($files['modules/heavenexample/test-site/setup.php']);
    }

    /**
     * A folder without apply.php, a missing folder and an empty argument are refused with the usage.
     *
     * @dataProvider invalidFolders
     */
    public function testAFolderWithoutApplyPhpIsRefused(string $suffix): void
    {
        if ($suffix === '/no-apply') {
            mkdir($this->dir . $suffix);
        }
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('usage: ps-module demo-site apply');

        publish_apply_files($suffix === 'empty' ? '' : $this->dir . $suffix);
    }

    /**
     * Folders that apply refuses.
     *
     * @return array<string, array{string}>
     */
    public static function invalidFolders(): array
    {
        return ['no apply.php' => ['/no-apply'], 'missing folder' => ['/missing'], 'empty argument' => ['empty']];
    }

    /**
     * A dry run says what it would do and connects to nothing.
     */
    public function testDryRunConnectsToNothing(): void
    {
        ob_start();
        $code = publish_demo_apply(['repo' => $this->dir, 'module' => '', 'version' => ''], $this->dir, 'test data', true, false);
        $output = (string) ob_get_clean();

        self::assertSame(0, $code);
        self::assertStringContainsString('Dry run: would upload 3 file(s)', $output);
        self::assertStringContainsString('"test data"', $output);
        self::assertStringContainsString('VPS snapshot', $output);
    }

    /**
     * The server script knows the command, documents it and only accepts a folder with apply.php.
     */
    public function testServerScriptHasTheApplyCommand(): void
    {
        $script = (string) file_get_contents(__DIR__ . '/../resources/publish/demo-site.sh');

        self::assertStringContainsString('apply) lock; cmd_apply "$@" ;;', $script);
        self::assertStringContainsString('#   demo-site.sh apply <dir> [note]', $script);
        self::assertStringContainsString('[ -f "$dir/apply.php" ] || die "usage: demo-site.sh apply', $script);
        self::assertStringContainsString("grep -q '^APPLY-OK'", $script);
    }
}
