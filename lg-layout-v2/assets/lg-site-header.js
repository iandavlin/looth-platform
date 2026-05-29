/* lg-site-header — interaction layer for the v2 cloned masthead.
   Search toggle, mobile-menu toggle, optional lazy-count poll. */

(function () {
  'use strict';
  var header = document.querySelector('[data-lg-site-header]');
  if (!header) return;

  /* ── Priority navigation ─────────────────────────────────────────
     BB's header uses a "more" button + sub-menu to handle menu items
     that overflow the available row width. Mirror that:
       - measure each top-level menu item
       - hide ones that won't fit, move them into a dropdown under "…"
       - re-run on resize
     The dropdown is built once and reused; we just toggle item parents. */

  var menu       = header.querySelector('.lg-site-header__menu');
  var aside      = header.querySelector('.lg-site-header__aside');
  var nav        = header.querySelector('.lg-site-header__nav');
  var moreItem   = null;
  var moreMenu   = null;
  var originalItems = [];

  function setupPriorityNav() {
    if (!menu) return;
    /* Snapshot the original list once. */
    originalItems = Array.prototype.slice.call(menu.children).filter(function (c) {
      return !c.classList.contains('lg-site-header__more');
    });

    moreItem = document.createElement('li');
    moreItem.className = 'lg-site-header__more';
    moreItem.innerHTML =
      '<a href="#" class="lg-site-header__more-btn" aria-label="More menu items">' +
        '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="18" height="18"><circle cx="5" cy="12" r="1.5"/><circle cx="12" cy="12" r="1.5"/><circle cx="19" cy="12" r="1.5"/></svg>' +
      '</a>' +
      '<ul class="sub-menu lg-site-header__more-menu"></ul>';
    moreMenu = moreItem.querySelector('.lg-site-header__more-menu');
    menu.appendChild(moreItem);
    /* Prevent the more-button anchor from navigating. */
    moreItem.querySelector('a').addEventListener('click', function (e) { e.preventDefault(); });

    layout();
  }

  function layout() {
    if (!menu || !nav || !aside) return;
    /* Start by restoring every original item back to the visible menu. */
    originalItems.forEach(function (li) {
      if (li.parentNode !== menu) menu.insertBefore(li, moreItem);
    });
    moreMenu.innerHTML = '';
    moreItem.style.display = 'none';

    /* Available width for the menu = nav-row width minus aside width. */
    var available = nav.clientWidth;
    /* Walk items in reverse, pushing overflowers into the more menu until
       the remaining ones + the more button fit. */
    var moreBtnWidth = 36;
    var idx = originalItems.length - 1;
    while (idx >= 0 && menu.scrollWidth > available) {
      var overflow = originalItems[idx];
      /* Move to dropdown. Wrap inside an <li> if not already. */
      var clone = overflow;
      menu.removeChild(clone);
      moreMenu.appendChild(clone);
      moreItem.style.display = '';
      idx--;
    }
  }

  /* ── Compact-on-scroll ───────────────────────────────────────────
     When scrolled past a small threshold, add .is-compact. CSS shrinks
     the header height + tightens the inner container so it feels
     "rolled up." Uses requestAnimationFrame throttling. */
  var ticking = false;
  var COMPACT_AT = 60;
  function onScroll() {
    if (ticking) return;
    ticking = true;
    window.requestAnimationFrame(function () {
      header.classList.toggle('is-compact', window.scrollY > COMPACT_AT);
      ticking = false;
    });
  }
  window.addEventListener('scroll', onScroll, { passive: true });
  onScroll();

  /* Re-layout priority nav on resize (debounced). */
  var resizeTimer;
  window.addEventListener('resize', function () {
    clearTimeout(resizeTimer);
    resizeTimer = setTimeout(layout, 100);
  });

  setupPriorityNav();

  /* Search panel toggle */
  var searchBtn   = header.querySelector('[data-lg-search-toggle]');
  var searchPanel = header.querySelector('[data-lg-search-panel]');
  if (searchBtn && searchPanel) {
    searchBtn.addEventListener('click', function (e) {
      e.preventDefault();
      var hidden = searchPanel.hasAttribute('hidden');
      if (hidden) {
        searchPanel.removeAttribute('hidden');
        var input = searchPanel.querySelector('input[type="search"]');
        if (input) input.focus();
      } else {
        searchPanel.setAttribute('hidden', '');
      }
    });
  }

  /* Mobile menu toggle */
  var mobileBtn   = header.querySelector('[data-lg-mobile-toggle]');
  var mobilePanel = header.querySelector('[data-lg-mobile-panel]');
  if (mobileBtn && mobilePanel) {
    mobileBtn.addEventListener('click', function (e) {
      e.preventDefault();
      if (mobilePanel.hasAttribute('hidden')) {
        mobilePanel.removeAttribute('hidden');
      } else {
        mobilePanel.setAttribute('hidden', '');
      }
    });
  }

  /* Lazy-load badge counts. Server renders empty badge placeholders so
     initial page render skips the BP queries + component boot. JS fetches
     once on load, then every 60s while the tab is visible. Anon bails. */
  var CFG = window.LG_SITE_HEADER;
  if (!CFG || !CFG.is_logged_in) return;

  function setBadge(selector, count) {
    var el = header.querySelector(selector);
    if (!el) return;
    if (count > 0) {
      el.textContent = count > 99 ? '99+' : String(count);
      el.removeAttribute('hidden');
      el.classList.remove('lg-site-header__badge--zero');
    } else {
      el.setAttribute('hidden', '');
      el.classList.add('lg-site-header__badge--zero');
    }
  }

  function refreshCounts() {
    if (document.hidden) return;
    /* Messages — total unread count via BP REST */
    fetch(CFG.rest_root + 'buddyboss/v1/messages?per_page=1&fields=ids', {
      credentials: 'include',
      headers: { 'X-WP-Nonce': CFG.nonce },
    }).then(function (r) {
      var unread = parseInt(r.headers.get('X-BP-Unread-Count') || '0', 10);
      if (!isNaN(unread)) setBadge('[data-lg-msg-count]', unread);
    }).catch(function () { /* silent — initial render still valid */ });

    fetch(CFG.rest_root + 'buddyboss/v1/notifications?per_page=1&is_new=1&fields=ids', {
      credentials: 'include',
      headers: { 'X-WP-Nonce': CFG.nonce },
    }).then(function (r) {
      var total = parseInt(r.headers.get('X-WP-Total') || '0', 10);
      if (!isNaN(total)) setBadge('[data-lg-notif-count]', total);
    }).catch(function () {});
  }

  /* First fetch right after page settle — defer 200ms so it doesn't
     compete with the rest of the first paint. */
  setTimeout(refreshCounts, 200);
  setInterval(refreshCounts, 60000);
  document.addEventListener('visibilitychange', function () {
    if (!document.hidden) refreshCounts();
  });
})();
