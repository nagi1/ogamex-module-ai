#!/usr/bin/env bash
# Run one T row. Refuse to overwrite evidence from a previous run.
set -euo pipefail
module=$(cd "$(dirname "$0")/../.." && pwd)
root=$(cd "$module/../.." && pwd)
source_dir=${OGSIM_SOURCE:-"$module/rl/ogsim"}
row=${1:?usage: row.sh ROW [ARM ...]}
shift
[[ $row =~ ^[a-zA-Z0-9_-]+$ ]] || { echo 'invalid row name' >&2; exit 2; }
arms=(base "$@")
for arm in "${arms[@]}"; do
    [[ $arm =~ ^[a-zA-Z0-9_-]+$ ]] || { echo "invalid arm: $arm" >&2; exit 2; }
    if [[ $arm != base ]] && ! grep -Fq "arg == \"$arm\"" "$source_dir/examples/season.rs"; then
        echo "season has no named arm: $arm" >&2
        exit 2
    fi
done
data="$root/storage/rl/ogsim"
out="$data/runs/flow/$row"
mkdir -p "$out"
if compgen -G "$out/*.jsonl" >/dev/null; then
    echo "refusing to overwrite season data in $out" >&2
    exit 2
fi
export PATH="$HOME/.cargo/bin:$PATH"
export VIRTUAL_ENV="$root/.venv-rl"
python="$root/.venv-rl/bin/python"
(cd "$source_dir" && "$root/.venv-rl/bin/maturin" develop --release && cargo build --release --example season)
season="$source_dir/target/release/examples/season"
catalogue="$data/units-parity/econ-u1-colonies.json"
baseline=${ROW_BASELINE:-"$data/runs/flow/e47h/base-score.json"}
[[ $row == e47* || $row == e48* ]] && baseline="$data/runs/flow/e42/score-base.json"
[[ -f $baseline ]] || { echo "baseline scorecard not found: $baseline" >&2; exit 2; }
pids=()
for arm in "${arms[@]}"; do
    for seed in {1..10}; do
        args=()
        [[ $arm == base ]] || args=("$arm")
        "$season" "$data" 365 64 "$seed" "$catalogue" "$out/$arm-$seed.jsonl" "${args[@]}" >"$out/$arm-$seed.log" &
        pids+=("$!")
        if ((${#pids[@]} == 26)); then
            for pid in "${pids[@]}"; do wait "$pid"; done
            pids=()
        fi
    done
done
for pid in "${pids[@]}"; do wait "$pid"; done
for arm in "${arms[@]}"; do
    files=("$out/$arm-"*.jsonl)
    if ! "$python" "$module/rl/scripts/census.py" "${files[@]}" >"$out/$arm-census.txt" 2>&1; then
        grep -Eq 'health: .*BROKEN|health: (unavailable|incomplete)' "$out/$arm-census.txt" || { cat "$out/$arm-census.txt" >&2; exit 1; }
    fi
    "$python" "$module/rl/scripts/growth.py" "${files[@]}" >"$out/$arm-growth.txt" 2>&1
    if ! "$python" "$module/rl/scripts/hds.py" "${files[@]}" --baseline "$baseline" --save "$out/$arm-score.json" >"$out/$arm-hds.txt" 2>&1; then
        grep -Eq 'health: .*BROKEN|health: (unavailable|incomplete)|verdict:' "$out/$arm-hds.txt" || { cat "$out/$arm-hds.txt" >&2; exit 1; }
    fi
done
"$python" - "$out" "${arms[@]}" <<'PY'
import json, pathlib, statistics, sys
out = pathlib.Path(sys.argv[1])
lines = ["# Season results", "", "| Arm | Seeds | Production mean | Production range | Health |", "| --- | ---: | ---: | ---: | --- |"]
reports = []
broken = False
for arm in sys.argv[2:]:
    totals = []
    seed_values = []
    counts = {}
    required = {'frozen', 'invalid_stock', 'unbalanced', 'clock_ahead', 'unexplained_over_cap', 'production_drop_warning'}
    complete = True
    for path in sorted(out.glob(f"{arm}-*.jsonl")):
        total = 0.0
        with path.open() as f:
            for line in f:
                row = json.loads(line)
                total += row['produced']
                complete = complete and required <= row.get('health', {}).keys()
                for key, value in row.get('health', {}).items():
                    counts[key] = counts.get(key, 0) + int(bool(value))
        totals.append(total)
        seed_values.append(f"{path.stem}: {total:,.0f}")
    health = "BROKEN" if any(v for k, v in counts.items() if not k.endswith('_warning')) else ("PASS" if complete else "INCOMPLETE")
    broken = broken or health != "PASS"
    lines.append(f"| {arm} | {len(totals)} | {statistics.mean(totals):,.0f} | {min(totals):,.0f}–{max(totals):,.0f} | {health}: {counts} |")
    reports.append(f"- {arm}: `{arm}-census.txt`, `{arm}-growth.txt`, `{arm}-hds.txt`; "
                   f"source `{arm}-*.jsonl`, commands `census.py`, `growth.py`, `hds.py --baseline`. "
                   f"Production by seed: {', '.join(seed_values)}.")
    for report in ('census', 'growth', 'hds'):
        reports.append(f"\n### {arm}: {report}\n\n```text\n" +
                       (out / f'{arm}-{report}.txt').read_text().rstrip() + "\n```\n")
(out / 'summary.md').write_text('\n'.join(lines + ['', *reports]) + '\n')
print(out / 'summary.md')
sys.exit(int(broken))
PY
