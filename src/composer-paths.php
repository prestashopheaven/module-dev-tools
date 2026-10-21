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

require_once __DIR__ . '/verbose.php';

/**
 * Makes relative path repositories (e.g. ../../libraries/*) absolute in the composer.json
 * and composer.lock of a copy of the module, so composer can still find them when the
 * copy lives somewhere else (build stage, exported release).
 *
 * @param string $moduleDir directory the relative paths are relative to (the real module)
 * @param string $stage directory of the copy whose composer files are rewritten
 */
function rewritePathRepositories(string $moduleDir, string $stage): void
{
    $absolute = static function (string $url) use ($moduleDir): string {
        if (preg_match('#^([a-zA-Z]:)?[/\\\\]#', $url)) {
            return $url; // already absolute
        }
        $path = $moduleDir . '/' . $url;
        $real = realpath(str_contains($path, '*') ? dirname($path) : $path);

        return $real === false ? $path : str_replace('\\', '/', $real) . (str_contains($path, '*') ? '/' . basename($path) : '');
    };

    $composer = json_decode((string) file_get_contents("$stage/composer.json"), true);
    foreach ($composer['repositories'] ?? [] as $i => $repository) {
        if (($repository['type'] ?? '') === 'path') {
            $composer['repositories'][$i]['url'] = $absolute($repository['url']);
            ps_vlog("composer.json: path repository {$repository['url']} -> {$composer['repositories'][$i]['url']} (no symlink)");
            $composer['repositories'][$i]['options']['symlink'] = false;
        }
    }
    file_put_contents("$stage/composer.json", json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

    if (is_file("$stage/composer.lock")) {
        $lock = json_decode((string) file_get_contents("$stage/composer.lock"), true);
        foreach (['packages', 'packages-dev'] as $section) {
            foreach ($lock[$section] ?? [] as $i => $package) {
                if (($package['dist']['type'] ?? '') === 'path') {
                    $lock[$section][$i]['dist']['url'] = $absolute($package['dist']['url']);
                    ps_vlog("composer.lock: {$package['name']} path {$package['dist']['url']} -> {$lock[$section][$i]['dist']['url']}");
                    $lock[$section][$i]['transport-options'] = ['symlink' => false, 'relative' => false];
                }
            }
        }
        file_put_contents("$stage/composer.lock", json_encode($lock, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    }
}
