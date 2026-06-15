#!/usr/bin/env bash
#
# topoff-dev-from-live.sh — additively top off DEV from LIVE (prod), one direction.
#
#   Pulls content/media that LIVE has but DEV is MISSING, and inserts it.
#   It NEVER updates or deletes existing dev rows, so it cannot clobber dev's
#   conversions or local work. It is the prod->dev half of the workflow only;
#   dev->live publishing goes through the v2 content pipeline, not this.
#
# Why "missing rows only": dev forked from live and both mint their own
# auto-increment IDs, so the two DBs cannot be merged by overwriting. We only
# add rows whose primary key dev does not already have -> zero collisions.
# Surrogate-keyed meta tables (postmeta/usermeta/...) are inserted WITHOUT the
# live meta_id (dev auto-assigns), keyed on the natural parent id.
#
# Usage:
#   topoff-dev-from-live.sh <preview|test|apply> [all|db|bucket]
#
#     preview  read-only. Reports exactly what WOULD be added. Writes nothing.
#     test     does the real DB inserts inside a transaction, then ROLLS BACK.
#              (bucket "test" == dry-run.) Proves it runs clean, persists nothing.
#     apply    backs up dev first, then commits the DB inserts and copies media.
#
#     scope    all (default) | db | bucket
#
set -euo pipefail

CONF=/etc/lg-topoff.conf
sudo test -r "$CONF" || { echo "FATAL: cannot read $CONF" >&2; exit 1; }
# creds are root:www-data 0640; read via sudo so both ubuntu (CLI) and www-data (button via sudo) work
# shellcheck disable=SC1090
source <(sudo cat "$CONF")

MODE="${1:-preview}"
SCOPE="${2:-all}"
case "$MODE"  in preview|test|apply) ;; *) echo "mode must be preview|test|apply" >&2; exit 2;; esac
case "$SCOPE" in all|db|bucket) ;;       *) echo "scope must be all|db|bucket" >&2; exit 2;; esac

# ---- safety guard: dev only. Never let this run against the prod box. -------
HOST="$(hostname)"
if [ "${DEV_DB}" = "wp_loothgroup" ] || echo "$HOST" | grep -qiE '45-223|prod'; then
  echo "FATAL: refusing to run — this looks like the prod target, not dev (host=$HOST, dev_db=$DEV_DB)" >&2
  exit 3
fi

# ---- connections ------------------------------------------------------------
live()     { MYSQL_PWD="$LIVE_PASS" mysql -h "$LIVE_HOST" -u "$LIVE_USER" --connect-timeout=12 -N "$LIVE_DB" "$@"; }
livedump() { MYSQL_PWD="$LIVE_PASS" mysqldump -h "$LIVE_HOST" -u "$LIVE_USER" --single-transaction \
               --no-tablespaces --skip-lock-tables --skip-triggers --no-create-info --complete-insert "$@"; }
devsql()   { sudo mysql -N "$@"; }

echo "== top-off  mode=$MODE  scope=$SCOPE  ($LIVE_DB @ $LIVE_HOST  ->  $DEV_DB @ $HOST) =="
live -e "SELECT 1" >/dev/null || { echo "FATAL: cannot reach live DB (SG/bind/creds?)" >&2; exit 4; }

# ---- compute the delta: ids LIVE has that DEV lacks -------------------------
# comm -23 sortedA sortedB  ->  lines only in A (live).
new_ids() {  # $1 col, $2 table, [$3 live WHERE], [$4 dev WHERE]
  # dev side defaults to ALL rows (WHERE 1) so we never insert a PK dev already
  # has in any form (e.g. dev holds an ID as a revision, live as a real post).
  local lw="${3:-1}" dw="${4:-1}"
  comm -23 <(live -e "SELECT $1 FROM $2 WHERE $lw" | sort) <(devsql "$DEV_DB" -e "SELECT $1 FROM $2 WHERE $dw" | sort)
}
csv() { paste -sd, - ; }   # newline list -> a,b,c   (empty stays empty)

run_db() {
  echo "-- scanning DB delta --"
  local NP NU NT NTT NC
  NP=$(new_ids ID wp_posts "post_type NOT IN (${POST_TYPE_DENY})"); local nP; nP=$(echo -n "$NP" | grep -c . || true)
  NU=$(new_ids ID wp_users);              local nU;  nU=$(echo -n "$NU"  | grep -c . || true)
  NT=$(new_ids term_id wp_terms);         local nT;  nT=$(echo -n "$NT"  | grep -c . || true)
  NTT=$(new_ids term_taxonomy_id wp_term_taxonomy); local nTT; nTT=$(echo -n "$NTT" | grep -c . || true)
  NC=$(new_ids comment_ID wp_comments);   local nC;  nC=$(echo -n "$NC"  | grep -c . || true)

  printf '   would add: %s posts, %s users, %s terms, %s term_taxonomy, %s comments\n' "$nP" "$nU" "$nT" "$nTT" "$nC"
  printf '   (+ their postmeta/usermeta/termmeta/commentmeta and new posts'\'' term_relationships)\n'

  if [ "$MODE" = "preview" ]; then echo "   preview only — no writes."; return 0; fi
  if [ $((nP+nU+nT+nTT+nC)) -eq 0 ]; then echo "   nothing to add."; return 0; fi

  # ---- stage ONLY the delta rows into a scratch DB (schema mirrors dev) ----
  echo "-- staging delta into $STAGE_DB --"
  devsql -e "DROP DATABASE IF EXISTS $STAGE_DB; CREATE DATABASE $STAGE_DB;"
  local T
  for T in wp_users wp_usermeta wp_terms wp_termmeta wp_term_taxonomy \
           wp_posts wp_postmeta wp_term_relationships wp_comments wp_commentmeta; do
    devsql -e "CREATE TABLE $STAGE_DB.$T LIKE $DEV_DB.$T;"
  done

  stage() {  # $1 table  $2 where-expr (skipped if empty list)
    local tbl="$1" where="$2"
    [ -n "$where" ] || return 0
    livedump --where="$where" "$LIVE_DB" "$tbl" | sudo mysql "$STAGE_DB"
  }
  local NP_C NU_C NT_C NTT_C NC_C
  NP_C=$(echo "$NP" | csv); NU_C=$(echo "$NU" | csv); NT_C=$(echo "$NT" | csv)
  NTT_C=$(echo "$NTT" | csv); NC_C=$(echo "$NC" | csv)

  [ -n "$NU_C" ]  && stage wp_users               "ID IN ($NU_C)"
  [ -n "$NU_C" ]  && stage wp_usermeta            "user_id IN ($NU_C)"
  [ -n "$NT_C" ]  && stage wp_terms               "term_id IN ($NT_C)"
  [ -n "$NT_C" ]  && stage wp_termmeta            "term_id IN ($NT_C)"
  [ -n "$NTT_C" ] && stage wp_term_taxonomy       "term_taxonomy_id IN ($NTT_C)"
  [ -n "$NP_C" ]  && stage wp_posts               "ID IN ($NP_C)"
  [ -n "$NP_C" ]  && stage wp_postmeta            "post_id IN ($NP_C)"
  [ -n "$NP_C" ]  && stage wp_term_relationships  "object_id IN ($NP_C)"
  [ -n "$NC_C" ]  && stage wp_comments            "comment_ID IN ($NC_C)"
  [ -n "$NC_C" ]  && stage wp_commentmeta         "comment_id IN ($NC_C)"

  # ---- apply: backup first (apply only), then insert in a transaction ------
  if [ "$MODE" = "apply" ]; then
    mkdir -p "$BACKUP_DIR"
    local bk="$BACKUP_DIR/${DEV_DB}_pre-topoff_$(date +%F_%H%M%S).sql.gz"
    echo "-- backing up dev -> $bk --"
    sudo mysqldump --single-transaction --no-tablespaces --skip-lock-tables "$DEV_DB" | gzip -c | sudo tee "$bk" >/dev/null
    echo "   backup: $(ls -lh "$bk" | awk '{print $5}')"
  fi

  local FINISH; [ "$MODE" = "apply" ] && FINISH="COMMIT;" || FINISH="ROLLBACK;"
  echo "-- inserting (transaction; will $([ "$MODE" = apply ] && echo COMMIT || echo ROLLBACK)) --"
  # No real FK constraints in WP, so insert order is free. Surrogate meta ids dropped.
  sudo mysql "$DEV_DB" <<SQL
START TRANSACTION;
INSERT INTO $DEV_DB.wp_users               SELECT * FROM $STAGE_DB.wp_users;
INSERT INTO $DEV_DB.wp_usermeta   (user_id,meta_key,meta_value)    SELECT user_id,meta_key,meta_value       FROM $STAGE_DB.wp_usermeta;
INSERT INTO $DEV_DB.wp_terms               SELECT * FROM $STAGE_DB.wp_terms;
INSERT INTO $DEV_DB.wp_termmeta   (term_id,meta_key,meta_value)    SELECT term_id,meta_key,meta_value        FROM $STAGE_DB.wp_termmeta;
INSERT INTO $DEV_DB.wp_term_taxonomy       SELECT * FROM $STAGE_DB.wp_term_taxonomy;
INSERT INTO $DEV_DB.wp_posts               SELECT * FROM $STAGE_DB.wp_posts;
INSERT INTO $DEV_DB.wp_postmeta   (post_id,meta_key,meta_value)    SELECT post_id,meta_key,meta_value        FROM $STAGE_DB.wp_postmeta;
INSERT IGNORE INTO $DEV_DB.wp_term_relationships  SELECT * FROM $STAGE_DB.wp_term_relationships;
INSERT INTO $DEV_DB.wp_comments            SELECT * FROM $STAGE_DB.wp_comments;
INSERT INTO $DEV_DB.wp_commentmeta(comment_id,meta_key,meta_value) SELECT comment_id,meta_key,meta_value     FROM $STAGE_DB.wp_commentmeta;
SELECT CONCAT('   in-txn dev post count = ', COUNT(*)) FROM $DEV_DB.wp_posts;
$FINISH
SQL
  devsql -e "DROP DATABASE IF EXISTS $STAGE_DB;"
  if [ "$MODE" = "test" ]; then
    echo "   TEST complete — transaction ROLLED BACK, dev unchanged."
  else
    echo "   APPLY complete — committed. (undo: restore the pre-topoff backup above)"
  fi
}

run_bucket() {
  echo "-- bucket: $R2_SRC -> $R2_DST (additive, never deletes) --"
  local args=( copy "$R2_SRC" "$R2_DST" --transfers 16 --checkers 32 --exclude "$BUCKET_EXCLUDE" )
  if [ "$MODE" = "apply" ]; then
    rclone "${args[@]}" --stats-one-line -v
    echo "   bucket copy complete."
  else
    echo "   (dry-run — nothing copied)"
    rclone "${args[@]}" --dry-run 2>&1 | tail -8
  fi
}

if [ "$SCOPE" = "all" ] || [ "$SCOPE" = "db" ];     then run_db;     fi
if [ "$SCOPE" = "all" ] || [ "$SCOPE" = "bucket" ]; then run_bucket; fi
echo "== done =="
