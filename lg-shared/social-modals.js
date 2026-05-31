/* /srv/lg-shared/social-modals.js — P9 social modals
 * Notifications bell, messages threads, connections/friends.
 * Loaded deferred by site-header.php on authenticated pages only.
 *
 * API base: /profile-api/v0   Auth: credentials:'include' (same-origin cookie/JWT)
 *
 * GET  /me/social-counts       {messages_unread, requests_pending, notifications_unread}
 * GET  /me/notifications       [{id, content|message|text, is_read, created_at}]
 * PATCH /me/notifications      mark all read (no body)
 * GET  /me/messages            [{id|uuid, other_participant:{display_name,avatar_url,slug},
 *                                last_message:{content}, unread_count}]
 * GET  /me/messages/<uuid>     {messages:[{id,content|body,is_mine|sent_by_me,created_at}]}
 *                              OR array directly
 * POST /me/messages/<uuid>     body:{content:string}
 * GET  /me/connections         [{user:{display_name,avatar_url,slug}}] or flat
 * GET  /me/connections/pending [{id, from_user:{display_name,avatar_url}}]
 * PATCH /connections/<id>      body:{action:'accept'|'decline'}
 */
(function () {
'use strict';

var API = '/profile-api/v0';
var currentThreadId = null;

/* ── helpers ── */
function esc(s) {
  return String(s == null ? '' : s)
    .replace(/&/g, '&amp;').replace(/</g, '&lt;')
    .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}
function capCount(n) {
  n = parseInt(n, 10) || 0;
  return n > 9 ? '9+' : (n > 0 ? String(n) : '');
}
function setBadge(sel, n) {
  var el = document.querySelector(sel);
  if (!el) return;
  var lbl = capCount(n);
  el.textContent = lbl;
  el.hidden = !lbl;
}
function relTime(ts) {
  if (!ts) return '';
  var d = new Date(typeof ts === 'number' ? ts * 1000 : ts);
  var diff = (Date.now() - d.getTime()) / 1000;
  if (isNaN(diff) || diff < 0) return '';
  if (diff < 60)    return 'just now';
  if (diff < 3600)  return Math.floor(diff / 60) + 'm ago';
  if (diff < 86400) return Math.floor(diff / 3600) + 'h ago';
  return Math.floor(diff / 86400) + 'd ago';
}
function extractArray(d, hints) {
  if (Array.isArray(d)) return d;
  for (var i = 0; i < hints.length; i++) {
    if (Array.isArray(d[hints[i]])) return d[hints[i]];
  }
  return [];
}
function avatarEl(u, sz) {
  sz = sz || 32; u = u || {};
  var name = u.display_name || u.name || '?';
  var url  = u.avatar_url   || u.avatar || '';
  var init = name.charAt(0).toUpperCase();
  if (url) {
    return '<img class="lg-sm__avatar" src="' + esc(url) + '" alt="' + esc(name) +
           '" width="' + sz + '" height="' + sz + '" loading="lazy">';
  }
  return '<span class="lg-sm__avatar lg-sm__avatar--initial">' + esc(init) + '</span>';
}

/* ── modal open/close ── */
function openModal(id) {
  closeAllModals();
  var m = document.getElementById(id);
  if (!m) return;
  m.hidden = false;
  m.setAttribute('aria-hidden', 'false');
  document.body.classList.add('lg-sm-open');
  var cb = m.querySelector('[data-lg-modal-close]');
  if (cb) cb.focus();
}
function closeAllModals() {
  document.querySelectorAll('.lg-social-modal').forEach(function (m) {
    m.hidden = true;
    m.setAttribute('aria-hidden', 'true');
  });
  document.body.classList.remove('lg-sm-open');
}
document.addEventListener('keydown', function (e) {
  if (e.key === 'Escape') closeAllModals();
});
document.addEventListener('click', function (e) {
  var t = e.target;
  if (t.hasAttribute('data-lg-modal-close') ||
      (t.closest && t.closest('[data-lg-modal-close]'))) {
    closeAllModals();
  } else if (t.classList && t.classList.contains('lg-social-modal__backdrop')) {
    closeAllModals();
  }
});

/* ── social counts ── */
function refreshCounts() {
  fetch(API + '/me/social-counts', { credentials: 'include' })
    .then(function (r) { return r.ok ? r.json() : null; })
    .then(function (d) {
      if (!d) return;
      setBadge('[data-lg-notif-count]', d.notifications_unread || 0);
      setBadge('[data-lg-msg-count]',   d.messages_unread      || 0);
      setBadge('[data-lg-conn-count]',  d.requests_pending     || 0);
    })
    .catch(function () {});
}

/* ── notifications ── */
function loadNotifications() {
  var list = document.getElementById('lg-notif-list');
  if (!list) return;
  list.innerHTML = '<p class="lg-sm__status">Loading...</p>';
  fetch(API + '/me/notifications', { credentials: 'include' })
    .then(function (r) { return r.ok ? r.json() : Promise.reject(r.status); })
    .then(function (d) {
      var items = extractArray(d, ['notifications', 'data', 'items']);
      if (!items.length) {
        list.innerHTML = '<p class="lg-sm__empty">No notifications yet.</p>';
        return;
      }
      list.innerHTML = items.map(function (n) {
        return '<div class="lg-notif__item' + (n.is_read ? '' : ' lg-notif__item--unread') + '">'
          + '<p class="lg-notif__text">' + esc(n.content || n.message || n.text || '') + '</p>'
          + '<span class="lg-notif__time">' + relTime(n.created_at || n.date) + '</span>'
          + '</div>';
      }).join('');
      fetch(API + '/me/notifications', { method: 'PATCH', credentials: 'include' })
        .catch(function () {});
      setBadge('[data-lg-notif-count]', 0);
    })
    .catch(function () {
      list.innerHTML = '<p class="lg-sm__error">Could not load notifications.</p>';
    });
}

/* ── messages: thread list ── */
function loadThreadList() {
  var list   = document.getElementById('lg-msg-list');
  var detail = document.getElementById('lg-msg-detail');
  if (!list) return;
  currentThreadId = null;
  list.hidden = false;
  if (detail) detail.hidden = true;
  list.innerHTML = '<p class="lg-sm__status">Loading...</p>';
  fetch(API + '/me/messages', { credentials: 'include' })
    .then(function (r) { return r.ok ? r.json() : Promise.reject(r.status); })
    .then(function (d) {
      var threads = extractArray(d, ['threads', 'data', 'messages']);
      if (!threads.length) {
        list.innerHTML = '<p class="lg-sm__empty">No messages yet.</p>';
        return;
      }
      list.innerHTML = threads.map(function (t) {
        var p      = t.other_participant || t.participant || {};
        var unread = t.unread_count || 0;
        var prev   = (t.last_message && t.last_message.content) || t.preview || '';
        return '<div class="lg-msg__thread' + (unread ? ' lg-msg__thread--unread' : '') +
          '" data-thread-id="' + esc(t.id || t.uuid) + '" tabindex="0" role="button">'
          + '<div class="lg-msg__av">' + avatarEl(p, 36) + '</div>'
          + '<div class="lg-msg__meta">'
            + '<div class="lg-msg__name">' + esc(p.display_name || p.name || 'Unknown') + '</div>'
            + '<div class="lg-msg__preview">' + esc(prev) + '</div>'
          + '</div>'
          + (unread ? '<span class="lg-sm__badge">' + capCount(unread) + '</span>' : '')
          + '</div>';
      }).join('');
      list.querySelectorAll('[data-thread-id]').forEach(function (el) {
        el.addEventListener('click', function () { openThread(el.dataset.threadId); });
        el.addEventListener('keydown', function (e) {
          if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); openThread(el.dataset.threadId); }
        });
      });
    })
    .catch(function () {
      list.innerHTML = '<p class="lg-sm__error">Could not load messages.</p>';
    });
}

/* ── messages: thread detail ── */
function openThread(uuid) {
  var list   = document.getElementById('lg-msg-list');
  var detail = document.getElementById('lg-msg-detail');
  var msgs   = document.getElementById('lg-msg-messages');
  if (!detail || !msgs) return;
  currentThreadId = uuid;
  if (list) list.hidden = true;
  detail.hidden = false;
  msgs.innerHTML = '<p class="lg-sm__status">Loading...</p>';
  fetch(API + '/me/messages/' + encodeURIComponent(uuid), { credentials: 'include' })
    .then(function (r) { return r.ok ? r.json() : Promise.reject(r.status); })
    .then(function (d) {
      var messages = extractArray(d, ['messages', 'data']);
      if (!messages.length) {
        msgs.innerHTML = '<p class="lg-sm__empty">No messages in this thread.</p>';
        return;
      }
      msgs.innerHTML = messages.map(function (m) {
        var mine = m.is_mine || m.sent_by_me;
        return '<div class="lg-msg__msg' + (mine ? ' lg-msg__msg--mine' : '') + '">'
          + '<p class="lg-msg__msg-text">' + esc(m.content || m.body || '') + '</p>'
          + '<span class="lg-msg__msg-time">' + relTime(m.created_at || m.sent_at) + '</span>'
          + '</div>';
      }).join('');
      msgs.scrollTop = msgs.scrollHeight;
      setTimeout(refreshCounts, 400);
    })
    .catch(function () {
      msgs.innerHTML = '<p class="lg-sm__error">Could not load thread.</p>';
    });
}

function sendReply() {
  if (!currentThreadId) return;
  var input = document.getElementById('lg-msg-reply-input');
  var text  = input ? input.value.trim() : '';
  if (!text) return;
  var saved = text;
  input.value    = '';
  input.disabled = true;
  fetch(API + '/me/messages/' + encodeURIComponent(currentThreadId), {
    method:      'POST',
    credentials: 'include',
    headers:     { 'Content-Type': 'application/json' },
    body:        JSON.stringify({ content: text }),
  })
    .then(function (r) {
      if (!r.ok) throw new Error(r.status);
      return openThread(currentThreadId);
    })
    .catch(function () { if (input) input.value = saved; })
    .then(function () { if (input) { input.disabled = false; input.focus(); } });
}

/* lg:open-dm dispatched by Social::renderProfileActions() on /u/ pages */
document.addEventListener('lg:open-dm', function () {
  openModal('lg-messages-modal');
  loadThreadList();
});

/* ── connections / friends ── */
function loadConnections() {
  var accepted       = document.getElementById('lg-conn-accepted');
  var pending        = document.getElementById('lg-conn-pending');
  var pendingSection = document.getElementById('lg-conn-pending-section');
  if (!accepted) return;
  accepted.innerHTML = '<p class="lg-sm__status">Loading...</p>';
  Promise.all([
    fetch(API + '/me/connections',         { credentials: 'include' }),
    fetch(API + '/me/connections/pending', { credentials: 'include' }),
  ])
    .then(function (rs) {
      return Promise.all(rs.map(function (r) { return r.ok ? r.json() : []; }));
    })
    .then(function (results) {
      var conns = extractArray(results[0], ['connections', 'data', 'users']);
      var reqs  = extractArray(results[1], ['requests',    'data', 'pending']);

      accepted.innerHTML = conns.length
        ? conns.map(function (c) {
            var u = c.user || c;
            return '<div class="lg-conn__item">'
              + avatarEl(u, 36)
              + '<a class="lg-conn__name" href="/u/' + esc(u.slug || '') + '">'
                + esc(u.display_name || u.name || '')
              + '</a></div>';
          }).join('')
        : '<p class="lg-sm__empty">No connections yet.</p>';

      if (reqs.length && pendingSection) {
        pendingSection.hidden = false;
        pending.innerHTML = reqs.map(function (req) {
          var u = req.from_user || req.user || req;
          return '<div class="lg-conn__item lg-conn__item--pending" data-conn-id="' + esc(req.id) + '">'
            + avatarEl(u, 36)
            + '<span class="lg-conn__name">' + esc(u.display_name || u.name || '') + '</span>'
            + '<div class="lg-conn__actions">'
              + '<button class="lg-conn__accept"  data-conn-id="' + esc(req.id) + '">Accept</button>'
              + '<button class="lg-conn__decline" data-conn-id="' + esc(req.id) + '">Decline</button>'
            + '</div></div>';
        }).join('');
        pending.querySelectorAll('.lg-conn__accept').forEach(function (btn) {
          btn.addEventListener('click', function () { respondToRequest(btn.dataset.connId, 'accept'); });
        });
        pending.querySelectorAll('.lg-conn__decline').forEach(function (btn) {
          btn.addEventListener('click', function () { respondToRequest(btn.dataset.connId, 'decline'); });
        });
        setBadge('[data-lg-conn-count]', reqs.length);
      } else if (pendingSection) {
        pendingSection.hidden = true;
      }
    })
    .catch(function () {
      accepted.innerHTML = '<p class="lg-sm__error">Could not load connections.</p>';
    });
}

function respondToRequest(id, action) {
  fetch(API + '/connections/' + encodeURIComponent(id), {
    method:      'PATCH',
    credentials: 'include',
    headers:     { 'Content-Type': 'application/json' },
    body:        JSON.stringify({ action: action }),
  })
    .then(function (r) { if (r.ok) loadConnections(); })
    .catch(function () {});
}

/* ── button hookup ── */
var notifBtn = document.querySelector('[data-lg-notif-link]');
var msgBtn   = document.querySelector('[data-lg-msg-link]');
var connBtn  = document.querySelector('[data-lg-conn-link]');

if (notifBtn) notifBtn.addEventListener('click', function (e) { e.preventDefault(); openModal('lg-notif-modal');       loadNotifications(); });
if (msgBtn)   msgBtn.addEventListener  ('click', function (e) { e.preventDefault(); openModal('lg-messages-modal');    loadThreadList();    });
if (connBtn)  connBtn.addEventListener ('click', function (e) { e.preventDefault(); openModal('lg-connections-modal'); loadConnections();   });

document.addEventListener('click', function (e) {
  var t = e.target;
  if ((t.hasAttribute && t.hasAttribute('data-lg-thread-back')) ||
      (t.closest && t.closest('[data-lg-thread-back]'))) { loadThreadList(); }
  if ((t.hasAttribute && t.hasAttribute('data-lg-send-reply')) ||
      (t.closest && t.closest('[data-lg-send-reply]')))  { sendReply(); }
});
document.addEventListener('keydown', function (e) {
  if (e.key === 'Enter' && !e.shiftKey &&
      e.target && e.target.id === 'lg-msg-reply-input') {
    e.preventDefault(); sendReply();
  }
});

/* ── init: load counts immediately + poll every 60s ── */
refreshCounts();
setInterval(refreshCounts, 60000);

})();
