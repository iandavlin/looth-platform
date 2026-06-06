/* Looth bottom tab bar — iOS-style mobile navigation.
   Self-contained: injects its own styles + a fixed bottom bar with 5 tabs
   (Hub, Events, Members, Shop, Profile). Shown only on mobile viewports; on desktop it
   stays out of the way. The Shop tab reuses the existing shop drawer
   (#looth-shop-fab) instead of a separate page, and the floating Shop FAB is
   hidden on mobile so the two don't duplicate.
   Loaded site-wide via /pwa.js (already injected into every <head>). */
(function () {
  'use strict';
  if (window.__loothTabbar) return;
  window.__loothTabbar = true;

  var MOBILE_MQ = '(max-width: 640px)';
  var BAR_ID = 'looth-tabbar';
  var STYLE_ID = 'looth-tabbar-style';

  // Inline stroke icons (24x24, currentColor). No emoji per brand rules.
  var ICONS = {
    hub: '<path d="M3 10.5 12 3l9 7.5"/><path d="M5 9.5V20h5v-6h4v6h5V9.5"/>',
    events: '<rect x="3" y="4.5" width="18" height="16" rx="2.5"/><path d="M3 9h18"/><path d="M8 2.5v4M16 2.5v4"/>',
    members: '<circle cx="9" cy="8" r="3.2"/><path d="M3.5 19.5c0-3 2.6-5 5.5-5s5.5 2 5.5 5"/><path d="M16 5.6a3 3 0 0 1 0 5.4"/><path d="M17.5 14.6c2 .5 3.5 2.2 3.5 4.9"/>',
    shop: '<path d="M6 8h12l-1 12H7L6 8z"/><path d="M9 8V6a3 3 0 0 1 6 0v2"/>',
    // Fallback only — the Profile tab shows the member's avatar when one exists.
    person: '<circle cx="12" cy="8.5" r="3.8"/><path d="M5 20c0-3.6 3-6 7-6s7 2.4 7 6"/>'
  };

  // Tab definitions. `match` decides the active tab from the current path.
  var TABS = [
    { key: 'hub',     label: 'Hub',     href: '/hub/',                icon: ICONS.hub,
      match: function (p) { return p === '/' || /^\/(hub|stream|archive)(\/|$)/.test(p); } },
    { key: 'events',  label: 'Events',  href: '/events/',             icon: ICONS.events,
      match: function (p) { return /^\/events(\/|$)/.test(p); } },
    { key: 'members', label: 'Members', href: '/directory/members/',  icon: ICONS.members,
      match: function (p) { return /^\/(directory|members)(\/|$)/.test(p); } },
    { key: 'shop',    label: 'Shop',    href: null,                   icon: ICONS.shop,
      shop: true, match: function () { return false; } },
    // Instagram-style: the member's own avatar is the tab; tapping opens their profile.
    { key: 'profile', label: 'You',     href: '/profile/edit',        icon: ICONS.person,
      profile: true, match: function (p) { return /^\/(profile|u)(\/|$)/.test(p); } }
  ];

  // Pull the signed-in member's avatar from the shared header (.lg-chrome__avatar
  // img). Returns the src, or '' when the header only shows a text initial.
  function avatarSrc() {
    var img = document.querySelector('.lg-chrome__avatar img, .lg-chrome__account img');
    var src = img && (img.currentSrc || img.getAttribute('src'));
    return src || '';
  }

  function injectStyles() {
    if (document.getElementById(STYLE_ID)) return;
    var H = 54; // bar content height (px); safe-area added on top
    var css =
      '#' + BAR_ID + '{position:fixed;left:0;right:0;bottom:0;z-index:2147481200;' +
      'display:none;align-items:stretch;justify-content:space-around;' +
      'height:calc(' + H + 'px + env(safe-area-inset-bottom,0px));' +
      'padding-bottom:env(safe-area-inset-bottom,0px);' +
      'background:rgba(251,251,248,.92);-webkit-backdrop-filter:saturate(1.6) blur(14px);' +
      'backdrop-filter:saturate(1.6) blur(14px);border-top:1px solid var(--lg-line,#e3ddd0);' +
      'box-shadow:0 -1px 12px rgba(26,29,26,.06);' +
      'font:11px/1 system-ui,-apple-system,Segoe UI,Roboto,sans-serif}' +
      // each tab
      '#' + BAR_ID + ' a,#' + BAR_ID + ' button{all:unset;box-sizing:border-box;' +
      'flex:1 1 0;display:flex;flex-direction:column;align-items:center;justify-content:center;' +
      'gap:3px;cursor:pointer;color:var(--lg-mute,#6b6f6b);' +
      '-webkit-tap-highlight-color:transparent;padding:7px 2px 5px;min-width:0;' +
      'transition:color .15s ease}' +
      '#' + BAR_ID + ' .lt-ico{width:25px;height:25px;display:block}' +
      '#' + BAR_ID + ' .lt-ico svg{width:25px;height:25px;display:block;fill:none;' +
      'stroke:currentColor;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round}' +
      // Profile tab avatar (Instagram-style): circular, with an active ring.
      '#' + BAR_ID + ' .lt-avi{border-radius:50%;overflow:hidden;background:var(--lg-sage-tint,#eef2e3);' +
      'box-shadow:0 0 0 1.5px transparent;transition:box-shadow .15s ease}' +
      '#' + BAR_ID + ' .lt-avi img{width:100%;height:100%;object-fit:cover;display:block}' +
      '#' + BAR_ID + ' .is-active .lt-avi{box-shadow:0 0 0 2px var(--lg-sage-d,#6b7c52)}' +
      '#' + BAR_ID + ' .lt-lb{font-weight:600;letter-spacing:.01em;max-width:100%;' +
      'overflow:hidden;text-overflow:ellipsis;white-space:nowrap}' +
      // active state
      '#' + BAR_ID + ' .is-active{color:var(--lg-sage-d,#6b7c52)}' +
      '#' + BAR_ID + ' a:active,#' + BAR_ID + ' button:active{color:var(--lg-sage,#87986a);transform:translateY(.5px)}' +
      // show only on mobile
      '@media ' + MOBILE_MQ + '{#' + BAR_ID + '{display:flex}' +
      'body.has-looth-tabbar{padding-bottom:calc(' + H + 'px + env(safe-area-inset-bottom,0px)) !important}' +
      // Shop tab replaces the floating FAB on mobile
      '#looth-shop-fab{display:none !important}' +
      // lift the install banner above the bar
      '#looth-pwa-banner{bottom:calc(' + (H + 14) + 'px + env(safe-area-inset-bottom,0px)) !important}' +
      // Consolidate to the bottom "You": hide the shared header's account bubble
      // on the app (mobile). Desktop keeps it. Avatar src is still readable for
      // the profile tab even while the button is display:none.
      '.lg-chrome__account{display:none !important}}' +

      // ---- Profile sheet (Instagram-style upward sheet from "You") ----
      '.lt-sheet-bd{position:fixed;inset:0;z-index:2147481300;background:rgba(26,29,26,.45);' +
        'opacity:0;visibility:hidden;transition:opacity .22s ease,visibility .22s ease}' +
      '.lt-sheet-bd.is-open{opacity:1;visibility:visible}' +
      '.lt-sheet{position:fixed;left:0;right:0;bottom:0;z-index:2147481400;max-height:86vh;overflow:auto;' +
        '-webkit-overflow-scrolling:touch;background:var(--lg-cream,#fbfbf8);border-radius:18px 18px 0 0;' +
        'box-shadow:0 -8px 40px rgba(26,29,26,.22);transform:translateY(100%);' +
        'transition:transform .26s cubic-bezier(.32,.72,0,1);' +
        'padding:0 16px calc(18px + env(safe-area-inset-bottom,0px));' +
        'font:14px/1.4 var(--lg-font-sans,system-ui,-apple-system,"Segoe UI",sans-serif);color:var(--lg-ink,#323532)}' +
      '.lt-sheet.is-open{transform:translateY(0)}' +
      '.lt-sheet__grab{width:38px;height:4px;border-radius:999px;background:var(--lg-line,#e3ddd0);margin:9px auto 4px}' +
      '.lt-sheet__head{display:flex;align-items:center;gap:12px;padding:8px 2px 14px;' +
        'border-bottom:1px solid var(--lg-line,#e3ddd0)}' +
      '.lt-sheet__avi{width:46px;height:46px;border-radius:50%;overflow:hidden;flex:0 0 auto;' +
        'background:var(--lg-sage-tint,#eef2e3);box-shadow:0 0 0 2px var(--lg-sage-d,#6b7c52)}' +
      '.lt-sheet__avi img{width:100%;height:100%;object-fit:cover;display:block}' +
      '.lt-sheet__id{display:flex;flex-direction:column;min-width:0}' +
      '.lt-sheet__name{font-weight:700;font-size:15px;color:var(--lg-charcoal,#1a1d1a);' +
        'font-family:var(--lg-font-serif,Georgia,serif);overflow:hidden;text-overflow:ellipsis;white-space:nowrap}' +
      '.lt-sheet__view{font-size:12.5px;color:var(--lg-sage-d,#6b7c52);text-decoration:none}' +
      '.lt-sheet__nav{display:flex;flex-direction:column;padding:6px 0;border-bottom:1px solid var(--lg-line,#e3ddd0)}' +
      '.lt-sheet__row{display:block;padding:12px 6px;color:var(--lg-ink,#323532);text-decoration:none;' +
        'border-radius:10px;font-weight:600}' +
      '.lt-sheet__row:active{background:var(--lg-sage-tint,#eef2e3)}' +
      '.lt-sheet__sech{font-weight:700;font-size:12px;letter-spacing:.05em;text-transform:uppercase;' +
        'color:var(--lg-mute,#6b6f6b);padding:14px 6px 4px}' +
      // settings panel (rendered by app-settings.js buildPanel)
      '.lg-set-sec{padding:6px 6px 10px}' +
      '.lg-set-sec__h{font-size:12.5px;font-weight:600;color:var(--lg-mute,#6b6f6b);margin:0 0 8px}' +
      '.lg-set-row{display:flex;flex-wrap:wrap;gap:8px}' +
      '.lg-set-opt{display:inline-flex;align-items:center;gap:7px;background:#fff;' +
        'border:1px solid var(--lg-line,#e3ddd0);border-radius:999px;padding:8px 13px;' +
        'font:600 13px/1 var(--lg-font-sans,system-ui,sans-serif);color:var(--lg-ink,#323532);cursor:pointer}' +
      '.lg-set-opt.is-on{border-color:var(--lg-sage-d,#6b7c52);box-shadow:0 0 0 1px var(--lg-sage-d,#6b7c52);' +
        'background:var(--lg-sage-tint,#eef2e3)}' +
      '.lg-set-swatch{width:15px;height:15px;border-radius:50%;border:1px solid rgba(0,0,0,.12);flex:0 0 auto}' +
      // hide the install banner while the sheet is open so it doesn't overlap
      'body.lt-sheet-open #looth-pwa-banner{display:none !important}' +
      // ---- Tab-switch skeleton: a perceived-speed bridge during the full-page nav.
      // Shown instantly on a tab tap; the server-rendered page paints over it on arrival.
      '#looth-skel{position:fixed;left:0;top:0;right:0;bottom:0;z-index:2147481000;' +
        'background:var(--lg-cream,#fbfbf8);display:none;padding:14px 12px;overflow:hidden}' +
      '#looth-skel.is-on{display:block}' +
      '#looth-skel .sk{background:#fff;border:1px solid var(--lg-line,#e3ddd0);border-radius:16px;' +
        'margin:0 0 12px;overflow:hidden;position:relative}' +
      '#looth-skel .sk-cover{height:150px;background:#ece9e1}' +
      '#looth-skel .sk-row{height:13px;border-radius:7px;background:#ece9e1;margin:10px 14px}' +
      '#looth-skel .sk::after{content:"";position:absolute;inset:0;' +
        'background:linear-gradient(90deg,transparent,rgba(255,255,255,.55),transparent);' +
        'transform:translateX(-100%);animation:lt-shimmer 1.2s infinite}' +
      '@keyframes lt-shimmer{100%{transform:translateX(100%)}}';
    var s = document.createElement('style');
    s.id = STYLE_ID;
    s.textContent = css;
    (document.head || document.documentElement).appendChild(s);
  }

  function openShop() {
    var fab = document.getElementById('looth-shop-fab');
    if (fab) { fab.click(); return; }
    // Shop bubble not ready yet — retry briefly, else fall back to marketplace.
    var tries = 0;
    var iv = setInterval(function () {
      var f = document.getElementById('looth-shop-fab');
      if (f) { clearInterval(iv); f.click(); }
      else if (++tries > 20) { clearInterval(iv); window.location.href = 'https://loothtool.com/'; }
    }, 100);
  }

  function shopOpen() {
    var ov = document.getElementById('looth-shop-ov');
    return !!(ov && ov.classList.contains('is-open'));
  }

  // Instant skeleton overlay shown on a tab tap, bridging the full-page-nav wait
  // so switching tabs feels snappy. Mobile only; auto-clears as a safety if the
  // navigation never happens (e.g. same-page).
  var skelTimer = null;
  function showTabSkeleton() {
    if (!window.matchMedia(MOBILE_MQ).matches) return;
    var el = document.getElementById('looth-skel');
    if (!el) {
      el = document.createElement('div');
      el.id = 'looth-skel';
      el.setAttribute('aria-hidden', 'true');
      var card = '<div class="sk"><div class="sk-cover"></div>' +
        '<div class="sk-row" style="width:68%"></div>' +
        '<div class="sk-row" style="width:92%"></div>' +
        '<div class="sk-row" style="width:46%"></div></div>';
      el.innerHTML = card + card + card + card;
      (document.body || document.documentElement).appendChild(el);
    }
    el.classList.add('is-on');
    clearTimeout(skelTimer);
    skelTimer = setTimeout(function () { el.classList.remove('is-on'); }, 4000);
  }

  function build() {
    if (document.getElementById(BAR_ID)) return;
    injectStyles();
    var path = location.pathname || '/';
    var drawerOpen = shopOpen();

    var nav = document.createElement('nav');
    nav.id = BAR_ID;
    nav.setAttribute('role', 'navigation');
    nav.setAttribute('aria-label', 'Primary');

    TABS.forEach(function (t) {
      var active = t.shop ? drawerOpen : (!drawerOpen && t.match(path));
      var iconHtml;
      if (t.profile) {
        var src = avatarSrc();
        iconHtml = src
          ? '<span class="lt-ico lt-avi"><img src="' + src + '" alt=""></span>'
          : '<span class="lt-ico"><svg viewBox="0 0 24 24" aria-hidden="true">' + t.icon + '</svg></span>';
      } else {
        iconHtml = '<span class="lt-ico"><svg viewBox="0 0 24 24" aria-hidden="true">' + t.icon + '</svg></span>';
      }
      var inner = iconHtml + '<span class="lt-lb">' + t.label + '</span>';
      var el;
      if (t.shop) {
        el = document.createElement('button');
        el.type = 'button';
        el.addEventListener('click', function () { openShop(); });
      } else {
        el = document.createElement('a');
        el.href = t.href;
        // The "You" tab opens the profile sheet instead of navigating away.
        if (t.profile) el.addEventListener('click', function (e) { e.preventDefault(); openSheet(); });
        // Hub/Events/Members navigate — show an instant skeleton so the switch feels snappy.
        else el.addEventListener('click', function () { if (!active) showTabSkeleton(); });
      }
      el.className = active ? 'is-active' : '';
      el.setAttribute('aria-label', t.label);
      if (active) el.setAttribute('aria-current', 'page');
      el.innerHTML = inner;
      nav.appendChild(el);
    });

    (document.body || document.documentElement).appendChild(nav);
    document.body.classList.add('has-looth-tabbar');

    // The shared header may paint its avatar after we build — if the Profile tab
    // fell back to the person icon, poll briefly and swap in the real avatar.
    var profEl = nav.querySelector('a[href="' + '/profile/edit' + '"]');
    if (profEl && !profEl.querySelector('.lt-avi')) {
      var ptries = 0;
      var piv = setInterval(function () {
        var src = avatarSrc();
        if (src) {
          clearInterval(piv);
          var ico = profEl.querySelector('.lt-ico');
          if (ico) { ico.className = 'lt-ico lt-avi'; ico.innerHTML = '<img src="' + src + '" alt="">'; }
        } else if (++ptries > 20) { clearInterval(piv); }
      }, 150);
    }

    // Keep the Shop tab's active state in sync with the drawer.
    var shopBtn = nav.querySelector('button');
    if (shopBtn) {
      var sync = function () {
        var open = shopOpen();
        nav.querySelectorAll('a,button').forEach(function (n) {
          if (n === shopBtn) return;
          // when drawer open, no page tab is active
        });
        if (open) { shopBtn.classList.add('is-active'); shopBtn.setAttribute('aria-current', 'true'); }
        else { shopBtn.classList.remove('is-active'); shopBtn.removeAttribute('aria-current'); }
      };
      document.addEventListener('click', function () { setTimeout(sync, 60); }, true);
    }
  }

  // ---- Profile sheet ----------------------------------------------------
  var SHEET_ID = 'looth-sheet', SHEET_BD_ID = 'looth-sheet-bd';

  // Mirror the shared header's account-menu links so the sheet stays in sync
  // with whatever those are (Archive / The Hub / Events / Members / Loothtool).
  function accountLinks() {
    var menu = document.querySelector('.lg-chrome__menu');
    var acct = document.querySelector('.lg-chrome__account');
    var opened = false;
    if (!menu && acct) { acct.click(); menu = document.querySelector('.lg-chrome__menu'); opened = true; }
    var out = [];
    if (menu) {
      [].slice.call(menu.querySelectorAll('a[href]')).forEach(function (a) {
        var t = (a.textContent || '').replace(/\s+/g, ' ').trim();
        // Archive is merged into the Hub now — drop it from the sheet.
        if (t && !/^archive$/i.test(t)) out.push({ text: t, href: a.getAttribute('href') });
      });
    }
    if (opened && acct) acct.click(); // restore closed state
    return out;
  }

  function renderSettings(host) {
    if (window.LGSettings && typeof window.LGSettings.buildPanel === 'function') {
      window.LGSettings.buildPanel(host);
    } else {
      host.textContent = 'Settings unavailable.';
    }
  }

  function buildSheet() {
    var existing = document.getElementById(SHEET_ID);
    if (existing) return existing;

    var bd = document.createElement('div');
    bd.id = SHEET_BD_ID; bd.className = 'lt-sheet-bd';
    bd.addEventListener('click', closeSheet);

    var sheet = document.createElement('div');
    sheet.id = SHEET_ID; sheet.className = 'lt-sheet';
    sheet.setAttribute('role', 'dialog'); sheet.setAttribute('aria-modal', 'true'); sheet.setAttribute('aria-label', 'You');

    var grab = document.createElement('div'); grab.className = 'lt-sheet__grab';
    sheet.appendChild(grab);

    // header: avatar + name + view profile
    var nameBtn = document.querySelector('.lg-chrome__account');
    var name = nameBtn ? (nameBtn.textContent || '').replace(/\s+/g, ' ').trim() : 'You';
    var src = avatarSrc();
    var head = document.createElement('div'); head.className = 'lt-sheet__head';
    head.innerHTML =
      '<span class="lt-sheet__avi">' + (src ? '<img src="' + src + '" alt="">' : '') + '</span>' +
      '<span class="lt-sheet__id"><span class="lt-sheet__name"></span>' +
      '<a class="lt-sheet__view" href="/profile/edit">View profile</a></span>';
    head.querySelector('.lt-sheet__name').textContent = name;
    sheet.appendChild(head);

    // mirrored account-menu links
    var navWrap = document.createElement('div'); navWrap.className = 'lt-sheet__nav';
    accountLinks().forEach(function (l) {
      var a = document.createElement('a'); a.className = 'lt-sheet__row';
      a.href = l.href; a.textContent = l.text;
      navWrap.appendChild(a);
    });
    if (navWrap.children.length) sheet.appendChild(navWrap);

    // settings
    var setH = document.createElement('div'); setH.className = 'lt-sheet__sech'; setH.textContent = 'Settings';
    sheet.appendChild(setH);
    var setBody = document.createElement('div'); setBody.className = 'lt-sheet__set';
    renderSettings(setBody);
    sheet.appendChild(setBody);

    document.body.appendChild(bd);
    document.body.appendChild(sheet);
    return sheet;
  }

  function openSheet() {
    var sheet = buildSheet();
    var bd = document.getElementById(SHEET_BD_ID);
    // refresh the settings panel so the active chips reflect current choices
    var setBody = sheet.querySelector('.lt-sheet__set');
    if (setBody) renderSettings(setBody);
    void sheet.offsetHeight; // reflow so the transform transition runs
    if (bd) bd.classList.add('is-open');
    sheet.classList.add('is-open');
    document.body.classList.add('lt-sheet-open');
    document.body.style.overflow = 'hidden';
    document.addEventListener('keydown', onSheetKey);
  }

  function closeSheet() {
    var sheet = document.getElementById(SHEET_ID), bd = document.getElementById(SHEET_BD_ID);
    if (sheet) sheet.classList.remove('is-open');
    if (bd) bd.classList.remove('is-open');
    document.body.classList.remove('lt-sheet-open');
    document.body.style.overflow = '';
    document.removeEventListener('keydown', onSheetKey);
  }

  function onSheetKey(e) { if (e.key === 'Escape') closeSheet(); }

  function start() {
    if (document.body) build();
    else document.addEventListener('DOMContentLoaded', build);
  }
  start();
})();
