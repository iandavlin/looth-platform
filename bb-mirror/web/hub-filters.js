/* Hub control-sidebar interactivity:
 *   - Hub search  = LIVE in-page filter over the unified feed (primary), an AND
 *     dimension with the rail. Typing re-renders #hub-feed-results + the chip bar
 *     and updates the URL. A title dropdown stays as a secondary "quick jump".
 *   - Author box  = autocomplete; pick a name -> appended to the ?author= CSV.
 * Progressive enhancement: without JS the q form full-searches and the author
 * form sets one author (both server-rendered the same way).
 * Endpoint: <base>/?suggest=hub|author&q=<text>  (forums/_suggest.php). */
(function () {
  'use strict';
  var BASE = (window.LG_FORUM_BASE || '/hub').replace(/\/$/, '');
  var wrap = document.querySelector('.feed-toolbar-search');
  if (!wrap) return;

  function debounce(fn, ms) {
    var t; return function () { var a = arguments, c = this;
      clearTimeout(t); t = setTimeout(function () { fn.apply(c, a); }, ms); };
  }
  function esc(s) { return String(s).replace(/[&<>"]/g, function (m) {
    return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[m]; }); }
  function hide(box) { box.hidden = true; box.innerHTML = ''; }

  /* ---- secondary: title quick-jump / author autocomplete dropdowns -------- */
  function wireDropdown(input, box, mode, onPick) {
    var run = debounce(function () {
      var q = input.value.trim();
      if (q.length < 2) { hide(box); return; }
      fetch(BASE + '/?suggest=' + mode + '&q=' + encodeURIComponent(q), { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          if (!d.results || !d.results.length) { hide(box); return; }
          box.innerHTML = d.results.map(function (it) {
            if (mode === 'author') {
              return '<button type="button" class="hub-suggest__item" data-pick="' + esc(it.name) + '">' +
                     esc(it.name) + ' <span class="hub-suggest__n">' + it.n + '</span></button>';
            }
            var label = it.kind === 'discussion' ? 'Discussion' : it.kind;
            return '<a class="hub-suggest__item" href="' + esc(it.url) + '">' +
                   '<span class="hub-suggest__kind">' + esc(label) + '</span>' + esc(it.title) + '</a>';
          }).join('');
          box.hidden = false;
        })
        .catch(function () { hide(box); });
    }, 160);
    input.addEventListener('input', run);
    input.addEventListener('focus', function () { if (input.value.trim().length >= 2) run(); });
    box.addEventListener('mousedown', function (e) {
      var pick = e.target.closest('[data-pick]');
      if (pick) { e.preventDefault(); onPick(pick.getAttribute('data-pick')); }
    });
    input.addEventListener('keydown', function (e) { if (e.key === 'Escape') hide(box); });
  }

  function addAuthor(name) {
    var u = new URL(window.location.href);
    var cur = (u.searchParams.get('author') || '').split(',').map(function (s) { return s.trim(); }).filter(Boolean);
    if (cur.indexOf(name) === -1) cur.push(name);
    u.searchParams.set('author', cur.join(','));
    u.searchParams.delete('offset');
    window.location.href = u.toString();
  }

  /* ---- primary: live in-page feed filter ---------------------------------- */
  var inflight = null;
  function liveSearch(q) {
    var u = new URL(window.location.href);
    if (q) u.searchParams.set('q', q); else u.searchParams.delete('q');
    u.searchParams.delete('offset');
    if (inflight) inflight.abort();
    inflight = (typeof AbortController !== 'undefined') ? new AbortController() : null;
    var results = document.getElementById('hub-feed-results');
    if (results) results.classList.add('is-loading');
    fetch(u.toString(), { credentials: 'same-origin', signal: inflight && inflight.signal })
      .then(function (r) { return r.text(); })
      .then(function (html) {
        var doc = new DOMParser().parseFromString(html, 'text/html');
        var nr = doc.getElementById('hub-feed-results');
        var cr = document.getElementById('hub-feed-results');
        if (nr && cr) cr.replaceWith(nr);
        // chip bar may appear / change / disappear
        var page = document.querySelector('.feed-page');
        var nc = doc.querySelector('.hub-chipbar');
        var cc = document.querySelector('.hub-chipbar');
        if (cc && nc) cc.replaceWith(nc);
        else if (cc && !nc) cc.remove();
        else if (!cc && nc && page) page.insertBefore(nc, page.querySelector('.feed-sort-bar'));
        history.replaceState({}, '', u.toString());
        // let other scripts (comment modal, embeds) re-bind swapped-in cards
        document.dispatchEvent(new CustomEvent('hub:feed-updated'));
      })
      .catch(function () {})
      .finally(function () {
        var el = document.getElementById('hub-feed-results');
        if (el) el.classList.remove('is-loading');
      });
  }

  // Hub search: LIVE in-page filter only — no quick-jump dropdown (the feed IS
  // the result). Author box keeps its autocomplete below.
  var qIn = wrap.querySelector('[data-hub-search]');
  if (qIn) {
    qIn.addEventListener('input', debounce(function () { liveSearch(qIn.value.trim()); }, 280));
    // Enter: just live-filter (don't reload the whole page).
    qIn.closest('form').addEventListener('submit', function (e) { e.preventDefault(); liveSearch(qIn.value.trim()); });
  }

  var aIn  = wrap.querySelector('[data-hub-author]');
  var aBox = wrap.querySelector('[data-hub-suggest="author"]');
  if (aIn && aBox) wireDropdown(aIn, aBox, 'author', addAuthor);

  document.addEventListener('click', function (e) {
    if (!e.target.closest('.hub-tsearch')) {
      wrap.querySelectorAll('.hub-suggest').forEach(function (b) { hide(b); });
    }
  });
})();

/* Category accordion — single-open. Chevron toggles its leaves; opening one
 * collapses any other open parent. The parent NAME stays a filter link. */
(function () {
  'use strict';
  var acc = document.getElementById('hub-cat-accordion');
  if (!acc) return;
  acc.addEventListener('click', function (e) {
    var chev = e.target.closest('.hub-acc__chev');
    if (!chev || chev.classList.contains('hub-acc__chev--none')) return;
    e.preventDefault();
    var item = chev.closest('.hub-acc');
    var willOpen = !item.classList.contains('is-open');
    acc.querySelectorAll('.hub-acc.is-open').forEach(function (o) {
      o.classList.remove('is-open');
      var b = o.querySelector('.hub-acc__leaves'); if (b) b.hidden = true;
      var c = o.querySelector('.hub-acc__chev'); if (c) c.setAttribute('aria-expanded', 'false');
    });
    if (willOpen) {
      item.classList.add('is-open');
      var box = item.querySelector('.hub-acc__leaves'); if (box) box.hidden = false;
      chev.setAttribute('aria-expanded', 'true');
    }
  });
})();
