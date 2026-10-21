<?php
/**
 * Verbose output for every ps-module command.
 *
 * `ps-module -v <command>` (or --verbose, anywhere on the command line, or PS_MODULE_VERBOSE=1 in the environment)
 * sets PS_MODULE_VERBOSE=1 for the command and every script it starts. The scripts then report what they do: each
 * step, every program they run (with the folder, exit code and time), files they read or write, and remote actions.
 *
 * Verbose lines go to STDERR, so output that other tools read (--json, CONTEXT.md, ...) stays the same. Secrets never
 * show: values registered with ps_vsecret() and, in publish.php, everything publish_mask() hides, print as ***.
 */

declare(strict_types=1);

/** True when verbose output is on (PS_MODULE_VERBOSE=1, set by `ps-module -v`). */
function ps_verbose(): bool
{
    return in_array(strtolower((string) getenv('PS_MODULE_VERBOSE')), ['1', 'true', 'yes', 'on'], true);
}

/**
 * Registers a secret (API key, token, password) so verbose output never shows it; with null, returns the list.
 *
 * @return string[]
 */
function ps_vsecret(?string $value = null): array
{
    static $secrets = [];
    if ($value !== null && strlen(trim($value)) >= 4) {
        $secrets[trim($value)] = true;
    }

    return array_keys($secrets);
}

/** Hides every registered secret (and, in publish.php, what publish_mask() hides) in a line of verbose output. */
function ps_vmask(string $text): string
{
    $secrets = ps_vsecret();
    // Longest first, so a secret that contains another one is hidden as a whole.
    usort($secrets, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
    foreach ($secrets as $secret) {
        $text = str_replace($secret, '***', $text);
    }
    if (function_exists('publish_mask')) {
        $text = publish_mask($text);
    }

    return $text;
}

/** Prints one verbose line to STDERR ("[v 12:34:56] message"), only when verbose output is on. */
function ps_vlog(string $message): void
{
    if (!ps_verbose()) {
        return;
    }
    foreach (preg_split('/\R/', rtrim(ps_vmask($message))) ?: [] as $line) {
        fwrite(STDERR, '[v ' . date('H:i:s') . '] ' . $line . "\n");
    }
}

/**
 * Reports a program that is about to run: the command line and the folder it runs in.
 *
 * @param string|string[] $command
 */
function ps_vcmd(string|array $command, ?string $cwd = null): void
{
    if (!ps_verbose()) {
        return;
    }
    $line = is_array($command) ? implode(' ', array_map(static fn ($a): string => preg_match('/^[\w.\/:=@%+,-]+$/', (string) $a) === 1 ? (string) $a : escapeshellarg((string) $a), $command)) : $command;
    ps_vlog('$ ' . $line . ($cwd !== null && $cwd !== '' ? '   (in ' . $cwd . ')' : ''));
}

/** Reports how a step or program ended: exit code (when given) and the time since $start (microtime(true)). */
function ps_vdone(string $what, float $start, int|string|null $exit = null): void
{
    if (!ps_verbose()) {
        return;
    }
    ps_vlog($what . ($exit !== null ? ': exit ' . $exit : ': done') . sprintf(' after %.1fs', microtime(true) - $start));
}
