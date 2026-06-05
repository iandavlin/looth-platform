/* Hub control-sidebar type-ahead: live search + author autocomplete.
 * Progressive enhancement over the no-JS toolbar forms — if this never runs,
 * the q form still full-searches and the author form still sets one author.
 * Endpoint: <base>/?suggest=hub|author&q=<text>  (see forums/_suggest.php). */
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

  function wire(input, box, mode, onPick) {
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
    }, 180);

    input.addEventListener('input', run);
    input.addEventListener('focus', function () { if (input.value.trim().length >= 2) run(); });
    box.addEventListener('mousedown', function (e) {
      var pick = e.target.closest('[data-pick]');
      if (pick) { e.preventDefault(); onPick(pick.getAttribute('data-pick')); }
    });
    input.addEventListener('keydown', function (e) { if (e.key === 'Escape') hide(box); });
  }

  // Append an author to the current URL's ?author= CSV (dedup) and navigate.
  function addAuthor(name) {
    var u = new URL(window.location.href);
    var cur = (u.searchParams.get('author') || '').split(',').map(function (s) { return s.trim(); }).filter(Boolean);
    if (cur.indexOf(name) === -1) cur.push(name);
    u.searchParams.set('author', cur.join(','));
    u.searchParams.delete('offset');
    window.location.href = u.toString();
  }

  var qIn = wrap.querySelector('[data-hub-search]');
  var qBox = wrap.querySelector('[data-hub-suggest="hub"]');
  if (qIn && qBox) wire(qIn, qBox, 'hub', function () {});

  var aIn = wrap.querySelector('[data-hub-author]');
  var aBox = wrap.querySelector('[data-hub-suggest="author"]');
  if (aIn && aBox) wire(aIn, aBox, 'author', addAuthor);

  // Dismiss any open dropdown on outside click.
  document.addEventListener('click', function (e) {
    if (!e.target.closest('.hub-tsearch')) {
      wrap.querySelectorAll('.hub-suggest').forEach(function (b) { hide(b); });
    }
  });
})();
