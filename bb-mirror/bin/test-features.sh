#!/usr/bin/env bash
#
# bb-mirror feature test harness
# --------------------------------
# Drives the local headless Chrome (CDP on 127.0.0.1:9222) as a logged-in WP
# admin and exercises every feature added in the activity-stream work:
#   nav accordions, subforum pills, "Post here", Quill composer, client-side
#   embeds, inline reply "more", "View N replies" accordion, single-visible
#   reply, asset cache-busters, and the image upload -> real-time sync -> render
#   pipeline (creates + deletes a throwaway topic).
#
# Emits a PASS/FAIL report to stdout and to /tmp/bb-mirror-test-report.txt.
#
# Usage:  bin/test-features.sh
# Safe to re-run; the only write is a temporary test topic that is deleted.

set -uo pipefail

# ── Config ──────────────────────────────────────────────────────────────────
HOST="dev.loothgroup.com"
BASE="https://$HOST"
POC="/forum"                                     # canonical base (§0d); was /forums-poc
WP_PATH="/var/www/dev"
WP_USER="iandavlin"
TEST_FORUM_ID=67776                              # Strings Micro Factory
CAT_SLUG="repair-and-restoration"                # a category container (pills, NO post)
LEAF_SLUG="touring-tech"                          # a leaf subforum under it (postable)
EMBED_TOPIC_URL="$POC/acoustic/neck-reset-question/"  # has a bare YouTube URL
REPORT=/tmp/bb-mirror-test-report.txt
CDP=/tmp/bbtest-cdp.py

PASS=0; FAIL=0; RESULTS=()

pass(){ RESULTS+=("PASS  $1"); PASS=$((PASS+1)); }
fail(){ RESULTS+=("FAIL  $1  -- $2"); FAIL=$((FAIL+1)); }
# check NAME EXPECTED ACTUAL
check(){ if [[ "$2" == "$3" ]]; then pass "$1"; else fail "$1" "expected[$2] got[$3]"; fi; }

# ── 0. Chrome up? ─────────────────────────────────────────────────────────────
if [[ "$(systemctl is-active chrome-dev.service)" != "active" ]]; then
  sudo systemctl start chrome-dev.service; sleep 2
fi

# ── CDP helper (regenerated each run) ─────────────────────────────────────────
cat > "$CDP" <<'PY'
import asyncio, json, sys, urllib.request, websockets
HTTP="http://127.0.0.1:9222"
def page():
    ps=json.load(urllib.request.urlopen(HTTP+"/json"))
    return [p for p in ps if p["type"]=="page"][0]
async def _send(ws,m,p=None):
    async with websockets.connect(ws,max_size=None) as s:
        await s.send(json.dumps({"id":1,"method":m,"params":p or {}}))
        return json.loads(await s.recv())
def main():
    cmd=sys.argv[1]; p=page(); ws=p["webSocketDebuggerUrl"]
    if cmd=="pageid": print(p["id"])
    elif cmd=="cookies":
        env=dict(l.split("=",1) for l in open("/tmp/bbtest-cookies.env") if "=" in l)
        cks=[{"domain":"dev.loothgroup.com","name":"loothdev_auth","value":env["GATE"].strip(),"path":"/","secure":True,"httpOnly":True},
             {"domain":"dev.loothgroup.com","name":env["LOGGED_IN_NAME"].strip(),"value":env["LOGGED_IN_VAL"].strip(),"path":"/","secure":True,"httpOnly":True},
             {"domain":"dev.loothgroup.com","name":env["AUTH_NAME"].strip(),"value":env["AUTH_VAL"].strip(),"path":"/","secure":True,"httpOnly":True}]
        async def go():
            async with websockets.connect(ws,max_size=None) as s:
                await s.send(json.dumps({"id":1,"method":"Network.clearBrowserCookies"})); await s.recv()
                for i,c in enumerate(cks,2):
                    await s.send(json.dumps({"id":i,"method":"Network.setCookie","params":c})); await s.recv()
        asyncio.run(go()); print("ok")
    elif cmd=="nav":
        asyncio.run(_send(ws,"Page.navigate",{"url":sys.argv[2]})); print("ok")
    elif cmd=="eval":
        r=asyncio.run(_send(ws,"Runtime.evaluate",{"expression":sys.argv[2],"returnByValue":True,"awaitPromise":True}))
        try: print(r["result"]["result"].get("value",""))
        except Exception: print("")
if __name__=="__main__": main()
PY

cdp(){ python3 "$CDP" "$@"; }
ev(){ cdp eval "$1"; }                        # eval JS, print returnByValue
# Navigate AND wait for the page to actually land: poll until document.readyState
# is 'complete' and the URL path matches $2 (a substring, e.g. the slug). Fixed
# sleeps race the page on a busy box — querying the PREVIOUS page silently. Up to ~12s.
nav(){
  local url="$1" want="${2:-}" i state
  cdp nav "$url" >/dev/null
  for i in $(seq 1 24); do
    state=$(ev "document.readyState+'|'+location.pathname")
    if [[ "$state" == complete\|* ]]; then
      [[ -z "$want" || "$state" == *"$want"* ]] && { sleep 0.4; return 0; }
    fi
    sleep 0.5
  done
  return 0   # fall through; the assertion itself will report if the page is wrong
}

# ── Mint cookies (gate + WP admin) ────────────────────────────────────────────
TOK=$(sudo grep '$loothdev_token' /etc/nginx/sites-available/$HOST.conf | head -1 | awk -F'"' '{print $2}')
GATE=$(curl -sI "$BASE/claim?t=$TOK" | sed -n 's/.*loothdev_auth=\([^;]*\).*/\1/Ip' | head -1)
echo "GATE=$GATE" > /tmp/bbtest-cookies.env
sudo -u looth-dev wp --path=$WP_PATH --allow-root eval '
$uid=1; $exp=time()+14*DAY_IN_SECONDS; $sec=is_ssl();
echo "LOGGED_IN_NAME=".LOGGED_IN_COOKIE."\n";
echo "LOGGED_IN_VAL=".wp_generate_auth_cookie($uid,$exp,"logged_in")."\n";
echo "AUTH_NAME=".($sec?SECURE_AUTH_COOKIE:AUTH_COOKIE)."\n";
echo "AUTH_VAL=".wp_generate_auth_cookie($uid,$exp,$sec?"secure_auth":"auth")."\n";
' 2>/dev/null >> /tmp/bbtest-cookies.env
cdp cookies >/dev/null

echo "Running bb-mirror feature tests…"

# ── 1. Asset cache-busters ────────────────────────────────────────────────────
nav "$BASE$POC/" "$POC/"
JS_VER=$(curl -s "$BASE$POC/" -H "Cookie: loothdev_auth=$GATE" | grep -oE "forums\.js\?v=[0-9]+" | head -1)
[[ -n "$JS_VER" ]] && pass "asset cache-buster on forums.js ($JS_VER)" || fail "asset cache-buster" "no ?v= on forums.js"

# ── 2. Nav accordion toggle ───────────────────────────────────────────────────
R=$(ev "(function(){var s=document.querySelector('.nav-tree__section');if(!s)return'nosec';var t=s.querySelector('.nav-tree__section-toggle');var b=s.classList.contains('nav-tree__section--open');t.click();var a=s.classList.contains('nav-tree__section--open');return (b!==a)?'toggles':'static';})()")
check "nav accordion toggles open/closed" "toggles" "$R"

# ── 3. Single visible reply per card ──────────────────────────────────────────
R=$(ev "(function(){var c=[...document.querySelectorAll('.feed-card--topic')].find(x=>x.querySelector('.reply-stub'));if(!c)return'-1';return ''+c.querySelectorAll('.reply-stub:not(.reply-stub--overflow):not(.reply-stub--child)').length;})()")
check "exactly 1 visible top-level reply" "1" "$R"

# ── 4. Inline reply "… more" reveals text (the [hidden] CSS bug) ──────────────
R=$(ev "(function(){var b=document.querySelector('.reply-stub__expand');if(!b)return'nobtn';var f=b.previousElementSibling;var v0=f.getClientRects().length;b.click();var v1=f.getClientRects().length;return (v0===0&&v1===1)?'reveals':('v0='+v0+' v1='+v1);})()")
check "inline reply 'more' reveals hidden text" "reveals" "$R"

# ── 5. "View N replies" lazy-loads the full thread ────────────────────────────
# Feed ships one teaser per card; clicking fetches /?replies=<id> into
# .feed-card__replies-full. Verify the container starts empty then populates.
R=$(ev "(function(){var b=document.querySelector('.feed-card__expand');if(!b)return'nobtn';var f=b.closest('.feed-card').querySelector('.feed-card__replies-full');var pre=f.dataset.loaded?'pre-loaded':'empty';b.click();return pre;})()")
LAZY=""
for i in 1 2 3 4 5 6 7 8; do
  LAZY=$(ev "(function(){var f=document.querySelector('.feed-card.replies-expanded .feed-card__replies-full');return (f&&!f.hidden&&f.querySelector('.reply-stub'))?'loaded':'no';})()")
  [[ "$LAZY" == "loaded" ]] && break; sleep 1
done
[[ "$R" == "empty" ]] && pass "replies not rendered until expand (lazy)" || fail "replies lazy" "container state=$R"
check "'View N replies' lazy-loads the thread" "loaded" "$LAZY"

# ── 6. Quill composer mounts (open modal, wait for auth+init) ─────────────────
ev "document.getElementById('ntm-open').click(); 'opened'" >/dev/null; sleep 2
R=$(ev "(function(){return JSON.stringify({q:typeof Quill!=='undefined',tb:!!document.querySelector('.ntm-form .ql-toolbar'),ed:!!document.querySelector('.ql-editor'),img:!!document.querySelector('.ql-image')});})()")
echo "$R" | grep -q '"q":true' && echo "$R" | grep -q '"ed":true' && echo "$R" | grep -q '"img":true' \
  && pass "Quill composer mounts (toolbar+editor+image)" || fail "Quill composer" "$R"

# ── 7. Category page: pills present, NO "Post here" (can't post to a container) ─
nav "$BASE$POC/$CAT_SLUG/" "$CAT_SLUG"
R=$(ev "''+document.querySelectorAll('.subforum-pill').length")
[[ "${R:-0}" -gt 0 ]] && pass "subforum pills render on category ($R pills)" || fail "subforum pills" "0 pills on $CAT_SLUG"
R=$(ev "!!document.querySelector('.feed-post-btn[data-ntm-open]') + ''")
check "category container has NO 'Post here' button" "false" "$R"

# ── 7b. Leaf subforum: "Post here" + sibling nav + parent breadcrumb ──────────
nav "$BASE$POC/$LEAF_SLUG/" "$LEAF_SLUG"
R=$(ev "!!document.querySelector('.feed-post-btn[data-ntm-open]') + ''")
check "leaf subforum HAS 'Post here' button" "true" "$R"
# Leaf shows sibling pills (parent's children), with itself marked active
R=$(ev "''+document.querySelectorAll('.subforum-pill').length")
[[ "${R:-0}" -gt 0 ]] && pass "leaf shows sibling-nav pills ($R)" || fail "leaf sibling pills" "0 pills on $LEAF_SLUG"
R=$(ev "!!document.querySelector('.subforum-pill--active') + ''")
check "leaf marks its own pill active" "true" "$R"
# Parent breadcrumb in the header
R=$(ev "!!document.querySelector('.forum-header__parent') + ''")
check "leaf header shows parent breadcrumb" "true" "$R"
# Header is colour-coded by category (data-cat + accent var resolves)
R=$(ev "(function(){var h=document.querySelector('.forum-header');var c=h&&h.getAttribute('data-cat');var bl=h&&getComputedStyle(h).borderLeftWidth;return (c&&bl&&parseFloat(bl)>=4)?('cat:'+c):'no';})()")
[[ "$R" == cat:* ]] && pass "header colour-coded by category ($R)" || fail "header colour-code" "$R"
# Admin sees the header-image pencil (revealed by auth fetch)
PEN=""
for i in 1 2 3 4 5 6; do
  PEN=$(ev "(function(){var b=document.querySelector('.forum-header__edit-img');return b&&!b.hidden?'shown':'hidden';})()")
  [[ "$PEN" == "shown" ]] && break; sleep 1
done
check "admin sees header-image pencil" "shown" "$PEN"
# And the composer's forum <select> must NOT list the category container
ev "document.getElementById('ntm-open').click(); 'opened'" >/dev/null; sleep 2
R=$(ev "(function(){var o=[...document.querySelectorAll('#ntm-forum option')];return o.some(x=>x.dataset.slug==='$CAT_SLUG')?'present':'absent';})()")
check "composer select excludes category container" "absent" "$R"

# ── 8. Client-side embed (bare YouTube URL -> iframe) ─────────────────────────
nav "$BASE$EMBED_TOPIC_URL" "neck-reset-question"
R=$(ev "(function(){var f=document.querySelector('.bb-embed--video iframe');return f?(/youtube\.com\/embed\//.test(f.src)?'embedded':'badsrc'):'noembed';})()")
check "bare YouTube URL renders as embed iframe" "embedded" "$R"

# ── 9. Image upload -> real-time sync -> render (full pipeline) ───────────────
img_test() {
  local img=/tmp/bbtest.png creds=/tmp/bbtest-creds.json
  printf '\x89PNG\r\n\x1a\n\x00\x00\x00\rIHDR\x00\x00\x00\x10\x00\x00\x00\x10\x08\x06\x00\x00\x00\x1f\xf3\xffa\x00\x00\x00\x19IDATx\x9cc\xfc\xcf\x80\x1f0\x91\x81\x81\x81\x11\x03\x18\x18\x00\x00\xff\xff\x03\x00\x06\x05\x02\x9d\x9d\xc3\x8d\xb0\x00\x00\x00\x00IEND\xaeB\x60\x82' > "$img"
  sudo -u looth-dev wp --path=$WP_PATH --allow-root eval '
    $u=get_user_by("login","'"$WP_USER"'");$exp=time()+3600;
    $t=WP_Session_Tokens::get_instance($u->ID)->create($exp);
    $c=wp_generate_auth_cookie($u->ID,$exp,"logged_in",$t);
    $_COOKIE[LOGGED_IN_COOKIE]=$c; wp_set_current_user($u->ID);
    file_put_contents("'"$creds"'",json_encode(["cn"=>LOGGED_IN_COOKIE,"cv"=>$c,"nonce"=>wp_create_nonce("wp_rest")]));' 2>/dev/null
  local cn cv nonce upid tid
  cn=$(python3 -c "import json;print(json.load(open('$creds'))['cn'])")
  cv=$(python3 -c "import json;print(json.load(open('$creds'))['cv'])")
  nonce=$(python3 -c "import json;print(json.load(open('$creds'))['nonce'])")
  upid=$(curl -s -X POST "$BASE/wp-json/buddyboss/v1/media/upload" \
    -H "Cookie: loothdev_auth=$GATE; $cn=$cv" -H "X-WP-Nonce: $nonce" \
    -F "file=@$img;type=image/png" | python3 -c "import json,sys;print(json.load(sys.stdin).get('upload_id',''))")
  tid=$(curl -s -X POST "$BASE/wp-json/buddyboss/v1/topics" \
    -H "Cookie: loothdev_auth=$GATE; $cn=$cv" -H "X-WP-Nonce: $nonce" -H "Content-Type: application/json" \
    -d "{\"parent\":$TEST_FORUM_ID,\"title\":\"HARNESS media test DELETE\",\"content\":\"<p>t</p>\",\"bbp_media\":[$upid]}" \
    | python3 -c "import json,sys;print(json.load(sys.stdin).get('id',''))")
  if [[ -z "$tid" ]]; then fail "image upload+create" "no topic id"; rm -f "$img"; sudo rm -f "$creds"; return; fi
  # Poll pg for the attachment row (deferred shutdown sync is async).
  local att=0 i
  for i in 1 2 3 4 5 6 7 8; do
    att=$(sudo -u bb-mirror psql -d looth -tA -c "SELECT count(*) FROM forums.attachment WHERE parent_id=$tid AND parent_kind='topic';" 2>/dev/null)
    [[ "$att" == "1" ]] && break
    sleep 2
  done
  check "image auto-syncs to pg (real-time, no reconcile)" "1" "$att"
  # render check — poll the page too (give the synced row a beat to surface)
  local fs ts rendered=no code="" url
  read fs ts < <(sudo -u bb-mirror psql -d looth -tA -F' ' -c "SELECT f.slug,t.slug FROM forums.topic t JOIN forums.forum f ON f.id=t.forum_id WHERE t.id=$tid;" 2>/dev/null)
  url="$BASE$POC/$fs/$ts/"
  for i in 1 2 3 4 5 6; do
    code=$(curl -s -o /tmp/bbtest-page.html -w "%{http_code}" "$url" -H "Cookie: loothdev_auth=$GATE")
    if grep -q "post__attachments" /tmp/bbtest-page.html; then rendered=yes; break; fi
    sleep 2
  done
  if [[ "$rendered" == "yes" ]]; then
    pass "image renders on bb-mirror topic page ($fs/$ts)"
  else
    local why; why=$(grep -oE "Forum not found|Topic not found|Not Found" /tmp/bbtest-page.html | head -1)
    fail "image renders on topic page ($fs/$ts)" "http=$code ${why:-no-attachments-div}"
  fi
  rm -f /tmp/bbtest-page.html
  sudo -u looth-dev wp --path=$WP_PATH --allow-root post delete "$tid" --force >/dev/null 2>&1
  sudo -u looth-dev wp --path=$WP_PATH --allow-root post delete "$upid" --force >/dev/null 2>&1
  rm -f "$img"; sudo rm -f "$creds"
}
img_test

# ── 10. Admin edit-all reveal + edit round-trip ───────────────────────────────
# (a) Admin can see Edit on a topic they didn't author (canEditOthers path).
nav "$BASE$EMBED_TOPIC_URL" "neck-reset-question"
R=""
for i in 1 2 3 4 5 6 7 8; do
  R=$(ev "(function(){var b=document.querySelector('.post--op .post__edit-btn');return b&&!b.hidden?'shown':(b?'hidden':'none');})()")
  [[ "$R" == "shown" ]] && break; sleep 1
done
check "admin sees Edit on a post they didn't author" "shown" "$R"

# (b) Full round-trip: create throwaway topic, edit via the UI, verify persisted.
edit_test() {
  local creds=/tmp/bbtest-ecreds.json tid fs ts cn cv nonce
  sudo -u looth-dev wp --path=$WP_PATH --allow-root eval '
    $u=get_user_by("login","'"$WP_USER"'");$exp=time()+3600;
    $t=WP_Session_Tokens::get_instance($u->ID)->create($exp);
    $c=wp_generate_auth_cookie($u->ID,$exp,"logged_in",$t);
    $_COOKIE[LOGGED_IN_COOKIE]=$c; wp_set_current_user($u->ID);
    file_put_contents("'"$creds"'",json_encode(["cn"=>LOGGED_IN_COOKIE,"cv"=>$c,"nonce"=>wp_create_nonce("wp_rest")]));' 2>/dev/null
  cn=$(python3 -c "import json;print(json.load(open('$creds'))['cn'])")
  cv=$(python3 -c "import json;print(json.load(open('$creds'))['cv'])")
  nonce=$(python3 -c "import json;print(json.load(open('$creds'))['nonce'])")
  tid=$(curl -s -X POST "$BASE/wp-json/buddyboss/v1/topics" \
    -H "Cookie: loothdev_auth=$GATE; $cn=$cv" -H "X-WP-Nonce: $nonce" -H "Content-Type: application/json" \
    -d "{\"parent\":$TEST_FORUM_ID,\"title\":\"HARNESS edit test DELETE\",\"content\":\"<p>original body</p>\"}" \
    | python3 -c "import json,sys;print(json.load(sys.stdin).get('id',''))")
  if [[ -z "$tid" ]]; then fail "edit round-trip" "no topic id"; sudo rm -f "$creds"; return; fi
  sleep 5
  read fs ts < <(sudo -u bb-mirror psql -d looth -tA -F' ' -c "SELECT f.slug,t.slug FROM forums.topic t JOIN forums.forum f ON f.id=t.forum_id WHERE t.id=$tid;" 2>/dev/null)
  nav "$BASE$POC/$fs/$ts/" "$ts"
  # wait for edit button, open editor, set content, save
  local ok=""
  for i in 1 2 3 4 5 6 7 8; do
    ok=$(ev "(function(){var b=document.querySelector('.post--op .post__edit-btn');return b&&!b.hidden?'1':'0';})()")
    [[ "$ok" == "1" ]] && break; sleep 1
  done
  ev "document.querySelector('.post--op .post__edit-btn').click(); 'x'" >/dev/null; sleep 1
  ev "(function(){var e=document.querySelector('.post-edit .ql-editor');if(e){e.innerHTML='<p>EDITED-BY-HARNESS</p>';}document.querySelector('.post-edit__save').click();return 'x';})()" >/dev/null
  sleep 4
  # optimistic DOM update
  local dom; dom=$(ev "(function(){return document.querySelector('.post--op .post__body').textContent.indexOf('EDITED-BY-HARNESS')>=0?'yes':'no';})()")
  check "edit updates the post in-place (optimistic)" "yes" "$dom"
  # persisted to WP?
  local content; content=$(sudo -u looth-dev wp --path=$WP_PATH --allow-root post get "$tid" --field=post_content 2>/dev/null)
  [[ "$content" == *"EDITED-BY-HARNESS"* ]] && pass "edit persisted to BB (WP post_content)" || fail "edit persisted to BB" "content=[$content]"
  sudo -u looth-dev wp --path=$WP_PATH --allow-root post delete "$tid" --force >/dev/null 2>&1
  sudo rm -f "$creds"
}
edit_test

# ── 11. Delete: user deletes own, can't delete others'; admin deletes any ─────
# Real browser (CDP) clicks on the actual Delete button, as a regular member
# AND as admin. Auto-provisions a stable non-admin member so the harness is
# self-contained. Runs last: it swaps the browser cookie to the test user and
# back to admin, so it must not precede any admin-cookie assertion.
DELUSER=$(sudo -u looth-dev wp --path=$WP_PATH --allow-root eval '
  $u=get_user_by("login","deltest_user");
  if(!$u){$id=wp_insert_user(["user_login"=>"deltest_user","user_email"=>"deltest_user@example.com","user_pass"=>wp_generate_password(20),"role"=>"subscriber"]);}
  else{$id=$u->ID;}
  if(function_exists("bbp_set_user_role")) bbp_set_user_role($id,bbp_get_participant_role());
  echo (int)$id;' 2>/dev/null)

# REST creds (session-bound nonce) for a uid → json file
del_rcreds(){ sudo -u looth-dev wp --path=$WP_PATH --allow-root eval '
  $uid=(int)"'"$1"'";$exp=time()+3600;$t=WP_Session_Tokens::get_instance($uid)->create($exp);
  $c=wp_generate_auth_cookie($uid,$exp,"logged_in",$t);$_COOKIE[LOGGED_IN_COOKIE]=$c;wp_set_current_user($uid);
  file_put_contents("'"$2"'",json_encode(["cn"=>LOGGED_IN_COOKIE,"cv"=>$c,"nonce"=>wp_create_nonce("wp_rest")]));' 2>/dev/null; }
# Swap the browser to a uid's cookies (overwrites the shared env, then loads)
del_setcookies(){ { echo "GATE=$GATE"; sudo -u looth-dev wp --path=$WP_PATH --allow-root eval '
  $uid=(int)"'"$1"'";$exp=time()+14*DAY_IN_SECONDS;$sec=is_ssl();
  echo "LOGGED_IN_NAME=".LOGGED_IN_COOKIE."\n";echo "LOGGED_IN_VAL=".wp_generate_auth_cookie($uid,$exp,"logged_in")."\n";
  echo "AUTH_NAME=".($sec?SECURE_AUTH_COOKIE:AUTH_COOKIE)."\n";echo "AUTH_VAL=".wp_generate_auth_cookie($uid,$exp,$sec?"secure_auth":"auth")."\n";' 2>/dev/null; } > /tmp/bbtest-cookies.env
  cdp cookies >/dev/null; }
del_create(){ # credfile title -> "id fslug tslug"
  local cn cv nn id fs ts i
  cn=$(python3 -c "import json;print(json.load(open('$1'))['cn'])")
  cv=$(python3 -c "import json;print(json.load(open('$1'))['cv'])")
  nn=$(python3 -c "import json;print(json.load(open('$1'))['nonce'])")
  id=$(curl -s -X POST "$BASE/wp-json/buddyboss/v1/topics" -H "Cookie: loothdev_auth=$GATE; $cn=$cv" \
        -H "X-WP-Nonce: $nn" -H "Content-Type: application/json" \
        -d "{\"parent\":$TEST_FORUM_ID,\"title\":\"$2\",\"content\":\"<p>del body</p>\"}" \
        | python3 -c "import json,sys;print(json.load(sys.stdin).get('id',''))")
  [[ -z "$id" ]] && { echo ""; return; }
  for i in 1 2 3 4 5 6 7 8; do
    read fs ts < <(sudo -u bb-mirror psql -d looth -tA -F' ' -c "SELECT f.slug,t.slug FROM forums.topic t JOIN forums.forum f ON f.id=t.forum_id WHERE t.id=$id;" 2>/dev/null)
    [[ -n "$ts" ]] && break; sleep 1
  done
  echo "$id $fs $ts"; }
del_gone(){ local s; s=$(sudo -u bb-mirror psql -d looth -tA -c "SELECT status FROM forums.topic WHERE id=$1;" 2>/dev/null); [[ -z "$s" || "$s" == trash || "$s" == spam ]] && echo gone || echo "$s"; }
del_reveal(){ ev "(function(){var b=document.querySelector('.post--op .post__delete-btn');return b?(b.hidden?'hidden':'shown'):'none';})()"; }
del_click(){ ev "(function(){window.confirm=function(){return true;};var b=document.querySelector('.post--op .post__delete-btn');if(b)b.click();return 'x';})()" >/dev/null; }

delete_test(){
  if [[ -z "$DELUSER" || "$DELUSER" == 0 ]]; then fail "delete tests" "could not provision test user"; return; fi
  del_rcreds 1 /tmp/bbtest-dadmin.json
  del_rcreds "$DELUSER" /tmp/bbtest-duser.json

  # (a) regular USER deletes their OWN topic via the UI
  read TU FS TS < <(del_create /tmp/bbtest-duser.json "HARNESS del user-own DELETE")
  if [[ -z "${TS:-}" ]]; then fail "user deletes own topic" "setup (create/sync) failed"; else
    del_setcookies "$DELUSER"; nav "$BASE$POC/$FS/$TS/" "$TS"
    R=""; for i in $(seq 1 10); do R=$(del_reveal); [[ "$R" == shown ]] && break; sleep 1; done
    check "user sees Delete on own topic" "shown" "$R"
    del_click; G=""; for i in 1 2 3 4 5 6 7 8; do G=$(del_gone "$TU"); [[ "$G" == gone ]] && break; sleep 1; done
    check "user delete removes own topic (pg)" "gone" "$G"
  fi

  # (b) regular USER must NOT see Delete on an admin-authored topic
  read TA FA TAS < <(del_create /tmp/bbtest-dadmin.json "HARNESS del admin-own DELETE")
  if [[ -z "${TAS:-}" ]]; then fail "user can't delete others'" "setup failed"; else
    nav "$BASE$POC/$FA/$TAS/" "$TAS"          # still on USER cookies
    SAW=hidden; for i in 1 2 3 4 5 6; do [[ "$(del_reveal)" == shown ]] && { SAW=shown; break; }; sleep 1; done
    check "user does NOT see Delete on others' topic" "hidden" "$SAW"
  fi

  # (c) ADMIN deletes the someone-else (admin-authored) topic via the UI
  if [[ -n "${TAS:-}" ]]; then
    del_setcookies 1; nav "$BASE$POC/$FA/$TAS/" "$TAS"
    R=""; for i in $(seq 1 10); do R=$(del_reveal); [[ "$R" == shown ]] && break; sleep 1; done
    check "admin sees Delete on any topic" "shown" "$R"
    del_click; G=""; for i in 1 2 3 4 5 6 7 8; do G=$(del_gone "$TA"); [[ "$G" == gone ]] && break; sleep 1; done
    check "admin delete removes topic (pg)" "gone" "$G"
  fi

  sudo -u looth-dev wp --path=$WP_PATH --allow-root post delete "${TU:-0}" "${TA:-0}" --force >/dev/null 2>&1
  sudo rm -f /tmp/bbtest-dadmin.json /tmp/bbtest-duser.json
}
delete_test

# ── Report ────────────────────────────────────────────────────────────────────
{
  echo "════════════════════════════════════════════════════"
  echo " bb-mirror feature test report — $(date '+%Y-%m-%d %H:%M:%S')"
  echo "════════════════════════════════════════════════════"
  for r in "${RESULTS[@]}"; do echo "  $r"; done
  echo "────────────────────────────────────────────────────"
  echo "  TOTAL: $((PASS+FAIL))   PASS: $PASS   FAIL: $FAIL"
  echo "════════════════════════════════════════════════════"
} | tee "$REPORT"

# cleanup transient helpers
rm -f "$CDP" /tmp/bbtest-cookies.env
[[ $FAIL -eq 0 ]] && exit 0 || exit 1
