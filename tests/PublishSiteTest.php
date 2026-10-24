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
 * `ps-module publish site` (publish_build_site()): the docs site of a module with the PDF and the flag of every
 * language, and the page that shows them.
 */
final class PublishSiteTest extends TestCase
{
    private string $repo = '';

    /**
     * A module repository with a manual PDF in three languages.
     */
    protected function setUp(): void
    {
        $this->repo = sys_get_temp_dir() . '/ps-module-site-' . getmypid() . '-' . uniqid();
        foreach (['en', 'nl', 'xx'] as $iso) {
            mkdir("{$this->repo}/docs/manual/$iso", 0777, true);
            file_put_contents("{$this->repo}/docs/manual/$iso/readme_$iso.pdf", "%PDF-1.4 $iso");
        }
    }

    /**
     * Removes the repository.
     */
    protected function tearDown(): void
    {
        $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->repo, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->repo);
    }

    /**
     * Every language of resources/languages.json has a flag that exists in resources/publish/flags/.
     */
    public function testEveryLanguageHasAFlag(): void
    {
        $catalog = publish_json(__DIR__ . '/../resources/languages.json')['languages'];
        foreach ($catalog as $iso => $language) {
            $this->assertNotEmpty($language['flag'] ?? '', "$iso has no flag");
            $this->assertFileExists(__DIR__ . "/../resources/publish/flags/{$language['flag']}.svg", "flag of $iso");
        }
    }

    /**
     * The site gets flag-<country>.svg next to the PDFs (English: the British flag); a language without a flag gets
     * none, and the page shows each flag before the language name.
     */
    public function testSiteHasTheFlagOfEveryLanguage(): void
    {
        $this->expectOutputRegex('/3 PDF\(s\) \+ 2 flag\(s\)/');
        $dir = publish_build_site($this->context());

        $this->assertFileExists("$dir/flag-gb.svg");
        $this->assertFileExists("$dir/flag-nl.svg");
        $this->assertSame(['flag-gb.svg', 'flag-nl.svg', 'index.php', 'readme_en.pdf', 'readme_nl.pdf', 'readme_xx.pdf'], $this->files($dir));

        $page = $this->render("$dir/index.php");
        $this->assertStringContainsString('<img class="flag" src="flag-nl.svg" alt="" width="24" height="18" loading="lazy">Nederlands', $page);
        $this->assertStringContainsString('<img class="flag" src="flag-gb.svg" alt="" width="24" height="18" loading="lazy">PDF · English', $page);
        $this->assertStringContainsString('lang="xx">Xx – ', $page);
    }

    /**
     * A flag outside resources/publish/flags/ (or a strange value) is never copied.
     */
    public function testUnknownOrUnsafeFlagIsSkipped(): void
    {
        $dir = "{$this->repo}/out";
        mkdir($dir);

        $this->assertSame('', publish_site_flag('../../composer', $dir));
        $this->assertSame('', publish_site_flag('zz', $dir));
        $this->assertSame('flag-nl.svg', publish_site_flag('nl', $dir));
        $this->assertSame(['flag-nl.svg'], $this->files($dir));
    }

    /**
     * The context publish_build_site() needs: languages en, nl and one without a flag (xx).
     *
     * @return array<string, mixed>
     */
    private function context(): array
    {
        return [
            'repo' => $this->repo,
            'module' => 'heavenexample',
            'version' => '1.0.0',
            'ps_min' => '8.1.0',
            'ps_tested' => '9.2.0',
            'languages' => ['en', 'nl', 'xx'],
            'spec' => ['display_name' => 'Example', 'description' => 'An example module.'],
            'catalog' => [
                'en' => ['name' => 'English', 'native' => 'English', 'flag' => 'gb'],
                'nl' => ['name' => 'Dutch', 'native' => 'Nederlands', 'flag' => 'nl'],
                'xx' => ['name' => 'Xx', 'native' => 'Xx'],
            ],
        ];
    }

    /**
     * The file names in a folder, sorted.
     *
     * @return string[]
     */
    private function files(string $dir): array
    {
        $files = array_map('basename', glob("$dir/*") ?: []);
        sort($files);

        return $files;
    }

    /**
     * The HTML of the built page, as the docs server sends it (English first).
     */
    private function render(string $file): string
    {
        $command = escapeshellarg(PHP_BINARY) . ' -d display_errors=stderr ' . escapeshellarg($file);

        return (string) shell_exec($command);
    }
}
