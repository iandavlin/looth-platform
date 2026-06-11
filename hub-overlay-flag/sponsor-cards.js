/* sponsor-cards.js — Looth PWA (all widths; /hub only)
 *
 * Spotlight sponsor cards scattered through the Hub feed — the "Spotlight
 * Cards" placement Buck + Ian greenlit from the 2026-06-11 sponsors deck
 * (hub-styles/sponsors-deck.html → dev.loothgroup.com/sponsors-deck/).
 *
 * - One sponsor card after every Nth organic feed card (N below), rotating
 *   through the five sponsors; rotation start is randomized per pageload so
 *   the same sponsor doesn't always lead.
 * - Real production logo assets from wp-content/uploads; whole card links to
 *   the sponsor's /sponsors/<slug>/ page. On mobile the existing
 *   sponsor-sheet.js interceptor catches that link and opens the app-style
 *   pull-up sheet; desktop navigates to the full page.
 * - Cards are pre-marked data-lg-card / data-lg-prevobs so hub-polish's
 *   relayout + preview passes skip them; .lg-sponsor-card excludes them from
 *   our own organic-card counting. Token-driven (--lguser-* with --lg-*
 *   fallbacks) so user themes + dark mode restyle them like everything else.
 * - Client-layer only — no canonical code touched. Loaded via /pwa.js.
 */
(function () {
  'use strict';
  if (window.__lgSponsorCards) return;
  window.__lgSponsorCards = true;
  if (!/^\/hub(\/|$)/.test(location.pathname)) return;

  var EVERY = 6; // one sponsor card after every 6 organic cards

  var SPONSORS = [
    { slug: 'total-vise', name: 'Total Vise',
      img: '/wp-content/uploads/2024/06/Sponor-Banner-Total-Vise-300x108.webp',
      tag: 'Precision repair vises',
      blurb: 'Repair-grade holding for guitars and basses.' },
    { slug: 'stewmac', name: 'StewMac',
      img: '/wp-content/uploads/2024/06/Sidebar-Affiliate-Stew-Mac-300x108.webp',
      tag: 'Tools + supplies for lutherie',
      blurb: 'Everything for building and repairing stringed instruments.' },
    { slug: 'go-acoustic-audio', name: 'Go Acoustic Audio',
      img: '/wp-content/uploads/2024/06/Sponsor-Go-Acoustic-300x80.webp',
      tag: 'Pickups & acoustic amplification',
      blurb: 'Natural acoustic tone, amplified the way it should be.' },
    { slug: 'strings-micro-factory', name: 'Strings Micro Factory',
      img: '/wp-content/uploads/2024/06/SMF-Logo-Horizontal-624x192.jpg',
      tag: 'Small-batch handmade strings',
      blurb: 'Hand-wound strings from a microfactory obsessed with tone.' },
    { slug: 'gluboost', name: 'GluBoost',
      img: '/wp-content/uploads/2026/04/gluboost-logo-624x163.png',
      tag: 'Adhesives & finishing',
      blurb: 'Pro-grade CA glues and finish systems trusted by luthiers.' }
  ];
  var OFFSET = Math.floor(Math.random() * SPONSORS.length);

  /* ---------- styles (token-driven; dark mode comes free via --lguser-*) ---------- */
  function ensureCss() {
    if (document.getElementById('lg-sponsor-cards-css')) return;
    var st = document.createElement('style');
    st.id = 'lg-sponsor-cards-css';
    st.textContent =
      '.lg-sponsor-card{overflow:hidden}' +
      // other layers append a Like/Reply/Share action row to every .feed-card —
      // meaningless on a sponsor card (no topic behind it), so suppress here
      '.lg-sponsor-card .feed-card__actions,.lg-sponsor-card .lg-card-actions,' +
      '.lg-sponsor-card .fc-actions{display:none!important}' +
      '.lg-sponsor-card .lgsc-link{display:block;color:inherit;text-decoration:none;-webkit-tap-highlight-color:transparent}' +
      '.lgsc-meta{display:flex;align-items:center;gap:9px;padding:11px 13px 9px;min-width:0}' +
      '.lgsc-name{flex:1 1 auto;min-width:0;font:600 12.5px/1.25 var(--lg-font-sans,system-ui,sans-serif);' +
        'color:var(--lguser-ink,var(--lg-ink,#323532));overflow:hidden;text-overflow:ellipsis;white-space:nowrap}' +
      '.lgsc-badge{flex:0 0 auto;font:700 10px/1 var(--lg-font-sans,system-ui,sans-serif);letter-spacing:.05em;' +
        'text-transform:uppercase;background:var(--lg-amber,#ecb351);color:#3a2f12;border-radius:7px;padding:5px 8px}' +
      // FIXED hero height — the logo loads lazily, and a container that grows on
      // image load shifts everything below it (Buck's "things jump around")
      '.lgsc-hero{display:flex;align-items:center;justify-content:center;height:100px;padding:0 16px;' +
        'background:linear-gradient(135deg,var(--lguser-pill,var(--lg-sage-tint,#eef2e3)),transparent 90%);' +
        'border-top:1px solid var(--lguser-line,var(--lg-line,#e3ddd0));border-bottom:1px solid var(--lguser-line,var(--lg-line,#e3ddd0))}' +
      '.lgsc-chip{display:flex;align-items:center;justify-content:center;height:74px;min-width:180px;max-width:86%;' +
        'background:#fff;border-radius:12px;padding:0 18px;box-shadow:0 1px 3px rgba(26,29,26,.10)}' +
      '.lgsc-chip img{display:block;max-width:100%;max-height:50px;width:auto;height:auto}' +
      '.lgsc-body{padding:11px 13px 4px}' +
      '.lgsc-title{margin:0 0 4px;font:600 17px/1.25 var(--lg-font-serif,Georgia,serif);' +
        'color:var(--lguser-ink,var(--lg-charcoal,#1a1d1a))}' +
      '.lgsc-blurb{margin:0;font:400 13.5px/1.5 var(--lg-font-sans,system-ui,sans-serif);' +
        'color:var(--lguser-mute,var(--lg-mute,#6b6f6b))}' +
      '.lgsc-cta{display:inline-flex;align-items:center;gap:6px;margin:9px 13px 13px;' +
        'background:var(--lguser-pill,var(--lg-sage-tint,#eef2e3));color:var(--lguser-accent-d,var(--lg-sage-d,#6b7c52));' +
        'border-radius:999px;padding:7px 13px;font:700 12px/1 var(--lg-font-sans,system-ui,sans-serif)}' +
      '.lgsc-cta svg{width:13px;height:13px}';
    document.head.appendChild(st);
  }

  function esc(s) {
    return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;')
      .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

  function cardEl(s) {
    var el = document.createElement('article');
    el.className = 'feed-card lg-sponsor-card';
    // pre-mark so hub-polish relayout/preview passes leave it alone
    el.setAttribute('data-lg-card', '1');
    el.setAttribute('data-lg-prevobs', '1');
    el.innerHTML =
      '<a class="lgsc-link" href="/sponsors/' + esc(s.slug) + '/">' +
        '<div class="lgsc-meta">' +
          '<span class="lgsc-name">' + esc(s.name) + '</span>' +
          '<span class="lgsc-badge">Sponsor</span>' +
        '</div>' +
        '<div class="lgsc-hero"><span class="lgsc-chip"><img loading="lazy" src="' + esc(s.img) + '" alt="' + esc(s.name) + '"></span></div>' +
        '<div class="lgsc-body">' +
          '<h3 class="lgsc-title">' + esc(s.tag) + '</h3>' +
          '<p class="lgsc-blurb">' + esc(s.blurb) + '</p>' +
        '</div>' +
        '<span class="lgsc-cta">Visit ' + esc(s.name) +
          '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M7 17 17 7"/><path d="M9 7h8v8"/></svg>' +
        '</span>' +
      '</a>';
    return el;
  }

  /* ---------- insertion: after every Nth ORGANIC card, idempotent ----------
   * Only insert at anchors fully BELOW the viewport: inserting at or above
   * where the user currently is shoves the content they're reading (the other
   * half of Buck's "things jump around"). Slots the user has already scrolled
   * past just stay empty for this pageload — never reclaimed, never a jump.
   * New infinite-scroll cards always arrive below the fold, so they qualify. */
  function reflow(feed) {
    var organic = feed.querySelectorAll('.feed-card:not(.lg-sponsor-card)');
    var fold = window.innerHeight || 800;
    for (var i = EVERY - 1; i < organic.length; i += EVERY) {
      var anchor = organic[i];
      var nxt = anchor.nextElementSibling;
      if (nxt && nxt.classList && nxt.classList.contains('lg-sponsor-card')) continue;
      try {
        if (anchor.getBoundingClientRect().top <= fold) continue;
        var idx = (OFFSET + Math.floor(i / EVERY)) % SPONSORS.length;
        anchor.insertAdjacentElement('afterend', cardEl(SPONSORS[idx]));
      } catch (e) {}
    }
  }

  function boot() {
    var feed = document.querySelector('.feed');
    if (!feed) { setTimeout(boot, 600); return; } // feed may render after us
    ensureCss();
    reflow(feed);
    var pending = null;
    new MutationObserver(function () {
      if (pending) return;
      pending = setTimeout(function () { pending = null; reflow(feed); }, 250);
    }).observe(feed, { childList: true });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
