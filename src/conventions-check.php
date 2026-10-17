<?php
/**
 * Checks two house rules that no standard tool covers:
 *
 *   DOC-01  Every class, interface, trait and enum, and every function and method,
 *           has a docblock with a description (not only @tags).
 *   MOD-01  The main module file contains a clean class: only trait `use` lines and
 *           the constructor. Everything else lives in traits under src/Module/.
 *
 * Usage: php conventions-check.php <module-dir> [--with-tests]
 *   Skips vendor/, node_modules/, .cache/, index.php files and (unless --with-tests) tests/.
 *
 * Exit code: 0 = ok, 1 = violations found, 2 = usage error.
 */

declare(strict_types=1);

$args = array_slice($argv, 1);
$withTests = in_array('--with-tests', $args, true);
$args = array_values(array_filter($args, static fn (string $a): bool => !str_starts_with($a, '--')));
$moduleDir = rtrim(str_replace('\\', '/', (string) realpath($args[0] ?? '')), '/');
if ($moduleDir === '' || !is_dir($moduleDir)) {
    fwrite(STDERR, "Usage: php conventions-check.php <module-dir> [--with-tests]\n");
    exit(2);
}
$name = basename($moduleDir);
$skip = ['vendor', 'node_modules', '.cache', 'dist', '.git'];
if (!$withTests) {
    $skip[] = 'tests';
}

$violations = [];
$it = new RecursiveIteratorIterator(
    new RecursiveCallbackFilterIterator(
        new RecursiveDirectoryIterator($moduleDir, FilesystemIterator::SKIP_DOTS),
        static fn (SplFileInfo $f): bool => !($f->isDir() && in_array($f->getFilename(), $skip, true))
    )
);
$files = [];
foreach ($it as $file) {
    $path = str_replace('\\', '/', $file->getPathname());
    if (str_ends_with($path, '.php') && basename($path) !== 'index.php' && !str_starts_with(basename($path), '.php-cs-fixer')) {
        $files[] = $path;
    }
}
sort($files);

foreach ($files as $path) {
    $relative = substr($path, strlen($moduleDir) + 1);
    $code = (string) file_get_contents($path);
    try {
        $tokens = token_get_all($code, TOKEN_PARSE);
    } catch (Throwable $e) {
        $violations[] = "$relative: parse error: " . $e->getMessage();
        continue;
    }
    foreach (checkDocblocks($tokens) as [$line, $message]) {
        $violations[] = "$relative:$line DOC-01 $message";
    }
    if ($relative === "$name.php") {
        foreach (checkMainClass($tokens) as [$line, $message]) {
            $violations[] = "$relative:$line MOD-01 $message";
        }
    }
}

if ($violations === []) {
    echo "Conventions check OK (" . count($files) . " files: docblocks, clean main class).\n";
    exit(0);
}
echo "Conventions check found " . count($violations) . " problem(s):\n  " . implode("\n  ", $violations) . "\n";
exit(1);

// ---------------------------------------------------------------------------

/**
 * Walks the tokens and reports declarations without a describing docblock.
 *
 * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
 *
 * @return array<int, array{0: int, 1: string}> [line, message]
 */
function checkDocblocks(array $tokens): array
{
    $problems = [];
    $doc = null;
    $count = count($tokens);
    $skipThrough = [T_WHITESPACE, T_PUBLIC, T_PROTECTED, T_PRIVATE, T_STATIC, T_ABSTRACT, T_FINAL, T_READONLY];

    for ($i = 0; $i < $count; ++$i) {
        $t = $tokens[$i];
        $id = is_array($t) ? $t[0] : null;

        if ($id === T_DOC_COMMENT) {
            $doc = $t[1];
            continue;
        }
        if ($id !== null && in_array($id, $skipThrough, true)) {
            continue;
        }
        if ($id === T_ATTRIBUTE) {
            $i = skipAttribute($tokens, $i);
            continue;
        }

        $kind = null;
        $label = '';
        if (in_array($id, [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)) {
            $prev = previousSignificant($tokens, $i);
            $isAnonymousOrConstant = $prev !== null && is_array($prev) && in_array($prev[0], [T_NEW, T_DOUBLE_COLON], true);
            $next = nextSignificant($tokens, $i);
            if (!$isAnonymousOrConstant && is_array($next) && $next[0] === T_STRING) {
                $kind = strtolower(substr(token_name($id), 2));
                $label = $next[1];
            }
        } elseif ($id === T_FUNCTION) {
            $next = nextSignificant($tokens, $i);
            if ($next === '&') {
                $next = nextSignificant($tokens, nextSignificantIndex($tokens, $i));
            }
            if (is_array($next) && in_array($next[0], [T_STRING], true)) {
                $kind = 'function';
                $label = $next[1] . '()';
            }
        }

        if ($kind !== null) {
            if ($doc === null) {
                $problems[] = [$t[2], "$kind $label has no docblock."];
            } elseif (!hasDescription($doc)) {
                $problems[] = [$t[2], "docblock of $kind $label has no description (only tags)."];
            }
        }
        $doc = null;
    }

    return $problems;
}

/**
 * Checks that the main module class has nothing but trait uses and __construct().
 *
 * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
 *
 * @return array<int, array{0: int, 1: string}>
 */
function checkMainClass(array $tokens): array
{
    $problems = [];
    $count = count($tokens);
    $classStart = null;
    for ($i = 0; $i < $count; ++$i) {
        if (is_array($tokens[$i]) && $tokens[$i][0] === T_CLASS) {
            $prev = previousSignificant($tokens, $i);
            if (!(is_array($prev) && in_array($prev[0], [T_NEW, T_DOUBLE_COLON], true))) {
                $classStart = $i;
                break;
            }
        }
    }
    if ($classStart === null) {
        return [[1, 'no class found in the main module file.']];
    }

    $depth = 0;
    $inClass = false;
    for ($i = $classStart; $i < $count; ++$i) {
        $t = $tokens[$i];
        if ($t === '{' || (is_array($t) && in_array($t[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
            ++$depth;
            $inClass = true;
            continue;
        }
        if ($t === '}') {
            --$depth;
            if ($inClass && $depth === 0) {
                break;
            }
            continue;
        }
        if ($depth !== 1 || !is_array($t)) {
            continue;
        }
        switch ($t[0]) {
            case T_FUNCTION:
                $next = nextSignificant($tokens, $i);
                $method = is_array($next) ? $next[1] : '?';
                if (strtolower($method) !== '__construct') {
                    $problems[] = [$t[2], "method $method() belongs in a trait (src/Module/Helper or src/Module/Hook), not in the main class."];
                }
                break;
            case T_VARIABLE:
                $problems[] = [$t[2], "property {$t[1]} belongs in a trait, not in the main class."];
                break;
            case T_CONST:
                $problems[] = [$t[2], 'constants belong in ModuleDefinition or a trait, not in the main class.'];
                break;
        }
    }

    return $problems;
}

/** True when the docblock has at least one line of text that is not a tag. */
function hasDescription(string $doc): bool
{
    foreach (preg_split('/\R/', $doc) ?: [] as $line) {
        $line = trim(preg_replace('#^\s*(/\*\*|\*/|\*)#', '', $line) ?? '');
        $line = trim(str_replace('*/', '', $line));
        if ($line !== '' && $line[0] !== '@') {
            return true;
        }
    }

    return false;
}

/**
 * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
 */
function skipAttribute(array $tokens, int $i): int
{
    $depth = 0;
    for ($count = count($tokens); $i < $count; ++$i) {
        $t = $tokens[$i];
        if ((is_array($t) && $t[0] === T_ATTRIBUTE) || $t === '[') {
            ++$depth;
        } elseif ($t === ']') {
            --$depth;
            if ($depth === 0) {
                return $i;
            }
        }
    }

    return $i;
}

/**
 * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
 *
 * @return array{0: int, 1: string, 2: int}|string|null
 */
function nextSignificant(array $tokens, int $i): array|string|null
{
    $j = nextSignificantIndex($tokens, $i);

    return $j === null ? null : $tokens[$j];
}

/**
 * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
 */
function nextSignificantIndex(array $tokens, int $i): ?int
{
    for ($j = $i + 1, $count = count($tokens); $j < $count; ++$j) {
        if (!(is_array($tokens[$j]) && in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true))) {
            return $j;
        }
    }

    return null;
}

/**
 * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
 *
 * @return array{0: int, 1: string, 2: int}|string|null
 */
function previousSignificant(array $tokens, int $i): array|string|null
{
    for ($j = $i - 1; $j >= 0; --$j) {
        if (!(is_array($tokens[$j]) && in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true))) {
            return $tokens[$j];
        }
    }

    return null;
}
