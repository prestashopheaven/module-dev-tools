<?php
/**
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License version 3.0
 * that is bundled with this package in the file LICENSE.md.
 * It is also available through the world-wide-web at this URL:
 * https://opensource.org/licenses/AFL-3.0
 *
 * @author    George van Engers | PrestaHaven.com
 * @copyright Since 2026 George van Engers
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 */

/*
 * Release signing for the PrestaHaven update server (update-system/PLAN.md §2 and §5).
 *
 *   ps-module sign <zip> [--publish=accept|pending] [--prestashop=8.1,9.2] [--php=8.1-8.5] [--channel=stable|beta]
 *                        [--rollout=N] [--min-updater=X.Y.Z] [--trailers-from=<git ref>] [--out=<file>]
 *       Writes <zip>.sig: a signed statement about exactly this zip (module, version, sha256) plus the publish
 *       instructions. --trailers-from reads the Update-* trailers of that commit (the release workflow uses HEAD);
 *       explicit options win over trailers. The key comes from UPDATE_SIGNING_KEY (environment, else the nearest .env).
 *
 *   ps-module update-keys generate [--force] [--org=prestashopheaven] [--no-github]
 *       Makes the ed25519 key pair once: the private key goes into the workspace .env (UPDATE_SIGNING_KEY) and into the
 *       GitHub organization secret UPDATE_SIGNING_KEY (gh, through stdin); only the public key is printed.
 *   ps-module update-keys public
 *       Prints the public key and key id of UPDATE_SIGNING_KEY.
 *
 * Signature file (JSON), the format the server and the updater verify:
 *   {"format": 1, "key_id": "<16 hex>", "statement": "<base64 statement JSON>", "signature": "<base64 ed25519>"}
 * The private key is never printed, logged or written anywhere else.
 */

declare(strict_types=1);

const UPDATE_SIGN_FORMAT = 1;
const UPDATE_TRAILERS = ['Update-Publish', 'Update-PrestaShop', 'Update-PHP', 'Update-Channel', 'Update-Rollout', 'Update-Min-Updater'];

/**
 * `ps-module sign <zip> [options]`: writes <zip>.sig next to the zip (or --out).
 *
 * @param list<string> $args
 */
function update_sign_cli(array $args): int
{
    $zip = null;
    $options = [];
    foreach ($args as $arg) {
        if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $arg, $m) === 1) {
            $options[$m[1]] = $m[2] ?? '1';
        } elseif ($zip === null) {
            $zip = $arg;
        }
    }
    if ($zip === null || !is_file($zip)) {
        fwrite(STDERR, "ps-module sign: give the zip to sign (e.g. dist/<module>.zip).\n");

        return 2;
    }
    try {
        $publish = [];
        if (isset($options['trailers-from'])) {
            $publish = update_publish_from_trailers(update_commit_message($options['trailers-from'], dirname(realpath($zip) ?: $zip)));
        }
        $publish = array_merge($publish, update_publish_from_options($options));
        $signature = update_sign_zip((string) file_get_contents($zip), update_signing_key(), $publish, $options['module'] ?? null, $options['version'] ?? null);
        $out = $options['out'] ?? $zip . '.sig';
        file_put_contents($out, $signature);
        $statement = update_statement_of($signature);
        printf(
            "ps-module sign: %s %s signed (key %s, publish: %s%s) → %s\n",
            $statement['module'],
            $statement['version'],
            json_decode($signature, true)['key_id'],
            $statement['publish']['mode'],
            ($statement['publish']['mode'] === 'accept') ? ', PS ' . implode(' ', $statement['publish']['prestashop']) . ', PHP ' . implode(' ', $statement['publish']['php']) : '',
            $out,
        );

        return 0;
    } catch (Throwable $e) {
        fwrite(STDERR, 'ps-module sign: ' . $e->getMessage() . "\n");

        return 1;
    }
}

/**
 * Signs a zip: reads module and version from it (unless given), builds the statement, signs it.
 *
 * @param array<string, mixed> $publish
 */
function update_sign_zip(string $zipBytes, string $secretKey, array $publish = [], ?string $module = null, ?string $version = null): string
{
    if (strlen($secretKey) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
        throw new RuntimeException('UPDATE_SIGNING_KEY is not a valid ed25519 secret key.');
    }
    [$detectedModule, $detectedVersion] = update_zip_identity($zipBytes);
    $module ??= $detectedModule;
    $version ??= $detectedVersion;
    $statement = json_encode([
        'module' => $module,
        'version' => $version,
        'sha256' => hash('sha256', $zipBytes),
        'size' => strlen($zipBytes),
        'signed_at' => gmdate('Y-m-d\TH:i:s+00:00'),
        'publish' => update_normalise_publish($publish),
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $publicKey = sodium_crypto_sign_publickey_from_secretkey($secretKey);

    return json_encode([
        'format' => UPDATE_SIGN_FORMAT,
        'key_id' => update_key_id($publicKey),
        'statement' => base64_encode($statement),
        'signature' => base64_encode(sodium_crypto_sign_detached($statement, $secretKey)),
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
}

/**
 * Module name (the zip's single root folder) and version ($this->version in the main class).
 *
 * @return array{string, string}
 */
function update_zip_identity(string $zipBytes): array
{
    $file = (string) tempnam(sys_get_temp_dir(), 'pssign');
    file_put_contents($file, $zipBytes);
    $zip = new ZipArchive();
    try {
        if ($zip->open($file) !== true) {
            throw new RuntimeException('The file is not a readable zip.');
        }
        $roots = [];
        for ($i = 0; $i < $zip->numFiles; ++$i) {
            $roots[explode('/', (string) $zip->getNameIndex($i))[0]] = true;
        }
        if (count($roots) !== 1) {
            throw new RuntimeException('The zip must contain exactly one folder (the module).');
        }
        $module = (string) array_key_first($roots);
        $main = $zip->getFromName("$module/$module.php");
        if ($main === false || preg_match('/\$this->version\s*=\s*[\'"](\d+\.\d+\.\d+)[\'"]/', $main, $m) !== 1) {
            throw new RuntimeException("Cannot read the version from $module/$module.php.");
        }

        return [$module, $m[1]];
    } finally {
        $zip->close();
        @unlink($file);
    }
}

/**
 * Validates and completes the publish instructions (defaults: pending, stable, 100 %).
 *
 * @param array<string, mixed> $publish
 *
 * @return array{mode: string, prestashop: list<string>, php: list<string>, channel: string, rollout: int, min_updater: ?string}
 */
function update_normalise_publish(array $publish): array
{
    $mode = in_array($publish['mode'] ?? 'pending', ['accept', 'pending'], true) ? (string) ($publish['mode'] ?? 'pending') : throw new RuntimeException('Update-Publish must be accept or pending.');
    $channel = (string) ($publish['channel'] ?? 'stable');
    if (!in_array($channel, ['stable', 'beta'], true)) {
        throw new RuntimeException('Update-Channel must be stable or beta.');
    }
    $rollout = (int) ($publish['rollout'] ?? 100);
    if ($rollout < 0 || $rollout > 100) {
        throw new RuntimeException('Update-Rollout must be 0 to 100.');
    }
    $min = $publish['min_updater'] ?? null;
    if ($min !== null && $min !== '' && preg_match('/^\d+\.\d+\.\d+$/', (string) $min) !== 1) {
        throw new RuntimeException('Update-Min-Updater must look like 1.2.3.');
    }
    $prestashop = update_expand_lines((array) ($publish['prestashop'] ?? []), 'Update-PrestaShop');
    $php = update_expand_lines((array) ($publish['php'] ?? []), 'Update-PHP');
    if ($mode === 'accept' && ($prestashop === [] || $php === [])) {
        throw new RuntimeException('Update-Publish: accept needs Update-PrestaShop and Update-PHP lines.');
    }

    return ['mode' => $mode, 'prestashop' => $prestashop, 'php' => $php, 'channel' => $channel, 'rollout' => $rollout, 'min_updater' => ($min === '' ? null : $min)];
}

/**
 * "8.1, 9.2" / "8.1-8.5" / ["8.1-8.3", "8.5"] → ["8.1", "8.2", "8.3", "8.5"] (a range stays within one major version).
 *
 * @param array<mixed> $values
 *
 * @return list<string>
 */
function update_expand_lines(array $values, string $what): array
{
    $lines = [];
    foreach ($values as $value) {
        foreach (preg_split('/[\s,]+/', trim((string) $value)) ?: [] as $part) {
            if ($part === '') {
                continue;
            }
            if (preg_match('/^(\d+)\.(\d+)-(\d+)\.(\d+)$/', $part, $m) === 1) {
                if ($m[1] !== $m[3] || (int) $m[2] > (int) $m[4]) {
                    throw new RuntimeException("$what: a range must stay within one major version and go up ($part).");
                }
                for ($minor = (int) $m[2]; $minor <= (int) $m[4]; ++$minor) {
                    $lines[] = $m[1] . '.' . $minor;
                }
            } elseif (preg_match('/^\d+\.\d+$/', $part) === 1) {
                $lines[] = $part;
            } else {
                throw new RuntimeException("$what: \"$part\" is not a major.minor line or range.");
            }
        }
    }

    return array_values(array_unique($lines));
}

/**
 * Publish instructions from the Update-* trailers of a commit message.
 *
 * @return array<string, mixed>
 */
function update_publish_from_trailers(string $message): array
{
    $publish = [];
    foreach (preg_split('/\R/', $message) ?: [] as $line) {
        if (preg_match('/^(Update-[A-Za-z-]+):\s*(.*?)\s*$/', $line, $m) !== 1) {
            continue;
        }
        $value = $m[2];
        switch (strtolower($m[1])) {
            case 'update-publish': $publish['mode'] = strtolower($value); break;
            case 'update-prestashop': $publish['prestashop'] = [$value]; break;
            case 'update-php': $publish['php'] = [$value]; break;
            case 'update-channel': $publish['channel'] = strtolower($value); break;
            case 'update-rollout': $publish['rollout'] = (int) rtrim($value, '%'); break;
            case 'update-min-updater': $publish['min_updater'] = $value; break;
            default: throw new RuntimeException("Unknown trailer {$m[1]} (known: " . implode(', ', UPDATE_TRAILERS) . ').');
        }
    }

    return $publish;
}

/**
 * Publish instructions from the command-line options (they win over trailers).
 *
 * @param array<string, string> $options
 *
 * @return array<string, mixed>
 */
function update_publish_from_options(array $options): array
{
    $map = ['publish' => 'mode', 'prestashop' => 'prestashop', 'php' => 'php', 'channel' => 'channel', 'rollout' => 'rollout', 'min-updater' => 'min_updater'];
    $publish = [];
    foreach ($map as $option => $key) {
        if (isset($options[$option])) {
            $publish[$key] = in_array($key, ['prestashop', 'php'], true) ? [$options[$option]] : $options[$option];
        }
    }

    return $publish;
}

/** The full message of a commit (for its trailers). git runs without a shell, so nothing needs quoting. */
function update_commit_message(string $ref, string $cwd): string
{
    $process = proc_open(['git', '-C', $cwd, 'log', '-1', '--format=%B', $ref], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Cannot run git.');
    }
    $out = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);
    if (proc_close($process) !== 0) {
        throw new RuntimeException("Cannot read commit $ref: " . trim($err));
    }

    return $out;
}

/**
 * The statement inside a signature file (not verified: for display and tests).
 *
 * @return array{module: string, version: string, sha256: string, publish: array<string, mixed>}
 */
function update_statement_of(string $signature): array
{
    $envelope = json_decode($signature, true, 512, JSON_THROW_ON_ERROR);

    return json_decode((string) base64_decode((string) $envelope['statement'], true), true, 512, JSON_THROW_ON_ERROR);
}

/** Short id of a public key: the first 16 hex characters of its sha256 (the server uses the same). */
function update_key_id(string $publicKey): string
{
    return substr(hash('sha256', $publicKey), 0, 16);
}

/** The secret key from UPDATE_SIGNING_KEY: the environment first (CI secret), else the nearest .env upwards. */
function update_signing_key(): string
{
    $value = getenv('UPDATE_SIGNING_KEY');
    if (!is_string($value) || $value === '') {
        $value = update_env_file_value('UPDATE_SIGNING_KEY', (string) getcwd()) ?? '';
    }
    if ($value === '') {
        throw new RuntimeException('UPDATE_SIGNING_KEY is not set (environment or .env). Create it once with: ps-module update-keys generate');
    }
    $key = base64_decode(trim($value), true);
    if ($key === false || strlen($key) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
        throw new RuntimeException('UPDATE_SIGNING_KEY is not a base64 ed25519 secret key.');
    }

    return $key;
}

/** A value from the nearest .env file upwards from $from, or null. */
function update_env_file_value(string $key, string $from): ?string
{
    for ($dir = $from; $dir !== dirname($dir); $dir = dirname($dir)) {
        $file = $dir . '/.env';
        if (is_file($file) && preg_match('/^\s*' . preg_quote($key, '/') . '\s*=\s*["\']?([^"\'\r\n]*)["\']?\s*$/m', (string) file_get_contents($file), $m) === 1 && $m[1] !== '') {
            return $m[1];
        }
    }

    return null;
}

/**
 * `ps-module update-keys generate | public`.
 *
 * @param list<string> $args
 */
function update_keys_cli(array $args, string $workspace): int
{
    $sub = $args[0] ?? '';
    $force = in_array('--force', $args, true);
    $noGithub = in_array('--no-github', $args, true);
    $org = 'prestashopheaven';
    foreach ($args as $arg) {
        if (str_starts_with($arg, '--org=')) {
            $org = substr($arg, 6);
        }
    }
    try {
        if ($sub === 'public') {
            $public = sodium_crypto_sign_publickey_from_secretkey(update_signing_key());
            echo 'public key: ' . base64_encode($public) . "\nkey id:     " . update_key_id($public) . "\n";

            return 0;
        }
        if ($sub === 'push-secret') {
            update_push_org_secret(base64_encode(update_signing_key()), $org);

            return 0;
        }
        if ($sub !== 'generate') {
            fwrite(STDERR, "ps-module update-keys generate [--force] [--org=prestashopheaven] [--no-github] | public | push-secret [--org=…]\n");

            return 2;
        }
        $envFile = $workspace . '/.env';
        $existing = is_file($envFile) && preg_match('/^\s*UPDATE_SIGNING_KEY\s*=\s*\S/m', (string) file_get_contents($envFile)) === 1;
        if ($existing && !$force) {
            throw new RuntimeException("UPDATE_SIGNING_KEY exists already in $envFile. Rotating keys needs --force and a new trusted key on the server first.");
        }
        $keyPair = sodium_crypto_sign_keypair();
        $secret = base64_encode(sodium_crypto_sign_secretkey($keyPair));
        $public = sodium_crypto_sign_publickey($keyPair);

        $text = is_file($envFile) ? (string) file_get_contents($envFile) : '';
        $line = "UPDATE_SIGNING_KEY=$secret";
        $text = $existing
            ? (string) preg_replace('/^\s*UPDATE_SIGNING_KEY\s*=.*$/m', $line, $text)
            : rtrim($text) . "\n\n# Release signing key (ps-module update-keys generate). Never share; backup offline.\n$line\n";
        file_put_contents($envFile, ltrim($text));
        echo "ps-module update-keys: private key written to $envFile (UPDATE_SIGNING_KEY).\n";

        if (!$noGithub) {
            update_push_org_secret($secret, $org);
        }
        sodium_memzero($keyPair);
        echo "\npublic key: " . base64_encode($public) . "\nkey id:     " . update_key_id($public) . "\n\n"
            . "Next: add the public key to update-system/server/config/packages/signing.yaml (app.signing_public_keys) and to\n"
            . "the updater module, and store a backup of UPDATE_SIGNING_KEY offline (password manager).\n";

        return 0;
    } catch (Throwable $e) {
        fwrite(STDERR, 'ps-module update-keys: ' . $e->getMessage() . "\n");

        return 1;
    }
}

/**
 * Puts the signing key into the GitHub organization secret UPDATE_SIGNING_KEY (all repositories) through gh, on stdin;
 * the key never appears on a command line or in output. Needs the admin:org scope (gh auth refresh -s admin:org).
 */
function update_push_org_secret(string $secretBase64, string $org): void
{
    $process = proc_open(['gh', 'secret', 'set', 'UPDATE_SIGNING_KEY', '--org', $org, '--visibility', 'all'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Cannot run gh to set the organization secret.');
    }
    fwrite($pipes[0], $secretBase64);
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    if (proc_close($process) !== 0) {
        throw new RuntimeException("gh could not set the organization secret (the key is in .env; after gh auth refresh -h github.com -s admin:org run: ps-module update-keys push-secret): " . trim(str_replace($secretBase64, '***', (string) $out)));
    }
    echo "ps-module update-keys: organization secret UPDATE_SIGNING_KEY set for $org (all repositories).\n";
}

/**
 * Runs a command without a shell; returns [exit code, stdout, stderr].
 *
 * @param list<string> $command
 *
 * @return array{int, string, string}
 */
function update_run(array $command, ?string $cwd = null): array
{
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd);
    if (!is_resource($process)) {
        return [1, '', 'cannot start ' . $command[0]];
    }
    $out = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);

    return [proc_close($process), $out, $err];
}

/**
 * ps-module update backfill-signatures [--repo=owner/name ...] [--dry-run]
 * Signs every existing GitHub release that has a <module>.zip but no <module>.zip.sig, and uploads the .sig to that
 * release (publish mode "pending": the admin accepts old versions by hand). Without --repo: every module repository of
 * the workspace (modules/*, update-system/heaven-updater).
 *
 * @param list<string> $args
 */
function update_backfill_cli(array $args, string $workspace): int
{
    $dryRun = in_array('--dry-run', $args, true);
    $repos = [];
    foreach ($args as $arg) {
        if (str_starts_with($arg, '--repo=')) {
            $repos[] = substr($arg, 7);
        }
    }
    if ($repos === []) {
        foreach (array_merge(glob($workspace . '/modules/*', GLOB_ONLYDIR) ?: [], [$workspace . '/update-system/heaven-updater']) as $dir) {
            if (!is_dir("$dir/.git")) {
                continue;
            }
            [$code, $url] = update_run(['git', '-C', $dir, 'remote', 'get-url', 'origin']);
            if ($code === 0 && preg_match('#github\.com[:/]([^/]+/[^/.\s]+)#', $url, $m) === 1) {
                $repos[] = $m[1];
            }
        }
    }
    try {
        $key = $dryRun ? '' : update_signing_key();
    } catch (Throwable $e) {
        fwrite(STDERR, 'ps-module update: ' . $e->getMessage() . "\n");

        return 1;
    }
    $failures = 0;
    foreach ($repos as $repo) {
        [$code, $out, $err] = update_run(['gh', 'release', 'list', '--repo', $repo, '--limit', '300', '--json', 'tagName,isDraft']);
        if ($code !== 0) {
            fwrite(STDERR, "$repo: cannot list releases: " . trim($err) . "\n");
            ++$failures;
            continue;
        }
        foreach (json_decode($out, true) ?: [] as $release) {
            if (!empty($release['isDraft'])) {
                continue;
            }
            $tag = (string) $release['tagName'];
            [, $view] = update_run(['gh', 'release', 'view', $tag, '--repo', $repo, '--json', 'assets']);
            $names = array_column((array) (json_decode($view, true)['assets'] ?? []), 'name');
            $zips = array_values(array_filter($names, static fn (string $n): bool => preg_match('/^[a-z0-9_]+\.zip$/', $n) === 1));
            if ($zips === []) {
                echo "$repo $tag: no module zip, skipped\n";
                continue;
            }
            $zipName = $zips[0];
            if (in_array($zipName . '.sig', $names, true)) {
                echo "$repo $tag: already signed\n";
                continue;
            }
            if ($dryRun) {
                echo "$repo $tag: would sign $zipName\n";
                continue;
            }
            $dir = sys_get_temp_dir() . '/ps-backfill-' . bin2hex(random_bytes(4));
            mkdir($dir);
            try {
                [$code, , $err] = update_run(['gh', 'release', 'download', $tag, '--repo', $repo, '--pattern', $zipName, '--dir', $dir]);
                if ($code !== 0 || !is_file("$dir/$zipName")) {
                    throw new RuntimeException('download failed: ' . trim($err));
                }
                file_put_contents("$dir/$zipName.sig", update_sign_zip((string) file_get_contents("$dir/$zipName"), $key));
                [$code, , $err] = update_run(['gh', 'release', 'upload', $tag, "$dir/$zipName.sig", '--repo', $repo]);
                if ($code !== 0) {
                    throw new RuntimeException('upload failed: ' . trim($err));
                }
                echo "$repo $tag: signed and uploaded $zipName.sig\n";
            } catch (Throwable $e) {
                echo "$repo $tag: FAILED, " . $e->getMessage() . "\n";
                ++$failures;
            } finally {
                array_map('unlink', glob("$dir/*") ?: []);
                @rmdir($dir);
            }
        }
    }

    return $failures > 0 ? 1 : 0;
}

/** The workspace root (the folder with .claude/skills/ps-module), searched upwards from $from. */
function update_workspace_dir(string $from): ?string
{
    for ($dir = $from; $dir !== dirname($dir); $dir = dirname($dir)) {
        if (is_dir($dir . '/.claude/skills/ps-module')) {
            return $dir;
        }
    }

    return null;
}
