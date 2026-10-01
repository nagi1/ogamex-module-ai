#!/usr/bin/env python3
"""The behaviour board: every Situation-kit story, PASS or FAIL, with why, in about five seconds.

DEV TOOLING. Each test under tests/Feature/Situations/ is one step of an experienced player's day
(plant a situation, run a real session, read what the account did). Run together they answer "what
does an account do today" without a cohort, a clock or a worker. A failing story prints the kit's own
explanation: the work created, what an executor refused and why, what was queued and flown, and the
ranked candidates of the last decision. Rows whose proof names a story are listed beside it.

    python3 scripts/stories.py           # the board
    python3 scripts/stories.py --json    # also written to plan/research/ogame/stories.json
"""
import json
import os
import re
import sqlite3
import subprocess
import sys
import time
import xml.etree.ElementTree as ElementTree

MODULE = os.path.abspath(os.path.join(os.path.dirname(__file__), ".."))
OUT = os.path.join(MODULE, "plan/research/ogame/stories.json")
JUNIT = "/tmp/ogamex-stories.xml"


def run():
    """One parallel run of every story through the shared test lane; the junit file is read back out."""
    started = time.time()
    script = (
        f"./vendor/bin/pest --testsuite=Modules --parallel --processes=4 --filter=SituationTest --log-junit {JUNIT}"
        f" >/dev/null 2>&1; cat {JUNIT}"
    )
    env = dict(os.environ, OGAMEX_RUNNER=os.environ.get("OGAMEX_RUNNER", "local-docker-dev"))
    result = subprocess.run(
        ["bash", "-c", f'source <(sed -n "/^run_core()/,/^}}/p;/^with_test_lane()/,/^}}/p" scripts/ogamex); '
         f'OGAMEX_ROOT="{os.path.dirname(os.path.dirname(MODULE))}" MODULE_ROOT="{MODULE}" with_test_lane run_core sh -c \'{script}\''],
        cwd=MODULE, env=env, capture_output=True, text=True, timeout=600,
    )
    return result.stdout[result.stdout.find("<?xml"):], time.time() - started


def proofs():
    """test file name -> the open rows whose proof names it."""
    owners = {}
    with sqlite3.connect(os.path.join(MODULE, "plan/tasks/tasks.db")) as db:
        for code, proof in db.execute("select code, proof from tasks where status not in ('done','deferred')"):
            for step in (proof or "").split():
                if step.startswith("test:"):
                    owners.setdefault(step[5:], []).append(code)
    return owners


def why(message, story):
    """The kit's explanation, without the repeated story name, the PHPUnit boilerplate or the stack."""
    text = re.sub(r"\s+", " ", message or "")
    text = text.removeprefix(story or "").split(" at Modules/")[0]
    text = re.sub(r"Failed asserting that .*?\.(?= |$)", "", text).strip(" .'")
    return text[:700]


def main():
    xml, seconds = run()
    if not xml:
        sys.exit("stories: the test run produced no report (is local-docker-dev up?)")
    owners = proofs()
    stories = []
    for case in ElementTree.fromstring(xml).iter("testcase"):
        test = case.get("class", "").rsplit("\\", 1)[-1]
        # An Element with no children is falsy, so `find(a) or find(b)` would lose a failure.
        failure = next((node for node in (case.find("failure"), case.find("error")) if node is not None), None)
        stories.append({
            "test": test,
            "story": case.get("name"),
            "pass": failure is None,
            "why": why(failure.text if failure is not None else "", case.get("name")),
            "rows": owners.get(test, []),
        })
    stories.sort(key=lambda story: (story["pass"], story["test"]))

    passed = sum(story["pass"] for story in stories)
    print(f"{passed}/{len(stories)} stories pass ({seconds:.1f}s)\n")
    for story in stories:
        rows = f"  [{', '.join(story['rows'])}]" if story["rows"] else ""
        print(f"{'PASS' if story['pass'] else 'FAIL'}  {story['test']}: {story['story']}{rows}")
        if not story["pass"]:
            print(f"      {story['why']}")

    with open(OUT, "w") as handle:
        json.dump({"at": int(time.time()), "seconds": round(seconds, 1), "stories": stories}, handle, indent=1)
    if "--json" in sys.argv:
        print(f"\nwritten to {os.path.relpath(OUT, MODULE)}")


if __name__ == "__main__":
    main()
