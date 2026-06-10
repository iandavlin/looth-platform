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
      // Hide the redundant "add emoji" (☺+) button on mobile — press-and-hold the
      // like opens the reaction picker instead (Buck 2026-06-08). Kept in the DOM
      // (display:none) so mobile-hub's long-press openShared can still find it.
      '.fcr-add{display:none!important}' +
    '}' +
    // Very small phones: a single link column reads better than cramped pairs.
    '@media (max-width:380px){' +
      '.lg-chrome-foot__cols{grid-template-columns:1fr}' +
    '}' +
    // Members directory: pin the map below the sticky header so it stays visible
    // while the filters + member list scroll underneath. (Canonical home is
    // directory.css, which is now ubuntu-owned; this guard ships it on the live
    // domain today. Map shrinks to a band so the list keeps room below it.)
    '@media (max-width:640px){' +
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
      // Hub top-bar fixes for the v54 absorption regressions (Buck, 2026-06-06):
      //  (1) the sort/filter/new-post bar went position:static and scrolled away on
      //      scroll — pin it just under the 61px sticky header so it stays reachable;
      //  (2) the theme/text/compact toggles got crammed into the top bar — hide them
      //      (theme lives in the profile-bubble Settings). Temporary client guard until
      //      the canonical forums fix lands; coordinator notified.
      '.feed-sort-bar{position:sticky;top:61px;z-index:40;background:var(--lg-cream,#fbfbf8);transition:transform .25s ease}' +
      // The header (which holds the search bar) auto-hides on scroll via .lg-chrome--tuck;
      // tuck the sort bar away WITH it so it does not float alone (Buck, 2026-06-06).
      '.feed-sort-bar.lg-sortbar-tuck{transform:translateY(-120px)}' +
      '.feed-view-toggles{display:none !important}' +
      // The Filters chip hamburger glyph (.corner-hamburger__icon) carries legacy
      // corner-menu styling: position:absolute with corner offsets. Its button
      // (.lg-filters-chip) is position:static, so the icon escaped its parent and
      // floated to the top-left, overlapping the first sort tab (Random/Fresh) — the
      // "hamburger remnant" Buck reported (2026-06-07). Pin it back inside the chip.
      '.feed-sort-bar .lg-filters-chip .corner-hamburger__icon{position:static;inset:auto;margin-right:4px}' +
      // Lock the sort bar to the viewport edges (Buck, 2026-06-07: "new posts is
      // slightly off the screen, I want the edges locked"). With THREE sort tabs
      // (Random/Newest/Trending) the nowrap row (~479px) overflowed ~366px and pushed
      // "+ New post" off the right edge. Collapse the two right-hand ACTIONS to compact
      // icon button (Filters → ☰); the New-post pill keeps a label but a SHORT one
      // ("+ Create" instead of "+ New post", Buck 2026-06-07) and the sort tabs are
      // trimmed slightly so the whole row still fits the locked edges.
      '.feed-page .feed-sort-bar{max-width:100%;box-sizing:border-box;gap:5px;overflow-x:auto;scrollbar-width:none;-ms-overflow-style:none}' +
      '.feed-page .feed-sort-bar::-webkit-scrollbar{display:none}' +
      '.feed-page .feed-sort-bar>a{padding-left:8px;padding-right:8px}' +
      '.feed-page .feed-sort-bar>.lg-filters-chip{margin-left:auto;padding:7px 10px;margin-right:0}' +
      '.feed-page .feed-sort-bar>.lg-filters-chip .lg-filters-chip__tx{display:none}' +
      '.feed-page .feed-sort-bar>.lg-newpost{font-size:0;gap:0;padding:7px 12px}' +
      '.feed-page .feed-sort-bar>.lg-newpost::before{content:"+ Create";font-size:13px;font-weight:700;line-height:1;display:inline-block;white-space:nowrap}' +
      // (Card-header category-dup stopgap RETIRED 2026-06-07: canonical commit ccd6350
      // "fix mobile category dup — default-hide .fc-cat-chip, show only >=641" now owns
      // this, so the app-mobile-fixes hack is no longer needed.)
      // Consolidate the DUPLICATE comment/reply controls on CONTENT cards (Buck
      // 2026-06-07: loothprints/articles/videos showed BOTH a "💬 Comment" button AND a
      // "Reply" action). The canonical content card renders feed_action_bar(0) (→ the
      // .lg-act-replies "Reply", which hub-polish wires to open the comments modal) AND a
      // standalone .feed-card__comments-btn. On MOBILE both show. Keep the Reply (it opens
      // the same thread) and hide the redundant comment button. (Desktop ≥641 keeps the
      // comment button — its action row is hidden there, so it's the only access; this
      // rule is mobile-only.) Proper consolidation + a comment-count on Reply = canonical
      // follow-up, relayed to the coordinator.
      '.feed-page .feed-card--content .feed-card__comments-btn{display:none}' +
      // TEXT SIZE: the canonical MOBILE card title/excerpt are FIXED px (they ignore the
      // user size — Buck: "Small still looks big"). Re-tie the prominent Hub text to the
      // user scale var (--lguser-scale, which app-settings drives off the Settings size)
      // so Small/Default/Large/Larger actually resize the whole card. (Reply/secondary
      // text already keys off --lguser-scale.) (Buck 2026-06-08.)
      '.feed-page .feed-card__title{font-size:calc(18px*var(--lguser-scale,1)) !important}' +
      '.feed-page .feed-card--content .feed-card__title{font-size:calc(20px*var(--lguser-scale,1)) !important}' +
      '.feed-page .feed-card__op-excerpt,.feed-page .feed-card__full-body{font-size:calc(15px*var(--lguser-scale,1)) !important}' +
      // iOS: a long-press on the engagement rows was firing Safari text-selection +
      // image copy/paste callouts instead of the reaction picker (Ian, 2026-06-06).
      // Kill the iOS touch-callout + text selection on BOTH engagement rows and the
      // canonical reaction picker (mobile-hub.js's wireLongPressReactions now owns the
      // long-press → it opens the persisted .fcr palette; the old visual-only
      // .lg-react-bar floating bar was removed 2026-06-07, unified on the real picker).
      '.lg-card-actions,.lg-card-actions *,.fc-actions,.fcr,.fcr-palette,.fcr-palette *{' +
        '-webkit-touch-callout:none;-webkit-user-select:none;user-select:none}' +
      // (Theme-normalize block REMOVED 2026-06-10 bespoke-cutover: it keyed on
      // the retired hub-theme-* classes — dead code — and mobile now honors the
      // user's own Light/Dark pick from the gear.)
      ''+
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

  // killCompactOnMobile RETIRED here 2026-06-10 (bespoke-cutover, audit C6): it was
  // a byte-identical duplicate of mobile-hub.js §1, which owns it (mobile behaviors
  // layer, ≤640-gated at load). One implementation, one owner.

  // Mobile only: the sort/filter bar should hide together with the search bar.
  // The header (#site-header, which contains the .lg-hub-search bar) auto-hides on
  // scroll-down by toggling .lg-chrome--tuck. Mirror that state onto the sort bar so
  // the two move as one - hide on scroll-down, reveal on scroll-up (Buck, 2026-06-06).
  // Desktop: matchMedia is false, so the observer is never set up.
  function tieSortBarToHeaderTuck() {
    try {
      if (!window.matchMedia('(max-width:640px)').matches) return;
      var hdr = document.getElementById('site-header') || document.querySelector('.lg-chrome');
      if (!hdr) return;
      var sync = function () {
        var sb = document.querySelector('.feed-sort-bar');
        if (sb) sb.classList.toggle('lg-sortbar-tuck', hdr.classList.contains('lg-chrome--tuck'));
      };
      sync();
      if ('MutationObserver' in window) {
        new MutationObserver(sync).observe(hdr, { attributes: true, attributeFilter: ['class'] });
      }
    } catch (e) {}
  }
  tieSortBarToHeaderTuck();
  document.addEventListener('DOMContentLoaded', tieSortBarToHeaderTuck);

  // ── Fullscreen video → auto-rotate to landscape (Buck 2026-06-08) ───────────
  // When a YouTube (or any) video goes fullscreen on a phone, lock the screen to
  // landscape; restore (unlock → portrait) when leaving fullscreen. Android Chrome
  // honors screen.orientation.lock inside fullscreen; iOS Safari ignores it but its
  // native video fullscreen already auto-rotates, so it's a harmless no-op there.
  // Mobile only — desktop never locks orientation.
  (function () {
    if (!window.matchMedia('(max-width:640px)').matches && !window.matchMedia('(pointer:coarse)').matches) return;
    function onFsChange() {
      var fsEl = document.fullscreenElement || document.webkitFullscreenElement || document.msFullscreenElement;
      try {
        var so = screen.orientation;
        if (fsEl) {
          if (so && so.lock) so.lock('landscape').catch(function () {});   // entering → landscape
        } else {
          if (so && so.unlock) so.unlock();                               // leaving → back to natural/portrait
        }
      } catch (e) {}
    }
    document.addEventListener('fullscreenchange', onFsChange, false);
    document.addEventListener('webkitfullscreenchange', onFsChange, false);
  })();

  // ── Rotate phone to landscape → put the playing video FULLSCREEN (Buck 2026-06-08).
  // The inverse of the lock above: when the user turns the phone sideways while a
  // feed video is playing, take it fullscreen; turning back to portrait exits.
  // BEST-EFFORT: the Fullscreen API usually needs a user gesture, and an
  // orientationchange may not count as one on every browser (Android Chrome often
  // allows it shortly after rotation; iOS only fullscreens native <video>, not
  // YouTube iframes — but iOS already rotates its own player). Harmless if blocked.
  (function () {
    if (!window.matchMedia('(max-width:640px)').matches && !window.matchMedia('(pointer:coarse)').matches) return;
    function isLandscape() {
      try { if (screen.orientation && /landscape/.test(screen.orientation.type)) return true; } catch (e) {}
      return window.innerWidth > window.innerHeight;
    }
    function playingVid() {
      if (document.fullscreenElement || document.webkitFullscreenElement) return null;
      return document.querySelector('.fc-cover--video iframe.fc-video') || document.querySelector('iframe.fc-video');
    }
    function reqFs(el) { var fn = el.requestFullscreen || el.webkitRequestFullscreen || el.webkitEnterFullscreen; if (fn) { try { fn.call(el); } catch (e) {} } }
    function exitFs() { var fn = document.exitFullscreen || document.webkitExitFullscreen; if (fn) { try { fn.call(document); } catch (e) {} } }
    function onRotate() {
      try {
        if (isLandscape()) { var v = playingVid(); if (v) reqFs(v); }
        else {
          var fs = document.fullscreenElement || document.webkitFullscreenElement;
          if (fs && fs.classList && fs.classList.contains('fc-video')) exitFs();
        }
      } catch (e) {}
    }
    try { if (screen.orientation && screen.orientation.addEventListener) screen.orientation.addEventListener('change', function () { setTimeout(onRotate, 130); }); } catch (e) {}
    window.addEventListener('orientationchange', function () { setTimeout(onRotate, 130); });
  })();

  // Mobile reactions: the old visual-only "heart, hold for more options" floating
  // emoji bar (wireMobileReactions / .lg-react-bar) was REMOVED 2026-06-07. It never
  // persisted, and it collided with mobile-hub.js's wireLongPressReactions — two
  // long-press handlers fought on every card, so the picker flashed then vanished
  // before you could tap it (Buck report). We unified on the REAL picker: the
  // canonical, persisted .fcr palette, opened by mobile-hub.js's long-press. Nothing
  // reaction-related lives here anymore.
})();
