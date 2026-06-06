/* bb-mirror/web/forums.js
 *
 * Feature areas:
 *   1. Corner hamburger: toggle left-nav open/closed on desktop + drawer on mobile.
 *   2. Feed reply-stack expand: inline reveal of older replies on click.
 *   2b. Feed full-body expand: Read more / Read less inline toggle.
 *   3. Forum pages (unread + reply form + mark-seen) — unchanged.
 */
(function () {
  'use strict';

  // Forum mount base — single source, injected by _chrome.php (window.LG_FORUM_BASE).
  // Never hardcode a path; this makes the /forums-poc → /forum flip (and any
  // future rename) a one-line config change. Fallback matches the launch base.
  var FORUM_BASE = (window.LG_FORUM_BASE || '/forum').replace(/\/+$/, '');

  // ── Text-size toggle (pill beside Compact) ───────────────────────────────
  // 3-state cycle: Normal → Large → Larger → Normal. Scales --lg-read-scale
  // (post/reply/card body copy only). Persists per browser; aria-pressed +
  // data-level + label drive the pill's look.
  (function () {
    var KEY = 'lg_hub_read_level';
    var SCALES = [1, 1.25, 1.5];
    var LABELS = ['Text size', 'Large text', 'Larger text'];
    function level() { var n = parseInt(localStorage.getItem(KEY), 10); return (n === 1 || n === 2) ? n : 0; }
    function apply(n) {
      document.documentElement.style.setProperty('--lg-read-scale', String(SCALES[n]));
      var btn = document.querySelector('.feed-text-toggle');
      if (!btn) return;
      btn.setAttribute('aria-pressed', n > 0 ? 'true' : 'false');
      btn.setAttribute('data-level', String(n));
      var label = btn.querySelector('.feed-text-toggle__label');
      if (label) label.textContent = LABELS[n];
    }
    apply(level());
    document.addEventListener('click', function (e) {
      var btn = e.target.closest && e.target.closest('.feed-text-toggle');
      if (!btn) return;
      var n = (level() + 1) % 3;
      try { localStorage.setItem(KEY, String(n)); } catch (_) {}
      apply(n);
    });
  })();

  // ── Color theme toggle (pill beside Text size) ───────────────────────────
  // 4-state cycle: Default → Panels → Dark → Black. Toggles a class on <html>
  // (hub-theme-panel / hub-theme-dark / hub-theme-black) that re-points the
  // design tokens. Persists per browser; the before-paint script in _chrome.php
  // applies it on load so there's no flash. Mirror of the text-size cycle above.
  (function () {
    var KEY = 'lg_hub_theme';
    var CLASSES = ['', 'hub-theme-panel', 'hub-theme-dark', 'hub-theme-black'];
    var LABELS  = ['Theme', 'Panels', 'Dark', 'Black'];
    function level() { var n = parseInt(localStorage.getItem(KEY), 10); return (n >= 1 && n <= 3) ? n : 0; }
    function apply(n) {
      var de = document.documentElement;
      de.classList.remove('hub-theme-panel', 'hub-theme-dark', 'hub-theme-black');
      if (CLASSES[n]) de.classList.add(CLASSES[n]);
      var btn = document.querySelector('.feed-theme-toggle');
      if (!btn) return;
      btn.setAttribute('aria-pressed', n > 0 ? 'true' : 'false');
      btn.setAttribute('data-level', String(n));
      var label = btn.querySelector('.feed-theme-toggle__label');
      if (label) label.textContent = LABELS[n];
    }
    apply(level());
    document.addEventListener('click', function (e) {
      var btn = e.target.closest && e.target.closest('.feed-theme-toggle');
      if (!btn) return;
      var n = (level() + 1) % 4;
      try { localStorage.setItem(KEY, String(n)); } catch (_) {}
      apply(n);
    });
  })();

  // Clickable card: a click anywhere on a feed card navigates to its topic,
  // EXCEPT on real interactive elements (links/buttons/inputs) or while the
  // user is selecting text. data-href is the topic URL.
  document.addEventListener('click', function (e) {
    var card = e.target.closest('.feed-card--topic[data-href]');
    if (!card) return;
    // Skip interactive elements AND images (images open the lightbox below).
    if (e.target.closest('a, button, input, textarea, select, label, [role=\"button\"], img')) return;
    if (window.getSelection && String(window.getSelection()).length) return;
    window.location.href = card.dataset.href;
  });

  // ── Image lightbox: click any forum image to view it full-size ──────────────
  // Delegated so it covers lazily-loaded thread/body images. Picks the best URL:
  // attachment link href (full-res) > a wrapping image link > the <img> src.
  (function () {
    var lb, lbImg;
    function ensure() {
      if (lb) return;
      lb = document.createElement('div');
      lb.className = 'lg-lightbox'; lb.hidden = true;
      lb.innerHTML = '<button class="lg-lightbox__close" type="button" aria-label="Close">✕</button>'
                   + '<img class="lg-lightbox__img" alt="">';
      lbImg = lb.querySelector('.lg-lightbox__img');
      document.body.appendChild(lb);
      lb.addEventListener('click', function (e) { if (e.target !== lbImg) closeLb(); });
    }
    function openLb(url) {
      if (!url) return;
      ensure();
      lbImg.src = url;
      lb.hidden = false;
      document.body.classList.add('ntm-active');   // reuse scroll-lock
      requestAnimationFrame(function () { lb.classList.add('is-open'); });
    }
    function closeLb() {
      if (!lb) return;
      lb.classList.remove('is-open');
      lb.hidden = true; lbImg.removeAttribute('src');
      document.body.classList.remove('ntm-active');
    }
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && lb && !lb.hidden) closeLb();
    });
    function imgExt(href) { return href && /\.(jpe?g|png|gif|webp|avif)(\?|#|$)/i.test(href); }

    document.addEventListener('click', function (e) {
      // 1) attachment-gallery image (wrapped in a.attachment--image → full-res href)
      var alink = e.target.closest('a.attachment--image');
      if (alink) { e.preventDefault(); openLb(alink.getAttribute('href')); return; }
      // 2) feed cover image: let it CLICK THROUGH to the post (the a.feed-card__cover
      //    href) instead of opening the lightbox — covers are post links, not gallery
      //    images. (Body/reply images below still lightbox.)
      if (e.target.closest('.feed-card__cover')) return;
      // 3) bare content / reply images (deferred ones have no src yet → skip)
      var img = e.target.closest('.reply-stub__img, .post__body img, .feed-card__full-body img');
      if (img && img.tagName === 'IMG' && img.getAttribute('src')) {
        var wrap = img.closest('a[href]');
        var href = (wrap && imgExt(wrap.getAttribute('href'))) ? wrap.getAttribute('href') : (img.currentSrc || img.src);
        e.preventDefault(); openLb(href);
      }
    });
  })();

  // ── 1. Corner hamburger ──────────────────────────────────────────────────
  // Desktop: default = nav visible; hamburger adds body.nav-closed to hide it.
  // Mobile:  default = nav hidden;  hamburger adds body.nav-open to show drawer.
  const ham     = document.getElementById('bb-ham');
  const overlay = document.getElementById('bb-overlay');

  if (ham) {
    ham.addEventListener('click', function () {
      const mobile = window.innerWidth <= 960;
      if (mobile) {
        const opening = document.body.classList.toggle('nav-open');
        ham.setAttribute('aria-expanded', opening ? 'true' : 'false');
        overlay.setAttribute('aria-hidden', opening ? 'false' : 'true');
      } else {
        const closing = document.body.classList.toggle('nav-closed');
        ham.setAttribute('aria-expanded', closing ? 'false' : 'true');
      }
    });
  }

  if (overlay) {
    overlay.addEventListener('click', function () {
      document.body.classList.remove('nav-open');
      if (ham) ham.setAttribute('aria-expanded', 'false');
      overlay.setAttribute('aria-hidden', 'true');
    });
  }

  // ── 1b. Nav section accordions ───────────────────────────────────────────
  document.querySelectorAll('.nav-tree__section').forEach(function (sec) {
    var toggle = sec.querySelector('.nav-tree__section-toggle');
    if (!toggle) return;
    toggle.addEventListener('click', function () {
      var willOpen = !sec.classList.contains('nav-tree__section--open');
      if (willOpen) {
        // single-expand: collapse any other open section first
        document.querySelectorAll('.nav-tree__section--open').forEach(function (o) {
          if (o === sec) return;
          o.classList.remove('nav-tree__section--open');
          var t = o.querySelector('.nav-tree__section-toggle');
          if (t) t.setAttribute('aria-expanded', 'false');
        });
      }
      sec.classList.toggle('nav-tree__section--open', willOpen);
      toggle.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
    });
  });

  // ── 1b2. Rail accordion is native <details>. ────────────────────────────────
  // Type/Categories sections + category parents open/close with zero JS. The
  // ONLY enhancement: guarantee single-open on the named top-level sections for
  // browsers without native <details name> exclusive-accordion (pre-2024
  // Safari/Firefox), where two could otherwise be open at once.
  document.querySelectorAll('details.hub-rail__sec[name]').forEach(function (d) {
    d.addEventListener('toggle', function () {
      if (!d.open) return;
      document.querySelectorAll('details.hub-rail__sec[name="' + d.getAttribute('name') + '"]').forEach(function (o) {
        if (o !== d) o.open = false;
      });
    });
  });

  // ── 1b4. Card prototype: expand-in-place to "max" (flag-gated) ──────────────
  // Flag: ?proto=cards (sticky via localStorage; ?proto=off clears). When on,
  // clicking a feed card opens it to the max tier IN PLACE (single-open) and
  // lazy-loads the full body + full reply thread through the existing WP-free
  // endpoints — the "no click-through" Hub direction. Without the flag the feed
  // is untouched. One slice to validate the interaction on real data.
  (function () {
    try {
      if (/[?&]proto=cards/.test(location.search)) localStorage.setItem('lg_card_proto', '1');
      else if (/[?&]proto=off/.test(location.search)) localStorage.removeItem('lg_card_proto');
    } catch (e) {}
    var protoOn; try { protoOn = localStorage.getItem('lg_card_proto') === '1'; } catch (e) { protoOn = false; }
    var feed = document.querySelector('.feed');
    if (!protoOn || !feed) return;
    feed.classList.add('feed--proto');

    // Shared auth/nonce (fetched once) for inline reply compose.
    var protoAuth = null, protoAuthPending = null;
    function protoGetAuth(cb) {
      if (protoAuth) { cb(protoAuth); return; }
      if (protoAuthPending) { protoAuthPending.push(cb); return; }
      protoAuthPending = [cb];
      fetch('/bb-mirror-api/v0/auth.php', { credentials: 'same-origin' })
        .then(function (r) { return r.ok ? r.json() : null; })
        .then(function (d) { protoAuth = d || { authenticated: false }; protoAuthPending.forEach(function (f) { f(protoAuth); }); protoAuthPending = null; })
        .catch(function () { protoAuth = { authenticated: false }; protoAuthPending.forEach(function (f) { f(protoAuth); }); protoAuthPending = null; });
    }
    var protoReplyBase = (document.getElementById('frm-form') || { dataset: {} }).dataset.restBase || '/wp-json/buddyboss/v1';

    // Moderator Trash on thread reply stubs (BB-style admin action, in-feed).
    // Reveal the trash controls when the viewer can moderate; the DELETE endpoint
    // re-checks caps server-side, so the UI gate is convenience, not security.
    protoGetAuth(function (auth) { if (auth && auth.can_edit_others) feed.classList.add('feed--can-moderate'); });

    // Moderator Edit — inline editor on a thread reply stub (PUT /reply/{id};
    // topic/forum read from the card). Text-only for now; server re-checks caps.
    feed.addEventListener('click', function (ev) {
      var e = ev.target.closest('.reply-stub__edit');
      if (!e || !feed.contains(e)) return;
      ev.preventDefault(); ev.stopPropagation();
      var stub = e.closest('.reply-stub');
      if (!stub || stub.querySelector('.reply-stub__editbox')) return;
      var bodyDiv = stub.querySelector('.reply-stub__body');
      var excerpt = stub.querySelector('.reply-stub__excerpt');
      var card = e.closest('.feed-card');
      var cta = card && card.querySelector('.feed-card__reply-cta[data-frm-open]');
      var id = parseInt(e.getAttribute('data-reply-id'), 10);
      var topicId = card ? parseInt(card.getAttribute('data-topic-id'), 10) : 0;
      var forumId = cta ? parseInt(cta.dataset.forumId, 10) : 0;
      var cur = excerpt ? (excerpt.innerText || excerpt.textContent || '').trim() : '';
      var box = document.createElement('div');
      box.className = 'reply-stub__editbox';
      box.innerHTML = '<textarea class="rse-input"></textarea>' +
        '<div class="rse-row"><button type="button" class="rse-save">Save</button>' +
        '<button type="button" class="rse-cancel">Cancel</button><span class="rse-status"></span></div>';
      box.querySelector('.rse-input').value = cur;
      if (bodyDiv) { bodyDiv.style.display = 'none'; bodyDiv.parentNode.insertBefore(box, bodyDiv.nextSibling); }
      else { stub.appendChild(box); }
      var ta = box.querySelector('.rse-input'); ta.focus();
      ta.style.height = 'auto'; ta.style.height = Math.min(ta.scrollHeight, 200) + 'px';
      box.querySelector('.rse-cancel').addEventListener('click', function () { box.remove(); if (bodyDiv) bodyDiv.style.display = ''; });
      box.querySelector('.rse-save').addEventListener('click', function () {
        var text = ta.value.trim(); var status = box.querySelector('.rse-status');
        if (!text) { status.textContent = "Can't be empty."; return; }
        if (!id || !topicId) { status.textContent = 'Missing reply/topic.'; return; }
        status.textContent = 'Saving…';
        protoGetAuth(function (auth) {
          if (!auth || !auth.nonce) { status.textContent = 'Not signed in.'; return; }
          var html = '<p>' + text.replace(/[&<>]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;' }[c]; }).replace(/\n/g, '<br>') + '</p>';
          fetch(protoReplyBase + '/reply/' + id, {
            method: 'PUT', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': auth.nonce },
            body: JSON.stringify({ id: id, topic_id: topicId, forum_id: forumId || undefined, content: html }),
          })
            .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }, function () { return { ok: r.ok, j: {} }; }); })
            .then(function (res) {
              if (!res.ok) { status.textContent = (res.j && (res.j.message || res.j.code)) || 'Could not save.'; return; }
              if (excerpt) excerpt.innerHTML = html;
              box.remove(); if (bodyDiv) bodyDiv.style.display = '';
            })
            .catch(function (err) { status.textContent = 'Network error: ' + err.message; });
        });
      });
    });

    feed.addEventListener('click', function (ev) {
      var t = ev.target.closest('.reply-stub__trash');
      if (!t || !feed.contains(t)) return;
      ev.preventDefault(); ev.stopPropagation();
      var id = parseInt(t.getAttribute('data-reply-id'), 10);
      if (!id || !window.confirm('Trash this reply? This can’t be undone.')) return;
      protoGetAuth(function (auth) {
        if (!auth || !auth.nonce) { alert('Not signed in.'); return; }
        t.disabled = true;
        fetch(protoReplyBase + '/reply/' + id, {
          method: 'DELETE', credentials: 'same-origin', headers: { 'X-WP-Nonce': auth.nonce },
        })
          .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }, function () { return { ok: r.ok, j: {} }; }); })
          .then(function (res) {
            if (!res.ok) { t.disabled = false; alert('Could not trash: ' + ((res.j && (res.j.message || res.j.code)) || 'failed')); return; }
            var stub = t.closest('.reply-stub'); if (stub) stub.remove();
          })
          .catch(function (err) { t.disabled = false; alert('Network error: ' + err.message); });
      });
    });

    // Inline reply composer in the expanded discussion card — post in-feed, no modal.
    function protoMountComposer(card) {
      if (card.querySelector('.feed-card__inline-compose')) return;
      var cta = card.querySelector('.feed-card__reply-cta[data-frm-open]');
      var topicId = card.getAttribute('data-topic-id') || (cta && cta.dataset.topicId) || '';
      var forumId = (cta && cta.dataset.forumId) || '';
      if (!topicId) return;
      var box = document.createElement('div');
      box.className = 'feed-card__inline-compose';
      box.innerHTML =
        '<textarea class="fic-input" rows="1" placeholder="Reply to this thread…"></textarea>' +
        '<button type="button" class="fic-send" disabled>Reply</button>' +
        '<div class="fic-status" role="status"></div>';
      (card.querySelector('.feed-card__replies') || card).appendChild(box);
      var ta = box.querySelector('.fic-input'), send = box.querySelector('.fic-send'), status = box.querySelector('.fic-status');
      ta.addEventListener('input', function () { send.disabled = !ta.value.trim(); ta.style.height = 'auto'; ta.style.height = ta.scrollHeight + 'px'; });
      send.addEventListener('click', function () {
        var text = ta.value.trim(); if (!text) return;
        send.disabled = true; status.textContent = 'Posting…';
        protoGetAuth(function (auth) {
          if (!auth || !auth.authenticated) { status.textContent = 'Sign in to reply.'; return; }
          var html = '<p>' + text.replace(/[&<>]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;' }[c]; }).replace(/\n/g, '<br>') + '</p>';
          var payload = { topic_id: parseInt(topicId, 10), content: html };
          if (parseInt(forumId, 10)) payload.forum_id = parseInt(forumId, 10);
          fetch(protoReplyBase + '/reply', {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': auth.nonce },
            body: JSON.stringify(payload),
          })
            .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
            .then(function (res) {
              if (!res.ok) { status.textContent = (res.j && (res.j.message || res.j.code)) || 'Could not post.'; send.disabled = false; return; }
              ta.value = ''; ta.style.height = 'auto'; status.textContent = 'Posted ✓ — refreshing thread…';
              var ex = card.querySelector('.feed-card__expand');   // reload the thread to show the new reply
              if (ex) { card.classList.remove('replies-expanded'); var full = card.querySelector('.feed-card__replies-full'); if (full) { full.dataset.loaded = ''; } ex.click(); }
            })
            .catch(function (err) { status.textContent = 'Network error: ' + err.message; send.disabled = false; });
        });
      });
    }

    feed.addEventListener('click', function (ev) {
      var card = ev.target.closest('.feed-card');
      if (!card || !feed.contains(card)) return;

      // CPT / content cards CLICK THROUGH to the full post (Ian 6/6). Real links +
      // controls work; a bare-area click navigates to the post.
      if (card.classList.contains('feed-card--content')) {
        if (ev.target.closest('a, button, input, textarea, label, select, [data-comments], .feed-card__compact-expand')) return;
        var href = card.getAttribute('data-href');
        if (href) window.location.href = href;
        return;
      }

      // Discussion (topic) cards: expand IN PLACE — no click-through. The title +
      // body + bare area expand; real controls (read-more, reply, view-replies,
      // author/profile links, thread links) keep working.
      if (ev.target.closest('button, input, textarea, label, select, .feed-card__compact-expand, ' +
            '.feed-card__read-more, .feed-card__expand, .feed-card__reply-cta, .reply-stub__reply')) return;
      // author + in-thread links still navigate; only the title link is hijacked to expand
      if (ev.target.closest('a') && !ev.target.closest('.feed-card__title a')) return;
      var titleA = ev.target.closest('.feed-card__title a');
      if (titleA) ev.preventDefault();

      var willOpen = !card.classList.contains('feed-card--max');
      feed.querySelectorAll('.feed-card--max').forEach(function (c) { if (c !== card) c.classList.remove('feed-card--max'); });
      card.classList.toggle('feed-card--max', willOpen);
      if (!willOpen) return;
      var rm = card.querySelector('.feed-card__read-more');          // lazy full body
      if (rm && rm.dataset.state !== 'expanded') rm.click();
      var ex = card.querySelector('.feed-card__expand');             // lazy full thread (?replies=)
      if (ex && !card.classList.contains('replies-expanded')) ex.click();
      protoMountComposer(card);                                       // inline reply box (no modal)
      card.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    });
  })();

  // ── 1c. Admin: set forum header image (pencil) ──────────────────────────────
  var hdrEdit = document.querySelector('.forum-header__edit-img');
  if (hdrEdit) {
    fetch('/bb-mirror-api/v0/auth.php', { credentials: 'same-origin' })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (data) {
        if (!data || !data.authenticated || !data.can_edit_others) return;
        var nonce = data.nonce;
        hdrEdit.hidden = false;
        hdrEdit.addEventListener('click', function () {
          var input = document.createElement('input');
          input.type = 'file'; input.accept = 'image/*';
          input.onchange = function () {
            var file = input.files && input.files[0];
            if (!file) return;
            hdrEdit.disabled = true; hdrEdit.textContent = '…';
            var fd = new FormData(); fd.append('file', file);
            fetch('/wp-json/buddyboss/v1/media/upload', {
              method: 'POST', credentials: 'same-origin',
              headers: { 'X-WP-Nonce': nonce }, body: fd,
            })
              .then(function (r) { return r.json(); })
              .then(function (up) {
                if (!up || !up.upload_id) throw new Error('upload failed');
                // Send the attachment id; the endpoint resolves a clean public URL.
                return fetch('/bb-mirror-api/v0/set-forum-image', {
                  method: 'POST', credentials: 'same-origin',
                  headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
                  body: JSON.stringify({ forum_id: parseInt(hdrEdit.dataset.forumId, 10), upload_id: up.upload_id }),
                });
              })
              .then(function (r) { return r.json(); })
              .then(function (res) {
                if (res && res.ok) { window.location.reload(); }
                else { hdrEdit.disabled = false; hdrEdit.textContent = '✎'; alert('Could not set image: ' + ((res && res.error) || 'error')); }
              })
              .catch(function (e) { hdrEdit.disabled = false; hdrEdit.textContent = '✎'; alert('Upload error: ' + e.message); });
          };
          input.click();
        });
      })
      .catch(function () { /* silent */ });
  }

  // ── 2. Feed card replies: lazy-load full thread on "View N replies" ─────────
  // The feed ships only ONE teaser reply per card (perf). The full threaded
  // list is fetched on first expand from <FORUM_BASE>/?replies=<id> and injected
  // into .feed-card__replies-full, then toggled.
  // Delegated: works for expand buttons added dynamically after an inline reply.
  document.addEventListener('click', async function (ev) {
    const btn = ev.target.closest('.feed-card__expand');
    if (!btn) return;
    if (!btn.dataset.collapseLabel) btn.dataset.collapseLabel = btn.textContent;
    const card = btn.closest('.feed-card');
    const full = card && card.querySelector('.feed-card__replies-full');
    if (!full) return;
    const expanded = card.classList.contains('replies-expanded');

    if (expanded) {                       // collapse
      card.classList.remove('replies-expanded');
      full.hidden = true;
      btn.textContent = btn.dataset.collapseLabel;
      return;
    }

    // lazy-fetch the full thread once
    if (!full.dataset.loaded) {
      const orig = btn.textContent;
      btn.textContent = 'Loading…';
      btn.disabled = true;
      try {
        const res = await fetch(FORUM_BASE + '/?replies=' + btn.dataset.topicId);
        if (!res.ok) throw new Error('fetch failed');
        full.innerHTML = await res.text();
        full.dataset.loaded = '1';
      } catch (err) {
        btn.textContent = orig;
        btn.disabled = false;
        return;
      }
      btn.disabled = false;
    }

    card.classList.add('replies-expanded');
    full.hidden = false;
    btn.textContent = 'Hide replies ▲';
  });

  // ── 2c. Reply stub inline expand ("… more") ──────────────────────────────
  // Delegated so it works on stubs revealed by the accordion expand above.
  document.addEventListener('click', function (e) {
    var btn = e.target.closest('.reply-stub__expand');
    if (!btn) return;
    e.stopPropagation();
    var full = btn.previousElementSibling; // .reply-stub__full
    full.hidden = false;
    btn.remove();
  });

  // Lead-reply image opener: a teaser reply hides its image (and defers loading)
  // until opened. Swap data-src -> src and reveal.
  document.addEventListener('click', function (e) {
    var b = e.target.closest('.reply-stub__img-open');
    if (!b) return;
    e.stopPropagation();
    var img = b.nextElementSibling;
    if (img && img.dataset && img.dataset.src) { img.src = img.dataset.src; img.hidden = false; }
    b.remove();
  });

  // ── 2c-bis. Reply sort toggle (Newest / Oldest) in the expanded thread ──────
  // The ?replies fragment carries the toggle; clicking re-fetches with &sort= and
  // swaps the thread HTML (the fragment re-emits the toggle with active state).
  document.addEventListener('click', function (e) {
    var b = e.target.closest('.replies-sort__btn');
    if (!b || b.classList.contains('is-active')) return;
    var bar = b.closest('.replies-sort');
    var host = b.closest('.feed-card__replies-full');
    if (!bar || !host) return;
    host.style.opacity = '0.5';
    fetch(FORUM_BASE + '/?replies=' + bar.dataset.topicId + '&sort=' + b.dataset.sort)
      .then(function (r) { return r.ok ? r.text() : Promise.reject(new Error('fetch')); })
      .then(function (html) { host.innerHTML = html; host.style.opacity = ''; })
      .catch(function () { host.style.opacity = ''; });
  });

  // ── 2b. Feed card full-body expand (Read more / Read less — lazy fetch) ──
  document.querySelectorAll('.feed-card__read-more').forEach(btn => {
    btn.addEventListener('click', async () => {
      const card = btn.closest('.feed-card');
      const body = card.querySelector('.feed-card__full-body');

      if (btn.dataset.state === 'expanded') {
        // collapse this card
        body.hidden = true;
        const excerpt = card.querySelector('.feed-card__op-excerpt');
        if (excerpt) excerpt.style.display = '';
        const embC = card.querySelector('.feed-card__embed');
        if (embC) embC.hidden = false;   // restore inline embed
        btn.textContent = 'Read more ▾';
        btn.dataset.state = 'collapsed';
        return;
      }

      // close any other open post body first
      document.querySelectorAll('.feed-card__read-more[data-state="expanded"]').forEach(other => {
        const otherCard = other.closest('.feed-card');
        const otherBody = otherCard.querySelector('.feed-card__full-body');
        const otherExcerpt = otherCard.querySelector('.feed-card__op-excerpt');
        otherBody.hidden = true;
        if (otherExcerpt) otherExcerpt.style.display = '';
        other.textContent = 'Read more ▾';
        other.dataset.state = 'collapsed';
      });

      // lazy-fetch on first open
      if (!body.dataset.loaded) {
        btn.textContent = 'Loading…';
        btn.disabled = true;
        try {
          const res = await fetch(FORUM_BASE + '/?body=' + btn.dataset.topicId);
          if (!res.ok) throw new Error('fetch failed');
          body.innerHTML = await res.text();
          body.dataset.loaded = '1';
          bbProcessEmbeds(body);
        } catch (e) {
          btn.textContent = 'Read more ▾';
          btn.disabled = false;
          return;
        }
        btn.disabled = false;
      }

      // hide excerpt — use style.display, not hidden, because display:-webkit-box overrides [hidden]
      const excerpt = card.querySelector('.feed-card__op-excerpt');
      if (excerpt) excerpt.style.display = 'none';
      const embE = card.querySelector('.feed-card__embed');
      if (embE) embE.hidden = true;   // full body re-embeds it; avoid duplicate
      body.hidden = false;
      btn.textContent = 'Read less ▲';
      btn.dataset.state = 'expanded';
    });
  });

  // ── 2d. Client-side embeds ───────────────────────────────────────────────
  // Bare provider URLs (stored as plain text in content_html) become iframes /
  // provider blockquotes. Best-effort: YouTube, Vimeo, Twitter/X, Instagram.
  // function declarations are hoisted, so 2b's lazy-loader can call this.
  var bbEmbedScripts = {}; // provider → loaded flag

  function bbLoadScript(key, src) {
    if (bbEmbedScripts[key]) return;
    bbEmbedScripts[key] = true;
    var s = document.createElement('script');
    s.src = src; s.async = true; s.charset = 'utf-8';
    document.body.appendChild(s);
  }

  // Returns an embed wrapper Element for a provider URL, or null.
  function bbBuildEmbed(url) {
    var m;
    // YouTube
    m = url.match(/(?:youtube\.com\/(?:watch\?(?:.*&)?v=|shorts\/|embed\/)|youtu\.be\/)([\w-]{6,})/i);
    if (m) return bbIframeEmbed('https://www.youtube.com/embed/' + m[1]);
    // Vimeo
    m = url.match(/vimeo\.com\/(?:video\/)?(\d+)/i);
    if (m) return bbIframeEmbed('https://player.vimeo.com/video/' + m[1]);
    // Twitter / X
    m = url.match(/(?:twitter\.com|x\.com)\/(\w+)\/status\/(\d+)/i);
    if (m) {
      var bq = document.createElement('blockquote');
      bq.className = 'twitter-tweet';
      var a = document.createElement('a');
      a.href = 'https://twitter.com/' + m[1] + '/status/' + m[2];
      bq.appendChild(a);
      var wrap = document.createElement('div');
      wrap.className = 'bb-embed bb-embed--tweet';
      wrap.appendChild(bq);
      bbLoadScript('twitter', 'https://platform.twitter.com/widgets.js');
      return wrap;
    }
    // Instagram (post / reel / tv)
    m = url.match(/instagram\.com\/(?:p|reel|tv)\/([\w-]+)/i);
    if (m) {
      var permalink = 'https://www.instagram.com/p/' + m[1] + '/';
      var ig = document.createElement('blockquote');
      ig.className = 'instagram-media';
      ig.setAttribute('data-instgrm-permalink', permalink);
      ig.setAttribute('data-instgrm-version', '14');
      var iga = document.createElement('a');
      iga.href = permalink; iga.textContent = 'View this post on Instagram';
      ig.appendChild(iga);
      var igwrap = document.createElement('div');
      igwrap.className = 'bb-embed bb-embed--ig';
      igwrap.appendChild(ig);
      bbLoadScript('instagram', 'https://www.instagram.com/embed.js');
      // if embed.js already loaded earlier, re-process
      if (window.instgrm && window.instgrm.Embeds) {
        setTimeout(function () { window.instgrm.Embeds.process(); }, 50);
      }
      return igwrap;
    }
    return null;
  }

  function bbIframeEmbed(src) {
    var wrap = document.createElement('div');
    wrap.className = 'bb-embed bb-embed--video';
    var ifr = document.createElement('iframe');
    ifr.src = src;
    ifr.setAttribute('frameborder', '0');
    ifr.setAttribute('allowfullscreen', '');
    ifr.setAttribute('allow', 'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture');
    ifr.loading = 'lazy';
    wrap.appendChild(ifr);
    return wrap;
  }

  function bbProcessEmbeds(root) {
    if (!root) return;

    // 1. Bare anchors whose text == href (auto-linkified URLs)
    root.querySelectorAll('a[href]').forEach(function (a) {
      var href = a.getAttribute('href') || '';
      if (a.textContent.trim() !== href) return; // not a bare link
      var embed = bbBuildEmbed(href);
      if (!embed) return;
      // if the anchor is the only child of a <p>, replace the whole <p>
      var target = (a.parentElement && a.parentElement.tagName === 'P'
                    && a.parentElement.childNodes.length === 1) ? a.parentElement : a;
      target.replaceWith(embed);
    });

    // 2. Paragraphs whose entire text is a single provider URL
    root.querySelectorAll('p').forEach(function (p) {
      if (p.querySelector('iframe, blockquote, .bb-embed')) return;
      var txt = p.textContent.trim();
      if (!/^https?:\/\/\S+$/.test(txt)) return;
      var embed = bbBuildEmbed(txt);
      if (embed) p.replaceWith(embed);
    });

    // 3. content_html that is JUST a bare URL with no element wrapper
    if (!root.querySelector('.bb-embed') && root.children.length === 0) {
      var raw = root.textContent.trim();
      if (/^https?:\/\/\S+$/.test(raw)) {
        var embed = bbBuildEmbed(raw);
        if (embed) { root.textContent = ''; root.appendChild(embed); }
      }
    }

    // 3b. Any text node that is EXACTLY a single provider URL → embed it.
    //     Legacy content sometimes leads with a bare provider URL glued straight
    //     to following markup (e.g. an IG reel URL + "<div>…"), so it never sits
    //     alone in a <p> and steps 1–3 miss it; catch it before auto-linking
    //     turns it into a plain text link.
    (function () {
      var w = document.createTreeWalker(root, NodeFilter.SHOW_TEXT, null);
      var nodes = [], n;
      while ((n = w.nextNode())) nodes.push(n);
      nodes.forEach(function (node) {
        var txt = (node.nodeValue || '').trim();
        if (!/^https?:\/\/\S+$/.test(txt)) return;
        for (var p = node.parentNode; p && p !== root; p = p.parentNode) {
          if (p.nodeName === 'A' || (p.classList && p.classList.contains('bb-embed'))) return;
        }
        var em = bbBuildEmbed(txt);
        if (em && node.parentNode) node.parentNode.replaceChild(em, node);
      });
    })();

    // 4. Auto-link any remaining bare URLs (legacy posts store them as plain
    //    text; WP make_clickable()s them at render — we echo raw, so do it here).
    bbAutoLink(root);
  }

  // Wrap bare http(s) URLs in text nodes with anchors. Skips text already inside
  // links, embeds, or code so we never double-wrap or break existing markup.
  function bbAutoLink(root) {
    if (!root) return;
    var walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT, {
      acceptNode: function (n) {
        if (!n.nodeValue || n.nodeValue.indexOf('http') === -1) return NodeFilter.FILTER_REJECT;
        for (var p = n.parentNode; p && p !== root; p = p.parentNode) {
          var t = p.nodeName;
          if (t === 'A' || t === 'SCRIPT' || t === 'STYLE' || t === 'CODE' || t === 'PRE') return NodeFilter.FILTER_REJECT;
          if (p.classList && p.classList.contains('bb-embed')) return NodeFilter.FILTER_REJECT;
        }
        return NodeFilter.FILTER_ACCEPT;
      }
    });
    var nodes = [], n;
    while ((n = walker.nextNode())) nodes.push(n);
    var re = /https?:\/\/[^\s<>()]+[^\s<>().,;:!?'"\]]/g;
    nodes.forEach(function (node) {
      var text = node.nodeValue, frag = document.createDocumentFragment(), last = 0, m;
      re.lastIndex = 0;
      while ((m = re.exec(text))) {
        if (m.index > last) frag.appendChild(document.createTextNode(text.slice(last, m.index)));
        var a = document.createElement('a');
        a.href = m[0]; a.textContent = m[0];
        a.target = '_blank'; a.rel = 'noopener noreferrer';
        a.className = 'bb-autolink';
        frag.appendChild(a);
        last = m.index + m[0].length;
      }
      if (last === 0) return;                 // no match — leave node untouched
      if (last < text.length) frag.appendChild(document.createTextNode(text.slice(last)));
      node.parentNode.replaceChild(frag, node);
    });
  }

  // Initial scan of any rendered bodies present at load (single-topic pages).
  document.querySelectorAll('.post__body, .feed-card__full-body[data-loaded]').forEach(bbProcessEmbeds);

  // ── 2e. Lazy provider-URL embeds in feed cards ──────────────────────────────
  // The feed shows the plain excerpt; provider posts (IG/YouTube/Vimeo/X) get an
  // inline embed for their first provider URL. Built via IntersectionObserver so
  // we don't pull every provider script up front.
  (function () {
    var slots = document.querySelectorAll('.feed-card__embed[data-embed-url]');
    if (!slots.length) return;
    function fill(slot) {
      if (slot.dataset.embedded) return;
      slot.dataset.embedded = '1';
      var em = bbBuildEmbed(slot.dataset.embedUrl);
      if (em) slot.appendChild(em);
    }
    if (typeof IntersectionObserver === 'undefined') {
      slots.forEach(fill);
      return;
    }
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (en) {
        if (!en.isIntersecting) return;
        io.unobserve(en.target);
        fill(en.target);
      });
    }, { rootMargin: '300px' });
    slots.forEach(function (s) { io.observe(s); });
  })();

  // ── 3a. Topic-list page: fetch unread IDs + mark them ───────────────────
  const topicList = document.querySelector('.topic-list');
  if (topicList) {
    const ids = Array.from(topicList.querySelectorAll('[data-topic-id]'))
      .map(function (el) { return parseInt(el.dataset.topicId, 10); })
      .filter(function (n) { return n > 0; });
    if (ids.length) {
      fetch('/bb-mirror-api/v0/unread.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ topic_ids: ids }),
      })
        .then(function (r) { return r.ok ? r.json() : null; })
        .then(function (data) {
          if (!data || !data.authenticated) return;
          var unread = new Set(data.unread);
          topicList.querySelectorAll('[data-topic-id]').forEach(function (el) {
            if (unread.has(parseInt(el.dataset.topicId, 10))) {
              el.classList.add('topic--unread');
            }
          });
        })
        .catch(function () { /* silent */ });
    }
  }

  // ── 4. New topic modal ───────────────────────────────────────────────────
  var ntmOverlay  = document.getElementById('ntm-overlay');
  var ntmOpen     = document.getElementById('ntm-open');
  var ntmBackdrop = document.getElementById('ntm-backdrop');
  var ntmCancel   = document.getElementById('ntm-cancel');
  var ntmForm     = document.getElementById('ntm-form');
  var ntmForumSel = document.getElementById('ntm-forum');
  var ntmTitleIn  = document.getElementById('ntm-title-in');
  var ntmContentEl= document.getElementById('ntm-content');
  var ntmSubmit   = document.getElementById('ntm-submit');
  var ntmStatus   = document.getElementById('ntm-status');
  var ntmLoading  = document.getElementById('ntm-loading');
  var ntmAnon     = document.getElementById('ntm-anon');

  if (ntmOverlay) {   // modal init no longer requires #ntm-open (button moved to the header banner / leaf "Post here")
    var ntmNonce     = null;
    var ntmAuthState = 'idle'; // idle | loading | anon | authed
    var ntmQuill     = null;   // Quill instance (lazy)
    var ntmMediaIds  = [];      // upload_ids for bbp_media
    var ntmRestBase  = ntmForm.dataset.restBase || '/wp-json/buddyboss/v1';
    var ntmEditorEl  = document.getElementById('ntm-editor');

    // Lazy-init Quill on first authed open. Falls back to the plain textarea
    // if the CDN script didn't load.
    function ntmInitEditor() {
      if (ntmQuill || !ntmEditorEl) return;
      if (typeof Quill === 'undefined') {
        // Fallback: reveal the plain textarea
        if (ntmContentEl) ntmContentEl.hidden = false;
        ntmEditorEl.style.display = 'none';
        return;
      }
      ntmQuill = new Quill(ntmEditorEl, {
        theme: 'snow',
        placeholder: 'Share details, ask a question…',
        modules: {
          toolbar: {
            container: [
              [{ header: [2, 3, false] }],
              ['bold', 'italic', 'underline'],
              ['blockquote', 'code-block'],
              [{ list: 'ordered' }, { list: 'bullet' }],
              ['link', 'image'],
              ['clean'],
            ],
            handlers: { image: ntmImageHandler },
          },
        },
      });
    }

    // Image button → file picker → upload to BB → track id + show inline preview.
    function ntmImageHandler() {
      var input = document.createElement('input');
      input.type = 'file';
      input.accept = 'image/*';
      input.onchange = function () {
        var file = input.files && input.files[0];
        if (!file) return;
        ntmStatus.textContent = 'Uploading image…';
        var fd = new FormData();
        fd.append('file', file);
        fetch(ntmRestBase + '/media/upload', {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'X-WP-Nonce': ntmNonce },
          body: fd,
        })
          .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
          .then(function (res) {
            if (!res.ok || !res.j.upload_id) {
              ntmStatus.textContent = 'Image upload failed: ' + ((res.j && res.j.message) || 'error');
              return;
            }
            ntmMediaIds.push(res.j.upload_id);
            ntmStatus.textContent = 'Image attached.';
            // Inline preview in the editor so the user sees it (stripped on submit;
            // the real image is stored as BB media and rendered by the mirror).
            var range = ntmQuill.getSelection(true);
            ntmQuill.insertEmbed(range ? range.index : 0, 'image', res.j.upload_thumb || res.j.upload);
          })
          .catch(function (err) { ntmStatus.textContent = 'Upload error: ' + err.message; });
      };
      input.click();
    }

    function ntmShowOverlay(overrideForumId) {
      ntmOverlay.hidden = false;
      document.body.classList.add('ntm-active');
      if (ntmAuthState === 'idle') {
        ntmLoadAuth(overrideForumId);
      } else if (ntmAuthState === 'authed' && overrideForumId) {
        ntmForumSel.value = String(overrideForumId);
      }
      setTimeout(function () {
        var el = ntmForm.hidden ? null : (ntmForumSel.value === '' ? ntmForumSel : ntmTitleIn);
        if (el) el.focus();
      }, 50);
    }

    function ntmHideOverlay() {
      ntmOverlay.hidden = true;
      document.body.classList.remove('ntm-active');
      ntmStatus.textContent = '';
    }

    function ntmSetState(state) {
      ntmAuthState = state;
      ntmLoading.hidden = (state !== 'loading');
      ntmAnon.hidden    = (state !== 'anon');
      ntmForm.hidden    = (state !== 'authed');
    }

    function ntmLoadAuth(overrideForumId) {
      ntmSetState('loading');
      fetch('/bb-mirror-api/v0/auth.php', { credentials: 'same-origin' })
        .then(function (r) { return r.ok ? r.json() : Promise.reject('auth ' + r.status); })
        .then(function (data) {
          if (!data.authenticated) { ntmSetState('anon'); return; }
          ntmNonce = data.nonce;
          ntmSetState('authed');
          ntmInitEditor();
          // pre-select: explicit override (e.g. "Post here" button) > data attr from URL
          var presel = overrideForumId || parseInt(ntmForm.dataset.currentForum, 10);
          if (presel > 0 && ntmForumSel) ntmForumSel.value = String(presel);
          setTimeout(function () { (ntmForumSel.value === '' ? ntmForumSel : ntmTitleIn).focus(); }, 30);
        })
        .catch(function () { ntmSetState('anon'); });
    }

    // Pull body HTML from Quill (or textarea fallback), stripping preview <img>
    // tags — those images are stored natively via bbp_media and rendered by the mirror.
    function ntmGetContent() {
      var html;
      if (ntmQuill) {
        html = ntmQuill.root.innerHTML;
        if (html === '<p><br></p>') html = '';
      } else {
        html = (ntmContentEl.value || '').trim();
      }
      // strip inline preview images (bbp_media carries the real ones)
      html = html.replace(/<img[^>]*>/gi, '');
      // collapse emptied paragraphs
      html = html.replace(/<p>\s*<\/p>/gi, '').trim();
      return html;
    }

    if (ntmOpen) ntmOpen.addEventListener('click', function () { ntmShowOverlay(null); });
    ntmCancel.addEventListener('click', ntmHideOverlay);
    ntmBackdrop.addEventListener('click', ntmHideOverlay);

    // Any element with [data-ntm-open] opens the modal (e.g. forum header "Post here" button).
    // If it carries data-forum-id, override the pre-selected forum.
    document.addEventListener('click', function (e) {
      var trigger = e.target.closest('[data-ntm-open]');
      if (!trigger || trigger === ntmOpen) return;
      var forumId = trigger.dataset.forumId ? parseInt(trigger.dataset.forumId, 10) : null;
      ntmShowOverlay(forumId);
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && !ntmOverlay.hidden) ntmHideOverlay();
    });

    ntmForm.addEventListener('submit', function (e) {
      e.preventDefault();
      if (!ntmNonce) { ntmStatus.textContent = 'Not signed in.'; return; }
      var forumId = parseInt(ntmForumSel.value, 10);
      var title   = ntmTitleIn.value.trim();
      var content = ntmGetContent();
      if (!forumId) { ntmStatus.textContent = 'Please choose a forum.'; ntmForumSel.focus(); return; }
      if (!title)   { ntmStatus.textContent = 'Title is required.'; ntmTitleIn.focus(); return; }

      ntmSubmit.disabled = true;
      ntmStatus.textContent = 'Posting…';

      var payload = { parent: forumId, title: title };
      if (content) payload.content = content;
      if (ntmMediaIds.length) payload.bbp_media = ntmMediaIds;
      var tagsEl = document.getElementById('ntm-tags');
      var tags = tagsEl && tagsEl.value.trim();
      if (tags) payload.topic_tags = tags;

      fetch(ntmRestBase + '/topics', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': ntmNonce },
        body: JSON.stringify(payload),
      })
        .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
        .then(function (res) {
          if (res.ok) {
            ntmStatus.textContent = 'Posted! Redirecting…';
            // Build bb-mirror URL from the selected forum slug + topic slug extracted from BB link
            var bbLink   = res.j && res.j.link; // e.g. /all-forums-all-topics/topic/my-slug/
            var pubPath  = ntmForm.dataset.publicPath || FORUM_BASE;
            var opt      = ntmForumSel.options[ntmForumSel.selectedIndex];
            var fSlug    = opt && opt.dataset.slug;
            var topicSlug = bbLink && bbLink.replace(/^.*\/topic\/([^/]+)\/?$/, '$1');
            var dest = (fSlug && topicSlug && topicSlug !== bbLink)
              ? pubPath + '/' + fSlug + '/' + topicSlug + '/'
              : (bbLink || window.location.href);
            setTimeout(function () { window.location.href = dest; }, 600);
            return;
          }
          var msg = (res.j && (res.j.message || res.j.code)) || 'Unknown error';
          ntmStatus.textContent = 'Error: ' + msg;
          ntmSubmit.disabled = false;
        })
        .catch(function (err) {
          ntmStatus.textContent = 'Network error: ' + err.message;
          ntmSubmit.disabled = false;
        });
    });
  }

  // ── 4b. Feed reply modal ────────────────────────────────────────────────────
  // A feed card's "Reply" button pops this modal (mirrors the new-topic modal),
  // wired to that card's topic + forum. Posts a top-level reply via BB REST, then
  // drops an optimistic stub into the card. Lazy auth + nonce, like the ntm modal.
  // MUST live above the `.reply-form-wrap` early-return below — the feed has no
  // reply-form-wrap, so anything after that return never runs on the feed.
  var frmOverlay = document.getElementById('frm-overlay');
  if (frmOverlay) {
    var frmBackdrop = document.getElementById('frm-backdrop');
    var frmCancel   = document.getElementById('frm-cancel');
    var frmForm     = document.getElementById('frm-form');
    var frmContent  = document.getElementById('frm-content');
    var frmTopicId  = document.getElementById('frm-topic-id');
    var frmForumId  = document.getElementById('frm-forum-id');
    var frmStatus   = document.getElementById('frm-status');
    var frmSubmit   = document.getElementById('frm-submit');
    var frmLoading  = document.getElementById('frm-loading');
    var frmAnon     = document.getElementById('frm-anon');
    var frmContext  = document.getElementById('frm-context');
    var frmCtxTitle = frmContext && frmContext.querySelector('.frm-context__title');
    var frmRestBase = frmForm.dataset.restBase || '/wp-json/buddyboss/v1';

    var frmNonce = null, frmName = 'You', frmState = 'idle', frmCard = null;

    var frmEditorEl = document.getElementById('frm-editor');
    var frmQuill    = null;     // lazy Quill instance (same editor as new-topic)
    var frmMediaIds = [];       // bbp_media upload_ids for this reply
    var frmMediaPreviews = [];  // preview URLs, for the optimistic stub (no refresh)
    var frmParentId = 0;        // reply_to: set when replying to a specific reply (nested)

    function frmFocus() { if (frmQuill) frmQuill.focus(); else if (frmContent) frmContent.focus(); }

    // Lazy-init Quill; fall back to the plain textarea if the CDN didn't load.
    function frmInitEditor() {
      if (frmQuill || !frmEditorEl) return;
      if (typeof Quill === 'undefined') {
        if (frmContent) frmContent.hidden = false;
        frmEditorEl.style.display = 'none';
        return;
      }
      frmQuill = new Quill(frmEditorEl, {
        theme: 'snow',
        placeholder: 'Share your thoughts…',
        modules: { toolbar: {
          container: [
            [{ header: [2, 3, false] }],
            ['bold', 'italic', 'underline'],
            ['blockquote', 'code-block'],
            [{ list: 'ordered' }, { list: 'bullet' }],
            ['link', 'image'],
            ['clean'],
          ],
          handlers: { image: frmImageHandler },
        } },
      });
    }

    // Image button → upload to BB media → track id + inline preview (mirrors ntm).
    function frmImageHandler() {
      var input = document.createElement('input');
      input.type = 'file'; input.accept = 'image/*';
      input.onchange = function () {
        var file = input.files && input.files[0];
        if (!file) return;
        frmStatus.textContent = 'Uploading image…';
        var fd = new FormData(); fd.append('file', file);
        fetch(frmRestBase + '/media/upload', {
          method: 'POST', credentials: 'same-origin',
          headers: { 'X-WP-Nonce': frmNonce }, body: fd,
        })
          .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
          .then(function (res) {
            if (!res.ok || !res.j.upload_id) {
              frmStatus.textContent = 'Image upload failed: ' + ((res.j && res.j.message) || 'error'); return;
            }
            frmMediaIds.push(res.j.upload_id);
            frmMediaPreviews.push(res.j.upload_thumb || res.j.upload);
            frmStatus.textContent = 'Image attached.';
            var range = frmQuill.getSelection(true);
            frmQuill.insertEmbed(range ? range.index : 0, 'image', res.j.upload_thumb || res.j.upload);
          })
          .catch(function (err) { frmStatus.textContent = 'Upload error: ' + err.message; });
      };
      input.click();
    }

    // Body HTML from Quill (or textarea), stripping preview <img> — the real
    // images ride along as bbp_media and are rendered by the mirror.
    function frmGetContent() {
      var html = frmQuill ? frmQuill.root.innerHTML : (frmContent.value || '').trim();
      if (html === '<p><br></p>') html = '';
      html = html.replace(/<img[^>]*>/gi, '').replace(/<p>\s*<\/p>/gi, '').trim();
      return html;
    }

    function frmResetEditor() {
      frmMediaIds = [];
      frmMediaPreviews = [];
      if (frmQuill) frmQuill.setText('');
      else if (frmContent) frmContent.value = '';
    }

    function frmSetState(s) {
      frmState = s;
      frmLoading.hidden = (s !== 'loading');
      frmAnon.hidden    = (s !== 'anon');
      frmForm.hidden    = (s !== 'authed');
    }
    function frmLoadAuth() {
      frmSetState('loading');
      fetch('/bb-mirror-api/v0/auth.php', { credentials: 'same-origin' })
        .then(function (r) { return r.ok ? r.json() : Promise.reject('auth'); })
        .then(function (d) {
          if (!d.authenticated) { frmSetState('anon'); return; }
          frmNonce = d.nonce; frmName = d.display_name || 'You';
          frmSetState('authed');
          frmInitEditor();
          setTimeout(frmFocus, 30);
        })
        .catch(function () { frmSetState('anon'); });
    }
    function frmOpen(trigger) {
      // The card is the trigger's ancestor (used for the optimistic stub + to
      // source topic/forum when the trigger is a per-reply button).
      frmCard = trigger.closest('.feed-card');
      var replyTo = parseInt(trigger.dataset.replyTo, 10) || 0;
      frmParentId = replyTo;
      // A per-reply "Reply" button only carries reply-to/-author; topic + forum
      // live on the card's top-level reply CTA. The card CTA carries them directly.
      var src = trigger;
      if (replyTo && frmCard) {
        var cta = frmCard.querySelector('.feed-card__reply-cta[data-frm-open]');
        if (cta) src = cta;
      }
      frmTopicId.value = src.dataset.topicId || (frmCard && frmCard.dataset.topicId) || '';
      frmForumId.value = src.dataset.forumId || '';
      var title = src.dataset.topicTitle || '';
      if (frmCtxTitle) {
        if (replyTo) {
          frmCtxTitle.textContent = '↩ Replying to ' + (trigger.dataset.replyToAuthor || 'a reply') + (title ? ' · ' + title : '');
          frmContext.hidden = false;
        } else if (title) { frmCtxTitle.textContent = title; frmContext.hidden = false; }
        else if (frmContext) { frmContext.hidden = true; }
      }
      frmStatus.textContent = '';
      frmResetEditor();
      frmOverlay.hidden = false;
      document.body.classList.add('ntm-active');
      if (frmState !== 'authed') frmLoadAuth();
      else { frmInitEditor(); setTimeout(frmFocus, 30); }
    }
    function frmClose() {
      frmOverlay.hidden = true;
      document.body.classList.remove('ntm-active');
      frmStatus.textContent = '';
    }

    // Delegated so it also works on lazily-loaded / optimistically-added cards.
    document.addEventListener('click', function (e) {
      var t = e.target.closest('.feed-card__reply-cta[data-frm-open], .reply-stub__reply');
      if (!t) return;
      e.stopPropagation();
      frmOpen(t);
    });
    frmCancel.addEventListener('click', frmClose);
    frmBackdrop.addEventListener('click', frmClose);
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && !frmOverlay.hidden) frmClose();
    });

    frmForm.addEventListener('submit', function (e) {
      e.preventDefault();
      if (!frmNonce) { frmStatus.textContent = 'Not signed in.'; return; }
      var content = frmGetContent();
      var topicId = parseInt(frmTopicId.value, 10);
      var forumId = parseInt(frmForumId.value, 10);
      if (!content && !frmMediaIds.length) { frmStatus.textContent = "Reply can't be empty."; frmFocus(); return; }
      if (!topicId) { frmStatus.textContent = 'Missing topic.'; return; }
      frmSubmit.disabled = true; frmStatus.textContent = 'Posting…';
      var frmPayload = { topic_id: topicId, forum_id: forumId };
      if (content) frmPayload.content = content;
      if (frmMediaIds.length) frmPayload.bbp_media = frmMediaIds;
      if (frmParentId) frmPayload.reply_to = frmParentId;   // nested reply
      fetch(frmRestBase + '/reply', {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': frmNonce },
        body: JSON.stringify(frmPayload),
      })
        .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
        .then(function (res) {
          if (!res.ok) {
            frmStatus.textContent = 'Error: ' + ((res.j && (res.j.message || res.j.code)) || 'failed');
            frmSubmit.disabled = false; return;
          }
          if (frmParentId) {
            frmRefreshThread(frmCard);                      // nested: reload thread so it nests in place
          } else {
            frmAppendOptimistic(frmCard, frmName, content); // images come from frmMediaPreviews
          }
          frmResetEditor();
          frmSubmit.disabled = false;
          frmClose();
        })
        .catch(function (err) { frmStatus.textContent = 'Network error: ' + err.message; frmSubmit.disabled = false; });
    });

    function frmEsc(s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; }
    function frmAppendOptimistic(card, name, content) {
      if (!card) return;
      var wrapEl = card.querySelector('.feed-card__replies');
      if (!wrapEl) {
        wrapEl = document.createElement('div');
        wrapEl.className = 'feed-card__replies';
        card.insertBefore(wrapEl, card.querySelector('.feed-card__actions') || null);
      }
      var text = content.replace(/<[^>]*>/g, '').trim();
      var initial = (name || 'Y').charAt(0).toUpperCase();
      // Render the just-uploaded image(s) so the reply shows complete without a
      // refresh. upload_thumb is a cookie-gated BB preview URL; the browser holds
      // the gate cookie, so it loads. Same .reply-stub__img markup as the server.
      var imgsHtml = frmMediaPreviews.map(function (u) {
        return '<img class="reply-stub__img" src="' + frmEsc(u) + '" alt="" loading="lazy">';
      }).join('');
      var textHtml = text ? '<span class="reply-stub__excerpt">' + frmEsc(text.slice(0, 160)) + '</span>' : '';
      var stub = document.createElement('div');
      stub.className = 'reply-stub reply-stub--mine';
      stub.innerHTML =
        '<div class="reply-stub__head">' +
          '<span class="avatar-init" style="width:28px;height:28px;font-size:12px;background:#6b7c52" aria-hidden="true">' + frmEsc(initial) + '</span>' +
          '<span class="reply-stub__author">' + frmEsc(name) + '</span>' +
          '<time class="reply-stub__time">now</time>' +
        '</div>' +
        '<div class="reply-stub__body">' + textHtml + imgsHtml + '</div>';
      // Make the new reply the single teaser (drop prior teaser stubs so they
      // don't stack), then bump the count and ensure the "View N replies" button
      // so the thread stays navigable — the bug where inline replies never
      // triggered the expand button.
      Array.prototype.forEach.call(wrapEl.querySelectorAll(':scope > .reply-stub'), function (s) { s.remove(); });
      wrapEl.insertBefore(stub, wrapEl.firstChild);

      var full = wrapEl.querySelector('.feed-card__replies-full');
      if (!full) { full = document.createElement('div'); full.className = 'feed-card__replies-full'; full.hidden = true; wrapEl.appendChild(full); }
      full.dataset.loaded = ''; full.innerHTML = '';   // stale: re-fetch (incl. new reply) on next expand
      card.classList.remove('replies-expanded'); full.hidden = true;

      // Keep the thread expandable if there was already an expand button. topic
      // reply_count lags in pg, so don't trust a count — if a button exists,
      // leave it (it lazy-loads the real thread, which includes the new reply).
      if (!wrapEl.querySelector('.feed-card__expand')) {
        // none yet: the new reply may be the only one, OR pg just hasn't surfaced
        // the count. Add a generic opener — expanding fetches the true thread.
        var exp = document.createElement('button');
        exp.className = 'feed-card__expand'; exp.type = 'button';
        exp.dataset.topicId = card.dataset.topicId || '';
        exp.textContent = 'View replies \u25be';
        wrapEl.appendChild(exp);
      }
    }

    // Nested reply: the optimistic teaser path would misrepresent depth, so just
    // refresh the thread. Reload now if it's open (keeps it open, reply nested in
    // place); otherwise mark stale so the next expand re-fetches.
    function frmRefreshThread(card) {
      if (!card) return;
      var full = card.querySelector('.feed-card__replies-full');
      if (!full) return;
      full.dataset.loaded = '';
      if (card.classList.contains('replies-expanded')) {
        fetch(FORUM_BASE + '/?replies=' + card.dataset.topicId)
          .then(function (r) { return r.ok ? r.text() : Promise.reject(new Error('fetch')); })
          .then(function (html) { full.innerHTML = html; full.dataset.loaded = '1'; })
          .catch(function () { /* leave stale; next expand re-fetches */ });
      } else {
        full.innerHTML = '';
      }
    }
  }

  // ── 2e. "Load more replies" — append the next page of top-level threads ─────
  document.addEventListener('click', function (e) {
    var b = e.target.closest('.replies-loadmore');
    if (!b) return;
    var host = b.closest('.feed-card__replies-full');
    if (!host) return;
    var orig = b.textContent;
    b.disabled = true; b.textContent = 'Loading…';
    fetch(FORUM_BASE + '/?replies=' + b.dataset.topicId + '&sort=' + (b.dataset.sort || 'newest') + '&offset=' + b.dataset.offset)
      .then(function (r) { return r.ok ? r.text() : Promise.reject(new Error('fetch')); })
      .then(function (html) {
        b.insertAdjacentHTML('beforebegin', html);  // next page (carries its own load-more if more remain)
        b.remove();
      })
      .catch(function () { b.disabled = false; b.textContent = orig; });
  });

  // ── 3b. Single-topic page: reply form + mark-seen on load ───────────────
  var wrap = document.querySelector('.reply-form-wrap');
  if (!wrap) return;
  var seenTopicId = parseInt(wrap.dataset.topicId, 10);
  var topicId     = parseInt(wrap.dataset.topicId, 10);
  var forumId     = parseInt(wrap.dataset.forumId, 10);
  var restBase    = wrap.dataset.bbRestBase || '/wp-json/buddyboss/v1';

  var loading      = wrap.querySelector('[data-state="loading"]');
  var anon         = wrap.querySelector('[data-state="anon"]');
  var authed       = wrap.querySelector('[data-state="authed"]');
  var textarea     = authed.querySelector('textarea[name="content"]');
  var parentInput  = authed.querySelector('input[name="parent_reply_id"]');
  var replyingTo   = authed.querySelector('.reply-form__replying-to');
  var replyingToNm = authed.querySelector('.reply-form__replying-to-name');
  var cancelThread = authed.querySelector('.reply-form__cancel-thread');
  var submitBtn    = authed.querySelector('.reply-form__submit');
  var status       = authed.querySelector('.reply-form__status');

  // Rich-text reply editor (Quill + image upload), like the new-topic/feed-reply modals.
  var replyEditorEl = authed.querySelector('.reply-form__editor');
  var replyQuill = null, replyMediaIds = [];
  function replyImageHandler() {
    var input = document.createElement('input');
    input.type = 'file'; input.accept = 'image/*';
    input.onchange = function () {
      var file = input.files && input.files[0];
      if (!file) return;
      status.textContent = 'Uploading image…';
      var fd = new FormData(); fd.append('file', file);
      fetch(restBase + '/media/upload', { method: 'POST', credentials: 'same-origin', headers: { 'X-WP-Nonce': nonce }, body: fd })
        .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
        .then(function (res) {
          if (!res.ok || !res.j.upload_id) { status.textContent = 'Image upload failed.'; return; }
          replyMediaIds.push(res.j.upload_id);
          status.textContent = 'Image attached.';
          var range = replyQuill.getSelection(true);
          replyQuill.insertEmbed(range ? range.index : 0, 'image', res.j.upload_thumb || res.j.upload);
        })
        .catch(function (err) { status.textContent = 'Upload error: ' + err.message; });
    };
    input.click();
  }
  function replyInitEditor() {
    if (replyQuill || !replyEditorEl) return;
    if (typeof Quill === 'undefined') { if (textarea) textarea.hidden = false; replyEditorEl.style.display = 'none'; return; }
    replyQuill = new Quill(replyEditorEl, {
      theme: 'snow', placeholder: 'Share your build, ask a question, drop a measurement…',
      modules: { toolbar: {
        container: [ [{ header: [2, 3, false] }], ['bold','italic','underline'], ['blockquote','code-block'], [{ list:'ordered' }, { list:'bullet' }], ['link','image'], ['clean'] ],
        handlers: { image: replyImageHandler },
      } } });
  }
  function replyGetContent() {
    var html = replyQuill ? replyQuill.root.innerHTML : (textarea.value || '').trim();
    if (html === '<p><br></p>') html = '';
    return html.replace(/<img[^>]*>/gi, '').replace(/<p>\s*<\/p>/gi, '').trim();
  }
  function replyFocus() { if (replyQuill) replyQuill.focus(); else if (textarea) textarea.focus(); }

  function show(el) { el.hidden = false; }
  function hide(el) { el.hidden = true; }
  function setState(stateEl) {
    [loading, anon, authed].forEach(function (s) {
      if (s === stateEl) show(s); else hide(s);
    });
  }

  var nonce = null;

  fetch('/bb-mirror-api/v0/auth.php', { credentials: 'same-origin' })
    .then(function (r) { return r.ok ? r.json() : Promise.reject(new Error('auth ' + r.status)); })
    .then(function (data) {
      if (!data.authenticated) { setState(anon); return; }
      nonce = data.nonce;
      setState(authed);
      replyInitEditor();
      revealReplyButtons();
      revealEditButtons(data.wp_user_id || 0, !!data.can_edit_others);
      revealDeleteButtons(data.wp_user_id || 0, !!data.can_edit_others);
      if (seenTopicId > 0) {
        fetch('/bb-mirror-api/v0/mark-seen.php', {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ topic_id: seenTopicId }),
        }).catch(function () { /* silent */ });
      }
    })
    .catch(function () { setState(anon); });

  function revealReplyButtons() {
    document.querySelectorAll('.post__reply-btn').forEach(function (btn) {
      btn.hidden = false;
      btn.addEventListener('click', function () {
        parentInput.value = btn.dataset.replyTo;
        replyingToNm.textContent = btn.dataset.replyToAuthor || 'a reply';
        show(replyingTo);
        replyFocus();
        authed.scrollIntoView({ behavior: 'smooth', block: 'center' });
      });
    });
    cancelThread.addEventListener('click', function () {
      parentInput.value = '';
      hide(replyingTo);
    });
  }

  // ── 3c. Inline post editing (own posts; admins/mods edit all) ───────────────
  function revealEditButtons(viewerId, canEditOthers) {
    document.querySelectorAll('.post__edit-btn').forEach(function (btn) {
      var authorId = parseInt(btn.dataset.authorId, 10) || 0;
      var mine = viewerId > 0 && authorId === viewerId;
      if (!mine && !canEditOthers) return;   // not allowed → leave hidden
      btn.hidden = false;
      btn.addEventListener('click', function () { startEdit(btn); });
    });
  }

  function startEdit(btn) {
    var post = btn.closest('.post');
    if (!post || post.querySelector('.post-edit')) return;  // already editing
    var body  = post.querySelector('.post__body');
    var kind  = btn.dataset.editKind;                       // topic | reply
    var id    = parseInt(btn.dataset.editId, 10);
    var restBase = '/wp-json/buddyboss/v1';

    // Build editor scaffold
    var box = document.createElement('div');
    box.className = 'post-edit';
    var titleHtml = (kind === 'topic')
      ? '<input class="post-edit__title" type="text" value="">' : '';
    box.innerHTML =
      titleHtml +
      '<div class="post-edit__quill"></div>' +
      '<div class="post-edit__row">' +
        '<button type="button" class="post-edit__save">Save</button>' +
        '<button type="button" class="post-edit__cancel">Cancel</button>' +
        '<span class="post-edit__status" aria-live="polite"></span>' +
      '</div>';
    body.style.display = 'none';
    btn.hidden = true;
    body.parentNode.insertBefore(box, body.nextSibling);

    if (kind === 'topic') box.querySelector('.post-edit__title').value = btn.dataset.title || '';

    // Quill (fallback to a plain textarea if the CDN didn't load)
    var quill = null, ta = null, editMediaIds = [];
    var qEl = box.querySelector('.post-edit__quill');
    function editImageHandler() {
      var input = document.createElement('input');
      input.type = 'file'; input.accept = 'image/*';
      input.onchange = function () {
        var file = input.files && input.files[0];
        if (!file) return;
        statusEl.textContent = 'Uploading image…';
        var fd = new FormData(); fd.append('file', file);
        fetch(restBase + '/media/upload', { method: 'POST', credentials: 'same-origin', headers: { 'X-WP-Nonce': nonce }, body: fd })
          .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
          .then(function (res) {
            if (!res.ok || !res.j.upload_id) { statusEl.textContent = 'Image upload failed.'; return; }
            editMediaIds.push(res.j.upload_id);
            statusEl.textContent = 'Image attached.';
            var range = quill.getSelection(true);
            quill.insertEmbed(range ? range.index : 0, 'image', res.j.upload_thumb || res.j.upload);
          })
          .catch(function (err) { statusEl.textContent = 'Upload error: ' + err.message; });
      };
      input.click();
    }
    if (typeof Quill !== 'undefined') {
      quill = new Quill(qEl, { theme: 'snow', modules: { toolbar: {
        container: [
          [{ header: [2, 3, false] }], ['bold','italic','underline'],
          ['blockquote','code-block'], [{ list:'ordered' }, { list:'bullet' }],
          ['link','image'], ['clean'] ],
        handlers: { image: editImageHandler },
      } } });
      quill.root.innerHTML = body.innerHTML;   // seed from rendered body
    } else {
      ta = document.createElement('textarea');
      ta.className = 'post-edit__fallback'; ta.rows = 6; ta.value = body.innerHTML;
      qEl.replaceWith(ta);
    }

    var statusEl = box.querySelector('.post-edit__status');
    var saveBtn  = box.querySelector('.post-edit__save');

    function teardown(restoreBody) {
      box.remove();
      body.style.display = '';
      btn.hidden = false;
      if (restoreBody) { /* body already restored to new html by caller */ }
    }

    box.querySelector('.post-edit__cancel').addEventListener('click', function () { teardown(false); });

    saveBtn.addEventListener('click', function () {
      var html = quill ? quill.root.innerHTML : ta.value;
      if (html === '<p><br></p>') html = '';
      // Strip inline preview <img> — new images attach via bbp_media and are
      // rendered by the mirror (bb-mirror content images are attachments, not
      // inline <img>, so this is safe).
      html = html.replace(/<img[^>]*>/gi, '').replace(/<p>\s*<\/p>/gi, '').trim();
      saveBtn.disabled = true; statusEl.textContent = 'Saving…';

      // New uploads attach via bbp_media; existing attachments are preserved.
      var payload, url;
      if (kind === 'topic') {
        url = restBase + '/topics/' + id;
        payload = { id: id, parent: parseInt(btn.dataset.forumId, 10),
                    title: box.querySelector('.post-edit__title').value.trim(), content: html };
      } else {
        url = restBase + '/reply/' + id;
        payload = { id: id, topic_id: parseInt(btn.dataset.topicId, 10),
                    forum_id: parseInt(btn.dataset.forumId, 10), content: html };
      }
      if (editMediaIds.length) payload.bbp_media = editMediaIds;

      fetch(url, {
        method: 'PUT',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
        body: JSON.stringify(payload),
      })
        .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
        .then(function (res) {
          if (!res.ok) {
            statusEl.textContent = 'Error: ' + ((res.j && (res.j.message || res.j.code)) || 'failed');
            saveBtn.disabled = false; return;
          }
          // Optimistic: show edited content immediately; pg catches up via sync.
          body.innerHTML = html;
          if (kind === 'topic') {
            var newTitle = payload.title;
            var h1 = document.querySelector('.topic-header__title');
            if (h1 && newTitle) h1.textContent = newTitle;
            btn.dataset.title = newTitle;
          }
          bbProcessEmbeds(body);   // re-embed any pasted media URLs
          teardown(true);
        })
        .catch(function (err) { statusEl.textContent = 'Network error: ' + err.message; saveBtn.disabled = false; });
    });
  }

  // ── 3d. Inline post deletion (own posts; admins/mods delete all) ────────────
  // Mirrors the edit gate: a Delete button is revealed when the post is the
  // viewer's own OR they hold edit_others (mod/admin). BB REST re-checks the
  // delete_topic/delete_reply meta cap server-side, and the trash/delete hook
  // propagates to pg via the sync mu-plugin, so the row drops from every view.
  function revealDeleteButtons(viewerId, canEditOthers) {
    document.querySelectorAll('.post__delete-btn').forEach(function (btn) {
      var authorId = parseInt(btn.dataset.authorId, 10) || 0;
      var mine = viewerId > 0 && authorId === viewerId;
      if (!mine && !canEditOthers) return;   // not allowed → leave hidden
      btn.hidden = false;
      btn.addEventListener('click', function () { confirmDelete(btn); });
    });
  }

  function confirmDelete(btn) {
    var kind = btn.dataset.delKind;                  // topic | reply
    var id   = parseInt(btn.dataset.delId, 10);
    if (!id) return;
    var what = kind === 'topic' ? 'this entire topic' : 'this reply';
    if (!window.confirm('Delete ' + what + '? This can’t be undone.')) return;

    var url  = '/wp-json/buddyboss/v1/' + (kind === 'topic' ? 'topics/' : 'reply/') + id;
    var prev = btn.textContent;
    btn.disabled = true; btn.textContent = 'Deleting…';

    fetch(url, {
      method: 'DELETE',
      credentials: 'same-origin',
      headers: { 'X-WP-Nonce': nonce },
    })
      .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; },
                                               function () { return { ok: r.ok, j: {} }; }); })
      .then(function (res) {
        if (!res.ok) {
          btn.disabled = false; btn.textContent = prev;
          alert('Could not delete: ' + ((res.j && (res.j.message || res.j.code)) || 'failed'));
          return;
        }
        if (kind === 'topic') {
          // Whole thread is gone → return to the forum (breadcrumb keeps us
          // path-correct across the /forums-poc → /forum flip).
          var fl = document.querySelector('.breadcrumbs a:nth-of-type(2)');
          window.location.href = (fl && fl.getAttribute('href')) || (FORUM_BASE + '/');
        } else {
          // Reply gone → reload so the threaded tree re-renders accurately.
          window.location.reload();
        }
      })
      .catch(function (err) {
        btn.disabled = false; btn.textContent = prev;
        alert('Network error: ' + err.message);
      });
  }

  authed.addEventListener('submit', function (e) {
    e.preventDefault();
    if (!nonce) { status.textContent = 'Not signed in.'; return; }
    var content = replyGetContent();
    if (!content && !replyMediaIds.length) { status.textContent = "Reply can't be empty."; replyFocus(); return; }
    submitBtn.disabled = true;
    status.textContent = 'Posting…';

    var body = { topic_id: topicId, forum_id: forumId };
    if (content) body.content = content;
    if (replyMediaIds.length) body.bbp_media = replyMediaIds;
    var parentId = parseInt(parentInput.value, 10);
    if (parentId > 0) body.reply_to = parentId;

    fetch(restBase + '/reply', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
      body: JSON.stringify(body),
    })
      .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, code: r.status, j: j }; }); })
      .then(function (res) {
        if (res.ok) {
          status.textContent = 'Posted. Refreshing…';
          setTimeout(function () { window.location.reload(); }, 800);
          return;
        }
        var msg = (res.j && (res.j.message || res.j.code)) || ('HTTP ' + res.code);
        status.textContent = 'Error: ' + msg;
        submitBtn.disabled = false;
      })
      .catch(function (err) {
        status.textContent = 'Network error: ' + err.message;
        submitBtn.disabled = false;
      });
  });

})();

/* ─── Compact feed view toggle ──────────────────────────────────────────
   Persists to localStorage('hub-compact'). The no-flash class is applied
   pre-paint by an inline script in _chrome.php; this only handles clicks
   and keeps the button's aria-pressed in sync. */
(function () {
  var KEY = 'hub-compact';
  function syncBtn(btn) {
    btn.setAttribute('aria-pressed',
      document.documentElement.classList.contains('hub-compact') ? 'true' : 'false');
  }
  document.addEventListener('click', function (e) {
    var btn = e.target.closest && e.target.closest('.feed-compact-toggle');
    if (!btn) return;
    var on = document.documentElement.classList.toggle('hub-compact');
    try { on ? localStorage.setItem(KEY, '1') : localStorage.removeItem(KEY); } catch (_) {}
    syncBtn(btn);
  });
  document.querySelectorAll('.feed-compact-toggle').forEach(syncBtn);
})();

/* ─── Per-card expand: compact → verbose for a single card ──────────────
   The caret on a compact card toggles .is-verbose on that card only, which
   un-scopes it from the compact collapse rules (see forums.css). Lazy bits
   (Read more / View N replies) then work via their existing handlers. */
(function () {
  document.addEventListener('click', function (e) {
    var btn = e.target.closest && e.target.closest('.feed-card__compact-expand');
    if (!btn) return;
    var card = btn.closest('.feed-card');
    if (!card) return;
    var verbose = card.classList.toggle('is-verbose');
    btn.setAttribute('aria-expanded', verbose ? 'true' : 'false');
    btn.setAttribute('title', verbose ? 'Collapse' : 'Show full post');
    btn.setAttribute('aria-label', verbose ? 'Collapse post' : 'Show full post');
  });
})();

/* ─── §4c. Content comment modal (Hub content cards) ───────────────────────
   A Hub content card's comment button opens this modal; the iframe loads the
   WP-free read endpoint (archive-poc comments.php, ~30ms), which renders the
   thread + its own composer and posts its content height back. Same-origin,
   so the [data-post-type]/[data-item-id] map straight onto the query string. */
(function () {
  var modal = document.getElementById('lgc-modal'),
      frame = document.getElementById('lgc-modal-frame');
  if (!modal || !frame) return;

  var openerBtn = null;   // the card's comment button that opened the modal

  function openModal(pt, id) {
    frame.src = '/archive-api/v0/comments?post_type=' +
      encodeURIComponent(pt) + '&item_id=' + encodeURIComponent(id);
    modal.hidden = false;
    document.body.style.overflow = 'hidden';
  }

  // The engine iframe (comments.php) owns the thread + composer; it only posts its
  // height back, not a count. Rather than touch the engine, read the live thread
  // count same-origin off the iframe and reflect it on the card's comment button
  // so a freshly-posted comment shows up without a reload. Surface-only.
  function syncOpenerCount() {
    if (!openerBtn) return;
    var n = null;
    try {
      var doc = frame.contentDocument;
      if (doc) n = doc.querySelectorAll('.lgc-list .lgc').length;
    } catch (e) { /* cross-origin (shouldn't happen, same host) — skip */ }
    if (n === null) return;
    openerBtn.textContent = '💬 ' +
      (n > 0 ? n + ' ' + (n === 1 ? 'comment' : 'comments') : 'Comment');
    openerBtn.setAttribute('title', n > 0 ? 'View comments' : 'Be the first to comment');
  }

  function closeModal() {
    syncOpenerCount();         // pull the latest count before unloading the iframe
    modal.hidden = true;
    document.body.style.overflow = '';
    frame.src = '';            // unload the iframe so a re-open refetches fresh
    frame.style.height = '';   // reset to the CSS default for the next thread
    openerBtn = null;
  }

  document.addEventListener('click', function (e) {
    var btn = e.target.closest && e.target.closest('[data-comments]');
    if (btn) {
      e.preventDefault();
      e.stopPropagation();     // don't trigger card navigation
      openerBtn = btn;
      openModal(btn.getAttribute('data-post-type'), btn.getAttribute('data-item-id'));
      return;
    }
    if (e.target.closest && e.target.closest('[data-lgc-close]')) closeModal();
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && !modal.hidden) closeModal();
  });

  /* Height handshake — size the iframe to the thread (same message the
     standalone page's modal listens for). Clamp to 82vh; taller scrolls. */
  window.addEventListener('message', function (e) {
    if (e.origin !== location.origin || !e.data ||
        typeof e.data.lgCommentsHeight !== 'number') return;
    var cap = Math.round(window.innerHeight * 0.82);
    frame.style.height = Math.max(220, Math.min(e.data.lgCommentsHeight, cap)) + 'px';
  });
})();
