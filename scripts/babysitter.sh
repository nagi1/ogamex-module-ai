#!/usr/bin/env bash
# Watches the worker harness on a clock; see scripts/babysit.py. Log: /tmp/babysitter.log
cd "$(dirname "$0")/.." || exit 1
while true; do
  python3 scripts/babysit.py 2>&1 | tee -a /tmp/babysitter.log
  sleep "${BABYSIT_EVERY:-600}"
done
