<?php
/**
 * Update-system rules of a module repo (conventions UPD-01..03, update-system/PLAN.md §5–6), part of
 * `ps-module conventions`:
 *
 *   UPD-01  The release workflow signs the direct zip for the update server and attaches it with its .sig, plus the
 *           Addons build (<module>-addons.zip) for modules that are sold on Addons.
 *   UPD-02  No module_key in the source: it lives in module.spec.json > module_key and only the Addons build gets it
 *           (the direct build must stay empty, or the updater treats copies as Addons copies).
 *   UPD-03  A module with its own hookDisplayAdminAfterHeader resolves the clash with module-kit's updater notice in
 *           the main class (`… insteadof ModuleBase;`), otherwise PHP stops with a fatal error.
 */

declare(strict_types=1);

/**
 * Checks a module folder; returns the violations as "<file>[:line] UPD-0x message" lines.
 *
 * @return list<string>
 */
function checkUpdateReadiness(string $moduleDir): array
{
    $name = basename($moduleDir);
    $repoDir = dirname($moduleDir);
    $violations = [];

    $workflow = "$repoDir/.github/workflows/release.yml";
    if (is_file($workflow)) {
        $yaml = (string) file_get_contents($workflow);
        $spec = is_file("$repoDir/module.spec.json") ? json_decode((string) file_get_contents("$repoDir/module.spec.json"), true) : [];
        $distribution = is_array($spec) && is_string($spec['distribution'] ?? null) ? $spec['distribution'] : 'addons';
        if (!str_contains($yaml, 'ps-module sign') || !str_contains($yaml, '.zip.sig')) {
            $violations[] = '.github/workflows/release.yml UPD-01 the release does not sign the zip for the update server: take the "Sign for the update server" step and the .zip.sig asset from the release.yml template';
        }
        if ($distribution !== 'own-clients' && !str_contains($yaml, '-addons.zip')) {
            $violations[] = '.github/workflows/release.yml UPD-01 the release does not attach the Addons build (<module>-addons.zip from dist/addons/): take it from the release.yml template';
        }
    }

    $mainFile = "$moduleDir/$name.php";
    $main = is_file($mainFile) ? (string) file_get_contents($mainFile) : '';
    if (preg_match('/\$this->module_key\s*=\s*([\'"])([^\'"]+)\1/', $main, $m, PREG_OFFSET_CAPTURE) === 1) {
        $line = substr_count(substr($main, 0, (int) $m[0][1]), "\n") + 1;
        $violations[] = "$name.php:$line UPD-02 module_key in the source: move it to module.spec.json > module_key (only the Addons build gets it)";
    }

    if ($main !== '' && preg_match('/\buse\s+[^;]*\bModuleBase\b/', $main) === 1 && preg_match('/hookDisplayAdminAfterHeader\s+insteadof\s+ModuleBase/i', $main) !== 1) {
        foreach (glob("$moduleDir/src/Module/Hook/*.php") ?: [] as $hookFile) {
            if (preg_match('/function\s+hookDisplayAdminAfterHeader\s*\(/i', (string) file_get_contents($hookFile)) === 1) {
                $violations[] = "$name.php UPD-03 " . basename($hookFile) . ' has hookDisplayAdminAfterHeader, which module-kit (updater notice) has too: add `' . basename($hookFile, '.php') . '::hookDisplayAdminAfterHeader insteadof ModuleBase;` to the use block of the main class';
            }
        }
    }

    return $violations;
}
