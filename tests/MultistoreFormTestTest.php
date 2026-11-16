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

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/ps-multistore.php';

/**
 * The multistore round tests the configuration form only for a module with configuration keys: a configure page that
 * only explains (no settings) has no form to save per shop. The values it writes and the fields it tries.
 */
final class MultistoreFormTestTest extends TestCase
{
    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function snapshots(): array
    {
        return [
            'configure page with settings' => [['configurable' => true, 'configuration' => ['HEAVENFOO_LIMIT']], 'form'],
            'configure page without settings' => [['configurable' => true, 'configuration' => []], 'no-settings'],
            'configure page, keys missing in the snapshot' => [['configurable' => true], 'no-settings'],
            'no configure page, with settings' => [['configurable' => false, 'configuration' => ['HEAVENFOO_LIMIT']], 'none'],
            'no configure page, no settings' => [['configurable' => false, 'configuration' => []], 'none'],
            'empty snapshot' => [[], 'none'],
        ];
    }

    /**
     * @param array<string, mixed> $snapshot
     */
    #[DataProvider('snapshots')]
    public function testWhichFormTestTheModuleNeeds(array $snapshot, string $expected): void
    {
        self::assertSame($expected, msConfigurationFormTest($snapshot));
    }

    /**
     * @return array<string, array{string, bool, string, string}>
     */
    public static function markers(): array
    {
        return [
            'text, all shops' => ['all', false, 'all', 'ms-bo-all'],
            'text, shop 2' => ['shop', false, 's2', 'ms-bo-s2'],
            'text, group 2' => ['group', false, 'g2', 'ms-bo-g2'],
            'number, all shops' => ['all', true, 'all', '71'],
            'number, shop' => ['shop', true, 's2', '72'],
            'number, group' => ['group', true, 'g2', '73'],
        ];
    }

    #[DataProvider('markers')]
    public function testTheMarkerOfEachContext(string $context, bool $numeric, string $scope, string $expected): void
    {
        self::assertSame($expected, msFormMarker($context, $numeric, $scope));
    }

    public function testATextMarkerFitsAStrictFieldFormat(): void
    {
        // A field that only takes page names or slugs (found with heavenscrollbuttons) must accept the marker as is.
        foreach (['all', 's2', 'g2', 's12'] as $scope) {
            self::assertMatchesRegularExpression('/^[a-z0-9-]+$/', msFormMarker('all', false, $scope));
        }
    }

    public function testTheMarkersOfTheContextsDiffer(): void
    {
        $markers = [msFormMarker('all', false), msFormMarker('shop', false, 's2'), msFormMarker('group', false, 'g2')];

        self::assertSame($markers, array_values(array_unique($markers)));
    }

    public function testTheTextFieldsAreTriedInTheOrderOfTheForm(): void
    {
        $page = ['text' => ['form[pages]', 'form[title]', 'form[pages]']];

        self::assertSame('form[pages]', msFirstTextField($page));
        self::assertSame(['form[pages]', 'form[title]'], msTextFields($page));
        self::assertNull(msFirstTextField(['text' => []]));
        self::assertSame([], msTextFields(['text' => []]));
    }
}
