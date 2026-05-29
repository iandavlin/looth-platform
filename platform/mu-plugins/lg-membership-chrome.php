<?php
/**
 * Plugin Name: LG Membership Pages — Shared Chrome
 * Description: Renders the lg-patreon-stripe-poller auto-seeded membership pages
 *              (lgjoin, membership-guide, manage-subscription, …) on the unified
 *              /srv/lg-shared/ header+footer instead of BuddyBoss theme chrome,
 *              by swapping the template via `template_include`.
 *
 * Owner lane: poller (lg-patreon-stripe-poller). Consumes the shared header
 * (lg-shell owns /srv/lg-shared/ — we do NOT modify it, only call it).
 *
 * Mechanism:
 *   - On a singular page whose slug is one of the 11 registry slugs, return a
 *     custom template that emits  header → the_content() → footer  and bypasses
 *     the active theme entirely.
 *   - Viewer state is built IN-PROCESS (wp_get_current_user() + lg_viewer_tier())
 *     so these pages still render while profile-app / Stripe are dormant
 *     (B-now/A-later cutover). No /whoami loopback dependency.
 *   - Fail-safe: if the shared partial isn't readable, fall through to the
 *     theme template rather than fatal.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Slugs of the poller's auto-seeded membership pages (Pages::PAGES registry).
 * Keep in sync with src/Wp/Pages.php — 11 entries.
 */
const LG_MEMBERSHIP_CHROME_SLUGS = [
	'lgjoin',
	'lggift-buy',
	'lggift',
	'manage-subscription',
	'regional-pricing-not-available',
	'welcome',
	'my-gifts',
	'membership-guide',
	'affiliate-earnings',
	'test-checklist',
	'request-refund',
];

const LG_MEMBERSHIP_CHROME_HEADER = '/srv/lg-shared/site-header.php';
const LG_MEMBERSHIP_CHROME_FOOTER = '/srv/lg-shared/site-footer.php';

/**
 * Swap in the shared-chrome template for membership pages on the main query.
 *
 * Priority 99 so it runs after most theme/template-routing filters and wins.
 */
add_filter( 'template_include', function ( $template ) {

	// Only the real page view — never feeds, REST, embeds, or secondary queries.
	if ( ! is_main_query() || ! is_singular( 'page' ) ) {
		return $template;
	}

	$obj = get_queried_object();
	if ( ! $obj instanceof WP_Post || ! in_array( $obj->post_name, LG_MEMBERSHIP_CHROME_SLUGS, true ) ) {
		return $template;
	}

	// Fail-safe: shared partials must be present + readable, else keep the theme.
	if ( ! is_readable( LG_MEMBERSHIP_CHROME_HEADER ) || ! is_readable( LG_MEMBERSHIP_CHROME_FOOTER ) ) {
		return $template;
	}

	$custom = __DIR__ . '/lg-membership-chrome/template.php';
	return is_readable( $custom ) ? $custom : $template;

}, 99 );

/**
 * Build the viewer-context array the shared header expects, in-process.
 * No DB-write, no HTTP. Mirrors lg-viewer-tier.php's tier map.
 *
 * @return array<string,mixed>
 */
function lg_membership_chrome_viewer_ctx(): array {
	$authed = is_user_logged_in();

	$tier = 'public';
	if ( $authed && function_exists( 'lg_viewer_tier' ) ) {
		$tier = lg_viewer_tier();
	}

	$display_name = '';
	$avatar_url   = null;
	$caps         = [
		'manage_options'   => false,
		'edit_archive_poc' => false,
	];

	if ( $authed ) {
		$user         = wp_get_current_user();
		$display_name = (string) ( $user->display_name ?: $user->user_login );
		$avatar_url   = get_avatar_url( $user->ID ) ?: null;
		$caps         = [
			'manage_options'   => current_user_can( 'manage_options' ),
			'edit_archive_poc' => current_user_can( 'edit_archive_poc' ),
		];
	}

	$logo_url = ( defined( 'WP_HOME' ) && str_contains( WP_HOME, 'dev.loothgroup.com' ) )
		? 'https://dev.loothgroup.com/wp-content/uploads/2024/05/Looth-Group-Logo-Site-Menu.png'
		: 'https://loothgroup.com/wp-content/uploads/2024/05/Looth-Group-Logo-Site-Menu.png';

	return [
		'authenticated' => $authed,
		'tier'          => $tier,
		'display_name'  => $display_name,
		'avatar_url'    => $avatar_url,
		'capabilities'  => $caps,
		'msg_unread'    => null,   // lazy-load via REST
		'notif_unread'  => null,   // lazy-load via REST
		'profile_url'   => '/members/me/',
		'logout_url'    => wp_logout_url( '/' ),
		'logo_url'      => $logo_url,
	];
}
