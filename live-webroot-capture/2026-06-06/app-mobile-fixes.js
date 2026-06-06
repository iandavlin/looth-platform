/* Looth app — mobile layout guards (client-side, injected site-wide via /pwa.js).
   Self-contained: injects one <style> block. No deps, no emoji.

   WHY: the shared chrome FOOTER (site-header.css `.lg-chrome-foot__inner`) uses a
   fixed `grid-template-columns: 320px 1fr` with NO mobile breakpoint, so on a phone
   it is wider than the viewport and forces horizontal scroll on EVERY page (Hub,
   Events, Members, …). Confirmed via CDP phone audit: hiding `.lg-chrome-foot__cols`
   drops document scrollWidth from 663–695 back under the 500 viewport.

   The proper fix lives in `/srv/lg-shared/site-header.css` (www-data / coordinator) —
   handed off. This is the same rules as a client-side guard so the bug is gone NOW.
   When the canonical media query lands, this becomes a harmless no-op duplicate.

   These rules sit in a <style> appended to <head> AFTER site-header.css, so plain
   source-order specificity wins — no !important needed. */
(function () {
  'use strict';
  if (window.__loothMobileFixes) return;
  window.__loothMobileFixes = true;

  // Mobile only: the legacy Archive views (/archive, /archive-poc) are desktop-only
  // reference surfaces — on a phone the Hub is the front door, so a tap should never
  // dead-end there. Send mobile visitors to the Hub. Runs as early as this script
  // executes; location.replace avoids a back-button trap. Desktop is untouched.
  (function () {
    try {
      if (!window.matchMedia('(max-width:640px)').matches) return;
      if (/^\/archive(-poc)?(\/|$)/.test(location.pathname || '')) {
        location.replace('/hub/');
      }
    } catch (e) {}
  })();

  var STYLE_ID = 'looth-mobile-fixes';
  if (document.getElementById(STYLE_ID)) return;

  var css =
    '@media (max-width:640px){' +
      // Footer: stack the brand column above the link columns, collapse links to
      // two columns, and let the legal row wrap — kills the horizontal overflow.
      '.lg-chrome-foot__inner{grid-template-columns:1fr;gap:28px;padding:32px 18px 24px}' +
      '.lg-chrome-foot__brand{max-width:100%}' +
      '.lg-chrome-foot__cols{grid-template-columns:repeat(2,1fr);gap:18px 20px}' +
      '.lg-chrome-foot__legal{flex-direction:column;align-items:flex-start;gap:8px}' +
      '.lg-chrome-foot{margin-top:36px}' +
    '}' +
    // Very small phones: a single link column reads better than cramped pairs.
    '@media (max-width:380px){' +
      '.lg-chrome-foot__cols{grid-template-columns:1fr}' +
    '}' +
    // Members directory: pin the map below the sticky header so it stays visible
    // while the filters + member list scroll underneath. (Canonical home is
    // directory.css, which is now ubuntu-owned; this guard ships it on the live
    // domain today. Map shrinks to a band so the list keeps room below it.)
    '@media (max-width:760px){' +
      '.dir-map{position:sticky;top:61px;height:240px;z-index:30;' +
      'box-shadow:0 6px 12px -6px rgba(26,29,26,.35)}' +
    '}' +
    // Off-canvas search/filter drawer (.ls-*) + chrome aside spilled ~87px past
    // the phone viewport (backdrop 477px wide, panel 440px parked via transform),
    // forcing the page to render zoomed-out/cramped. Contain them to the viewport.
    '@media (max-width:640px){' +
      // Kill any residual horizontal scroll at the document root. overflow-x:clip
      // (not hidden) does NOT create a scroll container, so the sticky map above
      // and position:fixed chrome keep working. Belt-and-suspenders over the
      // specific drawer/aside containment below.
      'html{overflow-x:clip}' +
      '.ls-back{max-width:100vw;overflow-x:hidden}' +
      '.ls-panel{max-width:86vw}' +
      '.lg-chrome__aside{max-width:100%;overflow:hidden}' +
    '}';

  function inject() {
    if (document.getElementById(STYLE_ID)) return;
    var s = document.createElement('style');
    s.id = STYLE_ID;
    s.textContent = css;
    (document.head || document.documentElement).appendChild(s);
  }

  if (document.head) inject();
  else document.addEventListener('DOMContentLoaded', inject);

  // Mobile only: the bottom tab bar already provides Hub / Events / Members, so
  // remove those duplicate links from the hamburger drawer — leaving the unique
  // items (Archive, Loothtool). On mobile the bottom bar owns page navigation;
  // the redundant menu page-selection is just clutter. Desktop has no bottom bar,
  // so it keeps the full menu. Client-side; the canonical header is coordinator-owned.
  function trimHamburgerDupes() {
    try {
      if (!window.matchMedia('(max-width:640px)').matches) return;
      var menu = document.querySelector('.lg-chrome__menu');
      if (!menu) return;
      var dupe = /^\/(hub|events|directory\/members)\/?$/;
      var links = menu.querySelectorAll('a');
      for (var i = 0; i < links.length; i++) {
        var href = (links[i].getAttribute('href') || '').replace(/[#?].*$/, '');
        if (dupe.test(href)) { var li = links[i].closest('li'); (li || links[i]).remove(); }
      }
    } catch (e) {}
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', trimHamburgerDupes);
  else trimHamburgerDupes();
})();
