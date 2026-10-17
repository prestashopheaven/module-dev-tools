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
 * Shared php-cs-fixer configuration for PrestaShopHeaven modules and libraries.
 * Use it in .php-cs-fixer.dist.php:
 *
 *   $factory = require __DIR__ . '/vendor/prestashopheaven/module-dev-tools/resources/php-cs-fixer.php';
 *
 *   return $factory(__DIR__);
 *
 * Base: the PrestaShop coding standard (prestashop/php-dev-tools), plus the rule from its
 * newer releases that keeps the license header directly after <?php, like PrestaShop's
 * own files and the Addons validator expect.
 */

return static function (string $dir): PhpCsFixer\ConfigInterface {
    $config = new class extends PrestaShop\CodingStandards\CsFixer\Config {
        /**
         * Returns the PrestaShop rules with the house additions.
         *
         * @return array<string, mixed>
         */
        public function getRules(): array
        {
            return array_merge(parent::getRules(), [
                'blank_line_after_opening_tag' => false,
            ]);
        }
    };

    /** @var Symfony\Component\Finder\Finder $finder */
    $finder = $config->setUsingCache(true)->getFinder();
    $finder->in($dir)->exclude(['vendor', 'node_modules', '.cache', 'tests/behat']);
    $config->setCacheFile($dir . '/.cache/.php-cs-fixer.cache');

    return $config;
};
