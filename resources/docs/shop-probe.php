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
 * Runs INSIDE the PrestaShop test shop container (copied there by src/docs-shots.php) to
 * prepare it for screenshots. Prints one JSON object.
 *
 *   php shop-probe.php languages                 installed languages and the default language
 *   php shop-probe.php install <iso>             install a language (with its translation pack when available)
 *   php shop-probe.php employee-lang <iso>       back-office language of the demo employee
 *   php shop-probe.php config <base64 json>      Configuration::updateValue for each key => value
 *   php shop-probe.php setup <file>              runs the shop.setup script of docs.json (demo data for the screenshots)
 */

// Installing a language loads the translation catalogues of every installed language: with 30+ languages that is
// more than the 512M of the PrestaShop image (fatal error in XliffUtils). Only a test shop runs this script.
ini_set('memory_limit', '2048M');

$root = getenv('_PS_ROOT_DIR_') ?: '/var/www/html';
if (!defined('_PS_ADMIN_DIR_')) {
    define('_PS_ADMIN_DIR_', $root . '/admin-dev');
}
require_once $root . '/config/config.inc.php';

$command = $argv[1] ?? '';
$arg = $argv[2] ?? '';

/**
 * Prints the result as JSON and stops.
 *
 * @param array<string, mixed> $data
 */
function probe_out(array $data): void
{
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit(0);
}

/**
 * The installed languages: iso => [id, active, locale].
 *
 * @return array<string, array{id: int, active: bool, locale: string}>
 */
function probe_languages(): array
{
    $languages = [];
    foreach (Language::getLanguages(false) as $language) {
        $languages[$language['iso_code']] = ['id' => (int) $language['id_lang'], 'active' => (bool) $language['active'], 'locale' => (string) $language['locale']];
    }

    return $languages;
}

/**
 * Boots the Symfony kernel of the shop (as global $kernel, where SymfonyContainer looks for it).
 */
function probe_boot_kernel(): void
{
    global $kernel;
    if ($kernel !== null) {
        return;
    }
    // PrestaShop 9: AdminKernel; PrestaShop 8: AppKernel.
    foreach (['AdminKernel', 'AppKernel'] as $class) {
        if (class_exists($class)) {
            $kernel = new $class('prod', false);
            $kernel->boot();

            return;
        }
    }
}

try {
    switch ($command) {
        case 'languages':
            probe_out(['ok' => true, 'languages' => probe_languages(), 'default' => (int) Configuration::get('PS_LANG_DEFAULT')]);
            // no break
        case 'install':
            // Installing a language needs the Symfony container (translator, multilang tables).
            probe_boot_kernel();
            $details = Language::getLangDetails($arg);
            $locale = is_array($details) ? (string) $details['locale'] : '';
            $installed = isset(probe_languages()[$arg]);
            if ($installed && $locale !== '' && is_dir(_PS_ROOT_DIR_ . '/translations/' . $locale)) {
                probe_out(['ok' => true, 'pack' => true, 'message' => 'already installed']);
            }
            if (!$installed) {
                $result = Language::downloadAndInstallLanguagePack($arg, _PS_VERSION_, null, true);
                Language::resetCache();
                if ($result === true && isset(probe_languages()[$arg])) {
                    probe_out(['ok' => true, 'pack' => true, 'message' => 'installed with the translation pack']);
                }
                $errors = is_array($result) ? implode('; ', array_map('strval', $result)) : 'invalid language';
                // No translation pack for this PrestaShop version: add the language without it.
                if (!isset(probe_languages()[$arg]) && $details && Language::checkAndAddLanguage($arg, $details, true)) {
                    Language::resetCache();
                    Language::updateMultilangTable($arg);
                }
                probe_out(['ok' => isset(probe_languages()[$arg]), 'pack' => false, 'message' => "added without the PrestaShop translation pack ($errors)"]);
            }
            // The language exists but its translation pack is missing (e.g. an earlier failed install): add the pack.
            $errors = [];
            if (Language::downloadLanguagePack($arg, _PS_VERSION_, $errors) && Language::installSfLanguagePack($locale, $errors)) {
                Language::updateMultilangTable($arg);
                probe_out(['ok' => true, 'pack' => true, 'message' => 'translation pack added']);
            }
            probe_out(['ok' => true, 'pack' => false, 'message' => 'installed without the PrestaShop translation pack (' . implode('; ', array_map('strval', $errors)) . ')']);
            // no break
        case 'employee-lang':
            $languages = probe_languages();
            if (!isset($languages[$arg])) {
                probe_out(['ok' => false, 'message' => "language $arg is not installed"]);
            }
            Db::getInstance()->execute('UPDATE `' . _DB_PREFIX_ . 'employee` SET id_lang = ' . (int) $languages[$arg]['id'] . " WHERE email = 'demo@prestashop.com'");
            probe_out(['ok' => true]);
            // no break
        case 'config':
            $values = json_decode((string) base64_decode($arg), true);
            $languages = probe_languages();
            foreach (is_array($values) ? $values : [] as $key => $value) {
                if (is_array($value)) {
                    // Translatable value: {"en": "...", "fr": "..."}; languages without a value get the first one.
                    $byId = [];
                    foreach ($languages as $iso => $language) {
                        $byId[$language['id']] = (string) ($value[$iso] ?? reset($value));
                    }
                    $value = $byId;
                }
                Configuration::updateValue((string) $key, is_array($value) ? $value : (string) $value, true);
            }
            probe_out(['ok' => is_array($values), 'count' => is_array($values) ? count($values) : 0]);
            // no break
        case 'setup':
            // The script of docs.json > shop.setup, with PrestaShop and the Symfony kernel loaded. It gets $languages
            // (iso => [id, active, locale]) and may return a short message for the build output.
            probe_boot_kernel();
            if (!is_file($arg)) {
                probe_out(['ok' => false, 'message' => "setup script $arg not found"]);
            }
            $message = (static function (string $file, array $languages) {
                return require $file;
            })($arg, probe_languages());
            Cache::clean('*');
            probe_out(['ok' => true, 'message' => is_string($message) ? $message : '']);
            // no break
        default:
            probe_out(['ok' => false, 'message' => "unknown command '$command'"]);
    }
} catch (Throwable $e) {
    probe_out(['ok' => false, 'message' => get_class($e) . ': ' . $e->getMessage()]);
}
