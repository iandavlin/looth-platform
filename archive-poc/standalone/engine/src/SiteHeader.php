<?php
/**
 * SiteHeader — plugin-resident site header.
 *
 * Why this lives in the plugin (not the child theme):
 *   - Deploy is "activate the plugin," not "edit theme files."
 *   - Rollback is "deactivate the plugin," not "delete header.php override."
 *   - The plugin owns its dependencies (CSS + JS + the LG_SITE_HEADER inline
 *     config blob) in one place.
 *
 * How it takes over:
 *   - BuddyBoss parent header.php hooks its masthead chrome via
 *     `do_action(THEME_HOOK_PREFIX . 'header')` (i.e. `buddyboss_header`).
 *     We remove the BB callbacks and add our own, so the outer
 *     `<header id="masthead">` element BB ships still wraps the page, but
 *     its contents are our lg-site-header markup.
 *   - We also filter `buddyboss_site_header_class` so the masthead element
 *     carries `site-header--lg` instead of `site-header--bb` (the latter
 *     pulls in BB's masthead-specific CSS we don't want).
 *
 * For the v2 lite template (single-v2.php), which bypasses get_header()
 * entirely, the partial is included directly. Both paths use the same
 * markup file — single source of truth.
 */

declare(strict_types=1);

namespace LG\LayoutV2;

final class SiteHeader
{
    public const PARTIAL = LG_LAYOUT_V2_DIR . 'templates/partials/site-header.php';

    public static function boot(): void
    {
        /* Hook on `wp` so BB's own add_action() calls (which run during
           theme setup) have already registered by the time we tear them
           down. Priority 999 just to be sure we run last. */
        add_action('wp', [self::class, 'replace_bb_header'], 999);

        add_filter('buddyboss_site_header_class', [self::class, 'filter_header_class']);
        add_action('wp_enqueue_scripts',          [self::class, 'enqueue_assets']);
    }

    /**
     * Strip BB's masthead callbacks and replace with ours. Idempotent —
     * running twice is harmless because remove_all_actions clears whatever
     * was attached. THEME_HOOK_PREFIX is `buddyboss_` in BB; we fall back
     * to that literal if the constant isn't defined (e.g. swapped theme).
     */
    public static function replace_bb_header(): void
    {
        $hook = (defined('THEME_HOOK_PREFIX') ? THEME_HOOK_PREFIX : 'buddyboss_') . 'header';
        remove_all_actions($hook);
        add_action($hook, [self::class, 'render']);
    }

    public static function filter_header_class($cls): string
    {
        /* Replace BB's `site-header--bb` modifier so BB's CSS targeting that
           class doesn't paint over our chrome. Keep `site-header` so any
           generic theme rules that don't fight us still apply. */
        return 'site-header site-header--lg';
    }

    public static function render(): void
    {
        if (!is_file(self::PARTIAL)) return;
        /* No-op if the partial errored upstream — front-end auth + cookie
           gate has already vetted the request by the time get_header() runs. */
        include self::PARTIAL;
    }

    /**
     * CSS + JS enqueued site-wide. Handle names must stay `lg-site-header`
     * so Isolate.php (which allowlists them on managed-CPT posts) doesn't
     * dequeue them when v2 takes over the render. Filemtime drives the
     * `?ver=` so any CSS edit cache-busts the browser automatically.
     */
    public static function enqueue_assets(): void
    {
        $css_path = LG_LAYOUT_V2_DIR . 'assets/lg-site-header.css';
        $js_path  = LG_LAYOUT_V2_DIR . 'assets/lg-site-header.js';
        $css_ver  = is_file($css_path) ? (string) filemtime($css_path) : LG_LAYOUT_V2_VERSION;
        $js_ver   = is_file($js_path)  ? (string) filemtime($js_path)  : LG_LAYOUT_V2_VERSION;

        wp_enqueue_style('lg-site-header', LG_LAYOUT_V2_URL . 'assets/lg-site-header.css', [], $css_ver);
        wp_enqueue_script('lg-site-header', LG_LAYOUT_V2_URL . 'assets/lg-site-header.js', [], $js_ver, true);
        wp_add_inline_script(
            'lg-site-header',
            'window.LG_SITE_HEADER = ' . wp_json_encode([
                'rest_root'    => esc_url_raw(rest_url()),
                'nonce'        => wp_create_nonce('wp_rest'),
                'is_logged_in' => is_user_logged_in(),
            ]) . ';',
            'before'
        );
    }
}
