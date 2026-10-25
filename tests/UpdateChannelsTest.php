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

require_once dirname(__DIR__) . '/src/build-channels.php';
require_once dirname(__DIR__) . '/src/update-check.php';
require_once dirname(__DIR__) . '/src/update-sign.php';

/**
 * The direct and Addons builds (build-channels.php) and the update-system rules UPD-01..03 (update-check.php).
 */
final class UpdateChannelsTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = str_replace('\\', '/', sys_get_temp_dir()) . '/upd_' . uniqid();
        mkdir($this->dir, 0775, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->dir)) {
            removeChannelTree($this->dir);
        }
    }

    private function write(string $relative, string $content): void
    {
        $path = "$this->dir/$relative";
        @mkdir(dirname($path), 0775, true);
        file_put_contents($path, $content);
    }

    private const MAIN = "<?php\nclass heavenexample extends Module\n{\n    use ModuleBase;\n\n    public function __construct()\n    {\n        \$this->name = 'heavenexample';\n        parent::__construct();\n    }\n}\n";

    public function testSetModuleKeyAddsAKeyBeforeTheParentConstructor(): void
    {
        $this->write('heavenexample.php', self::MAIN);
        setModuleKey("$this->dir/heavenexample.php", 'abcdef0123456789abcdef0123456789');
        $code = (string) file_get_contents("$this->dir/heavenexample.php");
        self::assertStringContainsString("        \$this->module_key = 'abcdef0123456789abcdef0123456789';\n        parent::__construct();", $code);
    }

    public function testSetModuleKeyReplacesAnExistingKeyAndEmptiesIt(): void
    {
        $this->write('heavenexample.php', str_replace('parent::__construct();', "\$this->module_key = \"old\";\n        parent::__construct();", self::MAIN));
        setModuleKey("$this->dir/heavenexample.php", '');
        $code = (string) file_get_contents("$this->dir/heavenexample.php");
        self::assertStringContainsString("\$this->module_key = '';", $code);
        self::assertStringNotContainsString('old', $code);
    }

    public function testSetModuleKeyWithoutKeyAndAssignmentChangesNothing(): void
    {
        $this->write('heavenexample.php', self::MAIN);
        setModuleKey("$this->dir/heavenexample.php", '');
        self::assertSame(self::MAIN, file_get_contents("$this->dir/heavenexample.php"));
    }

    public function testSetModuleKeyFailsWithoutParentConstructor(): void
    {
        $this->write('heavenexample.php', "<?php\nclass heavenexample {}\n");
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no parent::__construct()');
        setModuleKey("$this->dir/heavenexample.php", 'abcdef0123456789abcdef0123456789');
    }

    public function testReadModuleKeyAcceptsOnlyARealKey(): void
    {
        self::assertSame('', readModuleKey($this->dir), 'no module.spec.json');
        $this->write('module.spec.json', '{"module_key": "  ABCDEF0123456789ABCDEF0123456789 "}');
        self::assertSame('abcdef0123456789abcdef0123456789', readModuleKey($this->dir));
        $this->write('module.spec.json', '{"module_key": "TODO"}');
        self::assertSame('', readModuleKey($this->dir));
        $this->write('module.spec.json', '{"module_key": 12}');
        self::assertSame('', readModuleKey($this->dir));
    }

    public function testModuleDistributionDefaultsToAddons(): void
    {
        self::assertSame('addons', moduleDistribution($this->dir));
        $this->write('module.spec.json', '{"distribution": "own-clients"}');
        self::assertSame('own-clients', moduleDistribution($this->dir));
        $this->write('module.spec.json', '{"distribution": ["x"]}');
        self::assertSame('addons', moduleDistribution($this->dir));
    }

    public function testTheAddonsStageLeavesTheNoticeOutAndGetsTheKey(): void
    {
        $this->write('stage/heavenexample/heavenexample.php', self::MAIN);
        $this->write('stage/heavenexample/vendor/prestashopheaven/module-kit/src/Updater/UpdaterNotice.php', "<?php // https://updates.prestahaven.com/\n");
        $this->write('stage/heavenexample/vendor/prestashopheaven/module-kit/src/ModuleBase.php', '<?php trait ModuleBase {}');

        makeAddonsStage("$this->dir/stage/heavenexample", "$this->dir/addons/heavenexample", 'heavenexample', 'abcdef0123456789abcdef0123456789', ' --quiet');

        self::assertDirectoryDoesNotExist("$this->dir/addons/heavenexample/vendor/prestashopheaven/module-kit/src/Updater");
        self::assertFileExists("$this->dir/addons/heavenexample/vendor/prestashopheaven/module-kit/src/ModuleBase.php");
        self::assertStringContainsString("module_key = 'abcdef0123456789abcdef0123456789'", (string) file_get_contents("$this->dir/addons/heavenexample/heavenexample.php"));
        self::assertFileExists("$this->dir/stage/heavenexample/vendor/prestashopheaven/module-kit/src/Updater/UpdaterNotice.php", 'the direct stage keeps the notice');
        self::assertStringNotContainsString('module_key', (string) file_get_contents("$this->dir/stage/heavenexample/heavenexample.php"));
    }

    public function testTheAddonsStageRefusesAnUpdateServerUrlOutsideTheNotice(): void
    {
        $this->write('stage/heavenexample/heavenexample.php', self::MAIN);
        $this->write('stage/heavenexample/src/Client.php', "<?php const URL = 'https://updates.prestahaven.com/api';\n");
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('still mentions updates.prestahaven.com: src/Client.php');
        makeAddonsStage("$this->dir/stage/heavenexample", "$this->dir/addons/heavenexample", 'heavenexample', '', ' --quiet');
    }

    private const SIGNING_WORKFLOW = "steps:\n  - run: vendor/bin/ps-module sign ../dist/heavenexample.zip\n  - files: |\n      dist/heavenexample.zip\n      dist/heavenexample.zip.sig\n      dist/heavenexample-addons.zip\n";

    public function testUpdateRulesPassForAReadyModule(): void
    {
        $this->write('.github/workflows/release.yml', self::SIGNING_WORKFLOW);
        $this->write('heavenexample/heavenexample.php', self::MAIN);
        self::assertSame([], checkUpdateReadiness("$this->dir/heavenexample"));
    }

    public function testUpd01AWorkflowThatDoesNotSign(): void
    {
        $this->write('.github/workflows/release.yml', "steps:\n  - files: dist/heavenexample.zip\n");
        $this->write('heavenexample/heavenexample.php', self::MAIN);
        $violations = checkUpdateReadiness("$this->dir/heavenexample");
        self::assertCount(2, $violations);
        self::assertStringContainsString('UPD-01 the release does not sign', $violations[0]);
        self::assertStringContainsString('UPD-01 the release does not attach the Addons build', $violations[1]);
    }

    public function testUpd01OwnClientModulesNeedNoAddonsBuild(): void
    {
        $this->write('.github/workflows/release.yml', str_replace("      dist/heavenexample-addons.zip\n", '', self::SIGNING_WORKFLOW));
        $this->write('module.spec.json', '{"distribution": "own-clients"}');
        $this->write('heavenexample/heavenexample.php', self::MAIN);
        self::assertSame([], checkUpdateReadiness("$this->dir/heavenexample"));
    }

    public function testUpd01WithoutWorkflowThereIsNothingToCheck(): void
    {
        $this->write('heavenexample/heavenexample.php', self::MAIN);
        self::assertSame([], checkUpdateReadiness("$this->dir/heavenexample"));
    }

    public function testUpd02AModuleKeyInTheSource(): void
    {
        $this->write('heavenexample/heavenexample.php', str_replace('parent::__construct();', "\$this->module_key = 'abc';\n        parent::__construct();", self::MAIN));
        $violations = checkUpdateReadiness("$this->dir/heavenexample");
        self::assertSame(['heavenexample.php:9 UPD-02 module_key in the source: move it to module.spec.json > module_key (only the Addons build gets it)'], $violations);
    }

    public function testUpd02AnEmptyModuleKeyIsFine(): void
    {
        $this->write('heavenexample/heavenexample.php', str_replace('parent::__construct();', "\$this->module_key = '';\n        parent::__construct();", self::MAIN));
        self::assertSame([], checkUpdateReadiness("$this->dir/heavenexample"));
    }

    public function testUpd03AnOwnNoticeHookNeedsInsteadof(): void
    {
        $this->write('heavenexample/heavenexample.php', str_replace('use ModuleBase;', "use ModuleBase, Tabs {\n        Tabs::getTabs insteadof ModuleBase;\n    }\n    use DisplayAdminAfterHeader;", self::MAIN));
        $this->write('heavenexample/src/Module/Hook/DisplayAdminAfterHeader.php', "<?php\ntrait DisplayAdminAfterHeader { public function hookDisplayAdminAfterHeader(\$p) {} }\n");
        $violations = checkUpdateReadiness("$this->dir/heavenexample");
        self::assertCount(1, $violations);
        self::assertStringContainsString('UPD-03 DisplayAdminAfterHeader.php has hookDisplayAdminAfterHeader', $violations[0]);

        $this->write('heavenexample/heavenexample.php', str_replace('use ModuleBase;', "use ModuleBase, DisplayAdminAfterHeader {\n        DisplayAdminAfterHeader::hookDisplayAdminAfterHeader insteadof ModuleBase;\n    }", self::MAIN));
        self::assertSame([], checkUpdateReadiness("$this->dir/heavenexample"));
    }

    public function testRepositoryArguments(): void
    {
        self::assertSame(['a/b', 'c/d'], update_repos_from_args(['--repo=a/b', '--check', '--repo=c/d']));
        self::assertSame([], update_repos_from_args(['--all']));
    }
}
