#!/bin/bash
# hub-content-paragraph-gate.sh — the paragraph-collapse checkpoint
# (docs/CRAFT-STANDARD.md: a defect class found TWICE becomes a gate).
#
# THE DEFECT CLASS (regressed twice — feed teaser fixed in e3d1d07, then the
# full-body single-topic + topic-body paths collapsed again):
#   bbPress/BuddyBoss store post_content RAW — paragraph breaks are bare "\n\n",
#   no <p>/<br> — and apply wpautop on DISPLAY. The Hub (bb-mirror) renders
#   forums.{topic,reply}.content_html VERBATIM and WP-free (no wpautop at render).
#   If the materializer doesn't bake the paragraph structure in at sync, every
#   newline collapses into one unbroken wall of text.
#
# THE INVARIANT: any published topic/reply whose SOURCE has a multi-paragraph
# break (content_text contains a blank line) MUST carry a block/break tag in its
# materialized content_html. The single source of truth is
# bb_mirror_content_html() in bb-mirror/lib/materializers.php (decode->kses->
# wpautop), shared by the live sync AND bin/backfill.php.
#
# Pure SQL against the canonical store — no browser, no /whoami, no rate-limit
# flake (unlike the forum-visibility gate), so it runs inline in run-all.sh.
#
# Run as ubuntu on dev (sudos to the postgres superuser to read schema forums).
# Exit 0 = GREEN. Non-zero = collapse offenders exist — re-run the backfill
# after fixing the materializer:
#   sudo -u looth-dev wp --path=/var/www/dev eval 'require "/srv/bb-mirror/bin/backfill.php";'
set -uo pipefail

DB=looth

# Offenders: source has a paragraph break (blank line) but the materialized HTML
# carries no block/break tag at all → it WILL render as one collapsed block.
read -r -d '' SQL <<'EOSQL'
WITH bad AS (
  SELECT 'topic'::text AS kind, id FROM forums.topic
   WHERE status IN ('publish','closed')
     AND content_text ~ E'\n[[:space:]]*\n'
     AND content_html !~* '<(p|br|ul|ol|li|blockquote|h[1-6]|pre|div|table)[ />]'
  UNION ALL
  SELECT 'reply'::text AS kind, id FROM forums.reply
   WHERE status = 'publish'
     AND content_text ~ E'\n[[:space:]]*\n'
     AND content_html !~* '<(p|br|ul|ol|li|blockquote|h[1-6]|pre|div|table)[ />]'
)
SELECT kind, id FROM bad ORDER BY kind, id LIMIT 25;
EOSQL

OFFENDERS=$(sudo -u postgres psql -d "$DB" -t -A -F'|' -c "$SQL" 2>&1)
RC=$?
if [ $RC -ne 0 ]; then
  echo "PARAGRAPH-GATE: ERROR querying postgres:"
  echo "$OFFENDERS"
  exit 2
fi

if [ -n "$OFFENDERS" ]; then
  N=$(printf '%s\n' "$OFFENDERS" | grep -c '|')
  echo "PARAGRAPH-COLLAPSE: $N+ posts have multi-paragraph source but NO block tag in content_html"
  echo "  (showing up to 25 — kind|id):"
  printf '%s\n' "$OFFENDERS" | sed 's/^/    /'
  echo "  Fix: bb_mirror_content_html() in materializers.php must wpautop; then re-backfill."
  exit 1
fi

echo "paragraph-collapse gate: GREEN (no collapsed topic/reply bodies)"
exit 0
