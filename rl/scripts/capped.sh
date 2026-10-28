#!/usr/bin/env bash
# Runs a command as a detached unit in rl.slice, capped at 85% of WSL's RAM and CPU: a job that outgrows the cap is
# killed alone instead of taking WSL down (owner, 28 Oct 2026). Usage: capped.sh NAME LOG COMMAND...
set -euo pipefail
name=$1 log=$2
shift 2
slice=~/.config/systemd/user/rl.slice
if [ ! -f "$slice" ]; then
  mem=$(awk '/MemTotal/ {print int($2 * 0.85 / 1024 / 1024)}' /proc/meminfo)
  mkdir -p "$(dirname "$slice")"
  printf '[Slice]\nMemoryHigh=%sG\nMemoryMax=%sG\nMemorySwapMax=0\nCPUQuota=%s%%\n' $((mem - 2)) "$mem" $(($(nproc) * 85)) > "$slice"
  systemctl --user daemon-reload
fi
systemd-run --user --slice=rl.slice --unit="rl-$name" --collect --same-dir \
  -E PATH="$PATH" -E CUDA_DEVICE_ORDER=PCI_BUS_ID -E OMP_NUM_THREADS="${OMP_NUM_THREADS:-4}" \
  -p StandardOutput="truncate:$log" -p StandardError=inherit "$@"
