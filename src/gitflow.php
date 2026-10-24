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
 * Git workflow of a module repo (conventions GIT-01..07): every feature or fix on its own branch,
 * commit when it is done, push, pull/merge request, merge, clean up. Claude asks the user before each
 * of those steps; this file tells it which question is due.
 *
 * Usage (from bin/ps-module, cwd = repo):
 *   php gitflow.php <moduleDir> status [--remote] [--json]   state + the next question to ask
 *   php gitflow.php <moduleDir> start <slug> [--fix]         new branch feature/<slug> (or fix/<slug>), keeps uncommitted work
 *   php gitflow.php <moduleDir> pr                           push the branch and open a PR (GitHub) / MR (GitLab)
 *   php gitflow.php <moduleDir> finish                       after the merge: back to the default branch, pull, delete the branch
 *   php gitflow.php <moduleDir> later                        the user said "not now": don't ask again until the state changes
 *
 * The hooks of the MODULES project use gitflow_state() and gitflow_advice(): the UserPromptSubmit hook
 * gives Claude the state of every module repo, the Stop hook asks once per state.
 */

declare(strict_types=1);

require_once __DIR__ . '/dod.php';
require_once __DIR__ . '/verbose.php';

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    exit(gitflow_cli(array_slice($argv, 1)));
}

/**
 * Command line entry.
 *
 * @param string[] $args module directory, command, options
 */
function gitflow_cli(array $args): int
{
    $moduleDir = dod_norm((string) array_shift($args));
    $repoDir = gitflow_repo_root($moduleDir);
    if ($repoDir === null) {
        fwrite(STDERR, "ps-module git: $moduleDir is not inside a git repository (git init -b main first).\n");

        return 2;
    }
    $command = (string) (array_shift($args) ?? 'status');
    switch ($command) {
        case 'status':
            $state = gitflow_state($repoDir, in_array('--remote', $args, true));
            $dod = is_file("$repoDir/module.spec.json") ? dod_status($repoDir)['complete'] : true;
            $advice = gitflow_advice($state, $dod);
            ps_vlog('git: definition of done ' . ($dod ? 'complete' : 'not complete') . ', questions due: '
                . ($advice !== [] ? implode(', ', array_column($advice, 'key')) : 'none'));
            if (in_array('--json', $args, true)) {
                echo json_encode(['state' => $state, 'advice' => $advice], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";

                return 0;
            }
            echo gitflow_describe($state) . "\n";
            foreach ($advice as $a) {
                echo "Next: {$a['question']}\n  " . implode("\n  ", $a['commands']) . "\n";
            }
            if ($advice === []) {
                echo "Next: nothing to do in git.\n";
            }

            return 0;

        case 'start':
            return gitflow_start($repoDir, (string) ($args[0] ?? ''), in_array('--fix', $args, true) ? 'fix' : 'feature');

        case 'pr':
            return gitflow_pr($repoDir, gitflow_options($args));

        case 'merge':
            return gitflow_merge($repoDir);

        case 'finish':
            return gitflow_finish($repoDir);

        case 'later':
            $state = gitflow_state($repoDir, true);
            gitflow_mark_asked($repoDir, gitflow_signature($state), 'later');
            echo "ps-module git: noted. I will ask again when the state of the repository changes.\n";

            return 0;
    }
    fwrite(STDERR, "Usage: ps-module git status [--remote] [--json] | start <slug> [--fix]"
        . " | pr [--release=X.Y.Z --notes=... [--publish [--no-accept] [--channel=beta] [--rollout=N] [--min-updater=X.Y.Z] [--prestashop=...] [--php=...]]]"
        . " | merge | finish | later\n");

    return 2;
}

// ---------------------------------------------------------------------------
// State

/**
 * Runs git in a repository and returns the exit code and trimmed output (stderr included).
 *
 * @param string[] $args
 *
 * @return array{0: int, 1: string}
 */
function gitflow_git(string $repoDir, array $args): array
{
    $command = 'git -C ' . escapeshellarg($repoDir) . ' ' . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1';

    return gitflow_exec($command, null);
}

/**
 * Runs a command (stderr included) and returns the exit code and trimmed output. Verbose output shows the command,
 * the folder, the exit code, the time and the first lines of the output.
 *
 * @return array{0: int, 1: string}
 */
function gitflow_exec(string $command, ?string $cwd): array
{
    ps_vcmd($command, $cwd ?? (string) getcwd());
    $start = microtime(true);
    exec($command, $output, $code);
    $out = trim(implode("\n", $output));
    ps_vdone((string) strtok($command, ' '), $start, (int) $code);
    if ($out !== '' && ps_verbose()) {
        $lines = preg_split('/\R/', $out) ?: [];
        ps_vlog('  ' . implode("\n  ", array_slice($lines, 0, 20)) . (count($lines) > 20 ? "\n  ... (" . (count($lines) - 20) . ' more line(s))' : ''));
    }

    return [(int) $code, $out];
}

/**
 * The root of the git repository that contains a directory, or null.
 */
function gitflow_repo_root(string $dir): ?string
{
    if (!is_dir($dir)) {
        return null;
    }
    [$code, $out] = gitflow_git($dir, ['rev-parse', '--show-toplevel']);

    return $code === 0 && $out !== '' ? dod_norm($out) : null;
}

/**
 * Everything the workflow needs to know about a repository. With $remote, also asks GitHub (gh) for
 * the pull request of the current branch; that needs the network, so the prompt hook skips it.
 *
 * @return array<string, mixed>
 */
function gitflow_state(string $repoDir, bool $remote = false): array
{
    $repoDir = dod_norm($repoDir);
    [, $branch] = gitflow_git($repoDir, ['rev-parse', '--abbrev-ref', 'HEAD']);
    [$headCode, $head] = gitflow_git($repoDir, ['rev-parse', 'HEAD']);
    [, $porcelain] = gitflow_git($repoDir, ['status', '--porcelain']);
    [, $remoteUrl] = gitflow_git($repoDir, ['remote', 'get-url', 'origin']);
    $hasRemote = !str_contains($remoteUrl, 'fatal') && !str_contains($remoteUrl, 'error') && $remoteUrl !== '';
    $default = gitflow_default_branch($repoDir, $hasRemote);
    [$upCode, $upstream] = gitflow_git($repoDir, ['rev-parse', '--abbrev-ref', '--symbolic-full-name', '@{u}']);
    $ahead = $behind = 0;
    if ($upCode === 0) {
        [, $counts] = gitflow_git($repoDir, ['rev-list', '--left-right', '--count', '@{u}...HEAD']);
        [$behind, $ahead] = array_map('intval', preg_split('/\s+/', $counts) ?: [0, 0]) + [0, 0];
    }
    $aheadOfDefault = 0;
    if ($branch !== $default && $headCode === 0) {
        [$c, $n] = gitflow_git($repoDir, ['rev-list', '--count', "$default..HEAD"]);
        $aheadOfDefault = $c === 0 ? (int) $n : 0;
    }
    // No --format: escapeshellarg() on Windows turns "%" into a space.
    [, $merged] = gitflow_git($repoDir, ['branch', '--merged', $default]);
    $mergedBranches = array_values(array_filter(
        array_map(static fn (string $l): string => trim(ltrim($l, '*+ ')), preg_split('/\R/', $merged) ?: []),
        static fn (string $b): bool => $b !== '' && $b !== $default && $b !== $branch && preg_match('#^(feature|fix)/#', $b) === 1
    ));
    $dirty = array_values(array_filter(preg_split('/\R/', $porcelain) ?: [], static fn (string $l): bool => trim($l) !== ''));

    $state = [
        'repo' => $repoDir,
        'branch' => $branch,
        'default' => $default,
        'on_default' => $branch === $default,
        'head' => $headCode === 0 ? $head : '',
        'dirty' => count($dirty),
        'dirty_files' => array_slice(array_map(static fn (string $l): string => trim(substr($l, 3)), $dirty), 0, 8),
        'remote' => $hasRemote ? $remoteUrl : '',
        'host' => !$hasRemote ? 'none' : (str_contains($remoteUrl, 'github.com') ? 'github' : (str_contains($remoteUrl, 'gitlab') ? 'gitlab' : 'other')),
        'upstream' => $upCode === 0 ? $upstream : '',
        'ahead' => $ahead,
        'behind' => $behind,
        'ahead_of_default' => $aheadOfDefault,
        'merged_branches' => $mergedBranches,
        'pr' => null,
    ];
    if ($remote && $state['host'] === 'github' && !$state['on_default'] && gitflow_has_gh()) {
        [$code, $json] = gitflow_run('gh pr view ' . escapeshellarg($branch) . ' --json number,state,url,isDraft,mergeStateStatus', $repoDir);
        $pr = $code === 0 ? json_decode($json, true) : null;
        $state['pr'] = is_array($pr) ? $pr : null;
    } elseif ($remote) {
        ps_vlog('git: no pull request lookup: ' . ($state['host'] !== 'github' ? "host is {$state['host']}, not github" : ($state['on_default'] ? 'on the default branch' : 'gh not installed')));
    }
    ps_vlog('git: state: ' . gitflow_describe($state) . ($state['head'] !== '' ? ', HEAD ' . substr((string) $state['head'], 0, 12) : ', no commits'));

    return $state;
}

/**
 * The default branch: origin/HEAD when known, otherwise main or master (whichever exists).
 */
function gitflow_default_branch(string $repoDir, bool $hasRemote): string
{
    if ($hasRemote) {
        [$code, $ref] = gitflow_git($repoDir, ['symbolic-ref', '--quiet', '--short', 'refs/remotes/origin/HEAD']);
        if ($code === 0 && str_starts_with($ref, 'origin/')) {
            ps_vlog('git: default branch ' . substr($ref, 7) . ' (origin/HEAD)');

            return substr($ref, 7);
        }
    }
    foreach (['main', 'master'] as $candidate) {
        [$code] = gitflow_git($repoDir, ['show-ref', '--verify', '--quiet', "refs/heads/$candidate"]);
        if ($code === 0) {
            ps_vlog("git: default branch $candidate (local branch; origin/HEAD unknown)");

            return $candidate;
        }
    }
    ps_vlog('git: default branch main (assumed: no origin/HEAD, main or master)');

    return 'main';
}

/**
 * Runs a shell command in a directory and returns exit code and output.
 *
 * @return array{0: int, 1: string}
 */
function gitflow_run(string $command, string $cwd): array
{
    $previous = getcwd();
    chdir($cwd);
    $result = gitflow_exec($command . ' 2>&1', $cwd);
    if ($previous !== false) {
        chdir($previous);
    }

    return $result;
}

/**
 * True when the GitHub CLI is installed.
 */
function gitflow_has_gh(): bool
{
    // Asked several times per run: look it up once.
    static $found = null;
    if ($found === null) {
        [$code] = gitflow_exec((PHP_OS_FAMILY === 'Windows' ? 'where gh' : 'command -v gh') . ' 2>&1', null);
        $found = $code === 0;
        ps_vlog('git: GitHub CLI (gh) ' . ($found ? 'found' : 'not found'));
    }

    return $found;
}

/**
 * A short description of the state, one line.
 *
 * @param array<string, mixed> $s
 */
function gitflow_describe(array $s): string
{
    $parts = ["branch {$s['branch']}" . ($s['on_default'] ? ' (default)' : " (default: {$s['default']})")];
    $parts[] = $s['dirty'] > 0 ? "{$s['dirty']} uncommitted change(s)" : 'clean';
    if (!$s['on_default']) {
        $parts[] = "{$s['ahead_of_default']} commit(s) ahead of {$s['default']}";
    }
    if ($s['host'] === 'none') {
        $parts[] = 'no remote';
    } elseif ($s['upstream'] === '') {
        $parts[] = 'not pushed yet';
    } else {
        $parts[] = "{$s['ahead']} to push, {$s['behind']} to pull";
    }
    if (is_array($s['pr'])) {
        $parts[] = "PR #{$s['pr']['number']} {$s['pr']['state']}" . (!empty($s['pr']['isDraft']) ? ' (draft)' : '');
    }
    if ($s['merged_branches'] !== []) {
        $parts[] = 'merged branches to delete: ' . implode(', ', $s['merged_branches']);
    }

    return basename((string) $s['repo']) . ': ' . implode(', ', $parts);
}

/**
 * The questions that are due for this state, most urgent first. Each has a key, the question to ask
 * the user (AskUserQuestion), and the commands to run when the user agrees.
 *
 * @param array<string, mixed> $s state from gitflow_state()
 * @param bool $dodComplete true when the definition of done passed on the current code
 *
 * @return array<int, array{key: string, question: string, commands: string[]}>
 */
function gitflow_advice(array $s, bool $dodComplete): array
{
    $advice = [];
    $pr = is_array($s['pr']) ? $s['pr'] : null;
    if ($s['on_default'] && $s['dirty'] > 0) {
        $advice[] = [
            'key' => 'branch',
            'question' => "Er zijn wijzigingen op {$s['default']}. Is dit een nieuwe feature of fix? Dan zet ik het werk op een eigen branch.",
            'commands' => ['vendor/bin/ps-module git start <korte-naam>   (of --fix; neemt de wijzigingen mee)'],
        ];
    }
    if (!$s['on_default'] && $s['dirty'] > 0 && $dodComplete) {
        $advice[] = [
            'key' => 'commit',
            'question' => "De definition of done is groen op branch {$s['branch']}. Zal ik de wijzigingen committen?",
            'commands' => ['git add -A && git commit -m "<Engelse omschrijving>"'],
        ];
    }
    $prPossible = $s['host'] === 'github' && gitflow_has_gh();
    if (!$s['on_default'] && $s['dirty'] === 0 && $s['ahead_of_default'] > 0 && $s['host'] !== 'none'
        && ($s['upstream'] === '' || $s['ahead'] > 0 || ($pr === null && $prPossible)) && ($pr === null || ($pr['state'] ?? '') === 'OPEN')) {
        $advice[] = [
            'key' => $pr === null ? 'pr' : 'push',
            'question' => $pr === null
                ? "Branch {$s['branch']} is klaar ({$s['ahead_of_default']} commit(s)). Zal ik pushen en een " . ($s['host'] === 'gitlab' ? 'merge request' : 'pull request') . ' aanmaken?'
                : "Er staan nieuwe commits op {$s['branch']}. Zal ik ze naar PR #{$pr['number']} pushen?",
            'commands' => [$pr === null ? 'vendor/bin/ps-module git pr' : 'git push'],
        ];
    }
    // Pushed, but no pull request to see (GitLab, another host, or no gh): ask to merge there or locally.
    $prVisible = $s['host'] === 'github' && gitflow_has_gh();
    if (!$s['on_default'] && $s['dirty'] === 0 && $s['ahead_of_default'] > 0 && $s['upstream'] !== '' && $s['ahead'] === 0 && !$prVisible) {
        $advice[] = [
            'key' => 'merge',
            'question' => "Branch {$s['branch']} is gepusht" . ($s['host'] === 'gitlab' ? ' (met merge request)' : '') . ". Is hij gemerged, of zal ik hem lokaal in {$s['default']} mergen en pushen?",
            'commands' => [
                'gemerged in de webinterface: vendor/bin/ps-module git finish',
                "lokaal: git switch {$s['default']} && git merge --squash {$s['branch']} && git commit && git push && vendor/bin/ps-module git finish",
            ],
        ];
    }
    if ($pr !== null && ($pr['state'] ?? '') === 'OPEN' && $s['ahead'] === 0 && $s['dirty'] === 0 && $dodComplete) {
        $advice[] = [
            'key' => 'merge',
            'question' => "PR #{$pr['number']} ({$s['branch']}) staat klaar: {$pr['url']}. Zal ik hem mergen (squash; titel `Release X.Y.Z - …` als dit een release is)?",
            'commands' => ['gh pr merge ' . $pr['number'] . ' --squash --delete-branch', 'vendor/bin/ps-module git finish'],
        ];
    }
    if ($pr !== null && ($pr['state'] ?? '') === 'MERGED') {
        $advice[] = [
            'key' => 'finish',
            'question' => "PR #{$pr['number']} is gemerged. Zal ik teruggaan naar {$s['default']}, pullen en branch {$s['branch']} opruimen?",
            'commands' => ['vendor/bin/ps-module git finish'],
        ];
    }
    if ($s['on_default'] && $s['dirty'] === 0 && $s['host'] !== 'none' && $s['ahead'] > 0) {
        $advice[] = [
            'key' => 'push-default',
            'question' => "Er staan {$s['ahead']} commit(s) op {$s['default']} die nog niet gepusht zijn. Zal ik pushen?",
            'commands' => ["git push origin {$s['default']}"],
        ];
    }
    if ($s['on_default'] && $s['merged_branches'] !== []) {
        $advice[] = [
            'key' => 'cleanup',
            'question' => 'Deze branches zijn al gemerged: ' . implode(', ', $s['merged_branches']) . '. Zal ik ze verwijderen?',
            'commands' => ['git branch -d ' . implode(' ', $s['merged_branches'])],
        ];
    }

    return $advice;
}

// ---------------------------------------------------------------------------
// Asked once per state

/**
 * A signature of the state the questions depend on: a question is asked again when it changes.
 *
 * @param array<string, mixed> $s
 */
function gitflow_signature(array $s): string
{
    $pr = is_array($s['pr']) ? ($s['pr']['state'] ?? '') . '#' . ($s['pr']['number'] ?? '') : '';
    $dirty = $s['dirty'] > 0 ? hash('sha256', (string) gitflow_git((string) $s['repo'], ['diff', 'HEAD', '--stat'])[1] . implode(',', $s['dirty_files'])) : '';

    return hash('sha256', implode('|', [$s['branch'], $s['head'], $dirty, $s['upstream'], $s['ahead'], $pr, implode(',', $s['merged_branches'])]));
}

/**
 * File in .git/ (never committed, also in repos without .cache in .gitignore) with the last asked state.
 */
function gitflow_asked_file(string $repoDir): string
{
    [$code, $gitDir] = gitflow_git($repoDir, ['rev-parse', '--absolute-git-dir']);

    return ($code === 0 ? dod_norm($gitDir) : dod_norm($repoDir) . '/.git') . '/ps-module-asked.json';
}

/**
 * True when the questions for this exact state were already asked (or the user said "later").
 */
function gitflow_already_asked(string $repoDir, string $signature): bool
{
    $file = gitflow_asked_file($repoDir);
    $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
    $asked = is_array($data) && ($data['signature'] ?? '') === $signature;
    ps_vlog('git: state ' . substr($signature, 0, 12) . ($asked ? ' already asked (' . ($data['reason'] ?? '?') . ', ' . ($data['time'] ?? '?') . ')' : ' not asked yet') . " ($file)");

    return $asked;
}

/**
 * Records that the questions for this state were asked.
 */
function gitflow_mark_asked(string $repoDir, string $signature, string $reason): void
{
    $file = gitflow_asked_file($repoDir);
    @file_put_contents($file, json_encode(['signature' => $signature, 'reason' => $reason, 'time' => date('c')]) . "\n");
    ps_vlog('git: state ' . substr($signature, 0, 12) . " marked as asked ($reason) in $file");
}

// ---------------------------------------------------------------------------
// Actions

/**
 * Starts a feature or fix branch from the default branch. Uncommitted work comes along.
 */
function gitflow_start(string $repoDir, string $slug, string $type): int
{
    $slug = strtolower(trim((string) preg_replace('/[^a-z0-9]+/i', '-', $slug), '-'));
    if ($slug === '') {
        fwrite(STDERR, "Usage: ps-module git start <short-name> [--fix]   e.g. start free-shipping-threshold\n");

        return 2;
    }
    $state = gitflow_state($repoDir);
    $branch = "$type/$slug";
    if (!$state['on_default']) {
        fwrite(STDERR, "ps-module git: you are on {$state['branch']}, not on {$state['default']}. Finish or switch first, or create the branch from here with: git switch -c $branch\n");

        return 1;
    }
    if ($state['host'] !== 'none') {
        gitflow_git($repoDir, ['fetch', '--quiet', 'origin', $state['default']]);
        $state = gitflow_state($repoDir);
        ps_vlog("git: {$state['default']} is {$state['behind']} commit(s) behind origin" . ($state['behind'] > 0 ? ': pull --ff-only first' : ': no pull needed'));
        if ($state['behind'] > 0) {
            if ($state['dirty'] > 0) {
                fwrite(STDERR, "ps-module git: {$state['default']} is {$state['behind']} commit(s) behind origin and there are uncommitted changes.\n"
                    . "  Stash them, pull, and start again: git stash && git pull --ff-only && git stash pop\n");

                return 1;
            }
            [$code, $out] = gitflow_git($repoDir, ['pull', '--ff-only']);
            if ($code !== 0) {
                fwrite(STDERR, "ps-module git: pulling {$state['default']} failed:\n$out\n");

                return 1;
            }
        }
    }
    [$code, $out] = gitflow_git($repoDir, ['switch', '-c', $branch]);
    echo $out . "\n";
    if ($code === 0) {
        echo "ps-module git: working on $branch. Commit here; when the feature is done: ps-module git pr\n";
    }

    return $code;
}

/**
 * Pushes the current branch and opens a pull request (GitHub, via gh) or merge request (GitLab, via
 * push options). The title is the last commit's subject; the body lists the commits.
 */
/**
 * Pushes the branch and opens the pull request; with --release the title is "Release X.Y.Z - <notes>" and with
 * --publish the Update-* trailers for the update server go into the PR body (ps-module git merge uses them).
 *
 * @param array<string, string> $options
 */
function gitflow_pr(string $repoDir, array $options = []): int
{
    $state = gitflow_state($repoDir, true);
    if ($state['on_default']) {
        fwrite(STDERR, "ps-module git: you are on {$state['default']}; a pull request needs a feature branch (ps-module git start <name>).\n");

        return 1;
    }
    if ($state['dirty'] > 0) {
        fwrite(STDERR, "ps-module git: commit the {$state['dirty']} uncommitted change(s) first.\n");

        return 1;
    }
    if ($state['host'] === 'none') {
        fwrite(STDERR, "ps-module git: the repository has no remote 'origin'. Add one (git remote add origin <url>) or merge locally:\n"
            . "  git switch {$state['default']} && git merge --squash {$state['branch']} && git commit\n");

        return 1;
    }
    // --oneline instead of --format: escapeshellarg() on Windows turns "%" into a space.
    [, $log] = gitflow_git($repoDir, ['log', '--oneline', '--no-decorate', "{$state['default']}..HEAD"]);
    $subjects = array_map(static fn (string $l): string => (string) preg_replace('/^[0-9a-f]+\s+/', '', $l), array_filter(preg_split('/\R/', $log) ?: []));
    $title = $subjects !== [] ? (string) end($subjects) : (string) $state['branch']; // the first commit of the branch
    $trailers = '';
    if (isset($options['release'])) {
        if (preg_match('/^\d+\.\d+\.\d+$/', $options['release']) !== 1) {
            fwrite(STDERR, "ps-module git: --release must look like 1.2.3.\n");

            return 1;
        }
        $notes = trim($options['notes'] ?? '');
        $title = 'Release ' . $options['release'] . ' - ' . ($notes !== '' ? $notes : (string) preg_replace('/^Release \S+ - /', '', $title));
        if (isset($options['publish'])) {
            try {
                $trailers = gitflow_release_trailers($repoDir, $options);
            } catch (RuntimeException $e) {
                fwrite(STDERR, 'ps-module git: ' . $e->getMessage() . "\n");

                return 1;
            }
        }
    }
    ps_vlog('git: ' . count($subjects) . " commit(s) on {$state['branch']}, title \"$title\", host {$state['host']}");
    $log = implode("\n", array_map(static fn (string $l): string => "- $l", $subjects));
    if ($state['host'] === 'gitlab') {
        [$code, $out] = gitflow_git($repoDir, ['push', '-u', 'origin', $state['branch'],
            '-o', 'merge_request.create', '-o', "merge_request.target={$state['default']}", '-o', "merge_request.title=$title",
            '-o', 'merge_request.remove_source_branch']);
        echo $out . "\n";

        return $code;
    }
    [$code, $out] = gitflow_git($repoDir, ['push', '-u', 'origin', $state['branch']]);
    echo $out . "\n";
    if ($code !== 0 || $state['host'] !== 'github') {
        return $code;
    }
    if (is_array($state['pr'])) {
        echo "ps-module git: PR #{$state['pr']['number']} already exists: {$state['pr']['url']}\n";

        return 0;
    }
    if (!gitflow_has_gh()) {
        echo "ps-module git: pushed. Install the GitHub CLI (gh) or open the pull request in the browser.\n";

        return 0;
    }
    $bodyFile = (string) tempnam(sys_get_temp_dir(), 'pspr');
    file_put_contents($bodyFile, "Changes:\n$log\n\nDefinition of done: `composer verify` passed (`vendor/bin/ps-module status`).\n"
        . ($trailers !== '' ? "\nUpdate server (these lines go into the squash commit with `ps-module git merge`):\n\n" . GITFLOW_TRAILERS_START . "\n$trailers\n" . GITFLOW_TRAILERS_END . "\n" : ''));
    [$code, $out] = gitflow_run('gh pr create --base ' . escapeshellarg($state['default']) . ' --head ' . escapeshellarg($state['branch'])
        . ' --title ' . escapeshellarg($title) . ' --body-file ' . escapeshellarg($bodyFile), $repoDir);
    @unlink($bodyFile);
    echo $out . "\n";

    return $code;
}

const GITFLOW_TRAILERS_START = '<!-- update-trailers -->';
const GITFLOW_TRAILERS_END = '<!-- /update-trailers -->';
/** PrestaShop lines the update server knows (update-system server: app:platforms:seed) and the newest PHP line. */
const GITFLOW_PS_LINES = ['8.1', '8.2', '9.0', '9.1', '9.2'];
const GITFLOW_PHP_MAX = '8.5';

/**
 * --name=value and --flag options of a git subcommand.
 *
 * @param string[] $args
 *
 * @return array<string, string>
 */
function gitflow_options(array $args): array
{
    $options = [];
    foreach ($args as $arg) {
        if (preg_match('/^--([a-z-]+)(?:=(.*))?$/s', $arg, $m) === 1) {
            $options[$m[1]] = $m[2] ?? '1';
        }
    }

    return $options;
}

/**
 * The Update-* trailers of a release commit (update-system/PLAN.md §5): accept automatically with the PrestaShop lines
 * the module is tested on (testing.json) and PHP from module.spec.json php_min up to the newest PHP line. Options
 * override every value.
 *
 * @param array<string, string> $options
 */
function gitflow_release_trailers(string $repoDir, array $options): string
{
    $prestashop = $options['prestashop'] ?? '';
    if ($prestashop === '') {
        $testing = json_decode((string) @file_get_contents("$repoDir/testing.json"), true);
        $lines = [];
        foreach ((array) ($testing['versions'] ?? []) as $version) {
            if (preg_match('/^(\d+\.\d+)/', (string) $version, $m) === 1 && in_array($m[1], GITFLOW_PS_LINES, true)) {
                $lines[$m[1]] = true;
            }
        }
        if ($lines === []) {
            throw new RuntimeException('No tested PrestaShop versions in testing.json; pass --prestashop=8.1,9.2.');
        }
        $prestashop = implode(', ', array_keys($lines));
    }
    $php = $options['php'] ?? '';
    if ($php === '') {
        $spec = json_decode((string) @file_get_contents("$repoDir/module.spec.json"), true);
        $min = (string) ($spec['php_min'] ?? '8.1');
        if (preg_match('/^(\d+\.\d+)/', $min, $m) !== 1) {
            throw new RuntimeException('module.spec.json php_min is missing; pass --php=8.1-8.5.');
        }
        $php = $m[1] === GITFLOW_PHP_MAX ? $m[1] : $m[1] . '-' . GITFLOW_PHP_MAX;
    }
    $trailers = [
        'Update-Publish: ' . (isset($options['no-accept']) ? 'pending' : 'accept'),
        'Update-PrestaShop: ' . $prestashop,
        'Update-PHP: ' . $php,
        'Update-Channel: ' . ($options['channel'] ?? 'stable'),
        'Update-Rollout: ' . ($options['rollout'] ?? '100'),
    ];
    if (isset($options['min-updater'])) {
        $trailers[] = 'Update-Min-Updater: ' . $options['min-updater'];
    }

    return implode("\n", $trailers);
}

/** The trailers block of a PR body, or ''. */
function gitflow_trailers_from_body(string $body): string
{
    $start = strpos($body, GITFLOW_TRAILERS_START);
    $end = strpos($body, GITFLOW_TRAILERS_END);
    if ($start === false || $end === false || $end < $start) {
        return '';
    }

    return trim(substr($body, $start + strlen(GITFLOW_TRAILERS_START), $end - $start - strlen(GITFLOW_TRAILERS_START)));
}

/**
 * Squash-merges the open PR of the current branch with its title as the commit subject and, for a release, the
 * Update-* trailers from the PR body as the commit body (so the release workflow can sign with them). GitHub only.
 */
function gitflow_merge(string $repoDir): int
{
    $state = gitflow_state($repoDir, true);
    if (!is_array($state['pr'] ?? null)) {
        fwrite(STDERR, "ps-module git: there is no open pull request for {$state['branch']} (ps-module git pr).\n");

        return 1;
    }
    $number = (string) $state['pr']['number'];
    [$code, $json] = gitflow_run('gh pr view ' . escapeshellarg($number) . ' --json title,body', $repoDir);
    $pr = json_decode($json, true);
    if ($code !== 0 || !is_array($pr)) {
        fwrite(STDERR, "ps-module git: cannot read PR #$number.\n");

        return 1;
    }
    $bodyFile = (string) tempnam(sys_get_temp_dir(), 'psmerge');
    file_put_contents($bodyFile, gitflow_trailers_from_body((string) ($pr['body'] ?? '')) . "\n");
    [$code, $out] = gitflow_run('gh pr merge ' . escapeshellarg($number) . ' --squash --subject ' . escapeshellarg((string) $pr['title'])
        . ' --body-file ' . escapeshellarg($bodyFile), $repoDir);
    @unlink($bodyFile);
    echo $out . "\n";
    if ($code === 0) {
        echo "ps-module git: PR #$number merged as \"{$pr['title']}\". Clean up with: ps-module git finish\n";
    }

    return $code;
}

/**
 * After a merge: switch to the default branch, pull, and delete the merged feature branch.
 */
function gitflow_finish(string $repoDir): int
{
    $state = gitflow_state($repoDir, true);
    if ($state['dirty'] > 0) {
        fwrite(STDERR, "ps-module git: there are uncommitted changes; commit or stash them first.\n");

        return 1;
    }
    $branch = $state['on_default'] ? '' : (string) $state['branch'];
    foreach ([['switch', $state['default']], $state['host'] !== 'none' ? ['pull', '--ff-only'] : null] as $args) {
        if ($args === null) {
            continue;
        }
        [$code, $out] = gitflow_git($repoDir, $args);
        echo $out . "\n";
        if ($code !== 0) {
            return $code;
        }
    }
    if ($branch !== '') {
        // Merged into the default branch: a normal merge (ancestor), a squash merge (same content), or the PR says so.
        // Never rely on `git branch -d` alone: it also deletes a branch that is only merged into its own upstream.
        [$ancestor] = gitflow_git($repoDir, ['merge-base', '--is-ancestor', $branch, $state['default']]);
        [$sameContent] = gitflow_git($repoDir, ['diff', '--quiet', $state['default'], $branch]);
        $prMerged = is_array($state['pr']) && ($state['pr']['state'] ?? '') === 'MERGED';
        ps_vlog("git: $branch merged into {$state['default']}? ancestor: " . ($ancestor === 0 ? 'yes' : 'no') . ', same content: '
            . ($sameContent === 0 ? 'yes' : 'no') . ', PR merged: ' . ($prMerged ? 'yes' : 'no'));
        if ($ancestor === 0 || $sameContent === 0 || $prMerged) {
            [, $out] = gitflow_git($repoDir, ['branch', '-D', $branch]);
            echo $out . "\n";
            if ($state['upstream'] !== '') {
                gitflow_git($repoDir, ['push', '--quiet', 'origin', '--delete', $branch]); // may already be gone (gh --delete-branch)
            }
        } else {
            echo "ps-module git: $branch is not merged into {$state['default']} yet; kept it (switch back with: git switch $branch).\n";
        }
    }
    foreach (gitflow_state($repoDir)['merged_branches'] as $b) {
        [, $out] = gitflow_git($repoDir, ['branch', '-d', $b]); // listed by `git branch --merged <default>`
        echo $out . "\n";
    }
    if ($state['host'] !== 'none') {
        gitflow_git($repoDir, ['fetch', '--prune', '--quiet']);
    }
    echo "ps-module git: back on {$state['default']}.\n";

    return 0;
}
