"""The one floor: a single agent works the tree at a time.

Every agent that edits the module (a DeepSeek writer in strategy-pipeline.py, the Claude lane in
claude-lane.py) takes this flock before it starts a row and keeps it until the row ends. A second agent
does not queue behind it or race it: it leaves and tries again on its next pass. The kernel releases the
lock when the holder dies, so a killed agent never strands the floor.
"""
import fcntl
import os
from contextlib import contextmanager

FLOOR = os.path.join(os.path.dirname(os.path.dirname(os.path.abspath(__file__))), "plan/research/ogame/agent-floor.lock")


@contextmanager
def floor(name):
    """Yield True when this process holds the floor (and write who), False when another agent does."""
    os.makedirs(os.path.dirname(FLOOR), exist_ok=True)
    handle = open(FLOOR, "a+")
    try:
        fcntl.flock(handle, fcntl.LOCK_EX | fcntl.LOCK_NB)
    except OSError:
        handle.close()
        yield False
        return
    handle.seek(0)
    handle.truncate()
    handle.write(f"{name} pid {os.getpid()}\n")
    handle.flush()
    try:
        yield True
    finally:
        handle.close()
