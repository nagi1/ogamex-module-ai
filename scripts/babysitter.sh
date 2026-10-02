#!/usr/bin/env bash
# Watches the worker harness; see scripts/babysit.py. A row held still for 5 minutes is given back every
# minute; the full report (and the page's panel) refreshes every BABYSIT_EVERY seconds. Log: /tmp/babysitter.log
cd "$(dirname "$0")/.." || exit 1
last=0
while true; do
  python3 scripts/babysit.py advance 2>&1 | tee -a /tmp/babysitter.log
  if [ $(( $(date +%s) - last )) -ge "${BABYSIT_EVERY:-600}" ]; then
    python3 scripts/babysit.py 2>&1 | tee -a /tmp/babysitter.log
    last=$(date +%s)
  fi
  sleep 60
done
