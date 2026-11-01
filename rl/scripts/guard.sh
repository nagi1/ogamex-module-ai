#!/usr/bin/env bash
# Stops every RL job before WSL runs short: memory pressure itself can freeze the VM (.wslconfig notes, crashes of 28 Oct
# and 1 Nov), so a cgroup's limit is too late. Counts everything on the machine, Docker included. Runs outside rl.slice.
floor=$(awk '/MemTotal/ {print int($2 * 0.20)}' /proc/meminfo)  # 20% free stops the jobs well before the owner's 85% line
while sleep 3; do
  available=$(awk '/MemAvailable/ {print $2}' /proc/meminfo)
  if [ "$available" -lt "$floor" ]; then
    echo "$(date +%T) only $((available / 1048576)) GB available: stopping rl.slice"
    systemctl --user stop rl.slice
    sleep 30
  fi
done
