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
 * - the demo employee <email> / <password> (SuperAdmin, active), created or reset; never two with that address
 *   (PrestaShop's login fails on a duplicate e-mail address);
 * - the login always filled in: PrestaShop 9 fills the login form from ?email=&password= in demo mode, so a block in
 *   <admin>/.htaccess sends every GET of the login page without them to the same page with them (Apache);
 * - the brand (when brand-logo.svg / brand-logo.png are next to this script, uploaded by ps-module from the
 *   workspace's branding/): the shop logo (PS_LOGO, PNG), the logo of the back-office header (SVG, by a CSS rule in
 *   the admin theme) and of the login page (img/prestashop@2x.png);
 * - the intro page <intro-path>/index.html in the brand colours with the brand logo: links to the shop and to the back
 *   office, and the PrestaShopHeaven modules in the shop with their version and a link to their manuals.
 * With DEMO_SETTINGS_ONLY=intro (demo-site.sh deploy) only the intro page is written; the rest stays as it is.
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

/**
 * One verbose line (VERBOSE=1, set by demo-site.sh -v / ps-module -v), on the output demo-site.sh shows.
 */
function demo_vlog(string $message): void
{
    if (getenv('VERBOSE') === '1') {
        echo '  [v] demo-settings: ' . $message . "\n";
    }
}

/**
 * Writes a file of the shop: to a temporary file next to it, then renamed over the old one. A rename only needs the
 * folder to be writable, so it also replaces a file of another owner (one that root wrote, e.g. by a command run as
 * root on the server), which a plain write cannot change. Stops the script when it fails: never report a page that was
 * not written.
 */
function demo_write(string $file, string $content): void
{
    $tmp = dirname($file) . '/.' . basename($file) . '.tmp-' . getmypid();
    if (file_put_contents($tmp, $content) === false || !chmod($tmp, 0644) || !rename($tmp, $file)) {
        @unlink($tmp);
        fwrite(STDERR, "Cannot write $file (is the folder writable for this account?).\n");
        exit(1);
    }
}

demo_vlog('PrestaShop ' . _PS_VERSION_ . ', PHP ' . PHP_VERSION . ', shop root ' . _PS_ROOT_DIR_);

// The Symfony kernel (global $kernel, where SymfonyContainer looks): installing a language translates the
// multilingual tables with the translator service. PrestaShop 9: AdminKernel; PrestaShop 8: AppKernel.
global $kernel;
foreach (['AdminKernel', 'AppKernel'] as $kernelClass) {
    if ($kernel === null && class_exists($kernelClass)) {
        $kernel = new $kernelClass('prod', false);
        $kernel->boot();
        demo_vlog("Symfony kernel $kernelClass booted");
    }
}

// DEMO_SETTINGS_ONLY=intro (demo-site.sh deploy): only the intro page with its list of modules; the languages, e-mail,
// employee, login rules and brand stay as they are in the shop.
$introOnly = getenv('DEMO_SETTINGS_ONLY') === 'intro';
if ($introOnly) {
    demo_vlog('intro page only: languages, e-mail, employee, login rules and brand are left as they are');
}
$brandSvg = __DIR__ . '/brand-logo.svg';
$brandPng = __DIR__ . '/brand-logo.png';
$brand = [];

// 0. The languages of the demo: installed and active, the others off.
$wanted = $introOnly ? [] : array_values(array_filter(preg_split('/[\s,]+/', strtolower($languageList)) ?: [], static fn (string $iso): bool => preg_match('/^[a-z]{2}$/', $iso) === 1));
if ($wanted !== []) {
    foreach ($wanted as $iso) {
        if ((int) Language::getIdByIso($iso) === 0) {
            demo_vlog("language $iso: not installed, installing the language pack");
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
            demo_vlog('language ' . $language['iso_code'] . ': ' . ($active ? 'switched on' : 'switched off'));
            Db::getInstance()->update('lang', ['active' => $active], 'id_lang = ' . (int) $language['id_lang']);
        }
    }
    Language::resetCache();
    demo_vlog('languages: ' . implode(' ', $wanted) . ', default ' . Language::getIsoById((int) Configuration::get('PS_LANG_DEFAULT')));
}

if (!$introOnly) {
    // 1. E-mail off.
    Configuration::updateGlobalValue('PS_MAIL_METHOD', 3);
    demo_vlog('e-mail off (PS_MAIL_METHOD 3)');

    // 2. The demo employee.
    $hashing = new PrestaShop\PrestaShop\Core\Crypto\Hashing();
    // Employee::employeeExists() only says yes or no: look the ids up, keep the lowest and remove duplicates.
    $ids = array_map('intval', array_column(Db::getInstance()->executeS('SELECT id_employee FROM ' . _DB_PREFIX_ . "employee WHERE email = '" . pSQL($email) . "' ORDER BY id_employee") ?: [], 'id_employee'));
    $id = $ids[0] ?? 0;
    foreach (array_slice($ids, 1) as $duplicate) {
        (new Employee($duplicate))->delete();
        demo_vlog("removed duplicate demo employee #$duplicate");
    }
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
    demo_vlog(($id > 0 ? "demo employee #$id updated" : "demo employee #{$employee->id} created") . " ($email, SuperAdmin, password reset to the demo password)");
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
if (!$introOnly) {
    // 4. The login always filled in (Apache): GET of the login page without ?email= -> the same page with the login.
    $htaccess = _PS_ROOT_DIR_ . '/' . $adminDir . '/.htaccess';
    $rules = (string) @file_get_contents($htaccess);
    $rules = (string) preg_replace('/\n?# BEGIN heaven-demo-login.*?# END heaven-demo-login\n?/s', "\n", $rules);
    $query = 'email=' . rawurlencode($email) . '&password=' . rawurlencode($password);
    // The shop's own address: behind a proxy (Cloudflare, nginx) Apache may get plain HTTP and would redirect to http://.
    $ssl = (bool) Configuration::get('PS_SSL_ENABLED');
    $base = ($ssl ? 'https://' : 'http://') . (string) Configuration::get($ssl ? 'PS_SHOP_DOMAIN_SSL' : 'PS_SHOP_DOMAIN');
    // At the top: PrestaShop's own rules below end with [L] and would handle the login URL first.
    $rules = "# BEGIN heaven-demo-login (demo-settings.php: the demo login is always filled in)\n"
        . "<IfModule mod_rewrite.c>\nRewriteEngine on\nRewriteCond %{REQUEST_METHOD} =GET\nRewriteCond %{QUERY_STRING} !(^|&)email=\n"
        . 'RewriteRule ^(index\.php/)?login$ ' . $base . '%{REQUEST_URI}?' . str_replace('%', '\%', $query) . " [QSA,NE,R=302,L]\n</IfModule>\n# END heaven-demo-login\n\n" . ltrim($rules);
    demo_write($htaccess, $rules);
    demo_vlog("login filled in: rule at the top of $adminDir/.htaccess (redirect to $base)");

    // 4b. HTTPS behind a proxy: a proxy that reaches the server over plain HTTP (Cloudflare "Flexible", a load balancer)
    // tells the visitor's scheme in a header; PHP then sees HTTPS=on, so PrestaShop does not refuse the back-office login
    // ("SSL is activated") and builds https links. Kept at the top of the root .htaccess (PrestaShop keeps text outside
    // its own block when it rewrites the file); set again after every restore.
    $rootHtaccess = _PS_ROOT_DIR_ . '/.htaccess';
    $rootRules = (string) preg_replace('/\n?# BEGIN heaven-demo-https.*?# END heaven-demo-https\n?/s', "\n", (string) @file_get_contents($rootHtaccess));
    if ($ssl) {
        $rootRules = "# BEGIN heaven-demo-https (demo-settings.php: the visitor's HTTPS as told by a proxy)\n"
            . "<IfModule mod_setenvif.c>\nSetEnvIf X-Forwarded-Proto \"^https$\" HTTPS=on\nSetEnvIf CF-Visitor \"\\\"scheme\\\":\\\"https\\\"\" HTTPS=on\n</IfModule>\n"
            . "# END heaven-demo-https\n\n" . ltrim($rootRules);
    }
    demo_write($rootHtaccess, ltrim($rootRules));
    demo_vlog($ssl ? 'HTTPS behind a proxy: rules at the top of .htaccess (X-Forwarded-Proto, CF-Visitor)' : 'SSL off: no HTTPS proxy rules in .htaccess');

    // 5. The brand: shop logo, back-office header logo, login page logo.
    if (is_file($brandPng)) {
        demo_write(_PS_IMG_DIR_ . 'heaven-demo-logo.png', (string) file_get_contents($brandPng));
        Configuration::updateGlobalValue('PS_LOGO', 'heaven-demo-logo.png');
        Db::getInstance()->delete('configuration', "name = 'PS_LOGO' AND (id_shop IS NOT NULL OR id_shop_group IS NOT NULL)");
        // The login page shows img/prestashop@2x.png (PrestaShop 8.1 and 9.x).
        demo_write(_PS_IMG_DIR_ . 'prestashop@2x.png', (string) file_get_contents($brandPng));
        $brand[] = 'shop + login logo';
    }
    if (is_file($brandSvg)) {
        foreach (['new-theme', 'default'] as $theme) {
            $css = _PS_ROOT_DIR_ . '/' . $adminDir . "/themes/$theme/public/theme.css";
            if (!is_file($css)) {
                continue;
            }
            demo_write(dirname($css) . '/heaven-demo-logo.svg', (string) file_get_contents($brandSvg));
            $text = (string) preg_replace('#\n?/\* BEGIN heaven-demo-logo \*/.*?/\* END heaven-demo-logo \*/\n?#s', '', (string) file_get_contents($css));
            demo_write($css, rtrim($text) . "\n/* BEGIN heaven-demo-logo */#header_logo,.logo,.logo-container .logo{background-image:url(heaven-demo-logo.svg)!important;background-size:34px 34px!important;background-position:left center!important}/* END heaven-demo-logo */\n");
        }
        $brand[] = 'back-office logo';
    }
    Tools::clearSmartyCache();
    demo_vlog('brand: ' . ($brand !== [] ? implode(', ', $brand) : 'no brand-logo.svg/png next to this script') . '; Smarty cache cleared');
}

$base =rtrim($url !== '' ? $url : Tools::getShopDomainSsl(true) . __PS_BASE_URI__, '/');
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
$dir = _PS_ROOT_DIR_ . '/' . $introPath;
if (!is_dir($dir) && !mkdir($dir, 0775, true)) {
    fwrite(STDERR, "Cannot create $dir.\n");
    exit(1);
}
$logo = '';
if (is_file($brandSvg)) {
    demo_write("$dir/logo.svg", (string) file_get_contents($brandSvg));
    $logo = 'logo.svg';
}
$html = '<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>PrestaShopHeaven demo shop</title>
<style>
  :root { --bg: #fdf6fa; --card: #fff; --text: #3c3c3b; --muted: #6b6470; --primary: #e31b87; --secondary: #802982; --line: #efd9e7; }
  * { box-sizing: border-box; }
  body { margin: 0; background: var(--bg); color: var(--text); font: 16px/1.5 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; }
  header { background: linear-gradient(135deg, var(--primary), var(--secondary)); color: #fff; padding: 40px 16px; }
  header div, main { max-width: 860px; margin: 0 auto; }
  header div { display: flex; align-items: center; gap: 24px; }
  header .logo { flex: none; width: 96px; height: 96px; background: #fff; border-radius: 20px; padding: 12px; }
  h1 { margin: 0 0 8px; font-size: 1.8rem; }
  h2 { color: var(--secondary); }
  main { padding: 24px 16px 48px; }
  .buttons { display: flex; flex-wrap: wrap; gap: 12px; margin: 0 0 16px; }
  .buttons a { background: var(--primary); color: #fff; padding: 12px 20px; border-radius: 8px; text-decoration: none; font-weight: 600; }
  .buttons a.admin { background: var(--secondary); }
  .buttons a:hover { filter: brightness(1.08); }
  .note { color: var(--muted); }
  code { background: #fbe3f0; color: var(--secondary); padding: 1px 6px; border-radius: 4px; }
  ul { list-style: none; padding: 0; margin: 0; background: var(--card); border: 1px solid var(--line); border-radius: 10px; }
  li { padding: 14px 16px; border-top: 1px solid var(--line); }
  li:first-child { border-top: 0; }
  li span { color: var(--muted); }
  a { color: var(--secondary); }
</style>
</head>
<body>
<header><div>' . ($logo !== '' ? '<img class="logo" src="' . $e($logo) . '" alt="PrestaShopHeaven">' : '') . '<div><h1>PrestaShopHeaven demo shop</h1><p>Try every PrestaShopHeaven module in a real PrestaShop ' . $e(_PS_VERSION_) . ' shop, in the shop and in the back office.</p></div></div></header>
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
demo_write("$dir/index.html", $html);
demo_vlog("intro page $dir/index.html (" . strlen($html) . ' bytes): ' . ($modules !== [] ? implode(', ', array_map(static fn (array $m): string => $m['name'] . ' ' . $m['version'], $modules)) : 'no modules') . ($docsUrl !== '' ? ", manuals at $docsUrl" : ''));
if ($introOnly) {
    echo 'DEMO-SETTINGS-OK ' . $adminDir . ' (only the intro /' . $introPath . '/, ' . count($modules) . " module(s))\n";
    exit(0);
}
echo 'DEMO-SETTINGS-OK ' . $adminDir . ' (languages ' . ($wanted !== [] ? implode(' ', $wanted) : 'unchanged') . ', e-mail off, employee ' . $email . ', login filled in, brand: ' . ($brand !== [] ? implode(', ', $brand) : 'no brand files') . ', intro /' . $introPath . '/, ' . count($modules) . " module(s))\n";
exit(0);
