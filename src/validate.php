<?php
/**
 * Local pre-check for the PrestaShop Addons validator (validator.prestashop.com).
 *
 * Uses the live rule set from https://validator.prestashop.com/rules (cached for
 * a day), falling back to resources/validator-rules.json. It checks what can be
 * checked statically; the real validator still has the final say.
 *
 * Usage:
 *   php validate.php <module-dir>    check the source (vendor/ and tests/ skipped)
 *   php validate.php <module.zip>    check a built zip exactly as it will be uploaded
 *   add --json for machine readable output
 *
 * Exit code: 0 = no errors (warnings allowed), 1 = errors found.
 */

declare(strict_types=1);

$args = array_values(array_filter(array_slice($argv, 1), static fn (string $a): bool => $a !== '--json'));
$asJson = in_array('--json', $argv, true);
$target = $args[0] ?? '';

if ($target === '' || !file_exists($target)) {
    fwrite(STDERR, "Usage: php validate.php <module-dir|module.zip> [--json]\n");
    exit(2);
}

$rules = loadRules();
$fromZip = is_file($target) && str_ends_with(strtolower($target), '.zip');
$moduleDir = $fromZip ? extractZip($target) : rtrim(str_replace('\\', '/', (string) realpath($target)), '/');
$name = basename($moduleDir);

$report = new Report();
$skipDirs = $fromZip ? [] : ['vendor', 'tests', 'node_modules', '.cache', 'dist'];

checkIdentity($moduleDir, $name, $rules, $report);
checkStructure($moduleDir, $rules, $report, $skipDirs, $fromZip);
// Dev files that build-zip.php leaves out of the package.
$devFiles = ['.php-cs-fixer*', 'phpstan*', 'phpcs.xml*', 'phpunit.xml*', 'composer.json', 'composer.lock', '.license-header.txt'];
foreach (listFiles($moduleDir, array_merge($skipDirs, ['vendor'])) as $file) {
    if (!$fromZip && dirname($file) === '.' && array_filter($devFiles, static fn (string $p): bool => fnmatch($p, $file))) {
        continue;
    }
    checkFile($moduleDir, $file, $rules, $report);
}

if ($fromZip) {
    removeTree(dirname($moduleDir));
}

$report->output($asJson, $rules['_version'] ?? 'unknown', $name);

// Definition-of-done stamp (only when nothing failed).
if ($report->errorCount() === 0 && is_file(__DIR__ . '/dod.php')) {
    require_once __DIR__ . '/dod.php';
    if ($fromZip) {
        $meta = is_file($target . '.json') ? json_decode((string) file_get_contents($target . '.json'), true) : null;
        $repoDir = dod_repo_dir(dirname((string) realpath($target)));
        if ($repoDir !== null && is_array($meta) && isset($meta['fingerprint'])) {
            dod_stamp($repoDir, 'validate-zip', $meta['fingerprint']);
        }
    } elseif (($repoDir = dod_repo_dir($moduleDir)) !== null) {
        dod_stamp($repoDir, 'validate-src');
    }
}

exit($report->errorCount() > 0 ? 1 : 0);

// ---------------------------------------------------------------------------

final class Report
{
    /** @var array<int, array{level: string, rule: string, file: string, line: int, message: string}> */
    private array $items = [];

    public function error(string $rule, string $message, string $file = '', int $line = 0): void
    {
        $this->items[] = ['level' => 'error', 'rule' => $rule, 'file' => $file, 'line' => $line, 'message' => $message];
    }

    public function warning(string $rule, string $message, string $file = '', int $line = 0): void
    {
        $this->items[] = ['level' => 'warning', 'rule' => $rule, 'file' => $file, 'line' => $line, 'message' => $message];
    }

    public function errorCount(): int
    {
        return count(array_filter($this->items, static fn (array $i): bool => $i['level'] === 'error'));
    }

    public function output(bool $json, string $rulesVersion, string $module): void
    {
        if ($json) {
            echo json_encode(['module' => $module, 'rules_version' => $rulesVersion, 'errors' => $this->errorCount(), 'items' => $this->items], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

            return;
        }
        echo "Validator pre-check for '$module' (rules v$rulesVersion)\n";
        usort($this->items, static fn (array $a, array $b): int => [$a['level'], $a['rule'], $a['file'], $a['line']] <=> [$b['level'], $b['rule'], $b['file'], $b['line']]);
        foreach ($this->items as $i) {
            $where = $i['file'] !== '' ? $i['file'] . ($i['line'] ? ':' . $i['line'] : '') . ' — ' : '';
            printf("  [%s] %-12s %s%s\n", $i['level'] === 'error' ? 'ERROR' : 'warn ', $i['rule'], $where, $i['message']);
        }
        $warnings = count($this->items) - $this->errorCount();
        printf("\n%d error(s), %d warning(s)\n", $this->errorCount(), $warnings);
    }
}

/** @return array<string, mixed> */
function loadRules(): array
{
    $bundled = dirname(__DIR__) . '/resources/validator-rules.json';
    $cache = sys_get_temp_dir() . '/ps-validator-rules.json';
    $json = null;
    if (is_file($cache) && filemtime($cache) > time() - 86400) {
        $json = file_get_contents($cache);
    } else {
        $live = @file_get_contents('https://validator.prestashop.com/rules', false, stream_context_create(['http' => ['timeout' => 10]]));
        if ($live !== false && is_array(json_decode($live, true))) {
            file_put_contents($cache, $live);
            $json = $live;
        }
    }
    $data = json_decode((string) ($json ?? file_get_contents($bundled)), true);
    $rules = $data['rules'];
    $rules['_version'] = $data['validator']['version'] ?? 'unknown';
    // The live list contains zero-width spaces inside function names (proc_​open).
    foreach ($rules['Disallowed functions & methods'] as &$fn) {
        $fn['name'] = str_replace("\u{200B}", '', $fn['name']);
    }

    return $rules;
}

function extractZip(string $zipFile): string
{
    $work = str_replace('\\', '/', sys_get_temp_dir()) . '/ps_validate_' . uniqid();
    mkdir($work, 0775, true);
    $zip = new ZipArchive();
    if ($zip->open($zipFile) !== true) {
        fwrite(STDERR, "Cannot open $zipFile\n");
        exit(2);
    }
    $zip->extractTo($work);
    $zip->close();
    $entries = array_values(array_diff(scandir($work) ?: [], ['.', '..']));
    if (count($entries) !== 1 || !is_dir("$work/{$entries[0]}")) {
        fwrite(STDERR, "The zip must contain exactly one top-level folder named after the module.\n");
        exit(1);
    }

    return "$work/{$entries[0]}";
}

/**
 * @param string[] $skipDirs
 *
 * @return string[] paths relative to $root
 */
function listFiles(string $root, array $skipDirs): array
{
    $files = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $item) {
        $relative = substr(str_replace('\\', '/', $item->getPathname()), strlen($root) + 1);
        if (array_intersect(explode('/', dirname($relative)), $skipDirs)) {
            continue;
        }
        $files[] = $relative;
    }
    sort($files);

    return $files;
}

/** @param array<string, mixed> $rules */
function checkIdentity(string $dir, string $name, array $rules, Report $r): void
{
    $identity = $rules['Module identity'];
    if (!preg_match($identity['Name regex'], $name)) {
        $r->error('identity', "Module name '$name' does not match {$identity['Name regex']}.");
    }
    if (!preg_match('/^[a-z][a-z0-9]{4,}$/', $name)) {
        $r->warning('identity', "Use only a-z0-9, start with a letter, min. 5 chars, no underscores (translation domains break on '_').");
    }
    $main = "$dir/$name.php";
    if (!is_file($main)) {
        $r->error('identity', "Main file $name.php is missing.");

        return;
    }
    $code = (string) file_get_contents($main);
    $extends = implode('|', $rules['Possible class extends']);
    if (!preg_match('/class\s+(\w+)\s+extends\s+(' . $extends . ')\b/i', $code, $m)) {
        $r->error('identity', "The main class must extend one of: $extends.", "$name.php");
    } elseif (strtolower($m[1]) !== $name) {
        $r->error('identity', "Main class '{$m[1]}' must have the module name '$name'.", "$name.php");
    }
    foreach (['name', 'version', 'author', 'tab', 'displayName', 'description'] as $property) {
        if (!preg_match('/\$this->' . $property . '\s*=/', $code)) {
            $r->error('identity', "The constructor must set \$this->$property.", "$name.php");
        }
    }
    if (preg_match('/\$this->version\s*=\s*[\'"]([^\'"]+)[\'"]/', $code, $v) && !preg_match($identity['Version regex'] . 'u', $v[1])) {
        $r->error('identity', "Version '{$v[1]}' must be X.Y.Z.", "$name.php");
    }
    if (preg_match('/\$this->tab\s*=\s*[\'"]([^\'"]+)[\'"]/', $code, $t) && !in_array($t[1], $identity['Tab values'], true)) {
        $r->error('identity', "Tab '{$t[1]}' is not accepted. Use one of: " . implode(', ', $identity['Tab values']), "$name.php");
    }
    if (!preg_match('/function\s+install\s*\(/', $code)) {
        $r->warning('identity', 'No install() method in the main class.', "$name.php");
    }
    if (is_file("$dir/config.xml") && isset($v[1])) {
        $xml = (string) file_get_contents("$dir/config.xml");
        if (preg_match('#<version><!\[CDATA\[([^\]]+)\]\]></version>#', $xml, $x) && $x[1] !== $v[1]) {
            $r->error('identity', "config.xml version {$x[1]} differs from the class version {$v[1]}.", 'config.xml');
        }
    }
}

/**
 * @param array<string, mixed> $rules
 * @param string[] $skipDirs
 */
function checkStructure(string $dir, array $rules, Report $r, array $skipDirs, bool $fromZip): void
{
    // logo
    if (!is_file("$dir/logo.png")) {
        $r->error('structure', 'logo.png is missing in the module root.');
    } else {
        $size = @getimagesize("$dir/logo.png");
        if ($size === false || $size[2] !== IMAGETYPE_PNG) {
            $r->error('structure', 'logo.png is not a PNG image.', 'logo.png');
        } elseif ($size[0] !== $size[1] || $size[0] < 32) {
            $r->warning('structure', "logo.png should be square and at least 32x32 (is {$size[0]}x{$size[1]}).", 'logo.png');
        }
    }

    // index.php in every directory
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    $dirs = [''];
    foreach ($it as $item) {
        if ($item->isDir()) {
            $relative = substr(str_replace('\\', '/', $item->getPathname()), strlen($dir) + 1);
            if (!array_intersect(explode('/', $relative), $skipDirs)) {
                $dirs[] = $relative;
            }
        }
    }
    foreach ($dirs as $relative) {
        if (!is_file($dir . ($relative === '' ? '' : "/$relative") . '/index.php')) {
            $r->error('structure', 'Missing index.php in ' . ($relative === '' ? '/' : $relative) . ($fromZip ? '' : ' (vendor/ gets them at build time)'));
        }
    }

    // unneeded files
    $unneeded = $rules['Structure']['Uneeded files on production'];
    foreach (listFiles($dir, $skipDirs) as $file) {
        foreach (explode('/', $file) as $segment) {
            if (in_array($segment, $unneeded, true)) {
                $r->error('structure', "Remove '$segment' from the package.", $file);
                break;
            }
        }
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if (preg_match('#^config_[a-z]{2}\.xml$#', basename($file))) {
            $r->warning('structure', 'Do not ship generated config_xx.xml files.', $file);
        }
        if (in_array(basename($file), array_keys($rules['Libraries defined in PS']), true)) {
            $r->warning('structure', 'This library is already shipped with PrestaShop; use the core one.', $file);
        }
        if (str_starts_with($file, 'vendor/')) {
            continue;
        }
        foreach ($rules['Structure']['Expected structure']['App\\Classes\\Entity\\ModuleValidation'] as $pattern => $spec) {
            if (!in_array($ext, $spec['extensions'], true)) {
                continue;
            }
            $path = '/' . $file;
            if (in_array($path, $spec['exceptions'] ?? [], true)) {
                continue;
            }
            if (!preg_match('#^' . $pattern . '/#', $path) && !($ext === 'png' && dirname($file) === '.')) {
                $r->warning('structure', "{$spec['name']} belong in $pattern/.", $file);
            }
        }
    }
}

/** @param array<string, mixed> $rules */
function checkFile(string $root, string $file, array $rules, Report $r): void
{
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    if (!in_array($ext, ['php', 'tpl', 'twig', 'js', 'css', 'xml', 'sql', 'html', 'yml', 'yaml', 'json'], true)) {
        return;
    }
    $content = (string) file_get_contents("$root/$file");

    if (str_starts_with($content, "\xEF\xBB\xBF")) {
        $r->error('encoding', 'File starts with a UTF-8 BOM.', $file);
    }
    if (!mb_check_encoding($content, 'UTF-8')) {
        $r->error('encoding', 'File is not valid UTF-8.', $file);
    }
    foreach (preg_split('/\R/', $content) ?: [] as $n => $line) {
        if (preg_match($rules['Conflicts marks regex'], $line)) {
            $r->error('conflict', 'Merge conflict marker.', $file, $n + 1);
        }
    }

    $isIndex = basename($file) === 'index.php';
    if (in_array($ext, ['php', 'tpl', 'twig', 'js', 'css'], true) && !$isIndex && !str_starts_with($file, 'mails/')) {
        checkLicense($content, $file, $rules, $r, $ext);
    }

    switch ($ext) {
        case 'php':
            checkPhp($content, $file, $rules, $r, $isIndex);
            break;
        case 'tpl':
            checkSmarty($content, $file, $rules, $r);
            break;
        case 'sql':
            checkSql($content, $file, $r);
            break;
        case 'js':
            if (preg_match('/\bconsole\.(log|debug)\s*\(/', $content)) {
                $r->warning('debug', 'Remove console.log/console.debug.', $file);
            }
            break;
    }
}

/** @param array<string, mixed> $rules */
function checkLicense(string $content, string $file, array $rules, Report $r, string $ext): void
{
    $head = implode("\n", array_slice(preg_split('/\R/', $content) ?: [], 0, 40));
    if (!preg_match($rules['Licenses']['Copyright regex'] . 'm', $head)) {
        $level = in_array($ext, ['php', 'tpl'], true) ? 'error' : 'warning';
        $r->$level('license', 'Missing license header (a " * ...LICENSE..." line).', $file);
    }
    $yearRegex = str_replace('<year>', date('y'), $rules['Licenses']['Copyright year regex']);
    if (preg_match($yearRegex, $head)) {
        $r->error('license', 'Copyright year range must end in the current year; prefer "Since YYYY".', $file);
    }
}

/** @param array<string, mixed> $rules */
function checkPhp(string $code, string $file, array $rules, Report $r, bool $isIndex): void
{
    try {
        $tokens = token_get_all($code, TOKEN_PARSE);
    } catch (Throwable $e) {
        $r->error('syntax', 'Parse error: ' . $e->getMessage(), $file, method_exists($e, 'getLine') ? $e->getLine() : 0);

        return;
    }

    if (!$isIndex && !str_contains($code, "defined('_PS_VERSION_')") && !str_contains($code, 'defined("_PS_VERSION_")')) {
        $r->error('security', "Missing `if (!defined('_PS_VERSION_')) { exit; }` guard.", $file);
    }

    $disallowed = [];
    foreach ($rules['Disallowed functions & methods'] as $fn) {
        $disallowed[strtolower($fn['name'])] = $fn;
    }
    $variables = $rules['Disallowed variables'];
    $extTriggers = $rules['PHP Extensions']['triggers'];
    $extChecks = $rules['PHP Extensions']['checks'];

    $significant = array_values(array_filter($tokens, static fn ($t): bool => !is_array($t) || !in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)));
    $count = count($significant);
    $usedExtensions = [];
    $hasExtensionCheck = false;

    for ($i = 0; $i < $count; ++$i) {
        $t = $significant[$i];
        if (!is_array($t)) {
            if ($t === '`') {
                $r->error('security', 'Backtick shell execution is not allowed.', $file);
            }
            continue;
        }
        [$id, $text, $line] = $t;
        $prev = $significant[$i - 1] ?? null;
        $next = $significant[$i + 1] ?? null;
        $prevId = is_array($prev) ? $prev[0] : $prev;
        $isCall = $next === '(';
        $isMember = in_array($prevId, [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON], true);
        $isDeclaration = $prevId === T_FUNCTION;

        if ($id === T_EVAL) {
            $r->error('security', 'eval() is not allowed.', $file, $line);
        }
        if ($id === T_GLOBAL) {
            $r->warning('standards', 'Avoid the global keyword.', $file, $line);
        }
        if ($id === T_VARIABLE && array_key_exists($text, $variables)) {
            $hint = $variables[$text] ? " Use {$variables[$text]}." : '';
            $r->error('security', "$text is not allowed.$hint", $file, $line);
        }
        if ($id === T_STRING && in_array($text, ['_SESSION', '_GET', '_POST', '_COOKIE'], true) && $prevId === '{') {
            $r->error('security', "\${'$text'} is not allowed.", $file, $line);
        }
        if ($id !== T_STRING && $id !== T_NAME_FULLY_QUALIFIED) {
            continue;
        }
        $fname = strtolower(ltrim($text, '\\'));

        if (in_array($fname, $extChecks, true)) {
            $hasExtensionCheck = true;
        }
        foreach ($extTriggers as $extension => $triggers) {
            if (in_array($fname, array_map('strtolower', $triggers), true) && !$isMember) {
                $usedExtensions[$extension] = $line;
            }
        }

        if ($isDeclaration && $fname === 'delete') {
            $r->warning('security', "A method named delete() is reported by the validator ('delete' is on its list).", $file, $line);
        }
        if (!$isCall || $isMember || $isDeclaration || $prevId === T_NEW) {
            continue;
        }
        if (isset($disallowed[$fname])) {
            $rule = $disallowed[$fname];
            if (isset($rule['needles'])) {
                $args = '';
                for ($j = $i + 1; $j < min($count, $i + 6); ++$j) {
                    $args .= is_array($significant[$j]) ? $significant[$j][1] : $significant[$j];
                }
                if (in_array(basename($file), $rule['excludes'] ?? [], true) || !preg_match('/' . implode('|', $rule['needles']) . '/i', $args)) {
                    continue;
                }
            }
            $hint = isset($rule['replace_with']) ? " Use {$rule['replace_with']}." : (isset($rule['error_message']) ? " Use {$rule['error_message']}." : '');
            $r->error('security', "$fname() is not allowed.$hint", $file, $line);
        }
        if (in_array($fname, ['serialize', 'unserialize'], true)) {
            $r->error('security', "$fname() is not allowed in review; use json_encode/json_decode.", $file, $line);
        }
        if (in_array($fname, ['var_dump', 'print_r', 'var_export', 'dump', 'dd', 'debug_print_backtrace', 'error_log'], true)) {
            $r->warning('debug', "Remove debug call $fname().", $file, $line);
        }
        if (in_array($fname, ['base64_decode', 'create_function', 'extract', 'assert'], true)) {
            $r->warning('security', "$fname() is a red flag for reviewers; avoid it or explain it.", $file, $line);
        }
    }
    foreach ($usedExtensions as $extension => $line) {
        if (!$hasExtensionCheck) {
            $r->error('extensions', "$extension used without a guard (" . implode('/', $extChecks) . ').', $file, $line);
        }
    }
}

/** @param array<string, mixed> $rules */
function checkSmarty(string $tpl, string $file, array $rules, Report $r): void
{
    $escapes = $rules['Smarty']['Expected escapes'];
    $modifiers = $rules['Smarty']['Expected modifiers'];
    $lines = preg_split('/\R/', $tpl) ?: [];
    foreach ($lines as $n => $line) {
        if (preg_match('/\|(unescape|html_entity_decode)/', $line)) {
            $r->error('smarty', '|unescape and |html_entity_decode are not allowed.', $file, $n + 1);
        }
        // Output tags: {$var...} (not {if $x}, {foreach ...}, {l ...})
        if (preg_match_all('/\{\$([^}]*)\}/', $line, $matches)) {
            foreach ($matches[1] as $expr) {
                if (preg_match('/\bnofilter\b/', $expr)) {
                    continue;
                }
                if (preg_match('/^\w+\s*=/', $expr) || preg_match('/^[\w.\[\]>-]+\s*(\+\+|--)$/', $expr)) {
                    continue; // assignment like {$i = 0}
                }
                $ok = false;
                if (preg_match('/\|escape:([\'"])([^\'"]*)\1:([\'"])([^\'"]*)\3/', $expr, $m)) {
                    $ok = in_array($m[2], $modifiers, true);
                }
                foreach ($escapes as $escape) {
                    if (preg_match('/\|' . $escape . '\b/', $expr)) {
                        $ok = true;
                    }
                }
                if (!$ok) {
                    $r->error('smarty', "Unescaped output {\$$expr}. Use |escape:'html':'UTF-8' (or nofilter with a reason).", $file, $n + 1);
                }
            }
        }
        if (preg_match_all('/\{l\s+([^}]*)\}/', $line, $ls)) {
            foreach ($ls[1] as $attrs) {
                if (!preg_match("/\\bs='/", $attrs)) {
                    $r->error('translations', "{l} needs s='...' with single quotes.", $file, $n + 1);
                }
                if (!preg_match('/\b(d|mod)=/', $attrs)) {
                    $r->error('translations', "{l} needs d='Modules.<Module>.Shop' (or mod='<module>').", $file, $n + 1);
                }
            }
        }
    }
}

function checkSql(string $sql, string $file, Report $r): void
{
    if (preg_match('/\bTRIGGER\b/i', $sql)) {
        $r->error('sql', 'TRIGGER is not allowed.', $file);
    }
    if (preg_match_all('/\bCREATE\s+TABLE\s+(?!IF\s+NOT\s+EXISTS)/i', $sql) > 0) {
        $r->error('sql', 'Use CREATE TABLE IF NOT EXISTS.', $file);
    }
    if (preg_match_all('/\bDROP\s+TABLE\s+(?!IF\s+EXISTS)/i', $sql) > 0) {
        $r->error('sql', 'Use DROP TABLE IF EXISTS.', $file);
    }
    if (preg_match('/\b(ADD|DROP)\s+(COLUMN|INDEX|KEY)\s+IF\s+(NOT\s+)?EXISTS\b/i', $sql)) {
        $r->warning('sql', 'ADD/DROP ... IF [NOT] EXISTS only works on MariaDB, not on MySQL (DB-03).', $file);
    }
    if (preg_match('/\bps_\w+/i', $sql) && !str_contains($sql, '{_DB_PREFIX_}')) {
        $r->warning('sql', 'Hard-coded "ps_" table prefix; use {_DB_PREFIX_}.', $file);
    }
}

function removeTree(string $dir): void
{
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($dir);
}
