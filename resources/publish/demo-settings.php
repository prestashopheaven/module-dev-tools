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
 * The fixed settings of the demo shop, applied by demo-site.sh after every restore, deploy and activate (DEMO-04, DEMO-08):
 *
 *   php demo-settings.php <url> <email> <password> <intro-path> <docs-url> <reset-time> "<languages>"   (shop root)
 *
 * - the languages of the demo (DEMO-01, e.g. "en nl"): missing ones are installed (language pack from PrestaShop),
 *   they are active, every other language is switched off; the default language is one of them;
 * - e-mail off: PS_MAIL_METHOD = 3 ("never send e-mails"); in demo mode visitors cannot change it;
 * - the demo employee <email> / <password> (SuperAdmin, active), created or reset;
 * - the intro page <intro-path>/index.html: links to the shop and to the back office with the login filled in
 *   (PrestaShop 9 fills the login form from ?email=&password= in demo mode), and the PrestaShopHeaven modules in the
 *   shop with their version and a link to their manuals on the docs site.
 * Prints "DEMO-SETTINGS-OK <admin folder>".
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli' || !is_file('config/config.inc.php')) {
    fwrite(STDERR, "Run in the root of the shop: php demo-settings.php <url> <email> <password> <intro-path> <docs-url> <reset-time>\n");
    exit(2);
}
[$url, $email, $password, $introPath, $docsUrl, $resetTime, $languageList] = array_slice($argv, 1, 7) + ['', 'demo@demo.com', 'demodemo', 'start', '', '03:30', ''];
if (preg_match('#^[a-z0-9-]+$#', $introPath) !== 1 || filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($password) < 8) {
    fwrite(STDERR, "Invalid intro path (a-z, 0-9, -), e-mail address or password (at least 8 characters).\n");
    exit(2);
}

require 'config/config.inc.php';

// The Symfony kernel (global $kernel, where SymfonyContainer looks): installing a language translates the
// multilingual tables with the translator service. PrestaShop 9: AdminKernel; PrestaShop 8: AppKernel.
global $kernel;
foreach (['AdminKernel', 'AppKernel'] as $kernelClass) {
    if ($kernel === null && class_exists($kernelClass)) {
        $kernel = new $kernelClass('prod', false);
        $kernel->boot();
    }
}

// 0. The languages of the demo: installed and active, the others off.
$wanted = array_values(array_filter(preg_split('/[\s,]+/', strtolower($languageList)) ?: [], static fn (string $iso): bool => preg_match('/^[a-z]{2}$/', $iso) === 1));
if ($wanted !== []) {
    foreach ($wanted as $iso) {
        if ((int) Language::getIdByIso($iso) === 0) {
            $result = Language::downloadAndInstallLanguagePack($iso, _PS_VERSION_, null, true);
            if ($result !== true || (int) Language::getIdByIso($iso, true) === 0) {
                fwrite(STDERR, "Installing language $iso failed: " . (is_array($result) ? implode(' ', $result) : 'unknown error') . "\n");
                exit(1);
            }
        }
    }
    $wantedIds = array_map(static fn (string $iso): int => (int) Language::getIdByIso($iso, true), $wanted);
    if (!in_array((int) Configuration::get('PS_LANG_DEFAULT'), $wantedIds, true)) {
        Configuration::updateGlobalValue('PS_LANG_DEFAULT', $wantedIds[0]);
    }
    foreach (Language::getLanguages(false) as $language) {
        $active = in_array((int) $language['id_lang'], $wantedIds, true) ? 1 : 0;
        if ((int) $language['active'] !== $active) {
            Db::getInstance()->update('lang', ['active' => $active], 'id_lang = ' . (int) $language['id_lang']);
        }
    }
    Language::resetCache();
}

// 1. E-mail off.
Configuration::updateGlobalValue('PS_MAIL_METHOD', 3);

// 2. The demo employee.
$hashing = new PrestaShop\PrestaShop\Core\Crypto\Hashing();
$id = (int) Employee::employeeExists($email);
$employee = $id > 0 ? new Employee($id) : new Employee();
$employee->email = $email;
$employee->firstname = 'Demo';
$employee->lastname = 'PrestaShopHeaven';
$employee->passwd = $hashing->hash($password);
$employee->id_profile = _PS_ADMIN_PROFILE_;
$employee->id_lang = (int) Configuration::get('PS_LANG_DEFAULT');
$employee->active = true;
$employee->default_tab = (int) Tab::getIdFromClassName('AdminDashboard');
if (!($id > 0 ? $employee->update() : $employee->add())) {
    fwrite(STDERR, "Saving the demo employee $email failed.\n");
    exit(1);
}

// 3. The intro page.
// The back-office folder: _PS_ADMIN_DIR_ only exists in the back office, so look for it (index.php + init.php + themes/).
$adminDir = '';
foreach (glob(_PS_ROOT_DIR_ . '/*', GLOB_ONLYDIR) ?: [] as $candidate) {
    if (is_file("$candidate/index.php") && is_file("$candidate/init.php") && is_dir("$candidate/themes")) {
        $adminDir = basename($candidate);
        break;
    }
}
if ($adminDir === '') {
    fwrite(STDERR, "No back-office folder found in " . _PS_ROOT_DIR_ . ".\n");
    exit(1);
}
$base = rtrim($url !== '' ? $url : Tools::getShopDomainSsl(true) . __PS_BASE_URI__, '/');
$login = $base . '/' . $adminDir . '/index.php?controller=AdminLogin&email=' . rawurlencode($email) . '&password=' . rawurlencode($password);
$modules = [];
foreach (Db::getInstance()->executeS('SELECT name, version, active FROM ' . _DB_PREFIX_ . "module WHERE name LIKE 'heaven%' ORDER BY name") ?: [] as $row) {
    $instance = Module::getInstanceByName((string) $row['name']);
    $modules[] = [
        'name' => (string) $row['name'],
        'display' => $instance ? (string) $instance->displayName : (string) $row['name'],
        'description' => $instance ? (string) $instance->description : '',
        'version' => (string) $row['version'],
    ];
}
$e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$items = '';
foreach ($modules as $module) {
    $docs = $docsUrl !== '' ? ' · <a href="' . $e(rtrim($docsUrl, '/') . '/' . $module['name'] . '/index.php') . '">Manual (PDF, all languages)</a>' : '';
    $items .= '<li><strong>' . $e($module['display']) . '</strong> <span>v' . $e($module['version']) . '</span><br>'
        . $e($module['description']) . $docs . "</li>\n";
}
if ($items === '') {
    $items = "<li>No PrestaShopHeaven modules installed yet.</li>\n";
}
$html = '<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>PrestaShopHeaven demo shop</title>
<style>
  :root { --bg: #f8fbff; --card: #fff; --text: #0f172a; --muted: #475569; --primary: #1e3a8a; --accent: #f5b42a; --line: #dbe4f0; }
  * { box-sizing: border-box; }
  body { margin: 0; background: var(--bg); color: var(--text); font: 16px/1.5 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; }
  header { background: linear-gradient(135deg, #1e3a8a, #3b82f6); color: #fff; padding: 40px 16px; }
  header div, main { max-width: 860px; margin: 0 auto; }
  h1 { margin: 0 0 8px; font-size: 1.8rem; }
  main { padding: 24px 16px 48px; }
  .buttons { display: flex; flex-wrap: wrap; gap: 12px; margin: 0 0 16px; }
  .buttons a { background: var(--primary); color: #fff; padding: 12px 20px; border-radius: 8px; text-decoration: none; font-weight: 600; }
  .buttons a.admin { background: var(--accent); color: var(--text); }
  .note { color: var(--muted); }
  code { background: #eef2ff; padding: 1px 6px; border-radius: 4px; }
  ul { list-style: none; padding: 0; margin: 0; background: var(--card); border: 1px solid var(--line); border-radius: 10px; }
  li { padding: 14px 16px; border-top: 1px solid var(--line); }
  li:first-child { border-top: 0; }
  li span { color: var(--muted); }
  a { color: var(--primary); }
</style>
</head>
<body>
<header><div><h1>PrestaShopHeaven demo shop</h1><p>Try every PrestaShopHeaven module in a real PrestaShop ' . $e(_PS_VERSION_) . ' shop, in the shop and in the back office.</p></div></header>
<main>
  <p class="buttons"><a href="' . $e($base . '/') . '">Open the shop</a> <a class="admin" href="' . $e($login) . '">Open the back office</a></p>
  <p class="note">Back office login (already filled in, just click "Log in"): <code>' . $e($email) . '</code> / <code>' . $e($password) . '</code>.
  Everyone uses the same demo: feel free to try things, the shop is reset every night at ' . $e($resetTime) . '. E-mail is switched off, and
  some settings (uploading modules, e-mail, employees) are locked.</p>
  <h2>Modules in this demo</h2>
  <ul>
' . $items . '  </ul>
</main>
</body>
</html>
';
$dir = _PS_ROOT_DIR_ . '/' . $introPath;
if (!is_dir($dir) && !mkdir($dir, 0775, true)) {
    fwrite(STDERR, "Cannot create $dir.\n");
    exit(1);
}
file_put_contents("$dir/index.html", $html);
echo 'DEMO-SETTINGS-OK ' . $adminDir . ' (languages ' . ($wanted !== [] ? implode(' ', $wanted) : 'unchanged') . ', e-mail off, employee ' . $email . ', intro /' . $introPath . '/, ' . count($modules) . " module(s))\n";
exit(0);
