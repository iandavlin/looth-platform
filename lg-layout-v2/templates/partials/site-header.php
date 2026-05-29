<?php
/**
 * v2 site header — visually parallel to BuddyBoss's header but with zero
 * BB CSS dependency. Reuses the same nav-menu locations the parent theme
 * registered, BP's native count helpers for the message/notification
 * badges, and inline SVG icons (no `bb-icons` font).
 *
 * Slots:
 *   - Logo                       — custom_logo() or site name
 *   - Primary nav                — wp_nav_menu('header-menu' / '-logout')
 *   - Search button              — opens an inline search bar (CSS-only)
 *   - Messages icon + count      — logged-in
 *   - Notifications icon + count — logged-in
 *   - Avatar + sign-out / Sign in
 *
 * The header is sticky at top: 0 and consumes its own height; the v2
 * article + post-header sit below. CSS lives in assets/lg-site-header.css.
 */

if (!defined('ABSPATH')) exit;

$is_logged_in = is_user_logged_in();
$user         = $is_logged_in ? wp_get_current_user() : null;

/* Counts are lazy-loaded by lg-site-header.js — server renders the badges
   as hidden placeholders so initial page render skips the BP/DB hit and
   the BP component boot. JS fills them after DOMContentLoaded. */

/* Logo: custom logo image if set, else site name as text. */
$logo_id   = function_exists('get_theme_mod') ? (int) get_theme_mod('custom_logo') : 0;
$home_url  = esc_url(home_url('/'));
$site_name = (string) get_bloginfo('name');
?>
<header class="lg-site-header" data-lg-site-header>
  <div class="lg-site-header__inner">
    <button type="button" class="lg-site-header__icon-btn lg-site-header__hamburger" data-lg-mobile-toggle aria-label="Menu">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M3 12h18M3 18h18"/></svg>
    </button>
    <a class="lg-site-header__logo" href="<?= $home_url ?>" rel="home" aria-label="<?= esc_attr($site_name) ?> home">
      <?php if ($logo_id && function_exists('wp_get_attachment_image')) {
          echo wp_get_attachment_image($logo_id, 'medium', false, ['class' => 'lg-site-header__logo-img', 'alt' => $site_name]);
      } else {
          echo '<span class="lg-site-header__logo-text">' . esc_html($site_name) . '</span>';
      } ?>
    </a>

    <nav class="lg-site-header__nav" aria-label="Primary">
      <?php wp_nav_menu([
          'theme_location' => $is_logged_in ? 'header-menu' : 'header-menu-logout',
          'container'      => false,
          'menu_class'     => 'lg-site-header__menu',
          'depth'          => 2,
          'fallback_cb'    => false,
      ]); ?>
    </nav>

    <div class="lg-site-header__aside">
      <button type="button" class="lg-site-header__icon-btn" data-lg-search-toggle aria-label="Search">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.35-4.35"/></svg>
      </button>

      <?php if ($is_logged_in): ?>
        <?php
        /* BuddyPress/BuddyBoss messages + notifications live under the
           logged-in user's BP member domain (/members/<slug>/messages/),
           not /messages/. home_url('/messages/') 404s on a normal BP
           install. Fall back to /members/ in the unlikely case the BP
           functions aren't available. */
        $bp_root      = function_exists('bp_loggedin_user_domain') ? bp_loggedin_user_domain() : home_url('/members/');
        $messages_url = $bp_root . 'messages/';
        $notifs_url   = $bp_root . 'notifications/';
        ?>
        <a class="lg-site-header__icon-btn" href="<?= esc_url($messages_url) ?>" aria-label="Messages">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2z"/><path d="M22 6l-10 7L2 6"/></svg>
          <span class="lg-site-header__badge lg-site-header__badge--zero" data-lg-msg-count hidden>0</span>
        </a>

        <a class="lg-site-header__icon-btn" href="<?= esc_url($notifs_url) ?>" aria-label="Notifications">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
          <span class="lg-site-header__badge lg-site-header__badge--zero" data-lg-notif-count hidden>0</span>
        </a>

        <a class="lg-site-header__user-link"
           href="<?= esc_url(function_exists('bp_loggedin_user_domain') ? bp_loggedin_user_domain() . 'profile/edit/' : get_edit_profile_url($user->ID)) ?>"
           aria-label="Edit <?= esc_attr($user->display_name) ?>'s profile"
           title="Edit profile">
          <?= get_avatar($user->ID, 40, '', '', ['class' => 'lg-site-header__avatar']) ?>
        </a>
      <?php else: ?>
        <a class="lg-site-header__signin" href="<?= esc_url(wp_login_url(get_permalink())) ?>">Sign in</a>
      <?php endif; ?>
    </div>
  </div>

  <div class="lg-site-header__search" hidden data-lg-search-panel>
    <form role="search" method="get" action="<?= $home_url ?>">
      <input type="search" name="s" class="lg-site-header__search-input" placeholder="Search…" aria-label="Search" />
    </form>
  </div>

  <div class="lg-site-header__mobile" hidden data-lg-mobile-panel>
    <?php wp_nav_menu([
        'theme_location' => $is_logged_in ? 'mobile-menu-logged-in' : 'mobile-menu-logged-out',
        'container'      => false,
        'menu_class'     => 'lg-site-header__mobile-menu',
        'depth'          => 2,
        'fallback_cb'    => false,
    ]); ?>
  </div>
</header>
