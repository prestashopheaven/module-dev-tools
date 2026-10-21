<?php
/**
 * Sends a module to the online PrestaShop validator (validator.prestashop.com)
 * and prints the report.
 *
 * Usage:
 *   php validator-api.php <module-dir>      builds the zip first (build-zip.php: composer --no-dev,
 *                                           dev files stripped, index.php everywhere), then uploads it
 *   php validator-api.php <module.zip>      uploads an existing zip
 *   options: --compat=8,9   PrestaShop major versions to check (default: derived from
 *                           ps_versions_compliancy in the main file, else 9)
 *            --json         print the raw JSON response
 *
 * API key: env PRESTASHOP_VALIDATOR_API_KEY, or a .env file in the module dir or any
 * parent directory (e.g. C:/_GIT/_PrestaShopHeaven/MODULES/.env).
 *
 * The API is not officially documented. Request format: multipart POST /api/modules with
 * fields "archive" (zip), "key" and "compatibility_<major>" = true.
 * The raw response is saved next to the zip as <module>.validator.json. The validator accepts at most 30 MB, so
 * it gets a temporary copy of the zip without the manual PDFs (docs/*.pdf); the zip itself is not changed.
 *
 * Exit code: 0 = no errors reported, 1 = errors reported, 2 = request failed.
 */

declare(strict_types=1);

require_once __DIR__ . '/verbose.php';

/** Largest upload validator.prestashop.com accepts (30 MB; HTTP 413 above it). */
const VALIDATOR_MAX_BYTES = 30 * 1024 * 1024;

$options = [];
$target = '';
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--(compat|json)(?:=(.*))?$/', $arg, $m)) {
        $options[$m[1]] = $m[2] ?? true;
    } else {
        $target = $arg;
    }
}
if ($target === '' || !file_exists($target)) {
    fwrite(STDERR, "Usage: php validator-api.php <module-dir|module.zip> [--compat=8,9] [--json]\n");
    exit(2);
}
$target = str_replace('\\', '/', (string) realpath($target));

if (!extension_loaded('curl')) {
    fwrite(STDERR, "The PHP curl extension is required.\n");
    exit(2);
}

$env = loadEnv(is_dir($target) ? $target : dirname($target));
$apiKey = getenv('PRESTASHOP_VALIDATOR_API_KEY') ?: ($env['PRESTASHOP_VALIDATOR_API_KEY'] ?? '');
$baseUrl = rtrim(getenv('PRESTASHOP_VALIDATOR_URL') ?: ($env['PRESTASHOP_VALIDATOR_URL'] ?? 'https://validator.prestashop.com'), '/');
// Never shown in verbose output.
ps_vsecret((string) $apiKey);
ps_vlog('validator-api: target ' . $target . ', API ' . $baseUrl . ', key ' . ($apiKey === '' ? 'missing' : 'from ' . (getenv('PRESTASHOP_VALIDATOR_API_KEY') ? 'the environment' : 'a .env file')));
if ($apiKey === '') {
    fwrite(STDERR, "No API key. Set PRESTASHOP_VALIDATOR_API_KEY in a .env file (module dir or a parent dir) or as an environment variable.\n");
    exit(2);
}

// 1. Zip
if (is_dir($target)) {
    $buildScript = __DIR__ . '/build-zip.php';
    $output = [];
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($buildScript) . ' ' . escapeshellarg($target);
    ps_vcmd($cmd, (string) getcwd());
    $start = microtime(true);
    exec($cmd, $output, $code);
    ps_vdone('build-zip.php', $start, $code);
    ps_vlog(implode("\n", $output));
    if ($code !== 0) {
        fwrite(STDERR, "Building the zip failed:\n" . implode("\n", $output) . "\n");
        exit(2);
    }
    $zip = trim((string) end($output));
    $moduleDir = $target;
} else {
    $zip = $target;
    $moduleDir = null;
}
fwrite(STDERR, "Zip: $zip\n");

// The validator takes at most 30 MB (nginx answers 413 above that, measured 2026-10-20). The manual PDFs (one per
// language, with screenshots) are no code and make up most of a zip with many languages, so the validator gets a
// temporary copy without them. The release zip itself keeps the PDFs (REL-06).
$upload = uploadCopyWithoutPdfs($zip);
if ($upload === null) {
    exit(2);
}
register_shutdown_function(static function () use ($upload, $zip): void {
    if ($upload !== $zip && is_file($upload)) {
        unlink($upload);
        @rmdir(dirname($upload));
    }
});
if (filesize($upload) > VALIDATOR_MAX_BYTES) {
    fwrite(STDERR, sprintf("The zip for the validator is %.1f MB, more than the %d MB it accepts (even without the manual PDFs).\n", filesize($upload) / 1048576, VALIDATOR_MAX_BYTES / 1048576));
    exit(2);
}

// 2. Compatibility flags
$majors = isset($options['compat'])
    ? array_filter(array_map('trim', explode(',', (string) $options['compat'])))
    : detectMajors($moduleDir, $zip);
fwrite(STDERR, 'Checking compatibility with PrestaShop ' . implode(', ', $majors) . "\n");

// 3. Upload
$fields = [
    'archive' => new CURLFile($upload, 'application/zip', basename($zip)),
    'key' => $apiKey,
];
foreach ($majors as $major) {
    $fields['compatibility_' . $major] = 'true';
}
ps_vlog(sprintf('validator-api: POST %s/api/modules (multipart: archive=%s %.1f MB, key=***, %s)', $baseUrl, basename($zip), filesize($upload) / 1048576,
    implode(', ', array_map(static fn (string $m): string => "compatibility_$m=true", $majors))));
$start = microtime(true);

$ch = curl_init($baseUrl . '/api/modules');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $fields,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 300,
    CURLOPT_CONNECTTIMEOUT => 20,
    CURLOPT_HTTPHEADER => ['Accept: application/json'],
]);
$body = curl_exec($ch);
$status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
$error = curl_error($ch);
ps_vdone("validator-api: HTTP $status, " . ($body === false ? "no body ($error)" : strlen((string) $body) . ' bytes'), $start);
// Release the upload file now: on Windows an open handle keeps the temporary copy (and its directory) in place.
unset($ch, $fields);

if ($body === false) {
    fwrite(STDERR, "Request failed: $error\n");
    exit(2);
}
$reportFile = preg_replace('/\.zip$/i', '', $zip) . '.validator.json';
file_put_contents($reportFile, $body);
ps_vlog("validator-api: raw response saved to $reportFile");

$data = json_decode((string) $body, true);
if ($status >= 400 || !is_array($data)) {
    fwrite(STDERR, "Validator answered HTTP $status:\n" . substr((string) $body, 0, 2000) . "\n");
    exit(2);
}
// Errors come back as HTTP 200 with {"code": 401, "message": "..."} in the body.
if (isset($data['code']) && is_numeric($data['code']) && (int) $data['code'] >= 400) {
    fwrite(STDERR, "Validator error {$data['code']}: " . ($data['message'] ?? 'unknown') . "\n"
        . ((int) $data['code'] === 401 ? "Check PRESTASHOP_VALIDATOR_API_KEY in .env.\n" : ''));
    exit(2);
}
// A real report has at least one category with a list of results.
if (array_filter($data, 'is_array') === []) {
    fwrite(STDERR, "Unexpected response from the validator (no report categories):\n" . substr((string) $body, 0, 2000) . "\n");
    exit(2);
}

if (isset($options['json'])) {
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
}

// 4. Report
[$errors, $total] = printReport($data);
fwrite(STDERR, "Raw report: $reportFile\n");

if ($errors === 0 && is_file(__DIR__ . '/dod.php')) {
    require_once __DIR__ . '/dod.php';
    $meta = is_file($zip . '.json') ? json_decode((string) file_get_contents($zip . '.json'), true) : null;
    $repoDir = dod_repo_dir(dirname($zip));
    if ($repoDir !== null && is_array($meta) && isset($meta['fingerprint'])) {
        dod_stamp($repoDir, 'validator-api', $meta['fingerprint']);
    } else {
        ps_vlog('validator-api: no stamp: ' . ($repoDir === null ? 'no module repository above the zip' : "no fingerprint in $zip.json (zip not made by ps-module build)"));
    }
} else {
    ps_vlog("validator-api: no stamp: $errors error(s) reported");
}

exit($errors > 0 ? 1 : 0);

// ---------------------------------------------------------------------------

/**
 * A copy of the zip for the validator without the manual PDFs (<module>/docs/*.pdf), in a temporary directory and
 * with the same file name. Returns the zip itself when it has no PDFs, null when the copy failed.
 */
function uploadCopyWithoutPdfs(string $zip): ?string
{
    $za = new ZipArchive();
    if ($za->open($zip) !== true) {
        fwrite(STDERR, "Cannot open $zip\n");

        return null;
    }
    $pdfs = [];
    for ($i = 0; $i < $za->numFiles; ++$i) {
        $name = (string) $za->getNameIndex($i);
        if (preg_match('#^[^/]+/docs/[^/]+\.pdf$#i', $name)) {
            $pdfs[] = $name;
        }
    }
    $za->close();
    ps_vlog(sprintf('validator-api: zip %s, %.1f MB, %d manual PDF(s) in docs/', $zip, filesize($zip) / 1048576, count($pdfs)));
    if ($pdfs === []) {
        return $zip;
    }
    $dir = sys_get_temp_dir() . '/ps-module-validator-' . bin2hex(random_bytes(4));
    $copy = $dir . '/' . basename($zip);
    if (!mkdir($dir, 0700, true) || !copy($zip, $copy) || $za->open($copy) !== true) {
        fwrite(STDERR, "Cannot make a copy of the zip in $dir\n");

        return null;
    }
    foreach ($pdfs as $name) {
        $za->deleteName($name);
    }
    if (!$za->close()) {
        fwrite(STDERR, "Cannot write the copy of the zip in $dir\n");

        return null;
    }
    fwrite(STDERR, sprintf("Sending a copy without the %d manual PDF(s) in docs/ (%.1f MB instead of %.1f MB; the validator accepts at most %d MB).\n", count($pdfs), filesize($copy) / 1048576, filesize($zip) / 1048576, VALIDATOR_MAX_BYTES / 1048576));

    return $copy;
}

/**
 * Reads KEY=value pairs from .env files in the directory and its parents; the nearest file wins.
 *
 * @return array<string, string>
 */
function loadEnv(string $dir): array
{
    $values = [];
    $dirs = [];
    for ($d = $dir; $d !== dirname($d); $d = dirname($d)) {
        $dirs[] = $d;
    }
    // Nearest .env wins: read from the top down, later files overwrite.
    foreach (array_reverse($dirs) as $d) {
        if (!is_file("$d/.env")) {
            continue;
        }
        foreach (file("$d/.env", FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            if (preg_match('/^\s*([A-Z0-9_]+)\s*=\s*(.*?)\s*$/', $line, $m) && $line[0] !== '#') {
                $values[$m[1]] = trim($m[2], "\"'");
            }
        }
    }

    return array_filter($values, static fn (string $v): bool => $v !== '');
}

/**
 * PrestaShop major versions to check, from ps_versions_compliancy in the main file.
 *
 * @return string[]
 */
function detectMajors(?string $moduleDir, string $zip): array
{
    $code = '';
    if ($moduleDir !== null) {
        $code = (string) @file_get_contents($moduleDir . '/' . basename($moduleDir) . '.php');
    } else {
        $za = new ZipArchive();
        if ($za->open($zip) === true) {
            $name = explode('/', (string) $za->getNameIndex(0))[0];
            $code = (string) $za->getFromName("$name/$name.php");
            $za->close();
        }
    }
    if (!preg_match("/'min'\s*=>\s*'(\d+)\./", $code, $min)) {
        ps_vlog('validator-api: no ps_versions_compliancy min in the main file: PrestaShop 9 only');

        return ['9'];
    }
    $minMajor = max(8, (int) $min[1]);
    $maxMajor = preg_match("/'max'\s*=>\s*'(\d+)\./", $code, $max) ? (int) $max[1] : 9;
    $maxMajor = max($minMajor, min(9, $maxMajor));
    ps_vlog("validator-api: ps_versions_compliancy min {$min[1]}.x, max " . ($max[1] ?? '(none)') . ".x: majors $minMajor..$maxMajor (between 8 and 9)");

    return array_map('strval', range($minMajor, $maxMajor));
}

/**
 * The response is keyed by category ("Details" holds the module info). Observed formats:
 * {"Security": {"<message>": ["/file", ...]}} and lists of {message,file,line,type} or
 * {messages: [{content: [...]}]}. Without an explicit type, the category decides:
 * requirements, structure, errors, security and licenses block a submission; the rest
 * (compatibility, standards, optimizations, translations) are warnings.
 *
 * @param array<string, mixed> $data
 *
 * @return array{0: int, 1: int} [errors, total]
 */
function printReport(array $data): array
{
    $blocking = ['requirements', 'structure', 'errors', 'security', 'licenses', 'license'];
    $errors = 0;
    $total = 0;
    foreach ($data as $category => $items) {
        if (!is_array($items) || strtolower((string) $category) === 'details') {
            continue;
        }
        $default = in_array(strtolower((string) $category), $blocking, true) ? 'error' : 'warning';
        $lines = [];
        foreach (flatten($items) as $msg) {
            $type = strtolower($msg['type'] ?? $default);
            if ($type === 'error') {
                ++$errors;
            }
            $where = isset($msg['file']) ? ' (' . $msg['file'] . (isset($msg['line']) ? ':' . $msg['line'] : '') . ')' : '';
            $lines[] = sprintf('  [%s] %s%s', strtoupper($type), $msg['message'], $where);
        }
        if ($lines !== []) {
            $total += count($lines);
            echo "\n" . strtoupper((string) $category) . ' (' . count($lines) . ")\n" . implode("\n", $lines) . "\n";
        }
    }
    if (isset($data['Details']) && is_array($data['Details'])) {
        echo "\nDetails: " . implode(' | ', array_filter(array_map(static fn ($v) => is_scalar($v) ? (string) $v : null, $data['Details']))) . "\n";
    }
    echo "\n" . ($total === 0 ? 'No issues reported.' : "$total issue(s), $errors error(s).") . "\n";

    return [$errors, $total];
}

/**
 * Flattens the different item formats of the validator report into a list of messages.
 *
 * @param array<mixed> $items
 *
 * @return array<int, array{message: string, file?: string, line?: int|string, type?: string}>
 */
function flatten(array $items): array
{
    $out = [];
    foreach ($items as $key => $item) {
        // {"<message>": ["/file", ...]}: the key is the message, the values are files.
        if (is_string($key) && !in_array($key, ['message', 'messages', 'content', 'file', 'line', 'type'], true) && is_array($item)
            && array_filter($item, 'is_string') === $item) {
            foreach ($item === [] ? [''] : $item as $file) {
                $out[] = $file === '' ? ['message' => $key] : ['message' => $key, 'file' => $file];
            }
            continue;
        }
        if (is_string($item) && trim($item) !== '') {
            $out[] = ['message' => is_string($key) ? "$key: " . trim($item) : trim($item)];
        } elseif (is_array($item)) {
            if (isset($item['message']) && is_string($item['message'])) {
                $out[] = $item;
            } elseif (isset($item['messages']) && is_array($item['messages'])) {
                foreach ($item['messages'] as $group) {
                    foreach ((array) ($group['content'] ?? []) as $content) {
                        if (isset($content['message'])) {
                            $out[] = $content;
                        }
                    }
                }
            } elseif (isset($item['content']) && is_array($item['content'])) {
                $out = array_merge($out, flatten($item['content']));
            } else {
                $out = array_merge($out, flatten($item));
            }
        }
    }

    return $out;
}
