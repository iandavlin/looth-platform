/* Looth PWA bootstrap — service-worker registration + mobile-only install banner.
   Loaded site-wide via a single <script src="/pwa.js" defer> injection. */
(function () {
  'use strict';
  if (window.__loothPwa) return;
  window.__loothPwa = true;

  // Embedded in an iframe (e.g. the §4c comments modal) — the app-shell
  // (bottom nav, shop bubble, install banner, theming) belongs to the top-level
  // page only; loading it inside the iframe leaks chrome into the modal.
  if (window.top !== window.self) return;

  // Register the service worker on every viewport (needed for installability).
  if ('serviceWorker' in navigator) {
    window.addEventListener('load', function () {
      navigator.serviceWorker.register('/sw.js', { scope: '/' }).catch(function () {});
    });
  }

  // Load the user settings engine FIRST (color theme / webfont / text size from
  // localStorage, applied site-wide). Earliest so a picked theme paints with
  // minimal flash; defaults apply no override, so most users see no change.
  (function () {
    if (document.getElementById('looth-app-settings-js')) return;
    var s = document.createElement('script');
    s.id = 'looth-app-settings-js';
    s.src = '/app-settings.js?v=31';
    (document.head || document.documentElement).appendChild(s);
  })();

  // Load the marketplace shop bubble (separate concern; runs on every viewport,
  // so it must be kicked off before the mobile-only install-banner bail-out below).
  (function () {
    if (document.getElementById('looth-shop-js')) return;
    var s = document.createElement('script');
    s.id = 'looth-shop-js';
    s.src = '/shop-bubble.js?v=20';
    s.defer = true;
    (document.head || document.documentElement).appendChild(s);
  })();

  // Load the mobile bottom tab bar (Hub/Events/Members/Shop). Self-contained;
  // shows only on mobile viewports and hides the floating Shop FAB there.
  (function () {
    if (document.getElementById('looth-tabbar-js')) return;
    var s = document.createElement('script');
    s.id = 'looth-tabbar-js';
    s.src = '/bottom-nav.js?v=21';
    s.defer = true;
    (document.head || document.documentElement).appendChild(s);
  })();

  // Mobile Hub behaviors (≤640px): killCompactOnMobile + long-press → the SHARED
  // .fcr-palette reaction picker (forums.js). Behaviors only — the mobile LOOK is
  // mobile-hub.css (<head> <link media>). Mobile/desktop split. OWNER: Buck.
  (function () {
    if (document.getElementById('looth-mobile-hub-js')) return;
    if (!window.matchMedia || !window.matchMedia('(max-width:640px)').matches) return;
    var s = document.createElement('script');
    s.id = 'looth-mobile-hub-js';
    s.src = '/mobile-hub.js?v=3';
    s.defer = true;
    (document.head || document.documentElement).appendChild(s);
  })();


  // Load the Loothalong CTA (pinned banner at the top of the Events page).
  // Self-contained; path-gates to /events/ only. No gated Zoom URL in client.
  (function () {
    if (document.getElementById("looth-loothalong-js")) return;
    var s = document.createElement("script");
    s.id = "looth-loothalong-js";
    s.src = "/loothalong.js?v=4";
    s.defer = true;
    (document.head || document.documentElement).appendChild(s);
  })();

  // Load the events LIVE-NOW surfacing (forward-compatible; no-ops until the
  // events listing emits per-card start/end timestamps). No gated URL in client.
  (function () {
    if (document.getElementById("looth-events-live-js")) return;
    var s = document.createElement("script");
    s.id = "looth-events-live-js";
    s.src = "/events-live.js?v=1";
    s.defer = true;
    (document.head || document.documentElement).appendChild(s);
  })();

  // Load site-wide mobile layout guards (fixes shared-footer horizontal overflow
  // on phones; CSS-only, no-op once the canonical site-header.css media query lands).
  (function () {
    if (document.getElementById("looth-mobile-fixes-js")) return;
    var s = document.createElement("script");
    s.id = "looth-mobile-fixes-js";
    s.src = "/app-mobile-fixes.js?v=28";
    s.defer = true;
    (document.head || document.documentElement).appendChild(s);
  })();

  // Load Hub feed visual polish (app-card feed + richer hero on the Hub landing;
  // CSS layers over forums.css via source order, path-gated to /hub, no-op elsewhere).
  (function () {
    if (document.getElementById("looth-hub-polish-js")) return;
    var s = document.createElement("script");
    s.id = "looth-hub-polish-js";
    s.src = "/hub-polish.js?v=177";
    s.async = false;   // ordered, earliest execution (dynamic-script defer is a no-op = async)
    (document.head || document.documentElement).appendChild(s);
  })();

  // Load Hub infinite scroll (auto-append older feed items at the bottom so the
  // Hub feels endless). Reuses the server offset pagination; path-gated to /hub.
  (function () {
    if (document.getElementById("looth-hub-infinite-js")) return;
    var s = document.createElement("script");
    s.id = "looth-hub-infinite-js";
    s.src = "/hub-infinite.js?v=3";
    s.defer = true;
    (document.head || document.documentElement).appendChild(s);
  })();

  // Load the map-first mobile directory layer (full-stage map + draggable
  // results sheet + Filters sheet). Path-gated to /directory, ≤640 only; a
  // no-op on desktop and elsewhere. Buck-owned client layer.
  (function () {
    if (document.getElementById("looth-dir-mobile-js")) return;
    var s = document.createElement("script");
    s.id = "looth-dir-mobile-js";
    s.src = "/directory-mobile.js?v=11";
    s.defer = true;
    (document.head || document.documentElement).appendChild(s);
  })();

  // Load the Google-Maps-style desktop directory layer (two-pane: left results
  // column + full-height map, single floating search bar + Filters popover,
  // hover-sync). Path-gated to /directory, >=641 only; a no-op on mobile and
  // elsewhere. Buck-owned client layer.
  (function () {
    if (document.getElementById("looth-dir-desktop-js")) return;
    var s = document.createElement("script");
    s.id = "looth-dir-desktop-js";
    s.src = "/directory-desktop.js?v=9";
    s.defer = true;
    (document.head || document.documentElement).appendChild(s);
  })();

  // Load the mobile event-details popup (tapping an event card opens an inline
  // sheet instead of the desktop /event/ page). Path-gated to /events, <=640.
  (function () {
    if (document.getElementById("looth-events-mobile-js")) return;
    var s = document.createElement("script");
    s.id = "looth-events-mobile-js";
    s.src = "/events-mobile.js?v=7";
    s.defer = true;
    (document.head || document.documentElement).appendChild(s);
  })();

  // Load the mobile business/practice popup sheet (tapping a /p/<slug> link — the
  // bizpill on a profile, a practice row, a directory link — opens the business in
  // an app-style sheet instead of the desktop /p/ page). Self-gates to <=640.
  (function () {
    if (document.getElementById("looth-prac-sheet-js")) return;
    var s = document.createElement("script");
    s.id = "looth-prac-sheet-js";
    s.src = "/practice-sheet.js?v=2";
    s.defer = true;
    (document.head || document.documentElement).appendChild(s);
  })();

  // Load the mobile profile popup sheet (tapping a profile link or "View
  // profile" opens the person's profile in an app-style sheet instead of the
  // desktop /u/ page). Site-wide loader; the script self-gates to <=640.
  (function () {
    if (document.getElementById("looth-prof-sheet-js")) return;
    var s = document.createElement("script");
    s.id = "looth-prof-sheet-js";
    s.src = "/profile-sheet.js?v=6";
    s.defer = true;
    (document.head || document.documentElement).appendChild(s);
  })();

  // Load the Messenger-style DM pull-up (chats home + conversation view over
  // the canonical /me/messages API; supersedes the shared social modal on
  // mobile). Site-wide loader; the script self-gates to <=640 + logged-in use.
  (function () {
    if (document.getElementById("looth-msgr-js")) return;
    var s = document.createElement("script");
    s.id = "looth-msgr-js";
    s.src = "/messenger-sheet.js?v=1";
    s.defer = true;
    (document.head || document.documentElement).appendChild(s);
  })();

  // Load the mobile sponsor popup sheet (tapping the Sponsors nav or an
  // individual sponsor opens it in an app-style bottom sheet instead of the
  // full page). Site-wide loader; the script self-gates to <=640.
  (function () {
    if (document.getElementById("looth-spon-sheet-js")) return;
    var s = document.createElement("script");
    s.id = "looth-spon-sheet-js";
    s.src = "/sponsor-sheet.js?v=2";
    s.defer = true;
    (document.head || document.documentElement).appendChild(s);
  })();


  // Load Web Push opt-in + subscription sync (runs in the installed app too,
  // so it must sit BEFORE the mobile-only install-banner bail-out below).
  (function () {
    if (document.getElementById("looth-push-js")) return;
    var s = document.createElement("script");
    s.id = "looth-push-js";
    s.src = "/push.js?v=1";
    s.defer = true;
    (document.head || document.documentElement).appendChild(s);
  })();

  // Retire the dead Kick-era "Stream" nav item from the shared header, site-wide.
  // The streaming integration is gone; "Archive" stays for reference. Client-side
  // removal because the canonical header (site-header.php) is coordinator-owned.
  (function () {
    function dropStreamNav() {
      var links = document.querySelectorAll('.lg-chrome__menu a[href^="/stream"]');
      for (var i = 0; i < links.length; i++) {
        var li = links[i].closest('li');
        (li || links[i]).remove();
      }
    }
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', dropStreamNav);
    } else {
      dropStreamNav();
    }
  })();

  var DISMISS_KEY = 'looth_pwa_install_dismissed';
  var ua = navigator.userAgent || '';
  var isStandalone = window.matchMedia('(display-mode: standalone)').matches ||
                     window.navigator.standalone === true;
  // Mobile-only gate: narrow viewport AND a coarse (touch) pointer.
  var isMobile = window.matchMedia('(max-width: 640px)').matches &&
                 window.matchMedia('(pointer: coarse)').matches;
  var isIOS = /iphone|ipad|ipod/i.test(ua) && !window.MSStream;
  var isiOSSafari = isIOS && /safari/i.test(ua) && !/crios|fxios|edgios/i.test(ua);

  function dismissed() {
    try { return localStorage.getItem(DISMISS_KEY) === '1'; } catch (e) { return false; }
  }
  function setDismissed() {
    try { localStorage.setItem(DISMISS_KEY, '1'); } catch (e) {}
  }

  // Bail entirely unless we're on mobile, not already installed, not dismissed.
  if (isStandalone || !isMobile || dismissed()) return;

  var deferredPrompt = null;

  function injectStyles() {
    if (document.getElementById('looth-pwa-style')) return;
    var css =
      '#looth-pwa-banner{position:fixed;left:12px;right:12px;bottom:12px;z-index:2147483000;' +
      'background:var(--lg-cream,#fbfbf8);color:var(--lg-ink,#323532);border:1px solid var(--lg-line,#e3ddd0);' +
      'border-radius:16px;box-shadow:0 6px 24px rgba(26,29,26,.18);padding:14px 14px 14px 16px;' +
      'display:flex;align-items:center;gap:12px;' +
      'font:15px/1.4 system-ui,-apple-system,Segoe UI,Roboto,sans-serif;animation:looth-pwa-up .28s ease}' +
      '@keyframes looth-pwa-up{from{transform:translateY(120%);opacity:0}to{transform:translateY(0);opacity:1}}' +
      '#looth-pwa-banner img{width:40px;height:40px;border-radius:10px;flex:0 0 auto}' +
      '#looth-pwa-banner .lpw-tx{flex:1 1 auto;min-width:0}' +
      '#looth-pwa-banner .lpw-tl{font-weight:600;color:var(--lg-charcoal,#1a1d1a)}' +
      '#looth-pwa-banner .lpw-sb,#looth-pwa-banner .lpw-ios{font-size:13px;color:var(--lg-mute,#6b6f6b);margin-top:1px}' +
      '#looth-pwa-banner .lpw-ios b{color:var(--lg-ink,#323532)}' +
      '#looth-pwa-banner .lpw-act{display:flex;align-items:center;gap:4px;flex:0 0 auto}' +
      '#looth-pwa-banner button{font:inherit;cursor:pointer;border-radius:10px;border:0}' +
      '#looth-pwa-banner .lpw-install,#looth-pwa-banner .lpw-how{background:var(--lg-sage,#87986a);color:#fff;font-weight:600;padding:9px 14px;white-space:nowrap}' +
      '#looth-pwa-banner .lpw-install:active,#looth-pwa-banner .lpw-how:active{background:var(--lg-sage-d,#6b7c52)}' +
      '#looth-pwa-banner .lpw-x{background:transparent;color:var(--lg-mute,#6b6f6b);padding:8px 8px;font-size:20px;line-height:1}' +
      /* iOS step-by-step "Add to Home Screen" sheet (Buck 2026-06-08: make it super easy) */
      '#looth-ios-sheet{position:fixed;inset:0;z-index:2147483600;display:none}' +
      '#looth-ios-sheet.is-open{display:block}' +
      '#looth-ios-sheet .lis-back{position:absolute;inset:0;background:rgba(26,29,26,.55)}' +
      '#looth-ios-sheet .lis-card{position:absolute;left:10px;right:10px;bottom:10px;background:var(--lg-cream,#fbfbf8);' +
        'border-radius:18px;padding:18px 16px 16px;box-shadow:0 -8px 30px rgba(26,29,26,.32);' +
        'font:15px/1.45 system-ui,-apple-system,Segoe UI,Roboto,sans-serif;color:var(--lg-ink,#323532);' +
        'animation:looth-pwa-up .26s ease}' +
      '#looth-ios-sheet .lis-h{display:flex;align-items:center;gap:11px;margin-bottom:6px}' +
      '#looth-ios-sheet .lis-h img{width:38px;height:38px;border-radius:9px;flex:0 0 auto}' +
      '#looth-ios-sheet .lis-h .t{font:700 17px/1.2 var(--lg-font-serif,Georgia,serif);color:var(--lg-charcoal,#1a1d1a)}' +
      '#looth-ios-sheet .lis-h .s{font-size:13px;color:var(--lg-mute,#6b6f6b);margin-top:1px}' +
      '#looth-ios-sheet .lis-x{position:absolute;top:10px;right:12px;background:transparent;border:0;color:var(--lg-mute,#6b6f6b);font-size:24px;line-height:1;cursor:pointer;padding:4px 8px}' +
      '#looth-ios-sheet .lis-step{display:flex;align-items:center;gap:12px;padding:11px 2px;border-top:1px solid var(--lg-line,#e3ddd0)}' +
      '#looth-ios-sheet .lis-step:first-of-type{margin-top:8px}' +
      '#looth-ios-sheet .lis-n{flex:0 0 auto;width:24px;height:24px;border-radius:50%;background:var(--lg-sage,#87986a);color:#fff;font:700 13px/24px system-ui;text-align:center}' +
      '#looth-ios-sheet .lis-ic{flex:0 0 auto;width:30px;height:30px;display:flex;align-items:center;justify-content:center;color:var(--lg-sage-d,#6b7c52);background:var(--lg-sage-tint,#eef2e3);border-radius:8px}' +
      '#looth-ios-sheet .lis-ic svg{width:20px;height:20px}' +
      '#looth-ios-sheet .lis-tx{flex:1 1 auto;min-width:0}' +
      '#looth-ios-sheet .lis-tx b{color:var(--lg-charcoal,#1a1d1a)}' +
      '#looth-ios-sheet .lis-done{display:block;width:100%;margin-top:15px;background:var(--lg-sage,#87986a);color:#fff;' +
        'font:600 15px/1 system-ui;border:0;border-radius:12px;padding:14px;cursor:pointer}' +
      '#looth-ios-sheet .lis-done:active{background:var(--lg-sage-d,#6b7c52)}';
    var s = document.createElement('style');
    s.id = 'looth-pwa-style';
    s.textContent = css;
    (document.head || document.documentElement).appendChild(s);
  }

  function removeBanner() {
    var b = document.getElementById('looth-pwa-banner');
    if (b && b.parentNode) b.parentNode.removeChild(b);
  }

  function showBanner(mode) {
    if (document.getElementById('looth-pwa-banner') || dismissed()) return;
    injectStyles();
    var el = document.createElement('div');
    el.id = 'looth-pwa-banner';
    el.setAttribute('role', 'dialog');
    el.setAttribute('aria-label', 'Install the Looth app');
    var icon = '<img src="/icons/icon-192.png" alt="">';
    if (mode === 'ios') {
      el.innerHTML = icon +
        '<div class="lpw-tx"><div class="lpw-tl">Install Looth</div>' +
        '<div class="lpw-sb">Add the app to your Home Screen</div></div>' +
        '<div class="lpw-act"><button class="lpw-how" type="button">Show me how</button>' +
        '<button class="lpw-x" type="button" aria-label="Dismiss">&times;</button></div>';
    } else {
      el.innerHTML = icon +
        '<div class="lpw-tx"><div class="lpw-tl">Install Looth</div>' +
        '<div class="lpw-sb">Add it to your home screen</div></div>' +
        '<div class="lpw-act"><button class="lpw-install" type="button">Install</button>' +
        '<button class="lpw-x" type="button" aria-label="Dismiss">&times;</button></div>';
    }
    (document.body || document.documentElement).appendChild(el);

    var x = el.querySelector('.lpw-x');
    if (x) x.addEventListener('click', function () { setDismissed(); removeBanner(); });

    var inst = el.querySelector('.lpw-install');
    if (inst) inst.addEventListener('click', function () {
      if (!deferredPrompt) { removeBanner(); return; }
      deferredPrompt.prompt();
      deferredPrompt.userChoice.then(function (res) {
        if (res && res.outcome === 'accepted') setDismissed();
        deferredPrompt = null;
        removeBanner();
      });
    });

    var how = el.querySelector('.lpw-how');
    if (how) how.addEventListener('click', showIosSheet);
  }

  // iOS-only step-by-step "Add to Home Screen" sheet — Safari has no install prompt,
  // so spell it out with the real icons (Buck 2026-06-08: make it super easy).
  function showIosSheet() {
    injectStyles();
    if (document.getElementById('looth-ios-sheet')) {
      document.getElementById('looth-ios-sheet').classList.add('is-open'); return;
    }
    var shareIco = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 15V3"/><path d="m8 7 4-4 4 4"/><path d="M5 12v7a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-7"/></svg>';
    var addIco   = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="4" width="16" height="16" rx="4.5"/><path d="M12 9v6M9 12h6"/></svg>';
    var doneIco  = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>';
    var s = document.createElement('div');
    s.id = 'looth-ios-sheet';
    s.setAttribute('role', 'dialog');
    s.setAttribute('aria-label', 'Add Looth to your Home Screen');
    s.innerHTML =
      '<div class="lis-back" data-lis-close></div>' +
      '<div class="lis-card">' +
        '<button class="lis-x" type="button" aria-label="Close" data-lis-close>&times;</button>' +
        '<div class="lis-h"><img src="/icons/icon-192.png" alt="">' +
          '<div><div class="t">Add Looth to your Home Screen</div>' +
          '<div class="s">Takes 5 seconds — here’s how:</div></div></div>' +
        '<div class="lis-step"><span class="lis-n">1</span><span class="lis-ic">' + shareIco + '</span>' +
          '<span class="lis-tx">Tap the <b>Share</b> button in Safari’s toolbar (the box with an up-arrow, at the bottom).</span></div>' +
        '<div class="lis-step"><span class="lis-n">2</span><span class="lis-ic">' + addIco + '</span>' +
          '<span class="lis-tx">Scroll down and tap <b>Add to Home Screen</b>.</span></div>' +
        '<div class="lis-step"><span class="lis-n">3</span><span class="lis-ic">' + doneIco + '</span>' +
          '<span class="lis-tx">Tap <b>Add</b> — and Looth lands on your Home Screen like any app.</span></div>' +
        '<button class="lis-done" type="button" data-lis-close>Got it</button>' +
      '</div>';
    (document.body || document.documentElement).appendChild(s);
    requestAnimationFrame(function () { s.classList.add('is-open'); });
    s.addEventListener('click', function (e) {
      if (e.target.closest('[data-lis-close]')) s.classList.remove('is-open');
    });
  }

  // Chromium: intercept the native mini-infobar and show our own banner.
  window.addEventListener('beforeinstallprompt', function (e) {
    e.preventDefault();
    deferredPrompt = e;
    showBanner('install');
  });

  window.addEventListener('appinstalled', function () {
    setDismissed();
    deferredPrompt = null;
    removeBanner();
  });

  // iOS Safari has no beforeinstallprompt — surface manual instructions.
  if (isiOSSafari) {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', function () { showBanner('ios'); });
    } else {
      showBanner('ios');
    }
  }
})();
