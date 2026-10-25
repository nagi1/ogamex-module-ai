#!/usr/bin/env bash
# The fast feedback loop for the errand policy (plan/rl/errand-spec.md).
#
# One command, about six minutes, one number: behaviour-clone the scripted teacher, start PPO from that warm start, and
# report the held-out result the same way every time. Any change to features, candidates, reward or mask is judged here
# and nowhere else - a change that does not move the printed number is not a change.
#
#   PY=/path/to/.venv-rl/bin/python rl/scripts/errand-loop.sh
#   ROWS=120000 MINUTES=6 ACTORS=16 WORLDS=32 KL=0.5 rl/scripts/errand-loop.sh
#
# Exit code 1 when the policy does not beat the teacher, so a loop can stop on it.
set -euo pipefail
cd "$(dirname "$0")/.."

PY=${PY:-python3}
ROWS=${ROWS:-120000}
MINUTES=${MINUTES:-6}
ACTORS=${ACTORS:-16}
WORLDS=${WORLDS:-32}
ACCOUNTS=${ACCOUNTS:-8}
CONTROLLED=${CONTROLLED:-4}
SEED=${SEED:-1}
KL=${KL:-0.5}
DATA=${DATA:-../../../storage/rl/ogsim}
OUT=${OUT:-$DATA/runs/loop}

echo "== behaviour cloning the teacher ($ROWS rows)"
"$PY" -m ogrl.train_bc_world --rows "$ROWS" --seed "$SEED" --out "$OUT/bc.pt" | tail -2

echo "== PPO from that warm start ($MINUTES minutes, $ACTORS actors x $WORLDS worlds)"
"$PY" -m ogrl.train_world --queue 2 --seed "$SEED" --init "$OUT/bc.pt" --kl-anchor "$KL" \
    --actors "$ACTORS" --worlds "$WORLDS" --accounts "$ACCOUNTS" --controlled "$CONTROLLED" \
    --minutes "$MINUTES" --out "$OUT/ppo" > "$OUT/ppo.log" 2>&1 || { echo "PPO failed; see $OUT/ppo.log"; exit 2; }

echo "== verdict (identical held-out worlds each run)"
"$PY" - "$OUT/ppo/eval.jsonl" <<'PY'
import json, sys
rows = [json.loads(line) for line in open(sys.argv[1])]
last = rows[-1]
print(f"  value vs the scripted teacher : {last['value_vs_rule_mean']:+.2%}  95% CI "
      f"[{last['ci95_world_bootstrap'][0]:+.2%}, {last['ci95_world_bootstrap'][1]:+.2%}]")
print(f"  accounts better than teacher  : {last['better_share_accounts']:.0%} of {last['accounts']}")
print(f"  differs from teacher          : {last.get('differs_from_rule_share', 0):.0%} of {last.get('decisions_of_kind', 0):,} decisions")
if 'economy_vs_rule_mean' in last:
    print(f"  economy vs the teacher        : {last['economy_vs_rule_mean']:+.2%}")
    print(f"  fleet vs the teacher          : x{1 + last['fleet_vs_rule_mean']:,.0f}"
          f" ({1 - last['accounts_with_less_fleet']:.0%} of accounts hold more)")
print(f"  worst account                 : {last['worst_account']:+.2%}")
verdict = "PASS" if last["value_vs_rule_mean"] > 0 and last["ci95_world_bootstrap"][0] > 0 else "FAIL"
print(f"  verdict                       : {verdict} (needs value > 0 and its whole CI above 0)")
sys.exit(0 if verdict == "PASS" else 1)
PY
