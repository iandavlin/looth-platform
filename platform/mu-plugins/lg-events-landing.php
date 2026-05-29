<?php
/**
 * Plugin Name: LG Events Landing
 * Description: Renders the events landing page (page ID 2773, slug `calendar`)
 *              as a PUBLIC listing of v2 `event` posts on the unified
 *              /srv/lg-shared/ header+footer, instead of BuddyBoss theme chrome,
 *              by swapping the template via `template_include`.
 *
 * Owner lane: events. Consumes the shared header (lg-shell owns /srv/lg-shared/
 * — we do NOT modify it, only call it).
 *
 * Listing model:
 *   - Upcoming + Past split, sorted by `events_start_date_and_time_` (string
 *     compare on Ymd — meta_type CHAR avoids the cast-to-DATE that crashed the
 *     old Dynamic.ooo widget; see lg-events-shortcode.php prior art).
 *   - Region taxonomy filter via ?ev_region=<slug>.
 *   - The listing is PUBLIC: every event's date/time/region/tier shows to
 *     everyone. Cards link to the event's v2 detail page, where the per-event
 *     Zoom gate (event-header block) lives. The Zoom URL is NEVER emitted into
 *     the listing — clicking through applies the existing per-event gate.
 *   - No poller code touched: this runs its own WP_Query (the poller's
 *     UpcomingEvents::nextN() caps at 12 and returns a single upcoming-OR-past
 *     bucket, so it can't express this split + filter; flagged to coordinator).
 *
 * Fail-safe: if the shared partials aren't readable, fall through to the theme.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const LG_EVENTS_LANDING_PAGE_ID = 2773;
const LG_EVENTS_LANDING_HEADER  = '/srv/lg-shared/site-header.php';
const LG_EVENTS_LANDING_FOOTER  = '/srv/lg-shared/site-footer.php';

/**
 * Swap in the shared-chrome template for the events landing page on the main
 * query. Priority 99 so it wins over theme/template-routing filters.
 */
add_filter( 'template_include', function ( $template ) {
	if ( ! is_main_query() || ! is_page( LG_EVENTS_LANDING_PAGE_ID ) ) {
		return $template;
	}
	if ( ! is_readable( LG_EVENTS_LANDING_HEADER ) || ! is_readable( LG_EVENTS_LANDING_FOOTER ) ) {
		return $template;
	}
	$custom = __DIR__ . '/lg-events-landing/template.php';
	return is_readable( $custom ) ? $custom : $template;
}, 99 );

/**
 * Build the viewer-context array the shared header expects, in-process.
 * Mirrors lg-membership-chrome's ctx builder (no /whoami loopback dep).
 *
 * @return array<string,mixed>
 */
function lg_events_landing_viewer_ctx(): array {
	$authed = is_user_logged_in();

	$tier = 'public';
	if ( $authed && function_exists( 'lg_viewer_tier' ) ) {
		$tier = lg_viewer_tier();
	}

	$display_name = '';
	$avatar_url   = null;
	$caps         = [ 'manage_options' => false, 'edit_archive_poc' => false ];

	if ( $authed ) {
		$user         = wp_get_current_user();
		$display_name = (string) ( $user->display_name ?: $user->user_login );
		$avatar_url   = get_avatar_url( $user->ID ) ?: null;
		$caps         = [
			'manage_options'   => current_user_can( 'manage_options' ),
			'edit_archive_poc' => current_user_can( 'edit_archive_poc' ),
		];
	}

	$logo_url = ( defined( 'WP_HOME' ) && str_contains( (string) WP_HOME, 'dev.loothgroup.com' ) )
		? 'https://dev.loothgroup.com/wp-content/uploads/2024/05/Looth-Group-Logo-Site-Menu.png'
		: 'https://loothgroup.com/wp-content/uploads/2024/05/Looth-Group-Logo-Site-Menu.png';

	return [
		'authenticated' => $authed,
		'tier'          => $tier,
		'display_name'  => $display_name,
		'avatar_url'    => $avatar_url,
		'capabilities'  => $caps,
		'msg_unread'    => null,
		'notif_unread'  => null,
		'profile_url'   => '/members/me/',
		'logo_url'      => $logo_url,
	];
}

/**
 * Query published events for one bucket (upcoming or past), optionally filtered
 * by a region taxonomy slug. String-compare Ymd (meta_type CHAR) — never DATE.
 */
function lg_events_landing_query( bool $past, string $region_slug ): WP_Query {
	$today = gmdate( 'Ymd' );
	$args  = [
		'post_type'      => 'event',
		'post_status'    => 'publish',
		'posts_per_page' => 50,
		'meta_key'       => 'events_start_date_and_time_',
		'orderby'        => 'meta_value',
		'meta_type'      => 'CHAR',
		'order'          => $past ? 'DESC' : 'ASC',
		'meta_query'     => [ [
			'key'     => 'events_start_date_and_time_',
			'value'   => $today,
			'compare' => $past ? '<' : '>=',
			'type'    => 'CHAR',
		] ],
		'no_found_rows'  => true,
	];
	if ( $region_slug !== '' ) {
		$args['tax_query'] = [ [
			'taxonomy' => 'region',
			'field'    => 'slug',
			'terms'    => $region_slug,
		] ];
	}
	return new WP_Query( $args );
}

/**
 * Format an event's stored date + time into display parts.
 * Handles BOTH legacy 24h ("15:00:00") and the Sheet bridge's 12h ("3:00 pm").
 *
 * @return array{mon:string,day:string,line:string}
 */
function lg_events_landing_when( string $ymd, string $hms ): array {
	$mon = $day = $line = '';
	if ( preg_match( '/^\d{8}$/', $ymd ) ) {
		$ts = mktime( 12, 0, 0, (int) substr( $ymd, 4, 2 ), (int) substr( $ymd, 6, 2 ), (int) substr( $ymd, 0, 4 ) );
		if ( $ts !== false ) {
			$mon  = strtoupper( gmdate( 'M', $ts ) );
			$day  = gmdate( 'j', $ts );
			$line = gmdate( 'l, F j, Y', $ts );
		}
	}
	if ( preg_match( '/(\d{1,2}):(\d{2})/', $hms, $m ) ) {
		$h  = (int) $m[1];
		$mn = (int) $m[2];
		if ( preg_match( '/p\.?m/i', $hms ) )      { if ( $h < 12 ) $h += 12; }
		elseif ( preg_match( '/a\.?m/i', $hms ) )  { if ( $h === 12 ) $h = 0; }
		$ampm = $h >= 12 ? 'PM' : 'AM';
		$h12  = $h % 12 === 0 ? 12 : $h % 12;
		$line = trim( $line . ' · ' . sprintf( '%d:%02d %s ET', $h12, $mn, $ampm ), ' ·' );
	}
	return [ 'mon' => $mon, 'day' => $day, 'line' => $line ];
}

/** Region slugs→names actually used by PUBLISHED events, so the filter chips
 *  never offer a dead region (get_terms(hide_empty) counts other CPTs too).
 *  @return array<string,string> slug => display name, sorted by name */
function lg_events_landing_regions(): array {
	$q   = new WP_Query( [
		'post_type'      => 'event',
		'post_status'    => 'publish',
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'no_found_rows'  => true,
	] );
	$out = [];
	foreach ( $q->posts as $pid ) {
		foreach ( wp_get_object_terms( (int) $pid, 'region' ) ?: [] as $t ) {
			if ( isset( $t->slug, $t->name ) ) {
				$out[ (string) $t->slug ] = (string) $t->name;
			}
		}
	}
	wp_reset_postdata();
	asort( $out );
	return $out;
}

/** Human tier label for a card chip, or '' to omit. Listing is public; this
 *  is purely informational ("who is this event for"), not a gate. */
function lg_events_landing_tier_label( int $post_id ): string {
	foreach ( wp_get_object_terms( $post_id, 'tier', [ 'fields' => 'slugs' ] ) ?: [] as $slug ) {
		if ( $slug === 'looth-pro' ) return 'Pro';
		if ( $slug === 'looth-lite' ) return 'Lite';
	}
	return '';
}
