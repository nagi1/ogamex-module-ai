#!/usr/bin/env python3
"""Headless PsychSim sidecar: one bounded Theory-of-Mind step over HTTP.

The module owns the OGame-to-PsychSim mapping; this service owns only the
decision-theoretic step. It runs the library's own game-theory ToM pattern (two
agents in a Chicken-style dilemma, each reasoning about the other at depth 1) and
returns the action each agent chose on each step. Nothing here names a fleet,
resource or promise, and the served process has no provider, embedding or Qt.
"""

import json
from http.server import BaseHTTPRequestHandler, HTTPServer

from psychsim.agent import Agent
from psychsim.probability import Distribution
from psychsim.pwl import equalRow, makeTree, rewardKey, setToConstantMatrix
from psychsim.world import World

NOT_DECIDED = 'none'
WENT_STRAIGHT = 'straight'
SWERVED = 'swerved'

PAYOFF = {
    'sucker': -1.0,
    'temptation': 1.0,
    'mutual': 0.0,
    'punishment': -1000.0,
    'invalid': -10000.0,
}


def reward_tree(agent, my_dec, other_dec):
    """The proven game-theory payoff matrix from the library's own example."""
    key = rewardKey(agent.name)
    return makeTree({'if': equalRow(my_dec, NOT_DECIDED),
                     True: setToConstantMatrix(key, PAYOFF['invalid']),
                     False: {'if': equalRow(other_dec, NOT_DECIDED),
                             True: setToConstantMatrix(key, PAYOFF['invalid']),
                             False: {'if': equalRow(my_dec, SWERVED),
                                     True: {'if': equalRow(other_dec, SWERVED),
                                            True: setToConstantMatrix(key, PAYOFF['mutual']),
                                            False: setToConstantMatrix(key, PAYOFF['sucker'])},
                                     False: {'if': equalRow(other_dec, SWERVED),
                                             True: setToConstantMatrix(key, PAYOFF['temptation']),
                                             False: setToConstantMatrix(key, PAYOFF['punishment'])}}}})


def run(request):
    """Step a two-agent ToM dilemma ``steps`` times and return each decision.

    ``request`` is ``{"steps": int, "depth": int}``; ``depth`` 0 keeps only the
    true model, ``depth`` 1 lets each agent model the other.
    """
    steps = max(1, min(10, int(request.get("steps", 1))))
    depth = max(0, min(1, int(request.get("depth", 0))))

    world = World()
    agent1 = Agent('Agent 1')
    world.addAgent(agent1)
    agent2 = Agent('Agent 2')
    world.addAgent(agent2)

    decisions = []
    for agent in (agent1, agent2):
        agent.setAttribute('discount', 1)
        agent.setHorizon(1)
        dec = world.defineState(agent.name, 'decision', list, [NOT_DECIDED, WENT_STRAIGHT, SWERVED])
        world.setFeature(dec, NOT_DECIDED)
        decisions.append(dec)

        action = agent.addAction({'verb': '', 'action': 'go straight'})
        world.setDynamics(dec, action, makeTree(setToConstantMatrix(dec, WENT_STRAIGHT)))
        action = agent.addAction({'verb': '', 'action': 'swerve'})
        world.setDynamics(dec, action, makeTree(setToConstantMatrix(dec, SWERVED)))

    agent1.setReward(reward_tree(agent1, decisions[0], decisions[1]), 1)
    agent2.setReward(reward_tree(agent2, decisions[1], decisions[0]), 1)

    world.setOrder([{agent1.name, agent2.name}])

    if depth >= 1:
        world.setMentalModel(agent1.name, agent2.name, Distribution({agent2.get_true_model(): 1}))
        world.setMentalModel(agent2.name, agent1.name, Distribution({agent1.get_true_model(): 1}))

    result = []
    for _ in range(steps):
        world.step()
        for agent, dec in zip((agent1, agent2), decisions):
            result.append({'agent': agent.name, 'action': world.getFeature(dec, unique=True)})

    return {'decisions': result}


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
