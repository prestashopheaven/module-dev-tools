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
 * The box cover of a module (MKT-08): the product box of the brand in perspective with the module's logo and name,
 * docs/marketing/cover.png (transparent PNG) + cover-1000.png. Made by 'ps-module docs build' and 'ps-module docs cover'.
 *
 * The box comes from the workspace: branding/box/box.json (found upwards from the repository) names the empty box in
 * perspective, the flat front and side, the name bars, the logo area and where the four corners of the flat front and
 * side land in the perspective image (measured once per template, see branding/box/README.md). The cover is that
 * perspective image with, on top, the flat front and side layers (logo, name) transformed by the perspective
 * (CSS matrix3d), so the box keeps its own light, shadow and bands. Rendered like the frames: Docker, else Chrome.
 *
 * Per module, docs/docs.json > cover (all optional):
 *   name     the name on the box (default: the technical name without "heaven", upper case: heavenfoo -> FOO)
 *   logo     the logo, relative to docs/ (default: content/logo.svg|png, else the first content/logo-suggestions/*.svg)
 *   bar      "fit" (the pink bar as wide as the name, default) | "full" (the full bar of the template)
 *   stripes  "under" (the diagonal stripes stay, the logo lies on top, default) | "none" (no stripes) | "over"
 *   logo_area  [x0, y0, x1, y1] on the flat front, when the logo needs another place than the template's
 *   enabled  false: no cover
 */

declare(strict_types=1);

/**
 * The box template of the workspace: branding/box/box.json in a parent directory of the repository; null when absent.
 *
 * @param array<string, mixed> $ctx
 *
 * @return array{dir: string, config: array<string, mixed>}|null
 */
function docs_cover_box(array $ctx): ?array
{
    $override = getenv('PS_DOCS_BOX');
    $candidates = is_string($override) && $override !== '' ? [$override] : [];
    for ($dir = (string) $ctx['repo'], $i = 0; $i < 5 && $dir !== dirname($dir); $dir = dirname($dir), ++$i) {
        $candidates[] = "$dir/branding/box/box.json";
    }
    foreach ($candidates as $file) {
        if (is_file($file)) {
            $config = json_decode((string) file_get_contents($file), true);

            return is_array($config) ? ['dir' => str_replace('\\', '/', dirname((string) realpath($file))), 'config' => $config] : null;
        }
    }

    return null;
}

/**
 * The cover settings of the module: docs.json > cover over the defaults of box.json, plus command-line overrides.
 *
 * @param array<string, mixed> $ctx
 * @param array<string, mixed> $box
 * @param array<string, string> $overrides
 *
 * @return array{name: string, logo: string, bar: string, stripes: string, enabled: bool, logo_area: int[]}
 */
function docs_cover_settings(array $ctx, array $box, array $overrides = []): array
{
    $cover = array_merge((array) ($box['defaults'] ?? []), (array) ($ctx['config']['cover'] ?? []), $overrides);
    $name = trim((string) ($cover['name'] ?? ''));
    if ($name === '') {
        $name = strtoupper((string) preg_replace('/^heaven/i', '', (string) $ctx['module']));
    }
    $logo = (string) ($cover['logo'] ?? '');
    if ($logo === '') {
        foreach (['content/logo.svg', 'content/logo.png'] as $candidate) {
            if (is_file($ctx['docs'] . '/' . $candidate)) {
                $logo = $candidate;
                break;
            }
        }
    }
    if ($logo === '') {
        $suggestions = glob($ctx['docs'] . '/content/logo-suggestions/*.svg') ?: [];
        sort($suggestions);
        $logo = $suggestions !== [] ? 'content/logo-suggestions/' . basename($suggestions[0]) : '';
    }

    return [
        'name' => $name,
        'logo' => $logo,
        'bar' => in_array($cover['bar'] ?? 'fit', ['fit', 'full'], true) ? (string) ($cover['bar'] ?? 'fit') : 'fit',
        'stripes' => in_array($cover['stripes'] ?? 'under', ['under', 'none', 'over'], true) ? (string) ($cover['stripes'] ?? 'under') : 'under',
        'enabled' => ($cover['enabled'] ?? true) !== false,
        // Where the logo goes on the flat front (x0, y0, x1, y1): docs.json > cover.logo_area, else the template's.
        'logo_area' => array_map('intval', is_array($cover['logo_area'] ?? null) ? $cover['logo_area'] : (is_string($cover['logo_area'] ?? null) ? explode(',', $cover['logo_area']) : (array) ($box['front']['logo_area'] ?? [0, 0, 0, 0]))),
    ];
}

/**
 * The homography that maps the rectangle (0,0)-(w,h) onto four points (top left, top right, bottom right, bottom
 * left), as [h0..h8] with h8 = 1.
 *
 * @param array<int, array{0: float|int, 1: float|int}> $corners
 *
 * @return float[]
 */
function docs_cover_homography(float $w, float $h, array $corners): array
{
    $src = [[0.0, 0.0], [$w, 0.0], [$w, $h], [0.0, $h]];
    $A = [];
    $b = [];
    foreach ($src as $i => [$x, $y]) {
        [$X, $Y] = [(float) $corners[$i][0], (float) $corners[$i][1]];
        $A[] = [$x, $y, 1.0, 0.0, 0.0, 0.0, -$x * $X, -$y * $X];
        $b[] = $X;
        $A[] = [0.0, 0.0, 0.0, $x, $y, 1.0, -$x * $Y, -$y * $Y];
        $b[] = $Y;
    }
    // Gaussian elimination with partial pivoting.
    $n = 8;
    for ($i = 0; $i < $n; ++$i) {
        $max = $i;
        for ($k = $i + 1; $k < $n; ++$k) {
            if (abs($A[$k][$i]) > abs($A[$max][$i])) {
                $max = $k;
            }
        }
        [$A[$i], $A[$max]] = [$A[$max], $A[$i]];
        [$b[$i], $b[$max]] = [$b[$max], $b[$i]];
        for ($k = $i + 1; $k < $n; ++$k) {
            $f = $A[$k][$i] / $A[$i][$i];
            for ($j = $i; $j < $n; ++$j) {
                $A[$k][$j] -= $f * $A[$i][$j];
            }
            $b[$k] -= $f * $b[$i];
        }
    }
    $x = array_fill(0, $n, 0.0);
    for ($i = $n - 1; $i >= 0; --$i) {
        $s = $b[$i];
        for ($j = $i + 1; $j < $n; ++$j) {
            $s -= $A[$i][$j] * $x[$j];
        }
        $x[$i] = $s / $A[$i][$i];
    }
    $x[] = 1.0;

    return $x;
}

/**
 * CSS matrix3d() of a homography (for an element with transform-origin 0 0).
 *
 * @param float[] $h
 */
function docs_cover_matrix3d(array $h): string
{
    return sprintf('matrix3d(%.10F,%.10F,0,%.14F,%.10F,%.10F,0,%.14F,0,0,1,0,%.10F,%.10F,0,%.10F)', $h[0], $h[3], $h[6], $h[1], $h[4], $h[7], $h[2], $h[5], $h[8]);
}

/**
 * Copies a file into the staging folder of the cover (so the Docker engine sees it) and returns its file name there.
 */
function docs_cover_stage(string $stage, string $file, string $as = ''): string
{
    $name = $as !== '' ? $as : basename($file);
    copy($file, "$stage/$name");

    return $name;
}

/**
 * The HTML page of the cover: the perspective box with the front and side layers on top.
 *
 * @param array<string, mixed> $box box.json
 * @param array<string, string> $files staged file names: perspective, stripes, font, logo
 * @param array{name: string, logo: string, bar: string, stripes: string, enabled: bool, logo_area: int[]} $s
 */
function docs_cover_html(array $box, array $files, array $s): string
{
    $e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    [$W, $H] = $box['size'];
    [$fw, $fh] = $box['front']['size'];
    [$sw, $sh] = $box['side']['size'];
    $hf = docs_cover_homography((float) $fw, (float) $fh, $box['front']['corners']);
    $hs = docs_cover_homography((float) $sw, (float) $sh, $box['side']['corners']);
    [$bx0, $by0, $bx1, $by1] = $box['front']['name_bar'];
    [$sx0, $sy0, $sx1, $sy1] = $box['side']['name_bar'];
    [$lx0, $ly0, $lx1, $ly1] = $s['logo_area'];
    $barH = $by1 - $by0;
    $pad = (int) round($barH * (float) ($box['front']['bar_padding'] ?? 0.37));
    $color = (string) ($box['bar_color'] ?? '#ED368A');
    $text = (string) ($box['name_color'] ?? '#FFFFFF');
    $weight = (int) ($box['font']['weight'] ?? 700);
    $family = (string) ($box['font']['family'] ?? 'Poppins');
    $front = '';
    if ($s['stripes'] === 'none' && ($files['stripes_white'] ?? '') !== '') {
        // The front is white around the stripes: the stripe layer in white paints them away.
        $front .= '<img class="cover-stripes" alt="" src="' . $e($files['stripes_white']) . '">';
    }
    if ($s['bar'] === 'fit') {
        $front .= '<div class="cover-white" style="left:' . ($bx0 - 4) . 'px;top:' . ($by0 - 4) . 'px;width:' . ($bx1 - $bx0 + 8) . 'px;height:' . ($barH + 8) . 'px"></div>'
            . '<div class="cover-bar" data-fit-bar="front-name" data-full="' . ($bx1 - $bx0) . '" data-pad="' . $pad . '" data-center="' . (float) ($box['front']['bar_center'] ?? ($bx0 + $bx1) / 2) . '" style="left:' . $bx0 . 'px;top:' . $by0 . 'px;width:' . ($bx1 - $bx0) . 'px;height:' . $barH . 'px"></div>';
    }
    $front .= '<div class="cover-name" style="left:' . $bx0 . 'px;top:' . $by0 . 'px;width:' . ($bx1 - $bx0) . 'px;height:' . $barH . 'px;line-height:' . $barH . 'px">'
        . '<span class="front-name" data-fit data-max="' . (int) round($barH * (float) ($box['front']['font_size'] ?? 0.875)) . '" data-width="' . ($bx1 - $bx0 - 2 * $pad) . '">' . $e($s['name']) . '</span></div>';
    if (($files['logo'] ?? '') !== '') {
        $front .= '<img class="cover-logo" alt="" src="' . $e($files['logo']) . '" style="left:' . $lx0 . 'px;top:' . $ly0 . 'px;width:' . ($lx1 - $lx0) . 'px;height:' . ($ly1 - $ly0) . 'px">';
    }
    if ($s['stripes'] === 'over' && ($files['stripes'] ?? '') !== '') {
        $front .= '<img class="cover-stripes" alt="" src="' . $e($files['stripes']) . '">';
    }
    $sideH = $sy1 - $sy0;
    $sidePad = (int) round($sideH * (float) ($box['side']['bar_padding'] ?? 0.16));
    $side = '';
    if ($s['bar'] === 'fit') {
        // Like the front: the template's bar painted white, then a bar as wide as the name.
        // The template's bar is removed from the base image itself (docs_cover_erase_bar): the side is shaded.
        $side .= '<div class="cover-bar" data-fit-bar="side-name" data-full="' . ($sx1 - $sx0) . '" data-pad="' . $sidePad . '" data-center="' . (($sx0 + $sx1) / 2) . '" style="left:' . $sx0 . 'px;top:' . $sy0 . 'px;width:' . ($sx1 - $sx0) . 'px;height:' . $sideH . 'px"></div>';
    }
    $side .= '<div class="cover-name" style="left:' . $sx0 . 'px;top:' . $sy0 . 'px;width:' . ($sx1 - $sx0) . 'px;height:' . $sideH . 'px;line-height:' . $sideH . 'px">'
        . '<span class="side-name" data-fit data-max="' . (int) round($sideH * (float) ($box['side']['font_size'] ?? 0.55)) . '" data-width="' . ($sx1 - $sx0 - 2 * $sidePad) . '">' . $e($s['name']) . '</span></div>';

    return '<!doctype html>
<html><head><meta charset="utf-8"><meta name="generator" content="ps-module docs cover"><style>
@font-face { font-family: "' . $e($family) . '"; font-weight: ' . $weight . '; src: url("' . $e($files['font']) . '"); }
html, body { margin: 0; width: ' . $W . 'px; height: ' . $H . 'px; overflow: hidden; background: transparent; }
.cover-base { position: absolute; left: 0; top: 0; width: ' . $W . 'px; height: ' . $H . 'px; }
.cover-face { position: absolute; left: 0; top: 0; transform-origin: 0 0; overflow: hidden; }
.cover-face > * { position: absolute; }
.cover-white { background: #fff; }
.cover-bar { background: ' . $e($color) . '; }
.cover-name { display: flex; justify-content: center; align-items: center; color: ' . $e($text) . '; font-family: "' . $e($family) . '", sans-serif; font-weight: ' . $weight . '; white-space: nowrap; text-transform: uppercase; }
.cover-name span { display: inline-block; white-space: nowrap; padding-top: 0.08em; }
.cover-logo { object-fit: contain; object-position: 50% 100%; }
.cover-stripes { left: 0; top: 0; width: 100%; height: 100%; }
</style></head><body>
<img class="cover-base" alt="" src="' . $e(($files['perspective_fit'] ?? '') !== '' ? $files['perspective_fit'] : $files['perspective']) . '">
<div class="cover-face" style="width:' . $fw . 'px;height:' . $fh . 'px;transform:' . docs_cover_matrix3d($hf) . '">' . $front . '</div>
<div class="cover-face" style="width:' . $sw . 'px;height:' . $sh . 'px;transform:' . docs_cover_matrix3d($hs) . '">' . $side . '</div>
<script>
(async () => {
    try {
        await document.fonts.load("' . $weight . ' 100px \"' . $e($family) . '\"");
        await document.fonts.ready;
        await Promise.all(Array.from(document.images).map((img) => img.complete ? 0 : new Promise((done) => { img.onload = img.onerror = done; })));
        // Each name as large as allowed and as small as needed to fit its bar.
        document.querySelectorAll("[data-fit]").forEach((el) => {
            let size = Number(el.dataset.max);
            el.style.fontSize = size + "px";
            while (el.scrollWidth > Number(el.dataset.width) && size > 6) {
                size -= 1;
                el.style.fontSize = size + "px";
            }
        });
        // The pink bar as wide as the name plus padding (never wider than the bar of the template).
        document.querySelectorAll("[data-fit-bar]").forEach((bar) => {
            const name = document.querySelector("." + bar.dataset.fitBar);
            const width = Math.min(Number(bar.dataset.full), name.scrollWidth + 2 * Number(bar.dataset.pad));
            bar.style.width = width + "px";
            bar.style.left = (Number(bar.dataset.center) - width / 2) + "px";
        });
        const broken = Array.from(document.images).some((img) => !img.naturalWidth);
        document.body.dataset.ready = broken ? "0" : "1";
    } catch (e) {
        document.body.dataset.ready = "0";
    }
})();
</script>
</body></html>
';
}

/**
 * Renders docs/marketing/cover.png (+ cover-1000.png) when the inputs changed (or with $force). $overrides and $out
 * are for trying variants (ps-module docs cover --name=.. --stripes=.. --bar=.. --out=..).
 *
 * @param array<string, mixed> $ctx
 * @param array<string, string> $overrides
 *
 * @return array{file: string, skipped: string, error: string}
 */
function docs_render_cover(array $ctx, bool $force = false, array $overrides = [], string $out = ''): array
{
    $found = docs_cover_box($ctx);
    if ($found === null) {
        return ['file' => '', 'skipped' => 'no branding/box/box.json in a parent directory (the box template of the workspace)', 'error' => ''];
    }
    $box = $found['config'];
    $s = docs_cover_settings($ctx, $box, $overrides);
    if (!$s['enabled']) {
        return ['file' => '', 'skipped' => 'docs.json > cover.enabled is false', 'error' => ''];
    }
    $path = static fn (string $rel): string => str_starts_with($rel, '/') || preg_match('#^[A-Za-z]:[/\\\\]#', $rel) === 1 ? $rel : $found['dir'] . '/' . $rel;
    foreach (['perspective' => $box['perspective'] ?? '', 'font' => $box['font']['file'] ?? ''] as $what => $rel) {
        if ($rel === '' || !is_file($path((string) $rel))) {
            return ['file' => '', 'skipped' => '', 'error' => "box.json: $what file missing (" . $rel . ')'];
        }
    }
    $logo = $s['logo'] === '' ? '' : (preg_match('#^([A-Za-z]:)?[/\\\\]#', $s['logo']) === 1 ? $s['logo'] : $ctx['docs'] . '/' . $s['logo']);
    if ($logo !== '' && !is_file($logo)) {
        return ['file' => '', 'skipped' => '', 'error' => 'cover logo docs/' . $s['logo'] . ' not found'];
    }
    $out = $out !== '' ? $out : $ctx['docs'] . '/marketing/cover.png';
    $stage = $ctx['repo'] . '/.cache/docs/cover';
    @mkdir($stage, 0775, true);
    @mkdir(dirname($out), 0775, true);
    $files = [
        'perspective' => docs_cover_stage($stage, $path((string) $box['perspective']), 'perspective.png'),
        'perspective_fit' => '',
        'font' => docs_cover_stage($stage, $path((string) $box['font']['file']), 'font' . strrchr((string) $box['font']['file'], '.')),
        'stripes' => ($box['front']['stripes'] ?? '') !== '' && is_file($path((string) $box['front']['stripes'])) ? docs_cover_stage($stage, $path((string) $box['front']['stripes']), 'stripes.png') : '',
        'logo' => $logo !== '' ? docs_cover_stage($stage, $logo, 'logo' . strrchr($logo, '.')) : '',
    ];
    if ($s['bar'] === 'fit') {
        // A copy of the box without the side's name bar (its shading continues), for the bar as wide as the name.
        $files['perspective_fit'] = docs_cover_erase_bar($stage, $box) ? 'perspective-fit.png' : '';
    }
    $files['stripes_white'] = $s['stripes'] === 'none' && $files['stripes'] !== '' && docs_cover_whiten("$stage/stripes.png", "$stage/stripes-white.png") ? 'stripes-white.png' : '';
    $html = docs_cover_html($box, $files, $s);
    $hash = sha1($html . sha1_file($path((string) $box['perspective'])) . ($logo !== '' ? sha1_file($logo) : '') . sha1_file($path((string) $box['font']['file'])) . ($files['stripes'] !== '' ? sha1_file("$stage/stripes.png") : ''));
    $manifest = $ctx['repo'] . '/.cache/docs/cover-' . sha1($out) . '.hash';
    if (!$force && is_file($out) && is_file($manifest) && trim((string) file_get_contents($manifest)) === $hash) {
        return ['file' => $out, 'skipped' => 'up to date', 'error' => ''];
    }
    $page = "$stage/cover-" . substr(sha1($out), 0, 8) . '.html';
    file_put_contents($page, $html);
    [$W, $H] = $box['size'];
    $frame = ['width' => (int) $W, 'height' => (int) $H, 'transparent' => true];
    $todo = ['cover' => ['html' => $page, 'out' => $out, 'hash' => $hash]];
    $engines = (array) (docs_frame_config($ctx)['engines'] ?? ['docker', 'chrome']);
    $error = 'no engine';
    foreach ($engines as $engine) {
        $result = $engine === 'docker' ? docs_frames_docker($ctx, $todo, $frame) : docs_frames_chrome($todo, $frame);
        if (isset($result['cover']) && $result['cover']['ok']) {
            file_put_contents($manifest, $hash);
            docs_cover_small($out, 1000);

            return ['file' => $out, 'skipped' => '', 'error' => ''];
        }
        $error = (string) ($result['error'] ?? $result['cover']['error'] ?? 'failed');
    }

    return ['file' => '', 'skipped' => '', 'error' => $error];
}

/**
 * Removes the name bar of the side from the perspective image (perspective.png -> perspective-fit.png, cached): per
 * column the pixels of the bar (with a margin) become a blend of the pixels just above and just below it, so the
 * shading of the side continues. The bar's place comes from side.corners and side.name_bar.
 *
 * @param array<string, mixed> $box
 */
function docs_cover_erase_bar(string $stage, array $box): bool
{
    $in = "$stage/perspective.png";
    $out = "$stage/perspective-fit.png";
    $key = sha1(sha1_file($in) . json_encode($box['side']));
    if (is_file($out) && is_file("$out.key") && trim((string) file_get_contents("$out.key")) === $key) {
        return true;
    }
    $im = @imagecreatefrompng($in);
    if ($im === false) {
        return false;
    }
    imagealphablending($im, false);
    imagesavealpha($im, true);
    [$sw, $sh] = $box['side']['size'];
    $h = docs_cover_homography((float) $sw, (float) $sh, $box['side']['corners']);
    $map = static function (float $x, float $y) use ($h): array {
        $w = $h[6] * $x + $h[7] * $y + $h[8];

        return [($h[0] * $x + $h[1] * $y + $h[2]) / $w, ($h[3] * $x + $h[4] * $y + $h[5]) / $w];
    };
    [$x0, $y0, $x1, $y1] = $box['side']['name_bar'];
    $margin = 4;
    // The bar's top and bottom edge in the perspective image, as lines over the columns of the side.
    [$tlx, $tly] = $map($x0, $y0);
    [$trx, $try] = $map($x1, $y0);
    [$blx, $bly] = $map($x0, $y1);
    [$brx, $bry] = $map($x1, $y1);
    $fromX = (int) floor(min($tlx, $blx)) - 1;
    $toX = (int) ceil(max($trx, $brx)) + 1;
    for ($x = $fromX; $x <= $toX; ++$x) {
        $top = (int) floor($tly + ($try - $tly) * ($x - $tlx) / max(1e-6, $trx - $tlx)) - $margin;
        $bottom = (int) ceil($bly + ($bry - $bly) * ($x - $blx) / max(1e-6, $brx - $blx)) + $margin;
        $above = imagecolorat($im, $x, $top - 1);
        $below = imagecolorat($im, $x, $bottom + 1);
        for ($y = $top; $y <= $bottom; ++$y) {
            $t = ($y - $top) / max(1, $bottom - $top);
            $c = [];
            foreach ([24, 16, 8, 0] as $shift) {
                $c[] = (int) round((($above >> $shift) & 0xFF) * (1 - $t) + (($below >> $shift) & 0xFF) * $t);
            }
            imagesetpixel($im, $x, $y, ($c[0] << 24) | ($c[1] << 16) | ($c[2] << 8) | $c[3]);
        }
    }
    file_put_contents("$out.key", $key);

    return imagepng($im, $out);
}

/**
 * The stripe layer in white (same shape and transparency): painted over the white front it removes the stripes.
 */
function docs_cover_whiten(string $in, string $out): bool
{
    $im = @imagecreatefrompng($in);
    if ($im === false) {
        return false;
    }
    $w = imagesx($im);
    $h = imagesy($im);
    $white = imagecreatetruecolor($w, $h);
    imagealphablending($white, false);
    imagesavealpha($white, true);
    $alphas = [];
    for ($y = 0; $y < $h; ++$y) {
        for ($x = 0; $x < $w; ++$x) {
            $a = (imagecolorat($im, $x, $y) >> 24) & 0x7F;
            // A little wider than the stripe itself: its soft edge must go too.
            $alphas[$a] ??= imagecolorallocatealpha($white, 255, 255, 255, $a < 127 ? 0 : 127);
            imagesetpixel($white, $x, $y, $alphas[$a]);
        }
    }

    return imagepng($white, $out);
}

/**
 * A smaller copy of the cover (<name>-<width>.png, transparency kept) for web pages and Addons.
 */
function docs_cover_small(string $file, int $width): void
{
    $im = @imagecreatefrompng($file);
    if ($im === false) {
        return;
    }
    $height = (int) round(imagesy($im) * $width / imagesx($im));
    $small = imagecreatetruecolor($width, $height);
    imagealphablending($small, false);
    imagesavealpha($small, true);
    imagefill($small, 0, 0, imagecolorallocatealpha($small, 0, 0, 0, 127));
    imagecopyresampled($small, $im, 0, 0, 0, 0, $width, $height, imagesx($im), imagesy($im));
    imagepng($small, (string) preg_replace('/\.png$/', "-$width.png", $file));
}

/**
 * ps-module docs cover [--force] [--name=..] [--logo=..] [--logo-area=x0,y0,x1,y1] [--bar=fit|full] [--stripes=under|none|over] [--out=<file>]
 *
 * @param array<string, mixed> $ctx
 * @param string[] $flags
 */
function docs_cmd_cover(array $ctx, array $flags): int
{
    $overrides = [];
    $out = '';
    foreach ($flags as $flag) {
        if (preg_match('/^--(name|logo|bar|stripes|logo-area)=(.*)$/', $flag, $m) === 1) {
            $m[1] = str_replace('-', '_', $m[1]);
            $overrides[$m[1]] = $m[2];
        } elseif (str_starts_with($flag, '--out=')) {
            $out = substr($flag, 6);
        }
    }
    $result = docs_render_cover($ctx, in_array('--force', $flags, true) || $overrides !== [] || $out !== '', $overrides, $out);
    if ($result['error'] !== '') {
        fwrite(STDERR, 'ps-module docs cover: ' . $result['error'] . "\n");

        return 1;
    }
    echo $result['file'] !== '' ? 'Cover: ' . docs_rel($ctx, $result['file']) . ($result['skipped'] !== '' ? ' (' . $result['skipped'] . ')' : '') . "\n" : 'No cover: ' . $result['skipped'] . "\n";

    return 0;
}

/**
 * ps-module docs logo <file>: makes the chosen logo (MKT-07) the module's logo: copies it to docs/content/logo.svg (or
 * .png; the cover uses it) and renders <module>/logo.png, 512 x 512 with a transparent background, through the docs
 * engines (Docker, else Chrome). Run it after the user picked one of the suggestions, before docs build.
 *
 * @param array<string, mixed> $ctx
 * @param string[] $args
 */
function docs_cmd_logo(array $ctx, array $args): int
{
    $given = (string) ($args[0] ?? '');
    $file = '';
    foreach ([$given, $ctx['docs'] . '/' . $given, $ctx['repo'] . '/' . $given] as $candidate) {
        if ($given !== '' && is_file($candidate)) {
            $file = (string) realpath($candidate);
            break;
        }
    }
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    if ($file === '' || !in_array($ext, ['svg', 'png'], true)) {
        fwrite(STDERR, "ps-module docs logo: give the chosen logo (SVG or PNG), e.g. content/logo-suggestions/2-<idea>.svg\n");

        return 2;
    }
    $content = $ctx['docs'] . '/content/logo.' . $ext;
    foreach (['svg', 'png'] as $old) {
        if (is_file($ctx['docs'] . "/content/logo.$old") && $old !== $ext) {
            unlink($ctx['docs'] . "/content/logo.$old");
        }
    }
    if (str_replace('\\', '/', $file) !== str_replace('\\', '/', (string) realpath($content))) {
        copy($file, $content);
    }
    $stage = $ctx['repo'] . '/.cache/docs/logo';
    @mkdir($stage, 0775, true);
    copy($content, "$stage/logo.$ext");
    file_put_contents("$stage/logo.html", '<!doctype html><html><head><meta charset="utf-8"><style>html,body{margin:0;width:512px;height:512px;overflow:hidden;background:transparent}img{display:block;width:512px;height:512px;object-fit:contain}</style></head><body><img alt="" src="logo.' . $ext . '"><script>const i=document.images[0];const done=()=>{document.body.dataset.ready=i.naturalWidth?"1":"0"};i.complete?done():(i.onload=i.onerror=done);</script></body></html>');
    $out = "$stage/logo.png";
    @unlink($out);
    $frame = ['width' => 512, 'height' => 512, 'transparent' => true];
    $todo = ['logo' => ['html' => "$stage/logo.html", 'out' => $out, 'hash' => '']];
    $error = 'no engine';
    foreach ((array) (docs_frame_config($ctx)['engines'] ?? ['docker', 'chrome']) as $engine) {
        $result = $engine === 'docker' ? docs_frames_docker($ctx, $todo, $frame) : docs_frames_chrome($todo, $frame);
        if (isset($result['logo']) && $result['logo']['ok']) {
            copy($out, $ctx['module_dir'] . '/logo.png');
            echo 'Logo: ' . docs_rel($ctx, $content) . ' -> ' . basename($ctx['module_dir']) . "/logo.png (512 x 512, transparent). The cover uses it; next: docs build.\n";

            return 0;
        }
        $error = (string) ($result['error'] ?? $result['logo']['error'] ?? 'failed');
    }
    fwrite(STDERR, "ps-module docs logo: rendering logo.png failed: $error\n");

    return 1;
}
