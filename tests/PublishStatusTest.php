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
 * `ps-module publish status` (publish_cmd_status()): the checklist names the zip that belongs to each manual step.
 */
final class PublishStatusTest extends TestCase
{
    /**
     * The Addons upload is the Addons build (dist/addons/<module>.zip), not the direct build with the updater notice.
     */
    public function testAddonsZipIsTheAddonsBuild(): void
    {
        $this->assertSame('../dist/addons/heavenfoo.zip', publish_addons_zip(['module' => 'heavenfoo']));
    }

    /**
     * The open Addons step in the checklist points at the Addons build.
     */
    public function testChecklistNamesTheAddonsBuildForTheAddonsStep(): void
    {
        $repo = sys_get_temp_dir() . '/ps-module-status-' . getmypid() . '-' . uniqid();
        mkdir("$repo/heavenfoo", 0777, true);
        $ctx = publish_context_without_module($repo);
        $ctx['module_dir'] = "$repo/heavenfoo";
        $ctx['module'] = 'heavenfoo';
        $ctx['version'] = '1.0.0';

        ob_start();
        try {
            $code = publish_cmd_status($ctx, false);
        } finally {
            $output = (string) ob_get_clean();
            rmdir("$repo/heavenfoo");
            rmdir($repo);
        }

        $this->assertSame(1, $code);
        $this->assertMatchesRegularExpression('#Addons upload\s+open\s+upload \.\./dist/addons/heavenfoo\.zip in the Addons seller account#', $output);
        $this->assertStringNotContainsString('upload ../dist/heavenfoo.zip', $output);
    }
}
