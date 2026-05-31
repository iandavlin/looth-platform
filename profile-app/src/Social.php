<?php
declare(strict_types=1);

namespace Looth\ProfileApp;

require_once __DIR__ . '/Connections.php';

/**
 * Social — the on-profile Connect / Message widget for /u/ (and /p/).
 *
 * This is the ONE SLOT profile-2.0 consumes: it drops
 *   echo Social::renderProfileActions($viewer['uuid'] ?? null, $row['uuid']);
 * into the /u/ header card. The widget is purely SERVER-RENDERED off social state
 * (the "dumb host" pattern) — profile-2.0 owns the page, this lane owns the buttons.
 *
 * Actions are progressive: buttons carry data-* attributes and a one-time inline
 * script fetches the connection endpoints and reloads. NO SPA. The Message button
 * dispatches a `lg:open-dm` DOM event (detail.uuid) that lg-shell's header modal
 * hooks; if nothing listens it is a harmless no-op.
 *
 * Header-ceiling note: the buttons live INSIDE the header block, so the page's
 * effective-visibility gate already controls whether this widget renders at all
 * (private header → nothing; member header join-gates the public). This method
 * assumes the host decided the widget is allowed to render for this viewer.
 */
final class Social
{
    /** Returns the actions HTML (may be ''), self-contained incl. its one-time JS. */
    public static function renderProfileActions(?string $viewerUuid, string $profileUuid): string
    {
        // Own page → no buttons. Logged-out → an auth-gated Connect CTA.
        if ($viewerUuid !== null && $viewerUuid === $profileUuid) return '';

        if ($viewerUuid === null) {
            return self::wrap(
                self::btn('Connect', ['data-lg-social' => 'connect', 'data-requires-auth' => '1'])
            );
        }

        $edge   = Connections::stateWithId($viewerUuid, $profileUuid);
        $state  = $edge['state'];
        $cid    = $edge['id'];
        $target = htmlspecialchars($profileUuid, ENT_QUOTES);

        switch ($state) {
            case 'blocked':
                return '';

            case 'accepted':
                $html = self::btn('Connected', ['disabled' => '1', 'data-lg-social' => 'connected'])
                      . self::btn('Message', ['data-lg-social' => 'message', 'data-to-uuid' => $target]);
                break;

            case 'pending_out':
                $html = self::btn('Requested', ['disabled' => '1', 'data-lg-social' => 'requested'])
                      . self::btn('Cancel', ['data-lg-social' => 'cancel', 'data-cid' => (string)$cid]);
                break;

            case 'pending_in':
                $html = self::btn('Accept', ['data-lg-social' => 'accept', 'data-cid' => (string)$cid])
                      . self::btn('Decline', ['data-lg-social' => 'decline', 'data-cid' => (string)$cid]);
                break;

            case 'none':
            default:
                $html = self::btn('Connect', ['data-lg-social' => 'connect', 'data-to-uuid' => $target]);
                break;
        }

        return self::wrap($html);
    }

    private static function wrap(string $inner): string
    {
        return '<div class="lg-social-actions" data-lg-social-actions>' . $inner . '</div>' . self::script();
    }

    private static function btn(string $label, array $attrs): string
    {
        $a = '';
        foreach ($attrs as $k => $v) {
            if ($k === 'disabled') { $a .= ' disabled'; continue; }
            $a .= ' ' . $k . '="' . htmlspecialchars((string)$v, ENT_QUOTES) . '"';
        }
        return '<button type="button" class="lg-btn lg-social-btn"' . $a . '>'
             . htmlspecialchars($label, ENT_QUOTES) . '</button>';
    }

    /** One-time inline wiring (guarded so it prints once even with many widgets). */
    private static function script(): string
    {
        static $printed = false;
        if ($printed) return '';
        $printed = true;

        return <<<'JS'
<script>
(function () {
  if (window.__lgSocialWired) return; window.__lgSocialWired = true;
  var API = '/profile-api/v0';
  function post(url, body, method) {
    return fetch(url, {
      method: method || 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'same-origin',
      body: body ? JSON.stringify(body) : null
    });
  }
  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-lg-social]');
    if (!b) return;
    var act = b.getAttribute('data-lg-social');
    var cid = b.getAttribute('data-cid');
    var to  = b.getAttribute('data-to-uuid');

    if (act === 'message') {
      document.dispatchEvent(new CustomEvent('lg:open-dm', { detail: { uuid: to } }));
      return;
    }
    if (b.getAttribute('data-requires-auth')) {
      document.dispatchEvent(new CustomEvent('lg:require-auth', { detail: { reason: 'connect' } }));
      return;
    }
    b.disabled = true;
    var p;
    if (act === 'connect')      p = post(API + '/connections', { addressee_uuid: to });
    else if (act === 'accept')  p = post(API + '/connections/' + cid, { action: 'accept' }, 'PATCH');
    else if (act === 'decline') p = post(API + '/connections/' + cid, { action: 'decline' }, 'PATCH');
    else if (act === 'cancel')  p = post(API + '/connections/' + cid, { action: 'cancel' }, 'PATCH');
    else { b.disabled = false; return; }
    p.then(function () { location.reload(); })
     .catch(function () { b.disabled = false; });
  });
})();
</script>
JS;
    }
}
