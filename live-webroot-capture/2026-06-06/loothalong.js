/* Looth "Loothalong" CTA — pinned banner at the TOP of the Events landing page.
   The Loothalong is a recurring member-only live Zoom jam. This injects a
   permalink-style call-to-action button above the events grid.

   SECURITY (no-leak rule): the member-only Zoom URL must NEVER appear in client
   JS or the public DOM. This button is a PLAIN LINK whose destination handles
   gating server-side. We point at /loothalong, the dedicated gated route: it
   302-redirects entitled members to the live room, and bounces everyone else to
   the auth/upgrade flow. Do NOT embed a Zoom URL here.

   Loaded site-wide via /pwa.js, so it path-gates to /events/ only.
   Self-contained: injects its own <style> + one banner element. No deps, no emoji. */
(function () {
  'use strict';
  if (window.__loothLoothalong) return;
  window.__loothLoothalong = true;

  // Path gate — only render on the Events landing (and its subpaths). No-op elsewhere.
  if (!/^\/events(\/|$)/.test(location.pathname || '/')) return;

  var EL_ID = 'looth-loothalong';
  var STYLE_ID = 'looth-loothalong-style';

  // Single easy-to-change destination. Server-side gating lives at this route:
  // /loothalong 302s entitled members to the live room, others to auth/upgrade.
  var LOOTHALONG_HREF = '/loothalong.php';

  // Inline stroke icon (video/play glyph, currentColor). No emoji per brand rules.
  var ICON =
    '<svg viewBox="0 0 24 24" aria-hidden="true">' +
    '<rect x="2.5" y="6" width="13" height="12" rx="2.5"/>' +
    '<path d="M15.5 10l5-2.6v9.2l-5-2.6"/>' +
    '<path d="M6 12h0M9 12h0" stroke-width="2.4" stroke-linecap="round"/>' +
    '</svg>';

  // Trailing arrow affordance.
  var ARROW =
    '<svg class="ll-arrow" viewBox="0 0 24 24" aria-hidden="true">' +
    '<path d="M5 12h13"/><path d="M13 6l6 6-6 6"/>' +
    '</svg>';

  function injectStyles() {
    if (document.getElementById(STYLE_ID)) return;
    var css =
      '#' + EL_ID + '{display:flex;align-items:center;gap:14px;' +
      'margin:0 0 20px;padding:16px 18px;text-decoration:none;' +
      'border-radius:16px;border:1px solid var(--lg-sage-d,#6b7c52);' +
      'background:linear-gradient(135deg,var(--lg-sage,#87986a),var(--lg-sage-d,#6b7c52));' +
      'color:#fbfbf8;box-shadow:0 2px 14px rgba(26,29,26,.10);' +
      '-webkit-tap-highlight-color:transparent;' +
      'transition:transform .12s ease,box-shadow .15s ease,filter .15s ease}' +
      '#' + EL_ID + ':hover{filter:brightness(1.04);box-shadow:0 4px 20px rgba(26,29,26,.16);' +
      'transform:translateY(-1px)}' +
      '#' + EL_ID + ':active{transform:translateY(0);filter:brightness(.97);' +
      'box-shadow:0 1px 8px rgba(26,29,26,.14)}' +
      // leading icon medallion
      '#' + EL_ID + ' .ll-ico{flex:0 0 auto;width:46px;height:46px;border-radius:12px;' +
      'display:flex;align-items:center;justify-content:center;' +
      'background:rgba(251,251,248,.16);border:1px solid rgba(251,251,248,.28)}' +
      '#' + EL_ID + ' .ll-ico svg{width:24px;height:24px;display:block;fill:none;' +
      'stroke:currentColor;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round}' +
      // text column
      '#' + EL_ID + ' .ll-body{flex:1 1 auto;min-width:0}' +
      '#' + EL_ID + ' .ll-row{display:flex;align-items:center;gap:9px;flex-wrap:wrap}' +
      '#' + EL_ID + ' .ll-title{font-family:var(--lg-font-serif,Georgia,serif);' +
      'font-size:21px;line-height:1.15;font-weight:600;letter-spacing:.01em;margin:0}' +
      '#' + EL_ID + ' .ll-pill{flex:0 0 auto;font:600 11px/1 system-ui,-apple-system,' +
      'Segoe UI,Roboto,sans-serif;letter-spacing:.06em;text-transform:uppercase;' +
      'padding:5px 9px;border-radius:999px;color:var(--lg-charcoal,#1a1d1a);' +
      'background:var(--lg-amber,#ecb351)}' +
      '#' + EL_ID + ' .ll-sub{margin:5px 0 0;font:14px/1.5 system-ui,-apple-system,' +
      'Segoe UI,Roboto,sans-serif;color:rgba(251,251,248,.92)}' +
      // trailing arrow
      '#' + EL_ID + ' .ll-arrow{flex:0 0 auto;width:22px;height:22px;display:block;' +
      'fill:none;stroke:currentColor;stroke-width:2;stroke-linecap:round;' +
      'stroke-linejoin:round;opacity:.92;transition:transform .15s ease}' +
      '#' + EL_ID + ':hover .ll-arrow{transform:translateX(3px)}' +
      // phone width: tighten and let the arrow sit inline
      '@media (max-width:480px){' +
      '#' + EL_ID + '{gap:12px;padding:14px 15px}' +
      '#' + EL_ID + ' .ll-ico{width:42px;height:42px}' +
      '#' + EL_ID + ' .ll-title{font-size:19px}' +
      '#' + EL_ID + ' .ll-sub{font-size:13px}}';
    var s = document.createElement('style');
    s.id = STYLE_ID;
    s.textContent = css;
    (document.head || document.documentElement).appendChild(s);
  }

  // Find the most robust insertion point inside the events landing content.
  // Confirmed DOM: .lg-events-landing-page > header + main > .lg-evland >
  //   .lg-evland__head, .lg-evland__grid, ...  We insert as the first child of
  //   the inner content wrapper so the CTA sits above the head/grid.
  function findHost() {
    return document.querySelector('.lg-evland') ||
      document.querySelector('.lg-events-landing-page main') ||
      document.querySelector('.lg-events-landing-page');
  }

  function build() {
    if (document.getElementById(EL_ID)) return; // idempotent
    var host = findHost();
    if (!host) return; // graceful no-op if container not found

    injectStyles();

    var a = document.createElement('a');
    a.id = EL_ID;
    a.href = LOOTHALONG_HREF;
    a.setAttribute('aria-label', 'Loothalong — our member-only live jam');
    a.innerHTML =
      '<span class="ll-ico">' + ICON + '</span>' +
      '<span class="ll-body">' +
      '<span class="ll-row">' +
      '<span class="ll-title">Loothalong</span>' +
      '<span class="ll-pill">Live jam</span>' +
      '</span>' +
      '<span class="ll-sub">Our member-only live jam &mdash; hop into the room</span>' +
      '</span>' +
      ARROW;

    // Prefer inserting before the grid; else first child of the host.
    var grid = host.querySelector('.lg-evland__grid');
    if (grid && grid.parentNode === host) {
      host.insertBefore(a, grid);
    } else if (host.firstChild) {
      host.insertBefore(a, host.firstChild);
    } else {
      host.appendChild(a);
    }
  }

  function start() {
    if (document.body) build();
    else document.addEventListener('DOMContentLoaded', build);
  }
  start();
})();
