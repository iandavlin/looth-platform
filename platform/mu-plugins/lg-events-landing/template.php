<?php
/**
 * Shared-chrome template for the events landing page (page 2773, /calendar/).
 * Loaded via template_include (see ../lg-events-landing.php).
 *
 * Emits: doctype → <head> (wp_head) → shared header → <main> events listing
 *        → shared footer → wp_footer(). The listing is fully public; cards link
 *        to each event's v2 detail page where the per-event Zoom gate lives.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once LG_EVENTS_LANDING_HEADER;
require_once LG_EVENTS_LANDING_FOOTER;

$lg_ctx = lg_events_landing_viewer_ctx();

/* Region filter from the query string (taxonomy slug; '' = all). */
$active_region = isset( $_GET['ev_region'] ) ? sanitize_title( (string) $_GET['ev_region'] ) : '';

/* Region chips: only regions actually used by published events. */
$regions = lg_events_landing_regions(); // [ slug => name ]

$esc = static fn( string $s ): string => htmlspecialchars( $s, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8' );
$base_url = get_permalink( LG_EVENTS_LANDING_PAGE_ID ) ?: '/calendar/';

/**
 * Render one bucket (upcoming/past) as a card grid.
 */
$render_bucket = static function ( bool $past, string $region_slug ) use ( $esc ): void {
	$q = lg_events_landing_query( $past, $region_slug );
	if ( ! $q->have_posts() ) {
		echo '<p class="lg-evland__empty">' . ( $past ? 'No past events.' : 'No upcoming events scheduled — check back soon.' ) . '</p>';
		wp_reset_postdata();
		return;
	}
	echo '<div class="lg-evland__grid">';
	while ( $q->have_posts() ) {
		$q->the_post();
		$pid   = (int) get_the_ID();
		$when  = lg_events_landing_when(
			(string) get_post_meta( $pid, 'events_start_date_and_time_', true ),
			(string) get_post_meta( $pid, 'time_of_event', true )
		);
		$tier  = lg_events_landing_tier_label( $pid );
		$thumb = (string) ( get_the_post_thumbnail_url( $pid, 'medium_large' ) ?: '' );
		$rterms = get_the_terms( $pid, 'region' );
		$region = ( is_array( $rterms ) && $rterms ) ? (string) $rterms[0]->name : '';
		$url   = (string) ( get_permalink( $pid ) ?: '#' );
		?>
		<a class="lg-evland__card" href="<?php echo $esc( $url ); ?>">
			<div class="lg-evland__thumb"<?php echo $thumb ? ' style="background-image:url(' . $esc( $thumb ) . ')"' : ''; ?>>
				<?php if ( $when['mon'] !== '' ) : ?>
					<span class="lg-evland__pill"><span class="lg-evland__mon"><?php echo $esc( $when['mon'] ); ?></span><span class="lg-evland__day"><?php echo $esc( $when['day'] ); ?></span></span>
				<?php endif; ?>
			</div>
			<div class="lg-evland__body">
				<h3 class="lg-evland__title"><?php echo $esc( get_the_title( $pid ) ); ?></h3>
				<?php if ( $when['line'] !== '' ) : ?><p class="lg-evland__when"><?php echo $esc( $when['line'] ); ?></p><?php endif; ?>
				<div class="lg-evland__meta">
					<?php if ( $region !== '' ) : ?><span class="lg-evland__region">📍 <?php echo $esc( $region ); ?></span><?php endif; ?>
					<?php if ( $tier !== '' ) : ?><span class="lg-evland__tier lg-evland__tier--<?php echo $esc( strtolower( $tier ) ); ?>"><?php echo $esc( $tier ); ?></span><?php endif; ?>
					<span class="lg-evland__cta">Details &rarr;</span>
				</div>
			</div>
		</a>
		<?php
	}
	echo '</div>';
	wp_reset_postdata();
};
?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="stylesheet" href="/lg-shared/site-header.css">
<style>
  .lg-evland { max-width: var(--lg-article-max, 1040px); margin: 0 auto; padding: 32px clamp(16px,4vw,48px); }
  .lg-evland__head { margin: 0 0 8px; font: 700 clamp(28px,4vw,44px)/1.1 var(--lg-font-serif, Georgia, serif); color: var(--lg-charcoal, #1a1d1a); }
  .lg-evland__sub { margin: 0 0 24px; color: #6b6f68; font-size: 16px; }
  .lg-evland__filters { display: flex; flex-wrap: wrap; gap: 8px; margin: 0 0 28px; }
  .lg-evland__chip { display: inline-block; padding: 6px 14px; border-radius: 999px; border: 1px solid #d4e0b8; background: #fbfbf8; color: #323532; text-decoration: none; font: 600 13px/1 var(--lg-font-sans, system-ui); }
  .lg-evland__chip:hover { border-color: #99b27e; }
  .lg-evland__chip--active { background: #87986a; border-color: #87986a; color: #fff; }
  .lg-evland__section { margin: 0 0 40px; }
  .lg-evland__section-h { margin: 0 0 16px; font: 700 13px/1 var(--lg-font-sans, system-ui); letter-spacing: 0.12em; text-transform: uppercase; color: #b8842b; }
  .lg-evland__grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 18px; }
  .lg-evland__card { display: flex; flex-direction: column; background: #fff; border: 1px solid #e5e7e0; border-radius: 10px; overflow: hidden; text-decoration: none; color: inherit; transition: box-shadow .15s, transform .15s; }
  .lg-evland__card:hover { box-shadow: 0 4px 16px rgba(0,0,0,0.08); transform: translateY(-2px); }
  .lg-evland__thumb { position: relative; aspect-ratio: 16/9; background: #f3f1ea center/cover no-repeat; }
  .lg-evland__pill { position: absolute; top: 12px; left: 12px; display: flex; flex-direction: column; align-items: center; line-height: 1; background: rgba(255,255,255,0.95); border-radius: 6px; padding: 6px 10px; box-shadow: 0 1px 3px rgba(0,0,0,0.12); }
  .lg-evland__mon { font: 700 11px/1 var(--lg-font-sans, system-ui); letter-spacing: 0.1em; color: #b8842b; }
  .lg-evland__day { font: 700 20px/1.05 var(--lg-font-serif, Georgia, serif); color: #1a1d1a; }
  .lg-evland__body { padding: 14px 16px 16px; display: flex; flex-direction: column; gap: 6px; }
  .lg-evland__title { margin: 0; font: 700 18px/1.25 var(--lg-font-serif, Georgia, serif); color: #1a1d1a; }
  .lg-evland__when { margin: 0; font-size: 14px; color: #323532; }
  .lg-evland__meta { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; margin-top: 4px; font-size: 13px; }
  .lg-evland__region { color: #6b6f68; }
  .lg-evland__tier { padding: 2px 8px; border-radius: 999px; font-weight: 700; font-size: 11px; text-transform: uppercase; letter-spacing: 0.04em; }
  .lg-evland__tier--pro { background: #323532; color: #ecb351; }
  .lg-evland__tier--lite { background: #d4e0b8; color: #1a1d1a; }
  .lg-evland__cta { margin-left: auto; color: #87986a; font-weight: 700; }
  .lg-evland__empty { color: #6b6f68; font-style: italic; padding: 8px 0 16px; }
</style>
<?php wp_head(); ?>
</head>
<body <?php body_class( 'lg-events-landing-page' ); ?>>

<?php lg_shared_render_site_header( $lg_ctx ); ?>

<main id="lg-main" class="lg-evland">
	<h1 class="lg-evland__head">Events</h1>
	<p class="lg-evland__sub">Live builds, clinics, and community calls. Click any event for details and the join link.</p>

	<div class="lg-evland__filters">
		<a class="lg-evland__chip <?php echo $active_region === '' ? 'lg-evland__chip--active' : ''; ?>" href="<?php echo $esc( $base_url ); ?>">All regions</a>
		<?php foreach ( $regions as $slug => $name ) : ?>
			<a class="lg-evland__chip <?php echo $active_region === $slug ? 'lg-evland__chip--active' : ''; ?>"
			   href="<?php echo $esc( add_query_arg( 'ev_region', $slug, $base_url ) ); ?>"><?php echo $esc( $name ); ?></a>
		<?php endforeach; ?>
	</div>

	<section class="lg-evland__section">
		<h2 class="lg-evland__section-h">Upcoming</h2>
		<?php $render_bucket( false, $active_region ); ?>
	</section>

	<section class="lg-evland__section">
		<h2 class="lg-evland__section-h">Past events</h2>
		<?php $render_bucket( true, $active_region ); ?>
	</section>
</main>

<?php lg_shared_render_site_footer( [ 'logo_url' => $lg_ctx['logo_url'] ] ); ?>

<?php wp_footer(); ?>
</body>
</html>
