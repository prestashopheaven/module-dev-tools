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
 * Architecture check of a module (conventions ARCH-01..15, DDD + CQRS), used by conventions-check.php:
 *
 *   src/Domain/<Context>/...        pure domain: only own Domain classes and PHP built-ins
 *   src/Application/<Context>/...   Command/<Name>/, Query/<Name>/, Port/, Service/: Domain, Application, Psr
 *   src/Infrastructure/, src/Entity/, src/Model/, src/Install/   implementations: not the presentation
 *   presentation: src/Module, Controller, Form, Grid, Presenter, Console, Webservice, ApiPlatform,
 *                 controllers/, classes/, the main file: dispatch through the bus, no data access
 *
 * Plus the shape of commands, queries, results and handlers, their registration in config/*.yml and
 * (with tests/Unit) a unit test per handler.
 */

declare(strict_types=1);

const ARCH_PRESENTATION_DIRS = ['Module', 'Controller', 'Form', 'Grid', 'Presenter', 'Console', 'Webservice', 'ApiPlatform'];
const ARCH_INFRASTRUCTURE_DIRS = ['Infrastructure', 'Entity', 'Model', 'Install'];
const ARCH_DOMAIN_SUBDIRS = ['Model', 'ValueObject', 'Exception', 'Repository', 'Service', 'Event', 'Specification'];
const ARCH_APPLICATION_SUBDIRS = ['Command', 'Query', 'Port', 'Service'];
const ARCH_QUERY_WRITE_CALLS = '/^(save|add|update|delete|remove|persist|insert|create|store|flush|set|write|clear)/i';

/**
 * Runs all architecture checks; returns violations as "path:line ARCH-xx message".
 *
 * @param string[] $files absolute paths of the PHP files to check (without vendor/ and index.php)
 *
 * @return string[]
 */
function checkArchitecture(string $moduleDir, array $files): array
{
    $ns = archNamespace($moduleDir);
    // Only PrestaShop modules (main file <module>/<module>.php); not the shared libraries.
    if (!is_dir("$moduleDir/src") || !is_file("$moduleDir/" . basename($moduleDir) . '.php')) {
        return [];
    }
    if ($ns === '') {
        return ['composer.json ARCH-01 no PSR-4 autoload entry for "src/": the architecture check needs the module namespace'];
    }
    $name = basename($moduleDir);
    $violations = [];
    $messages = [];
    foreach ($files as $path) {
        $relative = substr($path, strlen($moduleDir) + 1);
        $layer = archLayer($relative, $name);
        if ($layer === null) {
            continue;
        }
        if ($layer === 'unknown') {
            $violations[] = "$relative:1 ARCH-01 not in a layer: put it in src/Domain/<Context>/, src/Application/<Context>/, src/Infrastructure/ or a presentation folder (src/" . implode(', src/', ARCH_PRESENTATION_DIRS) . ')';
            continue;
        }
        $tokens = token_get_all((string) file_get_contents($path));
        foreach (archCheckStructure($relative) as $message) {
            $violations[] = "$relative:1 $message";
        }
        foreach (archCheckReferences($tokens, $layer, $relative, $ns, strtoupper($name)) as [$line, $message]) {
            $violations[] = "$relative:$line $message";
        }
        if (preg_match('#^src/Application/[^/]+/(Command|Query)/([^/]+)/([^/]+)\.php$#', $relative, $m)) {
            $messages[] = ['relative' => $relative, 'kind' => $m[1], 'name' => $m[2], 'file' => $m[3]];
            foreach (archCheckMessageClass($tokens, $m[1], $m[2], $m[3]) as [$line, $message]) {
                $violations[] = "$relative:$line $message";
            }
        }
    }

    return array_merge($violations, archCheckCqrsSet($moduleDir, $ns, $messages));
}

/**
 * The PSR-4 namespace of src/ from composer.json ('' when there is none).
 */
function archNamespace(string $moduleDir): string
{
    $composer = json_decode((string) @file_get_contents("$moduleDir/composer.json"), true);
    foreach ((array) ($composer['autoload']['psr-4'] ?? []) as $prefix => $paths) {
        foreach ((array) $paths as $path) {
            if (rtrim((string) $path, '/') === 'src') {
                return rtrim((string) $prefix, '\\');
            }
        }
    }

    return '';
}

/**
 * The layer of a file: domain, application, infrastructure, presentation, 'unknown' (src/ outside the
 * layers) or null (not checked: tests, upgrade scripts, ...).
 */
function archLayer(string $relative, string $moduleName): ?string
{
    if ($relative === "$moduleName.php" || str_starts_with($relative, 'controllers/') || str_starts_with($relative, 'classes/')) {
        return 'presentation';
    }
    if (!str_starts_with($relative, 'src/')) {
        return null;
    }
    $top = explode('/', substr($relative, 4))[0];

    return match (true) {
        $top === 'Domain' => 'domain',
        $top === 'Application' => 'application',
        in_array($top, ARCH_INFRASTRUCTURE_DIRS, true) => 'infrastructure',
        in_array($top, ARCH_PRESENTATION_DIRS, true) => 'presentation',
        default => 'unknown',
    };
}

/**
 * Folder rules of Domain and Application (bounded contexts, fixed subfolders, one folder per message).
 *
 * @return string[]
 */
function archCheckStructure(string $relative): array
{
    $parts = explode('/', $relative);
    if ($parts[0] !== 'src' || count($parts) < 3) {
        return [];
    }
    if ($parts[1] === 'Domain') {
        if (count($parts) < 5) {
            return ['ARCH-02 a domain class belongs in src/Domain/<Context>/<' . implode('|', ARCH_DOMAIN_SUBDIRS) . '>/'];
        }
        if (!in_array($parts[3], ARCH_DOMAIN_SUBDIRS, true)) {
            return ["ARCH-02 src/Domain/{$parts[2]}/{$parts[3]}/ is not a domain folder (" . implode(', ', ARCH_DOMAIN_SUBDIRS) . ')'];
        }
        if (!preg_match('/^[A-Z][A-Za-z0-9]+$/', $parts[2])) {
            return ["ARCH-02 bounded context '{$parts[2]}' must be PascalCase"];
        }
    }
    if ($parts[1] === 'Application') {
        if (count($parts) < 5 || !in_array($parts[3], ARCH_APPLICATION_SUBDIRS, true)) {
            return ['ARCH-02 an application class belongs in src/Application/<Context>/<' . implode('|', ARCH_APPLICATION_SUBDIRS) . '>/'];
        }
        if (in_array($parts[3], ['Command', 'Query'], true) && count($parts) !== 6) {
            return ["ARCH-05 a {$parts[3]} lives in its own folder: src/Application/{$parts[2]}/{$parts[3]}/<Name>/<Name>{$parts[3]}.php, <Name>Handler.php, <Name>Result.php"];
        }
        if (in_array($parts[3], ['Command', 'Query'], true) && !str_starts_with(basename($relative, '.php'), $parts[4])) {
            return ["ARCH-05 only <Name>{$parts[3]}, <Name>Handler and <Name>Result* files in the folder {$parts[4]}/"];
        }
    }

    return [];
}

/**
 * Checks what a file uses against the dependency rules of its layer (ARCH-03, -04, -09, -10).
 *
 * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
 *
 * @return array<int, array{0: int, 1: string}>
 */
function archCheckReferences(array $tokens, string $layer, string $relative, string $ns, string $configPrefix): array
{
    $found = [];
    foreach (archReferences($tokens) as [$line, $kind, $fqn, $index]) {
        $message = archRuleFor($layer, $kind, $fqn, $ns, $relative);
        if ($message === '' && $layer === 'presentation' && $kind === 'class') {
            $message = archPresentationCall($tokens, $index, $fqn, $configPrefix);
        }
        if ($message !== '') {
            $found[$line . $message] = [$line, $message];
        }
    }

    return array_values($found);
}

/**
 * The violation of one reference ('' when allowed).
 */
function archRuleFor(string $layer, string $kind, string $fqn, string $ns, string $relative): string
{
    $own = str_starts_with($fqn, $ns . '\\') ? substr($fqn, strlen($ns) + 1) : null;
    $global = !str_contains($fqn, '\\');
    if ($layer === 'domain' || $layer === 'application') {
        $rule = $layer === 'domain' ? 'ARCH-03 the domain' : 'ARCH-04 the application layer';
        if ($kind === 'constant') {
            return "$rule may not use PrestaShop constant $fqn";
        }
        if ($global) {
            return archIsBuiltin($fqn, $kind) ? '' : "$rule may not use \\$fqn (PrestaShop or another library): put it behind a " . ($layer === 'domain' ? 'repository interface in Domain/<Context>/Repository' : 'port in Application/<Context>/Port') . ', implemented in Infrastructure';
        }
        if ($own !== null) {
            $allowed = $layer === 'domain' ? ['Domain\\'] : ['Domain\\', 'Application\\'];
            foreach ($allowed as $prefix) {
                if (str_starts_with($own, $prefix)) {
                    return '';
                }
            }

            return "$rule may not use $fqn (" . ($layer === 'domain' ? 'only Domain' : 'only Domain and Application') . ')';
        }
        if ($layer === 'application' && str_starts_with($fqn, 'Psr\\')) {
            return '';
        }

        return "$rule may not use $fqn: put it behind an interface implemented in Infrastructure";
    }
    if ($own === null) {
        return '';
    }
    $top = explode('\\', $own)[0];
    if ($layer === 'infrastructure') {
        return in_array($top, ARCH_PRESENTATION_DIRS, true) ? "ARCH-10 infrastructure may not use the presentation ($fqn)" : '';
    }
    // Presentation: dispatch commands and queries through the bus; no repositories, ports or persistence.
    if (in_array($top, ['Entity', 'Model'], true)) {
        return "ARCH-09 the presentation may not use the persistence model $fqn: dispatch a command or query";
    }
    if ($top === 'Infrastructure' && !str_starts_with($own, 'Infrastructure\\Bus\\') && $own !== 'Infrastructure\\PrestaShop\\ShopConstraintMapper') {
        return "ARCH-09 the presentation may not use $fqn (only Infrastructure\\Bus and ShopConstraintMapper): dispatch a command or query";
    }
    if (preg_match('#^Domain\\\\[^\\\\]+\\\\(Repository|Model|Service)\\\\#', $own, $m)) {
        return "ARCH-09 the presentation may not use domain {$m[1]} $fqn: dispatch a command or query";
    }
    // Command/query handlers live in Application; a hook trait like ActionAfterUpdateProductFormHandler is not one.
    if (preg_match('#^Application\\\\[^\\\\]+\\\\(Port|Service)\\\\#', $own) || preg_match('#^Application\\\\.*Handler$#', $own)) {
        return "ARCH-09 the presentation may not call $fqn directly: dispatch through the bus";
    }

    return '';
}

/**
 * Data access in the presentation (ARCH-09): \Db, \DbQuery, writing Configuration, reading module settings.
 *
 * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
 */
function archPresentationCall(array $tokens, int $index, string $fqn, string $configPrefix): string
{
    if ($fqn === 'Db' || $fqn === 'DbQuery') {
        return "ARCH-09 the presentation may not use \\$fqn: read with a query, write with a command";
    }
    if ($fqn !== 'Configuration') {
        return '';
    }
    $j = archNextIndex($tokens, $index);
    if ($j === null || !is_array($tokens[$j]) || $tokens[$j][0] !== T_DOUBLE_COLON) {
        return '';
    }
    $k = archNextIndex($tokens, $j);
    $method = $k !== null && is_array($tokens[$k]) ? $tokens[$k][1] : '';
    if (preg_match('/^(update|delete|set)/i', $method)) {
        return "ARCH-09 the presentation may not write Configuration ($method): dispatch a command";
    }
    if (preg_match('/^get/i', $method)) {
        // The arguments: a module key (ModuleDefinition::CONFIG_* or '<MODULE>_...') must be read with a query.
        $depth = 0;
        for ($i = (int) $k + 1, $n = count($tokens); $i < $n; ++$i) {
            $t = $tokens[$i];
            if ($t === '(') {
                ++$depth;
            } elseif ($t === ')') {
                if (--$depth === 0) {
                    break;
                }
            } elseif (is_array($t) && ($t[1] === 'ModuleDefinition' || ($t[0] === T_CONSTANT_ENCAPSED_STRING && str_starts_with(trim($t[1], '\'"'), $configPrefix . '_')))) {
                return 'ARCH-09 the presentation may not read module settings from Configuration: dispatch a query';
            }
        }
    }

    return '';
}

/**
 * True for classes, interfaces, traits, enums and functions of PHP itself (or a loaded extension).
 */
function archIsBuiltin(string $name, string $kind): bool
{
    if ($kind === 'function') {
        return function_exists($name) && (new ReflectionFunction($name))->isInternal();
    }
    foreach (['class_exists', 'interface_exists', 'trait_exists', 'enum_exists'] as $exists) {
        if ($exists($name, false)) {
            return (new ReflectionClass($name))->isInternal();
        }
    }

    return false;
}

/**
 * All references of a file: [line, kind (class|function|constant), fully qualified name, token index].
 * Covers use imports, fully qualified names, X:: / new X / instanceof X / extends / implements, and
 * PrestaShop constants (_DB_PREFIX_ etc., not _PS_VERSION_ of the guard).
 *
 * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
 *
 * @return array<int, array{0: int, 1: string, 2: string, 3: int}>
 */
function archReferences(array $tokens): array
{
    $refs = [];
    $namespace = '';
    $imports = [];
    $depth = 0;
    $n = count($tokens);
    for ($i = 0; $i < $n; ++$i) {
        $t = $tokens[$i];
        if ($t === '{' || (is_array($t) && in_array($t[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
            ++$depth;
            continue;
        }
        if ($t === '}') {
            --$depth;
            continue;
        }
        if (!is_array($t)) {
            continue;
        }
        if ($t[0] === T_NAMESPACE) {
            $j = archNextIndex($tokens, $i);
            $namespace = $j !== null && is_array($tokens[$j]) && in_array($tokens[$j][0], [T_NAME_QUALIFIED, T_STRING], true) ? $tokens[$j][1] : '';
            continue;
        }
        if ($t[0] === T_USE && $depth === 0) {
            // Namespace import: use A\B [as C][, ...]; (not "use function"/"use const").
            $j = archNextIndex($tokens, $i);
            if ($j !== null && is_array($tokens[$j]) && in_array($tokens[$j][0], [T_FUNCTION, T_CONST], true)) {
                continue;
            }
            for (; $j !== null && $tokens[$j] !== ';'; $j = archNextIndex($tokens, $j)) {
                $u = $tokens[$j];
                if (is_array($u) && in_array($u[0], [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_STRING], true)) {
                    $fqn = ltrim($u[1], '\\');
                    $k = archNextIndex($tokens, $j);
                    $alias = substr($fqn, (int) strrpos('\\' . $fqn, '\\'));
                    if ($k !== null && is_array($tokens[$k]) && $tokens[$k][0] === T_AS) {
                        $a = archNextIndex($tokens, $k);
                        $alias = $a !== null && is_array($tokens[$a]) ? $tokens[$a][1] : $alias;
                        $j = (int) $a;
                    }
                    $imports[strtolower($alias)] = $fqn;
                    $refs[] = [$u[2], 'class', $fqn, $j];
                }
            }
            $i = (int) $j;
            continue;
        }
        if ($t[0] === T_STRING && preg_match('/^_[A-Z0-9_]+_$/', $t[1]) && $t[1] !== '_PS_VERSION_') {
            $prev = archPrevIndex($tokens, $i);
            $next = archNextIndex($tokens, $i);
            $isMember = $prev !== null && is_array($tokens[$prev]) && in_array($tokens[$prev][0], [T_DOUBLE_COLON, T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_CONST], true);
            if (!$isMember && ($next === null || $tokens[$next] !== '(')) {
                $refs[] = [$t[2], 'constant', $t[1], $i];
            }
            continue;
        }
        if (!in_array($t[0], [T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED, T_STRING], true)) {
            continue;
        }
        $prev = archPrevIndex($tokens, $i);
        $next = archNextIndex($tokens, $i);
        $prevToken = $prev !== null && is_array($tokens[$prev]) ? $tokens[$prev][0] : null;
        $isClassPosition = ($next !== null && is_array($tokens[$next]) && $tokens[$next][0] === T_DOUBLE_COLON)
            || in_array($prevToken, [T_NEW, T_INSTANCEOF, T_EXTENDS, T_IMPLEMENTS], true)
            || ($prevToken === null ? false : ($tokens[$prev] === ',' && archInImplements($tokens, $i)));
        $isFunction = $t[0] !== T_STRING && $next !== null && $tokens[$next] === '(' && $prevToken !== T_NEW;
        if ($t[0] === T_STRING && !$isClassPosition) {
            continue;
        }
        if (in_array(strtolower($t[1]), ['self', 'static', 'parent'], true)) {
            continue;
        }
        if ($prevToken !== null && in_array($prevToken, [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_CONST], true)) {
            continue;
        }
        $refs[] = [$t[2], $isFunction ? 'function' : 'class', archResolve($t, $namespace, $imports, $isFunction), $i];
    }

    return $refs;
}

/**
 * Resolves a name token to a fully qualified name with the imports and the current namespace.
 *
 * @param array{0: int, 1: string, 2: int} $token
 * @param array<string, string> $imports alias (lowercase) => fqn
 */
function archResolve(array $token, string $namespace, array $imports, bool $isFunction): string
{
    $name = $token[1];
    if ($token[0] === T_NAME_FULLY_QUALIFIED) {
        return ltrim($name, '\\');
    }
    $first = strtolower(explode('\\', $name)[0]);
    if (isset($imports[$first])) {
        return $imports[$first] . substr($name, strlen($first));
    }
    if ($isFunction && !str_contains($name, '\\')) {
        return $name; // PHP falls back to the global function
    }

    return $namespace === '' ? $name : "$namespace\\$name";
}

/**
 * True when the name at $i is part of an "implements A, B" list.
 *
 * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
 */
function archInImplements(array $tokens, int $i): bool
{
    for ($j = $i - 1; $j >= 0; --$j) {
        $t = $tokens[$j];
        if ($t === '{' || $t === ';' || $t === '(' || $t === ')') {
            return false;
        }
        if (is_array($t) && $t[0] === T_IMPLEMENTS) {
            return true;
        }
    }

    return false;
}

/**
 * Shape of a command, query, result or handler class (ARCH-05, -06, -07).
 *
 * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
 *
 * @return array<int, array{0: int, 1: string}>
 */
function archCheckMessageClass(array $tokens, string $kind, string $name, string $file): array
{
    $class = archClassInfo($tokens);
    if ($class === null) {
        return [];
    }
    $found = [];
    if (!$class['final']) {
        $found[] = [$class['line'], "ARCH-06 $file must be final"];
    }
    $isHandler = $file === "{$name}Handler";
    if (!$isHandler) {
        foreach ($class['properties'] as [$line, $prop, $readonly]) {
            if (!$readonly) {
                $found[] = [$line, "ARCH-06 $file is immutable: make \$$prop readonly"];
            }
        }
        foreach ($class['methods'] as $method) {
            if (preg_match('/^set[A-Z_]/', $method['name'])) {
                $found[] = [$method['line'], "ARCH-06 $file is immutable: no setter {$method['name']}()"];
            }
        }

        return $found;
    }
    $public = array_values(array_filter($class['methods'], static fn (array $m): bool => $m['public'] && $m['name'] !== '__construct'));
    $handle = array_values(array_filter($public, static fn (array $m): bool => $m['name'] === 'handle'));
    if (count($public) !== 1 || $handle === []) {
        $found[] = [$class['line'], "ARCH-07 $file has exactly one public method: handle({$name}{$kind} \$" . lcfirst($kind) . ') (besides the constructor)'];
    }
    if ($handle !== []) {
        $m = $handle[0];
        if ($m['params'] !== ["{$name}{$kind}"]) {
            $found[] = [$m['line'], "ARCH-07 $file::handle() takes exactly one parameter of type {$name}{$kind}"];
        }
        $return = ltrim($m['return'], '?');
        if ($kind === 'Command' && !in_array($return, ['void', "{$name}Result"], true)) {
            $found[] = [$m['line'], "ARCH-05 a command handler returns void or {$name}Result (a new id or a small outcome), not '{$m['return']}'"];
        }
        if ($kind === 'Query' && in_array($return, ['', 'void', 'never'], true)) {
            $found[] = [$m['line'], "ARCH-05 a query handler returns its result (a read model, e.g. {$name}Result)"];
        }
    }
    if ($kind === 'Query') {
        foreach ($tokens as $i => $t) {
            if (is_array($t) && in_array($t[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)) {
                $j = archNextIndex($tokens, $i);
                $k = $j !== null ? archNextIndex($tokens, $j) : null;
                if ($j !== null && is_array($tokens[$j]) && $tokens[$j][0] === T_STRING && $k !== null && $tokens[$k] === '(' && preg_match(ARCH_QUERY_WRITE_CALLS, $tokens[$j][1])) {
                    $found[] = [$tokens[$j][2], "ARCH-05 a query handler only reads: no {$tokens[$j][1]}() (writes belong in a command)"];
                }
            }
        }
    }

    return $found;
}

/**
 * The first class of a file: final, line, properties (with readonly) and methods (public, params, return).
 *
 * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
 *
 * @return array{final: bool, line: int, properties: array<int, array{0: int, 1: string, 2: bool}>, methods: array<int, array{name: string, line: int, public: bool, params: string[], return: string}>}|null
 */
function archClassInfo(array $tokens): ?array
{
    $n = count($tokens);
    for ($i = 0; $i < $n; ++$i) {
        if (is_array($tokens[$i]) && $tokens[$i][0] === T_CLASS) {
            break;
        }
    }
    if ($i >= $n) {
        return null;
    }
    $prev = archPrevIndex($tokens, $i);
    $info = ['final' => false, 'line' => $tokens[$i][2], 'properties' => [], 'methods' => []];
    while ($prev !== null && is_array($tokens[$prev]) && in_array($tokens[$prev][0], [T_FINAL, T_ABSTRACT, T_READONLY], true)) {
        $info['final'] = $info['final'] || $tokens[$prev][0] === T_FINAL;
        $prev = archPrevIndex($tokens, $prev);
    }
    while ($i < $n && $tokens[$i] !== '{') {
        ++$i;
    }
    $depth = 0;
    $modifiers = [];
    for (; $i < $n; ++$i) {
        $t = $tokens[$i];
        if ($t === '{' || (is_array($t) && in_array($t[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
            ++$depth;
            continue;
        }
        if ($t === '}') {
            if (--$depth === 0) {
                break;
            }
            continue;
        }
        if ($depth !== 1 || !is_array($t)) {
            if ($depth === 1 && $t === ';') {
                $modifiers = [];
            }
            continue;
        }
        if (in_array($t[0], [T_PUBLIC, T_PROTECTED, T_PRIVATE, T_STATIC, T_READONLY, T_VAR, T_FINAL, T_ABSTRACT], true)) {
            $modifiers[] = $t[0];
            continue;
        }
        if ($t[0] === T_VARIABLE && $modifiers !== []) {
            $info['properties'][] = [$t[2], substr($t[1], 1), in_array(T_READONLY, $modifiers, true)];
            continue;
        }
        if ($t[0] === T_FUNCTION) {
            $j = archNextIndex($tokens, $i);
            $method = ['name' => $j !== null && is_array($tokens[$j]) ? $tokens[$j][1] : '', 'line' => $t[2], 'public' => !in_array(T_PRIVATE, $modifiers, true) && !in_array(T_PROTECTED, $modifiers, true), 'params' => [], 'return' => ''];
            // Parameter list: types of the parameters, promoted properties.
            while ($i < $n && $tokens[$i] !== '(') {
                ++$i;
            }
            $paren = 0;
            $paramModifiers = [];
            $type = '';
            for (; $i < $n; ++$i) {
                $p = $tokens[$i];
                if ($p === '(') {
                    ++$paren;
                    continue;
                }
                if ($p === ')') {
                    if (--$paren === 0) {
                        break;
                    }
                    continue;
                }
                if ($paren !== 1) {
                    continue;
                }
                if (is_array($p) && in_array($p[0], [T_PUBLIC, T_PROTECTED, T_PRIVATE, T_READONLY], true)) {
                    $paramModifiers[] = $p[0];
                } elseif (is_array($p) && in_array($p[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_ARRAY, T_CALLABLE], true) && $type === '') {
                    $type = substr($p[1], (int) strrpos('\\' . $p[1], '\\'));
                } elseif (is_array($p) && $p[0] === T_VARIABLE) {
                    $method['params'][] = $type;
                    if ($paramModifiers !== []) {
                        $info['properties'][] = [$p[2], substr($p[1], 1), in_array(T_READONLY, $paramModifiers, true)];
                    }
                    $paramModifiers = [];
                    $type = '';
                } elseif ($p === ',') {
                    $paramModifiers = [];
                    $type = '';
                }
            }
            // Return type, then skip the body.
            $return = '';
            for (++$i; $i < $n && $tokens[$i] !== '{' && $tokens[$i] !== ';'; ++$i) {
                if (is_array($tokens[$i]) && !in_array($tokens[$i][0], [T_WHITESPACE, T_COMMENT], true) || $tokens[$i] === '?') {
                    $return .= is_array($tokens[$i]) ? $tokens[$i][1] : $tokens[$i];
                }
            }
            $method['return'] = ltrim($return, ':');
            $info['methods'][] = $method;
            $modifiers = [];
            if ($i < $n && $tokens[$i] === '{') {
                $body = 0;
                for (; $i < $n; ++$i) {
                    if ($tokens[$i] === '{' || (is_array($tokens[$i]) && in_array($tokens[$i][0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                        ++$body;
                    } elseif ($tokens[$i] === '}' && --$body === 0) {
                        break;
                    }
                }
            }
            continue;
        }
        if (!in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_ARRAY, T_NS_SEPARATOR], true)) {
            $modifiers = [];
        }
    }

    return $info;
}

/**
 * Every command and query has its handler and a registration in config/*.yml; every registration
 * points to an existing class; the bus exists; with tests/Unit a unit test per handler (ARCH-07, -08, -14).
 *
 * @param array<int, array{relative: string, kind: string, name: string, file: string}> $messages
 *
 * @return string[]
 */
function archCheckCqrsSet(string $moduleDir, string $ns, array $messages): array
{
    $found = [];
    if (!is_file("$moduleDir/src/Infrastructure/Bus/ModuleCommandBus.php")) {
        $found[] = 'src/Infrastructure/Bus/ModuleCommandBus.php:1 ARCH-08 missing: the command bus of the module (copy it from the ps-module template)';
    }
    $yaml = '';
    foreach (archYamlFiles("$moduleDir/config") as $file) {
        // Without comment lines: the template has commented examples.
        $yaml .= "\n" . (string) preg_replace('/^\s*#.*$/m', '', (string) file_get_contents($file));
    }
    if (!str_contains($yaml, 'ModuleCommandBus')) {
        $found[] = 'config/common.yml:1 ARCH-08 the command bus is not registered (ModuleCommandBus with !tagged_locator)';
    }
    preg_match_all('/\bcommand:\s*[\'"]?([A-Za-z0-9_\\\\]+)[\'"]?/', $yaml, $m);
    $registered = array_map(static fn (string $c): string => ltrim(str_replace('\\\\', '\\', $c), '\\'), $m[1]);
    preg_match_all('/^\s{2}[\'"]?([A-Za-z0-9_\\\\]+Handler)[\'"]?\s*:/m', $yaml, $h);
    $handlers = array_map(static fn (string $c): string => ltrim(str_replace('\\\\', '\\', $c), '\\'), $h[1]);

    $folders = [];
    foreach ($messages as $message) {
        $folders[dirname($message['relative'])][] = $message['file'];
    }
    foreach ($folders as $folder => $classes) {
        $parts = explode('/', $folder);
        [$kind, $name] = [$parts[3], $parts[4]];
        $fqnBase = $ns . '\\' . str_replace('/', '\\', substr($folder, 4)) . '\\' . $name;
        if (!in_array($name . $kind, $classes, true)) {
            $found[] = "$folder/:1 ARCH-05 missing {$name}{$kind}.php";
            continue;
        }
        if (!in_array($name . 'Handler', $classes, true)) {
            $found[] = "$folder/:1 ARCH-07 missing {$name}Handler.php for {$name}{$kind}";
            continue;
        }
        if (!in_array($fqnBase . $kind, $registered, true) || !in_array($fqnBase . 'Handler', $handlers, true)) {
            $found[] = "$folder/{$name}Handler.php:1 ARCH-07 not registered: add {$fqnBase}Handler with the tag { name: '<vendor>.<module>.handler', command: '{$fqnBase}{$kind}' } to config/common.yml";
        }
        if (is_dir("$moduleDir/tests/Unit") && !is_file("$moduleDir/tests/Unit/" . substr($folder, 4) . "/{$name}HandlerTest.php")) {
            $found[] = "$folder/{$name}Handler.php:1 ARCH-14 no unit test: tests/Unit/" . substr($folder, 4) . "/{$name}HandlerTest.php (mocks of the interfaces, no PrestaShop)";
        }
    }
    foreach (array_unique($registered) as $class) {
        if (!str_starts_with($class, $ns . '\\')) {
            continue;
        }
        $file = "$moduleDir/src/" . str_replace('\\', '/', substr($class, strlen($ns) + 1)) . '.php';
        if (!is_file($file)) {
            $found[] = "config:1 ARCH-07 a handler tag points to $class, which does not exist (typo?)";
        }
    }

    return $found;
}

/**
 * The YAML files in config/ (recursive).
 *
 * @return string[]
 */
function archYamlFiles(string $dir): array
{
    if (!is_dir($dir)) {
        return [];
    }
    $files = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $file) {
        if (preg_match('/\.ya?ml$/', $file->getFilename())) {
            $files[] = $file->getPathname();
        }
    }
    sort($files);

    return $files;
}

/**
 * Index of the next token after $i that is not whitespace or a comment.
 *
 * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
 */
function archNextIndex(array $tokens, int $i): ?int
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
function archPrevIndex(array $tokens, int $i): ?int
{
    for ($j = $i - 1; $j >= 0; --$j) {
        if (!is_array($tokens[$j]) || !in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            return $j;
        }
    }

    return null;
}
