#!/usr/bin/env python3
"""Headless PsychSim sidecar: one bounded Theory-of-Mind step over HTTP.

The module owns the OGame-to-PsychSim mapping; this service owns only the
decision-theoretic step. It runs a sequential cooperation dilemma: the account
decides first whether to cooperate, then the counterparty answers holding a
depth-one mental model of the account. The answer is the account's depth-one
decision — cooperate when it models the counterparty as not exploiting its
cooperation, defect when it models exploitation. Nothing here names a fleet,
resource or promise, and the served process has no provider, embedding or Qt.
"""

import json
from http.server import BaseHTTPRequestHandler, HTTPServer

from psychsim.agent import Agent
from psychsim.probability import Distribution
from psychsim.pwl import equalRow, makeTree, rewardKey, setToConstantMatrix
from psychsim.world import World

NOT_DECIDED = 'none'
COOPERATE = 'cooperate'
DEFECT = 'defect'

# Payoff cells, authored by the module's OGame-to-PsychSim mapping contract.
COOPERATION_PAYOFF = 2.0    # both cooperate
SUCKER_PAYOFF = -1.0        # cooperate while the counterparty defects
MUTUAL_DEFECT_PAYOFF = 0.0  # both defect
ACCOUNT_TEMPTATION = 3.0    # the account's own incentive to defect on a cooperator
INVALID_PAYOFF = -10000.0   # deciding before the counterparty's move is not a play


def reward_tree(agent, mine, theirs, temptation):
    """Payoff for ``agent`` as a function of both decisions."""
    key = rewardKey(agent.name)
    return makeTree({'if': equalRow(mine, NOT_DECIDED),
                     True: setToConstantMatrix(key, INVALID_PAYOFF),
                     False: {'if': equalRow(theirs, NOT_DECIDED),
                             True: setToConstantMatrix(key, INVALID_PAYOFF),
                             False: {'if': equalRow(mine, COOPERATE),
                                     True: {'if': equalRow(theirs, COOPERATE),
                                            True: setToConstantMatrix(key, COOPERATION_PAYOFF),
                                            False: setToConstantMatrix(key, SUCKER_PAYOFF)},
                                     False: {'if': equalRow(theirs, COOPERATE),
                                             True: setToConstantMatrix(key, temptation),
                                             False: setToConstantMatrix(key, MUTUAL_DEFECT_PAYOFF)}}}})


def run(request):
    """Answer whether the account cooperates given the counterparty's ``temptation``.

    ``temptation`` is the counterparty's payoff for defecting on a cooperator. The
    account moves first and the counterparty responds, reasoning about the account at
    depth one, so the account's own decision is the depth-one theory-of-mind stance:
    it defects exactly when it models the counterparty as exploiting its cooperation.
    """
    temptation = float(request.get("temptation", 1.0))

    world = World()
    account = Agent('Account')
    world.addAgent(account)
    counterparty = Agent('Counterparty')
    world.addAgent(counterparty)

    decisions = []
    for agent in (account, counterparty):
        agent.setAttribute('discount', 1)
        agent.setHorizon(3)
        dec = world.defineState(agent.name, 'decision', list, [NOT_DECIDED, COOPERATE, DEFECT])
        world.setFeature(dec, NOT_DECIDED)
        decisions.append(dec)

        cooperate = agent.addAction({'verb': '', 'action': 'cooperate'})
        world.setDynamics(dec, cooperate, makeTree(setToConstantMatrix(dec, COOPERATE)))
        defect = agent.addAction({'verb': '', 'action': 'defect'})
        world.setDynamics(dec, defect, makeTree(setToConstantMatrix(dec, DEFECT)))

    account.setReward(reward_tree(account, decisions[0], decisions[1], ACCOUNT_TEMPTATION), 1)
    counterparty.setReward(reward_tree(counterparty, decisions[1], decisions[0], temptation), 1)

    # The account moves first; the counterparty answers with a depth-one model of it.
    world.setOrder([{account.name}, {counterparty.name}])
    world.setMentalModel(counterparty.name, account.name, Distribution({account.get_true_model(): 1}))

    world.step()

    return {'decision': world.getFeature(decisions[0], unique=True)}


class Handler(BaseHTTPRequestHandler):
    def do_GET(self):
        if self.path == "/health":
            self.respond(200, {"status": "ok"})
            return
        self.respond(404, {"error": "not found"})

    def do_POST(self):
        if self.path != "/evaluate":
            self.respond(404, {"error": "not found"})
            return
        try:
            length = int(self.headers.get("Content-Length", "0"))
            request = json.loads(self.rfile.read(length) or b"{}")
            self.respond(200, run(request))
        except (ValueError, json.JSONDecodeError, TypeError) as exc:
            self.respond(400, {"error": str(exc)})

    def respond(self, status, payload):
        body = json.dumps(payload).encode()
        self.send_response(status)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def log_message(self, *_):
        pass


if __name__ == "__main__":
    HTTPServer(("0.0.0.0", 8080), Handler).serve_forever()
