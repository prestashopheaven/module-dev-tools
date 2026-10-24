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

require_once __DIR__ . '/../src/docs.php';

/**
 * Marketing price: 0 means a free module (not sold on Addons, e.g. the PrestaHaven Updater); any other price stays
 * between the Addons limits. The marketing sheet shows "Free" instead of "€ 0.00".
 */
final class DocsFreePriceTest extends TestCase
{
    /**
     * The price errors docs_validate_marketing_common() reports for a price.
     *
     * @return list<string>
     */
    private static function priceErrors(mixed $price): array
    {
        $errors = [];
        $marketing = [
            'category' => 'Store Management › Data Synchronization & Update',
            'price' => $price,
            'benefits' => ['Productivity › Help save time'],
            'multistore' => 'Fully',
        ];
        docs_validate_marketing_common(['docs' => sys_get_temp_dir() . '/no-such-docs-' . getmypid(), 'repo' => sys_get_temp_dir()], $marketing, static function (string $level, string $iso, string $file, string $message) use (&$errors): void {
            if ($level === 'error' && str_contains($message, 'marketing.price')) {
                $errors[] = $message;
            }
        });

        return $errors;
    }

    /**
     * @return array<string, array{mixed, bool}>
     */
    public static function prices(): array
    {
        return [
            'free (int)' => [0, true],
            'free (float)' => [0.0, true],
            'lowest price' => [49.99, true],
            'highest price' => [4999.99, true],
            'below the lowest' => [10, false],
            'above the highest' => [5000, false],
            'negative' => [-1, false],
            'three decimals' => [59.999, false],
            'text' => ['0', false],
            'missing' => [null, false],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('prices')]
    public function testValidation(mixed $price, bool $valid): void
    {
        self::assertSame($valid, self::priceErrors($price) === [], implode(' ', self::priceErrors($price)));
    }

    public function testTheErrorMentionsFreeModules(): void
    {
        self::assertStringContainsString('0 = a free module', self::priceErrors(10)[0]);
        self::assertStringContainsString('0 (a free module)', self::priceErrors('x')[0]);
    }

    public function testTheMarketingSheetShowsFree(): void
    {
        self::assertSame('Free', docs_marketing_text(['value' => 0, 'type' => 'price']));
        self::assertSame('59.00', docs_marketing_text(['value' => 59, 'type' => 'price']));
        self::assertStringNotContainsString('€', docs_marketing_value_html(['value' => 0, 'type' => 'price', 'question' => 'Price'], null, sys_get_temp_dir()));
        self::assertStringContainsString('€ 59.00', docs_marketing_value_html(['value' => 59, 'type' => 'price', 'question' => 'Price'], null, sys_get_temp_dir()));
    }
}
