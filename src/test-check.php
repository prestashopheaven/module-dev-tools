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
 * Test check of a module (conventions TST-04..12), used by conventions-check.php. Static, no PHPUnit run:
 *
 *   TST-04  every class, trait and enum in src/, controllers/ and classes/ has its own test file:
 *           src/<path>/<Name>.php -> tests/Unit/<path>/<Name>Test.php and/or tests/Integration/<path>/<Name>Test.php
 *           controllers/front/<name>.php -> tests/Integration/Controllers/Front/<Name>Test.php (admin: Admin),
 *           classes/<Name>.php -> tests/Integration/Classes/<Name>Test.php
 *   TST-05  every public and protected method (not __construct, not abstract) is named in that test file: called
 *           (->name( / ::name( ), as a string ('name', a hook 'displayHome' for hookDisplayHome) or in a test name
 *   TST-06  every exception the class throws (throw new X / throw X::factory()) is expected in that test file:
 *           $this->expectException(X::class)
 *   TST-07  every command and query of src/Application is dispatched in an integration test (new <Name>Command()
 *           / new <Name>Query() in tests/Integration)
 *   TST-27  third-party JavaScript in views/js/vendor/<library>/ needs no tests, but its license file sits next to it
 * The coverage gate (TST-08, every line of Domain, Application and Presenter executed by the unit tests) is
 * coverage.php, run by "ps-module check".
 */

declare(strict_types=1);

require_once __DIR__ . '/verbose.php';

/**
 * Runs the static test checks; returns violations as "path:line TST-xx message".
 *
 * @return string[]
 */
function checkTests(string $moduleDir): array
{
    $name = basename($moduleDir);
    // Only PrestaShop modules (main file <module>/<module>.php), not the shared libraries.
    if (!is_file("$moduleDir/$name.php")) {
        ps_vlog('tests: skipped (no main file <module>.php: not a module)');

        return [];
    }
    $found = [];
    $subjects = 0;
    foreach (['tests/Unit', 'tests/Integration'] as $dir) {
        if (!is_dir("$moduleDir/$dir")) {
            $found[] = "$dir/:1 TST-04 missing: every module has unit tests and integration tests (unit + integration in module.spec.json > tests)";
        }
    }
    $integrationText = testAllText("$moduleDir/tests/Integration");
    foreach (testSubjectFiles($moduleDir) as $relative) {
        $tokens = token_get_all((string) file_get_contents("$moduleDir/$relative"));
        $subject = testSubject($tokens);
        if ($subject === null) {
            continue; // interface or no declaration: nothing to execute
        }
        ++$subjects;
        $testFiles = testFilesFor($relative);
        $text = '';
        foreach ($testFiles as $testFile) {
            if (is_file("$moduleDir/$testFile")) {
                $text .= "\n" . (string) file_get_contents("$moduleDir/$testFile");
            }
        }
        if ($text === '') {
            $found[] = "$relative:{$subject['line']} TST-04 {$subject['name']} has no test: " . implode(' or ', $testFiles);
            continue;
        }
        // A trait is used through a host or the main class: testing all its methods counts as using it.
        $traitTested = $subject['trait'] && $subject['methods'] !== [] && array_filter($subject['methods'], static fn (array $m): bool => !testMentionsMethod($text, $m[0])) === [];
        if (!$traitTested && !preg_match('/\b' . preg_quote($subject['name'], '/') . '\b/', $text)) {
            $found[] = "$relative:{$subject['line']} TST-04 the test (" . implode(', ', array_filter($testFiles, static fn (string $f): bool => is_file("$moduleDir/$f"))) . ") does not use {$subject['name']}";
        }
        foreach ($subject['methods'] as [$method, $line]) {
            if (!testMentionsMethod($text, $method)) {
                $found[] = "$relative:$line TST-05 {$subject['name']}::$method() is not tested: call it in the test, with the normal case and every fault case";
            }
        }
        foreach ($subject['throws'] as [$exception, $line]) {
            if (!preg_match('/expectException\(\s*\\\\?(?:[\w\\\\]*\\\\)?' . preg_quote($exception, '/') . '::class\s*\)/', $text)) {
                $found[] = "$relative:$line TST-06 {$subject['name']} throws $exception, but no test expects it: \$this->expectException($exception::class) with the faulty input";
            }
        }
        if (preg_match('#^src/Application/[^/]+/(Command|Query)/([^/]+)/\2\1\.php$#', $relative, $m) && !preg_match('/new\s+\\\\?(?:[\w\\\\]*\\\\)?' . preg_quote($m[2] . $m[1], '/') . '\s*\(/', $integrationText)) {
            $found[] = "$relative:{$subject['line']} TST-07 {$m[2]}{$m[1]} is never dispatched in an integration test (tests/Integration: through the bus, against a real shop)";
        }
    }

    ps_vlog("tests: $subjects class(es)/trait(s)/enum(s) in src/, controllers/ and classes/ matched against tests/Unit and tests/Integration (TST-04..07)");

    return array_merge($found, checkVendorScripts($moduleDir));
}

/**
 * TST-27: third-party JavaScript in views/js/vendor/ needs no tests, but every library sits in its own folder
 * views/js/vendor/<library>/ together with its license file (LICENSE, LICENCE or COPYING, any extension).
 *
 * @return string[] violations as "path:1 TST-27 message"
 */
function checkVendorScripts(string $moduleDir): array
{
    $found = [];
    // The online validator skips the license header in every lib/, libs/, vendor/ and vendors/ folder: own code
    // there would lose that check. Third-party JavaScript has one place, views/js/vendor/<library>/.
    $it = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(
        new RecursiveDirectoryIterator($moduleDir, FilesystemIterator::SKIP_DOTS),
        static fn (SplFileInfo $f, string $path): bool => !($f->isDir() && dirname(str_replace('\\', '/', $path)) === $moduleDir
            && in_array($f->getFilename(), ['vendor', 'node_modules', '_dev', 'tests', '.cache', 'dist', '.git'], true))
    ));
    foreach ($it as $file) {
        $relative = substr(str_replace('\\', '/', $file->getPathname()), strlen($moduleDir) + 1);
        if (strtolower($file->getExtension()) === 'js' && !str_starts_with($relative, 'views/js/vendor/')
            && array_intersect(explode('/', dirname($relative)), ['lib', 'libs', 'vendor', 'vendors']) !== []) {
            $found[] = "$relative:1 TST-27 JavaScript in a lib/, libs/, vendor/ or vendors/ folder (the validator skips its license check there): third-party code goes in views/js/vendor/<library>/, own code elsewhere in views/js/";
        }
    }
    $vendorDir = "$moduleDir/views/js/vendor";
    if (!is_dir($vendorDir)) {
        return $found;
    }
    foreach (scandir($vendorDir) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..' || $entry === 'index.php') {
            continue;
        }
        if (!is_dir("$vendorDir/$entry")) {
            $found[] = "views/js/vendor/$entry:1 TST-27 third-party files go in a folder per library with its license: views/js/vendor/<library>/";
            continue;
        }
        $scripts = [];
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$vendorDir/$entry", FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if (strtolower($file->getExtension()) === 'js') {
                $scripts[] = $file->getPathname();
            }
        }
        if ($scripts === []) {
            continue;
        }
        $licenses = array_filter(
            scandir("$vendorDir/$entry") ?: [],
            static fn (string $f): bool => preg_match('/^(LICEN[CS]E|COPYING)([.-][\w.-]+)?$/i', $f) === 1
                && is_file("$vendorDir/$entry/$f") && trim((string) file_get_contents("$vendorDir/$entry/$f")) !== ''
        );
        if ($licenses === []) {
            $found[] = "views/js/vendor/$entry/:1 TST-27 third-party library without its license: add the library's LICENSE file to views/js/vendor/$entry/ (only unmodified third-party code is exempt from tests)";
        }
    }

    return $found;
}

/**
 * The PHP files that need tests: src/, controllers/, classes/ (not index.php; the main file only holds the
 * constructor and trait uses, which the install tests run).
 *
 * @return string[] paths relative to the module
 */
function testSubjectFiles(string $moduleDir): array
{
    $files = [];
    foreach (['src', 'controllers', 'classes'] as $dir) {
        if (!is_dir("$moduleDir/$dir")) {
            continue;
        }
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$moduleDir/$dir", FilesystemIterator::SKIP_DOTS)) as $file) {
            $path = str_replace('\\', '/', $file->getPathname());
            if (str_ends_with($path, '.php') && basename($path) !== 'index.php') {
                $files[] = substr($path, strlen($moduleDir) + 1);
            }
        }
    }
    sort($files);

    return $files;
}

/**
 * The test files that may test a source file (unit and/or integration).
 *
 * @return string[]
 */
function testFilesFor(string $relative): array
{
    if (str_starts_with($relative, 'src/')) {
        $base = substr($relative, 4, -4) . 'Test.php';

        return ["tests/Unit/$base", "tests/Integration/$base"];
    }
    if (preg_match('#^controllers/(front|admin)/(.+)\.php$#', $relative, $m)) {
        return ['tests/Integration/Controllers/' . ucfirst($m[1]) . '/' . str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $m[2]))) . 'Test.php'];
    }

    return ['tests/Integration/Classes/' . substr(basename($relative), 0, -4) . 'Test.php'];
}

/**
 * True when the test text names the method: a call, a string (a hook name for hookX), or a test method name.
 */
function testMentionsMethod(string $text, string $method): bool
{
    $q = preg_quote($method, '/');
    if (preg_match('/(->|::|\?->)\s*' . $q . '\s*\(|[\'"]' . $q . '[\'"]|function\s+test\w*' . preg_quote(ucfirst($method), '/') . '/i', $text)) {
        return true;
    }
    if (preg_match('/^hook([A-Z]\w*)$/', $method, $m)) {
        return (bool) preg_match('/[\'"]' . preg_quote(lcfirst($m[1]), '/') . '[\'"]/i', $text);
    }

    return false;
}

/**
 * The class/trait/enum of a file: name, line, testable methods (public/protected, not __construct, not
 * abstract) and the exceptions it throws; null for interfaces or files without a declaration.
 *
 * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
 *
 * @return array{name: string, line: int, trait: bool, methods: array<int, array{0: string, 1: int}>, throws: array<int, array{0: string, 1: int}>}|null
 */
function testSubject(array $tokens): ?array
{
    $n = count($tokens);
    $subject = null;
    for ($i = 0; $i < $n; ++$i) {
        $t = $tokens[$i];
        if (!is_array($t)) {
            continue;
        }
        if ($t[0] === T_INTERFACE) {
            return null;
        }
        if (in_array($t[0], [T_CLASS, T_TRAIT, T_ENUM], true)) {
            $prev = testPrev($tokens, $i);
            if ($prev !== null && is_array($tokens[$prev]) && in_array($tokens[$prev][0], [T_DOUBLE_COLON, T_NEW], true)) {
                continue; // Foo::class or new class
            }
            $j = testNext($tokens, $i);
            $subject = ['name' => $j !== null && is_array($tokens[$j]) ? $tokens[$j][1] : '', 'line' => $t[2], 'trait' => $t[0] === T_TRAIT, 'methods' => [], 'throws' => []];
            break;
        }
    }
    if ($subject === null) {
        return null;
    }
    $modifiers = [];
    for (; $i < $n; ++$i) {
        $t = $tokens[$i];
        if (!is_array($t)) {
            if ($t === ';' || $t === '{' || $t === '}') {
                $modifiers = [];
            }
            continue;
        }
        if (in_array($t[0], [T_PUBLIC, T_PROTECTED, T_PRIVATE, T_STATIC, T_ABSTRACT, T_FINAL], true)) {
            $modifiers[] = $t[0];
            continue;
        }
        if ($t[0] === T_FUNCTION) {
            $j = testNext($tokens, $i);
            // A closure or arrow function has no name; "fn"/"function (" is skipped.
            if ($j !== null && is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
                $method = $tokens[$j][1];
                $visible = !in_array(T_PRIVATE, $modifiers, true);
                if ($visible && $method !== '__construct' && !in_array(T_ABSTRACT, $modifiers, true)) {
                    $subject['methods'][] = [$method, $t[2]];
                }
            }
            $modifiers = [];
            continue;
        }
        if ($t[0] === T_THROW) {
            $j = testNext($tokens, $i);
            if ($j !== null && is_array($tokens[$j]) && $tokens[$j][0] === T_NEW) {
                $j = testNext($tokens, $j);
            }
            if ($j !== null && is_array($tokens[$j]) && in_array($tokens[$j][0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                $short = substr($tokens[$j][1], (int) strrpos('\\' . $tokens[$j][1], '\\'));
                if (!in_array(strtolower($short), ['self', 'static', 'parent'], true)) {
                    $subject['throws'][$short] ??= [$short, $t[2]];
                } else {
                    $subject['throws'][$subject['name']] ??= [$subject['name'], $t[2]];
                }
            }
            continue;
        }
        if (!in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_ATTRIBUTE], true)) {
            $modifiers = [];
        }
    }
    $subject['throws'] = array_values($subject['throws']);

    return $subject;
}

/**
 * All text of the PHP test files in a directory (recursive).
 */
function testAllText(string $dir): string
{
    if (!is_dir($dir)) {
        return '';
    }
    $text = '';
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $file) {
        if (str_ends_with($file->getFilename(), '.php')) {
            $text .= "\n" . (string) file_get_contents($file->getPathname());
        }
    }

    return $text;
}

/**
 * Index of the next token after $i that is not whitespace or a comment.
 *
 * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
 */
function testNext(array $tokens, int $i): ?int
{
    for ($j = $i + 1, $n = count($tokens); $j < $n; ++$j) {
        if (!is_array($tokens[$j]) || !in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            return $j;
        }
    }

    return null;
}

/**
 * Index of the previous token before $i that is not whitespace or a comment.
 *
 * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
 */
function testPrev(array $tokens, int $i): ?int
{
    for ($j = $i - 1; $j >= 0; --$j) {
        if (!is_array($tokens[$j]) || !in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            return $j;
        }
    }

    return null;
}
