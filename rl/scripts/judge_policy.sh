#!/usr/bin/env bash
# A checkpoint through the whole yardstick: played in Rust by `examples/season`, 10 seeds x 365 days, judged by hds.py.
# Usage: judge_policy.sh CHECKPOINT OUT_DIR [SEASON_BIN] [sampled]
set -euo pipefail
ckpt=$(realpath "$1") out=$(realpath -m "$2")
root=$(cd "$(dirname "$0")/../../../.." && pwd)
data=$root/storage/rl/ogsim/r8w
bin=$(realpath "${3:-$root/Modules/AI/rl/ogsim/target/release/examples/season}")
arms="moonstack reach expedition colonies ships cap lab classes feed factory balance satellites doubler moonfeed standing labfirst moontaste netchain merchant techtree doctrine acsdefend acsattack wrecks moonraid relocate"
mkdir -p "$out"; cd "$out"
PYTHONPATH=$root/Modules/AI/rl "$root/.venv-rl/bin/python" -m ogrl.export_policy "$ckpt" policy.json
# A checkpoint of the errand kind (queue 2) flies the fleets of the first FLEET accounts (a third, as raiders are);
# any other decides the builds.
queue=$("$root/.venv-rl/bin/python" -c "import sys, torch; print(torch.load(sys.argv[1], map_location='cpu', weights_only=False).get('queue', 3))" "$ckpt")
plays="policy=policy.json"; [ "$queue" = 2 ] && plays="errand-policy=policy.json fleet-model=${FLEET:-21}"
for s in ${SEEDS:-$(seq 1 10)}; do "$bin" "$data" 365 64 "$s" "$data/units-parity/econ-u1-colonies.json" "ready-$s.jsonl" $arms $plays ${4:-} > "ready-$s.log" & done
wait
# hds.py exits 1 on a FAIL verdict, which is the normal state until the target is met.
"$root/.venv-rl/bin/python" "$root/Modules/AI/rl/scripts/hds.py" ready-*.jsonl --save ready-score.json > ready-hds.txt 2>&1 || true
grep -E 'health|HDS \(|Turing|importance|verdict' ready-hds.txt
