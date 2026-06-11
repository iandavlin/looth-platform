<?php
/**
 * img.php — on-the-fly image resizer for Hub feed covers (and any uploads image).
 *
 * Feed covers were serving full-res originals (3024px phone photos, ~9 MB/page).
 * This routes them through a width-capped WebP transcode with a local disk cache.
 *
 * Contract (emitted by lg_cover_src() in forums/_feed.php):
 *     /img.php?s=<path-under-wp-content/uploads>&w=<width>
 *
 * Runs on the looth-dev FPM pool (the WP pool) because it is in the `loothdevs`
 * group and can read the R2 uploads mount; the bb-mirror pool cannot.
 * Reads originals from /var/www/dev/wp-content/uploads/, caches WebP under
 * /var/cache/lg-img/, serves with a long immutable cache header.
 *
 * Fails SOFT: on any error (missing/oversized/undecodable source, no GD) it
 * 302-redirects to the original URL so the card still shows an image.
 *
 * Ian 2026-06-11 (cover-resize thread; finished by the bespoke-cutover coordinator).
 */

declare(strict_types=1);

const UPLOADS_DIR = '/var/www/dev/wp-content/uploads';
const CACHE_DIR   = '/var/cache/lg-img';
const MIN_W       = 64;
const MAX_W       = 2000;
const MAX_SRC     = 40 * 1024 * 1024; // skip pathological originals (>40 MB)

/** Redirect to the untouched original and stop. The card still renders. */
function passthrough(string $rel): never
{
    // $rel is the validated uploads-relative path; rawurlencode each segment.
    $safe = implode('/', array_map('rawurlencode', explode('/', $rel)));
    header('Location: /wp-content/uploads/' . $safe, true, 302);
    header('Cache-Control: no-store');
    exit;
}

function fail(int $code): never
{
    http_response_code($code);
    header('Cache-Control: no-store');
    exit;
}

// --- parse + sanitise -------------------------------------------------------
$rel = isset($_GET['s']) ? (string) $_GET['s'] : '';
$rel = ltrim($rel, '/');
$w   = isset($_GET['w']) ? (int) $_GET['w'] : 800;
$w   = max(MIN_W, min(MAX_W, $w));

// Reject anything that could escape the uploads root or hit a dotfile.
if ($rel === '' || str_contains($rel, '..') || str_contains($rel, "\0") || str_starts_with($rel, '.')) {
    fail(400);
}
if (!preg_match('/\.(jpe?g|png|gif|webp)$/i', $rel)) {
    fail(400);
}

$src = UPLOADS_DIR . '/' . $rel;
// Defence in depth: the real resolved path must still sit under uploads.
$realUploads = realpath(UPLOADS_DIR);
$realSrc     = realpath($src);
if ($realUploads === false || $realSrc === false || !str_starts_with($realSrc, $realUploads . '/')) {
    fail(404);
}

if (!function_exists('imagecreatefromstring') || !function_exists('imagewebp')) {
    passthrough($rel); // GD/WebP unavailable — let the browser fetch the original
}

$srcStat = @stat($realSrc);
if ($srcStat === false) {
    fail(404);
}
if ($srcStat['size'] > MAX_SRC) {
    passthrough($rel);
}

// --- cache lookup -----------------------------------------------------------
// Key on path+width+source-mtime so a re-uploaded original busts the cache.
$key   = sha1($rel . '|' . $w . '|' . $srcStat['mtime']);
$cache = CACHE_DIR . '/' . substr($key, 0, 2) . '/' . $key . '.webp';

function serve_webp(string $file): never
{
    header('Content-Type: image/webp');
    header('Cache-Control: public, max-age=31536000, immutable');
    header('Content-Length: ' . (string) filesize($file));
    readfile($file);
    exit;
}

if (is_file($cache)) {
    serve_webp($cache);
}

// --- decode + resize --------------------------------------------------------
$raw = @file_get_contents($realSrc);
if ($raw === false) {
    passthrough($rel);
}
$im = @imagecreatefromstring($raw);
unset($raw);
if ($im === false) {
    passthrough($rel);
}

$sw = imagesx($im);
$sh = imagesy($im);
if ($sw < 1 || $sh < 1) {
    imagedestroy($im);
    passthrough($rel);
}

if ($sw <= $w) {
    // Never upscale; just transcode at native size to WebP.
    $dst = $im;
} else {
    $dw  = $w;
    $dh  = (int) round($sh * ($w / $sw));
    $dst = imagecreatetruecolor($dw, $dh);
    imagealphablending($dst, false);
    imagesavealpha($dst, true);
    imagecopyresampled($dst, $im, 0, 0, 0, 0, $dw, $dh, $sw, $sh);
    imagedestroy($im);
}

// --- write cache (atomic) + serve ------------------------------------------
@mkdir(dirname($cache), 02775, true);
$tmp = $cache . '.' . getmypid() . '.tmp';
if (imagewebp($dst, $tmp, 82)) {
    @rename($tmp, $cache);
    imagedestroy($dst);
    if (is_file($cache)) {
        serve_webp($cache);
    }
}

// Last resort: stream the encode straight to the client without caching.
imagedestroy($dst);
@unlink($tmp);
header('Content-Type: image/webp');
header('Cache-Control: public, max-age=86400');
$im2 = @imagecreatefromstring(@file_get_contents($realSrc) ?: '');
if ($im2 !== false) {
    imagewebp($im2, null, 82);
    imagedestroy($im2);
    exit;
}
passthrough($rel);
