#!/bin/bash
# run-all.sh — EVERY quality gate, one entry point (docs/CRAFT-STANDARD.md).
# Run before pushing user-facing changes; the cut's Phase D acceptance gate.
# Add new gates HERE — a defect class found twice MUST become a gate.
set -uo pipefail
red=0
echo "=== GATE 1/3: visibility matrix (the privacy model) ==="
php /srv/profile-app/bin/visibility-matrix.php || red=1
echo
echo "=== GATE 2/3: web-craft gate (images / weight / eager scripts) ==="
python3 "$(dirname "$0")/craft-gate.py" || red=1
echo
echo "=== GATE 3/3: infra-sec gate (cookie auth / source disclosure / cdp) ==="
bash "$(dirname "$0")/infra-sec-gate.sh" || red=1
echo
if [ "$red" -ne 0 ]; then echo "############ GATES RED — do not push ############"; exit 1; fi
echo "############ ALL GATES GREEN ############"
