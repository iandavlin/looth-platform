/* Looth app settings — user-pickable color theme, webfont, and text size.
   Site-wide, persisted per-device in localStorage. No backend.

   How it applies (the trick): the brand design tokens on :root (--lg-cream,
   --lg-sage*, --lg-line, --lg-font-sans, ...) are what the whole site already
   references, so a THEME just OVERRIDES those tokens and the app recolors; a
   FONT overrides --lg-font-sans (and loads the webfont); SIZE scales the rem
   base. Themes also set --lguser-* vars that hub-polish.js reads for the Hub
   feed (whose colors are otherwise hardcoded), so the Hub follows the theme too.

   DEFAULTS preserve the current look exactly: theme 'default' / font 'default'
   apply NO override (app stays brand-cream, Hub stays its Sage-Tint look), so
   existing users see zero change until they actively pick something.

   Exposes window.LGSettings { THEMES, FONTS, SIZES, get, set, apply,
   getTheme/getFont/getSize, buildPanel(container) } for the settings UI
   (rendered by bottom-nav.js inside the profile sheet).
   Loaded EARLY site-wide via /pwa.js (before hub-polish.js). */
(function () {
  'use strict';
  if (window.LGSettings) return;

  var KEY = { theme: 'lg-set-theme', font: 'lg-set-font', size: 'lg-set-size', bubble: 'lg-set-bubble' };

  // ---- Color themes (on-brand, from the Style Sandbox family) ----
  // 'default' = no override (current look). Others override brand tokens (rest
  // of the app) + --lguser-* (Hub feed) so the whole experience recolors.
  var THEMES = [
    { id: 'default', name: 'Default', dot: '#e9eedd', dark: false, vars: null },
    { id: 'cream-classic', name: 'Cream', dot: '#fbfbf8', dark: false, vars: {
        '--lg-cream': '#fbfbf8', '--lg-sage': '#87986a', '--lg-sage-d': '#6b7c52',
        '--lg-sage-tint': '#eef2e3', '--lg-line': '#e3ddd0',
        '--lguser-bg': '#f1efe8', '--lguser-card': '#ffffff', '--lguser-accent': '#6b7c52',
        '--lguser-accent-d': '#52613d', '--lguser-pill': '#eef2e3', '--lguser-line': '#e3ddd0',
        '--lguser-ink': '#1a1d1a', '--lguser-mute': '#6b6f6b' } },
    { id: 'sage-deep', name: 'Sage', dot: '#6b7c52', dark: false, vars: {
        '--lg-cream': '#e6ecd9', '--lg-sage': '#6b7c52', '--lg-sage-d': '#52613d',
        '--lg-sage-tint': '#d6e1bf', '--lg-line': '#c6d2ad',
        '--lguser-bg': '#e6ecd9', '--lguser-card': '#ffffff', '--lguser-accent': '#5c6c45',
        '--lguser-accent-d': '#44512f', '--lguser-pill': '#d6e1bf', '--lguser-line': '#c6d2ad',
        '--lguser-ink': '#1a1d1a', '--lguser-mute': '#647053' } },
    { id: 'amber-warm', name: 'Amber', dot: '#ecb351', dark: false, vars: {
        '--lg-cream': '#f6efe2', '--lg-sage': '#b5872f', '--lg-sage-d': '#8f6620',
        '--lg-sage-tint': '#f0e2c4', '--lg-line': '#e6dac2',
        '--lguser-bg': '#f3ead8', '--lguser-card': '#fffdf8', '--lguser-accent': '#a9772a',
        '--lguser-accent-d': '#7f5717', '--lguser-pill': '#f0e2c4', '--lguser-line': '#e6dac2',
        '--lguser-ink': '#2a230f', '--lguser-mute': '#7a6f56' } },
    { id: 'rust-accent', name: 'Rust', dot: '#c66845', dark: false, vars: {
        '--lg-cream': '#f5efe9', '--lg-sage': '#c66845', '--lg-sage-d': '#a8512f',
        '--lg-sage-tint': '#f0ddd2', '--lg-line': '#e7d9cd',
        '--lguser-bg': '#f2e9e1', '--lguser-card': '#fffaf6', '--lguser-accent': '#b85a38',
        '--lguser-accent-d': '#94431f', '--lguser-pill': '#f0ddd2', '--lguser-line': '#e7d9cd',
        '--lguser-ink': '#2a1c14', '--lguser-mute': '#7c6a5e' } }
  ];
  // Union of every theme var key, so apply() can cleanly revert before re-setting.
  var THEME_KEYS = (function () {
    var seen = {}, out = [];
    THEMES.forEach(function (t) { if (t.vars) for (var k in t.vars) if (t.vars.hasOwnProperty(k) && !seen[k]) { seen[k] = 1; out.push(k); } });
    return out;
  })();

  // ---- Webfonts (cross-platform — always load, never rely on a system face) ----
  // 'default' = no override (Hub keeps Trebuchet/Cabin, app keeps brand sans).
  var FONTS = [
    { id: 'default', name: 'Default', stack: null, google: null },
    { id: 'cabin', name: 'Cabin', stack: '"Cabin", system-ui, -apple-system, "Segoe UI", Roboto, sans-serif', google: 'Cabin:wght@400;500;600;700' },
    { id: 'nunito', name: 'Nunito', stack: '"Nunito Sans", system-ui, sans-serif', google: 'Nunito+Sans:opsz,wght@6..12,400;6..12,600;6..12,700' },
    { id: 'inter', name: 'Inter', stack: '"Inter", system-ui, sans-serif', google: 'Inter:wght@400;500;600;700' },
    { id: 'source-serif', name: 'Source Serif', stack: '"Source Serif 4", Georgia, serif', google: 'Source+Serif+4:opsz,wght@8..60,400;8..60,600;8..60,700' }
  ];

  // ---- Text size (scales the rem base, the standard a11y approach) ----
  var SIZES = [
    { id: 's', name: 'Small', scale: 0.92 },
    { id: 'm', name: 'Default', scale: 1 },
    { id: 'l', name: 'Large', scale: 1.12 },
    { id: 'xl', name: 'Larger', scale: 1.25 }
  ];

  // ---- Comment bubble color (the Facebook-style comment bubbles) ----
  // 'default' = the standard grey (#eceff3). Others set --lguser-bubble.
  var BUBBLES = [
    { id: 'default', name: 'Grey', color: null, dot: '#eceff3' },
    { id: 'white', name: 'White', color: '#ffffff', dot: '#ffffff' },
    { id: 'sage', name: 'Sage', color: '#eef2e3', dot: '#eef2e3' },
    { id: 'cream', name: 'Cream', color: '#f5f1e6', dot: '#f5f1e6' },
    { id: 'sky', name: 'Sky', color: '#e7f0fb', dot: '#e7f0fb' },
    { id: 'amber', name: 'Amber', color: '#f6ecd6', dot: '#f6ecd6' },
    { id: 'rose', name: 'Rose', color: '#f7e8e6', dot: '#f7e8e6' }
  ];

  function byId(list, id) { for (var i = 0; i < list.length; i++) if (list[i].id === id) return list[i]; return list[0]; }
  function rd(k) { try { return localStorage.getItem(KEY[k]); } catch (e) { return null; } }
  function wr(k, v) { try { localStorage.setItem(KEY[k], v); } catch (e) {} }

  function getTheme() { return rd('theme') || 'default'; }
  function getFont() { return rd('font') || 'default'; }
  function getSize() { return rd('size') || 'm'; }
  function getBubble() { return rd('bubble') || 'default'; }

  // Lazy-load a Google webfont once.
  function loadFont(font) {
    if (!font || !font.google) return;
    var id = 'lg-font-' + font.id;
    if (document.getElementById(id)) return;
    var head = document.head || document.documentElement;
    if (!document.getElementById('lg-font-pre')) {
      var p = document.createElement('link');
      p.id = 'lg-font-pre'; p.rel = 'preconnect'; p.href = 'https://fonts.gstatic.com'; p.crossOrigin = 'anonymous';
      head.appendChild(p);
    }
    var l = document.createElement('link');
    l.id = id; l.rel = 'stylesheet';
    l.href = 'https://fonts.googleapis.com/css2?family=' + font.google + '&display=swap';
    head.appendChild(l);
  }

  var APPLY_STYLE_ID = 'lg-settings-apply';
  function ensureApplyStyle() {
    if (document.getElementById(APPLY_STYLE_ID)) return;
    var s = document.createElement('style');
    s.id = APPLY_STYLE_ID;
    s.textContent =
      'body{font-family:var(--lg-font-sans)}' +
      'html[data-lguser-dark="1"]{color-scheme:dark}';
    (document.head || document.documentElement).appendChild(s);
  }

  function apply() {
    var t = byId(THEMES, getTheme());
    var f = byId(FONTS, getFont());
    var z = byId(SIZES, getSize());
    var root = document.documentElement;

    // Theme: revert all theme keys, then set the chosen theme's overrides.
    THEME_KEYS.forEach(function (k) { root.style.removeProperty(k); });
    if (t.vars) for (var k in t.vars) if (t.vars.hasOwnProperty(k)) root.style.setProperty(k, t.vars[k]);
    root.setAttribute('data-lguser-theme', t.id);
    root.setAttribute('data-lguser-dark', t.dark ? '1' : '0');

    // Font: override the brand sans token + load the webfont (or revert).
    if (f.stack) {
      loadFont(f);
      root.style.setProperty('--lg-font-sans', f.stack);
      root.style.setProperty('--lguser-font', f.stack);
    } else {
      root.style.removeProperty('--lg-font-sans');
      root.style.removeProperty('--lguser-font');
    }

    // Size: scale the rem base.
    root.style.setProperty('--lguser-scale', String(z.scale));
    root.style.fontSize = (z.scale * 100) + '%';

    // Comment bubble color (Facebook-style comment bubbles).
    var bub = byId(BUBBLES, getBubble());
    if (bub.color) root.style.setProperty('--lguser-bubble', bub.color);
    else root.style.removeProperty('--lguser-bubble');

    ensureApplyStyle();
  }

  function set(kind, id) {
    if (!KEY[kind]) return;
    wr(kind, id);
    apply();
  }

  // Render the settings UI into `container`. Pure DOM; each control calls
  // set(...) for live apply + persistence. Styling piggybacks on brand tokens.
  function buildPanel(container) {
    container.innerHTML = '';
    if (!/lg-set-panel/.test(container.className)) container.className = (container.className + ' lg-set-panel').trim();

    function section(title, items, current, kind, render) {
      var sec = document.createElement('div');
      sec.className = 'lg-set-sec';
      var h = document.createElement('div');
      h.className = 'lg-set-sec__h';
      h.textContent = title;
      sec.appendChild(h);
      var row = document.createElement('div');
      row.className = 'lg-set-row';
      items.forEach(function (it) {
        var b = document.createElement('button');
        b.type = 'button';
        b.className = 'lg-set-opt' + (it.id === current ? ' is-on' : '');
        b.setAttribute('data-id', it.id);
        render(b, it);
        b.addEventListener('click', function () {
          set(kind, it.id);
          row.querySelectorAll('.lg-set-opt').forEach(function (x) { x.classList.remove('is-on'); });
          b.classList.add('is-on');
        });
        row.appendChild(b);
      });
      sec.appendChild(row);
      container.appendChild(sec);
    }

    section('Color', THEMES, getTheme(), 'theme', function (b, it) {
      b.innerHTML = '<span class="lg-set-swatch" style="background:' + it.dot + '"></span>' +
        '<span class="lg-set-opt__t">' + it.name + '</span>';
    });
    section('Font', FONTS, getFont(), 'font', function (b, it) {
      b.innerHTML = '<span class="lg-set-opt__t"' + (it.stack ? ' style="font-family:' + it.stack + '"' : '') + '>' + it.name + '</span>';
      if (it.google) loadFont(it); // preview in its own face
    });
    section('Text size', SIZES, getSize(), 'size', function (b, it) {
      b.innerHTML = '<span class="lg-set-opt__t">' + it.name + '</span>';
    });
    section('Comment bubble', BUBBLES, getBubble(), 'bubble', function (b, it) {
      b.innerHTML = '<span class="lg-set-swatch" style="background:' + it.dot + '"></span>' +
        '<span class="lg-set-opt__t">' + it.name + '</span>';
    });
  }

  window.LGSettings = {
    THEMES: THEMES, FONTS: FONTS, SIZES: SIZES, BUBBLES: BUBBLES,
    get: rd, set: set, apply: apply,
    getTheme: getTheme, getFont: getFont, getSize: getSize, getBubble: getBubble,
    buildPanel: buildPanel
  };

  apply();
})();
