#!/bin/bash
# run-all.sh — EVERY quality gate, one entry point (docs/CRAFT-STANDARD.md).
# Run before pushing user-facing changes; the cut's Phase D acceptance gate.
# Add new gates HERE — a defect class found twice MUST become a gate.
set -uo pipefail
red=0
echo "=== GATE 1/4: visibility matrix (the privacy model) ==="
php /srv/profile-app/bin/visibility-matrix.php || red=1
echo
echo "=== GATE 2/4: web-craft gate (images / weight / eager scripts) ==="
python3 "$(dirname "$0")/craft-gate.py" || red=1
echo
echo "=== GATE 3/4: infra-sec gate (cookie auth / source disclosure / cdp) ==="
bash "$(dirname "$0")/infra-sec-gate.sh" || red=1
echo
echo "=== GATE 4/4: hub paragraph-collapse (content_html keeps its breaks) ==="
bash "$(dirname "$0")/hub-content-paragraph-gate.sh" || red=1
echo
# bb-mirror forum-visibility gate (C2/H6) is HELD OUT of the runner: it passes
# standalone but flakes RED in-sequence — the gate's own /hub/ renders call
# /whoami from loopback and trip infra's new limit_req zone. Re-wire ONLY after
# infra exempts 127.0.0.1 from the rate-limits. Run manually meanwhile:
#   bash /srv/bb-mirror/bin/forum-visibility-gate.sh
if [ "$red" -ne 0 ]; then echo "############ GATES RED — do not push ############"; exit 1; fi
echo "############ ALL GATES GREEN ############"
