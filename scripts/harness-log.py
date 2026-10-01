#!/usr/bin/env python3
"""Keep the harness output: follow its log and append every line, timestamped, to a daily file.

DEV TOOLING. The harness writes /tmp/harness-live.log with no times on its lines, and /tmp does not
survive a reboot, so "what happened in the last hour" could not be answered after the fact. This
follower stamps each line with the UTC time it was seen and keeps three days under
plan/research/ogame/logs/, which the watch page reads. It only reads the harness log.

    python3 -u scripts/harness-log.py [/tmp/harness-live.log]
"""
import datetime
import glob
import os
import re
import sys
import time

MODULE = os.path.abspath(os.path.join(os.path.dirname(__file__), ".."))
LOGS = os.path.join(MODULE, "plan/research/ogame/logs")
KEEP_DAYS = 3
ANSI = re.compile(r"\x1b\[[0-9;]*[A-Za-z]")


def prune():
    cutoff = time.time() - KEEP_DAYS * 86400
    for path in glob.glob(os.path.join(LOGS, "harness-*.log")):
        if os.path.getmtime(path) < cutoff:
            os.remove(path)


def main():
    source = sys.argv[1] if len(sys.argv) > 1 else "/tmp/harness-live.log"
    os.makedirs(LOGS, exist_ok=True)
    prune()

    handle = None
    position = os.path.getsize(source) if os.path.exists(source) else 0
    last_prune = time.time()

    while True:
        if os.path.exists(source):
            size = os.path.getsize(source)
            position = 0 if size < position else position  # the log was truncated or replaced
            if size > position:
                if handle is None:
                    handle = open(source, "r", encoding="utf-8", errors="replace")
                handle.seek(position)
                chunk = handle.read()
                # Only whole lines: a half-written line is read again once it is finished.
                complete = chunk[: chunk.rfind("\n") + 1]
                position += len(complete.encode("utf-8", errors="replace"))
                now = datetime.datetime.now(datetime.timezone.utc)
                target = os.path.join(LOGS, f"harness-{now:%Y-%m-%d}.log")
                with open(target, "a", encoding="utf-8") as out:
                    for line in complete.splitlines():
                        text = ANSI.sub("", line).rstrip()
                        if text:
                            out.write(f"{now:%H:%M:%S}\t{text}\n")
        if time.time() - last_prune > 3600:
            prune()
            last_prune = time.time()
        time.sleep(1)


if __name__ == "__main__":
    main()
