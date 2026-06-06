/* Looth PWA bootstrap — service-worker registration + mobile-only install banner.
   Loaded site-wide via a single <script src="/pwa.js" defer> injection. */
(function () {
  'use strict';
  if (window.__loothPwa) return;
  window.__loothPwa = true;

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
    s.src = '/app-settings.js?v=3';
    (document.head || document.documentElement).appendChild(s);
  })();

  // Load the marketplace shop bubble (separate concern; runs on every viewport,
  // so it must be kicked off before the mobile-only install-banner bail-out below).
  (function () {
    if (document.getElementById('looth-shop-js')) return;
    var s = document.createElement('script');
    s.id = 'looth-shop-js';
    s.src = '/shop-bubble.js?v=4';
    s.defer = true;
    (document.head || document.documentElement).appendChild(s);
  })();

  // Load the mobile bottom tab bar (Hub/Events/Members/Shop). Self-contained;
  // shows only on mobile viewports and hides the floating Shop FAB there.
  (function () {
    if (document.getElementById('looth-tabbar-js')) return;
    var s = document.createElement('script');
    s.id = 'looth-tabbar-js';
    s.src = '/bottom-nav.js?v=6';
    s.defer = true;
    (document.head || document.documentElement).appendChild(s);
  })();


  // Load the Loothalong CTA (pinned banner at the top of the Events page).
  // Self-contained; path-gates to /events/ only. No gated Zoom URL in client.
  (function () {
    if (document.getElementById("looth-loothalong-js")) return;
    var s = document.createElement("script");
    s.id = "looth-loothalong-js";
    s.src = "/loothalong.js?v=3";
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
    s.src = "/app-mobile-fixes.js?v=6";
    s.defer = true;
    (document.head || document.documentElement).appendChild(s);
  })();

  // Load Hub feed visual polish (app-card feed + richer hero on the Hub landing;
  // CSS layers over forums.css via source order, path-gated to /hub, no-op elsewhere).
  (function () {
    if (document.getElementById("looth-hub-polish-js")) return;
    var s = document.createElement("script");
    s.id = "looth-hub-polish-js";
    s.src = "/hub-polish.js?v=48";
    s.defer = true;
    (document.head || document.documentElement).appendChild(s);
  })();

  // Load Hub infinite scroll (auto-append older feed items at the bottom so the
  // Hub feels endless). Reuses the server offset pagination; path-gated to /hub.
  (function () {
    if (document.getElementById("looth-hub-infinite-js")) return;
    var s = document.createElement("script");
    s.id = "looth-hub-infinite-js";
    s.src = "/hub-infinite.js?v=1";
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
      '#looth-pwa-banner .lpw-install{background:var(--lg-sage,#87986a);color:#fff;font-weight:600;padding:9px 14px}' +
      '#looth-pwa-banner .lpw-install:active{background:var(--lg-sage-d,#6b7c52)}' +
      '#looth-pwa-banner .lpw-x{background:transparent;color:var(--lg-mute,#6b6f6b);padding:8px 8px;font-size:20px;line-height:1}';
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
        '<div class="lpw-ios">Tap <b>Share</b>, then <b>Add to Home Screen</b></div></div>' +
        '<div class="lpw-act"><button class="lpw-x" aria-label="Dismiss">&times;</button></div>';
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
