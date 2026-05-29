<?php
/**
 * /srv/lg-shared/site-header.php
 *
 * Shared site-header partial.  Include from any strangler surface:
 *
 *   require_once '/srv/lg-shared/site-header.php';
 *   lg_shared_render_site_header([
 *       'authenticated' => true,
 *       'tier'          => 'pro',       // 'public' | 'lite' | 'pro'
 *       'display_name'  => 'evan-gluck',
 *       'avatar_url'    => 'https://…/bpfull.jpg',   // optional
 *       'capabilities'  => [
 *           'manage_options'   => false,
 *           'edit_archive_poc' => false,
 *       ],
 *       'msg_unread'    => 0,   // optional; null → lazy-load via REST
 *       'notif_unread'  => 0,   // optional; null → lazy-load via REST
 *       // 'logo_url'   => 'https://…/logo.png',     // optional override
 *       // 'search_id'  => 'chrome-q',               // optional; id of the <input>
 *       // 'search_placeholder' => 'Search…',        // optional
 *       // 'profile_url'        => '/members/me/',   // optional; default /members/me/
 *       // 'logout_url'         => wp_logout_url(),  // optional; WP callers pass nonce'd URL
 *   ]);
 *
 * The caller is responsible for outputting the corresponding CSS:
 *   <link rel="stylesheet" href="/lg-shared/site-header.css">
 * (nginx maps /lg-shared/ → /srv/lg-shared/)
 *
 * The partial is intentionally dumb — it renders what it's handed.
 * Source-of-truth per consumer:
 *   archive-poc  → reads /whoami (lg_archive_poc_whoami())
 *   bb-mirror    → reads /whoami (lg_bb_mirror_whoami())
 *   lg-layout-v2 → reads $current_user in-process
 *
 * Companion:
 *   lg_shared_render_site_footer([
 *       'logo_url' => '…',   // optional
 *   ]);
 *
 * Guard: require_once safe (function_exists check on each function).
 */

declare(strict_types=1);

if (!function_exists('lg_shared_h')) {
    function lg_shared_h(string $s): string {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }
}

if (!function_exists('lg_shared_render_site_header')) {
/**
 * Render the shared site header.
 *
 * @param array{
 *   authenticated: bool,
 *   tier: string,
 *   display_name?: string,
 *   avatar_url?: string|null,
 *   capabilities?: array<string,bool>,
 *   msg_unread?: int|null,
 *   notif_unread?: int|null,
 *   logo_url?: string,
 *   search_id?: string,
 *   search_placeholder?: string,
 *   profile_url?: string,
 *   logout_url?: string,   // optional; WP callers pass wp_logout_url() for nonce'd URL
 *   before_nav?: string,   // raw HTML injected between logo and <nav> (e.g. archive-poc back-link)
 * } $ctx
 */
function lg_shared_render_site_header(array $ctx): void
{
    // ---------- unpack with sane defaults ----------
    $authenticated = (bool)($ctx['authenticated'] ?? false);
    $tier          = (string)($ctx['tier'] ?? 'public');
    $display_name  = (string)($ctx['display_name'] ?? '');
    $avatar_url    = isset($ctx['avatar_url']) ? (string)$ctx['avatar_url'] : null;
    $caps          = (array)($ctx['capabilities'] ?? []);
    $msg_unread    = $ctx['msg_unread']   ?? null;   // null = lazy-load
    $notif_unread  = $ctx['notif_unread'] ?? null;   // null = lazy-load
    $profile_url   = (string)($ctx['profile_url'] ?? '/members/me/');
    $logout_url    = (string)($ctx['logout_url']  ?? '/wp-login.php?action=logout');
    $search_id     = (string)($ctx['search_id'] ?? 'lg-chrome-q');
    $search_ph     = (string)($ctx['search_placeholder'] ?? 'Search…');
    $active_nav    = (string)($ctx['active_nav'] ?? '');  // slug: 'archive'|'forum'|'events'|'members'
    // Raw HTML injected between logo and nav — consumer responsibility to escape
    $before_nav    = $ctx['before_nav'] ?? null;

    // Logo: consumer may pass its own logo URL (env-specific); fall back to
    // a host-relative path so each environment serves its own copy and the
    // default never 404s due to pointing at the wrong host.
    $logo_url = (string)($ctx['logo_url'] ?? '/wp-content/uploads/2024/05/Looth-Group-Logo-Site-Menu.png');

    // ---------- derived display values ----------
    $manage_opts = ($caps['manage_options'] ?? false) === true;

    // Tier pill label: Admin overrides paid-tier labels for manage_options users.
    $tier_label = match($tier) {
        'lite' => 'Lite',
        'pro'  => 'Pro',
        default => null,
    };
    if ($manage_opts) $tier_label = 'Admin';

    // Avatar: initials fallback when no URL or URL is empty.
    $avatar_initial = $display_name !== '' ? strtoupper(mb_substr($display_name, 0, 1)) : '?';

    // Badge display helpers (null = hidden and lazy-loaded by JS; 0 = shown but empty)
    $msg_hidden   = $msg_unread   === null;
    $notif_hidden = $notif_unread === null;
    $msg_count    = (int)($msg_unread ?? 0);
    $notif_count  = (int)($notif_unread ?? 0);

    $h = 'lg_shared_h';

    ?>
<a href="#lg-main" class="skip-link">Skip to content</a>

<header class="lg-chrome" id="site-header">
  <div class="lg-chrome__inner">

    <button class="lg-chrome__hamburger" type="button"
            aria-label="Menu" aria-expanded="false" data-lg-mobile-toggle>
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
           stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <path d="M3 6h18M3 12h18M3 18h18"/>
      </svg>
    </button>

    <a class="lg-chrome__logo" href="/front-page/" rel="home" aria-label="Looth Group home">
      <img src="<?= $h($logo_url) ?>" alt="Looth Group" width="36" height="36">
      <span class="lg-chrome__wordmark">Looth Group</span>
    </a>

    <?php if ($before_nav !== null): ?>
      <?= $before_nav ?>
    <?php endif; ?>

    <nav class="lg-chrome__nav" aria-label="Primary">
      <ul class="lg-chrome__menu">
        <?php if ($active_nav !== 'archive'):  ?><li><a href="/archive/">Archive</a></li><?php endif; ?>
        <?php if ($active_nav !== 'forum'):   ?><li><a href="/forum/">Forum</a></li><?php endif; ?>
        <?php if ($active_nav !== 'events'):  ?><li><a href="/events/">Events</a></li><?php endif; ?>
        <?php if ($active_nav !== 'members'): ?><li><a href="/directory/members/">Members</a></li><?php endif; ?>
      </ul>
    </nav>

    <div class="lg-chrome__aside">

      <button class="lg-chrome__search-btn" type="button"
              aria-label="Search the archive" data-chrome-search>
        <svg viewBox="0 0 24 24" width="18" height="18" fill="none"
             stroke="currentColor" stroke-width="2"
             stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
        </svg>
      </button>

      <?php if ($authenticated): ?>

        <?php if ($manage_opts): ?>
          <a class="lg-chrome__edit" href="/wp-admin/" target="_blank" aria-label="WP Admin">
            <svg viewBox="0 0 24 24" width="14" height="14" fill="none"
                 stroke="currentColor" stroke-width="2.5"
                 stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
              <path d="M12 20h9"/>
              <path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/>
            </svg>
            Edit
          </a>
        <?php endif; ?>

        <a class="lg-chrome__icon-btn lg-chrome__icon-btn--badged"
           href="/members/me/messages/"
           aria-label="Messages"
           data-lg-msg-link>
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
               stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M4 4h16a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2z"/>
            <path d="M22 6l-10 7L2 6"/>
          </svg>
          <?php if (!$msg_hidden && $msg_count > 0): ?>
            <span class="lg-chrome__badge" data-lg-msg-count><?= $msg_count ?></span>
          <?php else: ?>
            <span class="lg-chrome__badge" data-lg-msg-count hidden>0</span>
          <?php endif; ?>
        </a>

        <a class="lg-chrome__icon-btn lg-chrome__icon-btn--badged"
           href="/members/me/notifications/"
           aria-label="Notifications"
           data-lg-notif-link>
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
               stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/>
            <path d="M13.73 21a2 2 0 0 1-3.46 0"/>
          </svg>
          <?php if (!$notif_hidden && $notif_count > 0): ?>
            <span class="lg-chrome__badge" data-lg-notif-count><?= $notif_count ?></span>
          <?php else: ?>
            <span class="lg-chrome__badge" data-lg-notif-count hidden>0</span>
          <?php endif; ?>
        </a>

        <!-- Account dropdown trigger -->
        <div class="lg-chrome__account-wrap" data-lg-account-wrap>
          <button class="lg-chrome__account" type="button"
                  aria-haspopup="true" aria-expanded="false"
                  aria-controls="lg-account-menu"
                  data-lg-account-btn>
            <span class="lg-chrome__avatar" aria-hidden="true">
              <?php if ($avatar_url !== null && $avatar_url !== ''): ?>
                <img src="<?= $h($avatar_url) ?>"
                     alt="<?= $h($display_name) ?>"
                     width="30" height="30">
              <?php else: ?>
                <?= $h($avatar_initial) ?>
              <?php endif; ?>
            </span>
            <span class="lg-chrome__account-name"><?= $h($display_name) ?></span>
            <?php if ($tier_label !== null): ?>
              <span class="lg-chrome__tier lg-chrome__tier--<?= $h(strtolower($tier_label)) ?>">
                <?= $h($tier_label) ?>
              </span>
            <?php endif; ?>
            <svg class="lg-chrome__account-caret" viewBox="0 0 24 24" width="12" height="12"
                 fill="none" stroke="currentColor" stroke-width="2.5"
                 stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
              <polyline points="6 9 12 15 18 9"/>
            </svg>
          </button>

          <ul class="lg-chrome__account-menu" id="lg-account-menu"
              role="menu" aria-label="Account menu" hidden>
            <li role="none">
              <a role="menuitem" href="<?= $h($profile_url) ?>">Edit Profile</a>
            </li>
            <li role="none">
              <a role="menuitem" href="/manage-subscription/">Manage Subscription</a>
            </li>
            <li role="none">
              <a role="menuitem" href="/membership-guide/">Membership Guide</a>
            </li>
            <li role="none">
              <a role="menuitem" href="/my-gifts/">My Gifts</a>
            </li>
            <li role="none">
              <a role="menuitem" href="/lggift-buy/">Gift Memberships</a>
            </li>
            <li role="none">
              <a role="menuitem" href="/lggift/">Redeem a Gift</a>
            </li>
            <li role="none">
              <a role="menuitem" href="/request-refund/">Request a Refund</a>
            </li>
            <li role="none">
              <a role="menuitem" href="/affiliate-earnings/">Affiliate Earnings</a>
            </li>
            <li role="none" class="lg-chrome__account-menu-divider"></li>
            <li role="none">
              <a role="menuitem" class="lg-chrome__account-menu-signout"
                 href="<?= $h($logout_url) ?>">Sign out</a>
            </li>
          </ul>
        </div><!-- .lg-chrome__account-wrap -->

      <?php else: ?>

        <a class="lg-chrome__signin" href="/wp-login.php">Sign in</a>
        <a class="lg-chrome__join" href="/lgjoin/">Join</a>

      <?php endif; ?>

    </div><!-- .lg-chrome__aside -->
  </div><!-- .lg-chrome__inner -->
</header>

<script>
(function () {
  /* Mobile nav toggle */
  var btn = document.querySelector('[data-lg-mobile-toggle]');
  var hdr = document.getElementById('site-header');
  if (btn && hdr) {
    btn.addEventListener('click', function () {
      var open = hdr.hasAttribute('data-mobile-open');
      if (open) {
        hdr.removeAttribute('data-mobile-open');
        btn.setAttribute('aria-expanded', 'false');
      } else {
        hdr.setAttribute('data-mobile-open', '');
        btn.setAttribute('aria-expanded', 'true');
      }
    });
  }

  /* Search — archive.js hooks [data-chrome-search] on the archive page and
     opens the archive search modal. On all other pages, fall back to
     navigating to the archive. */
  var searchBtn = document.querySelector('[data-chrome-search]');
  if (searchBtn) {
    searchBtn.addEventListener('click', function () {
      if (!document.getElementById('search-modal')) {
        window.location.href = '/archive-poc/#search';
      }
    });
  }

  /* Account dropdown */
  var accountBtn  = document.querySelector('[data-lg-account-btn]');
  var accountMenu = document.getElementById('lg-account-menu');
  var accountWrap = document.querySelector('[data-lg-account-wrap]');

  function closeAccountMenu() {
    if (!accountMenu || !accountBtn) return;
    accountMenu.hidden = true;
    accountBtn.setAttribute('aria-expanded', 'false');
    accountWrap && accountWrap.removeAttribute('data-open');
  }

  function openAccountMenu() {
    if (!accountMenu || !accountBtn) return;
    accountMenu.hidden = false;
    accountBtn.setAttribute('aria-expanded', 'true');
    accountWrap && accountWrap.setAttribute('data-open', '');
    // Focus first item for keyboard nav
    var first = accountMenu.querySelector('[role="menuitem"]');
    if (first) first.focus();
  }

  if (accountBtn && accountMenu) {
    accountBtn.addEventListener('click', function (e) {
      e.stopPropagation();
      if (accountMenu.hidden) {
        openAccountMenu();
      } else {
        closeAccountMenu();
      }
    });

    // Close on outside click
    document.addEventListener('click', function (e) {
      if (accountWrap && !accountWrap.contains(e.target)) {
        closeAccountMenu();
      }
    });

    // Close on Escape
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && !accountMenu.hidden) {
        closeAccountMenu();
        accountBtn.focus();
      }
    });

    // Arrow key navigation within menu
    accountMenu.addEventListener('keydown', function (e) {
      var items = Array.prototype.slice.call(
        accountMenu.querySelectorAll('[role="menuitem"]')
      );
      var idx = items.indexOf(document.activeElement);
      if (e.key === 'ArrowDown') {
        e.preventDefault();
        var next = items[(idx + 1) % items.length];
        if (next) next.focus();
      } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        var prev = items[(idx - 1 + items.length) % items.length];
        if (prev) prev.focus();
      }
    });
  }
})();
</script>
<?php
} // end function
} // end if !function_exists
