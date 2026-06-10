/* Looth shop bubble — a floating "Shop" FAB on the Looth app that surfaces
   Loothtool marketplace listings. Tapping a listing opens the product on
   loothtool.com. Self-contained: injects its own styles, fetches a same-origin
   JSON feed (/shop-feed.json) that is refreshed from Loothtool server-side.
   Loaded site-wide via /pwa.js (which is already injected into every <head>). */
(function () {
  'use strict';
  if (window.__loothShop) return;
  window.__loothShop = true;

  var FEED_URL = '/shop-feed.json';
  var SHOP_HOME = 'https://loothtool.com/shop';
  var CACHE_KEY = 'looth_shop_feed_v1';

  var fetched = false;
  var items = [];
  var debounce = null;

  function esc(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;')
      .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

  function injectStyles() {
    if (document.getElementById('looth-shop-style')) return;
    var css =
      /* FAB */
      '#looth-shop-fab{position:fixed;right:20px;bottom:20px;z-index:2147482000;' +
      'width:54px;height:54px;border-radius:50%;border:0;cursor:pointer;' +
      'background:var(--lg-sage,#87986a);color:#fff;display:flex;align-items:center;justify-content:center;' +
      'box-shadow:0 6px 20px rgba(26,29,26,.28);transition:transform .18s ease,background .18s ease}' +
      '#looth-shop-fab:hover{transform:scale(1.08);background:var(--lg-sage-d,#6b7c52)}' +
      '#looth-shop-fab svg{width:24px;height:24px;pointer-events:none}' +
      '@media (max-width:640px){#looth-shop-fab{bottom:88px}}' + /* clear the PWA install banner */
      /* overlay */
      '#looth-shop-ov{position:fixed;inset:0;overflow:hidden;z-index:2147482100;visibility:hidden;opacity:0;' +
      'transition:opacity .25s ease,visibility 0s linear .25s;' +
      'font:15px/1.5 system-ui,-apple-system,Segoe UI,Roboto,sans-serif}' +
      '#looth-shop-ov.is-open{visibility:visible;opacity:1;transition:opacity .25s ease}' +
      '#looth-shop-ov .ls-back{position:absolute;inset:0;background:rgba(26,29,26,.5);cursor:pointer}' +
      '#looth-shop-ov .ls-panel{position:absolute;top:0;right:0;height:100%;width:min(440px,100%);' +
      'background:var(--lg-cream,#fbfbf8);display:flex;flex-direction:column;' +
      'box-shadow:-8px 0 30px rgba(26,29,26,.3);transform:translateX(100%);' +
      'transition:transform .28s cubic-bezier(.4,0,.2,1)}' +
      '#looth-shop-ov.is-open .ls-panel{transform:translateX(0)}' +
      /* head */
      '#looth-shop-ov .ls-head{display:flex;align-items:center;justify-content:space-between;gap:10px;' +
      'padding:16px 18px;background:var(--lg-sage,#87986a);color:#fff;flex:0 0 auto}' +
      '#looth-shop-ov .ls-ttl{font-weight:700;font-size:1.05rem}' +
      '#looth-shop-ov .ls-sub{font-size:12px;opacity:.85;margin-top:1px}' +
      '#looth-shop-ov .ls-x{background:transparent;border:0;color:#fff;cursor:pointer;font-size:26px;line-height:1;padding:2px 6px}' +
      /* search — collapsed by default so the product grid leads; a header
         magnifier reveals it (and only then does the keyboard appear). */
      '#looth-shop-ov .ls-head-actions{display:flex;align-items:center;gap:2px}' +
      '#looth-shop-ov .ls-search-btn{background:transparent;border:0;color:#fff;cursor:pointer;padding:2px 6px;display:flex;align-items:center}' +
      '#looth-shop-ov .ls-search-btn svg{width:20px;height:20px}' +
      '#looth-shop-ov .ls-search-btn[aria-expanded="true"]{opacity:.7}' +
      '#looth-shop-ov .ls-search-wrap{display:none;padding:12px 18px;border-bottom:1px solid var(--lg-line,#e3ddd0);flex:0 0 auto}' +
      '#looth-shop-ov .ls-search-wrap.is-open{display:block}' +
      '#looth-shop-ov .ls-search{width:100%;box-sizing:border-box;padding:10px 12px;border:1px solid var(--lg-line,#e3ddd0);' +
      'border-radius:10px;background:#fff;color:var(--lg-ink,#323532);font:inherit;outline:none}' +
      '#looth-shop-ov .ls-search:focus{border-color:var(--lg-sage,#87986a)}' +
      /* body */
      '#looth-shop-ov .ls-body{flex:1 1 auto;overflow-y:auto;-webkit-overflow-scrolling:touch;padding:14px 18px}' +
      '#looth-shop-ov .ls-status{text-align:center;color:var(--lg-mute,#6b6f6b);padding:38px 12px;font-size:.92rem}' +
      '#looth-shop-ov .ls-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}' +
      /* card */
      '#looth-shop-ov .ls-card{display:flex;flex-direction:column;background:#fff;border:1px solid var(--lg-line,#e3ddd0);' +
      'border-radius:16px;overflow:hidden;text-decoration:none;color:inherit;transition:box-shadow .15s ease,transform .15s ease}' +
      '#looth-shop-ov .ls-card:hover{box-shadow:0 6px 18px rgba(26,29,26,.14);transform:translateY(-2px)}' +
      '#looth-shop-ov .ls-card .ls-img{display:block;width:100%;height:auto;aspect-ratio:1/1;object-fit:cover;background:var(--lg-sage-tint,#eef2e3)}' +
      '#looth-shop-ov .ls-card .ls-info{padding:9px 10px 11px;display:flex;flex-direction:column;gap:3px}' +
      '#looth-shop-ov .ls-card .ls-name{font-weight:600;font-size:.9rem;line-height:1.2;color:var(--lg-charcoal,#1a1d1a);' +
      'display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}' +
      '#looth-shop-ov .ls-card .ls-vendor{font-size:.72rem;color:var(--lg-mute,#6b6f6b)}' +
      '#looth-shop-ov .ls-card .ls-price{font-weight:700;font-size:.9rem;color:var(--lg-sage-d,#6b7c52);margin-top:auto}' +
      /* foot */
      '#looth-shop-ov .ls-foot{flex:0 0 auto;padding:12px 18px;border-top:1px solid var(--lg-line,#e3ddd0)}' +
      '#looth-shop-ov .ls-all{display:block;text-align:center;padding:11px;border-radius:10px;background:var(--lg-sage,#87986a);' +
      'color:#fff;font-weight:600;text-decoration:none}' +
      '#looth-shop-ov .ls-all:hover{background:var(--lg-sage-d,#6b7c52)}' +
      /* Dark mode: panel went dark (global feed CSS) but cards stayed white w/ light text -> unreadable. Recolor to app dark palette. (Buck 2026-06-09) */
      'html[data-lguser-theme="dark"] #looth-shop-ov .ls-panel{background:#15171a}' +
      'html[data-lguser-theme="dark"] #looth-shop-ov .ls-search-wrap{border-bottom-color:#2c312d}' +
      'html[data-lguser-theme="dark"] #looth-shop-ov .ls-search{background:#222629;color:#e5e7e1;border-color:#333833}' +
      'html[data-lguser-theme="dark"] #looth-shop-ov .ls-status{color:#9aa097}' +
      'html[data-lguser-theme="dark"] #looth-shop-ov .ls-card{background:#1e2124;border-color:#2c312d}' +
      'html[data-lguser-theme="dark"] #looth-shop-ov .ls-card .ls-img{background:#243024}' +
      'html[data-lguser-theme="dark"] #looth-shop-ov .ls-card .ls-name{color:#f2f4ee}' +
      'html[data-lguser-theme="dark"] #looth-shop-ov .ls-card .ls-vendor{color:#9aa097}' +
      'html[data-lguser-theme="dark"] #looth-shop-ov .ls-card .ls-price{color:#b0c693}' +
      'html[data-lguser-theme="dark"] #looth-shop-ov .ls-card:hover{box-shadow:0 6px 18px rgba(0,0,0,.4)}' +
      'html[data-lguser-theme="dark"] #looth-shop-ov .ls-foot{border-top-color:#2c312d}' +
      'body.looth-shop-lock{overflow:hidden}';
    var s = document.createElement('style');
    s.id = 'looth-shop-style';
    s.textContent = css;
    (document.head || document.documentElement).appendChild(s);
  }

  var ov, bodyEl, searchEl, fab, searchBtn, searchWrap;

  function buildUI() {
    injectStyles();

    fab = document.createElement('button');
    fab.id = 'looth-shop-fab';
    fab.type = 'button';
    fab.setAttribute('aria-label', 'Shop the Loothtool marketplace');
    fab.innerHTML =
      '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' +
      '<path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 0 1-8 0"/></svg>';

    ov = document.createElement('div');
    ov.id = 'looth-shop-ov';
    ov.setAttribute('role', 'dialog');
    ov.setAttribute('aria-modal', 'true');
    ov.setAttribute('aria-label', 'Shop the Loot');
    ov.innerHTML =
      '<div class="ls-back" data-close></div>' +
      '<div class="ls-panel">' +
        '<div class="ls-head">' +
          '<div><div class="ls-ttl">Shop the Loot</div><div class="ls-sub">Gear from the Loothtool marketplace</div></div>' +
          '<div class="ls-head-actions">' +
            '<button class="ls-search-btn" type="button" aria-label="Search listings" aria-expanded="false">' +
              '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>' +
            '</button>' +
            '<button class="ls-x" type="button" data-close aria-label="Close">&times;</button>' +
          '</div>' +
        '</div>' +
        '<div class="ls-search-wrap"><input class="ls-search" type="search" placeholder="Search gear…" autocomplete="off" aria-label="Search listings"></div>' +
        '<div class="ls-body"><div class="ls-status">Loading…</div></div>' +
        '<div class="ls-foot"><a class="ls-all" href="' + esc(SHOP_HOME) + '" target="_blank" rel="noopener">Browse the full shop on Loothtool &rarr;</a></div>' +
      '</div>';

    (document.body || document.documentElement).appendChild(fab);
    (document.body || document.documentElement).appendChild(ov);

    bodyEl = ov.querySelector('.ls-body');
    searchEl = ov.querySelector('.ls-search');
    searchBtn = ov.querySelector('.ls-search-btn');
    searchWrap = ov.querySelector('.ls-search-wrap');

    // Shop is now its own page (Buck 2026-06-09) — the FAB navigates there
    // instead of opening the side drawer. (Drawer code kept but unused.)
    fab.addEventListener('click', function () { try { window.location.assign('/shop/'); } catch (e) { window.location.href = '/shop/'; } });
    // Search is opt-in: the magnifier reveals the field (and only THEN focuses it,
    // so the keyboard never pops on open). Toggling it off clears any filter.
    searchBtn.addEventListener('click', function () {
      var nowOpen = !searchWrap.classList.contains('is-open');
      searchWrap.classList.toggle('is-open', nowOpen);
      searchBtn.setAttribute('aria-expanded', nowOpen ? 'true' : 'false');
      if (nowOpen) { searchEl.focus(); }
      else if (searchEl.value) { searchEl.value = ''; render(''); }
    });
    ov.addEventListener('click', function (e) {
      if (e.target.closest('[data-close]')) close();
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && ov.classList.contains('is-open')) close();
    });
    searchEl.addEventListener('input', function () {
      clearTimeout(debounce);
      var q = this.value.trim().toLowerCase();
      debounce = setTimeout(function () { render(q); }, 180);
    });
  }

  function open() {
    ov.classList.add('is-open');
    document.body.classList.add('looth-shop-lock');
    // Instant paint: render from the preloaded/cached snapshot with no spinner.
    if (items.length) render('');
    else bodyEl.innerHTML = '<div class="ls-status">Loading…</div>';
    // Revalidate in the background so the list is always fresh without blocking.
    fetchFeed().catch(function () {
      if (!items.length) bodyEl.innerHTML = '<div class="ls-status">Couldn’t load listings right now.</div>';
    });
    // NOTE: deliberately NOT focusing the search on open — products lead, and the
    // mobile keyboard should not pop up. Search is opened via the header magnifier.
  }
  function close() {
    ov.classList.remove('is-open');
    document.body.classList.remove('looth-shop-lock');
    // Reset search so the next open is a clean product display.
    if (searchWrap) searchWrap.classList.remove('is-open');
    if (searchBtn) searchBtn.setAttribute('aria-expanded', 'false');
    if (searchEl) searchEl.value = '';
  }

  function readCache() {
    try {
      var raw = localStorage.getItem(CACHE_KEY);
      if (raw) { var d = JSON.parse(raw); if (Array.isArray(d)) return d; }
    } catch (e) {}
    return null;
  }
  function writeCache(d) {
    try { localStorage.setItem(CACHE_KEY, JSON.stringify(d)); } catch (e) {}
  }

  // Network fetch (cache:'no-cache' revalidates regardless of CDN headers — the
  // payload is tiny). Updates items + the localStorage snapshot, and re-renders
  // only if the drawer is currently open.
  function fetchFeed() {
    fetched = true;
    return fetch(FEED_URL, { credentials: 'same-origin', cache: 'no-cache' })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (data) {
        if (!data) return;
        var arr = Array.isArray(data) ? data : (data && data.items) || [];
        items = arr;
        writeCache(arr);
        if (ov && ov.classList.contains('is-open')) {
          render(searchEl ? searchEl.value.trim().toLowerCase() : '');
        }
      });
  }

  // Warm the cache before the user taps Shop: hydrate from the last snapshot
  // immediately, then prefetch fresh data when the browser is idle.
  function warm() {
    var cached = readCache();
    if (cached && cached.length) items = cached;
    var go = function () { fetchFeed().catch(function () {}); };
    if ('requestIdleCallback' in window) {
      requestIdleCallback(go, { timeout: 2500 });
    } else {
      setTimeout(go, 800);
    }
  }

  function render(q) {
    var list = items;
    if (q) {
      list = items.filter(function (p) {
        return (p.name || '').toLowerCase().indexOf(q) > -1 ||
               (p.vendor || '').toLowerCase().indexOf(q) > -1;
      });
    }
    if (!list.length) {
      bodyEl.innerHTML = '<div class="ls-status">' +
        (q ? 'No matches for “' + esc(q) + '”.' : 'Marketplace listings are coming soon.') +
        '</div>';
      return;
    }
    bodyEl.innerHTML = '<div class="ls-grid">' + list.map(function (p) {
      return '<a class="ls-card" href="' + esc(p.url || SHOP_HOME) + '" target="_blank" rel="noopener">' +
        '<img class="ls-img" src="' + esc(p.image || '') + '" alt="" loading="lazy" decoding="async" width="300" height="300">' +
        '<div class="ls-info">' +
          '<div class="ls-name">' + esc(p.name) + '</div>' +
          (p.vendor ? '<div class="ls-vendor">' + esc(p.vendor) + '</div>' : '') +
          '<div class="ls-price">' + esc(p.price || '') + '</div>' +
        '</div></a>';
    }).join('') + '</div>';
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', buildUI);
  } else {
    buildUI();
  }
  warm(); // preload + hydrate the feed so the first tap is instant
})();
