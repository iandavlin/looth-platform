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

  var css =
    '@media (max-width:640px){' +
      /* ---- Hero: taller, deeper scrim, bolder cream title ---- */
      '.feed-page .forum-header{position:relative;overflow:hidden}' +
      '.feed-page .forum-header--has-image{min-height:190px}' +
      '.feed-page .forum-header--explicit-image .forum-header__bg{opacity:.72}' +
      '.feed-page .forum-header::after{content:"";position:absolute;inset:0;z-index:1;' +
        'pointer-events:none;background:linear-gradient(165deg,' +
        'rgba(107,124,82,.32) 0%,rgba(26,29,26,.18) 46%,rgba(26,29,26,.64) 100%)}' +
      '.feed-page .forum-header__body{position:relative;z-index:2}' +
      '.feed-page .forum-header__title{font-size:30px;line-height:1.06;color:#fbfbf8;' +
        'letter-spacing:.01em;text-shadow:0 1px 14px rgba(26,29,26,.45),0 1px 2px rgba(26,29,26,.55)}' +
      '.feed-page .forum-header__label{color:var(--lg-amber,#ecb351);font-weight:700;' +
        'letter-spacing:.14em;text-shadow:0 1px 6px rgba(26,29,26,.55)}' +
      '.feed-page .' + TAGLINE_CLASS + '{display:block;margin-top:6px;max-width:32ch;' +
        'color:rgba(251,251,248,.94);font:500 13.5px/1.42 var(--lg-font-sans,system-ui,-apple-system,"Segoe UI",Roboto,sans-serif);' +
        'text-shadow:0 1px 8px rgba(26,29,26,.55)}' +

      /* ---- Feed: more air between cards ---- */
      '.feed-page .feed{gap:16px}' +

      /* ---- Card: app-card shell, soft float, press feedback ---- */
      '.feed-page .feed-card{border-radius:16px;overflow:hidden;border:1px solid var(--lg-line,#e3ddd0);' +
        'box-shadow:0 1px 2px rgba(26,29,26,.04),0 10px 22px -14px rgba(26,29,26,.30);' +
        'transition:transform .12s ease,box-shadow .15s ease}' +
      '.feed-page .feed-card:active{transform:scale(.992)}' +

      /* ---- Cover media: big + punchy, but cap monster portraits ---- */
      '.feed-page .feed-card__cover-img{aspect-ratio:4/5;max-height:440px;' +
        'width:100%;object-fit:cover;display:block}' +

      /* ---- Kind badge: cleaner, bolder pill (keeps category colors) ---- */
      '.feed-page .feed-card__kind-badge{margin-left:0;font-size:10px;font-weight:700;' +
        'letter-spacing:.06em;padding:3px 8px;border-radius:999px}' +

      /* ---- Overflow fix: the Hub feed is display:grid, but the track resolves
         to the WIDEST card''s min-content (grid items default to min-width:auto),
         so one card with an unshrinkable child blows the single column to ~760px
         and the whole document scrolls sideways. The canonical grid-blowout
         remedy: cap the track at minmax(0,1fr) AND let the cards shrink with
         min-width:0. Then the nowrap breadcrumb clips via its own overflow.
         (Pre-existing forums.css bug — present before this polish.) ---- */
      '.feed-page .feed{grid-template-columns:minmax(0,1fr)}' +
      '.feed-page .feed-card{min-width:0;max-width:100%}' +
      '.feed-page .feed-card__meta-top{min-width:0}' +
      '.feed-page .feed-card__forum-ctx{min-width:0;flex:0 1 auto}' +
      /* The replies block is ALSO a grid with the same min-content blowout
         (a reply preview ~620px wide won''t shrink). Same remedy. */
      '.feed-page .feed-card__replies{grid-template-columns:minmax(0,1fr)}' +
      '.feed-page .feed-card__replies>*{min-width:0;max-width:100%}' +
      '.feed-page .reply-stub{min-width:0}' +
      '.feed-page .reply-stub__head{min-width:0}' +
      '.feed-page .reply-stub__excerpt,.feed-page .reply-stub__body{min-width:0;overflow-wrap:anywhere}' +
      /* Long author/business names on a nowrap line overflow their flex row —
         let the row shrink and ellipsize the name. */
      '.feed-page .reply-stub__author{min-width:0;max-width:100%;overflow:hidden;' +
        'text-overflow:ellipsis;white-space:nowrap}' +

      /* ---- Titles + read-more affordance ---- */
      '.feed-page .feed-card__title{font-size:19px;line-height:1.22}' +
      '.feed-page .feed-card--content .feed-card__title{font-size:20px}' +
      '.feed-page .feed-card__read-more{font-weight:600;color:var(--lg-sage-d,var(--lguser-accent,#6b7c52))}' +
    '}' +

    /* ---- Mobile feed declutter (Instagram-feel): quiet the breadcrumb, tighten
       header rhythm, and DEMOTE the inline reply-stub thread (the main
       "forum-legacy" tell) to a single subtle "latest reply" snippet. <=640. ---- */
    '@media (max-width:640px){' +
      '.feed-page .feed-card__meta-top{padding:11px 13px 0;align-items:center}' +
      '.feed-page .feed-card__forum-ctx{font-size:11px;letter-spacing:.02em;' +
        'color:var(--lg-mute,var(--lguser-mute,#6b6f6b));overflow:hidden;text-overflow:ellipsis;white-space:nowrap}' +
      '.feed-page .feed-card__ctx-forum{color:var(--lg-ink,var(--lguser-ink,#323532));font-weight:600}' +
      '.feed-page .feed-card__time{font-size:11px;color:var(--lg-mute,var(--lguser-mute,#6b6f6b))}' +
      '.feed-page .feed-card__header-body{padding:8px 13px 13px}' +
      '.feed-page .feed-card__op-excerpt{font-size:14.5px;line-height:1.5;color:var(--lg-ink,var(--lguser-ink,#323532))}' +
      '.feed-page .feed-card__replies{margin:0 13px 13px;padding-top:11px;gap:8px;' +
        'border-top:1px solid var(--lg-line,#e3ddd0)}' +
      '.feed-page .reply-stub{background:var(--lg-sage-tint,#eef2e3);border-radius:12px;padding:9px 11px}' +
      '.feed-page .feed-card__replies .reply-stub ~ .reply-stub{display:none}' +
      '.feed-page .reply-stub__reply{display:none}' +
      '.feed-page .reply-stub__head{gap:7px;align-items:center}' +
      '.feed-page .reply-stub__author{font-size:12.5px;font-weight:600;color:var(--lg-ink,var(--lguser-ink,#323532))}' +
      '.feed-page .reply-stub__time{font-size:11px;color:var(--lg-mute,var(--lguser-mute,#6b6f6b))}' +
      '.feed-page .avatar-init{width:22px;height:22px;font-size:11px}' +
      '.feed-page .reply-stub__excerpt,.feed-page .reply-stub__body{font-size:13px;line-height:1.45;' +
        'color:var(--lg-ink,var(--lguser-ink,#323532));display:-webkit-box;-webkit-line-clamp:2;' +
        '-webkit-box-orient:vertical;overflow:hidden}' +
      '.feed-page .feed-card__expand{font-size:13px;font-weight:600;color:var(--lg-sage-d,var(--lguser-accent,#6b7c52))}' +
    '}' +

    /* ---- Hub control rail (ALL widths): pill the top-level Type rows + the
       category PARENTS; leave the LEAVES flat so the absence of a pill reads as
       the divider between a parent and its subforums (Ian''s ask). Type rows are
       direct children of .hub-rail__group; parents are .hub-acc__parent; leaves
       are .hub-acc__leaf. ---- */
    '.hub-rail__group>.hub-rail__row,' +
    '.hub-acc__parent{border:1px solid var(--lg-line,#e3ddd0);' +
      'border-radius:12px;background:var(--bg-card,#fff);margin:3px 0}' +
    '.hub-rail__group>.hub-rail__row:hover,' +
    '.hub-acc__parent:hover{border-color:var(--lg-sage-d,var(--lguser-accent,#6b7c52))}' +
    '.hub-rail__group>.hub-rail__row.is-on,' +
    '.hub-acc__parent.is-on{border-color:var(--lg-sage,#87986a)}' +
    /* leaves: no pill — an indented list under the open parent with a faint
       guide line tying them to the pill above. */
    '.hub-acc__leaves{margin:2px 0 6px 14px;' +
      'border-left:1px solid var(--lg-line,#e3ddd0)}' +
    '.hub-acc__leaf{border:0;border-radius:0;background:none}' +
    '.hub-acc__leaf:hover{background:var(--lg-sage-tint,#eef2e3)}' +
    '.hub-acc__parent .hub-acc__chev{margin-right:2px}' +

    /* ---- Mobile filter access: the canonical corner-hamburger (#bb-ham) that
       opens the filter drawer is relocated by JS (below) into the feed sort bar
       as a labelled "Filters" chip, because at the top-left corner it overlaps
       and swallows taps meant for the shared site-header hamburger. Reset its
       fixed-corner styling to an inline sage chip that matches the sort row. ---- */
    '.feed-sort-bar .lg-filters-chip{position:static;width:auto;height:auto;' +
      'display:inline-flex;align-items:center;gap:6px;clip-path:none;' +
      'background:var(--lg-sage,#87986a);color:#fff;border:0;border-radius:999px;' +
      'padding:6px 13px;box-shadow:none;cursor:pointer;margin-right:2px;' +
      'font:600 13px/1 var(--lg-font-sans,system-ui,-apple-system,"Segoe UI",Roboto,sans-serif)}' +
    '.feed-sort-bar .lg-filters-chip .corner-hamburger__icon{font-size:13px;line-height:1}' +
    '.feed-sort-bar .lg-filters-chip:active{background:var(--lg-sage-d,var(--lguser-accent,#6b7c52))}' +

    /* ====================================================================
       STYLE SANDBOX WINNER — Buck's chosen Hub look (picked 2026-06-06 in
       hub-styles/index.html, the Style Sandbox): theme "Sage Tint" + the
       "Comfort" cover format (3:2) + "Trebuchet" type. Applied at ALL widths
       (Buck reviews the Hub on wider-than-phone views too, so the look must be
       live there as well, not just <=640); scoped to .feed-page / the Hub sort
       bar, so brand tokens elsewhere on the site are untouched.
       NOTE: per-category kind-badge colors are intentionally preserved (the
       sandbox never exercised them) — only the rest of the palette shifts.
       ==================================================================== */
      /* Sage Tint ground showing through the feed gaps */
      '.feed-page{background:var(--lguser-bg,#e9eedd)}' +
      /* Trebuchet throughout the Hub feed (headings + body). Trebuchet MS
         exists on Windows/Mac (desktop keeps the exact face Buck reviewed);
         phones have no Trebuchet, so they fall to the Cabin webfont (loaded by
         injectFont) — the closest free match — instead of the system sans. */
      '.feed-page,' +
      '.feed-page .forum-header__title,' +
      '.feed-page .feed-card__title,' +
      '.feed-page .feed-card__op-excerpt,' +
      '.feed-page .reply-stub__excerpt,.feed-page .reply-stub__body{' +
        'font-family:var(--lguser-font,"Trebuchet MS","Cabin","Segoe UI",sans-serif)}' +
      /* Sage Tint card shell: 14px radius, sage-green hairline */
      '.feed-page .feed-card{border-radius:14px;border-color:var(--lguser-line,#cdd6ba)}' +
      /* Comfort covers: roomy 3:2 instead of the tall 4:5 cap */
      '.feed-page .feed-card__cover-img{aspect-ratio:3/2;max-height:360px}' +
      /* Sage Tint accents (read-more, reply tint + divider) */
      '.feed-page .feed-card__read-more,.feed-page .feed-card__expand{color:var(--lguser-accent-d,#52613d)}' +
      '.feed-page .feed-card__replies{border-top-color:var(--lguser-line,#cdd6ba)}' +
      '.feed-page .reply-stub{background:var(--lguser-pill,#dde6c7)}' +
    /* Filters chip to the Sage Tint accent (Hub sort bar, all widths) */
    '.feed-sort-bar .lg-filters-chip{background:var(--lguser-accent,#6b7c52)}' +
    '.feed-sort-bar .lg-filters-chip:active{background:var(--lguser-accent-d,#52613d)}' +

    /* ====================================================================
       STYLE SANDBOX CARD LAYOUT — recompose each feed card into the chosen
       sandbox shape (hub-styles/index.html): a top meta row [OP avatar +
       author . time | category pill], the cover image lifted ABOVE the title,
       and the redundant bottom "Started by ..." line slimmed to its Reply
       action. The avatar/author live in .feed-card__op-meta (bottom) and the
       category in the top breadcrumb, so relayCard() (JS below) moves those
       nodes across branches; these rules style the result. Scoped to
       .feed-page; all widths. NOTE: the category pill is a single Sage-Tint
       tint here (matches the sandbox) — it intentionally REPLACES the live
       per-category kind-badge colors with one calm tint.
       ==================================================================== */
    '.feed-page .feed-card__meta-top{display:flex;align-items:center;gap:8px;' +
      'padding:11px 13px 0;min-width:0}' +
    '.feed-page .feed-card__meta-top .lg-card-avatar{flex:0 0 auto;width:30px;' +
      'height:30px;border-radius:50%;object-fit:cover;background:var(--lguser-accent,#6b7c52)}' +
    '.feed-page .lg-card-id{min-width:0;flex:1 1 auto;color:var(--lguser-mute,#6b6f6b);' +
      'font:500 11.5px/1.3 var(--lg-font-sans,system-ui,-apple-system,"Segoe UI",Roboto,sans-serif);' +
      'overflow:hidden;text-overflow:ellipsis;white-space:nowrap}' +
    '.feed-page .lg-card-author{color:var(--lguser-ink,#323532);font-weight:600;text-decoration:none}' +
    '.feed-page .lg-card-time{flex:0 0 auto;color:var(--lguser-mute,#6b6f6b);white-space:nowrap;' +
      'font:500 11px/1 var(--lg-font-sans,system-ui,-apple-system,"Segoe UI",Roboto,sans-serif)}' +
    '.feed-page .lg-card-time::before{content:"\\00b7";margin:0 5px;color:#9aa093}' +
    '.feed-page .feed-card__meta-top .lg-card-cat{flex:0 0 auto;margin-left:auto;' +
      'letter-spacing:.06em;text-transform:uppercase;padding:4px 8px;border-radius:999px;' +
      'font:700 10px/1 var(--lg-font-sans,system-ui,-apple-system,"Segoe UI",Roboto,sans-serif);' +
      'background:var(--lguser-pill,#dde6c7);color:var(--lguser-accent-d,#52613d);white-space:nowrap;border:0}' +
    /* Cover lifted above the title (CSS order; cover + body are header siblings) */
    '.feed-page .feed-card__header{display:flex;flex-direction:column}' +
    '.feed-page .feed-card__cover{order:-1;margin:11px 0 0}' +
    /* Title sized to the sandbox card */
    '.feed-page .feed-card__title{font-size:18px;line-height:1.22;margin:0 0 5px}' +
    '.feed-page .feed-card__title a{color:var(--lguser-ink,#1a1d1a);text-decoration:none}' +
    /* Slim the now-redundant bottom started-by line to just its Reply action */
    '.feed-page .feed-card[data-lg-card] .feed-card__op-meta{margin-top:10px}' +
    '.feed-page .feed-card[data-lg-card] .feed-card__op-meta > img,' +
    '.feed-page .feed-card[data-lg-card] .feed-card__op-meta > span{display:none}' +

    /* ====================================================================
       CARD ACTION ROW (Like / N replies / Share) — the sandbox card footer
       (hub-styles/index.html .feed-card__actions). Built in JS (buildActions)
       and inserted before the replies block. MOBILE ONLY (<=640px): hidden by
       default so the DESKTOP card is left exactly as-is per Buck; shown only in
       the media query. On mobile it also hides the now-redundant card-body
       "Read more" + inline Reply so the body matches the sandbox (excerpt ->
       action row). Like + Share are visual affordances (Share uses the Web
       Share API when present); "N replies" expands the inline replies. Like is
       not yet persisted to a reactions backend (coordinator lane).
       ==================================================================== */
    '.feed-page .lg-card-actions{display:none}' +
    '@media (max-width:640px){' +
      '.feed-page .lg-card-actions{display:flex;gap:18px;padding:8px 13px 12px;' +
        'color:var(--lguser-mute,#6b6f6b);font:600 12.5px/1 var(--lg-font-sans,system-ui,-apple-system,"Segoe UI",Roboto,sans-serif)}' +
      '.feed-page .lg-card-actions .lg-act{display:inline-flex;align-items:center;gap:5px;' +
        'background:none;border:0;padding:0;margin:0;color:inherit;font:inherit;cursor:pointer}' +
      '.feed-page .lg-card-actions .ico{width:16px;height:16px;flex:0 0 auto}' +
      '.feed-page .lg-card-actions .lg-act-like.is-on{color:#c66845}' +
      '.feed-page .lg-card-actions .lg-act-like.is-on .ico{fill:#c66845}' +
      '.feed-page .feed-card[data-lg-card] .feed-card__read-more,' +
      '.feed-page .feed-card[data-lg-card] .feed-card__reply-cta--inline{display:none}' +
    '}' +

    /* ====================================================================
       SORT BAR -> sandbox shape (hub-styles/index.html .feed-sort-bar):
       pill tabs on the left, then Filters + "+ New post" pushed to the right,
       inline search hidden. MOBILE ONLY (<=640px) — desktop bar is left as-is.
       The "+ New post" lives in the hero on the live page; restyleSortBar()
       (JS) clones it into the bar wired to the original's click, so desktop
       keeps the hero button and only mobile shows it in the bar.
       ==================================================================== */
    '.feed-page .feed-sort-bar > .lg-newpost{display:none}' +
    '@media (max-width:640px){' +
      '.feed-page .feed-sort-bar{display:flex;align-items:center;gap:6px;flex-wrap:nowrap}' +
      '.feed-page .feed-sort-bar > a{order:1;border-radius:999px;padding:7px 12px;' +
        'font:600 13px/1 var(--lg-font-sans,system-ui,-apple-system,"Segoe UI",Roboto,sans-serif);' +
        'color:var(--lguser-mute,#6b6f6b);text-decoration:none;white-space:nowrap}' +
      '.feed-page .feed-sort-bar > a.active{background:var(--lguser-pill,#dde6c7);color:var(--lguser-accent-d,#52613d)}' +
      '.feed-page .feed-sort-bar > .lg-filters-chip{order:2;margin-left:auto}' +
      '.feed-page .feed-sort-bar > .lg-newpost{order:3;display:inline-flex;align-items:center;' +
        'gap:5px;background:var(--lguser-accent-d,#52613d);color:#fff;border:0;border-radius:999px;padding:7px 13px;' +
        'font:700 13px/1 var(--lg-font-sans,system-ui,-apple-system,"Segoe UI",Roboto,sans-serif);' +
        'white-space:nowrap;margin:0}' +
      '.feed-page .feed-sort-bar .feed-toolbar-search{display:none}' +
      '.feed-page .forum-header__new-post{display:none}' +
    '}' +

    /* TEXT SIZE: scale the Hub's readable text by the user size setting
       (--lguser-scale from app-settings; default 1 = unchanged). The Hub uses
       px font-sizes, so the rem-base scale alone wasn't visible. All widths. */
    '.feed-page .feed-card__title{font-size:calc(18px*var(--lguser-scale,1))}' +
    '.feed-page .feed-card--content .feed-card__title{font-size:calc(20px*var(--lguser-scale,1))}' +
    '.feed-page .feed-card__op-excerpt,.feed-page .feed-card__full-body{font-size:calc(14.5px*var(--lguser-scale,1))}' +
    '.feed-page .reply-stub__excerpt,.feed-page .reply-stub__body{font-size:calc(13px*var(--lguser-scale,1))}' +
    '.feed-page .reply-stub__author{font-size:calc(12.5px*var(--lguser-scale,1))}' +
    '.feed-page .lg-card-actions{font-size:calc(12.5px*var(--lguser-scale,1))}' +
    '.feed-page .lg-card-id{font-size:calc(11.5px*var(--lguser-scale,1))}' +
    '.feed-page .feed-card__expand{font-size:calc(13px*var(--lguser-scale,1))}' +

    /* READ FULL REPLIES: the base style clamps reply text to 2 lines (a teaser).
       Once expanded, show the FULL reply so people are actually readable. Higher
       specificity (.feed-card__replies-full ...) beats the clamp rule. */
    '.feed-page .feed-card__replies-full .reply-stub__excerpt,' +
    '.feed-page .feed-card__replies-full .reply-stub__body{display:block;' +
      '-webkit-line-clamp:unset;overflow:visible;max-height:none}' +

    /* REACTIONS: long-press the Like to pick a reaction (Instagram/FB style).
       Cosmetic for now (no reactions backend yet — coordinator lane). */
    '.feed-page .lg-act-like{position:relative}' +
    '.feed-page .lg-act-like .lg-react-emoji{font-size:16px;line-height:1;display:inline-flex}' +
    '.lg-react-bar{position:absolute;bottom:calc(100% + 9px);left:-8px;display:flex;gap:1px;' +
      'padding:5px 8px;background:#fff;border:1px solid var(--lg-line,#e3ddd0);border-radius:999px;' +
      'box-shadow:0 8px 26px rgba(26,29,26,.20);opacity:0;transform:translateY(7px) scale(.9);' +
      'transform-origin:bottom left;transition:opacity .15s ease,transform .16s cubic-bezier(.3,1.3,.5,1);' +
      'z-index:60;white-space:nowrap}' +
    '.lg-react-bar.is-open{opacity:1;transform:translateY(0) scale(1)}' +
    '.lg-react{all:unset;cursor:pointer;font-size:25px;line-height:1;padding:3px 5px;border-radius:50%;' +
      'transition:transform .12s ease}' +
    '.lg-react:hover,.lg-react:active{transform:scale(1.3) translateY(-2px)}' +
    /* React control on each reply (tap = like, long-press = reaction picker) */
    '.feed-page .lg-reply-react{position:relative;margin-left:auto;cursor:pointer;display:inline-flex;' +
      'align-items:center;padding:2px 3px;color:var(--lg-mute,var(--lguser-mute,#6b6f6b));flex:0 0 auto}' +
    '.feed-page .lg-reply-react .ico{width:15px;height:15px}' +
    '.feed-page .lg-reply-react.is-on{color:#c66845}' +
    '.feed-page .lg-reply-react.is-on .ico{fill:#c66845}' +
    '.feed-page .lg-reply-react .lg-react-emoji{font-size:14px;line-height:1}' +

    /* Inline reply preview: show up to 3 replies on the feed card, with a
       "View all N replies" to expand the rest (lazy-loaded as cards scroll in). */
    /* The canonical reply rendering hides replies past the first; force the ones
       we want shown visible, and hide the rest, both with !important to win. */
    '.feed-page .feed-card__replies-full .reply-stub.lg-rshow{display:flex!important}' +
    '.feed-page .feed-card__replies-full .reply-stub.lg-rhide{display:none!important}' +
    /* a hair more breathing room between replies */
    '.feed-page .feed-card__replies-full .reply-stub{margin-bottom:10px}' +
    '.feed-page .lg-viewall{display:block;width:100%;text-align:left;background:none;border:0;' +
      'cursor:pointer;padding:9px 4px 2px;margin:0;' +
      'font:600 13px/1.3 var(--lg-font-sans,system-ui,-apple-system,"Segoe UI",sans-serif);' +
      'color:var(--lguser-accent-d,#52613d)}' +
    /* Drop the Newest/Oldest reply sort on the feed — just show them all */
    '@media (max-width:640px){.feed-page .feed-card__replies-full .replies-sort{display:none}}' +

    /* ===== Facebook-style comments ===== */
    /* Apply to ALL reply stubs incl. the collapsed teaser, so the look is the
       same collapsed vs expanded (no green background that changes on unfold). */
    '.feed-page .feed-card .reply-stub{display:flex;gap:8px;align-items:flex-start;' +
      'background:none!important;padding:0;border:0;margin-bottom:12px}' +
    '.feed-page .feed-card .reply-stub .avatar-init{width:32px;height:32px;' +
      'border-radius:50%;flex:0 0 auto;font-size:13px}' +
    '.feed-page .lg-fb-col{display:flex;flex-direction:column;min-width:0;flex:1 1 auto;align-items:flex-start}' +
    '.feed-page .lg-fb-bubble{background:var(--lguser-bubble,#eceff3);border-radius:16px;padding:7px 12px;max-width:100%}' +
    '.feed-page .lg-fb-name{display:block;font-weight:700;font-size:13px;color:#1a1d1a;' +
      'text-decoration:none;margin:0 0 1px}' +
    '.feed-page .lg-fb-bubble .reply-stub__body{margin:0}' +
    '.feed-page .lg-fb-bubble .reply-stub__excerpt,.feed-page .lg-fb-bubble .reply-stub__body{' +
      'font-size:calc(14px*var(--lguser-scale,1));line-height:1.4;color:#1a1d1a}' +
    '.feed-page .lg-fb-bubble .reply-stub__img{margin-top:6px;border-radius:12px}' +
    '.feed-page .lg-fb-actions{display:flex;align-items:center;gap:16px;padding:4px 12px 0;' +
      'font:600 12.5px/1 var(--lg-font-sans,system-ui,-apple-system,"Segoe UI",sans-serif);color:#65676b}' +
    '.feed-page .lg-fb-act{cursor:pointer;position:relative}' +
    '.feed-page .lg-fb-like.is-on{color:var(--lguser-accent-d,#52613d)}' +
    '.feed-page .lg-fb-time{font-weight:400;color:#8a8d91}' +
    '.feed-page .lg-fb-like .lg-react-emoji{font-size:14px;margin-right:3px}' +
    '.feed-page .lg-fb-replybox{display:flex;gap:8px;align-items:flex-start;margin:8px 0 2px;width:100%}' +
    '.feed-page .lg-fb-myavi{width:28px;height:28px;border-radius:50%;overflow:hidden;flex:0 0 auto;' +
      'background:var(--lg-sage-tint,#eef2e3)}' +
    '.feed-page .lg-fb-myavi img{width:100%;height:100%;object-fit:cover;display:block}' +
    '.feed-page .lg-fb-replywrap{display:flex;align-items:flex-end;gap:6px;flex:1 1 auto;' +
      'background:var(--lguser-bubble,#eceff3);border-radius:18px;padding:4px 6px 4px 12px}' +
    '.feed-page .lg-fb-replyinput{flex:1 1 auto;border:0;background:none;resize:none;outline:none;' +
      'font:14px/1.4 var(--lg-font-sans,system-ui,sans-serif);color:#1a1d1a;max-height:120px;padding:4px 0}' +
    '.feed-page .lg-fb-send{flex:0 0 auto;border:0;background:none;cursor:pointer;' +
      'color:var(--lguser-accent-d,#52613d);font:700 13px/1 var(--lg-font-sans,system-ui,sans-serif);padding:6px 8px}' +
    '.feed-page .lg-fb-send:disabled{color:#b0b3b8;cursor:default}' +
    '.feed-page .lg-fb-note{font:12px/1.3 var(--lg-font-sans,system-ui,sans-serif);color:#8a8d91;padding:4px 0 0 36px;width:100%}' +

    /* ===== Facebook-style "write a post" composer (#ntm-form) ===== */
    '.lg-fbc .lg-fbc-head{display:flex;align-items:center;gap:10px;margin:2px 0 10px}' +
    '.lg-fbc .lg-fbc-avi{width:40px;height:40px;border-radius:50%;overflow:hidden;flex:0 0 auto;' +
      'background:var(--lg-sage-tint,#eef2e3)}' +
    '.lg-fbc .lg-fbc-avi img{width:100%;height:100%;object-fit:cover;display:block}' +
    '.lg-fbc .lg-fbc-name{font:700 14px/1.2 var(--lg-font-serif,Georgia,serif);color:var(--lg-charcoal,#1a1d1a)}' +
    '.lg-fbc #ntm-content,.lg-fbc .ql-editor{min-height:96px;font-size:16px;line-height:1.45}' +
    '.lg-fbc .ql-editor.ql-blank::before{font-size:16px;color:var(--lg-mute,#8a8d91);font-style:normal}' +
    '.lg-fbc .lg-fbc-chips{margin:12px 0 4px}' +
    '.lg-fbc .lg-fbc-chips__h{font:700 11.5px/1 var(--lg-font-sans,system-ui,sans-serif);' +
      'letter-spacing:.05em;text-transform:uppercase;color:var(--lg-mute,#6b6f6b);margin:0 0 8px}' +
    '.lg-fbc .lg-fbc-chiprow{display:flex;flex-wrap:wrap;gap:7px;max-height:132px;overflow:auto}' +
    '.lg-fbc .lg-fbc-chip{border:1px solid var(--lg-line,#e3ddd0);background:#fff;border-radius:999px;' +
      'padding:7px 12px;font:600 12.5px/1 var(--lg-font-sans,system-ui,sans-serif);' +
      'color:var(--lg-ink,#323532);cursor:pointer;white-space:nowrap}' +
    '.lg-fbc .lg-fbc-chip.is-on{background:var(--lguser-accent,#6b7c52);color:#fff;' +
      'border-color:var(--lguser-accent,#6b7c52)}' +

    /* ====================================================================
       TOP SEARCH BAR (mobile) — slim the shared lg-chrome header on the app
       down to a single search "bubble" that live-searches the Hub as you
       type. The canonical header packs a hamburger + logo + (hidden) nav +
       an aside of icon buttons (search/messages/notifs/account); on a phone
       the bottom tab bar (bottom-nav.js) already covers navigation and the
       "You" sheet carries the menu links, so the top row is free to become
       just search. We hide the hamburger, logo, and the whole aside (its
       account <img> stays in the DOM for bottom-nav's avatar read, since
       display:none doesn't detach it) and drop in .lg-hub-search, a pill
       input that queries the suggest endpoint /hub/?suggest=hub&q=… and
       drops a results panel below the header. MOBILE ONLY; built hub-gated in
       JS (buildTopSearch). ==================================================== */
    '@media (max-width:640px){' +
      '#site-header .lg-chrome__hamburger,' +
      '#site-header .lg-chrome__logo,' +
      '#site-header .lg-chrome__aside{display:none!important}' +
      '#site-header .lg-chrome__inner{gap:0;padding-left:12px;padding-right:12px}' +
      /* Auto-hide on scroll-down, reveal on scroll-up (set by wireHeaderAutoHide).
         The header is already position:sticky;top:0 site-wide, so a translateY
         tuck slides it cleanly above the viewport. */
      '#site-header.lg-chrome{transition:transform .26s ease;will-change:transform}' +
      '#site-header.lg-chrome.lg-chrome--tuck{transform:translateY(-100%)}' +
      '.lg-hub-search{display:flex;align-items:center;gap:9px;flex:1 1 auto;min-width:0;' +
        'background:#fff;border:1px solid var(--lg-line,#e3ddd0);border-radius:999px;' +
        'padding:9px 15px;box-shadow:0 1px 2px rgba(26,29,26,.04)}' +
      '.lg-hub-search__ico{width:18px;height:18px;flex:0 0 auto;' +
        'color:var(--lg-mute,#6b6f6b)}' +
      '.lg-hub-search input{flex:1 1 auto;min-width:0;border:0;outline:0;background:none;padding:0;margin:0;' +
        'font:15px/1.2 var(--lg-font-sans,system-ui,-apple-system,"Segoe UI",Roboto,sans-serif);' +
        'color:var(--lg-ink,#323532);-webkit-appearance:none}' +
      '.lg-hub-search input::placeholder{color:var(--lg-mute,#6b6f6b)}' +
      '.lg-hub-search input::-webkit-search-cancel-button{-webkit-appearance:none}' +
      '.lg-hub-search__panel{position:fixed;left:0;right:0;z-index:2147481250;display:none;' +
        'max-height:72vh;overflow:auto;-webkit-overflow-scrolling:touch;' +
        'background:var(--lg-cream,#fbfbf8);border-top:1px solid var(--lg-line,#e3ddd0);' +
        'box-shadow:0 14px 34px rgba(26,29,26,.18)}' +
      '.lg-hub-search__panel.is-open{display:block}' +
      '.lg-hub-search__item{display:flex;flex-direction:column;gap:3px;padding:12px 16px;' +
        'text-decoration:none;border-bottom:1px solid var(--lg-line,#e3ddd0)}' +
      '.lg-hub-search__item:active{background:var(--lg-sage-tint,#eef2e3)}' +
      '.lg-hub-search__k{font:700 10px/1 var(--lg-font-sans,system-ui,-apple-system,"Segoe UI",sans-serif);' +
        'letter-spacing:.07em;text-transform:uppercase;color:var(--lg-sage-d,#6b7c52)}' +
      '.lg-hub-search__t{font:600 14.5px/1.32 var(--lg-font-sans,system-ui,-apple-system,"Segoe UI",sans-serif);' +
        'color:var(--lg-ink,#323532)}' +
      '.lg-hub-search__note{padding:15px 16px;color:var(--lg-mute,#6b6f6b);' +
        'font:14px/1.4 var(--lg-font-sans,system-ui,-apple-system,"Segoe UI",sans-serif)}' +
    '}' +

    /* Mobile: drop the Hub hero banner entirely — straight to the feed. */
    '@media (max-width:640px){.feed-page .forum-header{display:none!important}}';

  function injectStyles() {
    if (document.getElementById(STYLE_ID)) return;
    var s = document.createElement('style');
    s.id = STYLE_ID;
    s.textContent = css;
    (document.head || document.documentElement).appendChild(s);
  }

  // Load the Cabin webfont so phones (which have no Trebuchet MS) render a
  // close Trebuchet-like face instead of falling back to the system sans.
  // Trebuchet stays first in the font stack, so Windows/Mac are unaffected.
  // display=swap avoids blocking paint. Idempotent.
  var FONT_LINK_ID = 'lg-hub-font';
  function injectFont() {
    if (document.getElementById(FONT_LINK_ID)) return;
    var head = document.head || document.documentElement;
    var pre1 = document.createElement('link');
    pre1.rel = 'preconnect';
    pre1.href = 'https://fonts.googleapis.com';
    head.appendChild(pre1);
    var pre2 = document.createElement('link');
    pre2.rel = 'preconnect';
    pre2.href = 'https://fonts.gstatic.com';
    pre2.crossOrigin = 'anonymous';
    head.appendChild(pre2);
    var link = document.createElement('link');
    link.id = FONT_LINK_ID;
    link.rel = 'stylesheet';
    link.href = 'https://fonts.googleapis.com/css2?family=Cabin:wght@400;500;600;700&display=swap';
    head.appendChild(link);
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
    var ham = document.getElementById('bb-ham');
    var bar = document.querySelector('.feed-sort-bar');
    if (!ham || !bar || ham.getAttribute('data-lg-relocated')) return;
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

  // Long-press the Like to open a reaction picker (Instagram/FB style). Cosmetic
  // for now — sets the chosen emoji on the Like; not persisted to a backend yet.
  var REACTIONS = ['👍', '❤️', '🔥', '🤘', '😆', '😮'];
  function wireReactions(likeEl, card) {
    var timer = null, longPressed = false, bar = null;
    function openBar() {
      if (bar) return;
      longPressed = true;
      bar = document.createElement('div');
      bar.className = 'lg-react-bar';
      REACTIONS.forEach(function (em) {
        var b = document.createElement('button');
        b.type = 'button'; b.className = 'lg-react'; b.textContent = em;
        b.addEventListener('click', function (ev) { ev.stopPropagation(); ev.preventDefault(); choose(em); });
        bar.appendChild(b);
      });
      likeEl.appendChild(bar);
      requestAnimationFrame(function () { if (bar) bar.classList.add('is-open'); });
      setTimeout(function () { document.addEventListener('pointerdown', outside, true); }, 0);
    }
    function outside(ev) { if (bar && !bar.contains(ev.target)) closeBar(); }
    function closeBar() { if (bar) { bar.remove(); bar = null; } document.removeEventListener('pointerdown', outside, true); }
    function choose(em) {
      likeEl.classList.add('is-on');
      var ico = likeEl.querySelector('.ico');
      if (ico) ico.style.display = 'none';
      var es = likeEl.querySelector('.lg-react-emoji');
      if (!es) { es = document.createElement('span'); es.className = 'lg-react-emoji'; likeEl.insertBefore(es, likeEl.firstChild); }
      es.textContent = em;
      closeBar();
    }
    likeEl.addEventListener('pointerdown', function () { longPressed = false; clearTimeout(timer); timer = setTimeout(openBar, 350); });
    ['pointerup', 'pointerleave', 'pointercancel'].forEach(function (evt) {
      likeEl.addEventListener(evt, function () { clearTimeout(timer); });
    });
    // capture-phase: if it was a long-press, swallow the click so the normal
    // Like toggle doesn't also fire — but let clicks on the reaction emojis
    // (inside the bar) through so a pick registers.
    likeEl.addEventListener('click', function (ev) {
      if (bar && bar.contains(ev.target)) return;
      if (longPressed) { ev.stopImmediatePropagation(); ev.preventDefault(); longPressed = false; }
    }, true);
  }

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
  function buildActions(card) {
    if (card.querySelector('.lg-card-actions')) return;
    var n = replyCount(card);
    var label = n === 0 ? 'Reply' : (n === 1 ? '1 reply' : n + ' replies');
    var row = document.createElement('div');
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

    var like = row.querySelector('.lg-act-like');
    like.addEventListener('click', function () { like.classList.toggle('is-on'); });
    wireReactions(like, card);
    var rep = row.querySelector('.lg-act-replies');
    rep.addEventListener('click', function () {
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
    share.addEventListener('click', function () {
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
      if (!card || card.getAttribute('data-lg-card')) return;
      var metaTop = card.querySelector('.feed-card__meta-top');
      if (!metaTop) return;
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

  // Add a react control to each loaded reply: tap = like toggle, long-press =
  // emoji reaction picker (reuses wireReactions). Cosmetic — no backend yet.
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
      wireReactions(like, stub);
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
    wireReactions(like, el);
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

  function capPreview(card, full, exp, total) {
    if (!card.getAttribute('data-lg-capped')) {
      card.setAttribute('data-lg-capped', '1');
      var stubs = full.querySelectorAll('.reply-stub');
      for (var i = 0; i < stubs.length; i++) {
        // force-show the first 3 (native hides some), hide the rest
        if (i < 3) { stubs[i].classList.remove('lg-rhide'); stubs[i].classList.add('lg-rshow'); }
        else { stubs[i].classList.remove('lg-rshow'); stubs[i].classList.add('lg-rhide'); }
      }
      if (exp) exp.style.display = 'none';
      var lm = full.querySelector('.replies-loadmore');
      if (lm) lm.style.display = 'none';
      if (total > 3 && !full.querySelector('.lg-viewall')) {
        var va = document.createElement('button');
        va.type = 'button'; va.className = 'lg-viewall';
        va.textContent = 'View all ' + total + ' replies';
        va.addEventListener('click', function () {
          card.removeAttribute('data-lg-capped');
          var all = full.querySelectorAll('.reply-stub');
          for (var k = 0; k < all.length; k++) { all[k].classList.remove('lg-rhide'); all[k].classList.add('lg-rshow'); }
          if (lm) lm.style.display = '';
          if (exp) exp.style.display = '';
          va.remove();
          autoLoadReplies(card, 10);
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

    [forumSel, titleIn, tagsIn].forEach(function (el) {
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

    forumSel.value = '3837'; // default: General

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

    // category chips
    var chipWrap = document.createElement('div'); chipWrap.className = 'lg-fbc-chips';
    chipWrap.innerHTML = '<div class="lg-fbc-chips__h">Add to</div>';
    var chipRow = document.createElement('div'); chipRow.className = 'lg-fbc-chiprow';
    [].slice.call(forumSel.options).forEach(function (o) {
      if (!o.value) return;
      var c = document.createElement('button');
      c.type = 'button'; c.className = 'lg-fbc-chip'; c.setAttribute('data-fid', o.value);
      c.textContent = o.textContent.trim();
      c.addEventListener('click', function () { c.classList.toggle('is-on'); syncChips(); });
      chipRow.appendChild(c);
    });
    chipWrap.appendChild(chipRow);
    var actionsRow = submit.parentNode;
    form.insertBefore(chipWrap, actionsRow === form ? submit : actionsRow);

    function syncChips() {
      var on = [].slice.call(chipRow.querySelectorAll('.lg-fbc-chip.is-on'));
      forumSel.value = on.length ? on[0].getAttribute('data-fid') : '3837';
      if (tagsIn) tagsIn.value = on.map(function (c) { return c.textContent.trim(); }).join(', ');
    }
    function bodyText() {
      if (body && 'value' in body && body.value) return body.value;
      var q = form.querySelector('.ql-editor');
      return q ? (q.textContent || '').trim() : '';
    }
    // Right before the canonical submit: auto title + ensure a forum.
    submit.addEventListener('click', function () {
      if (titleIn && !titleIn.value.trim()) {
        var b = bodyText();
        titleIn.value = ((b ? b.split(/\n/)[0].slice(0, 80) : '') || '').trim() || 'New post';
      }
      if (!forumSel.value) forumSel.value = '3837';
    }, true);
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

  // ── Fresh Feed (mobile default) ─────────────────────────────────────────────
  // On the default Hub landing (no ?sort=…), present a weighted shuffle so the
  // feed feels new every open: the newest few stay pinned at the top, the rest
  // are sprinkled by recency + popularity + a per-open random jitter. Explicit
  // sort tabs (?sort=new|old|hot) opt out and keep their exact server order.
  // Mobile only. Runs deliberately (initial render + after a filter reconcile);
  // never on every card mutation, so a session's order stays stable.
  function freshActive() {
    if (!window.matchMedia('(max-width:640px)').matches) return false;
    try { return !new URLSearchParams(location.search).get('sort'); } catch (e) { return false; }
  }
  function applyFreshFeed(feed) {
    try {
      if (!freshActive()) return;
      feed = feed || document.querySelector('.feed');
      if (!feed) return;
      var cards = [].slice.call(feed.querySelectorAll('.feed-card'));
      if (cards.length < 6) return;                       // too small to bother
      var PIN = 3, SPREAD = 10;
      var anchor = cards[cards.length - 1].nextSibling;   // keep any infinite-scroll sentinel in place
      var pinned = cards.slice(0, PIN), rest = cards.slice(PIN);
      rest.forEach(function (c, i) {
        var replies = parseInt(c.getAttribute('data-reply-count'), 10) || 0;
        var pop = Math.min(replies, 12) * 1.2;            // popular floats up
        c.__fresh = i + (Math.random() * 2 - 1) * SPREAD - pop;  // lower = higher
      });
      rest.sort(function (a, b) { return a.__fresh - b.__fresh; });
      var frag = document.createDocumentFragment();
      pinned.concat(rest).forEach(function (c) { frag.appendChild(c); });
      feed.insertBefore(frag, anchor);                    // re-insert in new order
    } catch (e) {}
  }

  // Hide filter rows whose count is 0 (e.g. empty "Local Looths") so the drawer
  // only lists categories that actually have content. Mobile only. Idempotent.
  function hideEmptyFilterRows() {
    try {
      if (!window.matchMedia('(max-width:640px)').matches) return;
      [].slice.call(document.querySelectorAll('.hub-rail__row')).forEach(function (row) {
        var ct = row.querySelector('.hub-rail__ct');
        if (ct && ct.textContent.trim() === '0') row.style.display = 'none';
      });
    } catch (e) {}
  }

  // Add a "Fresh" tab to the sort bar as the default mobile mode. It links to the
  // bare /hub/ (the weighted-shuffle landing); New/Old/Hot remain the explicit
  // "organize" overrides. Active when no ?sort= param is present. Inherits the
  // existing sort-tab pill styling (plain <a> sibling). Mobile only. Idempotent.
  function wireFreshPill() {
    try {
      if (!window.matchMedia('(max-width:640px)').matches) return;
      var bar = document.querySelector('.feed-sort-bar');
      if (!bar || bar.querySelector('.lg-fresh-tab')) return;
      var tabs = [].slice.call(bar.querySelectorAll('a'));
      var newTab = tabs.filter(function (a) { return /[?&]sort=new/.test(a.getAttribute('href') || ''); })[0];
      if (!newTab) return;
      var fresh = document.createElement('a');
      fresh.className = newTab.className.replace(/\bactive\b/, '').trim() + ' lg-fresh-tab';
      fresh.setAttribute('href', '/hub/');
      fresh.textContent = 'Fresh';
      newTab.parentNode.insertBefore(fresh, newTab);
      if (freshActive()) { fresh.classList.add('active'); newTab.classList.remove('active'); }
    } catch (e) {}
  }

  function run() {
    if (!onHubPath()) return;
    if (!document.querySelector('.feed-page')) return; // listing pages only
    injectStyles();
    wireFastFilters();
    reopenFiltersIfFlagged();
    injectFont();
    addTagline();
    relocateFilterToggle();
    restyleSortBar();
    wireFreshPill();
    buildTopSearch();
    wireHeaderAutoHide();
    relayCards(document);
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
  }

  function start() {
    if (!onHubPath()) return;
    if (document.body) run();
    else document.addEventListener('DOMContentLoaded', run);
  }
  start();
})();
