/* Looth Hub — visual polish for the activity feed (client-side, injected
   site-wide via /pwa.js). The Hub is the app's HOME surface: this makes the
   feed feel app-like and media-forward without touching the canonical Hub
   tree (bb-mirror, coordinator-owned).

   WHAT THIS DOES (mobile/app viewport, <=640px):
   - Richer hero: taller header, a brand scrim for depth + legibility, a bolder
     cream serif title, and a one-line tagline so the landing feels alive.
   - App-card feed: 16px radius (brand), a soft float shadow so each post reads
     as a physical card, more air between cards, and a press/tap feedback state.
   - Media-forward covers: keep cover images big and punchy but cap runaway
     portrait shots so the feed stays scannable.
   - Cleaner kind-badge pills + slightly larger titles.
   - Kills a pre-existing horizontal-scroll bug on the Hub: the feed (and the
     per-card replies) are CSS grids whose 1fr track was sized to the widest
     card''s min-content (grid items default to min-width:auto), and several
     nowrap lines (forum breadcrumb, reply author/time) refused to shrink. The
     net effect was the document scrolling sideways ~380px on a phone. Fixed
     with minmax(0,1fr) tracks, min-width:0 on the grid items, ellipsis on the
     nowrap author line, and overflow:hidden clipping at the card edge. Confirmed
     document scrollWidth back to viewport width (no horizontal scroll).

   WHY CLIENT-SIDE: the Hub is served from the bb-mirror tree (forums.css), which
   is coordinator-owned. A <style> appended to <head> AFTER forums.css wins on
   plain source-order specificity (no !important), so these rules layer cleanly
   over the existing mobile pass (@media max-width:640px). If/when the canonical
   forums.css absorbs them, this becomes a harmless duplicate.

   PLUS (all widths): pills the Hub control rail's top-level Type rows + category
   PARENTS, while leaving the LEAVES flat so the missing pill divides a parent
   from its subforums (Ian's category-nav polish).

   Self-contained: one <style> + (on the main Hub only) one tagline node.
   Path-gated to /hub. No deps, no emoji. */
(function () {
  'use strict';
  if (window.__loothHubPolish) return;
  window.__loothHubPolish = true;

  var STYLE_ID = 'looth-hub-polish';
  var TAGLINE_CLASS = 'lg-hub-tagline';
  var TAGLINE_TEXT =
    'The latest builds, repairs, and conversations from across Looth.';

  // Only act on the Hub and its listing sub-paths. No-op elsewhere.
  function onHubPath() {
    return /^\/hub(\/|$)/.test(location.pathname || '/');
  }


  // Add the hero tagline ONLY on the main activity Hub (title "The Hub"),
  // never on category listing pages (which carry their own label).
  function addTagline() {
    var title = document.querySelector('.feed-page .forum-header__title');
    if (!title || !/^\s*the hub\s*$/i.test(title.textContent || '')) return;
    var body = document.querySelector('.feed-page .forum-header__body');
    if (!body || body.querySelector('.' + TAGLINE_CLASS)) return;
    var span = document.createElement('span');
    span.className = TAGLINE_CLASS;
    span.textContent = TAGLINE_TEXT;
    body.appendChild(span);
  }

  // Mobile (<=960px): move the canonical corner-hamburger (#bb-ham, the filter
  // drawer trigger) into the feed sort bar as a clear "Filters" chip. At the
  // top-left corner it overlaps the shared site-header hamburger (76x76, z:300)
  // and swallows its taps, so the site menu is unreachable on the Hub. Moving
  // the node keeps its click handler intact and frees the site hamburger.
  // Defensive: no-op off-mobile or if either node is missing. Canonical home is
  // _chrome.php; this is a hub-gated client guard until that lands.
  function relocateFilterToggle() {
    if (!window.matchMedia('(max-width:960px)').matches) return;
    var bar = document.querySelector('.feed-sort-bar');
    if (!bar) return;
    // Server-rendered proxy: wire its click to the canonical #bb-ham
    var proxy = bar.querySelector('.lg-filters-chip');
    if (proxy) {
      if (!proxy.getAttribute('data-lg-wired')) {
        proxy.setAttribute('data-lg-wired', '1');
        var hamRef = document.getElementById('bb-ham');
        if (hamRef) proxy.addEventListener('click', function () { hamRef.click(); });
      }
      return;
    }
    // Fallback: move #bb-ham itself into the bar
    var ham = document.getElementById('bb-ham');
    if (!ham || ham.getAttribute('data-lg-relocated')) return;
    ham.setAttribute('data-lg-relocated', '1');
    ham.classList.add('lg-filters-chip');
    if (!ham.querySelector('.lg-filters-chip__tx')) {
      var tx = document.createElement('span');
      tx.className = 'lg-filters-chip__tx';
      tx.textContent = 'Filters';
      ham.appendChild(tx);
    }
    bar.insertBefore(ham, bar.firstChild);
  }

  // ── Desktop filter/settings rail (Buck 2026-06-09) ──────────────────────────
  // The left rail now holds the category filters AND the Hub-style/theme settings
  // panel, so on desktop: (1) rename the sort-bar pill "Filters" → "Filter and
  // settings"; (2) default the persistent sidebar (>960) to COLLAPSED, remembering
  // if the user opens it (localStorage 'lg-nav-open'). Mobile keeps "Filters" + its
  // drawer. The pill already toggles body.nav-closed.
  function setupDesktopFilterNav() {
    if (window.matchMedia('(max-width:640px)').matches) return;   // desktop only
    var tx = document.querySelector('.feed-sort-bar .lg-filters-chip .lg-filters-chip__tx');
    if (tx && tx.textContent !== 'Filter and settings') tx.textContent = 'Filter and settings';
    if (!window.matchMedia('(min-width:961px)').matches) return;  // sidebar is persistent only >960
    if (document.body.getAttribute('data-lg-navinit')) return;
    document.body.setAttribute('data-lg-navinit', '1');
    var saved = null; try { saved = localStorage.getItem('lg-nav-open'); } catch (e) {}
    if (saved !== '1') {                                          // default: collapsed
      document.body.classList.add('nav-closed');
      var chip = document.querySelector('.lg-filters-chip'); if (chip) chip.classList.remove('is-on');
    }
    var chip2 = document.querySelector('.lg-filters-chip');
    if (chip2) chip2.addEventListener('click', function () {
      setTimeout(function () { try { localStorage.setItem('lg-nav-open', document.body.classList.contains('nav-closed') ? '0' : '1'); } catch (e) {} }, 60);
    });
  }

  // (Popular-tags search dropdown REMOVED 2026-06-10 — Ian: "popular tags
  // removed". ensureTagSugCss + wireDesktopSearchTags retired.)

  // Recompose ONE feed card into the Style-Sandbox card layout: build a top
  // meta row [OP avatar + author . time | category pill] from nodes the live
  // bb-mirror markup scatters (avatar/author sit at the BOTTOM in
  // .feed-card__op-meta; the category is in the TOP breadcrumb), which CSS
  // alone can't reorder across branches. The cover lift + bottom-line slim are
  // CSS. Idempotent (data-lg-card) and wrapped so one bad card can't break the
  // feed.
  // Sandbox action-row icons (inline SVG, no emoji) — heart / chat / share-box.
  var ICO_LIKE = '<svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.6l-1-1a5.5 5.5 0 1 0-7.8 7.8L12 21l8.8-8.6a5.5 5.5 0 0 0 0-7.8z"/></svg>';
  var ICO_REPLIES = '<svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 11.5a8.5 8.5 0 0 1-12.3 7.6L3 21l1.9-5.7A8.5 8.5 0 1 1 21 11.5z"/></svg>';
  var ICO_SHARE = '<svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 12v7a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-7"/><path d="M16 6l-4-4-4 4"/><path d="M12 2v13"/></svg>';
  // Instagram-style bookmark — outline normally, fills (currentColor) when saved.
  var ICO_SAVE = '<svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"><path d="M6 3h12a1 1 0 0 1 1 1v17l-7-4.5L5 21V4a1 1 0 0 1 1-1z"/></svg>';

  // ── Category icons on feed cards (mobile ≤640) — Buck+Ian meeting 2026-06-08 ──
  // Prepend a small line-SVG glyph to each card's category label so repair /
  // restoration / 3D-printing etc. read at a glance. Keys off the card's
  // server-set data-cat (forum category) + a label-keyword refine. Style matches
  // u.php's section icons (stroke=currentColor) so it inherits the label color and
  // is dark-mode-safe. NO external assets needed; if Jerry's real icon art arrives,
  // swap the path data per key in CAT_ICON_PATHS — nothing else changes.
  var CAT_ICON_PATHS = {
    repair:      '<path d="M14.5 5.3a3.6 3.6 0 0 1 .8 3.9l5 5a2 2 0 0 1-2.8 2.8l-5-5a3.6 3.6 0 0 1-4.6-4.6l2.2 2.2 2-2-2.2-2.2a3.6 3.6 0 0 1 4.6 0z"/><path d="m6.5 14.5-3 3 3 3 3-3"/>',
    builds:      '<path d="M3 21h18"/><path d="M5 21V8l7-4 7 4v13"/><path d="M9 21v-6h6v6"/>',
    tools:       '<path d="M4 7l5 5"/><path d="m9 12 9 9 2-2-9-9"/><path d="M4 7 7 4l4 4-3 3z"/><path d="M14 5l5 5"/>',
    business:    '<rect x="3" y="7" width="18" height="13" rx="2"/><path d="M8 7V5a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><path d="M3 12h18"/>',
    market:      '<path d="M4 6h16l-1.3 8.6a2 2 0 0 1-2 1.7H7.3a2 2 0 0 1-2-1.7L4 6z"/><path d="M4 6 3 3H1"/><circle cx="9" cy="20" r="1.2"/><circle cx="17" cy="20" r="1.2"/>',
    acoustic:    '<path d="M9 17V5l10-2v12"/><circle cx="6.5" cy="17" r="2.5"/><circle cx="16.5" cy="15" r="2.5"/>',
    sponsors:    '<circle cx="12" cy="8.5" r="5"/><path d="M9 12.5 7.5 21l4.5-2.6L16.5 21 15 12.5"/>',
    looths:      '<path d="M12 21s7-5.8 7-11a7 7 0 1 0-14 0c0 5.2 7 11 7 11z"/><circle cx="12" cy="10" r="2.5"/>',
    suggestions: '<path d="M9.5 19h5"/><path d="M10 22h4"/><path d="M12 2a6 6 0 0 0-3.6 10.8c.6.5 1 1.2 1.1 2h5c.1-.8.5-1.5 1.1-2A6 6 0 0 0 12 2z"/>',
    general:     '<path d="M21 11.5a8.5 8.5 0 0 1-12.3 7.6L3 21l1.9-5.7A8.5 8.5 0 1 1 21 11.5z"/>',
    restoration: '<path d="M12 8v4l2.5 2.5"/><path d="M3.5 12a8.5 8.5 0 1 0 2.2-5.7"/><path d="M3 4v3.5h3.5"/>',
    printing3d:  '<path d="M12 3 4 7v10l8 4 8-4V7z"/><path d="m4 7 8 4 8-4"/><path d="M12 11v10"/>',
    electronics: '<path d="M9 3v4M15 3v4M9 17v4M15 17v4M3 9h4M3 15h4M17 9h4M17 15h4"/><rect x="7" y="7" width="10" height="10" rx="1.5"/>',
    finishing:   '<path d="M5 3h11l3 3v6l-9 1v8a2 2 0 0 1-4 0v-8H5z"/><path d="M5 3v6h11"/>',
    cnc:         '<rect x="3" y="4" width="18" height="12" rx="1.5"/><path d="M7 20h10"/><path d="M9 16v4M15 16v4"/><path d="M8 8h8M8 11h5"/>',
    organisation:'<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M3 9h18"/><path d="M8 4v16"/>'
  };
  function svgCat(key) {
    var p = CAT_ICON_PATHS[key]; if (!p) return '';
    return '<svg class="lg-cat-ico" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" ' +
           'stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + p + '</svg>';
  }
  function pickCatIcon(catKey, label) {
    var t = (label || '').toLowerCase();
    if (/3d|print/.test(t)) return 'printing3d';
    if (/cnc/.test(t)) return 'cnc';
    if (/restor/.test(t)) return 'restoration';
    if (/finish|paint|spray|lacqu/.test(t)) return 'finishing';
    if (/amp|pickup|pedal|electron|wiring|pre-?amp/.test(t)) return 'electronics';
    if (/organis|organiz|shop org/.test(t)) return 'organisation';
    if (CAT_ICON_PATHS[catKey]) return catKey;
    return 'general';
  }
  function addCategoryIcon(card) {
    try {
      if (!window.matchMedia('(max-width:640px)').matches) return;
      if (!card || card.getAttribute('data-lg-catico')) return;
      var label = card.querySelector('.fc-category.lg-card-cat') || card.querySelector('.lg-card-cat');
      if (!label) return;
      if (label.querySelector('.lg-cat-ico')) { card.setAttribute('data-lg-catico', '1'); return; }
      var catKey = (card.getAttribute('data-cat') || '').trim().toLowerCase();
      var svg = svgCat(pickCatIcon(catKey, (label.textContent || '').trim()));
      if (!svg) { card.setAttribute('data-lg-catico', '1'); return; }
      label.insertAdjacentHTML('afterbegin', svg);
      label.classList.add('lg-has-catico');
      card.setAttribute('data-lg-catico', '1');
    } catch (e) {}
  }

  // Total reply count for a card: prefer the "View N replies" expander text,
  // else fall back to counting rendered reply stubs.
  function replyCount(card) {
    var exp = card.querySelector('.feed-card__expand');
    if (exp) {
      var m = (exp.textContent || '').match(/(\d+)/);
      if (m) return parseInt(m[1], 10);
    }
    return card.querySelectorAll('.reply-stub').length;
  }

  // After the action-row "replies" expands a thread, bb-mirror's native loader
  // shows only the first batch (~5) plus its own ".replies-loadmore" button.
  // Auto-click that button until up to `target` (10) replies are visible; the
  // native button then stays as the "Show more" control for the rest. Bounded,
  // and waits for each AJAX batch to land before clicking again so it can't
  // double-fire. Mobile only (the action row is mobile only). Drives the
  // coordinator's native loader, doesn't replace it.
  function autoLoadReplies(card, target) {
    if (card.__lgAL) return;            // one run per card at a time
    card.__lgAL = true;
    var last = -1, pending = false, tries = 0;
    function stop() { clearInterval(iv); card.__lgAL = false; }
    var iv = setInterval(function () {
      if (++tries > 40) { stop(); return; }
      var full = card.querySelector('.feed-card__replies-full');
      if (!full) return;
      revealReplyImages(full);
      enhanceReplyReactions(full);
      // force every loaded reply visible (native rendering hides some)
      var sl = full.querySelectorAll('.reply-stub');
      for (var q = 0; q < sl.length; q++) { sl[q].classList.remove('lg-rhide'); sl[q].classList.add('lg-rshow'); }
      var stubs = sl.length;
      if (stubs >= target) { stop(); return; }
      var lm = full.querySelector('.replies-loadmore');
      if (!lm) { if (stubs > 0) stop(); return; } // all loaded, or not loaded yet
      if (pending) { if (stubs !== last) { pending = false; last = stubs; } return; }
      last = stubs; pending = true; lm.click();
    }, 200);
  }

  // Make the native "View N replies" expander ALSO auto-unfold to 10 on mobile,
  // so replies unfold the same whether you tap the action-row "replies" or the
  // native button. Idempotent per card; only fires when expanding (not collapsing).
  function wireExpand(card) {
    if (!window.matchMedia('(max-width:640px)').matches) return;
    var exp = card.querySelector('.feed-card__expand');
    if (!exp || exp.getAttribute('data-lg-exp')) return;
    exp.setAttribute('data-lg-exp', '1');
    // Capture phase runs BEFORE the native toggle handler, so the button text is
    // still its pre-click state: "View N replies" = expanding, "Hide replies" =
    // collapsing. autoLoadReplies polls, so it tolerates the AJAX not being done.
    // Capture phase runs before the native toggle. On EXPAND, let the native
    // loader run, then cap the shown replies to 3 with a "View all N replies"
    // button (the "view more"). On collapse, let the native toggle do its thing.
    exp.addEventListener('click', function () {
      if (card.__lgPreviewing) return;
      var txt = exp.textContent || '';
      if (/hide/i.test(txt)) return;                       // collapsing
      var m = txt.match(/(\d+)/); var total = m ? parseInt(m[1], 10) : 0;
      card.__lgPreviewing = true;
      var tries = 0;
      var iv = setInterval(function () {
        if (++tries > 40) { clearInterval(iv); card.__lgPreviewing = false; return; }
        var full = card.querySelector('.feed-card__replies-full');
        if (!full || !full.querySelectorAll('.reply-stub').length) return; // wait for AJAX
        clearInterval(iv);
        revealReplyImages(full);
        enhanceReplyReactions(full);
        capPreview(card, full, exp, total);
      }, 150);
    }, true);
  }

  // NOTE (reactions-surface lane, 2026-06-06): the generic-emoji long-press
  // reaction picker (Buck's `wireReactions` stub: 👍❤️🔥🤘😆😮, no backend) was
  // ripped out. It faked reactions with the wrong glyph set and never persisted.
  // The REAL reaction experience is the comments+reactions engine's BuddyBoss
  // palette picker, which lives ON COMMENTS inside the comments modal
  // (archive-poc comments.php → comment-react.php → discovery.comment_reactions).
  // Feed-card / reply "Like" stays a plain visual toggle — there is no
  // card/reply-level reaction backend to consume. If product wants persisted
  // feed-card or reply reactions, that's an engine contract addition (route to
  // the comments+reactions lane), not a surface stub.

  // Lightweight transient toast (bottom-center, above the tab bar). Reused by
  // Share (copy-link confirmation) and any future optimistic action. Idempotent
  // singleton; auto-dismisses.
  var lgToastEl = null, lgToastT = null;
  function lgToast(msg) {
    try {
      if (!lgToastEl) {
        if (!document.getElementById('lg-toast-css')) {
          var st = document.createElement('style'); st.id = 'lg-toast-css';
          st.textContent =
            '.lg-toast{position:fixed;left:50%;bottom:84px;transform:translate(-50%,16px);' +
            'background:#1a1d1a;color:#fff;font:600 13.5px/1.2 var(--lg-font-sans,system-ui,sans-serif);' +
            'padding:11px 18px;border-radius:999px;z-index:9999;opacity:0;pointer-events:none;' +
            'box-shadow:0 8px 24px -8px rgba(0,0,0,.5);transition:opacity .2s,transform .2s;max-width:80vw;text-align:center}' +
            '.lg-toast.is-show{opacity:1;transform:translate(-50%,0)}';
          document.head.appendChild(st);
        }
        lgToastEl = document.createElement('div');
        lgToastEl.className = 'lg-toast';
        lgToastEl.setAttribute('role', 'status');
        document.body.appendChild(lgToastEl);
      }
      lgToastEl.textContent = msg;
      lgToastEl.classList.add('is-show');
      clearTimeout(lgToastT);
      lgToastT = setTimeout(function () { lgToastEl.classList.remove('is-show'); }, 1900);
    } catch (e) {}
  }

  // Build the sandbox Like / N replies / Share row and insert it before the
  // replies block (after the cover+title+excerpt). "Replies" expands the inline
  // replies; Share uses the Web Share API (falls back to copy link); Like is a
  // visual toggle only (no reactions backend yet — coordinator lane). Idempotent.
  // ── Tap-to-read clamped bodies that have NO Read-more (Buck bug 2026-06-08) ──
  // forums.css clamps .feed-card__op-excerpt to -webkit-line-clamp:6, expecting a
  // "Read more" button to reveal the rest. But _feed.php only renders that button
  // when the body is TEXT-longer than the 440-char snippet — a body under 440 chars
  // that still wraps past 6 lines gets clamped with "…" and NO way to expand. The
  // full text is already in the DOM (just CSS-hidden), so we add a synthetic
  // "Read more" that unclamps it. Mobile only; skipped when a native read-more exists.
  function ensureUnclampCss() {
    if (document.getElementById('lg-unclamp-css')) return;
    var s = document.createElement('style'); s.id = 'lg-unclamp-css';
    s.textContent = [
      '@media (max-width:640px){',
      '.feed-page .feed-card__op-excerpt.lg-unclamp,.feed-page .fc-excerpt.lg-unclamp .feed-card__op-excerpt{',
      '-webkit-line-clamp:unset!important;display:block!important;overflow:visible!important;max-height:none!important}',
      '.feed-page .lg-rm-syn{display:block;width:100%;text-align:left;background:none;border:0;cursor:pointer;',
      'padding:6px 0 2px;margin:0;font:600 13px/1.3 var(--lg-font-sans,system-ui,-apple-system,"Segoe UI",sans-serif);color:var(--lguser-accent-d,#52613d)}',
      '}'
    ].join('\n');
    (document.head || document.documentElement).appendChild(s);
  }
  // Register a card; the clamp check is DEFERRED to when the card scrolls into view.
  // (Measuring scrollHeight>clientHeight at build time is unreliable for cards still
  // below the fold — they lay out later and were wrongly read as "not clamped", so
  // far-down posts never got the button = Buck's "works on some, not that exact post".)
  var excerptIO = null;
  function ensureExcerptReadMore(card) {
    if (!window.matchMedia('(max-width:640px)').matches) return;
    if (!card || card.getAttribute('data-lg-exrm')) return;
    if (card.querySelector('.feed-card__read-more:not(.lg-rm-syn)')) return;  // native read-more owns it
    if (!card.querySelector('.feed-card__op-excerpt')) return;                // nothing to expand
    card.setAttribute('data-lg-exrm', '1');                                   // registered (button added on view)
    if (!('IntersectionObserver' in window)) { evalExcerptClamp(card); return; }
    if (!excerptIO) excerptIO = new IntersectionObserver(function (ents) {
      ents.forEach(function (en) { if (en.isIntersecting) { evalExcerptClamp(en.target); excerptIO.unobserve(en.target); } });
    }, { threshold: 0.01 });
    excerptIO.observe(card);
  }
  function evalExcerptClamp(card) {
    if (card.querySelector('.lg-rm-syn')) return;
    if (card.querySelector('.feed-card__read-more:not(.lg-rm-syn)')) return;
    var ex = card.querySelector('.feed-card__op-excerpt'); if (!ex) return;
    if (ex.scrollHeight <= ex.clientHeight + 3) return;   // genuinely not clamped (now laid out + in view)
    ensureUnclampCss();
    var btn = document.createElement('button');
    btn.type = 'button'; btn.className = 'feed-card__read-more lg-rm-syn'; btn.setAttribute('data-state', 'collapsed');
    btn.textContent = 'Read more ▾';
    (ex.parentNode || card).insertBefore(btn, ex.nextSibling);
    btn.addEventListener('click', function (e) {
      e.preventDefault(); e.stopPropagation();
      var open = ex.classList.toggle('lg-unclamp');
      btn.textContent = open ? 'Show less ▴' : 'Read more ▾';
      btn.setAttribute('data-state', open ? 'expanded' : 'collapsed');
    });
  }

  function buildActions(card) {
    // Server-rendered path (_feed.php feed_action_bar): the bar already ships with
    // the card — JS only WIRES it, never rebuilds it (mirrors relayCard's
    // data-lg-card guard, kills the post-paint pop-in). Fallback below builds the
    // row for any legacy/dynamic card that lacks one.
    addCategoryIcon(card);
    ensureExcerptReadMore(card);
    var row = card.querySelector('.lg-card-actions');
    if (!row) {
      var n = replyCount(card);
      var label = n === 0 ? 'Reply' : (n === 1 ? '1 reply' : n + ' replies');
      row = document.createElement('div');
      row.className = 'feed-card__actions lg-card-actions';
      row.innerHTML =
        '<span class="lg-act lg-act-like" role="button" tabindex="0">' + ICO_LIKE + 'Like</span>' +
        '<span class="lg-act lg-act-replies" role="button" tabindex="0">' + ICO_REPLIES + label + '</span>' +
        '<span class="lg-act lg-act-share" role="button" tabindex="0">' + ICO_SHARE + 'Share</span>';
      var replies = card.querySelector('.feed-card__replies');
      var header = card.querySelector('.feed-card__header');
      if (replies) replies.parentNode.insertBefore(row, replies);
      else if (header && header.nextSibling) header.parentNode.insertBefore(row, header.nextSibling);
      else if (header) header.parentNode.appendChild(row);
      else card.appendChild(row);
    }
    wireActions(card, row);
  }

  // Attach handlers to an action row (server-rendered or built). Idempotent via
  // data-lg-wired so it's safe to call on every relay pass / re-wire.
  function wireActions(card, row) {
    if (!row || row.getAttribute('data-lg-wired')) return;
    row.setAttribute('data-lg-wired', '1');
    var like = row.querySelector('.lg-act-like');
    if (like) {
      like.addEventListener('click', function () { like.classList.toggle('is-on'); });
    }
    var rep = row.querySelector('.lg-act-replies');
    if (rep) rep.addEventListener('click', function (e) {
      // MOBILE: tapping Reply on a discussion/topic post opens the replies sheet with
      // its Facebook-style composer focused (keyboard up) — so you can reply to the
      // POST itself, not just to a reply (Buck 2026-06-08). Content cards are handled
      // by keepContentOnHub's capture route, so this only governs topic/legacy cards.
      if (window.matchMedia('(max-width:640px)').matches && card.getAttribute('data-topic-id')) {
        if (e) { e.preventDefault(); e.stopPropagation(); }
        openRepliesSheet(card, { focus: true });
        return;
      }
      var exp = card.querySelector('.feed-card__expand');
      if (exp) { exp.click(); return; }                          // has replies → expand inline
      var cta = card.querySelector('.feed-card__reply-cta');
      if (cta) { cta.click(); return; }                          // forum topic → reply composer
      var cm = card.querySelector('.feed-card__comments-btn');
      if (cm) { cm.click(); return; }                            // content card (article/loothprint) → comments
      var l = card.querySelector('.feed-card__title a');         // last resort → open the post so the tap is never dead
      var href = (l && l.getAttribute('href')) || card.getAttribute('data-href');
      if (href) location.href = href;
    });
    var share = row.querySelector('.lg-act-share');
    if (share) share.addEventListener('click', function () {
      var link = card.querySelector('.feed-card__title a');
      var url = link ? link.href : location.href;
      var title = link ? (link.textContent || '').trim() : 'Looth Hub';
      function legacyCopy() {
        try {
          var ta = document.createElement('textarea');
          ta.value = url; ta.setAttribute('readonly', ''); ta.style.position = 'fixed'; ta.style.opacity = '0';
          document.body.appendChild(ta); ta.select(); ta.setSelectionRange(0, url.length);
          var ok = document.execCommand('copy'); document.body.removeChild(ta); return ok;
        } catch (e) { return false; }
      }
      function copyLink() {
        if (navigator.clipboard && navigator.clipboard.writeText) {
          navigator.clipboard.writeText(url).then(
            function () { lgToast('Link copied'); },
            function () { lgToast(legacyCopy() ? 'Link copied' : 'Couldn’t copy link'); }
          );
        } else { lgToast(legacyCopy() ? 'Link copied' : 'Couldn’t copy link'); }
      }
      try {
        if (navigator.share) {
          navigator.share({ title: title, url: url }).catch(function (err) {
            if (err && err.name === 'AbortError') return;        // user dismissed the native sheet — no toast
            copyLink();                                          // sheet failed/unavailable → copy + confirm
          });
        } else { copyLink(); }
      } catch (e) { copyLink(); }
    });
    // Instagram-style SAVE bookmark (Buck 2026-06-08), right-aligned. Persists to the
    // ACCOUNT via the CANONICAL archive-poc save API (discovery.saved_posts) so mobile
    // + desktop share ONE store — wired per the coordinator's contract (b820532).
    if (!row.querySelector('.lg-act-save')) {
      var sv = document.createElement('span');
      sv.className = 'lg-act lg-act-save'; sv.setAttribute('role', 'button'); sv.setAttribute('tabindex', '0');
      sv.setAttribute('aria-label', 'Save'); sv.innerHTML = ICO_SAVE;
      row.appendChild(sv);
      var sref = cardSaveRef(card);
      if (sref && lgSavedSet[sref.key]) sv.classList.add('is-on');
      sv.addEventListener('click', function (e) { e.stopPropagation(); lgToggleSave(card, sv); });
    }
  }
  // Resolve a card to the canonical {post_type,item_id} the save API keys on. Content
  // cards carry data-post-type + data-item-id; discussion/topic cards carry
  // data-topic-id (post_type 'topic'). null if neither (not savable).
  function cardSaveRef(card) {
    var pt = card.getAttribute('data-post-type'), id = card.getAttribute('data-item-id');
    if ((!pt || !id) && card.getAttribute('data-topic-id')) { pt = 'topic'; id = card.getAttribute('data-topic-id'); }
    if (!pt || !id) return null;
    id = parseInt(id, 10); if (!id) return null;
    return { pt: pt, id: id, key: pt + ':' + id };
  }
  function postUrl(card) {
    var l = card.querySelector('.feed-card__title a');
    return (l && l.getAttribute('href')) || card.getAttribute('data-href') || '';
  }
  // Canonical save API: GET /archive-api/v0/save-post returns a WP nonce + saved-state;
  // POST toggles; GET /archive-api/v0/my-saved is the render-ready list. Cache the nonce.
  var lgSaveNonce = null, lgSavedSet = {}, lgSavedItems = [];
  function lgGetNonce(cb) {
    if (lgSaveNonce) { cb(lgSaveNonce); return; }
    fetch('/archive-api/v0/save-post', { credentials: 'include' })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (d) { lgSaveNonce = (d && d.nonce) || null; cb(lgSaveNonce); })
      .catch(function () { cb(null); });
  }
  function lgToggleSave(card, btn) {
    var ref = cardSaveRef(card); if (!ref) return;
    var saving = !btn.classList.contains('is-on');
    btn.classList.toggle('is-on', saving);                 // optimistic
    lgSavedSet[ref.key] = saving ? 1 : 0;
    if (typeof lgToast === 'function') lgToast(saving ? 'Saved' : 'Removed from saved');
    lgGetNonce(function (nonce) {
      if (!nonce) return;                                  // not logged in → can't persist
      fetch('/archive-api/v0/save-post', {
        method: 'POST', credentials: 'include',
        headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
        body: JSON.stringify({ post_type: ref.pt, item_id: ref.id, _wpnonce: nonce, unsave: !saving })
      }).then(function (r) { return r.ok ? r.json() : null; }).then(function (d) {
        if (d && typeof d.saved === 'boolean') { btn.classList.toggle('is-on', d.saved); lgSavedSet[ref.key] = d.saved ? 1 : 0; }
        try { window.dispatchEvent(new Event('lg-saved-changed')); } catch (e) {}
      }).catch(function () {});
    });
  }
  // On load, pull the account's saved list (render-ready) so bookmark fill-state +
  // the Saved view are correct. Anon → {authenticated:false}; leave buttons off.
  function lgSyncSaved(cb) {
    fetch('/archive-api/v0/my-saved', { credentials: 'include' })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (d) {
        if (d && d.authenticated && Array.isArray(d.items)) {
          lgSavedItems = d.items;
          var set = {}; d.items.forEach(function (it) { set[it.post_type + ':' + it.item_id] = 1; });
          lgSavedSet = set;
          document.querySelectorAll('.lg-act-save').forEach(function (b) {
            var card = b.closest('.feed-card'); if (!card) return;
            var ref = cardSaveRef(card); if (ref) b.classList.toggle('is-on', !!set[ref.key]);
          });
        }
        if (cb) cb();
      })
      .catch(function () { if (cb) cb(); });
  }

  // Reshape the feed sort bar toward the sandbox: clone the hero "+ New post"
  // button into the bar (wired to the original's click) so MOBILE shows it in
  // the bar while DESKTOP keeps the hero button. Pill-tab styling + Filters/New
  // post on the right + hidden inline search are CSS, mobile-only. Idempotent.
  function restyleSortBar() {
    try {
      var bar = document.querySelector('.feed-sort-bar');
      if (!bar || bar.getAttribute('data-lg-bar')) return;
      bar.setAttribute('data-lg-bar', '1');
      var hero = document.querySelector('.forum-header__new-post');
      if (hero && !bar.querySelector('.lg-newpost')) {
        var clone = hero.cloneNode(true);
        clone.classList.add('lg-newpost');
        clone.removeAttribute('id');
        clone.addEventListener('click', function (e) { e.preventDefault(); hero.click(); });
        bar.appendChild(clone);
      }
    } catch (e) {}
  }
  // "Saved" pill + client-rendered saved view RETIRED 2026-06-10 (bespoke-cutover,
  // audit C5): superseded by the canonical server-side Saved view — ?saved=1
  // constrains the feed union in _feed.php (9bcf24e) and the rail "Saved posts"
  // toggle (+ the You-menu link) is the entry point. lgToggleSave/lgSyncSaved
  // above STAY: they wire + state-sync the per-card bookmark buttons.

  // Tap the post text (clamped excerpt OR expanded full body) to toggle the
  // canonical "Read more" expander. Mobile only (we hide the Read-more button
  // there to match the sandbox), so desktop behavior is unchanged. Clicks on
  // links inside the text are left alone. Idempotent per node.
  function wireTextToggle(card) {
    if (!window.matchMedia('(max-width:640px)').matches) return;
    var rm = card.querySelector('.feed-card__read-more');
    if (!rm) return;
    var targets = [
      card.querySelector('.feed-card__op-excerpt'),
      card.querySelector('.feed-card__full-body')
    ];
    for (var i = 0; i < targets.length; i++) {
      var el = targets[i];
      if (!el || el.getAttribute('data-lg-txt')) continue;
      el.setAttribute('data-lg-txt', '1');
      el.style.cursor = 'pointer';
      el.addEventListener('click', function (ev) {
        if (ev.target.closest && ev.target.closest('a')) return;
        // The whole feed card has a click-to-open-thread handler; stop the
        // excerpt tap from bubbling to it so we expand inline instead of
        // navigating away.
        ev.stopPropagation();
        rm.click();
      });
    }
  }

  function relayCard(card) {
    try {
      if (!card) return;
      var metaTop = card.querySelector('.feed-card__meta-top');
      if (!metaTop) return;
      // Server-rendered: skip DOM recompose, just wire behaviors
      if (card.getAttribute('data-lg-card')) {
        buildActions(card);
        wireTextToggle(card);
        wireExpand(card);
        return;
      }
      card.setAttribute('data-lg-card', '1');

      var ctxForum = card.querySelector('.feed-card__ctx-forum');
      var ctxParent = card.querySelector('.feed-card__ctx-parent');
      var catText = (ctxForum && ctxForum.textContent.trim()) ||
        (ctxParent && ctxParent.textContent.trim()) || '';
      var timeNode = metaTop.querySelector('.feed-card__time');
      var timeText = timeNode ? timeNode.textContent.trim() : '';
      var opMeta = card.querySelector('.feed-card__op-meta');
      var avatar = opMeta && opMeta.querySelector('.avatar-init');
      var author = card.querySelector('.feed-card__op-author');

      var frag = document.createDocumentFragment();
      if (avatar) {
        var av = avatar.cloneNode(true);
        av.classList.add('lg-card-avatar');
        frag.appendChild(av);
      }
      var idLine = document.createElement('span');
      idLine.className = 'lg-card-id';
      if (author) {
        var a2 = author.cloneNode(true);
        a2.className = 'lg-card-author';
        idLine.appendChild(a2);
      }
      frag.appendChild(idLine);
      if (timeText) {
        var ts = document.createElement('span');
        ts.className = 'lg-card-time';
        ts.textContent = timeText;
        frag.appendChild(ts);
      }
      if (catText) {
        var cat = document.createElement('span');
        cat.className = 'feed-card__kind-badge lg-card-cat';
        cat.textContent = catText;
        frag.appendChild(cat);
      }
      metaTop.textContent = '';
      metaTop.appendChild(frag);
      buildActions(card);
      wireTextToggle(card);
      wireExpand(card);
    } catch (e) { /* never let one card break the feed */ }
  }

  function relayCards(root) {
    var scope = (root && root.querySelectorAll) ? root : document;
    var cards = scope.querySelectorAll('.feed-card:not([data-lg-card])');
    for (var i = 0; i < cards.length; i++) relayCard(cards[i]);
  }

  // Reply photos ship deferred: the canonical markup hides them behind a
  // ".reply-stub__img-open" ("Show image") button with the real URL in the
  // image's data-src. Buck wants reply photos shown, so auto-trigger that native
  // reveal for every VISIBLE reply (the button's own handler sets src from
  // data-src) and hide the now-redundant button (which also carries an emoji we
  // don't want). Hidden overflow stubs are left for when they become visible.
  // Idempotent via data-lg-shown.
  function revealReplyImages(scope) {
    var root = (scope && scope.querySelectorAll) ? scope : document;
    var btns = root.querySelectorAll('.reply-stub__img-open:not([data-lg-shown])');
    for (var i = 0; i < btns.length; i++) {
      var b = btns[i];
      var stub = b.closest ? b.closest('.reply-stub') : null;
      // skip only replies we explicitly hide past the cap; reveal everything else
      // (the native code may have them display:none until we force-show them)
      if (stub && stub.classList.contains('lg-rhide')) continue;
      b.setAttribute('data-lg-shown', '1');
      try { b.click(); } catch (e) {}
      b.style.display = 'none';
    }
  }

  // Recompose each loaded reply Facebook-style with a plain Like toggle (no
  // reaction picker — see the wireReactions rip-out note above).
  function enhanceReplyReactions(scope) {
    var root = (scope && scope.querySelectorAll) ? scope : document;
    var stubs = root.querySelectorAll('.reply-stub:not([data-lg-fb])');
    for (var i = 0; i < stubs.length; i++) fbStyleReply(stubs[i]);
  }

  // Recompose one reply into Facebook-style: avatar on the left, a rounded
  // bubble with the bold name + comment text, then a Like / Reply / time action
  // row beneath. Like: tap to like, long-press for the reaction picker. Reply:
  // opens an inline reply box. Idempotent; guarded so one bad reply can't break
  // the feed.
  function fbStyleReply(stub) {
    try {
      if (!stub || stub.getAttribute('data-lg-fb')) return;
      var head = stub.querySelector('.reply-stub__head');
      var body = stub.querySelector('.reply-stub__body');
      if (!head || !body) return;
      stub.setAttribute('data-lg-fb', '1');
      var avatar = head.querySelector('.avatar-init, .avatar-init--img');
      var author = head.querySelector('.reply-stub__author');
      var time = head.querySelector('.reply-stub__time');
      // Capture the native reply button's reply-to id (for nested posting) before
      // we drop the head, since canonical carries it there.
      var nativeReply = head.querySelector('.reply-stub__reply');
      if (nativeReply && nativeReply.dataset.replyTo) stub.setAttribute('data-lg-replyto', nativeReply.dataset.replyTo);

      var col = document.createElement('div'); col.className = 'lg-fb-col';
      var bubble = document.createElement('div'); bubble.className = 'lg-fb-bubble';
      if (author) { author.classList.add('lg-fb-name'); bubble.appendChild(author); }
      bubble.appendChild(body);                 // text + images move intact
      col.appendChild(bubble);

      var actions = document.createElement('div'); actions.className = 'lg-fb-actions';
      var like = document.createElement('span'); like.className = 'lg-fb-act lg-fb-like'; like.setAttribute('role', 'button'); like.textContent = 'Like';
      var reply = document.createElement('span'); reply.className = 'lg-fb-act lg-fb-reply'; reply.setAttribute('role', 'button'); reply.textContent = 'Reply';
      actions.appendChild(like);
      actions.appendChild(reply);
      if (time) { time.classList.add('lg-fb-time'); actions.appendChild(time); }
      col.appendChild(actions);

      stub.insertBefore(col, head);
      if (avatar) stub.insertBefore(avatar, col);
      if (head.parentNode) head.remove();

      like.addEventListener('click', function () { like.classList.toggle('is-on'); });
      reply.addEventListener('click', function () { openReplyBox(col, author, stub); });

      revealReplyImages(stub);
    } catch (e) {}
  }

  // Pull the signed-in member's avatar (shared header) for the reply box.
  function myAvatarSrc() {
    var img = document.querySelector('.lg-chrome__avatar img, .lg-chrome__account img');
    return img && (img.currentSrc || img.getAttribute('src')) || '';
  }

  // Inline reply box (Facebook-style) under a comment, @mentioning its author.
  // Submit posts via submitReply(). Idempotent per column.
  function openReplyBox(col, author, stub) {
    var existing = col.querySelector('.lg-fb-replybox');
    if (existing) { var t0 = existing.querySelector('textarea'); if (t0) t0.focus(); return; }
    var box = document.createElement('div'); box.className = 'lg-fb-replybox';
    var avi = myAvatarSrc();
    var aviEl = document.createElement('span'); aviEl.className = 'lg-fb-myavi';
    if (avi) aviEl.innerHTML = '<img src="' + avi + '" alt="">';
    var wrap = document.createElement('div'); wrap.className = 'lg-fb-replywrap';
    var ta = document.createElement('textarea'); ta.className = 'lg-fb-replyinput'; ta.rows = 1; ta.placeholder = 'Write a reply…';
    var name = author ? (author.textContent || '').trim().split(/[\s,]/)[0] : '';
    if (name) ta.value = '@' + name + ' ';
    var send = document.createElement('button'); send.type = 'button'; send.className = 'lg-fb-send'; send.textContent = 'Post';
    send.disabled = false;
    wrap.appendChild(ta); wrap.appendChild(send);
    box.appendChild(aviEl); box.appendChild(wrap);
    col.appendChild(box);
    ta.focus();
    try { ta.setSelectionRange(ta.value.length, ta.value.length); } catch (e) {}
    ta.addEventListener('input', function () { ta.style.height = 'auto'; ta.style.height = Math.min(ta.scrollHeight, 120) + 'px'; });
    send.addEventListener('click', function () { submitReply(ta.value, box, send, stub); });
  }

  // Post the reply via the canonical BuddyBoss flow: lazily fetch the auth nonce
  // from /bb-mirror-api/v0/auth.php, then POST to /wp-json/buddyboss/v1/reply
  // with topic/forum (from the card's reply CTA) and reply_to (this reply's id,
  // for nesting). On success, optimistically show the new comment.
  function submitReply(text, box, send, stub) {
    text = (text || '').trim();
    if (!text) return;
    send.disabled = true;
    var note = box.querySelector('.lg-fb-note') || document.createElement('div');
    note.className = 'lg-fb-note'; note.textContent = 'Posting…';
    if (!note.parentNode) box.appendChild(note);

    var card = stub.closest('.feed-card');
    var cta = card && card.querySelector('.feed-card__reply-cta[data-frm-open]');
    var topicId = parseInt((cta && cta.dataset.topicId) || (card && card.dataset.topicId) || '', 10);
    var forumId = parseInt((cta && cta.dataset.forumId) || '', 10);
    var replyTo = parseInt(stub.getAttribute('data-lg-replyto') || '0', 10);
    var myName = 'You';

    fetch('/bb-mirror-api/v0/auth.php', { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d || !d.authenticated) throw new Error('Sign in to reply.');
        myName = d.display_name || 'You';
        var payload = { topic_id: topicId, forum_id: forumId, content: text };
        if (replyTo) payload.reply_to = replyTo;
        return fetch('/wp-json/buddyboss/v1/reply', {
          method: 'POST', credentials: 'same-origin',
          headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': d.nonce },
          body: JSON.stringify(payload)
        });
      })
      .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
      .then(function (res) {
        if (!res.ok) throw new Error((res.j && (res.j.message || res.j.code)) || 'Could not post.');
        appendOptimisticReply(stub, myName, text);
        box.parentNode && box.remove();
      })
      .catch(function (e) {
        note.textContent = (e && e.message) || 'Could not post.';
        send.disabled = false;
      });
  }

  // Show the just-posted reply immediately (the real one nests on next load).
  function appendOptimisticReply(stub, name, text) {
    var full = stub.closest('.feed-card__replies-full') || stub.parentNode;
    if (!full) return;
    var el = document.createElement('div');
    el.className = 'reply-stub lg-rshow';
    el.setAttribute('data-lg-fb', '1');
    var avi = myAvatarSrc();
    el.innerHTML =
      '<span class="avatar-init avatar-init--img">' + (avi ? '<img src="' + avi + '" alt="">' : '') + '</span>' +
      '<div class="lg-fb-col"><div class="lg-fb-bubble">' +
      '<span class="lg-fb-name"></span><span class="reply-stub__excerpt"></span></div>' +
      '<div class="lg-fb-actions"><span class="lg-fb-act lg-fb-like" role="button">Like</span>' +
      '<span class="lg-fb-act lg-fb-reply" role="button">Reply</span><span class="lg-fb-time">now</span></div></div>';
    el.querySelector('.lg-fb-name').textContent = name;
    el.querySelector('.reply-stub__excerpt').textContent = text;
    if (stub.nextSibling) full.insertBefore(el, stub.nextSibling); else full.appendChild(el);
    var like = el.querySelector('.lg-fb-like');
    like.addEventListener('click', function () { like.classList.toggle('is-on'); });
    var rep = el.querySelector('.lg-fb-reply');
    rep.addEventListener('click', function () { openReplyBox(el.querySelector('.lg-fb-col'), el.querySelector('.lg-fb-name'), el); });
  }

  // Mobile only: watch the feed subtree for replies loading in (native expand,
  // loadmore, or our autoLoadReplies) and reveal their photos. Desktop keeps the
  // native click-to-reveal button untouched.
  var replyImgObserver = null;
  function watchReplyImages() {
    if (!window.matchMedia('(max-width:640px)').matches) return;
    var first = document.querySelector('.feed-card');
    var host = first && first.parentElement;
    if (!host || replyImgObserver) return;
    revealReplyImages(host);
    enhanceReplyReactions(host);
    replyImgObserver = new MutationObserver(function () { revealReplyImages(host); enhanceReplyReactions(host); });
    replyImgObserver.observe(host, { childList: true, subtree: true });
  }

  // Inline reply preview: as a card scrolls near the viewport, lazily load its
  // replies and show the first 3 on the feed, with "View all N replies" to
  // expand the rest. Mobile only; one load per card. Reuses the native loader.
  function previewReplies(card) {
    if (card.__lgPrev) return;
    card.__lgPrev = true;
    var exp = card.querySelector('.feed-card__expand');
    if (!exp) return;                                 // no replies on this card
    if (/hide/i.test(exp.textContent || '')) return;  // already expanded by the user
    var total = replyCount(card);                      // count while button still says "View N"
    card.__lgPreviewing = true;                        // tell wireExpand not to auto-load to 10
    exp.click();                                       // native: load + show the first batch
    var tries = 0;
    var iv = setInterval(function () {
      if (++tries > 40) { clearInterval(iv); card.__lgPreviewing = false; return; }
      var full = card.querySelector('.feed-card__replies-full');
      if (!full || !full.querySelectorAll('.reply-stub').length) return; // wait for AJAX
      clearInterval(iv);
      capPreview(card, full, exp, total);
    }, 150);
  }

  // Collapse the just-loaded batch to a ONE-reply teaser (always show at least
  // one reply, with its photo) + a "Show all N replies" button that loads EVERY
  // reply in one tap. (Buck 2026-06-08: the old preview capped to 3 but raced the
  // AJAX — it stuck at one reply and its "View all" called the flaky
  // autoLoadReplies, so the thread "only loaded one reply no matter what I
  // clicked." Teaser is now intentionally one; "Show all" drives the proven
  // autoLoadAllReplies that walks every batch.) Idempotent via data-lg-capped.
  function capPreview(card, full, exp, total) {
    if (!card.getAttribute('data-lg-capped')) {
      card.setAttribute('data-lg-capped', '1');
      var stubs = full.querySelectorAll('.reply-stub');
      for (var i = 0; i < stubs.length; i++) {
        // teaser: show the first reply, hide the rest until "Show all"
        if (i < 1) { stubs[i].classList.remove('lg-rhide'); stubs[i].classList.add('lg-rshow'); }
        else { stubs[i].classList.remove('lg-rshow'); stubs[i].classList.add('lg-rhide'); }
      }
      revealReplyImages(full);          // the teaser reply keeps its photo
      enhanceReplyReactions(full);
      if (exp) exp.style.display = 'none';
      var lm = full.querySelector('.replies-loadmore');
      if (lm) lm.style.display = 'none';
      if (total > 1 && !full.querySelector('.lg-viewall')) {
        var va = document.createElement('button');
        va.type = 'button'; va.className = 'lg-viewall';
        va.textContent = 'Show all ' + total + ' replies';
        va.addEventListener('click', function () {
          card.removeAttribute('data-lg-capped');
          var all = full.querySelectorAll('.reply-stub');
          for (var k = 0; k < all.length; k++) { all[k].classList.remove('lg-rhide'); all[k].classList.add('lg-rshow'); }
          if (lm) lm.style.display = '';
          if (exp) exp.style.display = '';
          va.remove();
          autoLoadAllReplies(card);     // walks every remaining batch, then reveals all
        });
        full.appendChild(va);
      }
    }
    card.__lgPreviewing = false;
  }

  var previewObserver = null;
  function observePreviewCards(root) {
    if (!previewObserver) return;
    var scope = (root && root.querySelectorAll) ? root : document;
    var cards = scope.querySelectorAll('.feed-card:not([data-lg-prevobs])');
    for (var i = 0; i < cards.length; i++) {
      cards[i].setAttribute('data-lg-prevobs', '1');
      previewObserver.observe(cards[i]);
    }
  }
  function watchPreviewReplies() {
    if (!window.matchMedia('(max-width:640px)').matches) return;
    if (previewObserver || !('IntersectionObserver' in window)) return;
    previewObserver = new IntersectionObserver(function (entries) {
      for (var i = 0; i < entries.length; i++) {
        if (entries[i].isIntersecting) {
          previewReplies(entries[i].target);
          previewObserver.unobserve(entries[i].target);
        }
      }
    }, { rootMargin: '250px 0px' });
    observePreviewCards(document);
  }

  var cardObserver = null;
  function watchCards() {
    var first = document.querySelector('.feed-card');
    var host = first && first.parentElement;
    if (!host || cardObserver) return;
    cardObserver = new MutationObserver(function (muts) {
      for (var i = 0; i < muts.length; i++) {
        var added = muts[i].addedNodes;
        for (var j = 0; j < added.length; j++) {
          var n = added[j];
          if (!n || n.nodeType !== 1) continue;
          if (n.classList && n.classList.contains('feed-card')) relayCard(n);
          else if (n.querySelectorAll) relayCards(n);
        }
      }
    });
    cardObserver.observe(host, { childList: true });
  }

  // Transform the canonical New-post modal (#ntm-form) into a Facebook-style
  // "write a post": hide Forum/Title/free-text-Tags, show avatar + name + a
  // "What's on your mind?" body + selectable CATEGORY chips (from the forum
  // options) that route + tag the post. Title is auto-derived from the first
  // line; forum defaults to General (3837). Idempotent.
  function fbStyleComposer() {
    // Mobile-only (<=640): the fb-style composer is the MOBILE layer. On desktop the
    // native #ntm-form owns the picker (a native single-select - no "two forums" bug),
    // per the 640 split + the "no JS-reshape the look on desktop" rule. Gated here so
    // both call sites (init + composer-open) honor it. (coordinator, ntm-picker unblock)
    if (!window.matchMedia('(max-width:640px)').matches) return;
    var form = document.getElementById('ntm-form');
    if (!form || form.getAttribute('data-lg-fbc')) return;
    var forumSel = document.getElementById('ntm-forum');
    var titleIn = document.getElementById('ntm-title-in');
    var tagsIn = document.getElementById('ntm-tags');
    var body = document.getElementById('ntm-content');
    var submit = document.getElementById('ntm-submit');
    if (!forumSel || !submit) return;
    form.setAttribute('data-lg-fbc', '1');
    form.classList.add('lg-fbc');

    // The native forum picker #ntm-forum is now a <div role=radiogroup
    // class=ntm-forumlist> (category headers + radio leaves, name=forum_id) that
    // hub-coord rebuilt from the old <select> — it IS the single-select
    // category->leaf list Ian asked for. So the mobile composer just SHOWS it
    // (below) instead of hiding it + building a duplicate. This is light mobile
    // polish (scroll cap + checked theming); base .ntm-fl styling is hub-coord's
    // in forums.css. (buck-coord, ntm-picker — contract changed under §A)
    if (!document.getElementById('lg-fbc-list-css')) {
      var fbcSt = document.createElement('style'); fbcSt.id = 'lg-fbc-list-css';
      fbcSt.textContent =
        '.lg-fbc #ntm-forum.ntm-forumlist{max-height:230px;overflow:auto;border:1px solid var(--lg-line,#e3ddd0);border-radius:10px;background:#fff;margin:10px 0 4px}' +
        '.lg-fbc #ntm-forum .ntm-fl__cat{position:sticky;top:0;background:#fff;padding:8px 12px 4px;font:700 10.5px/1 var(--lg-font-sans,system-ui,sans-serif);letter-spacing:.07em;text-transform:uppercase;color:var(--lguser-accent,#6b7c52)}' +
        '.lg-fbc #ntm-forum .ntm-fl__cat:not(:first-child){border-top:1px solid var(--lg-line,#e3ddd0);margin-top:2px;padding-top:8px}' +
        '.lg-fbc #ntm-forum .ntm-fl__leaf{display:flex;align-items:center;gap:8px;padding:8px 12px;margin:0;font:500 14px/1.2 var(--lg-font-sans,system-ui,sans-serif);color:var(--lg-ink,#323532);cursor:pointer}' +
        '.lg-fbc #ntm-forum .ntm-fl__leaf:has(input:checked){background:var(--lguser-pill,#eef2e3);font-weight:700}' +
        '.lg-fbc #ntm-forum .ntm-fl__leaf input{accent-color:var(--lguser-accent,#6b7c52);flex:0 0 auto;margin:0}';
      document.head.appendChild(fbcSt);
    }

    var quicktags = document.getElementById('ntm-quicktags');
    [titleIn, tagsIn, quicktags].forEach(function (el) {
      if (!el) return;
      el.style.display = 'none';
      var lab = el.previousElementSibling;
      if (lab && lab.tagName === 'LABEL') lab.style.display = 'none';
    });
    // the visible body is the Quill editor (#ntm-editor); #ntm-content is its
    // hidden textarea fallback. Hide the "Body" label (right before the editor)
    // and the paste-hint tip.
    var editor = document.getElementById('ntm-editor') || body;
    var blab = editor && editor.previousElementSibling;
    if (blab && blab.tagName === 'LABEL') blab.style.display = 'none';
    [].slice.call(form.querySelectorAll('.ntm-paste-hint,.ntm-tip,[class*="hint"],[class*="tip"]')).forEach(function (t) { t.style.display = 'none'; });

    // Buck 2026-06-08: mobile composer = quicker + simpler, NO categories. Hide the
    // category radiogroup + its label; the post just defaults to General (#3837) so
    // nobody has to pick. ensureForum still selects it (the radio exists, just hidden).
    forumSel.style.display = 'none';
    var forumLab = document.getElementById('ntm-forum-label'); if (forumLab) forumLab.style.display = 'none';
    function ensureForum() {
      if (forumSel.querySelector('input[name="forum_id"]:checked')) return;
      var def = forumSel.querySelector('input[name="forum_id"][value="3837"]') ||
                forumSel.querySelector('input[name="forum_id"]');
      if (def) def.checked = true;
    }
    ensureForum();

    // avatar + name header
    var nameBtn = document.querySelector('.lg-chrome__account');
    var name = nameBtn ? (nameBtn.textContent || '').replace(/\s+/g, ' ').trim().split(/\s{2,}|·/)[0] : 'You';
    var avi = myAvatarSrc();
    var head = document.createElement('div'); head.className = 'lg-fbc-head';
    head.innerHTML = '<span class="lg-fbc-avi">' + (avi ? '<img src="' + avi + '" alt="">' : '') + '</span>' +
      '<span class="lg-fbc-name"></span>';
    head.querySelector('.lg-fbc-name').textContent = name;
    if (editor && editor.parentNode) editor.parentNode.insertBefore(head, editor);
    if (body && 'placeholder' in body) body.placeholder = "What’s on your mind?";
    var ql = form.querySelector('.ql-editor'); if (ql) ql.setAttribute('data-placeholder', "What’s on your mind?");

    // Declutter: hide Quill's full format toolbar; give a clean two-button row —
    // Photo (drives Quill's existing image upload) + YouTube link (Buck 2026-06-08:
    // "just photos and option to add a youtube link"). The image button still works
    // hidden (we .click() it); a pasted/typed YouTube URL embeds on render.
    if (!document.getElementById('lg-fbc-add-css')) {
      var addSt = document.createElement('style'); addSt.id = 'lg-fbc-add-css';
      addSt.textContent =
        // hide Quill's format toolbar via CSS (race-proof — it mounts after fbStyle runs)
        '.lg-fbc .ql-toolbar{display:none!important}' +
        '.lg-fbc .lg-fbc-add{display:flex;gap:9px;margin:12px 0 2px}' +
        '.lg-fbc .lg-fbc-addbtn{display:inline-flex;align-items:center;gap:7px;border:1px solid var(--lg-line,#e3ddd0);' +
        'background:var(--lg-cream,#fbfbf8);color:var(--lg-ink,#323532);border-radius:999px;padding:9px 15px;cursor:pointer;' +
        'font:600 13.5px/1 var(--lg-font-sans,system-ui,-apple-system,"Segoe UI",sans-serif)}' +
        '.lg-fbc .lg-fbc-addbtn:active{background:var(--lg-sage-tint,#eef2e3)}' +
        '.lg-fbc .lg-fbc-addbtn svg{width:18px;height:18px;flex:0 0 auto}' +
        'html[data-lguser-theme="dark"] .lg-fbc .lg-fbc-addbtn{background:#222629;border-color:#333833;color:#e5e7e1}';
      document.head.appendChild(addSt);
    }
    if (editor && editor.parentNode && !form.querySelector('.lg-fbc-add')) {
      var addRow = document.createElement('div'); addRow.className = 'lg-fbc-add';
      addRow.innerHTML =
        '<button type="button" class="lg-fbc-addbtn" data-fbc-photo><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="14" rx="2"/><circle cx="8.5" cy="10" r="1.8"/><path d="M4 17l4.5-4.5 3 3L16 11l4 4"/></svg>Photo</button>' +
        '<button type="button" class="lg-fbc-addbtn" data-fbc-yt><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="6" width="18" height="12" rx="3"/><path d="M10 9.5l5 2.5-5 2.5z" fill="currentColor"/></svg>YouTube link</button>';
      editor.parentNode.insertBefore(addRow, editor.nextSibling);
      addRow.querySelector('[data-fbc-photo]').addEventListener('click', function () {
        var imgBtn = form.querySelector('.ql-toolbar .ql-image');
        if (imgBtn) imgBtn.click();                       // Quill's image upload (→ /media/upload)
      });
      addRow.querySelector('[data-fbc-yt]').addEventListener('click', function () {
        var url = window.prompt('Paste a YouTube link to embed:');
        if (!url) return;
        url = url.trim();
        if (!/^https?:\/\/(www\.)?(youtube\.com|youtu\.be)\//i.test(url)) { alert('That doesn’t look like a YouTube link.'); return; }
        var qe = form.querySelector('.ql-editor'); if (!qe) return;
        qe.focus();
        // Insert the bare URL on its own line — it embeds on render (bbProcessEmbeds).
        try { document.execCommand('insertText', false, '\n' + url + '\n'); }
        catch (e) { qe.appendChild(document.createTextNode(' ' + url + ' ')); }
        qe.dispatchEvent(new Event('input', { bubbles: true }));
      });
    }

    function bodyText() {
      if (body && 'value' in body && body.value) return body.value;
      var q = form.querySelector('.ql-editor');
      return q ? (q.textContent || '').trim() : '';
    }
    // Right before the canonical submit: auto title + ensure a forum is chosen.
    submit.addEventListener('click', function () {
      if (titleIn && !titleIn.value.trim()) {
        var b = bodyText();
        titleIn.value = ((b ? b.split(/\n/)[0].slice(0, 80) : '') || '').trim() || 'New post';
      }
      ensureForum();
    }, true);
    // MOBILE: after a successful post the canonical composer redirects to the new
    // TOPIC page (a single-post / desktop-style view) after 600ms. Buck wants to
    // stay on the Hub. Watch the status; the instant it flips to "Posted/Redirect",
    // go to the Hub feed — this fires before the canonical's setTimeout, so we win
    // the race and the user lands back on the feed with their new post present.
    var ntmStatusEl = document.getElementById('ntm-status');
    if (ntmStatusEl && window.MutationObserver && !ntmStatusEl.getAttribute('data-lg-postnav')) {
      ntmStatusEl.setAttribute('data-lg-postnav', '1');
      new MutationObserver(function () {
        if (/posted|redirect/i.test(ntmStatusEl.textContent || '')) {
          try { window.location.href = '/hub/'; } catch (e) {}
        }
      }).observe(ntmStatusEl, { childList: true, characterData: true, subtree: true });
    }
  }

  // Mobile only: turn the shared header into a single live-search bubble.
  // Inserts a pill input into .lg-chrome__inner (the canonical hamburger/logo/
  // aside are hidden by CSS), and a results panel into <body> that live-queries
  // the suggest endpoint as you type. Hub mode returns {kind,title,url} (the
  // linkable set); author mode exists but yields no URLs, so it's not wired.
  // Debounced + sequence-guarded so out-of-order responses can't clobber a
  // newer query. Idempotent; guarded so a failure can't break the header.
  var SEARCH_ICO =
    '<svg class="lg-hub-search__ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" ' +
    'stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' +
    '<circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>';
  function buildTopSearch() {
    try {
      if (!window.matchMedia('(max-width:640px)').matches) return;
      var inner = document.querySelector('#site-header .lg-chrome__inner');
      if (!inner || inner.querySelector('.lg-hub-search')) return;

      var wrap = document.createElement('div');
      wrap.className = 'lg-hub-search';
      wrap.innerHTML = SEARCH_ICO +
        '<input type="search" enterkeyhint="search" autocomplete="off" ' +
        'aria-label="Search the Hub" placeholder="Search the Hub">';
      inner.appendChild(wrap);
      var input = wrap.querySelector('input');

      var panel = document.createElement('div');
      panel.className = 'lg-hub-search__panel';
      panel.setAttribute('role', 'listbox');
      document.body.appendChild(panel);

      function position() {
        var r = wrap.getBoundingClientRect();
        panel.style.top = Math.round(r.bottom + 8) + 'px';
      }
      function open() { position(); panel.classList.add('is-open'); }
      function close() { panel.classList.remove('is-open'); }
      function note(msg) {
        panel.innerHTML = '';
        var n = document.createElement('div');
        n.className = 'lg-hub-search__note';
        n.textContent = msg;
        panel.appendChild(n);
        open();
      }

      var timer = null, seq = 0;
      function render(results, q) {
        if (q !== input.value.trim()) return;     // stale: input moved on
        if (!results.length) { note('No matches for “' + q + '”'); return; }
        panel.innerHTML = '';
        results.slice(0, 12).forEach(function (r) {
          if (!r || !r.url) return;
          var a = document.createElement('a');
          a.className = 'lg-hub-search__item';
          a.href = r.url;
          a.setAttribute('data-url', r.url);
          a.setAttribute('data-kind', r.kind || 'page');
          a.setAttribute('data-title', r.title || '');
          var k = document.createElement('span');
          k.className = 'lg-hub-search__k';
          k.textContent = (r.kind || 'result').replace(/[_-]/g, ' ');
          var t = document.createElement('span');
          t.className = 'lg-hub-search__t';
          t.textContent = r.title || r.url;
          a.appendChild(k); a.appendChild(t);
          panel.appendChild(a);
        });
        open();
      }
      function query(q) {
        var mine = ++seq;
        fetch('/hub/?suggest=hub&q=' + encodeURIComponent(q), { credentials: 'same-origin' })
          .then(function (r) { return r.json(); })
          .then(function (d) { if (mine === seq) render((d && d.results) || [], q); })
          .catch(function () { if (mine === seq) close(); });
      }

      input.addEventListener('input', function () {
        var q = input.value.trim();
        clearTimeout(timer);
        if (q.length < 2) { seq++; close(); return; }
        timer = setTimeout(function () { query(q); }, 180);
      });
      input.addEventListener('focus', function () {
        if (input.value.trim().length >= 2 && panel.childNodes.length) open();
      });
      input.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') { input.value = ''; close(); input.blur(); }
      });
      // Close when tapping outside the bubble + panel.
      document.addEventListener('pointerdown', function (e) {
        if (!wrap.contains(e.target) && !panel.contains(e.target)) close();
      }, true);
      window.addEventListener('resize', function () {
        if (panel.classList.contains('is-open')) position();
      });
    } catch (e) { /* never let search break the header */ }
  }

  // Mobile only: hide the sticky header on scroll-down, reveal it on the
  // slightest scroll-up (Instagram/Twitter-style). The header is already
  // position:sticky;top:0 site-wide; we just toggle a translateY tuck class.
  // rAF-throttled; asymmetric thresholds so a tiny upward flick pops it back
  // while hiding needs a more deliberate downward scroll. Never tucks near the
  // very top, while the search input is focused, or while the results panel is
  // open. Idempotent.
  function wireHeaderAutoHide() {
    if (!window.matchMedia('(max-width:640px)').matches) return;
    var hdr = document.getElementById('site-header');
    if (!hdr || hdr.getAttribute('data-lg-autohide')) return;
    hdr.setAttribute('data-lg-autohide', '1');
    var lastY = window.pageYOffset || 0, ticking = false;
    function update() {
      ticking = false;
      var y = window.pageYOffset || 0;
      var dy = y - lastY;
      lastY = y;
      var ae = document.activeElement;
      var searching = ae && ae.closest && ae.closest('.lg-hub-search');
      var panelOpen = document.querySelector('.lg-hub-search__panel.is-open');
      if (y < 80 || searching || panelOpen) { hdr.classList.remove('lg-chrome--tuck'); return; }
      if (dy < -2) hdr.classList.remove('lg-chrome--tuck');   // scrolling up -> show
      else if (dy > 8) hdr.classList.add('lg-chrome--tuck');  // scrolling down -> hide
    }
    window.addEventListener('scroll', function () {
      if (!ticking) { ticking = true; requestAnimationFrame(update); }
    }, { passive: true });
  }

  // ── Fast filters ──────────────────────────────────────────────────────────
  // The canonical type/category mute switches are <a href="/hub/?mute_toggle=…">
  // anchors, so every tap was a full page reload — slow, the drawer slammed
  // shut, and flipping several quickly was impossible. We intercept them: flip
  // the switch instantly, hide/show the loaded cards we can map client-side
  // (types via data-kind / .feed-card--topic, categories via data-cat; sub-forum
  // l: tokens reconcile on refresh), keep the drawer open, and after the user
  // stops toggling (debounced) replay the toggles to the server and soft-swap
  // the feed from its authoritative HTML. Any failure falls back to one reload.
  function tokenMatcher(token) {
    var i = token.indexOf(':'); if (i < 0) return null;
    var scope = token.slice(0, i), val = token.slice(i + 1);
    if (scope === 't') {
      if (val === 'discussions') return function (c) { return c.classList.contains('feed-card--topic'); };
      return function (c) { return c.getAttribute('data-kind') === val; };
    }
    if (scope === 'c') return function (c) { return c.getAttribute('data-cat') === val; };
    return null; // l: sub-forum — no card attribute; handled by the refresh
  }
  function applyClientFilter(feed) {
    var muted = [];
    [].slice.call(document.querySelectorAll('.hub-sw')).forEach(function (sw) {
      if (sw.classList.contains('is-on')) return;        // on = visible
      var href = sw.getAttribute('href') || '';
      var k = href.indexOf('mute_toggle=');
      if (k < 0) return;
      var token = decodeURIComponent(href.slice(k + 12).split('&')[0]);
      var fn = tokenMatcher(token);
      if (fn) muted.push(fn);
    });
    [].slice.call(feed.querySelectorAll('.feed-card')).forEach(function (c) {
      c.style.display = muted.some(function (fn) { return fn(c); }) ? 'none' : '';
    });
  }
  function wireFastFilters() {
    if (!window.matchMedia('(max-width:640px)').matches) return; // mobile only — desktop keeps its native behavior
    var feed = document.querySelector('.feed');
    if (!feed || document.body.getAttribute('data-lg-fastfilters')) return;
    document.body.setAttribute('data-lg-fastfilters', '1');

    if (!document.getElementById('lg-fastfilters-css')) {
      var st = document.createElement('style'); st.id = 'lg-fastfilters-css';
      st.textContent = '.feed.lg-feed-syncing{opacity:.55;transition:opacity .15s}' +
        '.hub-sw{transition:background-color .12s,box-shadow .12s}';
      document.head.appendChild(st);
    }

    var queue = [];        // one server-toggle URL per click (replays exactly)
    var pending = false, syncing = false, timer = null;

    function schedule() { pending = true; clearTimeout(timer); timer = setTimeout(sync, 900); }
    function sync() {
      if (!pending || syncing) return;
      syncing = true; pending = false;
      feed.classList.add('lg-feed-syncing');
      var jobs = queue.splice(0), chain = Promise.resolve();
      jobs.forEach(function (url) {
        chain = chain.then(function () {
          return fetch(url, { credentials: 'same-origin' }).then(function () {}, function () {});
        });
      });
      chain.then(function () {
        return fetch(location.href, { credentials: 'same-origin', cache: 'no-store' }).then(function (r) { return r.text(); });
      }).then(function (html) {
        var doc = new DOMParser().parseFromString(html, 'text/html');
        var fresh = doc.querySelector('.feed');
        if (!fresh) throw new Error('no feed in response');
        feed.innerHTML = fresh.innerHTML;     // cardObserver + relayCards re-wire
        relayCards(document);
        observePreviewCards(document);
        applyClientFilter(feed);
        applyFreshFeed(feed);                 // keep the filtered set freshly shuffled
        feed.classList.remove('lg-feed-syncing');
        syncing = false;
        if (pending) schedule();              // toggles arrived mid-sync
      }).catch(function () {
        try { sessionStorage.setItem('lg-filters-keepopen', '1'); } catch (e) {}
        location.reload();
      });
    }

    document.addEventListener('click', function (ev) {
      var sw = ev.target.closest && ev.target.closest('.hub-sw');
      if (!sw) return;
      ev.preventDefault(); ev.stopPropagation();
      sw.classList.toggle('is-on');             // instant visual flip
      var href = sw.getAttribute('href');
      if (href) queue.push(href);
      applyClientFilter(feed);                   // instant feed response
      schedule();
    }, true);

    // Keep newly infinite-scrolled cards consistent with active mutes.
    if ('MutationObserver' in window) {
      new MutationObserver(function (muts) {
        for (var i = 0; i < muts.length; i++) {
          if (muts[i].addedNodes && muts[i].addedNodes.length) { applyClientFilter(feed); return; }
        }
      }).observe(feed, { childList: true });
    }
  }
  // After the hard-reload fallback, re-open the filters drawer so the user lands
  // back where they were instead of a closed drawer.
  function reopenFiltersIfFlagged() {
    try {
      if (sessionStorage.getItem('lg-filters-keepopen') !== '1') return;
      sessionStorage.removeItem('lg-filters-keepopen');
    } catch (e) { return; }
    document.body.classList.add('nav-open');
    var ham = document.getElementById('bb-ham');
    if (ham) ham.setAttribute('aria-expanded', 'true');
    var ov = document.getElementById('bb-overlay');
    if (ov) ov.setAttribute('aria-hidden', 'false');
  }

  // ── Fresh Feed (RETIRED 2026-06-07) ─────────────────────────────────────────
  // The Hub now has a REAL server-side "Random" sort (front-door default: a
  // popularity-weighted seeded shuffle over the WHOLE DB — surfaces old/popular
  // posts and stays coherent across infinite scroll). That supersedes this old
  // client-side shuffle, which only reordered the already-loaded cards AND injected
  // a DUPLICATE "Fresh" pill next to the server's "Random" tab (Buck: "remove the
  // fresh bubble so it's just random"). Both are now no-ops — the server order is
  // authoritative. Stubs kept so existing call sites stay valid.
  function freshActive() { return false; }
  function applyFreshFeed() { /* server owns Random now — no client reshuffle */ }

  // Hide filter rows whose count is 0 (e.g. empty "Local Looths") so the drawer
  // only lists categories that actually have content. Mobile only. Idempotent.
  function hideEmptyFilterRows() {
    // No-op: empty rows now carry .hub-rail__row--empty server-side and are
    // hidden by a mobile-only CSS rule in forums.css (no DOM touch, no flicker).
  }

  // Fresh pill RETIRED 2026-06-07: the server now renders the real "Random" tab
  // (front-door default), so injecting a client-side "Fresh" pill just duplicated
  // it (Buck: "remove the fresh bubble so it's just random"). No-op now.
  function wireFreshPill() { /* server renders the Random tab; no client pill */ }

  // Keep mobile Hub taps ON the hub (Buck 2026-06-07: "keep it all on the hub, no
  // clicking into the page; only loothprints click through"). Discussions already
  // expand inline (forums.js). CONTENT/CPT cards normally navigate to their own page;
  // on mobile we intercept the post-navigation targets (title link + cover) and open
  // the card's inline comments thread (a modal over the feed) instead — so you stay on
  // the Hub. EXCEPTIONS that still behave normally: data-kind="loothprint" (the only
  // click-through), gated teasers (→ their paywall page), videos (play inline), and any
  // real control / author link. INTERIM: full inline article/video BODY render is a
  // server change in flight (coordinator); until then tapping opens the comments thread.
  // ── In-app content sheet (Buck 2026-06-08) ──────────────────────────────────
  // Tapping an article/video/imgcap/sponsor/event content card on mobile opens a
  // full-screen sheet showing the REAL post — its article body AND comments —
  // rendered chrome-free by the server's ?embed=1 mode (body.lg-embed, no site
  // header/footer; commit 0c35ad8). Inside the iframe, /pwa.js self-suppresses
  // (window.top !== window.self) so no nested tab bar / app-shell leaks in. A
  // history entry is pushed so the phone back-gesture closes the sheet (like the
  // image lightbox) instead of navigating the PWA away. Mobile only.
  var lgCs = null, lgCsHist = false, lgCsScroll = '';

  // ── Quick-view theming + Editorial polish (Buck 2026-06-09) — DESKTOP ONLY ──
  // The DESKTOP quick-view (#lg-qv) frames the real post via ?embed=1, but /pwa.js
  // self-suppresses inside frames (window.top!==self) so app-settings.js never runs
  // there — the framed post would always paint the DEFAULT light theme, even for a
  // Sage/Amber/Rust/custom/DARK user. (1) lgMirrorTheme copies the parent's active
  // theme — the inline --lg* vars + data-lguser-* attributes app-settings set on
  // <html> — into the frame (same-origin, so we can write its contentDocument) so the
  // quick-view follows the viewer's theme. (2) lgPostPolish injects a DARK-mode pass
  // the embed CSS doesn't cover: several post surfaces (meta strip, wysiwyg body,
  // callouts, author card, comments, image figures, related carousel) hardcode a
  // cream fill and ignore the --lg* tokens, so in dark mode the body stayed light
  // with washed-out text; we recolor them to the dark card token. Injected into the
  // FRAME only, so direct ?embed=1 loads and full post pages are untouched. These run
  // ONLY from the desktop quick-view load handler — the MOBILE content sheet is left
  // exactly as it was (Buck: the pop-up change is desktop-only; mobile was already good).
  function lgMirrorTheme(doc) {
    if (!doc || !doc.documentElement) return;
    try {
      var src = document.documentElement, s = src.style, dst = doc.documentElement;
      for (var i = 0; i < s.length; i++) {
        var p = s[i];
        if (p.lastIndexOf('--lg', 0) === 0) dst.style.setProperty(p, s.getPropertyValue(p));
      }
      ['data-lguser-theme', 'data-lguser-dark', 'data-lguser-feed'].forEach(function (a) {
        var v = src.getAttribute(a); if (v != null) dst.setAttribute(a, v);
      });
    } catch (e) {}
  }
  function lgPostPolish(doc) {
    if (!doc || !doc.documentElement || doc.getElementById('lg-post-polish')) return;
    try {
      var st = doc.createElement('style'); st.id = 'lg-post-polish';
      st.textContent =
        // (1) DARK MODE — recolor the surfaces that hardcode a light cream fill so
        // the post body is readable. Text already follows --lg-ink/--lg-charcoal.
        'html[data-lguser-dark="1"] body,html[data-lguser-dark="1"] .lg-standalone-main{background:#15171a!important}' +
        'html[data-lguser-dark="1"] .lg-post-header__meta-strip,' +
        'html[data-lguser-dark="1"] .lg-callout--links,' +
        'html[data-lguser-dark="1"] .lg-callout--note,' +
        'html[data-lguser-dark="1"] .lg-post-footer__author,' +
        'html[data-lguser-dark="1"] .lg-standalone-comments,' +
        'html[data-lguser-dark="1"] .lg-cmodal__panel,' +
        'html[data-lguser-dark="1"] .lg-cmodal__head{background:#1e2124!important;border-color:#2c312d!important;color:#e5e7e1!important}' +
        // wysiwyg body: dark fill but KEEP its amber accent border.
        'html[data-lguser-dark="1"] .lg-wysiwyg{background:#1e2124!important;color:#e5e7e1!important}' +
        'html[data-lguser-dark="1"] .lg-wysiwyg p,html[data-lguser-dark="1"] .lg-callout--note p,' +
        'html[data-lguser-dark="1"] .lg-post-footer__author *{color:#e5e7e1!important}' +
        'html[data-lguser-dark="1"] .lg-cmodal__backdrop{background:rgba(0,0,0,.6)!important}' +
        // image figures + the related-posts carousel cards also hardcode a light fill.
        'html[data-lguser-dark="1"] figure.lg-image,html[data-lguser-dark="1"] .lg-image__frame,' +
        'html[data-lguser-dark="1"] .lg-post-footer__card,html[data-lguser-dark="1"] .lg-post-footer__carousel-btn,' +
        'html[data-lguser-dark="1"] .lg-post-footer__card *{background:#1e2124!important;border-color:#2c312d!important;color:#e5e7e1!important}' +
        // DISCUSSION topics: the forum post/reply cards (.post) hardcode white and the
        // embed's own dark theme doesn't cover them. Fix here too so it's consistent
        // across both pop-ups (Buck: "fix across the board").
        'html[data-lguser-dark="1"] .post,html[data-lguser-dark="1"] .bbp-reply,html[data-lguser-dark="1"] .bbp-topic{background:#1e2124!important;border-color:#2c312d!important;color:#e5e7e1!important}' +
        'html[data-lguser-dark="1"] .search-form__input{background:#1e2124!important;color:#e5e7e1!important;border-color:#2c312d!important}' +
        // POLISH forum post/reply cards — brand card shape (drop the 4px left strip + 6px radius).
        '.post,.bbp-reply,.bbp-topic{border-width:1px!important;border-style:solid!important;' +
        'border-radius:16px!important;box-shadow:0 1px 4px rgba(0,0,0,.05)!important;padding:16px!important;margin-bottom:14px!important}' +
        'html:not([data-lguser-dark="1"]) .post,html:not([data-lguser-dark="1"]) .bbp-reply,html:not([data-lguser-dark="1"]) .bbp-topic{border-color:var(--lg-line,#e3ddd0)!important}' +
        // CLEANUP legacy forum chrome (Buck 2026-06-09) — match the mobile sheet.
        '.corner-hamburger{display:none!important}' +
        '.forum-header,.forum-header--post{background:none!important;background-image:none!important;border:0!important;box-shadow:none!important;border-radius:0!important;padding:4px 2px 8px!important;overflow:visible!important}' +
        '.forum-header__label{display:none!important}' +
        '.forum-header__title,.forum-header__title--link{font-family:var(--lg-font-serif,Georgia,serif)!important;font-size:22px!important;line-height:1.2!important;color:var(--lg-charcoal,#1a1d1a)!important}' +
        '.forum-header__home,.forum-header__parent{color:var(--lg-sage-d,#6b7c52)!important;font-size:12px!important;font-weight:600!important}' +
        '.post__author{font-family:var(--lg-font-serif,Georgia,serif)!important}' +
        'html[data-lguser-dark="1"] .post__author{color:#e5e7e1!important}' +
        '.ntm-quicktags,.quicktags-toolbar,.wp-editor-tools,.bbp-the-content-wrapper .wp-editor-tabs{display:none!important}' +
        // REPLIES as Facebook-style comments (Buck 2026-06-09) — match the mobile sheet.
        '.post:not(.post--op){display:grid!important;grid-template-columns:34px 1fr!important;column-gap:8px!important;align-items:start!important;background:none!important;border:0!important;box-shadow:none!important;border-radius:0!important;padding:5px 0!important;margin:0 0 2px!important}' +
        'html[data-lguser-dark="1"] .post:not(.post--op){background:none!important}' +
        '.post:not(.post--op) .post__avatar-wrap{width:34px!important;height:34px!important;margin:0!important}' +
        '.post:not(.post--op) .post__avatar-wrap img{border-radius:50%!important;width:100%!important;height:100%!important;object-fit:cover!important}' +
        '.post:not(.post--op) .post__content{display:block!important;min-width:0!important;padding:0!important}' +
        '.post:not(.post--op) .post__head{display:flex!important;align-items:baseline!important;flex-wrap:wrap!important;gap:7px!important;background:var(--lguser-bubble,var(--lg-sage-tint,#eef2e3))!important;border-radius:16px 16px 0 0!important;padding:8px 13px 2px!important;margin:0!important}' +
        '.post:not(.post--op) .post__author{font:700 13.5px/1.3 var(--lg-font-serif,Georgia,serif)!important;color:var(--lg-charcoal,#1a1d1a)!important}' +
        '.post:not(.post--op) .post__time{font:500 11.5px/1.2 var(--lg-font-sans,system-ui,sans-serif)!important;color:var(--lg-mute,#6b6f6b)!important}' +
        '.post:not(.post--op) .post__body{background:var(--lguser-bubble,var(--lg-sage-tint,#eef2e3))!important;border-radius:0 0 16px 16px!important;padding:2px 13px 9px!important;margin:0!important;color:var(--lg-ink,#1a1d1a)!important}' +
        '.post:not(.post--op) .post__body p{margin:0 0 6px!important;font-size:14.5px!important;line-height:1.45!important}' +
        '.post:not(.post--op) .post__body p:last-child{margin-bottom:0!important}' +
        '.post:not(.post--op) .post__actions{display:flex!important;gap:16px!important;margin:5px 0 0 13px!important;padding:0!important;background:none!important;border:0!important}' +
        '.post:not(.post--op) .post__actions button{font:700 12.5px/1 var(--lg-font-sans,system-ui,sans-serif)!important;color:var(--lg-mute,#6b6f6b)!important;background:none!important;border:0!important;padding:0!important}';
      (doc.head || doc.documentElement).appendChild(st);
    } catch (e) {}
  }

  function lgCsEnsure() {
    if (lgCs) return;
    if (!document.getElementById('lg-cs-css')) {
      var st = document.createElement('style'); st.id = 'lg-cs-css';
      st.textContent =
        // Pull-up bottom sheet (Buck 2026-06-09: "a popup like a pull-up menu"). A dimmed
        // backdrop + a panel that slides up from the bottom with a rounded top + drag handle.
        // Tap backdrop / drag down / ✕ to dismiss. .is-open shows it; .is-up runs the slide.
        '#looth-content-sheet{position:fixed;inset:0;z-index:2147483550;display:none}' +
        '#looth-content-sheet.is-open{display:block}' +
        '#looth-content-sheet .lcs-backdrop{position:absolute;inset:0;background:rgba(15,16,12,.5);opacity:0;transition:opacity .26s ease}' +
        '#looth-content-sheet.is-up .lcs-backdrop{opacity:1}' +
        '#looth-content-sheet .lcs-panel{position:absolute;left:0;right:0;bottom:0;top:max(6vh,env(safe-area-inset-top,0px));' +
        'display:flex;flex-direction:column;background:var(--lg-cream,#fbfbf8);border-radius:18px 18px 0 0;overflow:hidden;' +
        'box-shadow:0 -10px 36px rgba(0,0,0,.28);transform:translateY(100%);transition:transform .3s cubic-bezier(.32,.72,0,1);will-change:transform}' +
        '#looth-content-sheet.is-up .lcs-panel{transform:translateY(0)}' +
        '#looth-content-sheet .lcs-grab{flex:0 0 auto;height:22px;display:flex;align-items:center;justify-content:center;' +
        'touch-action:none;cursor:grab}' +
        '#looth-content-sheet .lcs-grab::before{content:"";width:40px;height:5px;border-radius:3px;background:var(--lg-line,#d8d2c4)}' +
        '#looth-content-sheet .lcs-bar{flex:0 0 auto;height:42px;display:flex;align-items:center;gap:10px;padding:0 8px 0 12px;' +
        'border-bottom:1px solid var(--lg-line,#e3ddd0);background:var(--lg-cream,#fbfbf8);touch-action:none}' +
        '#looth-content-sheet .lcs-x{width:34px;height:34px;border:0;border-radius:50%;background:var(--lg-sage-tint,#eef2e3);' +
        'color:var(--lg-sage-d,#6b7c52);font-size:19px;line-height:34px;text-align:center;cursor:pointer;flex:0 0 auto}' +
        '#looth-content-sheet .lcs-ttl{flex:1 1 auto;min-width:0;font:700 14px/1.2 var(--lg-font-serif,Georgia,serif);' +
        'color:var(--lg-charcoal,#1a1d1a);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}' +
        '#looth-content-sheet .lcs-frame{flex:1 1 auto;width:100%;border:0;display:block;background:var(--lg-cream,#fbfbf8)}' +
        'html[data-lguser-theme="dark"] #looth-content-sheet .lcs-panel,html[data-lguser-theme="dark"] #looth-content-sheet .lcs-bar,' +
        'html[data-lguser-theme="dark"] #looth-content-sheet .lcs-frame{background:#16181a}' +
        'html[data-lguser-theme="dark"] #looth-content-sheet .lcs-ttl{color:#f2f4ee}' +
        'html[data-lguser-theme="dark"] #looth-content-sheet .lcs-grab::before{background:#3a403a}';
      (document.head || document.documentElement).appendChild(st);
    }
    lgCs = document.createElement('div');
    lgCs.id = 'looth-content-sheet'; lgCs.setAttribute('role', 'dialog'); lgCs.setAttribute('aria-modal', 'true'); lgCs.setAttribute('aria-label', 'Post');
    lgCs.innerHTML =
      '<div class="lcs-backdrop"></div>' +
      '<div class="lcs-panel">' +
        '<div class="lcs-grab" aria-hidden="true"></div>' +
        '<div class="lcs-bar"><button class="lcs-x" type="button" aria-label="Close">✕</button><span class="lcs-ttl"></span></div>' +
        '<iframe class="lcs-frame" title="Post" referrerpolicy="same-origin" allow="fullscreen; clipboard-write; web-share"></iframe>' +
      '</div>';
    (document.body || document.documentElement).appendChild(lgCs);
    lgCs.querySelector('.lcs-x').addEventListener('click', function () { closeContentSheet(); });
    lgCs.querySelector('.lcs-backdrop').addEventListener('click', function () { closeContentSheet(); });
    // Shared panel-drag helpers (used by the grab/header drag AND the in-iframe
    // overscroll-pull-down wired in the frame load handler below).
    var lcsPanel = lgCs.querySelector('.lcs-panel');
    function csDragTo(dy) { lcsPanel.style.transition = 'none'; lcsPanel.style.transform = 'translateY(' + Math.max(0, dy) + 'px)'; }
    function csDragReset() { lcsPanel.style.transition = ''; lcsPanel.style.transform = ''; }
    function csDragEnd(dy) { csDragReset(); if (dy > 110) closeContentSheet(); }
    // Drag the handle/header down to dismiss (snaps back if not dragged far enough).
    (function () {
      var startY = 0, dy = 0, dragging = false;
      function start(e) { startY = (e.touches ? e.touches[0].clientY : e.clientY); dy = 0; dragging = true; }
      function move(e) {
        if (!dragging) return;
        dy = Math.max(0, (e.touches ? e.touches[0].clientY : e.clientY) - startY);
        csDragTo(dy);
        if (dy > 2 && e.cancelable) e.preventDefault();
      }
      function end() { if (!dragging) return; dragging = false; csDragEnd(dy); }
      ['.lcs-grab', '.lcs-bar'].forEach(function (sel) {
        var el = lgCs.querySelector(sel); if (!el) return;
        el.addEventListener('touchstart', start, { passive: true });
        el.addEventListener('touchmove', move, { passive: false });
        el.addEventListener('touchend', end);
        el.addEventListener('mousedown', start);
      });
      document.addEventListener('mousemove', move);
      document.addEventListener('mouseup', end);
    })();
    // Hide the site chrome inside the framed post. CONTENT posts are already
    // chrome-free (body.lg-embed), but DISCUSSION topics (?embed=1) are NOT — without
    // this their site header / nav / footer would show inside the sheet. Same hide-set
    // the desktop quick-view uses. (No theme work here — the embed page themes itself.)
    var _lcsf = lgCs.querySelector('.lcs-frame');
    if (_lcsf) _lcsf.addEventListener('load', function () {
      try {
        var d = _lcsf.contentDocument;
        if (d && !d.getElementById('lg-cs-embed-css')) {
          var st = d.createElement('style'); st.id = 'lg-cs-embed-css';
          st.textContent = '#site-header,.lg-chrome,#site-footer,footer,#looth-tabbar,#looth-pwa-banner,.bb-layout__nav,.nav-tree,.bb-mirror__searchbar--sidebar,.lg-set-gear,#lg-dset-pop{display:none!important}' +
            'body{padding-top:0!important}.bb-layout__content,.bb-layout__content .page{max-width:none!important}' +
            // DARK: discussion post/reply cards (.post) hardcode white and the embed's
            // own dark theme doesn't cover them — recolor so they aren't blinding.
            'html[data-lguser-dark="1"] .post,html[data-lguser-dark="1"] .bbp-reply,html[data-lguser-dark="1"] .bbp-topic{background:#1e2124!important;border-color:#2c312d!important;color:#e5e7e1!important}' +
            'html[data-lguser-dark="1"] .search-form__input{background:#1e2124!important;color:#e5e7e1!important;border-color:#2c312d!important}' +
            // POLISH the forum post/reply cards (Buck 2026-06-09: discussion looked legacy).
            // Drop the thick 4px left-accent strip + 6px radius for the brand card shape.
            '.post,.bbp-reply,.bbp-topic{border-width:1px!important;border-style:solid!important;' +
            'border-radius:16px!important;box-shadow:0 1px 4px rgba(0,0,0,.05)!important;padding:16px!important;margin-bottom:14px!important}' +
            'html:not([data-lguser-dark="1"]) .post,html:not([data-lguser-dark="1"]) .bbp-reply,html:not([data-lguser-dark="1"]) .bbp-topic{border-color:var(--lg-line,#e3ddd0)!important}' +
            // CLEANUP legacy forum chrome (Buck 2026-06-09): drop the green corner
            // hamburger, flatten the gradient category box, and fix the author name
            // contrast (it hardcodes near-black → invisible on the dark card).
            '.corner-hamburger{display:none!important}' +
            '.forum-header,.forum-header--post{background:none!important;background-image:none!important;border:0!important;box-shadow:none!important;border-radius:0!important;padding:4px 2px 8px!important;overflow:visible!important}' +
            '.forum-header__label{display:none!important}' +
            '.forum-header__title,.forum-header__title--link{font-family:var(--lg-font-serif,Georgia,serif)!important;font-size:21px!important;line-height:1.2!important;color:var(--lg-charcoal,#1a1d1a)!important}' +
            '.forum-header__home,.forum-header__parent{color:var(--lg-sage-d,#6b7c52)!important;font-size:12px!important;font-weight:600!important}' +
            '.post__author{font-family:var(--lg-font-serif,Georgia,serif)!important}' +
            'html[data-lguser-dark="1"] .post__author{color:#e5e7e1!important}' +
            // Comment composer: hide the quicktags/format toolbar (same as the post composer) so
            // the "Write a comment" box reads as a clean single line above the keyboard (Buck 2026-06-09).
            '.ntm-quicktags,.quicktags-toolbar,.wp-editor-tools,.bbp-the-content-wrapper .wp-editor-tabs{display:none!important}' +
            // REPLIES as Facebook-style comments (Buck 2026-06-09): avatar + grey rounded
            // bubble (name + text), compact, actions below. The OP (.post--op) stays the card.
            '.post:not(.post--op){display:grid!important;grid-template-columns:34px 1fr!important;column-gap:8px!important;align-items:start!important;background:none!important;border:0!important;box-shadow:none!important;border-radius:0!important;padding:5px 0!important;margin:0 0 2px!important}' +
            'html[data-lguser-dark="1"] .post:not(.post--op){background:none!important}' +
            '.post:not(.post--op) .post__avatar-wrap{width:34px!important;height:34px!important;margin:0!important}' +
            '.post:not(.post--op) .post__avatar-wrap img{border-radius:50%!important;width:100%!important;height:100%!important;object-fit:cover!important}' +
            '.post:not(.post--op) .post__content{display:block!important;min-width:0!important;padding:0!important}' +
            '.post:not(.post--op) .post__head{display:flex!important;align-items:baseline!important;flex-wrap:wrap!important;gap:7px!important;background:var(--lguser-bubble,var(--lg-sage-tint,#eef2e3))!important;border-radius:16px 16px 0 0!important;padding:8px 13px 2px!important;margin:0!important}' +
            '.post:not(.post--op) .post__author{font:700 13px/1.3 var(--lg-font-serif,Georgia,serif)!important;color:var(--lg-charcoal,#1a1d1a)!important}' +
            '.post:not(.post--op) .post__time{font:500 11px/1.2 var(--lg-font-sans,system-ui,sans-serif)!important;color:var(--lg-mute,#6b6f6b)!important}' +
            '.post:not(.post--op) .post__body{background:var(--lguser-bubble,var(--lg-sage-tint,#eef2e3))!important;border-radius:0 0 16px 16px!important;padding:2px 13px 9px!important;margin:0!important;color:var(--lg-ink,#1a1d1a)!important}' +
            '.post:not(.post--op) .post__body p{margin:0 0 6px!important;font-size:14px!important;line-height:1.42!important}' +
            '.post:not(.post--op) .post__body p:last-child{margin-bottom:0!important}' +
            '.post:not(.post--op) .post__actions{display:flex!important;gap:16px!important;margin:5px 0 0 13px!important;padding:0!important;background:none!important;border:0!important}' +
            '.post:not(.post--op) .post__actions button{font:700 12px/1 var(--lg-font-sans,system-ui,sans-serif)!important;color:var(--lg-mute,#6b6f6b)!important;background:none!important;border:0!important;padding:0!important}';
          (d.head || d.documentElement).appendChild(st);
        }
        // Overscroll-to-dismiss (Buck 2026-06-09): when the post is scrolled to the
        // very top and you keep pulling DOWN, drag the sheet down (and close past a
        // threshold) instead of rubber-banding. The post lives in this same-origin
        // iframe, so we listen on its document and drive the parent panel directly.
        if (d && !d.__lgCsOverscroll) {
          d.__lgCsOverscroll = 1;
          var sTop = function () { var se = d.scrollingElement || d.documentElement || d.body; return se ? se.scrollTop : 0; };
          var sy = 0, pdy = 0, pulling = false;
          d.addEventListener('touchstart', function (e) {
            pulling = (sTop() <= 0); if (pulling) { sy = e.touches[0].clientY; pdy = 0; }
          }, { passive: true });
          d.addEventListener('touchmove', function (e) {
            if (!pulling) return;
            var dy = e.touches[0].clientY - sy;
            if (sTop() <= 0 && dy > 0) { pdy = dy; csDragTo(dy); if (e.cancelable) e.preventDefault(); }
            else { if (pdy > 0) csDragReset(); pulling = false; pdy = 0; }
          }, { passive: false });
          d.addEventListener('touchend', function () { if (pulling) { pulling = false; csDragEnd(pdy); pdy = 0; } }, { passive: true });
        }
      } catch (e) {}
    });
  }
  function lgCsEmbedUrl(href) {
    try {
      var u = new URL(href, location.href);
      if (u.origin !== location.origin) return null;          // never frame off-site
      u.searchParams.set('embed', '1');
      return u.pathname + u.search + u.hash;
    } catch (e) { return href + (href.indexOf('?') === -1 ? '?' : '&') + 'embed=1'; }
  }
  function openContentSheet(href) {
    if (!href) return;
    var url = lgCsEmbedUrl(href);
    if (!url) { location.href = href; return; }               // off-origin → just navigate
    lgCsEnsure();
    var frame = lgCs.querySelector('.lcs-frame');
    frame.src = url;
    lgCs.classList.add('is-open');                              // display:block (panel still translated down)
    // next frames: add .is-up so the panel slides up from translateY(100%) → 0.
    requestAnimationFrame(function () { requestAnimationFrame(function () { if (lgCs) lgCs.classList.add('is-up'); }); });
    lgCsScroll = document.body.style.overflow; document.body.style.overflow = 'hidden';
    if (!lgCsHist) { try { history.pushState({ lgCs: 1 }, ''); lgCsHist = true; } catch (e) {} }
  }
  function closeContentSheet(fromPop) {
    if (!lgCs || !lgCs.classList.contains('is-open')) return;
    lgCs.classList.remove('is-up');                            // slide the panel back down
    var frame = lgCs.querySelector('.lcs-frame');
    setTimeout(function () {                                   // after the slide-out, fully hide + free the iframe
      if (lgCs && !lgCs.classList.contains('is-up')) { lgCs.classList.remove('is-open'); if (frame) frame.removeAttribute('src'); }
    }, 320);
    document.body.style.overflow = lgCsScroll || '';
    if (lgCsHist && !fromPop) { lgCsHist = false; try { history.back(); } catch (e) {} }
    else { lgCsHist = false; }
  }
  window.addEventListener('popstate', function () { if (lgCs && lgCs.classList.contains('is-open')) closeContentSheet(true); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && lgCs && lgCs.classList.contains('is-open')) closeContentSheet(); });

  // ── When to pop up vs expand inline (Buck 2026-06-09) ───────────────────────
  // The pop-up is only for listings with a lot MORE than the card shows. Rule (the
  // "simple by type" path Buck picked, no fetch): DISCUSSIONS with replies → pop up
  // (the thread is the extra content); CONTENT with photos OR a long/clamped body →
  // pop up (so article photos land in the pop-up); short text that just overflows a
  // little → expand the card inline; everything already visible → do nothing.
  function lgShouldPopup(card) {
    if (!card) return false;
    if (card.classList.contains('feed-card--topic')) return replyCount(card) > 0;
    // content card: a real cover/inline photo, or a long body (native read-more or clamped excerpt)
    if (card.querySelector('.feed-card__cover-img, .feed-card__cover img, .feed-card__body img')) return true;
    if (card.querySelector('.feed-card__read-more:not(.lg-rm-syn)')) return true;
    var ex = card.querySelector('.feed-card__op-excerpt');
    if (ex && !ex.classList.contains('lg-unclamp') && ex.scrollHeight > ex.clientHeight + 8) return true;
    return false;
  }
  // Expand the card in place: trigger a read-more if present, else unclamp the excerpt.
  // Returns true if it actually expanded something (else the card already fully fits).
  function lgInlineExpand(card) {
    if (!card) return false;
    var rm = card.querySelector('.feed-card__read-more');
    if (rm) { rm.click(); return true; }
    var ex = card.querySelector('.feed-card__op-excerpt');
    if (ex && ex.scrollHeight > ex.clientHeight + 4) { ensureUnclampCss(); ex.classList.toggle('lg-unclamp'); return true; }
    return false;
  }

  // ── Polish the Facebook-style comments composer (Buck 2026-06-09) ───────────
  // The comments live in a same-origin iframe (/archive-api/v0/comments) the
  // forums.js modal opens. Its "Add a comment…" box looked unfinished; inject a
  // clean Messenger-style composer (rounded pill input + sage Post button, pinned
  // to the bottom) into that frame whenever it appears. The frame self-themes, and
  // we mirror the theme tokens too so dark resolves.
  function lgPolishCommentsFrame() {
    var CSS =
      '.lgc-compose{position:sticky!important;bottom:0!important;display:flex!important;align-items:center!important;gap:8px!important;' +
      'background:var(--lg-cream,#fbfbf8)!important;border-top:1px solid var(--lg-line,#e3ddd0)!important;' +
      'padding:10px 12px calc(10px + env(safe-area-inset-bottom,0px))!important;margin:0!important;z-index:5!important}' +
      '.lgc-replyto{order:-1!important;flex-basis:100%!important;margin:0 0 4px!important}.lgc-replyto:empty{display:none!important}' +
      '#lgc-textarea{flex:1 1 auto!important;min-width:0!important;width:auto!important;background:#fff!important;' +
      'border:1px solid #d8d2c4!important;border-radius:22px!important;padding:11px 15px!important;min-height:44px!important;max-height:120px!important;' +
      'font-size:15px!important;line-height:1.35!important;resize:none!important;color:#111!important;box-sizing:border-box!important}' +
      '#lgc-textarea::placeholder{color:#888!important}' +
      '.lgc-actions{flex:0 0 auto!important;display:flex!important;align-items:center!important;gap:6px!important;margin:0!important;padding:0!important}' +
      '.lgc-err{order:-1!important;flex-basis:100%!important;margin:0 0 4px!important;color:var(--lg-rust,#c66845)!important}.lgc-err:empty{display:none!important}' +
      '.lgc-submit{background:var(--lg-sage-d,#6b7c52)!important;color:#fff!important;border:0!important;border-radius:999px!important;' +
      'padding:0 18px!important;height:40px!important;font:700 13px/40px var(--lg-font-sans,system-ui,sans-serif)!important;white-space:nowrap!important}' +
      'html[data-lguser-dark="1"] .lgc-compose{background:#15171a!important;border-top-color:#2c312d!important}';
    function inject(f) {
      try {
        var d = f.contentDocument; if (!d || !d.documentElement) return;
        var src = document.documentElement, s = src.style, dst = d.documentElement;   // mirror theme so tokens resolve
        for (var i = 0; i < s.length; i++) { var p = s[i]; if (p.lastIndexOf('--lg', 0) === 0) dst.style.setProperty(p, s.getPropertyValue(p)); }
        var dk = src.getAttribute('data-lguser-dark'); if (dk != null) dst.setAttribute('data-lguser-dark', dk);
        if (d.getElementById('lg-cc-css')) return;
        var st = d.createElement('style'); st.id = 'lg-cc-css'; st.textContent = CSS;
        (d.head || d.documentElement).appendChild(st);
      } catch (e) {}
    }
    function hook(f) {
      if (!f || f.getAttribute('data-lg-cc')) return;
      f.setAttribute('data-lg-cc', '1');
      inject(f);
      f.addEventListener('load', function () { inject(f); });
    }
    function scan() {
      var list = document.querySelectorAll('iframe[src*="archive-api/v0/comments"], .lg-cmodal__frame');
      for (var i = 0; i < list.length; i++) hook(list[i]);
    }
    try {
      var pending = false;
      var mo = new MutationObserver(function () {            // debounced — the comments frame is added deep in the tree
        if (pending) return; pending = true;
        setTimeout(function () { pending = false; scan(); }, 150);
      });
      // attributeFilter src: the frame is added first, then forums.js sets its src
      // (an attribute change, not childList) — without this we'd scan too early and miss it.
      mo.observe(document.body || document.documentElement, { childList: true, subtree: true, attributes: true, attributeFilter: ['src'] });
    } catch (e) {}
    scan();
  }

  function keepContentOnHub() {
    if (document.body.getAttribute('data-lg-keephub')) return;
    document.body.setAttribute('data-lg-keephub', '1');
    document.addEventListener('click', function (e) {
      try {
        if (!window.matchMedia('(max-width:640px)').matches) return;

        // ── Discussion topics (Buck 2026-06-09: "pop up everything on mobile, match
        // desktop"). Previously a topic tap expanded inline; now tapping its title or
        // post body/excerpt opens the SAME full-screen sheet (iframe of the topic
        // ?embed=1, chrome hidden on load) the desktop quick-view uses. Reactions /
        // reply / author links / the expand caret still self-handle (inline).
        var topic = e.target.closest('.feed-card--topic');
        if (topic && !e.target.closest('.feed-card--content')) {
          if (e.target.closest('button, a[href*="/u/"], .lg-act, .lg-act-replies, .lg-card-actions, .fcr, .fcr-palette, [data-comments], .fc-cover--video, video, iframe, .feed-card__read-more, .feed-card__expand, .fc-readmore, .reply-stub, .fc-reply')) return;
          var tOnText = e.target.closest('.feed-card__title a, .fc-title a, .feed-card__title, .fc-title, .feed-card__op-excerpt, .fc-excerpt, .feed-card__op, .feed-card__full-body, .fc-full-body');
          if (!tOnText) return;
          e.preventDefault(); e.stopPropagation();              // beat forums.js inline expand
          // Tapping a discussion opens the SAME Facebook-style comments the "N replies"
          // button does (archive-api, via [data-comments]) — NOT the legacy forum-thread
          // sheet. One comments view only (Buck 2026-06-09). No replies → just expand the OP.
          if (lgShouldPopup(topic)) {
            var tdc = topic.querySelector('[data-comments], .feed-card__comments-btn');
            if (tdc) tdc.click(); else lgInlineExpand(topic);
          } else {
            lgInlineExpand(topic);
          }
          return;
        }

        var card = e.target.closest && e.target.closest('.feed-card--content');
        if (!card) return;

        // (A) The REPLY action opens the Facebook-style comments modal (.lg-cmodal,
        // archive-api/v0/comments) via the card's [data-comments] trigger — NOT the
        // forum-thread sheet. (Buck 2026-06-09: that modal IS the comments view he wants.)
        if (e.target.closest('.lg-act-replies')) {
          var cmr = card.querySelector('[data-comments], .feed-card__comments-btn');
          if (cmr) { e.preventDefault(); e.stopPropagation(); cmr.click(); }
          return;
        }

        // (B) RETIRED 2026-06-10 (Ian: "all cpts click through to post. No
        // modals for cpts — only discussions"). Content/loothprint cards no
        // longer open sheets: the server markup already carries real anchors
        // (cover + title + gated teaser), so taps navigate natively. The
        // discussion branch above keeps its thread modal; the explicit
        // Reply/Comment button keeps the comments modal (A).
      } catch (err) {}
    }, true);                                                 // capture: run before forums.js
  }

  // (DESKTOP quick-view modal RETIRED 2026-06-10 — Ian: "no modals for cpts,
  // only discussions". Title/cover anchors navigate to the post page.)

  // ── Loothprint inline sheet (Buck 2026-06-08) ───────────────────────────────
  // Tapping a loothprint stays on the Hub and opens a sheet with the print's cover/
  // title + a Download button and an Email/Share-the-file action. The download file
  // (wp-content/uploads/*.zip|stl|3mf…) lives on the loothprint page, so we fetch that
  // page same-origin, extract the file link(s), and present them. No navigation.
  function lpEsc(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }
  function lpInjectStyles() {
    if (document.getElementById('looth-lp-style')) return;
    var css = [
      '#looth-lp-sheet{position:fixed;inset:0;z-index:2147483500;display:none}',
      '#looth-lp-sheet.is-open{display:block}',
      '#looth-lp-sheet .llp-back{position:absolute;inset:0;background:rgba(26,29,26,.55)}',
      '#looth-lp-sheet .llp-card{position:absolute;left:10px;right:10px;bottom:10px;max-height:88vh;overflow:auto;-webkit-overflow-scrolling:touch;background:var(--lg-cream,#fbfbf8);border-radius:18px;padding:0 0 14px;box-shadow:0 -8px 30px rgba(26,29,26,.32);font:15px/1.45 system-ui,-apple-system,Segoe UI,Roboto,sans-serif;color:var(--lg-ink,#323532);animation:looth-pwa-up .26s ease}',
      '#looth-lp-sheet .llp-cover{width:100%;max-height:200px;object-fit:cover;display:block;border-radius:18px 18px 0 0;background:var(--lg-sage-tint,#eef2e3)}',
      '#looth-lp-sheet .llp-x{position:absolute;top:8px;right:10px;width:32px;height:32px;border:0;border-radius:50%;background:rgba(26,29,26,.5);color:#fff;font-size:20px;line-height:30px;cursor:pointer}',
      '#looth-lp-sheet .llp-body{padding:14px 16px 4px}',
      '#looth-lp-sheet .llp-kind{font:700 10px/1 var(--lg-font-sans,system-ui);letter-spacing:.08em;text-transform:uppercase;color:var(--lg-sage-d,#6b7c52)}',
      '#looth-lp-sheet .llp-t{font:700 18px/1.25 var(--lg-font-serif,Georgia,serif);color:var(--lg-charcoal,#1a1d1a);margin:5px 0 8px}',
      '#looth-lp-sheet .llp-ex{font-size:14px;color:var(--lg-ink,#323532)}',
      '#looth-lp-sheet .llp-desc{font-size:14.5px;line-height:1.55;color:var(--lg-ink,#323532);margin-top:8px}',
      '#looth-lp-sheet .llp-desc p{margin:0 0 9px}',
      '#looth-lp-sheet .llp-acts{padding:12px 16px 2px;display:flex;flex-direction:column;gap:9px}',
      '#looth-lp-sheet .llp-btn{display:flex;align-items:center;justify-content:center;gap:8px;width:100%;box-sizing:border-box;text-decoration:none;border:0;cursor:pointer;border-radius:12px;padding:13px;font:600 15px/1 var(--lg-font-sans,system-ui)}',
      '#looth-lp-sheet .llp-dl{background:var(--lg-sage,#87986a);color:#fff}',
      '#looth-lp-sheet .llp-dl:active{background:var(--lg-sage-d,#6b7c52)}',
      '#looth-lp-sheet .llp-email{background:var(--lg-sage-tint,#eef2e3);color:var(--lg-sage-d,#6b7c52)}',
      '#looth-lp-sheet .llp-btn svg{width:18px;height:18px;flex:0 0 auto}',
      '#looth-lp-sheet .llp-open{display:block;text-align:center;color:var(--lg-mute,#6b6f6b);font-weight:600;padding:8px;text-decoration:underline}',
      '#looth-lp-sheet .llp-note{padding:8px 16px;color:var(--lg-mute,#6b6f6b);font-size:13px}'
    ].join('');
    var s = document.createElement('style'); s.id = 'looth-lp-style'; s.textContent = css;
    (document.head || document.documentElement).appendChild(s);
  }
  // Share the loothprint file. MUST run synchronously inside the click gesture —
  // the old version fetched the blob first and THEN called navigator.share, but by
  // the time the async fetch resolved the transient user-activation had expired, so
  // share() silently rejected and the button "did nothing" (Buck 2026-06-08). Now the
  // blob is pre-fetched in the background (openLoothprintSheet) and passed in as a
  // ready File; we share it (or fall back to the link) without any awaiting.
  function lpShare(file, url, title) {
    try {
      if (file && navigator.canShare && navigator.canShare({ files: [file] })) {
        navigator.share({ files: [file], title: title, text: 'Loothprint: ' + title }).catch(function () {});
        return;
      }
      if (navigator.share) {
        navigator.share({ title: title, text: 'Loothprint: ' + title, url: url }).catch(function () {});
        return;
      }
      navigator.clipboard.writeText(url).then(
        function () { if (typeof lgToast === 'function') lgToast('Download link copied'); },
        function () {}
      );
    } catch (e) {
      try { navigator.clipboard.writeText(url); if (typeof lgToast === 'function') lgToast('Download link copied'); } catch (_) {}
    }
  }
  // Download a loothprint file reliably. The old plain <a href download> pointed
  // straight at an application/octet-stream URL — in the installed (standalone) PWA
  // that tried to NAVIGATE the app to the file (broke / did nothing), and iOS often
  // ignores the download attribute (Buck 2026-06-08: "download didn't work"). Fetch
  // the bytes into a blob and save via a blob URL — no app navigation. `<a download>`
  // doesn't need a user gesture, so the async fetch is fine.
  function lpDownload(url, name) {
    fetch(url, { credentials: 'same-origin' })
      .then(function (r) { return r.ok ? r.blob() : Promise.reject(); })
      .then(function (blob) {
        var u = URL.createObjectURL(blob);
        var a = document.createElement('a');
        a.href = u; a.download = name || 'file'; a.rel = 'noopener';
        document.body.appendChild(a); a.click();
        setTimeout(function () { a.remove(); URL.revokeObjectURL(u); }, 6000);
      })
      .catch(function () { try { window.open(url, '_blank'); } catch (e) { location.href = url; } });
  }
  // Page popup. For a loothprint CARD: openLoothprintSheet(card). For a search
  // result (any kind): openLoothprintSheet(null, {href,title,kind}) — Buck
  // 2026-06-08: search taps open a popup, not the desktop page; loothprints show
  // full details (description + Download/Email). Pulls the page's description so
  // it's "the entire details," and only loothprints get the file actions.
  function openLoothprintSheet(card, opts) {
    lpInjectStyles();
    opts = opts || {};
    var href = opts.href || (card && card.getAttribute('data-href')) || '';
    var title = opts.title || (card && (function () { var t = card.querySelector('.feed-card__title, .fc-title'); return t ? (t.textContent || '').trim() : ''; })()) || 'Details';
    var kind = (opts.kind || (card ? 'loothprint' : 'page') || '').toString().toLowerCase();
    var isLoothprint = /loothprint/.test(kind) || (!!card && card.getAttribute('data-kind') === 'loothprint');
    var coverImg = card && card.querySelector('.feed-card__cover-img, .fc-cover img, .feed-card__cover img');
    var cover = coverImg ? (coverImg.currentSrc || coverImg.getAttribute('src') || '') : (opts.cover || '');
    var exEl = card && card.querySelector('.feed-card__op-excerpt, .fc-excerpt');
    var excerpt = exEl ? (exEl.textContent || '').trim().slice(0, 180) : '';
    var kindLabel = isLoothprint ? 'Loothprint' : (kind.replace(/[_-]/g, ' ') || 'Details');

    var sheet = document.getElementById('looth-lp-sheet');
    if (!sheet) {
      sheet = document.createElement('div'); sheet.id = 'looth-lp-sheet'; sheet.setAttribute('role', 'dialog');
      sheet.setAttribute('aria-label', 'Details');
      (document.body || document.documentElement).appendChild(sheet);
      sheet.addEventListener('click', function (e) { if (e.target.closest('[data-llp-close]')) sheet.classList.remove('is-open'); });
    }
    sheet.innerHTML =
      '<div class="llp-back" data-llp-close></div>' +
      '<div class="llp-card">' +
        (cover ? '<img class="llp-cover" src="' + lpEsc(cover) + '" alt="">' : '') +
        '<button class="llp-x" type="button" aria-label="Close" data-llp-close>&times;</button>' +
        '<div class="llp-body"><div class="llp-kind">' + lpEsc(kindLabel) + '</div>' +
          '<div class="llp-t">' + lpEsc(title) + '</div>' +
          (excerpt ? '<div class="llp-ex">' + lpEsc(excerpt) + '</div>' : '') +
          '<div class="llp-desc" id="llp-desc"></div>' +
        '</div>' +
        '<div class="llp-acts" id="llp-acts">' + (isLoothprint ? '<div class="llp-note">Loading the print file…</div>' : '') + '</div>' +
      '</div>';
    sheet.classList.add('is-open');

    if (!href) return;
    fetch(href, { credentials: 'same-origin' })
      .then(function (r) { return r.ok ? r.text() : Promise.reject(); })
      .then(function (html) {
        var doc = new DOMParser().parseFromString(html, 'text/html');
        // Description: the page's main content → "entire details" in the popup.
        var descBox = sheet.querySelector('#llp-desc');
        var descEl = doc.querySelector('.entry-content, .bb-card-content, .lg-event-header__detail, .wp-block-post-content, article .content, main');
        if (descBox && descEl) {
          var ps = descEl.querySelectorAll('p'); var out = '';
          for (var pi = 0; pi < ps.length && pi < 12; pi++) { var pt = (ps[pi].textContent || '').trim(); if (pt) out += '<p>' + lpEsc(pt) + '</p>'; }
          descBox.innerHTML = out || ('<p>' + lpEsc((descEl.textContent || '').trim().slice(0, 800)) + '</p>');
        }
        // Cover fallback (og:image / first content image) when we had none from a card.
        if (!cover) {
          var og = doc.querySelector('meta[property="og:image"]');
          var cim = (og && og.getAttribute('content')) || (descEl && descEl.querySelector('img') && descEl.querySelector('img').getAttribute('src'));
          if (cim) { try { cim = new URL(cim, href).href; } catch (e) {} var cardEl = sheet.querySelector('.llp-card'); if (cardEl && !cardEl.querySelector('.llp-cover')) { var im = document.createElement('img'); im.className = 'llp-cover'; im.src = cim; cardEl.insertBefore(im, cardEl.firstChild.nextSibling); } }
        }
        var acts = sheet.querySelector('#llp-acts'); if (!acts) return;
        if (!isLoothprint) { acts.innerHTML = ''; return; }      // non-loothprint: details only, no file actions
        var DLRX = /\.(zip|stl|3mf|step|stp|f3d|gcode|rar|7z|obj)(\?|$)/i;
        var seen = {}, files = [];
        [].forEach.call(doc.querySelectorAll('a[href]'), function (a) {
          var u = a.getAttribute('href') || ''; if (!u) return;
          var abs; try { abs = new URL(u, href).href; } catch (e) { return; }
          if (!DLRX.test(abs) || seen[abs]) return; seen[abs] = 1;
          files.push({ url: abs, name: decodeURIComponent(abs.split('/').pop().split('?')[0]) });
        });
        // No "Open the full loothprint" link here — that full listing view is for
        // desktop only (Buck 2026-06-08). The mobile sheet is download + share.
        if (!files.length) { acts.innerHTML = '<div class="llp-note">No downloadable file found for this print.</div>'; return; }
        // Pre-fetch the file blob NOW so the share button can attach the real file
        // synchronously within its click gesture (see lpShare). Non-blocking.
        var shareFile = null;
        fetch(files[0].url, { credentials: 'same-origin' })
          .then(function (r) { return r.ok ? r.blob() : null; })
          .then(function (b) { if (b) shareFile = new File([b], files[0].name, { type: b.type || 'application/octet-stream' }); })
          .catch(function () {});
        var dlIco = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3v12"/><path d="m7 12 5 5 5-5"/><path d="M5 21h14"/></svg>';
        var mailIco = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/></svg>';
        var out = files.map(function (f) {
          return '<button class="llp-btn llp-dl" type="button" data-llp-dl data-url="' + lpEsc(f.url) + '" data-name="' + lpEsc(f.name) + '">' + dlIco + 'Download ' + (files.length > 1 ? lpEsc(f.name) : 'file') + '</button>';
        }).join('');
        out += '<button class="llp-btn llp-email" type="button" data-llp-email>' + mailIco + 'Email / share the file</button>';
        acts.innerHTML = out;
        [].forEach.call(acts.querySelectorAll('[data-llp-dl]'), function (b) {
          b.addEventListener('click', function () { lpDownload(b.getAttribute('data-url'), b.getAttribute('data-name')); });
        });
        var em = acts.querySelector('[data-llp-email]');
        if (em) em.addEventListener('click', function () { lpShare(shareFile, files[0].url, title); });
      })
      .catch(function () {
        var acts = sheet.querySelector('#llp-acts');
        if (acts) acts.innerHTML = isLoothprint ? '<div class="llp-note">Couldn’t load the file right now. Try again in a moment.</div>' : '';
        var db = sheet.querySelector('#llp-desc'); if (db && !db.innerHTML) db.innerHTML = '<div class="llp-note">Couldn’t load the details right now.</div>';
      });
  }
  // (Search-preview popup RETIRED 2026-06-10 — same rule: search results
  // navigate to the post page.)

  // ── Stop an inline YouTube video when it scrolls off-screen (Buck 2026-06-08) ──
  // forums.js plays a content video by injecting an iframe.fc-video (autoplay, no JS-API)
  // into the .fc-cover--video host. Once it scrolls out of view it would keep playing
  // audio; remove the iframe when its host leaves the viewport (the thumb stays, so it
  // reverts to the play facade). (True PiP/float isn't clean — reparenting an iframe
  // reloads it — so per Buck we stop instead.)
  function wireVideoAutoStop() {
    if (document.body.getAttribute('data-lg-vidstop')) return;
    document.body.setAttribute('data-lg-vidstop', '1');
    if (!('IntersectionObserver' in window) || !('MutationObserver' in window)) return;
    function watch(iframe) {
      var host = iframe.closest && iframe.closest('.fc-cover--video');
      if (!host) return;
      var io = new IntersectionObserver(function (entries) {
        entries.forEach(function (en) {
          // Don't remove a video that's gone FULLSCREEN — fullscreen pushes the
          // feed behind it out of view, so the host reads as non-intersecting; the
          // old code then yanked the iframe and fullscreen "warped back" instantly
          // (Buck bug 2026-06-08). Skip removal while any element is fullscreen.
          if (document.fullscreenElement || document.webkitFullscreenElement) return;
          if (!en.isIntersecting && iframe.parentNode) {
            iframe.parentNode.removeChild(iframe);   // stop playback; thumb facade returns
            io.disconnect();
          }
        });
      }, { threshold: 0 });
      io.observe(host);
    }
    [].forEach.call(document.querySelectorAll('.fc-cover--video iframe.fc-video'), watch);
    var root = document.getElementById('hub-feed-results') || document.querySelector('.feed') || document.body;
    new MutationObserver(function (muts) {
      muts.forEach(function (m) {
        [].forEach.call(m.addedNodes || [], function (n) {
          if (n.nodeType !== 1) return;
          if (n.matches && n.matches('iframe.fc-video')) watch(n);
          else if (n.querySelector) { var f = n.querySelector('iframe.fc-video'); if (f) watch(f); }
        });
      });
    }).observe(root, { childList: true, subtree: true });
  }

  // ── Autoplay feed videos MUTED as they scroll into view (Buck 2026-06-08:
  // "feel like Instagram"). Feed videos are click-to-play; this watches the
  // .fc-cover--video hosts and, for the SINGLE most-visible one (≥60%), injects a
  // muted+playsinline+autoplay iframe so it plays silently (mobile blocks sound
  // autoplay; YouTube shows its own tap-to-unmute). Only one plays at a time; the
  // auto iframe is removed when it leaves view (the thumb facade returns) — but
  // NEVER while fullscreen (would warp back). User-clicked (sound) iframes are
  // left alone. Mobile only; desktop keeps click-to-play. ─────────────────────
  // YouTube IFrame-API postMessage (iframe must carry enablejsapi=1).
  function ytPost(f, func) { try { f.contentWindow.postMessage(JSON.stringify({ event: 'command', func: func, args: [] }), '*'); } catch (e) {} }
  function ytIdFrom(src) { var m = (src || '').match(/\/embed\/([\w-]{6,})/); return m ? m[1] : ''; }
  // Tap-to-unmute overlay (Buck 2026-06-08): a muted autoplay video shows a small
  // speaker-off badge; the FIRST tap unmutes it + removes the overlay, so every tap
  // after that reaches YouTube's own controls. Applies to cover + inline videos.
  function lgUnmuteCss() {
    if (document.getElementById('lg-unmute-css')) return;
    var s = document.createElement('style'); s.id = 'lg-unmute-css';
    s.textContent =
      '.lg-unmute{position:absolute;inset:0;z-index:6;background:transparent;border:0;padding:0;margin:0;cursor:pointer;display:flex;align-items:flex-end;justify-content:flex-end}' +
      '.lg-unmute__b{margin:0 10px 10px 0;min-width:34px;height:34px;padding:0 11px;border-radius:999px;background:rgba(0,0,0,.6);color:#fff;display:flex;align-items:center;gap:6px;font:600 12px/1 var(--lg-font-sans,system-ui,sans-serif)}' +
      '.lg-unmute__b svg{width:17px;height:17px;flex:0 0 auto}';
    (document.head || document.documentElement).appendChild(s);
  }
  function addUnmuteOverlay(host, f) {
    if (host.querySelector('.lg-unmute')) return;
    lgUnmuteCss();
    if (getComputedStyle(host).position === 'static') host.style.position = 'relative';
    var ov = document.createElement('button'); ov.type = 'button'; ov.className = 'lg-unmute';
    ov.innerHTML = '<span class="lg-unmute__b"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 5 6 9H2v6h4l5 4z"/><path d="m22 9-6 6M16 9l6 6"/></svg>Tap to unmute</span>';
    ov.addEventListener('click', function (e) {
      e.preventDefault(); e.stopPropagation();
      ytPost(f, 'unMute'); ytPost(f, 'playVideo');
      f.setAttribute('data-lg-unmuted', '1');
      ov.parentNode && ov.parentNode.removeChild(ov);    // first tap done → taps now reach YouTube
    });
    host.appendChild(ov);
  }
  function wireVideoAutoplay() {
    if (!window.matchMedia('(max-width:640px)').matches) return;
    if (document.body.getAttribute('data-lg-vidauto')) return;
    document.body.setAttribute('data-lg-vidauto', '1');
    if (!('IntersectionObserver' in window)) return;
    var ratios = (typeof WeakMap !== 'undefined') ? new WeakMap() : null;
    var rmap = ratios || { _k: [], _v: [], get: function (k) { var i = this._k.indexOf(k); return i < 0 ? 0 : this._v[i]; }, set: function (k, v) { var i = this._k.indexOf(k); if (i < 0) { this._k.push(k); this._v.push(v); } else this._v[i] = v; } };
    function fs() { return document.fullscreenElement || document.webkitFullscreenElement; }
    function isInline(host) { return host.classList && host.classList.contains('bb-embed--video'); }
    function ensurePlaying(host) {
      if (isInline(host)) {                              // inline body video (existing iframe)
        var inf = host.querySelector('iframe'); if (!inf) return;
        if (!inf.getAttribute('data-lg-auto')) {         // first play → autoplay muted + enable JS API
          var iid = ytIdFrom(inf.src); if (!iid) return;
          inf.setAttribute('data-lg-auto', '1');
          inf.setAttribute('allow', 'accelerometer; autoplay; encrypted-media; gyroscope; picture-in-picture');
          inf.src = 'https://www.youtube.com/embed/' + iid + '?autoplay=1&mute=1&playsinline=1&rel=0&modestbranding=1&enablejsapi=1';
          addUnmuteOverlay(host, inf);
        } else { ytPost(inf, 'playVideo'); }             // back in view → resume (don't re-mute)
        return;
      }
      if (host.querySelector('iframe')) return;          // cover already playing (auto or clicked)
      var id = host.getAttribute('data-yt-play'); if (!id) return;
      var f = document.createElement('iframe');
      f.className = 'fc-video'; f.setAttribute('data-lg-auto', '1');
      f.src = 'https://www.youtube.com/embed/' + encodeURIComponent(id) + '?autoplay=1&mute=1&playsinline=1&rel=0&modestbranding=1&enablejsapi=1';
      f.title = 'Video';
      f.allow = 'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture';
      f.allowFullscreen = true; f.referrerPolicy = 'strict-origin-when-cross-origin';
      f.style.cssText = 'position:absolute;inset:0;width:100%;height:100%;border:0;background:#000;z-index:5;';
      host.appendChild(f);
      addUnmuteOverlay(host, f);
    }
    function stopAuto(host) {
      if (fs()) return;                                  // never unload a fullscreen video
      if (isInline(host)) { var inf = host.querySelector('iframe[data-lg-auto]'); if (inf) ytPost(inf, 'pauseVideo'); return; }
      var f = host.querySelector('iframe[data-lg-auto]');
      if (f && f.parentNode) f.parentNode.removeChild(f);
      var ov = host.querySelector('.lg-unmute'); if (ov) ov.parentNode.removeChild(ov);
    }
    function allHosts() {
      var a = [].slice.call(document.querySelectorAll('.fc-cover--video[data-yt-play]'));
      // inline videos: only the YouTube ones (have an /embed/ iframe)
      [].forEach.call(document.querySelectorAll('.bb-embed--video'), function (h) {
        var ifr = h.querySelector('iframe'); if (ifr && /youtube\.com\/embed\//.test(ifr.src || '')) a.push(h);
      });
      return a;
    }
    function reconcile() {
      if (fs()) return;                                  // leave playback alone while fullscreen
      var hosts = allHosts();
      var best = null, bestR = 0;
      hosts.forEach(function (h) { var r = rmap.get(h) || 0; if (r > bestR) { bestR = r; best = h; } });
      hosts.forEach(function (h) { if (h === best && bestR >= 0.6) ensurePlaying(h); else stopAuto(h); });
    }
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (en) { rmap.set(en.target, en.isIntersecting ? en.intersectionRatio : 0); });
      reconcile();
    }, { threshold: [0, 0.3, 0.6, 0.9] });
    function observeAll() {
      allHosts().forEach(function (h) {
        if (!h.getAttribute('data-lg-vauto')) { h.setAttribute('data-lg-vauto', '1'); io.observe(h); }
      });
    }
    observeAll();
    var root = document.getElementById('hub-feed-results') || document.querySelector('.feed') || document.body;
    if ('MutationObserver' in window) new MutationObserver(observeAll).observe(root, { childList: true, subtree: true });
  }

  // ── Desktop hover-to-play (Buck 2026-06-09): on desktop, hovering the mouse over a
  // feed video starts it playing MUTED (YouTube/Netflix-style preview); CLICKING it
  // unmutes. Moving the mouse away stops a still-muted preview so the thumbnail
  // returns; once you've clicked to unmute it keeps playing. Mobile is untouched (it
  // has its own scroll-into-view autoplay). Reuses the same muted-iframe swap + the
  // click-to-unmute overlay as the mobile path; the auto-stop observer already unloads
  // any .fc-video that scrolls out of view. Desktop only. ──────────────────────────
  function wireDesktopVideoHover() {
    if (window.matchMedia('(max-width:640px)').matches) return;     // desktop only
    if (document.body.getAttribute('data-lg-vidhover')) return;
    document.body.setAttribute('data-lg-vidhover', '1');

    function playMuted(host) {
      if (host.querySelector('iframe')) return;                     // already playing (hover or clicked)
      var id = host.getAttribute('data-yt-play'); if (!id) return;
      if (getComputedStyle(host).position === 'static') host.style.position = 'relative';
      var f = document.createElement('iframe');
      f.className = 'fc-video'; f.setAttribute('data-lg-hover', '1');
      f.src = 'https://www.youtube.com/embed/' + encodeURIComponent(id) +
        '?autoplay=1&mute=1&playsinline=1&rel=0&modestbranding=1&enablejsapi=1';
      f.title = 'Video';
      f.allow = 'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture';
      f.allowFullscreen = true; f.referrerPolicy = 'strict-origin-when-cross-origin';
      f.style.cssText = 'position:absolute;inset:0;width:100%;height:100%;border:0;background:#000;z-index:5;';
      host.appendChild(f);
      addUnmuteOverlay(host, f);                                    // first click unmutes + removes overlay
      var lbl = host.querySelector('.lg-unmute__b');                // desktop wording
      if (lbl && lbl.lastChild && lbl.lastChild.nodeType === 3) lbl.lastChild.textContent = 'Click to unmute';
    }
    function stopHover(host) {
      var f = host.querySelector('iframe.fc-video[data-lg-hover]');
      if (!f || f.getAttribute('data-lg-unmuted')) return;          // engaged (clicked) videos keep playing
      if (f.parentNode) f.parentNode.removeChild(f);
      var ov = host.querySelector('.lg-unmute'); if (ov && ov.parentNode) ov.parentNode.removeChild(ov);
    }
    function wireHost(host) {
      if (host.getAttribute('data-lg-hoverwired')) return;
      host.setAttribute('data-lg-hoverwired', '1');
      host.addEventListener('mouseenter', function () {
        // Hover takes over playback: if this video already has an iframe (e.g. it was
        // PAUSED when another video stole play), resume it; otherwise start the muted
        // preview. Either way, pause every OTHER video so only this one plays (Buck
        // 2026-06-09: "hover a new video → new plays, the old pauses").
        var f = host.querySelector('iframe.fc-video');
        if (f) { ytPost(f, 'playVideo'); stopOtherVideos(f); }
        else playMuted(host);                                   // new preview → its observer pauses the others
      });
      host.addEventListener('mouseleave', function () { stopHover(host); });
    }
    function wireAll() { [].forEach.call(document.querySelectorAll('.fc-cover--video[data-yt-play]'), wireHost); }
    wireAll();
    var root = document.getElementById('hub-feed-results') || document.querySelector('.feed') || document.body;
    if ('MutationObserver' in window) new MutationObserver(wireAll).observe(root, { childList: true, subtree: true });
  }

  // ── Only ONE video plays at a time (Buck 2026-06-09, desktop + mobile). However a
  // video starts — hover-preview, click-to-play, scroll autoplay — the moment a new
  // YouTube iframe appears we stop every OTHER one. Buck 2026-06-09 refinement: the
  // playing video should PAUSE (stay as a paused frame you can resume by hovering it
  // again), not get yanked back to its thumbnail. So any iframe WE control (carries
  // enablejsapi=1) is paused via the JS API; only a native no-API iframe is removed.
  // A MutationObserver catches all play paths uniformly. Gated by the "Videos"
  // setting (LGSettings 'playone', default ON) so it's toggleable in the cog. ──────
  function singleVideoEnabled() {
    try {
      var v = (window.LGSettings && window.LGSettings.getPlayone) ? window.LGSettings.getPlayone()
            : (window.LGSettings && window.LGSettings.get ? (window.LGSettings.get('playone') || 'on') : 'on');
      return v !== 'off';
    } catch (e) { return true; }
  }
  function isYtFrame(f) { return !!(f && f.tagName === 'IFRAME' && /youtube(?:-nocookie)?\.com\/embed\//.test(f.src || '')); }
  function stopOtherVideos(except) {
    if (!singleVideoEnabled()) return;
    [].forEach.call(document.querySelectorAll('iframe'), function (f) {
      if (f === except || !isYtFrame(f)) return;
      if (/[?&]enablejsapi=1/.test(f.src || '')) {            // ours → PAUSE (stays as a paused frame)
        ytPost(f, 'pauseVideo');
      } else {                                                // native, no JS API → remove (thumb returns)
        var cover = f.closest && f.closest('.fc-cover--video');
        if (cover) {
          if (f.parentNode) f.parentNode.removeChild(f);
          var ov = cover.querySelector('.lg-unmute'); if (ov && ov.parentNode) ov.parentNode.removeChild(ov);
        }
      }
    });
  }
  function enforceSingleVideo() {
    if (document.body.getAttribute('data-lg-1vid')) return;
    document.body.setAttribute('data-lg-1vid', '1');
    if (!('MutationObserver' in window)) return;
    var root = document.getElementById('hub-feed-results') || document.querySelector('.feed') || document.body;
    new MutationObserver(function (muts) {
      muts.forEach(function (m) {
        [].forEach.call(m.addedNodes || [], function (n) {
          if (n.nodeType !== 1) return;
          var f = (n.matches && n.matches('iframe')) ? n : (n.querySelector ? n.querySelector('iframe') : null);
          if (isYtFrame(f)) stopOtherVideos(f);              // a new video started → stop the rest
        });
      });
    }).observe(root, { childList: true, subtree: true });
  }

  // ── Expand a thread = load ALL replies in one tap (Buck 2026-06-08) ──────────
  // bb-mirror loads replies in batches of 5 ("Load N more replies" / .replies-loadmore),
  // so a 35-reply thread needed 6 taps — felt like load-more "wasn't doing it". When the
  // user expands a thread (or taps load-more), auto-click the batch loader until every
  // reply is in. Capped so a giant thread can't runaway-fetch.
  // While auto-loading, hide the per-batch churn (stubs popping in 5s + the "Load N more"/
  // "Loading…" button flicker = the "funky" Buck saw) behind ONE steady "Loading replies…"
  // state, then reveal them all at once.
  function ensureReplyLoadCss() {
    if (document.getElementById('lg-rload-css')) return;
    var s = document.createElement('style'); s.id = 'lg-rload-css';
    s.textContent =
      // While auto-walking the batches, KEEP the already-loaded replies visible so
      // the thread fills in progressively (it must never look "stuck on one reply"
      // — Buck 2026-06-08). Hide only the per-batch loadmore button so its
      // "Load N more / Loading…" text can't flicker, and show one calm
      // "Loading replies…" line at the bottom until every batch is in.
      '.feed-card.lg-rload .replies-loadmore{visibility:hidden!important;position:absolute!important;height:0!important;margin:0!important;padding:0!important;overflow:hidden!important}' +
      '.feed-card.lg-rload .feed-card__replies-full{position:relative;padding-bottom:30px}' +
      '.feed-card.lg-rload .feed-card__replies-full::after{content:"Loading replies\\2026";position:absolute;left:0;right:0;bottom:6px;text-align:center;color:var(--lg-mute,#6b6f6b);font:600 13px/1 var(--lg-font-sans,system-ui,sans-serif)}';
    (document.head || document.documentElement).appendChild(s);
  }
  function autoLoadAllReplies(card) {
    if (!card || card.__lgLoadingAll) return;
    card.__lgLoadingAll = true;
    ensureReplyLoadCss();
    if (card.querySelector('.feed-card__replies-full')) card.classList.add('lg-rload'); // mask churn
    var tries = 0, waited = 0, seen = false, finished = false;
    function finish() {
      if (finished) return; finished = true; clearTimeout(hardStop);
      // Reveal every loaded reply (the teaser had hidden all but one), restore
      // their photos + reaction rows, then drop the "Loading replies…" mask.
      var full = card.querySelector('.feed-card__replies-full');
      if (full) {
        var all = full.querySelectorAll('.reply-stub');
        for (var z = 0; z < all.length; z++) { all[z].classList.remove('lg-rhide'); all[z].classList.add('lg-rshow'); }
        revealReplyImages(full);
        enhanceReplyReactions(full);
      }
      card.classList.remove('lg-rload'); card.__lgLoadingAll = false;
    }
    var hardStop = setTimeout(finish, 9000);     // never leave the "Loading replies…" mask stuck
    (function step() {
      if (finished) return;
      if (tries++ > 80) { finish(); return; }
      var btn = card.querySelector('.replies-loadmore');
      var present = btn && btn.offsetParent !== null;
      if (present) {
        seen = true; waited = 0;
        if (/loading/i.test(btn.textContent || '')) { setTimeout(step, 280); return; }  // mid-fetch
        btn.click(); setTimeout(step, 380); return;
      }
      // load-more not present
      if (seen) { finish(); return; }            // it was there, now gone → all batches in → reveal
      if (++waited > 8) { finish(); return; }     // never appeared (≤5 replies, no load-more) → done
      setTimeout(step, 350);
    })();
  }
  // ── Replies popup (Buck 2026-06-08): "View N replies" / load-more opens a
  // bottom sheet (like events/loothprints) with ALL replies, instead of expanding
  // inline. We drive the canonical loader to fill .feed-card__replies-full, then
  // RELOCATE that element into the sheet (keeps reactions/reply-boxes wired); on
  // close we move it back and collapse the card. ──────────────────────────────
  var lrsScroll = '';
  function ensureRepStyles() {
    if (document.getElementById('lg-rep-css')) return;
    var s = document.createElement('style'); s.id = 'lg-rep-css';
    s.textContent = [
      '#looth-rep-sheet{position:fixed;inset:0;z-index:2147483520;display:none}',
      '#looth-rep-sheet.is-open{display:block}',
      '#looth-rep-sheet .lrs-back{position:absolute;inset:0;background:rgba(26,29,26,.55)}',
      '#looth-rep-sheet .lrs-card{position:absolute;left:0;right:0;bottom:0;top:12%;display:flex;flex-direction:column;' +
        'background:var(--lg-cream,#fbfbf8);border-radius:18px 18px 0 0;box-shadow:0 -8px 30px rgba(26,29,26,.32);animation:looth-pwa-up .26s ease}',
      '#looth-rep-sheet .lrs-hd{flex:0 0 auto;display:flex;align-items:center;gap:10px;padding:13px 14px 11px;border-bottom:1px solid var(--lg-line,#e3ddd0)}',
      '#looth-rep-sheet .lrs-t{flex:1 1 auto;min-width:0;font:700 16px/1.25 var(--lg-font-serif,Georgia,serif);color:var(--lg-charcoal,#1a1d1a);' +
        'white-space:nowrap;overflow:hidden;text-overflow:ellipsis}',
      '#looth-rep-sheet .lrs-x{flex:0 0 auto;width:32px;height:32px;border:0;border-radius:50%;background:var(--lg-sage-tint,#eef2e3);' +
        'color:var(--lg-sage-d,#6b7c52);font-size:20px;line-height:1;cursor:pointer}',
      '#looth-rep-sheet .lrs-body{flex:1 1 auto;overflow-y:auto;-webkit-overflow-scrolling:touch;padding:10px 14px 24px}',
      '#looth-rep-sheet .lrs-note{padding:20px 6px;color:var(--lg-mute,#6b6f6b);font:14px/1.5 var(--lg-font-sans,system-ui)}',
      '#looth-rep-sheet .feed-card__replies-full{display:block!important}',
      '#looth-rep-sheet .feed-card__replies-full *{visibility:visible!important}',
      // ── Facebook-style comments in the sheet (the .lg-fb-* styling is .feed-page-
      // scoped in forums.css so it never reaches the sheet — mirror it here, token-
      // based so it adapts to dark, + FB "Like · Reply · time" action row). Buck 2026-06-08.
      '#looth-rep-sheet .reply-stub{display:flex!important;gap:8px;align-items:flex-start;background:none!important;border:0!important;padding:0!important;margin:0 0 16px!important}',
      '#looth-rep-sheet .reply-stub .avatar-init,#looth-rep-sheet .reply-stub .avatar-init--img{width:32px;height:32px;border-radius:50%;flex:0 0 auto;font-size:13px;overflow:hidden}',
      '#looth-rep-sheet .reply-stub .avatar-init img{width:100%;height:100%;object-fit:cover;display:block}',
      '#looth-rep-sheet .lg-fb-col{display:flex;flex-direction:column;min-width:0;flex:1 1 auto;align-items:flex-start}',
      '#looth-rep-sheet .lg-fb-bubble{background:var(--lguser-bubble,#eceff3);border-radius:16px;padding:8px 13px;max-width:100%}',
      '#looth-rep-sheet .lg-fb-name{display:block;font-weight:700;font-size:13.5px;color:var(--lg-charcoal,#1a1d1a);text-decoration:none;margin:0 0 1px}',
      '#looth-rep-sheet .lg-fb-bubble .reply-stub__body,#looth-rep-sheet .lg-fb-bubble .reply-stub__excerpt{margin:0;font-size:14px;line-height:1.45;color:var(--lg-ink,#1a1d1a);display:block;-webkit-line-clamp:unset;overflow:visible;max-height:none}',
      '#looth-rep-sheet .lg-fb-bubble .reply-stub__img{margin-top:6px;border-radius:12px;max-width:100%}',
      '#looth-rep-sheet .lg-fb-actions{display:flex;align-items:center;gap:0;padding:5px 12px 0;font:600 12.5px/1 var(--lg-font-sans,system-ui,sans-serif);color:var(--lg-mute,#65676b)}',
      '#looth-rep-sheet .lg-fb-act{cursor:pointer;font-weight:700;color:var(--lg-mute,#65676b)}',
      '#looth-rep-sheet .lg-fb-reply::before,#looth-rep-sheet .lg-fb-time::before{content:"·";margin:0 6px;color:#8a8d91;font-weight:400}',
      '#looth-rep-sheet .lg-fb-time{font-weight:400;color:#8a8d91}',
      '#looth-rep-sheet .lg-fb-like.is-on{color:#1877f2}',
      '#looth-rep-sheet .lg-fb-replybox{display:flex;gap:8px;align-items:flex-start;margin:8px 0 2px;width:100%}',
      '#looth-rep-sheet .lg-fb-replywrap{display:flex;align-items:flex-end;gap:6px;flex:1 1 auto;background:var(--lguser-bubble,#eceff3);border-radius:18px;padding:4px 6px 4px 12px}',
      '#looth-rep-sheet .lg-fb-replyinput{flex:1 1 auto;border:0;background:none;resize:none;outline:none;font:14px/1.4 var(--lg-font-sans,system-ui,sans-serif);color:var(--lg-ink,#1a1d1a);max-height:120px;padding:4px 0}',
      '#looth-rep-sheet .lg-fb-send{border:0;background:none;cursor:pointer;color:var(--lg-sage-d,#52613d);font:700 13px/1 var(--lg-font-sans,system-ui,sans-serif);padding:6px 8px}',
      // also apply the FB "·"-separated action format to the INLINE feed comments
      '.feed-page .lg-fb-actions{gap:0!important}',
      '.feed-page .lg-fb-act{font-weight:700!important}',
      '.feed-page .lg-fb-reply::before,.feed-page .lg-fb-time::before{content:"·";margin:0 6px;color:#8a8d91;font-weight:400}',
      '.feed-page .lg-fb-like.is-on{color:#1877f2!important}',
      // ── Facebook-style top-level composer pinned to the bottom of the sheet ──
      '#looth-rep-sheet .lrs-comp{flex:0 0 auto;display:flex;flex-wrap:wrap;align-items:flex-end;gap:9px;padding:9px 12px calc(9px + env(safe-area-inset-bottom,0px));border-top:1px solid var(--lg-line,#e3ddd0);background:var(--lg-cream,#fbfbf8);will-change:transform;transition:transform .18s ease;position:relative;z-index:3}',
      '#looth-rep-sheet .lrs-comp__av{width:32px;height:32px;border-radius:50%;overflow:hidden;flex:0 0 auto;background:var(--lg-sage-tint,#eef2e3)}',
      '#looth-rep-sheet .lrs-comp__av img{width:100%;height:100%;object-fit:cover;display:block}',
      '#looth-rep-sheet .lrs-comp__wrap{display:flex;align-items:flex-end;gap:4px;flex:1 1 auto;min-width:0;background:#fff;border:1px solid #d8d2c4;border-radius:20px;padding:5px 6px 5px 14px}',
      '#looth-rep-sheet .lrs-comp__input{flex:1 1 auto;min-width:0;border:0;background:none;outline:none;resize:none;font:15px/1.4 var(--lg-font-sans,system-ui,sans-serif);color:#111!important;max-height:120px;padding:5px 0}',
      '#looth-rep-sheet .lrs-comp__input::placeholder{color:#888!important}',
      'html[data-lguser-theme="dark"] #looth-rep-sheet .lrs-comp__wrap,html[data-lguser-dark="1"] #looth-rep-sheet .lrs-comp__wrap{background:#fff!important}',
      'html[data-lguser-theme="dark"] #looth-rep-sheet .lrs-comp__input,html[data-lguser-dark="1"] #looth-rep-sheet .lrs-comp__input{color:#111!important}',
      '#looth-rep-sheet .lrs-comp__send{flex:0 0 auto;border:0;background:none;cursor:pointer;color:var(--lg-sage-d,#52613d);font:700 14px/1 var(--lg-font-sans,system-ui,sans-serif);padding:7px 9px}',
      '#looth-rep-sheet .lrs-comp__send:disabled{color:#b0b3b8;cursor:default}',
      '#looth-rep-sheet .lrs-comp__status{flex-basis:100%;font:12px/1.3 var(--lg-font-sans,system-ui,sans-serif);color:#8a8d91;padding:2px 0 0 41px}',
      'html[data-lguser-theme="dark"] #looth-rep-sheet .lrs-comp{background:#1b1e21;border-color:#2c312d}'
    ].join('\n');
    (document.head || document.documentElement).appendChild(s);
  }
  // Canonical reply post: auth.php → nonce, then POST REPLY_BASE/reply {topic_id,content}.
  var LRS_REPLY_BASE = ((document.getElementById('frm-form') || { dataset: {} }).dataset.restBase) || '/wp-json/buddyboss/v1';
  var lrsAuth = null;
  function lrsGetAuth(cb) {
    if (lrsAuth) { cb(lrsAuth); return; }
    fetch('/bb-mirror-api/v0/auth.php', { credentials: 'same-origin' })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (d) { lrsAuth = d || { authenticated: false }; cb(lrsAuth); })
      .catch(function () { cb({ authenticated: false }); });
  }
  function lrsEsc(s) { return String(s).replace(/[&<>]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;' }[c]; }); }
  function lrsViewerAvatar() {
    var sels = ['.lg-chrome__aside img[src*="/avatars/"]', '.lg-chrome__aside img[src*="/profile-media/"]', '#site-header img[src*="/avatars/"]', 'header img[src*="/avatars/"]'];
    for (var i = 0; i < sels.length; i++) { var el = document.querySelector(sels[i]); if (el && el.src && !/logo/i.test(el.src)) return el.src; }
    return null;
  }
  function lrsClose() {
    var sh = document.getElementById('looth-rep-sheet'); if (!sh) return;
    sh.classList.remove('is-open');
    document.body.style.overflow = lrsScroll || '';
    var comp = sh.querySelector('.lrs-comp'); if (comp) comp.style.transform = '';   // reset keyboard lift
    var b = sh.querySelector('#lrs-body'); if (b) b.innerHTML = '';
  }
  function lrsEnhance(full) { try { revealReplyImages(full); enhanceReplyReactions(full); } catch (e) {} }
  // Walk the canonical .replies-loadmore (forums.js's delegated handler fetches the
  // next page + inserts it) until every reply is in the sheet.
  function lrsLoadAll(full) {
    lrsEnhance(full);
    var tries = 0;
    (function step() {
      if (++tries > 160) { lrsEnhance(full); return; }
      var btn = full.querySelector('.replies-loadmore');
      if (!btn) { lrsEnhance(full); return; }                 // all batches in
      // forums.js sets disabled + "Loading…" synchronously on click and removes the
      // button when the page lands — so poll fast and only click an idle button.
      if (btn.disabled || /loading/i.test(btn.textContent || '')) { setTimeout(step, 80); return; }
      btn.click();                                            // append next page
      lrsEnhance(full);
      setTimeout(step, 90);
    })();
  }
  // Fetch the thread into the sheet body (reused on open AND after posting a reply).
  function lrsLoadThread(tid) {
    var sh = document.getElementById('looth-rep-sheet'); if (!sh) return;
    var body = sh.querySelector('#lrs-body'); if (!body) return;
    body.innerHTML = '<div class="lrs-note">Loading replies…</div>';
    var base = (window.LG_FORUM_BASE || '/forum').toString().replace(/\/+$/, '');
    fetch(base + '/?replies=' + encodeURIComponent(tid), { credentials: 'same-origin' })
      .then(function (r) { return r.ok ? r.text() : Promise.reject(); })
      .then(function (html) {
        body.innerHTML = '<div class="feed-card__replies-full lg-rshow"></div>';
        var full = body.querySelector('.feed-card__replies-full');
        full.innerHTML = html;
        if (!full.querySelector('.reply-stub')) { body.innerHTML = '<div class="lrs-note">No replies yet. Be the first to reply.</div>'; return; }
        lrsLoadAll(full);
      })
      .catch(function () { body.innerHTML = '<div class="lrs-note">Couldn’t load replies right now.</div>'; });
  }
  // Lift the reply composer above the on-screen keyboard (visualViewport delta).
  function lrsAdjustKb() {
    var sh = document.getElementById('looth-rep-sheet');
    var comp = sh && sh.querySelector('.lrs-comp');
    if (!comp) return;
    if (!sh.classList.contains('is-open') || !window.visualViewport) { comp.style.transform = ''; return; }
    var vv = window.visualViewport;
    var kb = Math.max(0, Math.round(window.innerHeight - vv.height - vv.offsetTop));
    comp.style.transform = kb > 1 ? ('translateY(-' + kb + 'px)') : '';
  }
  function openRepliesSheet(card, opts) {
    if (!card) return;
    ensureRepStyles();
    var exp = card.querySelector('.feed-card__expand');
    // topic id from the expander OR (for 0-reply posts with no expander) the card.
    var tid = (exp && (exp.dataset.topicId || exp.getAttribute('data-topic-id'))) || card.getAttribute('data-topic-id');
    var fid = card.getAttribute('data-forum-id') || '';
    var m = exp && (exp.textContent || '').match(/(\d+)/); var n = m ? m[1] : '';
    var sh = document.getElementById('looth-rep-sheet');
    if (!sh) {
      sh = document.createElement('div'); sh.id = 'looth-rep-sheet';
      sh.innerHTML = '<div class="lrs-back" data-lrs-close></div><div class="lrs-card">' +
        '<div class="lrs-hd"><span class="lrs-t"></span><button class="lrs-x" type="button" data-lrs-close aria-label="Close">&times;</button></div>' +
        '<div class="lrs-body" id="lrs-body"></div>' +
        '<div class="lrs-comp"><span class="lrs-comp__av" id="lrs-comp-av"></span>' +
          '<div class="lrs-comp__wrap"><textarea class="lrs-comp__input" id="lrs-comp-input" rows="1" placeholder="Write a comment…"></textarea>' +
          '<button class="lrs-comp__send" id="lrs-comp-send" type="button" disabled>Post</button></div>' +
          '<span class="lrs-comp__status" id="lrs-comp-status"></span></div>';
      document.body.appendChild(sh);
      sh.addEventListener('click', function (e) { if (e.target.closest('[data-lrs-close]')) lrsClose(); });
      // composer wiring (once): enable Post on input; auto-grow; submit
      var inp = sh.querySelector('#lrs-comp-input'), send = sh.querySelector('#lrs-comp-send');
      inp.addEventListener('input', function () {
        send.disabled = !inp.value.trim();
        inp.style.height = 'auto'; inp.style.height = Math.min(inp.scrollHeight, 120) + 'px';
      });
      send.addEventListener('click', function () { lrsSubmit(sh); });
      // Keyboard-aware composer: pin the text box just above the on-screen keyboard
      // so it's visible the instant you focus it (Buck: "I can't see the text box
      // off the bat"). visualViewport shrinks when the keyboard opens → lift by the delta.
      if (window.visualViewport) {
        window.visualViewport.addEventListener('resize', lrsAdjustKb);
        window.visualViewport.addEventListener('scroll', lrsAdjustKb);
      }
      inp.addEventListener('focus', function () { setTimeout(lrsAdjustKb, 120); setTimeout(lrsAdjustKb, 330); });
      inp.addEventListener('blur', function () { setTimeout(lrsAdjustKb, 80); });
    }
    // per-open setup
    sh.setAttribute('data-tid', tid || ''); sh.setAttribute('data-fid', fid);
    sh.querySelector('.lrs-t').textContent = n ? (n + ' replies') : 'Replies';
    var av = sh.querySelector('#lrs-comp-av'); var avs = lrsViewerAvatar();
    av.innerHTML = avs ? '<img src="' + avs.replace(/"/g, '&quot;') + '" alt="">' : '';
    var inp2 = sh.querySelector('#lrs-comp-input'); inp2.value = ''; inp2.style.height = 'auto';
    sh.querySelector('#lrs-comp-send').disabled = true;
    sh.querySelector('#lrs-comp-status').textContent = '';
    lrsScroll = document.body.style.overflow; document.body.style.overflow = 'hidden';
    sh.classList.add('is-open');
    // Focus the composer (opens the keyboard) when opened via the Reply intent.
    // MUST be synchronous within the originating tap so iOS honors the focus.
    if (opts && opts.focus) { try { inp2.focus({ preventScroll: true }); } catch (e) { try { inp2.focus(); } catch (e2) {} } }
    if (!tid) { sh.querySelector('#lrs-body').innerHTML = '<div class="lrs-note">Couldn’t load replies.</div>'; return; }
    lrsLoadThread(tid);
  }
  // Post a top-level reply to the topic, then reload the thread to show it.
  function lrsSubmit(sh) {
    var inp = sh.querySelector('#lrs-comp-input'), send = sh.querySelector('#lrs-comp-send'), status = sh.querySelector('#lrs-comp-status');
    var text = (inp.value || '').trim(); if (!text) return;
    var tid = parseInt(sh.getAttribute('data-tid'), 10); if (!tid) return;
    var fid = parseInt(sh.getAttribute('data-fid'), 10);
    send.disabled = true; status.textContent = 'Posting…';
    lrsGetAuth(function (a) {
      if (!a || !a.authenticated) { status.textContent = 'Sign in to reply.'; send.disabled = false; return; }
      var payload = { topic_id: tid, content: '<p>' + lrsEsc(text).replace(/\n/g, '<br>') + '</p>' };
      if (fid) payload.forum_id = fid;
      fetch(LRS_REPLY_BASE + '/reply', {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': a.nonce },
        body: JSON.stringify(payload)
      })
        .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
        .then(function (res) {
          if (!res.ok) { status.textContent = (res.j && (res.j.message || res.j.code)) || 'Could not post.'; send.disabled = false; return; }
          inp.value = ''; inp.style.height = 'auto'; status.textContent = '';
          lrsLoadThread(tid);                                  // reload so the new reply shows
          var b = sh.querySelector('#lrs-body'); if (b) setTimeout(function () { b.scrollTop = b.scrollHeight; }, 600);
        })
        .catch(function () { status.textContent = 'Network error.'; send.disabled = false; });
    });
  }

  function wireExpandLoadAll() {
    if (!window.matchMedia('(max-width:640px)').matches) return;   // mobile only — desktop keeps native inline expand
    if (document.body.getAttribute('data-lg-loadall')) return;
    document.body.setAttribute('data-lg-loadall', '1');
    document.addEventListener('click', function (e) {
      var exp = e.target.closest && e.target.closest('.feed-card__expand');
      if (exp) {
        var c = exp.closest('.feed-card');
        if (!c || c.__lgRepLoading || c.__lgPreviewing) return;          // our/teaser programmatic clicks
        if (/hide/i.test(exp.textContent || '')) return;                 // collapsing → let native
        e.preventDefault(); e.stopPropagation();
        openRepliesSheet(c);                                             // "View N replies" → popup
        return;
      }
      var lm = e.target.closest && e.target.closest('.replies-loadmore');
      if (lm) { var c2 = lm.closest('.feed-card'); if (c2 && !c2.__lgRepLoading) { e.preventDefault(); e.stopPropagation(); openRepliesSheet(c2); } }
    }, true);                                                  // capture: own the tap, drive the loader ourselves
  }

  // Immersive (edge-to-edge) Hub feed — toggled via the "Hub feed" setting
  // (app-settings.js sets data-lguser-feed="immersive" on <html>). Instagram-style:
  // drop the card border / radius / side gutter and let cover photos go full-bleed
  // to the screen edges, while the text + meta stay inset by the card padding. The
  // default ('cards') is untouched — these rules only match when the attribute is
  // set, so injecting them always on the Hub is a no-op otherwise. Injected once.
  function ensureImmersiveCss() {
    if (document.getElementById('lg-immersive-css')) return;
    var P = 'html[data-lguser-feed="immersive"] .feed-page';
    var css = [
      // remove the feed-page side gutter so cards span the full viewport width
      P + '{padding-left:0!important;padding-right:0!important}',
      // tighten the gap between posts to ~half (Buck 2026-06-08): the feed is a
      // grid with row-gap 16px + the card's own 6px margin = ~22px. Halve both.
      P + ' .feed{row-gap:8px!important}',
      // strip the card chrome; keep a slim divider between posts (IG-style)
      P + ' .feed-card{border-radius:0!important;box-shadow:none!important;border-left:0!important;' +
        'border-right:0!important;border-top:0!important;border-bottom:1px solid var(--lguser-line,#e3ddd0)!important;margin:0 0 3px!important}',
      // cover photos break out of the 14px card padding → edge to edge
      P + ' .feed-card__cover,' + P + ' .fc-cover{margin-left:-14px!important;margin-right:-14px!important;' +
        'width:auto!important;max-width:none!important;border-radius:0!important}',
      P + ' .feed-card__cover-img,' + P + ' .fc-cover img{border-radius:0!important;width:100%!important;max-width:none!important}'
    ].join('\n');
    // Mobile/app only — desktop always keeps the bordered card feed regardless of
    // the user's feed setting (Buck 2026-06-08: none of the immersive work touches desktop).
    css = '@media (max-width:640px){\n' + css + '\n}';
    var s = document.createElement('style'); s.id = 'lg-immersive-css'; s.textContent = css;
    (document.head || document.documentElement).appendChild(s);
  }

  // Desktop Hub polish (Buck 2026-06-09): the Hub front door was 100% mobile-gated
  // (every other block here is @media max-width:640) so the DESKTOP feed was raw
  // forums.css — a flat single column that ignored the user's app theme. This adds
  // the desktop look, mirroring the mobile app:
  //   - MOSAIC layout (Buck's pick from the desktop Style Sandbox): the first post
  //     is a full-width hero, the rest fall into a 2-column card grid.
  //   - Card treatment matching the app: 16px radius + soft float shadow.
  //   - THEME-FOLLOWING: the feed reads the same --lguser-* tokens app-settings.js
  //     sets, so picking Sage/Amber/Rust/Dark in settings recolors the desktop feed
  //     exactly like mobile. The crux (Buck 2026-06-09): forums.css already defines
  //     --lguser-* under prefers-color-scheme:dark, so with NO theme picked the
  //     tokens read OS-dark and the default correctly FOLLOWS THE OS (Buck's call) —
  //     we only chain the card BACKGROUND to forums' --bg-card (the one token the
  //     themes set but forums leaves to OS), so default stays OS-consistent while a
  //     picked theme's --lguser-card wins. Verified live at 1280/3440px, both the
  //     OS-dark default and a picked light theme (amber) render correctly.
  // Gated min-width:641 so it never touches the mobile/app pass. Injected once.
  function ensureDesktopCss() {
    if (document.getElementById('lg-desktop-css')) return;
    var P = '.feed-page';
    // Picked-LIGHT-theme selector: app-settings sets both data-lguser-dark="0"
    // AND data-lguser-theme="<id>" on <html>. Matches cream/sage/amber/rust/custom
    // but NOT the no-pick default (which stays on forums' OS/native theme) and NOT
    // the picked Dark theme (dark="1"). Used to re-theme the desktop SHELL.
    var L = 'html[data-lguser-dark="0"]:not([data-lguser-theme="default"])';
    var css = [
      // (page-bg rule RETIRED 2026-06-10 with the C10 unification: default = body
      // var(--bg); picked light = the forums.css bridge repoints --bg; picked dark
      // = the boot script's critical CSS. First frame paints themed already.)
      // MASONRY geometry (feed max-width 2100 / column-width 370 / 16:9 covers)
      // RETIRED from this overlay 2026-06-10 (bespoke-cutover, audit C2): it lives in
      // forums.css @media(min-width:641) — commit 88955fb — and paints first-frame
      // server-side. forums.css is the ONLY masonry-geometry source now. The
      // .feed-card display:block/break-inside props below still ship until the C3
      // arrangement/chrome fold: they also SUPPRESS forums.css's desktop grid
      // arrangement, so removing them is a deliberate visual step, not a dupe kill.
      // card shell matches the app; bg chains to forums --bg-card so DEFAULT follows
      // the OS while a picked theme's --lguser-card overrides. Masonry props here too:
      // each card is a whole block in the column flow with an even vertical rhythm.
      P + ' .feed-card{background:var(--lguser-card,var(--bg-card,#fff))!important;' +
        'border:1px solid var(--lguser-line,#e3ddd0)!important;border-radius:16px!important;' +
        'box-shadow:0 1px 2px rgba(26,29,26,.05),0 10px 22px -14px rgba(26,29,26,.28)!important;' +
        'display:block!important;width:100%!important;margin:0 0 18px!important;column-span:none!important;' +
        'break-inside:avoid!important;-webkit-column-break-inside:avoid!important;page-break-inside:avoid!important}',
      // text + pills follow the theme tokens (OS-aware for default)
      P + ' .feed-card__title,' + P + ' .feed-card__title a,' + P + ' .feed-card__op-author{color:var(--lguser-ink,#1a1d1a)!important}',
      P + ' .feed-card__op-excerpt,' + P + ' .fc-excerpt,' + P + ' .fc-time,' + P + ' .fc-category{color:var(--lguser-mute,#6b6f6b)!important}',
      P + ' .feed-card__kind-badge,' + P + ' .fc-cat-chip{background:var(--lguser-pill,#eef2e3)!important;' +
        'color:var(--lguser-accent-d,#52613d)!important;border-color:transparent!important}',

      // ── SHELL COHERENCE token repoint + rail-label force MOVED to forums.css
      // 2026-06-10 (bespoke-cutover, audit C10 unification): same gate selector,
      // plain cascade instead of !important, applied on the FIRST frame. The two
      // theme systems are bridged server-side now — this overlay no longer
      // re-themes the shell after load.

      // ── Reaction summary chips (.fcr-chip/.fcr-add): forums.css backs them with
      // var(--bg-card) (un-themed), so they were dark islands + failed-AA count text
      // on a light card. Chain to the themed card surface (default still falls to
      // --bg-card, staying dark-coherent).
      P + ' .fcr-chip{background:var(--lguser-card,var(--bg-card,#fff))!important}',

      // ── Make the "Add reaction" button OBVIOUS on desktop (Buck 2026-06-09): the
      // native .fcr-add was a tiny 28×24 "☺+" — easy to miss. Turn it into a clear,
      // bigger labelled pill ("☺ React") with the accent tint, a real hover state,
      // and a 34px hit height (also helps the elderly-friendly goal). Desktop only.
      P + ' .fcr-add{height:34px!important;min-width:0!important;width:auto!important;padding:0 14px!important;' +
        'border-radius:999px!important;gap:6px!important;background:var(--lguser-pill,var(--lg-sage-tint,#eef2e3))!important;' +
        'border:1px solid var(--lguser-line,#e3ddd0)!important;color:var(--lguser-accent-d,var(--lg-sage-d,#52613d))!important;' +
        'font-size:18px!important;line-height:1!important;transition:background .12s,color .12s,transform .05s}',
      P + ' .fcr-add>span{display:none!important}',                       // hide the tiny "+"
      P + ' .fcr-add::after{content:"React"!important;font:700 13px/1 var(--lg-font-sans,system-ui,-apple-system,sans-serif)!important;color:inherit}',
      P + ' .fcr-add:hover{background:var(--lguser-accent,var(--lg-sage,#87986a))!important;color:#fff!important}',
      P + ' .fcr-add:active{transform:translateY(1px)}',

      // (Desktop .lg-card-actions surfacing + bookmark-pill RETIRED 2026-06-10 —
      // Ian: "the bottom row on cpts could use some love". The canonical
      // .fc-actions row (reactions left, comment + ☆ save right) is THE row;
      // forums.css owns its desktop layout. Mobile action row untouched.)

      // ── Reply composer. The DESKTOP card composer is .fc-composer > .fc-composer__wrap
      // (the input "bubble") > input.fc-composer__input. The wrap hardcodes a dark
      // var(--bg-card) bubble + the input text is dark → invisible on a light theme.
      // Theme the bubble to the comment-bubble/pill surface and bind the input text
      // to --lguser-ink (default/dark fall back to --bg-card + --fg, staying legible).
      P + ' .fc-composer__wrap{background:var(--lguser-bubble,var(--lguser-pill,var(--bg-card,#eceff3)))!important;color:var(--lguser-mute,var(--fg-muted,#6b6f6b))!important}',
      P + ' .fc-composer__input{color:var(--lguser-ink,var(--fg,#1a1d1a))!important;background:transparent!important}',
      P + ' .fc-composer__input::placeholder{color:var(--lguser-mute,var(--fg-muted,#6b6f6b))!important}',
      // also cover the native reply-form textarea + the inline fb-composer input
      // (same dark-on-light defect via forums.css hardcoded white bg + color:var(--fg)).
      P + ' .reply-form,' + P + ' .reply-form textarea,' + P + ' .feed-card__inline-compose .fic-input{' +
        'background:var(--lguser-card,var(--bg-card,#fff))!important;color:var(--lguser-ink,var(--fg,#1a1d1a))!important;' +
        'border-color:var(--lguser-line,#e3ddd0)!important}',

      // ── Desktop grid-card meta uses .fc-* classes (not the .lg-card-* the mobile
      // pass themes). Theme author/time/category so the meta row follows too.
      P + ' .fc-author__name,' + P + ' .fc-author__name a{color:var(--lguser-ink,#1a1d1a)!important}',
      P + ' .fc-time{color:var(--lguser-mute,#6b6f6b)!important}',

      // (MOSAIC first-child hero RETIRED 2026-06-10 — Ian: uniform cards.
      // forums.css owns uniform cover sizing in both layouts now.)

      // (Author-search hide RETIRED 2026-06-10 — Ian: "we need author search
      // back". The server-rendered .hub-tsearch--author box shows again.)

      // ── Make the "Search the Hub" box LONGER (Buck 2026-06-09: it's stubby). Let it
      // grow to fill the sort bar's free space (with the rail collapsed there's plenty).
      P + ' .feed-sort-bar .feed-toolbar-search{flex:1 1 320px!important;min-width:240px!important;max-width:560px!important}',
      P + ' .feed-sort-bar .feed-toolbar-search .hub-tsearch--q{flex:1 1 auto!important;width:auto!important}',
      P + ' .feed-sort-bar .feed-toolbar-search .hub-tsearch--q .hub-tsearch__in{flex:1 1 auto!important;width:auto!important;min-width:0!important}',

      // ── Hide the cryptic forums-native quick toggles in the sort bar (Buck
      // 2026-06-09): "T" (.feed-text-toggle, cycle text size) and "C"
      // (.feed-theme-toggle, cycle color theme) are now fully covered — clearly
      // labelled — by the header settings gear (LGSettings: Text size + Color), and
      // "Cpt" (.feed-compact-toggle, compact view) is power-user clutter. Drop all
      // three so the toolbar is clean + consistent with mobile.
      '.feed-sort-bar .feed-text-toggle,.feed-sort-bar .feed-theme-toggle,.feed-sort-bar .feed-compact-toggle{display:none!important}'
    ].join('\n');
    css = '@media (min-width:641px){\n' + css + '\n}';
    var s = document.createElement('style'); s.id = 'lg-desktop-css'; s.textContent = css;
    (document.head || document.documentElement).appendChild(s);
  }

  // ── HUB STYLE: user-pickable desktop LAYOUT + an Appearance panel in the left
  // nav rail (Buck 2026-06-09: "give me my hub style and theme settings under
  // these [filters]... different versions of layout for people that want the hub
  // to look different, like our style cards"). Four layouts, persisted per-device
  // and applied as html[data-lg-hublayout]; the base (masonry) is the default. The
  // panel sits under the category filters: layout style-cards + theme swatches +
  // text size (theme/size proxy LGSettings, so they stay in sync with the gear).
  // Desktop only. ───────────────────────────────────────────────────────────────
  // Hub layout picker + the sidebar "Hub style" panel RETIRED 2026-06-10
  // (bespoke-cutover; Ian: the header GEAR is the only page-state control zone).
  // - The Mosaic/Stream toggle lives in the gear panel (app-settings buildPanel).
  // - data-lg-hublayout is set PRE-PAINT by the nginx boot script (localStorage
  //   lg-hub-layout; legacy cards/compact values map to masonry = Mosaic).
  // - Stream variant CSS lives in forums.css @media(min-width:641).

  // Tapping the BODY text of a reply (not the author name, not a link/action)
  // expands the collapsed thread — same as tapping "View N replies" (Buck
  // 2026-06-08). Only fires while collapsed; once expanded a body tap is inert.
  function wireReplyBodyExpand() {
    if (!window.matchMedia('(max-width:640px)').matches) return;   // mobile only — desktop unchanged
    if (document.body.getAttribute('data-lg-replybody')) return;
    document.body.setAttribute('data-lg-replybody', '1');
    document.addEventListener('click', function (e) {
      var body = e.target.closest && e.target.closest('.reply-stub__body, .reply-stub__excerpt');
      if (!body) return;
      // never hijack the author name, a link, or an action/reaction control
      if (e.target.closest('a, button, .lg-fb-name, .reply-stub__author, .lg-fb-actions, .reply-stub__actions, .fcr, .fcr-palette')) return;
      var card = body.closest('.feed-card'); if (!card) return;
      // Tapping a reply opens the full replies popup (Buck 2026-06-08) — the teaser
      // reply shows first; tapping it (or "View N replies") opens the sheet with all.
      e.preventDefault(); e.stopPropagation();
      openRepliesSheet(card);
    }, true);  // capture: beat the card's open-thread handler
  }

  // Tapping the BODY text of a POST expands it inline (Buck 2026-06-08: "a post
  // with a large body — tapping the text does nothing and I can't see it all").
  // forums.js already does this for topic cards, but only inside its own `feed`
  // element, so cards in other contexts (and any card it missed) didn't respond.
  // This document-delegated fallback covers ALL post bodies: it mirrors forums.js
  // (toggle feed-card--max + drive the canonical .feed-card__read-more, which
  // lazy-fetches the full body). Capture + stopPropagation so forums.js can't
  // also fire and double-toggle. Cards with no read-more (content/articles whose
  // full text lives on their own page) are left to their normal tap behavior.
  function wirePostBodyExpand() {
    if (!window.matchMedia('(max-width:640px)').matches) return;   // mobile only — desktop keeps forums.js's native expand
    if (document.body.getAttribute('data-lg-postbody')) return;
    document.body.setAttribute('data-lg-postbody', '1');
    document.addEventListener('click', function (e) {
      var ex = e.target.closest && e.target.closest('.feed-card__op-excerpt, .fc-excerpt');
      if (!ex) return;
      if (e.target.closest('.reply-stub')) return;   // replies have their own handler
      if (e.target.closest('a, button, .lg-act, .lg-card-actions, .fcr, .fcr-palette')) return;
      var card = ex.closest('.feed-card'); if (!card) return;
      var rm = card.querySelector('.feed-card__read-more:not(.lg-rm-syn)');   // native expander
      if (rm) {
        e.preventDefault(); e.stopPropagation();
        var willOpen = !card.classList.contains('feed-card--max');
        card.classList.toggle('feed-card--max', willOpen);
        var wantState = willOpen ? 'expanded' : 'collapsed';
        if (rm.dataset.state !== wantState) rm.click();  // forums.js read-more handler does the fetch + show/hide
        return;
      }
      // No native read-more: if we added a synthetic "Read more" (clamped body with
      // full text in the DOM), tapping the body toggles it too.
      var syn = card.querySelector('.lg-rm-syn');
      if (syn) { e.preventDefault(); e.stopPropagation(); syn.click(); }
    }, true);  // capture: own the tap so forums.js's feed-scoped handler can't double-fire
  }

  // ── Image lightbox: tap ANY Hub image → snappy fullscreen, one tap to close,
  // pinch-to-zoom + pan (Buck 2026-06-08). Mobile only (pinch is a touch gesture;
  // desktop keeps forums.js's native lightbox). Captures covers (incl. content
  // cards — which otherwise open comments), reply images, and body images;
  // excludes video/gated covers (play/paywall), loothprint covers (→ their sheet),
  // and avatars. Runs in CAPTURE before keepContentOnHub/forums.js. ────────────
  var lgLb = null, lgImg = null, lgS = 1, lgTx = 0, lgTy = 0, lgScrollY = '';
  function lgApplyTf() { if (lgImg) lgImg.style.transform = 'translate(-50%,-50%) translate(' + lgTx + 'px,' + lgTy + 'px) scale(' + lgS + ')'; }
  function lgEnsureLb() {
    if (lgLb) return;
    if (!document.getElementById('lg-lb-css')) {
      var st = document.createElement('style'); st.id = 'lg-lb-css';
      st.textContent =
        '#lg-lb{position:fixed;inset:0;z-index:2147483600;background:rgba(0,0,0,.93);display:none;' +
        'touch-action:none;overscroll-behavior:contain;-webkit-user-select:none;user-select:none}' +
        '#lg-lb.is-on{display:block}' +
        '#lg-lb img{position:absolute;top:50%;left:50%;max-width:100vw;max-height:100vh;' +
        'transform:translate(-50%,-50%);will-change:transform;-webkit-user-drag:none;user-select:none}' +
        '#lg-lb .lg-lb-x{position:fixed;top:calc(env(safe-area-inset-top,0px) + 10px);right:14px;z-index:2;' +
        'width:38px;height:38px;border:0;border-radius:50%;background:rgba(0,0,0,.45);color:#fff;font-size:19px;line-height:38px;cursor:pointer}';
      document.head.appendChild(st);
    }
    lgLb = document.createElement('div'); lgLb.id = 'lg-lb';
    lgLb.innerHTML = '<button class="lg-lb-x" type="button" aria-label="Close">✕</button><img alt="">';
    document.body.appendChild(lgLb);
    lgImg = lgLb.querySelector('img');
    lgLb.querySelector('.lg-lb-x').addEventListener('click', function (e) { e.stopPropagation(); lgCloseLb(); });
    wireLbGestures();
  }
  var lgHist = false;
  function lgOpenLb(url) {
    lgEnsureLb();
    lgS = 1; lgTx = 0; lgTy = 0; lgApplyTf();
    lgImg.src = url; lgLb.classList.add('is-on');
    lgScrollY = document.body.style.overflow; document.body.style.overflow = 'hidden';
    // Push a history entry so the phone's back gesture/button CLOSES the lightbox
    // instead of navigating away (which on a PWA reads as "the app closed").
    if (!lgHist) { try { history.pushState({ lgLb: 1 }, ''); lgHist = true; } catch (e) {} }
  }
  function lgCloseLb(fromPop) {
    if (!lgLb) return;
    lgLb.classList.remove('is-on'); lgImg.removeAttribute('src');
    document.body.style.overflow = lgScrollY || '';
    // If we pushed a history entry and this close came from a tap/✕/Esc (not the
    // back gesture itself), pop our entry back off so history stays balanced.
    if (lgHist && !fromPop) { lgHist = false; try { history.back(); } catch (e) {} }
    else { lgHist = false; }
  }
  function wireLbGestures() {
    var startDist = 0, startS = 1, sox = 0, soy = 0, pinch = false;
    var panning = false, sTx = 0, sTy = 0, p0x = 0, p0y = 0, tStart = 0, moved = 0, x0 = 0, y0 = 0;
    function dist(t) { return Math.hypot(t[0].clientX - t[1].clientX, t[0].clientY - t[1].clientY); }
    function mid(t) { return { x: (t[0].clientX + t[1].clientX) / 2, y: (t[0].clientY + t[1].clientY) / 2 }; }
    lgLb.addEventListener('touchstart', function (e) {
      if (e.touches.length === 2) {
        pinch = true; panning = false; startDist = dist(e.touches); startS = lgS;
        var p = mid(e.touches), cx = window.innerWidth / 2, cy = window.innerHeight / 2;
        sox = (p.x - cx - lgTx) / lgS; soy = (p.y - cy - lgTy) / lgS; e.preventDefault();
      } else if (e.touches.length === 1) {
        tStart = Date.now(); moved = 0; x0 = e.touches[0].clientX; y0 = e.touches[0].clientY;
        if (lgS > 1) { panning = true; sTx = lgTx; sTy = lgTy; p0x = x0; p0y = y0; }
      }
    }, { passive: false });
    lgLb.addEventListener('touchmove', function (e) {
      if (pinch && e.touches.length === 2) {
        var s = Math.max(1, Math.min(6, startS * (dist(e.touches) / startDist)));
        var p = mid(e.touches), cx = window.innerWidth / 2, cy = window.innerHeight / 2;
        lgS = s; lgTx = p.x - cx - sox * s; lgTy = p.y - cy - soy * s; lgApplyTf(); e.preventDefault();
      } else if (panning && e.touches.length === 1) {
        var t = e.touches[0]; lgTx = sTx + (t.clientX - p0x); lgTy = sTy + (t.clientY - p0y);
        moved += Math.abs(t.clientX - x0) + Math.abs(t.clientY - y0); lgApplyTf(); e.preventDefault();
      } else if (e.touches.length === 1) {
        var t1 = e.touches[0]; moved = Math.abs(t1.clientX - x0) + Math.abs(t1.clientY - y0);
      }
    }, { passive: false });
    lgLb.addEventListener('touchend', function (e) {
      if (e.touches.length > 0) return;
      if (pinch) { pinch = false; if (lgS <= 1.03) { lgS = 1; lgTx = 0; lgTy = 0; lgApplyTf(); } return; }
      var quick = (Date.now() - tStart) < 300;
      if (!panning && quick && moved < 12) { lgCloseLb(); return; }   // clean tap → close
      panning = false;
    });
    lgLb.addEventListener('click', function (e) { if (e.target === lgImg || e.target === lgLb) lgCloseLb(); }); // mouse fallback
  }
  function wireImageLightbox() {
    if (!window.matchMedia('(max-width:640px)').matches) return;     // mobile only
    if (document.body.getAttribute('data-lg-imglb')) return;
    document.body.setAttribute('data-lg-imglb', '1');
    document.addEventListener('click', function (e) {
      if (e.target.closest('.fc-avatar, .avatar-init, .avi-sm, .lg-card-avatar, button, .lg-act, .lg-card-actions, .fcr, .fcr-palette, a.dir-link')) return;
      var src = null;
      var cover = e.target.closest('.feed-card__cover, .fc-cover');
      if (cover) {
        if (cover.classList.contains('fc-cover--video') || cover.classList.contains('fc-cover--gated')) return;
        var card = cover.closest('.feed-card');
        if (card && card.getAttribute('data-kind') === 'loothprint') return;   // loothprint → its sheet
        var cimg = cover.querySelector('.feed-card__cover-img, img');
        src = cimg && (cimg.currentSrc || cimg.getAttribute('src'));
      } else {
        var img = e.target.closest('.reply-stub__img, .feed-card__full-body img, .post__body img, .lg-fb-bubble img, .feed-card__op img');
        if (img && img.tagName === 'IMG') {
          var wrap = img.closest('a[href]');
          var href = (wrap && /\.(jpe?g|png|gif|webp|avif)(\?|#|$)/i.test(wrap.getAttribute('href') || '')) ? wrap.getAttribute('href') : null;
          src = href || img.currentSrc || img.getAttribute('src');
        }
      }
      if (!src) return;
      e.preventDefault(); e.stopPropagation();
      lgOpenLb(src);
    }, true);   // capture: beat keepContentOnHub + forums.js
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') lgCloseLb(); });
    // Back gesture / button → close the lightbox (our pushed entry is being popped).
    window.addEventListener('popstate', function () { if (lgLb && lgLb.classList.contains('is-on')) lgCloseLb(true); });
  }

  // ── Hub filter drawer (Buck 2026-06-08: "filter button does nothing") ───────
  // The Filters chip was wired to #bb-ham, which only toggles a desktop class —
  // nothing showed on mobile. Present the real filter rail (.bb-layout__nav, the
  // hub-rail switches) as a slide-in drawer instead. Mobile only.
  function ensureFilterDrawerCss() {
    if (document.getElementById('lg-hubf-css')) return;
    var s = document.createElement('style'); s.id = 'lg-hubf-css';
    s.textContent = [
      '@media (max-width:640px){',
      '.bb-layout__nav{position:fixed!important;top:0!important;bottom:0!important;left:0!important;width:88%!important;max-width:340px!important;',
      'z-index:2147482300!important;transform:translateX(-105%);transition:transform .26s cubic-bezier(.4,0,.2,1);',
      'overflow:auto;-webkit-overflow-scrolling:touch;background:var(--lg-cream,#fbfbf8)!important;box-shadow:0 0 40px rgba(20,30,15,.4);',
      'margin:0!important;padding:14px 12px calc(22px + env(safe-area-inset-bottom,0px))!important}',
      'html.lg-hubf-open .bb-layout__nav{transform:translateX(0)}',
      '.lg-hubf-bd{position:fixed;inset:0;z-index:2147482200;background:rgba(26,29,26,.45);opacity:0;visibility:hidden;transition:opacity .22s,visibility .22s}',
      'html.lg-hubf-open .lg-hubf-bd{opacity:1;visibility:visible}',
      '.lg-hubf-x{display:flex;align-items:center;justify-content:center;width:34px;height:34px;margin:0 0 8px auto;border:0;',
      'border-radius:50%;background:var(--lg-sage-tint,#eef2e3);color:var(--lg-sage-d,#6b7c52);font-size:22px;line-height:1;cursor:pointer}',
      '}'
    ].join('');
    (document.head || document.documentElement).appendChild(s);
  }
  function wireFilterDrawer() {
    if (!window.matchMedia('(max-width:640px)').matches) return;
    if (document.body.getAttribute('data-lg-hubf')) return;
    document.body.setAttribute('data-lg-hubf', '1');
    ensureFilterDrawerCss();
    var bd = document.createElement('div'); bd.className = 'lg-hubf-bd'; document.body.appendChild(bd);
    function close() { document.documentElement.classList.remove('lg-hubf-open'); }
    function open() {
      var nav = document.querySelector('.bb-layout__nav');
      if (nav && !nav.querySelector('.lg-hubf-x')) {
        var x = document.createElement('button'); x.type = 'button'; x.className = 'lg-hubf-x'; x.setAttribute('aria-label', 'Close filters'); x.innerHTML = '&times;';
        x.addEventListener('click', close); nav.insertBefore(x, nav.firstChild);
      }
      document.documentElement.classList.add('lg-hubf-open');
    }
    bd.addEventListener('click', close);
    document.addEventListener('click', function (e) {
      var chip = e.target.closest && e.target.closest('.lg-filters-chip');
      if (!chip) return;
      e.preventDefault(); e.stopPropagation();                  // own the tap (skip the #bb-ham wiring)
      if (document.documentElement.classList.contains('lg-hubf-open')) close(); else open();
    }, true);
  }

  // ── Facebook-style reaction picker (Buck 2026-06-08: "match the format Facebook
  // uses"). Restyle the .fcr-palette popup into FB's rounded pill of big circular
  // emoji that pop/scale on press. Mobile; applies in the feed AND the replies
  // sheet (#looth-rep-sheet), so selectors aren't .feed-page-scoped. ───────────
  function ensureFbReactionsCss() {
    if (document.getElementById('lg-fbreact-css')) return;
    var s = document.createElement('style'); s.id = 'lg-fbreact-css';
    s.textContent = [
      '@media (max-width:640px){',
      // only style the palette when OPEN — forums.js hides it via [hidden]; an
      // unconditional display:flex!important kept every palette stuck open.
      '.fcr-palette[hidden]{display:none!important}',
      '.fcr-palette:not([hidden]){display:flex!important;gap:2px!important;padding:5px 8px!important;background:#fff!important;',
      'border:1px solid rgba(0,0,0,.06)!important;border-radius:999px!important;box-shadow:0 6px 22px rgba(0,0,0,.24)!important;width:max-content!important}',
      '.fcr-opt{width:44px!important;height:44px!important;padding:0!important;border:0!important;background:transparent!important;',
      'border-radius:50%!important;display:inline-flex!important;align-items:center;justify-content:center;line-height:1;',
      'font-size:30px!important;cursor:pointer;transform-origin:center bottom;transition:transform .14s cubic-bezier(.34,1.56,.64,1)!important}',
      '.fcr-opt:hover,.fcr-opt:active{background:transparent!important;transform:scale(1.45) translateY(-7px)!important}',
      '.fcr-opt .fcr-emoji{font-size:30px!important}',
      '.fcr-opt .fcr-img{width:32px!important;height:32px!important}',
      // reaction summary chips: tighter, FB-ish rounded
      '.fcr-chip{border-radius:999px!important;padding:2px 8px 2px 6px!important}',
      '.fcr-chip .fcr-emoji{font-size:16px!important}',
      // dark-mode picker surface (light emoji on a dark pill)
      'html[data-lguser-theme="dark"] .fcr-palette{background:#2a2e31!important;border-color:#3a3f3a!important}',
      'html[data-lguser-theme="dark"] .fcr-chip{background:#262b30!important;color:#e5e7e1!important}',
      // Buck 2026-06-08: show the FULL photo in the feed — no 3/2 height crop.
      // forums.css caps covers at aspect-ratio 3/2 + object-fit:cover; let them flow
      // at natural ratio so nothing is cut off (trade: some CLS as images load).
      '.feed-page .feed-card__cover-img,.feed-page .fc-cover img{aspect-ratio:auto!important;height:auto!important;max-height:none!important;object-fit:contain!important}',
      // Buck 2026-06-08: like button sits too far from the edge — pull the action
      // row closer to the screen edge (FB-style), keep the save bookmark flush right.
      '.feed-page .lg-card-actions{padding-left:7px!important;padding-right:10px!important;gap:20px!important}',
      // Instagram save bookmark — pushed to the right of the action row; fills when saved
      '.feed-page .lg-card-actions .lg-act-save{margin-left:auto}',
      '.feed-page .lg-card-actions .lg-act-save .ico{fill:none;stroke:currentColor}',
      '.feed-page .lg-card-actions .lg-act-save.is-on{color:var(--lguser-ink,#1a1d1a)}',
      '.feed-page .lg-card-actions .lg-act-save.is-on .ico{fill:currentColor;stroke:currentColor}',
      // Category icon before the label text — inherits the label color (currentColor,
      // so dark-mode-safe), optical-center nudge.
      '.feed-page .fc-category.lg-card-cat .lg-cat-ico,.feed-page .lg-card-cat .lg-cat-ico{display:inline-block;vertical-align:-2px;margin-right:5px;flex:0 0 auto;opacity:.85}',
      '.feed-page .lg-card-cat.lg-has-catico{display:inline-flex;align-items:center}',
      '}'
    ].join('');
    (document.head || document.documentElement).appendChild(s);
  }

  function run() {
    if (!onHubPath()) return;
    if (!document.querySelector('.feed-page')) return; // listing pages only
    ensureFbReactionsCss();
    wireImageLightbox();
    wireFilterDrawer();
    ensureImmersiveCss();
    ensureDesktopCss();
    wireReplyBodyExpand();
    wirePostBodyExpand();
    wireFastFilters();
    reopenFiltersIfFlagged();
    wireVideoAutoStop();
    wireVideoAutoplay();
    wireDesktopVideoHover();
    enforceSingleVideo();
    wireExpandLoadAll();
    addTagline();
    relocateFilterToggle();
    restyleSortBar();
    setupDesktopFilterNav();
    lgSyncSaved();
    wireFreshPill();
    buildTopSearch();
    wireHeaderAutoHide();
    keepContentOnHub();
    lgPolishCommentsFrame();
    relayCards(document);
    // Server-rendered cards carry data-lg-card, so relayCards skips them (forums.js
    // owns their expand/read-more). Their action bar is hub-polish-exclusive and
    // ships server-rendered now, so wire it here on first paint (no rebuild = no flash).
    var svrCards = document.querySelectorAll('.feed-card[data-lg-card]');
    for (var ci = 0; ci < svrCards.length; ci++) buildActions(svrCards[ci]);
    watchCards();
    hideEmptyFilterRows();
    applyFreshFeed();
    watchReplyImages();
    fbStyleComposer();
    // Transform the composer when it's opened (in case it mounts lazily).
    document.addEventListener('click', function (e) {
      if (e.target.closest && e.target.closest('.forum-header__new-post,.lg-newpost,[data-ntm-open]')) {
        setTimeout(fbStyleComposer, 0);
      }
    }, true);
    // The pre-paint bootstrap added html.lg-feed-booting (feed held at opacity:0)
    // so the canonical single-column → mosaic relayout above never shows as a snap.
    // Now that the first relayCards pass is done, fade the arranged feed in. The
    // bootstrap also clears this class on a safety timeout, so the feed can never
    // get stuck hidden if this code path ever fails to run.
    revealFeed();
  }

  // Drop the boot-hold class on the next frame so the opacity transition (defined
  // in the injected <head> <style>) animates the already-arranged feed in.
  function revealFeed() {
    var de = document.documentElement;
    if (!de.classList.contains('lg-feed-booting')) return;
    requestAnimationFrame(function () { de.classList.remove('lg-feed-booting'); });
  }

  function start() {
    if (!onHubPath()) return;
    if (document.body) run();
    else document.addEventListener('DOMContentLoaded', run);
  }
  start();
})();
