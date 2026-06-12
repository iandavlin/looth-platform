<?php
/**
 * _gdle-promo.php — Guitardle promo + game modal (shared partial).
 *
 * Two render shapes, picked by $gdle_compact:
 *   false — the standalone front-page row promo: icon | pitch+Play | top-5
 *           (three-column grid; the original row--guitardle body).
 *   true  — the STACKED card under the featured video inside the What's-New
 *           container (Ian 6/12 "move the guitardle stuff in the container
 *           with featured video and stack it"): title line + Play + top-5.
 *
 * Either shape emits the SAME centered modal + script once: the iframe src is
 * set lazily on first open and the modal hides (never destroys) on close, so
 * a mid-game peek can't forfeit the round (refresh-forfeit rule). The embed
 * page carries its own weekly top-5 strip (side by side with the game on wide
 * hosts), so the modal shows the leaderboard with no extra wiring here.
 *
 * Expects: $is_member (bool) in scope. Keep the #guitardle anchor — the Hub
 * teaser and shares deep-link /archive-poc/#guitardle (and #guitardle=play
 * auto-opens the modal).
 */
$gdle_compact = !empty($gdle_compact);
$gdle_src = '/archive-poc/guitardle/index.html?embed=1&aud=' . ($is_member ? 'm' : 'p')
          . '&v=' . (@filemtime(__DIR__ . '/guitardle/game.js') ?: '1');
?>
<div class="gdle-block<?= $gdle_compact ? ' gdle-block--stack' : '' ?>" id="guitardle">
  <?php if ($gdle_compact): ?>
    <div class="gdle-stack__head">
      <img class="gdle-stack__ic" src="/archive-poc/guitardle/assets/guitardle-icon-512.webp" alt="" aria-hidden="true" loading="lazy">
      <span class="gdle-stack__title">Guitardle</span>
      <span class="gdle-stack__sub">the daily guitar phrase game</span>
    </div>
    <button type="button" class="gdle-promo__play" id="gdle-play">Play today's Guitardle &rarr;</button>
    <aside class="gdle-card gdle-promo__board" aria-label="Guitardle weekly top 5">
      <h3 class="gdle-card__title">🏆 Weekly top 5</h3>
      <ol class="gdle-side-board" id="gdle-side-board"></ol>
      <p class="gdle-side-empty" id="gdle-side-empty" hidden>No wins yet this week &mdash; be the first!</p>
    </aside>
  <?php else: ?>
    <div class="gdle-promo">
      <img class="gdle-promo__icon gdle-side-art" src="/archive-poc/guitardle/assets/guitardle-icon-512.webp" alt="" aria-hidden="true" loading="lazy" width="512" height="512">
      <div class="gdle-promo__main">
        <p class="gdle-promo__pitch">Six guesses, one guitar phrase a day. Wins score points &mdash; Hardcore counts double, board resets Monday.</p>
        <button type="button" class="gdle-promo__play" id="gdle-play">Play today's Guitardle &rarr;</button>
      </div>
      <aside class="gdle-card gdle-promo__board" aria-label="Guitardle weekly top 5">
        <h3 class="gdle-card__title">🏆 Weekly top 5</h3>
        <ol class="gdle-side-board" id="gdle-side-board"></ol>
        <p class="gdle-side-empty" id="gdle-side-empty" hidden>No wins yet this week &mdash; be the first!</p>
      </aside>
    </div>
  <?php endif; ?>

  <div class="gdle-modal" id="gdle-modal" hidden role="dialog" aria-modal="true" aria-label="Guitardle — daily guitar phrase game">
    <div class="gdle-modal__back" data-gdle-close></div>
    <div class="gdle-modal__panel">
      <div class="gdle-modal__row">
        <span class="gdle-modal__title"><img class="gdle-modal__ic" src="/archive-poc/guitardle/assets/guitardle-icon-512.webp" alt="">Guitardle</span>
        <button type="button" class="gdle-modal__x" data-gdle-close aria-label="Close">&times;</button>
      </div>
      <iframe class="gdle-frame" id="gdle-frame"
              data-src="<?= h($gdle_src) ?>"
              title="Guitardle — daily guitar phrase game"
              scrolling="no"></iframe>
    </div>
  </div>

  <script>
  (function () {
      addEventListener('message', function (e) {
          if (e.origin !== location.origin) return;
          if (!e.data || e.data.type !== 'guitardle:height' || !(e.data.height > 0)) return;
          var f = document.getElementById('gdle-frame');
          if (f) f.style.height = Math.ceil(e.data.height) + 'px';
      });

      function fillBoard() {
          fetch('/archive-api/v0/guitardle-board', { credentials: 'same-origin' })
              .then(function (r) { return r.ok ? r.json() : null; })
              .then(function (b) {
                  if (!b) return;
                  var list = document.getElementById('gdle-side-board');
                  var empty = document.getElementById('gdle-side-empty');
                  var leaders = (b.leaders || []).slice(0, 5);   // promo card = top 5
                  list.innerHTML = '';
                  empty.hidden = leaders.length > 0;
                  leaders.forEach(function (l, i) {
                      var li = document.createElement('li');
                      li.className = 'gdle-side-row' + (i === 0 ? ' is-first' : '');
                      var rank = document.createElement('span');
                      rank.className = 'gdle-side-row__rank';
                      rank.textContent = i === 0 ? '👑' : (i + 1);
                      var name = document.createElement(l.profile_url ? 'a' : 'span');
                      name.className = 'gdle-side-row__name';
                      name.textContent = l.name;
                      if (l.profile_url) name.href = l.profile_url;
                      var pts = document.createElement('span');
                      pts.className = 'gdle-side-row__pts';
                      pts.textContent = l.points + ' pts · ' + l.wins + 'W';
                      li.append(rank, name, pts);
                      list.appendChild(li);
                  });
              }).catch(function () {});
      }

      // Modal open/close. The iframe src loads ONCE on first open and the
      // modal only hides after that — destroying it would forfeit a round
      // in progress (refresh-forfeit rule).
      var modal = document.getElementById('gdle-modal');
      var frame = document.getElementById('gdle-frame');
      function openGame() {
          if (frame && !frame.src) frame.src = frame.dataset.src;
          modal.hidden = false;
          document.body.classList.add('gdle-modal-lock');
      }
      function closeGame() {
          modal.hidden = true;
          document.body.classList.remove('gdle-modal-lock');
          setTimeout(fillBoard, 1500);   // a just-finished round shows up
      }
      var play = document.getElementById('gdle-play');
      if (play) play.addEventListener('click', openGame);
      modal.addEventListener('click', function (e) {
          if (e.target.closest('[data-gdle-close]')) closeGame();
      });
      addEventListener('keydown', function (e) {
          if (e.key === 'Escape' && !modal.hidden) closeGame();
      });
      // Hub-teaser style deep link still works: /front-page/#guitardle=play
      if (location.hash === '#guitardle=play') openGame();

      fillBoard();
      // The iframe writing localStorage at game end fires `storage` here —
      // refresh the board after the score POST lands.
      addEventListener('storage', function (e) {
          if (!e.key || e.key.indexOf('guitardle_') !== 0) return;
          setTimeout(fillBoard, 2000);
      });
  })();
  </script>
</div>
