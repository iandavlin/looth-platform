/* Looth Hub — infinite scroll (client-side, injected site-wide via /pwa.js,
   path-gated to /hub). Makes the activity feed feel endless: as the bottom of
   the feed nears the viewport, the next page is fetched and its cards are
   appended in place — no full-page reload, no "Load older activity" click.

   HOW: reuses the server's EXISTING offset pagination. The page already renders
   a `.feed-more__btn` anchor (href `/hub/?sort=...&offset=N`) as a sibling after
   the `.feed` grid. We watch a 1px sentinel placed after `.feed-more`; when it
   enters an 800px pre-load margin we fetch that href, parse the returned HTML,
   move its `.feed > .feed-card` nodes into the live `.feed`, then swap the
   `.feed-more` contents for the fetched page's next link (advancing the offset).
   When a fetched page has no `.feed-more`, we're at the end: tear down and stop.

   The appended cards carry the same classes, so hub-polish.css applies to them
   automatically. A manual click on the button still works (delegated → same
   AJAX path, so it appends instead of navigating). Self-contained, no deps,
   no emoji. No-op off /hub or if the feed/pager markup is absent. */
(function () {
  'use strict';
  if (window.__loothHubInfinite) return;
  window.__loothHubInfinite = true;

  function onHubPath() { return /^\/hub(\/|$)/.test(location.pathname || '/'); }
  if (!onHubPath()) return;

  var SENTINEL_ID = 'looth-hub-sentinel';
  var STYLE_ID = 'looth-hub-infinite-style';
  var loading = false, done = false, io = null;

  function liveFeed() { return document.querySelector('.feed'); }
  function liveMore() { return document.querySelector('.feed-more'); }
  function nextUrl() {
    var btn = document.querySelector('.feed-more__btn');
    return btn ? btn.getAttribute('href') : null;
  }

  function injectStyles() {
    if (document.getElementById(STYLE_ID)) return;
    var css =
      '.feed-more[data-lg-loading="1"] .feed-more__btn{opacity:.55;pointer-events:none}' +
      '#' + SENTINEL_ID + '{height:1px;width:100%}';
    var s = document.createElement('style');
    s.id = STYLE_ID;
    s.textContent = css;
    (document.head || document.documentElement).appendChild(s);
  }

  function ensureSentinel() {
    var s = document.getElementById(SENTINEL_ID);
    if (s) return s;
    var more = liveMore();
    if (!more || !more.parentNode) return null;
    s = document.createElement('div');
    s.id = SENTINEL_ID;
    more.parentNode.insertBefore(s, more.nextSibling);
    return s;
  }

  function setBusy(b) {
    var more = liveMore();
    if (more) more.setAttribute('data-lg-loading', b ? '1' : '');
  }

  function teardown() {
    if (io) { io.disconnect(); io = null; }
    var s = document.getElementById(SENTINEL_ID);
    if (s && s.parentNode) s.parentNode.removeChild(s);
  }

  function maybeContinue() {
    // If the sentinel is still within the pre-load margin after a load (short
    // page / tall viewport), keep going — IntersectionObserver only fires on
    // CHANGES, so a still-visible sentinel would otherwise stall.
    var s = document.getElementById(SENTINEL_ID);
    if (!s) return;
    var r = s.getBoundingClientRect();
    if (r.top < (window.innerHeight || 0) + 800) loadMore();
  }

  function loadMore() {
    if (loading || done) return;
    var url = nextUrl();
    if (!url) { done = true; teardown(); return; }
    loading = true;
    setBusy(true);
    fetch(url, { credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch' } })
      .then(function (r) { return r.ok ? r.text() : Promise.reject(r.status); })
      .then(function (html) {
        var doc = new DOMParser().parseFromString(html, 'text/html');
        var srcFeed = doc.querySelector('.feed');
        var feed = liveFeed();
        if (!srcFeed || !feed) { done = true; teardown(); return; }
        var cards = srcFeed.querySelectorAll('.feed-card');
        if (!cards.length) { done = true; teardown(); return; }
        var frag = document.createDocumentFragment();
        for (var i = 0; i < cards.length; i++) frag.appendChild(document.importNode(cards[i], true));
        feed.appendChild(frag);
        var more = liveMore();
        var srcMore = doc.querySelector('.feed-more');
        if (more) {
          if (srcMore) {
            more.innerHTML = srcMore.innerHTML; // fresh button → advanced offset
          } else if (more.parentNode) {
            more.parentNode.removeChild(more); // server says: no more pages
            done = true;
          }
        } else {
          done = true;
        }
      })
      .catch(function () { /* leave the manual button as a working fallback */ })
      .then(function () {
        loading = false;
        setBusy(false);
        if (done) { teardown(); return; }
        ensureSentinel();
        maybeContinue();
      });
  }

  function start() {
    if (!liveFeed() || !liveMore()) return; // listing pages with a pager only
    injectStyles();
    if (!ensureSentinel()) return;
    io = new IntersectionObserver(function (entries) {
      for (var i = 0; i < entries.length; i++) {
        if (entries[i].isIntersecting) { loadMore(); break; }
      }
    }, { rootMargin: '0px 0px 800px 0px' });
    io.observe(document.getElementById(SENTINEL_ID));

    // Delegated: a manual button click also appends via AJAX (survives the
    // innerHTML swaps) instead of doing a full-page navigation.
    document.addEventListener('click', function (e) {
      var btn = e.target && e.target.closest && e.target.closest('.feed-more__btn');
      if (!btn) return;
      e.preventDefault();
      loadMore();
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start);
  } else {
    start();
  }
})();
