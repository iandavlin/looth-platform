<?php
/**
 * Shared-chrome template for poller membership pages.
 * Loaded via template_include (see ../lg-membership-chrome.php).
 *
 * Emits:  doctype → <head> (wp_head) → <body class=body_class()>
 *         → shared header → <main id="lg-main"> the_content() </main>
 *         → shared footer → wp_footer().
 *
 * body_class() is called so the poller's filters still fire:
 *   - Plugin::addCustomerBodyClass() → `lg-customer-only`
 *   - any other body_class consumers the shortcodes rely on
 * (The membership-guide is-member/is-anon split is computed by the shortcode
 *  itself, not from body_class — but the admin preview bar still needs these.)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once LG_MEMBERSHIP_CHROME_HEADER;
require_once LG_MEMBERSHIP_CHROME_FOOTER;

$lg_ctx = lg_membership_chrome_viewer_ctx();

?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="stylesheet" href="/lg-shared/site-header.css">
<?php wp_head(); ?>
</head>
<body <?php body_class( 'lg-membership-page' ); ?>>

<?php lg_shared_render_site_header( $lg_ctx ); ?>

<main id="lg-main" class="lg-membership-main">
<?php
while ( have_posts() ) :
	the_post();
	the_content();
endwhile;
?>
</main><!-- #lg-main -->

<?php lg_shared_render_site_footer( [ 'logo_url' => $lg_ctx['logo_url'] ] ); ?>

<?php wp_footer(); ?>
</body>
</html>
