/* sponsor-sheet.js — Looth PWA (mobile only)
 *
 * Tapping the Sponsors nav (/sponsors/) OR an individual sponsor
 * (/sponsor-page/<slug>/ which 301s to /sponsors/<slug>/) opens it in an
 * app-style chromeless bottom sheet instead of navigating to the full page
 * (which carries the site header). Sibling of practice-sheet.js — same sheet
 * scaffold + scopeCss mini-parser + capture-phase interceptor, minus the
 * Leaflet/map/carousel bits (sponsor content is just logos + copy).
 *
 * Both the list and an individual sponsor render through the theme wrapper
 * <main class="lg-content-page" id="main">, so we extract #main, scope the
 * page's <style> blocks to this sheet, and inject. Tapping a sponsor logo
 * INSIDE the list sheet is caught by the same interceptor, so it swaps to
 * that sponsor in-place. Loaded site-wide via /pwa.js; self-gates to <=640px
 * so desktop keeps the native full pages untouched. Touches no canonical code.
 */
(function () {
  'use strict';
  if (window.__lgSponSheet) return;
  window.__lgSponSheet = true;
  var MOBILE = window.matchMedia('(max-width:640px)');
  if (!MOBILE.matches) return;
  try { if (window.top !== window.self) return; } catch (e) {}

  var SHEET_ID = 'looth-spon-sheet';
  var STYLE_ID = 'looth-spon-style';
  var PAGE_CSS_ID = 'looth-spon-pagecss';

  function ensureStyle() {
    if (document.getElementById(STYLE_ID)) return;
    var S = '#' + SHEET_ID;
    var css = [
      S + '{position:fixed;inset:0;z-index:2147483520;display:none}',
      S + '.is-open{display:block}',
      S + ' .lss-back{position:absolute;inset:0;background:rgba(26,29,26,.55);opacity:0;transition:opacity .2s}',
      S + '.is-open .lss-back{opacity:1}',
      S + ' .lss-card{position:absolute;left:0;right:0;bottom:0;top:34px;display:flex;flex-direction:column;' +
        'background:var(--lg-cream,#fbfbf8);border-radius:18px 18px 0 0;overflow:hidden;' +
        'box-shadow:0 -10px 34px rgba(26,29,26,.34);transform:translateY(100%);transition:transform .28s cubic-bezier(.2,.7,.2,1)}',
      S + '.is-open .lss-card{transform:translateY(0)}',
      S + ' .lss-bar{flex:0 0 auto;position:relative;display:flex;align-items:center;gap:10px;' +
        'padding:13px 12px 10px;background:var(--lg-cream,#fbfbf8);border-bottom:1px solid var(--lg-line,#e3ddd0)}',
      S + ' .lss-grab{position:absolute;top:5px;left:50%;transform:translateX(-50%);width:38px;height:4px;border-radius:999px;background:var(--lg-line,#e3ddd0)}',
      S + ' .lss-x{flex:0 0 auto;width:34px;height:34px;border:0;border-radius:50%;background:var(--lg-sage-tint,#eef2e3);' +
        'color:var(--lg-charcoal,#1a1d1a);font-size:21px;line-height:1;cursor:pointer;display:flex;align-items:center;justify-content:center}',
      S + ' .lss-btitle{flex:1 1 auto;min-width:0;font:700 17px/1.2 var(--lg-font-serif,Georgia,serif);' +
        'color:var(--lg-charcoal,#1a1d1a);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}',
      S + ' .lss-body{flex:1 1 auto;overflow:auto;-webkit-overflow-scrolling:touch;padding:0 0 30px}',
      S + ' .lss-load{padding:46px 0;text-align:center;color:var(--lg-mute,#6b6f6b);font:600 14px/1 var(--lg-font-sans,system-ui)}',
      S + ' .lss-spin{width:26px;height:26px;margin:0 auto 12px;border:3px solid var(--lg-sage-tint,#eef2e3);' +
        'border-top-color:var(--lg-sage,#87986a);border-radius:50%;animation:lss-spin .8s linear infinite}',
      '@keyframes lss-spin{to{transform:rotate(360deg)}}',
      S + ' .lg-content-page,' + S + ' #main{max-width:none!important;margin:0!important;' +
        'padding:14px 16px 0!important;min-height:0!important;background:transparent!important}',
      S + ' .lss-body img{max-width:100%;height:auto}',
      // Social-link icons are BARE unsized <svg>s on the sponsor page — their sizing
      // CSS doesn't survive into the sheet, so they exploded to full width (Vanessa
      // 2026-06-11, StewMac). Clamp icon-in-a-link to chip size and lay the link row
      // out as a tidy strip; belt-and-suspenders cap on any other stray svg.
      S + ' .lss-body a > svg:only-child{width:30px;height:30px;display:inline-block;vertical-align:middle}',
      S + ' .lss-body a:has(> svg:only-child){display:inline-flex;align-items:center;justify-content:center;' +
        'width:48px;height:48px;border-radius:50%;background:var(--lg-sage-tint,#eef2e3);color:var(--lg-sage-d,#6b7c52);margin:4px 6px 4px 0}',
      S + ' .lss-body svg{max-width:72px;max-height:72px}',
      'html[data-lguser-theme="dark"] ' + S + ' .lss-body a:has(> svg:only-child){background:#262b30;color:#9cb37d}',
      // ── Sponsor-page polish in the sheet (Buck 2026-06-11 "it could look better"):
      // hero, CTA pills, section headings, who's-talking links, contact form. All
      // scoped to the sheet — the desktop /sponsors page is untouched.
      S + ' .lg-brand-hero__banner img,' + S + ' .lg-brand-hero__banner-img{width:100%;height:150px;object-fit:cover;display:block}',
      S + ' .lg-brand-hero__logo{width:112px!important;height:112px;margin:-48px auto 0;border-radius:50%;overflow:hidden;' +
        'border:4px solid var(--lg-cream,#fbfbf8);background:#fff;box-shadow:0 4px 14px rgba(26,29,26,.18)}',
      S + ' .lg-brand-hero__logo img{width:100%;height:100%;object-fit:cover;display:block}',
      S + ' .lg-brand-hero__bar{display:flex;flex-direction:column;align-items:center;text-align:center;padding:0 16px}',
      S + ' .lg-brand-hero__name{font:700 24px/1.2 var(--lg-font-serif,Georgia,serif);color:var(--lg-charcoal,#1a1d1a);margin:10px 0 4px;text-align:center}',
      S + ' .lg-brand-hero__tagline,' + S + ' .lg-brand-hero__desc{text-align:center;color:var(--lg-mute,#6b6f6b);font:14px/1.5 var(--lg-font-sans,system-ui,sans-serif)}',
      S + ' .lg-brand-hero__cta{display:flex;flex-wrap:wrap;justify-content:center;gap:9px;margin:14px 0 6px}',
      S + ' .lg-brand-hero__cta-btn{display:inline-flex;align-items:center;gap:7px;background:var(--lg-sage,#87986a);color:#fff!important;' +
        'border-radius:999px;padding:10px 16px;font:700 13.5px/1 var(--lg-font-sans,system-ui,sans-serif);text-decoration:none}',
      S + ' .lg-brand-hero__cta-btn svg{width:17px!important;height:17px!important;max-width:17px;color:#fff;stroke:#fff}',
      S + ' .lg-section-heading,' + S + ' .lg-feat-products__title,' + S + ' .lg-recent-posts__title,' +
        S + ' .lg-brand-gallery__title,' + S + ' .lg-whos-talking__title,' + S + ' .lg-contact-form__title{' +
        'font:700 19px/1.25 var(--lg-font-serif,Georgia,serif);color:var(--lg-charcoal,#1a1d1a);margin:24px 0 10px}',
      S + ' .lg-whos-talking__links{display:flex;flex-direction:column;gap:9px;margin:6px 0 4px}',
      S + ' .lg-whos-talking__link{display:inline-flex;align-items:center;gap:9px;background:var(--lg-sage-tint,#eef2e3);' +
        'color:var(--lg-sage-d,#6b7c52)!important;border-radius:14px;padding:12px 14px;font:600 14px/1.2 var(--lg-font-sans,system-ui,sans-serif);text-decoration:none}',
      S + ' .lg-whos-talking__link svg{width:20px!important;height:20px!important;max-width:20px;flex:0 0 auto}',
      // contact form: stacked labeled fields, brand inputs + submit
      S + ' .lg-contact-form__row{display:flex;flex-direction:column;gap:12px;margin:0 0 12px}',
      S + ' .lg-contact-form__field{display:flex;flex-direction:column;gap:5px;width:100%}',
      S + ' .lg-contact-form__field > span{font:700 12.5px/1 var(--lg-font-sans,system-ui,sans-serif);color:var(--lg-mute,#6b6f6b)}',
      S + ' .lg-contact-form__form input[type=text],' + S + ' .lg-contact-form__form input[type=email],' +
        S + ' .lg-contact-form__form textarea{width:100%;box-sizing:border-box;border:1px solid var(--lg-line,#e3ddd0);' +
        'border-radius:12px;background:#fff;padding:11px 14px;font:15px/1.4 var(--lg-font-sans,system-ui,sans-serif);color:var(--lg-ink,#323532)}',
      S + ' .lg-contact-form__form textarea{min-height:110px;resize:vertical}',
      S + ' .lg-contact-form__submit,' + S + ' .lg-contact-form__form button[type=submit]{display:block;width:100%;border:0;cursor:pointer;' +
        'background:var(--lg-sage,#87986a);color:#fff;border-radius:12px;padding:13px;font:700 15px/1 var(--lg-font-sans,system-ui,sans-serif);margin:2px 0 6px}',
      S + ' .lg-contact-form__form a,' + S + ' .lg-contact-form a{color:var(--lg-sage-d,#6b7c52)}',
      // dark pass for the polish
      'html[data-lguser-theme="dark"] ' + S + ' .lg-brand-hero__logo{border-color:#1b1e21}',
      'html[data-lguser-theme="dark"] ' + S + ' .lg-brand-hero__name{color:#f2f4ee}',
      'html[data-lguser-theme="dark"] ' + S + ' .lg-section-heading,html[data-lguser-theme="dark"] ' + S + ' .lg-feat-products__title,' +
        'html[data-lguser-theme="dark"] ' + S + ' .lg-recent-posts__title,html[data-lguser-theme="dark"] ' + S + ' .lg-brand-gallery__title,' +
        'html[data-lguser-theme="dark"] ' + S + ' .lg-whos-talking__title,html[data-lguser-theme="dark"] ' + S + ' .lg-contact-form__title{color:#f2f4ee}',
      'html[data-lguser-theme="dark"] ' + S + ' .lg-whos-talking__link{background:#243024;color:#b6c79a!important}',
      'html[data-lguser-theme="dark"] ' + S + ' .lg-contact-form__form input[type=text],html[data-lguser-theme="dark"] ' + S + ' .lg-contact-form__form input[type=email],' +
        'html[data-lguser-theme="dark"] ' + S + ' .lg-contact-form__form textarea{background:#222629;border-color:#333833;color:#e5e7e1}',
      'html[data-lguser-theme="dark"] ' + S + ' .lg-brand-hero__cta-btn,html[data-lguser-theme="dark"] ' + S + ' .lg-contact-form__submit,' +
        'html[data-lguser-theme="dark"] ' + S + ' .lg-contact-form__form button[type=submit]{background:var(--lg-sage-d,#6b7c52)}',
      'html[data-lguser-theme="dark"] ' + S + ' .lss-card,html[data-lguser-theme="dark"] ' + S + ' .lss-bar{background:#1b1e21}',
      'html[data-lguser-theme="dark"] ' + S + ' .lss-bar{border-color:#2c312d}',
      'html[data-lguser-theme="dark"] ' + S + ' .lss-btitle{color:#f2f4ee}',
      'html[data-lguser-theme="dark"] ' + S + ' .lss-x{background:#262b30;color:#e5e7e1}'
    ].join('\n');
    var s = document.createElement('style'); s.id = STYLE_ID; s.textContent = css;
    (document.head || document.documentElement).appendChild(s);
  }

  /* scope a stylesheet so the page's CSS only affects the sheet (verbatim from
   * practice-sheet.js): prefix every selector with the scope; html/body/:root
   * become the scope; @media/@supports recurse; @keyframes/@font-face copied. */
  function scopeCss(css, scope) {
    css = css.replace(/\/\*[\s\S]*?\*\//g, '');
    var out = '', i = 0, n = css.length;
    function scopeSel(list) {
      return list.split(',').map(function (s) {
        s = s.trim(); if (!s) return s;
        var m = s.match(/^(html|body|:root)\b/i);
        if (m) { var rest = s.slice(m[0].length).trim(); return scope + (rest ? ' ' + rest : ''); }
        return scope + ' ' + s;
      }).join(',');
    }
    while (i < n) {
      var at = css.indexOf('@', i), brace = css.indexOf('{', i);
      if (brace === -1) break;
      if (at !== -1 && at < brace) {
        var atEnd = css.indexOf('{', at);
        var prelude = css.slice(at, atEnd).trim();
        if (/^@(media|supports)/i.test(prelude)) {
          var depth = 1, j = atEnd + 1, start = j;
          while (j < n && depth > 0) { if (css[j] === '{') depth++; else if (css[j] === '}') depth--; j++; }
          out += prelude + '{' + scopeCss(css.slice(start, j - 1), scope) + '}';
          i = j; continue;
        }
        if (/^@(import|charset)/i.test(prelude)) { var semi = css.indexOf(';', at); out += css.slice(at, semi + 1); i = semi + 1; continue; }
        if (/^@(font-face|keyframes|-webkit-keyframes|page)/i.test(prelude)) {
          var d2 = 1, k = atEnd + 1; while (k < n && d2 > 0) { if (css[k] === '{') d2++; else if (css[k] === '}') d2--; k++; }
          out += css.slice(at, k); i = k; continue;
        }
      }
      var sel = css.slice(i, brace), end = css.indexOf('}', brace);
      if (end === -1) break;
      out += scopeSel(sel) + '{' + css.slice(brace + 1, end) + '}';
      i = end + 1;
    }
    return out;
  }

  var lastFocus = null;
  function buildSheet() {
    var sheet = document.getElementById(SHEET_ID);
    if (sheet) return sheet;
    ensureStyle();
    sheet = document.createElement('div');
    sheet.id = SHEET_ID; sheet.setAttribute('role', 'dialog'); sheet.setAttribute('aria-modal', 'true');
    sheet.innerHTML =
      '<div class="lss-back"></div>' +
      '<div class="lss-card">' +
        '<div class="lss-bar">' +
          '<span class="lss-grab"></span>' +
          '<button type="button" class="lss-x" aria-label="Close">✕</button>' +
          '<span class="lss-btitle"></span>' +
        '</div>' +
        '<div class="lss-body"></div>' +
      '</div>';
    document.body.appendChild(sheet);
    function close() {
      sheet.classList.remove('is-open');
      document.documentElement.style.overflow = '';
      if (lastFocus && lastFocus.focus) { try { lastFocus.focus(); } catch (e) {} }
    }
    sheet.querySelector('.lss-back').addEventListener('click', close);
    sheet.querySelector('.lss-x').addEventListener('click', close);
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && sheet.classList.contains('is-open')) close(); });
    var bar = sheet.querySelector('.lss-bar'), card = sheet.querySelector('.lss-card'), sy = null;
    bar.addEventListener('touchstart', function (e) { sy = e.touches[0].clientY; card.style.transition = 'none'; }, { passive: true });
    bar.addEventListener('touchmove', function (e) { if (sy === null) return; var dy = e.touches[0].clientY - sy; if (dy > 0) card.style.transform = 'translateY(' + dy + 'px)'; }, { passive: true });
    bar.addEventListener('touchend', function (e) { if (sy === null) return; var dy = e.changedTouches[0].clientY - sy; sy = null; card.style.transition = ''; card.style.transform = ''; if (dy > 110) close(); });
    sheet._close = close;
    return sheet;
  }

  var inflight = 0;
  function openSponsorSheet(url, fallbackTitle) {
    lastFocus = document.activeElement;
    var sheet = buildSheet();
    var body = sheet.querySelector('.lss-body');
    var bt = sheet.querySelector('.lss-btitle');
    bt.textContent = fallbackTitle || 'Sponsors';
    body.innerHTML = '<div class="lss-load"><div class="lss-spin"></div>Loading…</div>';
    sheet.classList.add('is-open');
    document.documentElement.style.overflow = 'hidden';
    var token = ++inflight;
    fetch(url, { credentials: 'same-origin' })
      .then(function (r) { return r.text(); })
      .then(function (html) {
        if (token !== inflight) return;
        var doc = new DOMParser().parseFromString(html, 'text/html');
        var node = doc.querySelector('main.lg-content-page') || doc.querySelector('#main') ||
                   doc.querySelector('.entry-content') || doc.querySelector('article') || doc.body;
        if (!node) throw new Error('no content');
        node.querySelectorAll('[id]').forEach(function (n) { n.removeAttribute('id'); });
        node.querySelectorAll('script,noscript').forEach(function (n) { n.remove(); });
        var old = document.getElementById(PAGE_CSS_ID); if (old) old.remove();
        var cssText = '';
        doc.querySelectorAll('style').forEach(function (st) { cssText += '\n' + st.textContent; });
        if (cssText.trim()) {
          var ps = document.createElement('style'); ps.id = PAGE_CSS_ID;
          ps.textContent = scopeCss(cssText, '#' + SHEET_ID);
          (document.head || document.documentElement).appendChild(ps);
        }
        var h = node.querySelector('h1, .entry-title, h2');
        var t = (h && h.textContent.trim()) || (doc.title || '').replace(/\s*[—|\-]\s*The Looth Group.*$/i, '').trim();
        if (t) bt.textContent = t;
        body.innerHTML = '';
        body.appendChild(node);
        body.scrollTop = 0;
      })
      .catch(function () {
        if (token !== inflight) return;
        body.innerHTML = '<div class="lss-load">Couldn’t load that.<br><a href="' + url +
          '" style="color:var(--lg-sage-d,#6b7c52);font-weight:700">Open the full page</a></div>';
      });
  }
  window.openSponsorSheet = openSponsorSheet;   // let other layers reuse it

  /* ---- wiring: intercept sponsor links site-wide (capture phase) ---- */
  document.addEventListener('click', function (e) {
    if (!MOBILE.matches) return;
    var a = e.target.closest && e.target.closest('a[href]');
    if (!a) return;
    var href = a.getAttribute('href') || '';
    if (!href || href.charAt(0) === '#') return;
    var path, full;
    try { var u = new URL(href, location.href); path = u.pathname; full = u.href; } catch (err) { return; }
    // Sponsors nav (the list) — exact /sponsors/
    if (/^\/sponsors\/?$/.test(path)) {
      e.preventDefault(); e.stopPropagation();
      openSponsorSheet(full, 'Our Sponsors');
      return;
    }
    // individual sponsor — /sponsor-page/<slug>/ or /sponsors/<slug>/
    var m = path.match(/^\/(?:sponsor-page|sponsors)\/([^/?#]+)\/?$/);
    if (m && m[1]) {
      e.preventDefault(); e.stopPropagation();
      openSponsorSheet(full, '');
      return;
    }
  }, true);

})();
