# Session handoff — poller lane

> **Currently active task:** membership pages onto shared shell. **PoC LANDED
> 2026-05-29** — `/membership-guide/` renders on shared chrome (anon + member);
> see "2026-05-29 — membership-pages PoC" below. Remaining: generalize to all 11
> slugs + BB/Elementor dequeue + `lg_member_nav` cleanup + P8.
> See [briefing-membership-pages.md](briefing-membership-pages.md) +
> [notes-for-rotated-chat-membership-pages.md](notes-for-rotated-chat-membership-pages.md)
> (tacit knowledge from the prior chat — read after the briefing, before code).
>
> **Still on the lane:** P8 poller dormant-mode dev smoke. Not started.
>
> **Closed cross-cutting threads:** header name ack ✓, secret file ✓, round-trip purge 204 ✓.

---

## Shipped this lane (most recent first)

| Date | Item | Section anchor |
|---|---|---|
| 2026-05-29 | **Membership-pages PoC** — `/membership-guide/` renders on shared `/srv/lg-shared/` chrome (anon + member), BB theme nav bypassed via a `template_include` mu-plugin. Executed from coordinator session (sub-agent was permission-blocked). | "2026-05-29 — membership-pages PoC" |
| 2026-05-28 | Round-trip purge live; PurgeNotifier supports loopback + Host override; 204 verified end-to-end via Arbiter | "Round-trip purge SHIPPED" |
| 2026-05-28 | Arbiter stripe-source coexistence guard (mirrors LGPO's); uid=1805 no longer downgraded | "Arbiter stripe guard" |
| 2026-05-28 | Patreon adapter (P2) — `PatreonSourceReader` + `RoleSourceWriter::readAllForUser` merge; provenance now `paid` for patreon users | "Patreon adapter shipped (P2)" |
| 2026-05-28 | `LG_PROFILE_APP_URL` constant (P4) — `PurgeNotifier` reads base from wp-config define | "P4 shipped" |
| 2026-05-27 | `GET /wp-json/looth-internal/v1/user-context/{id}` endpoint; `looth_tier_changed` action; `PurgeNotifier` first cut; secret file `/etc/lg-internal-secret`; nginx exempt | "user-context + action + purge SHIPPED" |
| 2026-05-17 | lg-stripe checklist ~75+ items verified, 16 code/config changes (mailpit, CDP bridge, throttles, welcome email merge, timestamp-poller rewrite, looth1 sticky bypass, Starter BB type, etc.) | "Code shipped this session" (original section) |

## Outstanding within lane

- **P8 ⏳** Poller dormant-mode dev smoke — confirm WP request path doesn't crash when Stripe creds are absent / poller disabled. Inherited by the rotating chat.
- **Membership pages** (in flight per active task above).

## Outstanding from earlier sessions (still flagged, not blocking)

- Fluent SMTP stores AWS SES access key plaintext in `wp_options`
- `subscriber` role has author-level posting caps (security)
- `customer` role residue from old buddyforms flow
- `bp_read` asymmetry: subscriber yes, looth1-4 no

---

# (Original handoff begins below — preserved for context.)

# Session handoff — checklist run for lg-stripe-billing / lg-patreon-stripe-poller

Written by the previous Claude session on 2026-05-17 ~19:00 UTC. Pick up
from here. The user ran out of patience for context, not for work.

## What this whole session has been

Working through `[lg_test_checklist]` (rendered at /test-checklist/, source
at [TestChecklist.php](/var/www/dev/wp-content/plugins/lg-patreon-stripe-poller/src/Wp/TestChecklist.php))
end-to-end. ~75+ items verified across cron/security/auth/manage-sub/
gift/refund/MG/admin/roles. 16 code/config changes shipped to fix things
found while testing. Decisions parked in
[PROD-CUTOVER.md](/srv/lg-stripe-billing/PROD-CUTOVER.md) under "Decisions
to be finalized" section.

## Infra you need to know is running

| Thing | Where | Notes |
|---|---|---|
| **Mailpit** SMTP catcher | systemd unit `mailpit.service`, UI at https://dev.loothgroup.com/mailpit/ (cookie-gated) | Started this session. /etc/msmtprc routes www-data sendmail to it. Fluent SMTP is the active mailer — Mailpit only catches if Fluent is deactivated first. |
| **Chromium-in-browser** | docker container `chromium`, KasmVNC UI at https://browser.dev.loothgroup.com/ (cookie-gated) | Wayland — xdotool won't work. CDP is the way. |
| **CDP bridge** | nsenter+socat from host port 9222 → container's localhost:9222 | **Dies on container restart.** Restart cmd: see "Restoring CDP" below. |
| **CDP driver script** | [cdp.py](/home/ubuntu/projects/cdp.py) | Auto-handles native JS dialogs (confirm/alert) since v2. 30s WS timeout. |

### Restoring CDP after a container restart

```bash
sudo pkill -f 'socat.*9222' 2>/dev/null
CPID=$(sudo docker inspect -f '{{.State.Pid}}' chromium)
CIP=$(sudo docker inspect -f '{{range .NetworkSettings.Networks}}{{.IPAddress}}{{end}}' chromium)
sudo nsenter -t $CPID -n socat TCP-LISTEN:9222,bind=$CIP,reuseaddr,fork TCP:127.0.0.1:9222 >>/tmp/cdp-bridge.log 2>&1 &
sleep 2
curl -sS http://127.0.0.1:9222/json/version  # should return Chrome/...
```

### If chromium itself died (closing the only tab kills it)

```bash
sudo docker rm -f chromium
sudo docker run -d --name chromium --restart unless-stopped \
  --security-opt seccomp=unconfined --shm-size="2gb" \
  -e PUID=1000 -e PGID=1000 -e TZ=America/New_York \
  -e TITLE="Loothdev Browser" \
  -e CHROME_CLI="--remote-debugging-port=9222 --remote-debugging-address=0.0.0.0 --remote-allow-origins=*" \
  -v /srv/browser-container/config:/config \
  -p 127.0.0.1:3010:3000 -p 127.0.0.1:9222:9222 \
  lscr.io/linuxserver/chromium:latest
sleep 10
# then re-run the bridge above
```

## Test users (all alive in DB, passwords known)

| Login | Pass | Role(s) | Purpose |
|---|---|---|---|
| `claude_admin` | `ClaudeAdmin1779036333!` | administrator | wp-admin work |
| `qa_lite_1779037646` (id 1906) | `QaTestPw1!` | subscriber, bbp_participant, looth3 (was LITE→PRO switched, sub canceled+refunded) | manage-sub tests |
| `qa_giftbuyer_1779043789` (id 1914) | `QaTestPw1!` | bbp_participant, looth1, type=starter | gift dashboard tests |
| `qa_pastdue_1779044650` (id 1915) | `QaTestPw1!` | looth2 with sub_qa_pastdue_… in past_due | past-due 409 test |
| `qa_u_1779034315` (id 1903) | `QaPassword1!` | subscriber | auth throttle tests |

Customer IDs in lg_membership.customers: 117 (qa_lite, blocked), 126 (qa_giftbuyer), 127 (qa_pastdue). Fixture gift codes for buyer 126: `QAFIXT*` (7 codes in mixed states).

## Code shipped this session (file backups under /tmp/*.bak)

1. **IP throttle counts failures only** — [RestController.php](/var/www/dev/wp-content/plugins/lg-patreon-stripe-poller/src/Wp/RestController.php) optimistic pre-bump + success-undo
2. **Per-email throttle counts validation failures** — same file, same pattern
3. **`customer.subscription.trial_will_end` handled** — [EventHandler.php](/var/www/dev/wp-content/plugins/lg-patreon-stripe-poller/src/Stripe/EventHandler.php) new `onTrialWillEnd`
4. **Existing-account modal opens clean** — [Shortcodes.php:~3389](/var/www/dev/wp-content/plugins/lg-patreon-stripe-poller/src/Wp/Shortcodes.php) dropped inline "Incorrect password." string
5. **Welcome modal CTAs renamed** — [Plugin.php:555-556](/var/www/dev/wp-content/plugins/lg-patreon-stripe-poller/src/Plugin.php) Take the tour → Member Guide; Jump to the feed → See What's New
6. **Welcome email merge** — slim template at [welcome-membership.html.php](/var/www/dev/wp-content/plugins/lg-patreon-stripe-poller/templates/email/welcome-membership.html.php) (was 407 lines, now ~60); password-reset link folded in from legacy [UserProvisioner::sendWelcomeEmail](/var/www/dev/wp-content/plugins/lg-patreon-stripe-poller/src/Wp/UserProvisioner.php) which is now a no-op
7. **Stripe poller rewritten timestamp-based** — [Poller.php](/var/www/dev/wp-content/plugins/lg-patreon-stripe-poller/src/Stripe/Poller.php) fixes same-second cursor leapfrog; 60s overlap window + lg_processed_events dedup
8. **Personal one-time membership purchases removed** — [Shortcodes.php loadProducts](/var/www/dev/wp-content/plugins/lg-patreon-stripe-poller/src/Wp/Shortcodes.php) filters out `price.type==='one_time'` for /lgjoin/ picker; [CheckoutController.php](/srv/lg-stripe-billing/src/Http/Controllers/CheckoutController.php) returns 400 on `gift=false + one_time`. Gift path unchanged.
9. **Disabled mu-plugin file deleted** — `dev-admin-only-login.php.disabled`
10. **`customer` removed from `GIFT_CAPABLE_ROLES`** — [Plugin.php:35](/var/www/dev/wp-content/plugins/lg-patreon-stripe-poller/src/Plugin.php) + cap revoked from existing customer role
11. **Looth1 Arbiter bypass (sticky)** — [Arbiter.php](/var/www/dev/wp-content/plugins/lg-patreon-stripe-poller/src/Arbiter.php) skips looth1 in the role-removal loop. Means UserProvisioner's `role=looth1` is permanent.
12. **BB Starter Profile Type** — created via wp-admin (post 69093, slug `starter`), with both hide flags. Requires Profile Types component which was enabled this session.
13. **UserProvisioner tags new looth1 users as Starter** — [UserProvisioner.php:50-58](/var/www/dev/wp-content/plugins/lg-patreon-stripe-poller/src/Wp/UserProvisioner.php)
14. **Arbiter syncs Starter type to winning tier** — [Arbiter.php:50-62](/var/www/dev/wp-content/plugins/lg-patreon-stripe-poller/src/Arbiter.php) looth1/null → starter; looth2+ → cleared
15. **Mailpit + nginx + msmtp + CDP bridge** (infra, see top)
16. **PROD-CUTOVER.md Decisions section** seeded over three batches

## Gotchas you'll trip over otherwise

- **Wayland blocks xdotool** — use CDP exclusively for UI driving. Native `confirm()` dialogs would have wedged chromium; cdp.py now auto-handles them but if you fork a new driver, replicate the `Page.javascriptDialog` handler.
- **Closing the last chromium tab kills the container** (no UI = exit). Always have at least one tab open. If you must close, do `/json/new?<url>` immediately.
- **Stripe poller can leapfrog same-second events** — was fixed this session. If you see "skip" entries for new events but no `invoice.paid` processing, check tick.log AND verify cursor format is unix-timestamp not `evt_…`.
- **Email routing is split**: www-data sendmail → /etc/msmtprc → Mailpit. `ubuntu` sendmail → /home/ubuntu/.msmtprc → Gmail directly (this is how "email me X" still reaches the user). If you toggle Fluent SMTP off to capture wp_mail, **flip it back on when done** or app emails leak to test recipients via legacy SES.
- **Fluent SMTP is currently ACTIVE**. Welcome / refund / gift emails go to real SES → real inboxes. Use `+qa-*` Gmail aliases for any test recipients you don't want spam-flagged.
- **Looth1 is sticky now**. Will never be removed by Arbiter. Manual `wp user remove-role` if needed.
- **Looth4 is protected**. Arbiter short-circuits with `reason="looth4 protected, skipped"` — no role sync runs at all for looth4 users.
- **Customer 117 (qa_lite) is BLOCKED** (admin Cancel & Refund auto-blocks). If you want to re-test with that user you'd need to clear `customers.blocked_at` first.
- **Test gift code IDs are in DB**: see `lg_membership.gift_codes WHERE purchased_by=126`. One was voided (QAFIXTUNS01), one reassigned (QAFIXTSNT01), QAFIXTUNS02 was sent. The fixtures are usable for re-runs.

## Bugs/findings flagged but NOT fixed

1. **Fluent SMTP stores AWS SES access key plaintext in `wp_options.fluentmail-settings`** — secret key encrypted, access key in plaintext.
2. **`subscriber` role has author-level posting caps** (`create_posts`, `edit_posts`, `publish_posts`, `level_0`, etc.) — residue from earlier "set up author privileges" work. Anyone on the default subscriber role can publish posts. Real security concern. Worth stripping subscriber down to bare `read`.
3. **`customer` role has 4 leftover buddyforms caps** even after we revoked `manage_gift_codes`. Probably safe to delete the entire customer role from the install — nothing in the new flow uses it.
4. **`bp_read` asymmetry**: subscriber has it, looth1-4 don't. Looth users may behave oddly in BP-gated areas as a result.
5. **`gift-qty-server` checklist item is stale** — current code allows `qty=1` with explicit `gift=true`. Checklist text needs updating.
6. **Admin "Cancel & Refund" always auto-blocks** the customer (extra `admin_action_log` row `auto_block_after_refund`). Decision queued: configurable per-case vs always-on. See PROD-CUTOVER.

## Queue still pending

Lower priority since most of the meaty stuff is done:

- **Affiliate inline-edit `Save rates`** — my form submit didn't update the DB; needs nonce/affiliate-id handling. Edit panel display is verified.
- **Cancel-immediate** — basically identical to cancel-period-end (passed). Worth a 2-min round-trip on a fresh sub for completeness.
- **Real-inbox deliverability** items (em-welcome-gmail / outlook / apple-mail, em-refund-admin reply-to in real inbox, em-payment-failed) — needs **user eyeballs on real Gmail/Outlook/Apple inboxes**. Don't waste cycles trying to verify these programmatically.
- **MG sub-items** — recurring shows / events list rendering details, elder bio page links (mostly verified, a few selectors could be tightened).
- **gift-qty-server** — rewrite the checklist item text rather than the code (see flagged finding #5).
- **Past-due test** is fully done; cancel-immediate is the only ms-* item not run.
- **looth1/2/3 gated post browsing** — the user added this to the queue but then asked to pause because they're "going to cook up a new posting mechanism anyway." Skip unless asked.

## Pending decisions in PROD-CUTOVER.md

See "Decisions to be finalized" section near bottom of [PROD-CUTOVER.md](/srv/lg-stripe-billing/PROD-CUTOVER.md). Currently includes:

- Regional pricing country → region_tag map (boilerplate started by previous Claude/work, needs sign-off)
- Discount scale per tier confirmation
- One-time personal-membership drop (decided: dropped; code shipped)
- Cancel-only-PM enforcement UX
- Trial reminder copy pass
- Welcome email content scope (slim template OK?)
- Admin Cancel & Refund auto-block policy
- Looth1 sticky bypass implications
- BB Starter Profile Type rationale

## How the user collaborates

- Fast feedback, doesn't over-spec. Trusts you to pick reasonable defaults and surface tradeoffs.
- Likes terse status updates with concrete evidence (DB rows, exact error strings, file:line refs).
- Will tap in when needed ("you can call me in to check or push or fill out as needed").
- Prefers driving the actual UI in the shared Chromium for verifiability when feasible.
- Don't over-narrate — say what changed and what's next. They read diffs.
- Asks `?` when they don't get something — explain the model, not just the symptom.

## To resume

1. Check infra:
   ```bash
   sudo systemctl status mailpit --no-pager | head
   sudo docker ps --filter name=chromium --format '{{.Status}}'
   curl -sS http://127.0.0.1:9222/json/version | head -3
   ```
2. If CDP probe fails, see "Restoring CDP" above.
3. Skim the queue, ask the user which thread to pull, then push.

---

## 2026-05-27 — Coordinator briefing absorbed (addendum, not a rotation)

Briefing v2 at `docs/briefing-stripe-poller.md` read (revised mid-session
— the v2 answered prior clarification asks). Coordinator's session state
at `docs/STRANGLER-SESSION-HANDOFF.md` also read.

### Positions back to coordinator (Ian to route)

**1. Endpoint shape — ack `/wp-json/looth-internal/v1/user-context/{id}` returning `{tier, provenance, capabilities}`.** Briefing v2 settled the prior clarifications: looth2→lite, looth3→pro, looth4→pro; provenance enum `paid|comp|lapsed|new`; capabilities computed via `user_can($uid, $cap)` (so whoever owns each cap-grant remains authoritative — not the poller's call). No changes requested.

**2. Shared-secret pattern — mirror archive-poc, with one variable rename.**

Archive-poc today (`/home/ubuntu/projects/archive-poc/api/v0/_config.php:86-97`):
- **Secret file:** `/etc/lg-archive-poc-secret` (outside source, deploy-provisioned, root-readable)
- **PHP constant:** `LG_ARCHIVE_POC_CONFIG_SECRET` (loaded by archive-poc's `config.php`)
- **Header:** `X-LG-Config-Secret` (purpose-named, not generic)
- **Verify:** `hash_equals()` constant-time compare

Proposed for the symmetrical poller↔profile-app channel (one secret, both directions):
- **Secret file:** `/etc/lg-internal-secret` (single shared key, used by both ends)
- **PHP constant** (poller side): `LG_INTERNAL_SECRET`
- **Header:** `X-LG-Internal-Auth` — the briefing v2 already uses `X-Looth-Internal-Auth`; suggest renaming to `X-LG-Internal-Auth` so all internal-channel headers share the `X-LG-` prefix that archive-poc established. Cosmetic only — flag for coordinator to choose.

**3. Arbiter purge hook — safe under the new timestamp-based poller; recommend a centralizing action.**

- The 60s overlap + `lg_processed_events` dedup means Arbiter may be *invoked* twice for the same event, but `Arbiter::apply()` already short-circuits when no role change is computed → no spurious purges.
- **Burst writes during a poll tick (the briefing's explicit concern):** a poll tick can apply N role changes back-to-back across distinct users; each fires one purge. That's N fire-and-forget HTTP POSTs per tick — fine for profile-app (idempotent), fine for the poller (non-blocking). The only real risk is if profile-app is *down*: requests stack up in PHP-FPM workers. Mitigation = strict 1s timeout + `blocking=false`. No retry queue needed.
- **Recommendation:** centralize via a new WP action `do_action('looth_tier_changed', $user_id, $old_role, $new_role, $provenance)` fired by every writer (Arbiter, UserProvisioner signup grant, admin role edits, refund/cancel paths). Purge subscribes to that single action. Otherwise we'll miss invalidations on non-Arbiter writes.
- Transport: `wp_remote_post` with `blocking=false`, 1s timeout, no retry.

**4. Post-cutover poller-shim direction (briefing §3) — agree.** Pulling Stripe keys out of the WP attack surface is the right move. Not blocking cutover. Roadmap item; will not start until checklist work and tier-lookup endpoint are both green.

### Poller queue — visibility for coordinator

What's still owed within the poller's lane (informational, so coordinator can predict when this lane has bandwidth for cutover work):

| Item | Size | Blocking cutover? |
|---|---|---|
| Affiliate inline-edit `Save rates` (nonce/affiliate-id) | ~30 min | No |
| Cancel-immediate verification round-trip | ~5 min | No |
| `gift-qty-server` checklist text rewrite (no code) | ~5 min | No |
| Real-inbox deliverability (Gmail/Outlook/Apple) | needs Ian's eyeballs | Yes |
| MG sub-item selector tightening | ~15 min | No |
| **Tier-lookup endpoint build** (`/user-context/{id}`) | ~2 hours once clarifications land | **Yes** |
| **`looth_tier_changed` action + purge hook** | ~1 hour | **Yes** |

Two items in the right column are the only ones gating profile-app cutover from this lane. Both ready to start once looth2/3 mapping + `edit_archive_poc` rule are answered.

### Flagged findings — still unfixed, surfaced to coordinator

(From the 2026-05-17 list, still open; no new ones discovered today.)
- Fluent SMTP stores AWS SES access key plaintext in wp_options
- `subscriber` role has author-level posting caps (security)
- `customer` role residue from old buddyforms flow
- `bp_read` asymmetry: subscriber yes, looth1-4 no

None are cutover blockers but the subscriber-role one is a real
security finding worth scheduling.

### Open design decision — coordinator please weigh in

Building `/user-context/{id}` per the green-lit ~3h scope. One decision
gates the build and I want it ratified, not assumed.

**Question:** how does the poller derive `provenance` (`paid|comp|lapsed|new`)?

Today `RoleSourceWriter::readAllForUser($id)` returns a flat array of
tier strings — it tells me *what tier* each source reports, not *which
kind of source* (subscription / gift / admin-grant / fallback) produced
it. The provenance enum needs source-type info to answer correctly.

**Option (a) — heuristic from existing state.** No refactor.
- looth4 present → `comp`
- looth1 only, user_registered within last N days, no other sources → `new`
- looth1 only, was-higher-before → `lapsed`
- looth2/3 with any source → `paid`

Ships in the ~3h envelope. Edge cases will be wrong (e.g. a long-time
looth1-from-day-one gift-buyer reads as `lapsed` if they ever had a
trial → `paid` → `lapsed-back-to-looth1` flip; or a paid user whose
single active source is a gift code reads as `paid` when arguably
they're a gift-recipient and should be tagged differently).

**Option (b) — extend `RoleSourceWriter::readAllForUser`** to return
`[source_type => tier]` instead of `[tier, tier, ...]`. ~30min extra,
single file edit, doesn't touch the writer side (it already knows the
source type internally; just stops throwing it away on read). Then
provenance derivation is deterministic from the returned shape.

**Poller chat's lean: (b).** Provenance is in the public response shape;
profile-app's cache will be stamped with whatever we send. Getting it
wrong on day one means a population of caches to invalidate later. The
refactor is small and contained to the poller's own internals — nothing
crosses a chat boundary.

**Hold-fire pattern:** I will not start the build until coordinator
acknowledges. Independent work continues in parallel (queue items in
the table above).

### Infra check today (2026-05-27 ~22:50 UTC)

- mailpit: active (since 21:47 UTC)
- CDP probe: 127.0.0.1:9222 → Chrome/148.0.7778.178 ✓
- chromium: now systemd `chrome-dev.service` (not docker; handoff text above is stale on that — see [[reference-chrome-dev-login]])

---

## 2026-05-27 ~23:20 UTC — user-context + action + purge SHIPPED

Coordinator green-lit option (b) (deterministic provenance from sources).
Build done in ~50min total (refactor was a non-event — RoleSourceWriter
already returned `[source => tier]` from line 33).

### Shipped

| File | Change |
|---|---|
| `/etc/lg-internal-secret` | new — root:www-data 0640, 64-hex-char openssl random |
| `wp-config.php` | new — `LG_INTERNAL_SECRET` define loaded from /etc/lg-internal-secret |
| `src/Wp/InternalRestController.php` | new — `GET /wp-json/looth-internal/v1/user-context/{id}` with shared-secret auth, tier/provenance/capabilities derivation. Provenance derivation extracted as `public static deriveProvenance(?string $tierRole, array $sources)` so Arbiter shares the same logic. |
| `src/PurgeNotifier.php` | new — subscribes to `looth_tier_changed`, fires non-blocking POST to `/profile-api/v0/internal/purge-whoami` with 1s timeout |
| `src/Arbiter.php` | edited — fires `do_action('looth_tier_changed', $uid, $old, $new, $provenance)` at end of `sync()` only when `$oldTier !== $winning` |
| `src/Wp/UserProvisioner.php` | edited — fires action on signup grant with `(uid, null, 'looth1', 'new')` |
| `src/Plugin.php` | edited — registers `InternalRestController` on `rest_api_init` and calls `PurgeNotifier::register()` |
| `/etc/nginx/sites-available/dev.loothgroup.com.conf` | edited — added `location ^~ /wp-json/looth-internal/` cookie-gate exempt block (mirrors `lg-member-sync`) |

Backups: `/tmp/wp-config.php.bak.20260527`, `/tmp/nginx-dev.conf.bak.20260527`.

### Test evidence (smoke ran 2026-05-27 23:18-23:20 UTC)

Endpoint:
- `no auth → 401`, `wrong secret → 401`, `right secret → 200`
- looth1 user → `tier=public, provenance=lapsed/new` per sources
- looth3 user (uid=7) → `tier=pro, provenance=new` (no source rows)
- looth4 user (uid=8) → `tier=pro, provenance=comp` (looth4 protected)
- nonexistent uid → `404 no_such_user`
- subscriber-only (uid=1903) → `tier=public, provenance=new`
- capabilities: `edit_posts`, `manage_options`, `edit_archive_poc` via `user_can`; `moderate_forums` via role-membership check (administrator | bbp_keymaster | bbp_moderator)

End-to-end transition (qa_lite uid=1906):
- Promote looth1→looth2 (via manual_admin source): action fired `old=looth1, new=looth2, prov=comp`, purge POST captured to correct URL with correct payload + 64-char auth header
- Revert looth2→looth1: action fired `old=looth2, new=looth1, prov=lapsed`, purge POST captured
- No-op `sync()` on stable looth1: action did NOT fire (guard works) ← confirms dedup/overlap concern from briefing v2 §2

### Outstanding nits / followups

1. **Header naming.** Briefing v2 used `X-Looth-Internal-Auth`; I shipped `X-LG-Internal-Auth` to match archive-poc's `X-LG-` convention. profile-app needs to know this for their reciprocal call. **Reply needed: ack the header name.**
2. **Endpoint URL is dev.loothgroup.com hardcoded** in PurgeNotifier. On live, profile-app endpoint will be a different host. Need a `LG_PROFILE_APP_URL` config or wp-option before cutover.
3. **TODO comment in code** about gift-recipient → potential 5th `gifted` enum value (per coordinator note). Don't expand now.
4. **Coordinator note**: `provenance=new` shows up for two distinct cases: (a) truly new accounts with no source rows, (b) legacy users with looth* roles that pre-date the source-writer system (e.g. uid=7 has looth3 with no source rows → reads as `new`). Not wrong per the enum's literal definition ("no sources recorded → never paid through the modern pipeline"), but worth flagging. Could be tightened later by adding a `legacy` provenance value or by backfilling source rows. Not blocking.

### Coordinator ack needed

- Header name: `X-LG-Internal-Auth` (chose this over briefing's `X-Looth-Internal-Auth` to keep `X-LG-` prefix consistent with archive-poc). OK?
- profile-app needs to know secret file is `/etc/lg-internal-secret` and to load the same value into its own constant.

---

## 2026-05-27 ~23:35 UTC — backlog: affiliate Save rates

**Result: NOT A BUG. Closing the queue item.**

Tested the `lgms_update_affiliate_commission` admin-post handler two ways
against affiliate id=17 (dan, 0% baseline), both succeeded with `lgms_aff_ok=Saved.`
notice and DB updated to exact submitted values:

1. **curl POST** with valid nonce + WP auth cookies → DB updated to 12.50/18.75/3.25 ✓
2. **CDP browser click on Save rates button** (claude_admin session via chrome-dev) → DB updated to 7.77/8.88/1.11 ✓

Reverted dan to 0/0/0 after testing.

**The prior session's "form submit didn't update the DB" claim was a CDP
test-driver artifact, not a code bug.**

WP's `submit_button()` helper renders `<input type="submit" name="submit" …>`.
That input *shadows* the HTMLFormElement's `submit()` method, so the JS
expression `form.submit()` throws `TypeError: form.submit is not a function`
silently in a CDP `Runtime.evaluate` call. Previous Claude almost certainly
tried `form.submit()`, the TypeError didn't surface in their reporting flow,
and they concluded the handler was broken.

**Fix for future CDP-driven admin-form tests:** use `form.requestSubmit()`
(triggers proper submit event, ignores the shadowing input), or click the
actual button element. Don't use `form.submit()` on any form that contains
an input or button named "submit" — and standard WP admin forms always
do (via `submit_button()`).

No code change. Next pick from queue: cancel-immediate verification.

---

## 2026-05-27 ~23:50 UTC — backlog burn complete

Per Ian's "burn the queue, ping only on cross-lane impact" directive,
worked through the remaining items without coordinator round-trip:

**Cancel-immediate** — **code-review verified, not live-tested.**
- All active Stripe subs in DB are Ian's / James's real test subs
  (`ian.davlin+N@gmail.com`, `jamesroadman+test{1,2}@gmail.com`).
  Canceling any of them is a non-reversible Stripe mutation on data
  I don't own — wrong blast-radius for an unsupervised queue item.
- Code path `RestController::meCancelSubscription` is structurally
  identical to cancel-at-period-end: same entrypoint, same
  `resolveOwnedSub` ownership check, same input validation, same
  error/AdminAlerts path. Only divergence is the Stripe API call
  (`subscriptions->cancel(...)` vs `->update(['cancel_at_period_end'=>true])`)
  which is correct per Stripe's API.
- Downstream: webhook `customer.subscription.deleted` is explicitly
  handled in `EventHandler` (Poller.php:169 — "canceled / incomplete_expired
  → revoke immediately") → Arbiter → role downgrade → new
  `looth_tier_changed` action fires → purge.
- Downgrading from "needs round-trip" to "verified-by-code-review."
  Re-test live if/when a fresh disposable sub is provisioned for
  some other reason; not worth standing one up just for this.

**`gift-qty-server` checklist text** — **already accurate, no change.**
- Real slug is `gb-qty-rules` (TestChecklist.php:503), not
  `gift-qty-server` as the handoff said.
- Current text correctly describes the three cases including
  qty=1 + gift=true accepted as 1-seat gift. Item is stale in the
  handoff; the work was done.

**MG sub-item selector tightening** — **one fix applied.**
- Inspected live DOM at `/membership-guide/` (logged-in admin via CDP).
- `mg-shows`: accurate as written (already notes "no dedicated wrapper id").
- `mg-events`: accurate — `#events .upcoming`, `.ev-card`, `.ev-date-pill`,
  `.ev-title`, `.ev-thumb` all present with inline `background-image: url(...)`.
- `mg-elders`: **selector text was wrong.** Old text said "Each card
  has avatar + name + optional IG/website links. 'View bio' hrefs
  follow /elder-{slug}/." But the rendered DOM has the entire card
  as a single `<a class="elder" href="/elder-{slug}/">` — there is
  no separate "View bio" link inside, and IG/website links are not
  on the card (they're on the bio destination page). Tightened to:
  ```
  Container .elders renders one <a class="elder" href="/elder-{slug}/">
  per option entry (count matches lgms_guide_elders, currently 7).
  Each card contains .lgms-elder-pic (avatar), .lgms-elder-name, and
  .lgms-elder-cta ("VIEW BIO"). The card root IS the bio link — no
  separate "View bio" anchor inside. Bio destination /elder-{slug}/
  resolves to a published post.
  ```
- File: `src/Wp/TestChecklist.php:547`.

### Queue status after burn

| Item | Status |
|---|---|
| Affiliate Save rates | closed — CDP-driver artifact (`form.submit()` shadowing), not a code bug |
| Cancel-immediate | code-review verified, no live test |
| `gift-qty-server` (real: `gb-qty-rules`) | text already accurate; handoff stale |
| MG sub-item selectors | `mg-elders` tightened; `mg-shows`/`mg-events` accurate |
| Real-inbox deliverability (Gmail/Outlook/Apple) | still needs Ian's eyeballs — unchanged |
| `looth1/2/3` gated post browsing | still paused per Ian — unchanged |

Coordination-side ack-needed items (header name, secret file path) and
the round-trip verification with profile-app's purge receiver are the
only outstanding cross-lane threads. Both are on the coordinator's
court.

---

## 2026-05-28 — status report for new coordinator session

Re-verified shipped pieces still clean against dev:

- **`GET /wp-json/looth-internal/v1/user-context/{id}`** — auth gate
  works (401 / 401), 5 tier cases return expected shapes, 404 on
  missing user. No regressions since 2026-05-27 ship.
- **`do_action('looth_tier_changed', ...)`** — captured-filter test
  shows the action fires correctly through `PurgeNotifier`. Payload:
  64-char auth header, blocking=false, timeout=1, body `{"wp_user_id":N}`.
- **Arbiter no-op suppression** — `sync(1906)` on stable looth1 still
  does NOT fire the action (guard works; no spurious purges under
  the timestamp-based poller's 60s overlap + dedup).

### Patreon adapter spec (per coord's marking order §2)

**Not started.** No BATCH-04 paste-back context in this session — the
new coordinator's manifest lists the "active poller chat" as session
`7c518e34` (different from this conversation). If this conversation is
meant to pick up that work, I need BATCH-04 output relayed.

### `LG_PROFILE_APP_URL` (per coord's marking order §3)

**Still open.** `src/PurgeNotifier.php:25` hardcodes
`https://dev.loothgroup.com/profile-api/v0/internal/purge-whoami`.

Cheap to land — one-line edit to read from a `LG_PROFILE_APP_URL`
constant defined in `wp-config.php` (same pattern as `LG_INTERNAL_SECRET`).
Held off pre-cutover because: (a) on dev the dev URL is correct, and
(b) the live URL hadn't been decided when I shipped. Will land when
coordinator confirms the live URL (and whether dev should still hit
dev.loothgroup.com or some other hostname).

This is P4 on the cutover-eligibility checklist — happy to take it
now if coordinator wants it before cutover-window prep.

### Cross-cutting questions still open

1. ~~**Header name ack.**~~ **Ratified by coordinator 2026-05-28** —
   `X-LG-Internal-Auth` is correct. profile-app will mirror exactly.
2. ~~**Secret file path coordination.**~~ **Coord ack 2026-05-28** —
   profile-app will join `www-data` group or get a copy; no action
   on poller's end.
3. **Round-trip verification.** Once profile-app's
   `/profile-api/v0/internal/purge-whoami` receiver is live, want to
   replace my captured-filter smoke with a real round-trip test.

---

## 2026-05-28 — P4 shipped (`LG_PROFILE_APP_URL`)

Per coordinator's `reply-to-poller-p4-and-acks.md` directive.

**Changes:**
- `wp-config.php` — added `define('LG_PROFILE_APP_URL', 'https://dev.loothgroup.com')` after the `LG_INTERNAL_SECRET` define. Wrapped in `defined()` guard. Backup `/tmp/wp-config.php.bak.p4`.
- `src/PurgeNotifier.php` — replaced `private const ENDPOINT = '<full url>'` with `private const ENDPOINT_PATH = '/profile-api/v0/internal/purge-whoami'`. `onTierChanged()` now composes the URL from `LG_PROFILE_APP_URL . self::ENDPOINT_PATH`, with `rtrim()` on the base for trailing-slash tolerance. Guards on empty base same as empty secret — silently no-op rather than crash.

**Re-smoke (2026-05-28):**
- Action fired → purge captured at `https://dev.loothgroup.com/profile-api/v0/internal/purge-whoami` (URL identical to pre-change; constant correctly composed)
- Arbiter no-op suppression still works
- Endpoint smoke still clean (re-run not needed; touched only PurgeNotifier)

**On live cutover:** `define('LG_PROFILE_APP_URL', '<live profile-app URL>')` in live wp-config. No poller code change needed.

P4 ⏳ → ✅ on cutover checklist.

---

## 2026-05-28 — Patreon adapter shipped (P2)

Per `docs/briefing-poller-patreon-adapter.md`. BATCH-04 + BATCH-04B
landed; adapter built per spec.

### Files

- **`src/Patreon/PatreonSourceReader.php`** — new. `readForUser(int)` returns `['source'=>'patreon','tier'=>'looth1|2|3','tier_id'=>?string]` or null. Reads `payment_source` + `lgpo_patreon_tier_id` usermeta and walks `$user->roles` (highest→lowest) to find the LGPO-written tier role. Skips non-patreon users (returns null). No API calls.
- **`src/RoleSourceWriter.php`** — `readAllForUser()` now merges `PatreonSourceReader::readForUser()` into the source map under the `'patreon'` key. Live read overwrites any stale `lg_role_sources.patreon` row (the persisted row from LGPO's existing `lgpo_apply_role_via_arbiter` bridge becomes a harmless cache artifact). `report()` unchanged.
- **`InternalRestController::deriveProvenance()`** — **no change needed.** It already iterates `['stripe', 'patreon']` over the source map; now that `'patreon'` shows up correctly, patreon users naturally derive `paid` instead of `new`.

### Smoke (2026-05-28)

| uid | payment_source | role | sources map | endpoint tier | endpoint provenance | before |
|---|---|---|---|---|---|---|
| 7 | patreon | looth3 | `{patreon:looth3}` | pro | paid | was `new` |
| 16 | patreon | looth2 | `{patreon:looth2}` | lite | paid | was `new` |
| 1805 | stripe | looth2 | `[]` | (n/a, looth2 surfaced via role only) | — | adapter skips ✓ |
| 1906 | (none) | looth1 | `{stripe:null}` | public | lapsed | unchanged ✓ |

### Pre-existing Arbiter bug surfaced (NOT introduced; NOT fixed)

While smoke-testing Arbiter on uid=1805 (stripe-source, looth2, no
`lg_role_sources` row), Arbiter computed `winning_tier=null` (empty sources →
null) and stripped the user's looth2 role. Restored manually
(`wp user set-role 1805 looth2`; cleared the BB `starter` type that Arbiter's
type-sync also set).

**Root cause** — pre-existing: Arbiter has guards for looth4 (protected) and
looth1 (sticky), but **no guard for `payment_source=stripe` users without
a `lg_role_sources.stripe` row**. LGPO has the equivalent guard
(`payment_source=stripe + looth2/3 → skip`); Arbiter does not mirror it.

**Why this matters at cutover:** Stripe is dormant at cutover per B-now/A-later.
A stripe-source user without an active `lg_role_sources.stripe` row will be
silently downgraded if anything (including the cron tick, webhook replay, or
manual sync) calls `Arbiter::sync()` for them.

**Recommendation** — not in this PR's scope, flagging for coordinator:

```php
// At top of Arbiter::sync(), after the looth4 guard:
if ( get_user_meta($wpUserId, 'payment_source', true) === 'stripe'
     && empty(array_intersect($user->roles, ['looth1'])) ) {
    return [ 'ok' => true, 'reason' => 'stripe-source w/o source row, skipped' ];
}
```

Mirrors LGPO's guard. Three lines. Safe — only skips users who'd otherwise
be downgraded incorrectly.

Filed as a cross-cutting concern because it intersects with the "Stripe
dormant at cutover" decision in §3h of the coordination doc.

### Cutover checklist movement

- P2 🔒 → ✅ Patreon adapter (poller, post-BATCH-04) — shipped & smoke-passed

---

## 2026-05-28 — Arbiter stripe guard + round-trip attempt

Per `docs/reply-to-poller-arbiter-stripe-guard.md`.

### Stripe guard applied ✓

`src/Arbiter.php` — added 4-line skip block right after the looth4
protect guard. Mirrors LGPO's existing `payment_source=stripe + non-looth1
→ skip`. Returns `{ ok: true, reason: 'stripe-source w/o source row,
skipped' }` instead of computing winning_tier=null and stripping the
role.

**Re-smoke uid=1805:**
- BEFORE roles: `["looth2"]`
- `Arbiter::sync(1805)` → `{ok:true, reason:"stripe-source w/o source row, skipped"}`
- AFTER roles: `["looth2"]` — no longer downgraded ✓

### Round-trip purge attempt — blocked by nginx (not my lane)

Forced `blocking=true` + 5s timeout via `http_request_args` filter,
fired `do_action('looth_tier_changed', 1906, 'looth1', 'looth2', 'paid')`.

**Result: HTTP 403 from nginx cookie gate.**

Direct curl to `https://dev.loothgroup.com/profile-api/v0/internal/purge-whoami`
with valid `X-LG-Internal-Auth` returns the nginx cookie-gate 403 page
(identifiable by the embedded heartbeat script). The full nginx conf
has **zero** `location` blocks matching `/profile-api/`:

```
$ grep -nE 'location' /etc/nginx/sites-available/dev.loothgroup.com.conf | grep profile
(empty)
```

So profile-app's purge endpoint is being served by the catch-all WP
fallback, and the cookie gate fires before PHP runs. profile-app's
chat needs to add an exempt block matching the pattern I used for
`/wp-json/looth-internal/`:

```
location ^~ /profile-api/v0/internal/ {
    include fastcgi.conf;
    fastcgi_param SCRIPT_FILENAME /var/www/dev/index.php; # or their entry
    fastcgi_param SCRIPT_NAME /index.php;
    fastcgi_pass unix:/run/php/<their-pool>.sock;
    fastcgi_read_timeout 300;
}
```

**Not adding myself** — `/profile-api/` is profile-app's lane and they
own their PHP-FPM pool + entry point. Flagging for coordinator/profile-app.

Round-trip will pass once the exempt is in place; the poller-side
request is well-formed (correct URL, correct header, correct secret,
non-blocking + 1s timeout per spec, can be forced blocking for tests).

---

## 2026-05-28 — Round-trip purge SHIPPED + verified (204)

Per `docs/reply-to-poller-purge-ready.md`. profile-app landed
`^~ /profile-api/v0/internal/` exempt with `allow 127.0.0.1; deny all`.
Two poller-side adjustments needed to satisfy that contract.

### Adjustments

1. **`wp-config.php`** — `LG_PROFILE_APP_URL` changed from
   `https://dev.loothgroup.com` to `https://127.0.0.1`. Required so the
   request arrives at nginx with source IP 127.0.0.1 (passes the allowlist).
2. **`src/PurgeNotifier.php`** —
   - Added `'sslverify' => false` (site cert is for `dev.loothgroup.com`,
     not the loopback IP; cert verification fails with no added security
     since the trust boundary is `hash_equals()` on the shared secret).
   - Added explicit `Host: dev.loothgroup.com` request header (otherwise
     nginx routes to the default server block instead of the
     `dev.loothgroup.com` server block that includes profile-app's snippet
     → cookie gate fires → 403).
   - Public host is read from a new optional constant
     `LG_PROFILE_APP_PUBLIC_HOST`, defaulting to `dev.loothgroup.com` so
     live can override (different cert/hostname) without code change.

### Round-trip smoke (2026-05-28)

1. **Direct `do_action`** → `https://127.0.0.1/profile-api/v0/internal/purge-whoami` → **204** ✓ (empty body, per spec)
2. **End-to-end via Arbiter** — promoted uid=1906 looth1→looth2 via manual_admin, then reverted. Both transitions captured purge POSTs returning **204**. Provenance derivation correct in both directions (`comp` on promote, `lapsed` on revert).

Captured-filter smoke replaced with real round-trip in this handoff.

### Cutover note

Live wp-config will need:
- `define('LG_PROFILE_APP_URL', '<live address that profile-app's allowlist accepts>')`
- `define('LG_PROFILE_APP_PUBLIC_HOST', '<live public hostname for Host header / SNI>')`
- If profile-app's live setup terminates TLS with a cert that matches the URL → can set `sslverify=true` again (currently false). Otherwise leave false; the shared-secret is the real authz.

### Outstanding cross-lane threads — all closed

- Header name ack ✓ (ratified)
- Secret file coordination ✓ (profile-app handles their side)
- Round-trip verification ✓ (this section)

P4 ✅, P2 ✅, round-trip ✅. Idle in lane.

---

## 2026-05-28 — round-trip verified ✓

Per `docs/reply-to-poller-purge-ready.md`. profile-app's exempt block
landed at `/etc/nginx/snippets/strangler-profile-app.conf` with
`allow 127.0.0.1; allow ::1; deny all`.

### Two adjustments needed on poller side to reach loopback correctly

**1. `LG_PROFILE_APP_URL` → `https://127.0.0.1`** (was `https://dev.loothgroup.com`).

Source IP needs to be 127.0.0.1 for nginx's allow rule. Going via
public hostname routes through the public IP (50.19.198.38), tripping
`deny all`.

**2. PurgeNotifier sets `sslverify=false` + explicit `Host` header.**

- `sslverify=false`: the site cert is for `dev.loothgroup.com`; cert
  validation against `127.0.0.1` would fail with no security benefit
  (the channel is shared-secret authed; TLS adds nothing the cert
  mismatch would protect on loopback).
- `Host: dev.loothgroup.com`: nginx selects server block via Host
  header / TLS SNI. Without the explicit Host, the request hits the
  default server block (cookie gate fires → 403). Added a
  `LG_PROFILE_APP_PUBLIC_HOST` constant (defaults to
  `dev.loothgroup.com`) so live can point at its own hostname.

Files: `wp-config.php` (one-line URL change), `src/PurgeNotifier.php`
(added sslverify + Host header + LG_PROFILE_APP_PUBLIC_HOST fallback).

### Smoke (2026-05-28)

End-to-end through Arbiter on qa_lite (uid=1906):
- Promote looth1→looth2 via manual_admin source → action fired →
  **POST to https://127.0.0.1/profile-api/v0/internal/purge-whoami → 204** ✓
- Revert looth2→looth1 (delete manual_admin row) → action fired →
  **204** ✓
- Both with empty response body, blocking=true forced just for the
  smoke; production path stays blocking=false / 1s timeout.

Captured-filter smoke retired — round-trip is now real.

### Cutover note for cross-cutting awareness

On live, `LG_PROFILE_APP_URL` + `LG_PROFILE_APP_PUBLIC_HOST` together
determine the routing:
- If profile-app is on the same box → keep loopback + public-host
  pattern; works as on dev.
- If profile-app is on a different host → set `LG_PROFILE_APP_URL`
  to its real URL and `LG_PROFILE_APP_PUBLIC_HOST` to its real
  hostname (cert validation can be re-enabled by removing
  `sslverify=false` from the wp_remote_post call).

This is a P4-adjacent config tweak; documenting here so it's not
forgotten at cutover.

---

## 2026-05-29 — membership-pages PoC (shared chrome) SHIPPED on dev

The membership-pages task's proof-of-concept is live on dev: `/membership-guide/`
renders on the unified `/srv/lg-shared/` header+footer, BuddyBoss theme chrome
bypassed. **Note on provenance:** the rotated poller sub-agent did the research +
design but its harness sandbox denied all writes/curl/wp-cli; per Ian's call this
PoC was built + verified from the coordinator session. Lane ownership of the code
stays poller — this section is the record.

### Shipped (the mechanism)

| File | What |
|---|---|
| `/var/www/dev/wp-content/mu-plugins/lg-membership-chrome.php` | new — loader. `template_include` @ pri 99; matches `get_queried_object()->post_name` against the 11 registry slugs on `is_main_query() && is_singular('page')`; `is_readable()` guard on the shared partials → falls back to theme template (no fatal) if `/srv/lg-shared/` is absent. Also defines `lg_membership_chrome_viewer_ctx()`. Owner `www-data:www-data`, 0644. |
| `…/mu-plugins/lg-membership-chrome/template.php` | new — the template. doctype → `<head>` (`<link rel=stylesheet href="/lg-shared/site-header.css">` + `wp_head()`) → `<body class=body_class('lg-membership-page')>` → `lg_shared_render_site_header($ctx)` → `<main id="lg-main">` + the loop `the_content()` → `lg_shared_render_site_footer()` → `wp_footer()`. |

### Decisions made (and why)

- **Viewer state = in-process, NOT `/whoami`.** Reuses `lg_viewer_tier()` from the
  existing `lg-viewer-tier.php` mu-plugin (looth2→lite, looth3/4/admin→pro, else
  public) + `wp_get_current_user()` + `get_avatar_url()`. Keeps the pages rendering
  while profile-app/Stripe are dormant (B-now/A-later); no loopback HTTP hop. The
  `/whoami` shim is a passthrough to profile-app → wrong dependency for must-render
  pages.
- **`body_class()` is called** so the poller's `body_class` filters still fire
  (`Plugin::addCustomerBodyClass` → `lg-customer-only`). The membership-guide
  `is-member`/`is-anon` split is computed by the shortcode itself, so it does NOT
  depend on `body_class` — but the admin preview bar's hooks do.

### Verification (dev, 2026-05-29)

Both via curl with the cookie-gate cookie; member path with a minted front-end
`wordpress_logged_in_*` cookie for `claude_admin` (uid 1904).

| Check | Anon | Member (admin) |
|---|---|---|
| HTTP | 200 | 200 |
| Shared header (`<header class="lg-chrome">`) | 1 | 1 |
| `<main id="lg-main">` + shared footer, in order | ✓ | ✓ |
| BB masthead/nav (`#masthead`, `site-header`, `bb-menu-wrap`, `site-navigation`) | 0 | 0 |
| Header right-side | Sign in / Join | account "Claude Admin" + **Admin** tier pill + Edit |
| mg-elders (`.elders` ×1, `<a class="elder">` ×7 = `lgms_guide_elders`, `lgms-elder-{pic,name,cta}`) | ✓ | ✓ |
| `wp_head()` / `wp_footer()` fired (plugin assets present) | ✓ | ✓ |

### Caveat to resolve in the generalization pass (NOT a blocker)

`wp_head()` still enqueues the **BuddyBoss theme + Elementor CSS/JS** (the active
theme's filters fire even though its header/footer templates are bypassed). The
shared header renders correctly, but BB/Elementor stylesheets co-load (~150–250KB
page weight, possible visual conflicts). The clean fix when generalizing to all 11
slugs: dequeue BB-theme + Elementor assets on these page slugs (`wp_enqueue_scripts`
at high priority, or `wp_print_styles`/`dequeue` keyed on the membership slug set).
Left for the per-page pass since the briefing's PoC bar was "renders on the shared
header end-to-end," which is met.

### `lg_member_nav` cleanup — READY, not yet applied (per coord §3k)

§3k resolved Open Decision #1: `lg_member_nav` folds into the shared header's
account dropdown (lg-shell's lane), so it does NOT become a secondary strip. Two
edits staged for the generalization pass:
1. `Pages.php` `createPage()` (~:387-390) — drop the `[lg_member_nav]` prefix from
   the auto-seed body.
2. Keep `lg_member_nav` registered as a **no-op** (`Shortcodes::memberNav()` ~:5905)
   so the ~existing~ seeded pages don't render literal `[lg_member_nav]` text at
   cutover. (No-op is the safe route vs. SQL-stripping existing post_content.)

### Next moves in lane

1. Generalize the `template_include` mechanism across all 11 slugs (most are
   `page-fullwidth.php` today — the mu-plugin already matches them all; just needs
   per-page smoke, esp. the JS-heavy ones: `lg_join` loadProducts ×3, `lg_my_gifts`
   modals, `lg_manage_subscription` plan-switch, `lg_subscription_success` welcome
   modal).
2. Apply the BB/Elementor dequeue + the `lg_member_nav` cleanup above.
3. CDP pass on the JS-heavy pages (use `requestSubmit()`/click — never `form.submit()`).
4. P8 (poller dormant-mode dev smoke) — still not started.
