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
      var open = sec.classList.toggle('nav-tree__section--open');
      toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
  });

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
  // list is fetched on first expand from /forums-poc/?replies=<id> and injected
  // into .feed-card__replies-full, then toggled.
  document.querySelectorAll('.feed-card__expand').forEach(btn => {
    btn.dataset.collapseLabel = btn.dataset.collapseLabel || btn.textContent;

    btn.addEventListener('click', async () => {
      const card = btn.closest('.feed-card');
      const full = card.querySelector('.feed-card__replies-full');
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
          const res = await fetch('/forums-poc/?replies=' + btn.dataset.topicId);
          if (!res.ok) throw new Error('fetch failed');
          full.innerHTML = await res.text();
          full.dataset.loaded = '1';
        } catch (e) {
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
          const res = await fetch('/forums-poc/?body=' + btn.dataset.topicId);
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

  if (ntmOverlay && ntmOpen) {
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

    ntmOpen.addEventListener('click', function () { ntmShowOverlay(null); });
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
            var pubPath  = ntmForm.dataset.publicPath || '/forums-poc';
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
          setTimeout(function () { frmContent.focus(); }, 30);
        })
        .catch(function () { frmSetState('anon'); });
    }
    function frmOpen(trigger) {
      // The trigger button carries topic-id / forum-id / title; the card is its
      // ancestor (used for the optimistic stub).
      frmCard = trigger.closest('.feed-card');
      frmTopicId.value = trigger.dataset.topicId || '';
      frmForumId.value = trigger.dataset.forumId || '';
      var title = trigger.dataset.topicTitle || '';
      if (frmCtxTitle && title) { frmCtxTitle.textContent = title; frmContext.hidden = false; }
      else if (frmContext) { frmContext.hidden = true; }
      frmStatus.textContent = '';
      frmOverlay.hidden = false;
      document.body.classList.add('ntm-active');
      if (frmState !== 'authed') frmLoadAuth();
      else setTimeout(function () { frmContent.focus(); }, 30);
    }
    function frmClose() {
      frmOverlay.hidden = true;
      document.body.classList.remove('ntm-active');
      frmStatus.textContent = '';
    }

    // Delegated so it also works on lazily-loaded / optimistically-added cards.
    document.addEventListener('click', function (e) {
      var t = e.target.closest('.feed-card__reply-cta[data-frm-open]');
      if (!t) return;
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
      var content = (frmContent.value || '').trim();
      var topicId = parseInt(frmTopicId.value, 10);
      var forumId = parseInt(frmForumId.value, 10);
      if (!content) { frmStatus.textContent = "Reply can't be empty."; frmContent.focus(); return; }
      if (!topicId) { frmStatus.textContent = 'Missing topic.'; return; }
      frmSubmit.disabled = true; frmStatus.textContent = 'Posting…';
      fetch(frmRestBase + '/reply', {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': frmNonce },
        body: JSON.stringify({ content: content, topic_id: topicId, forum_id: forumId }),
      })
        .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
        .then(function (res) {
          if (!res.ok) {
            frmStatus.textContent = 'Error: ' + ((res.j && (res.j.message || res.j.code)) || 'failed');
            frmSubmit.disabled = false; return;
          }
          frmAppendOptimistic(frmCard, frmName, content);
          frmContent.value = '';
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
      var stub = document.createElement('div');
      stub.className = 'reply-stub reply-stub--mine';
      stub.innerHTML =
        '<div class="reply-stub__head">' +
          '<span class="avatar-init" style="width:28px;height:28px;font-size:12px;background:#6b7c52" aria-hidden="true">' + frmEsc(initial) + '</span>' +
          '<span class="reply-stub__author">' + frmEsc(name) + '</span>' +
          '<time class="reply-stub__time">now</time>' +
        '</div>' +
        '<div class="reply-stub__body"><span class="reply-stub__excerpt">' + frmEsc(text.slice(0, 160)) + '</span></div>';
      wrapEl.insertBefore(stub, wrapEl.firstChild);
    }
  }

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
        textarea.focus();
        textarea.scrollIntoView({ behavior: 'smooth', block: 'center' });
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
    var quill = null, ta = null;
    var qEl = box.querySelector('.post-edit__quill');
    if (typeof Quill !== 'undefined') {
      quill = new Quill(qEl, { theme: 'snow', modules: { toolbar: [
        [{ header: [2, 3, false] }], ['bold','italic','underline'],
        ['blockquote','code-block'], [{ list:'ordered' }, { list:'bullet' }],
        ['link'], ['clean'] ] } });
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
      saveBtn.disabled = true; statusEl.textContent = 'Saving…';

      // Omit bbp_media → existing attachments are preserved server-side.
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
          // path-correct across the /forums-poc → /forums flip).
          var fl = document.querySelector('.breadcrumbs a:nth-of-type(2)');
          window.location.href = (fl && fl.getAttribute('href')) || '/forums-poc/';
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
    var content = textarea.value.trim();
    if (!content) { status.textContent = "Reply can't be empty."; textarea.focus(); return; }
    submitBtn.disabled = true;
    status.textContent = 'Posting…';

    var body = { content: content, topic_id: topicId, forum_id: forumId };
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
