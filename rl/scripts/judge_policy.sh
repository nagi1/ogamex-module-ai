#!/usr/bin/env bash
# A checkpoint through the whole yardstick: played in Rust by `examples/season`, 10 seeds x 365 days, judged by hds.py.
# Usage: judge_policy.sh CHECKPOINT OUT_DIR [SEASON_BIN] [sampled]
set -euo pipefail
ckpt=$(realpath "$1") out=$(realpath -m "$2")
root=$(cd "$(dirname "$0")/../../../.." && pwd)
data=$root/storage/rl/ogsim/r8w
bin=$(realpath "${3:-$root/Modules/AI/rl/ogsim/target/release/examples/season}")
arms="moonstack reach expedition colonies ships cap lab classes feed factory balance satellites doubler moonfeed standing labfirst moontaste netchain merchant"
mkdir -p "$out"; cd "$out"
PYTHONPATH=$root/Modules/AI/rl "$root/.venv-rl/bin/python" -m ogrl.export_policy "$ckpt" policy.json
for s in $(seq 1 10); do "$bin" "$data" 365 64 "$s" "$data/units-parity/econ-u1-colonies.json" "ready-$s.jsonl" $arms "policy=policy.json" ${4:-} > "ready-$s.log" & done
wait
# hds.py exits 1 on a FAIL verdict, which is the normal state until the target is met.
"$root/.venv-rl/bin/python" "$root/Modules/AI/rl/scripts/hds.py" ready-*.jsonl --save ready-score.json > ready-hds.txt 2>&1 || true
grep -E 'health|HDS \(|Turing|importance|verdict' ready-hds.txt
