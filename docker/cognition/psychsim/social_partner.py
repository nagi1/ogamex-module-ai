"""Recursive theory-of-mind partner model for ``POST /social/partner``.

One PsychSim world per question: the asker (``Me``) and a partner club leader (``Partner``) whose type the asker does
not know. The partner has two models with different rewards:

- ``loyal``: a friendship motive. It values the bond the relationship has built and finds betraying a friend costly.
- ``opportunist``: an interest motive. It values its own gains only, and its betrayal loot grows with how much
  stronger it is than the asker (``points_ratio`` = partner points / own points).

Both models choose softly (quantal response), so every observed act is evidence. The asker holds a belief over the
two models and PsychSim's own observation update (``set_observations`` + ``world.step`` with the observed act)
turns the partner's history into the posterior: nothing here computes a posterior by hand. Each partner model holds a
level-0 model of the asker, so the asker reasons about a partner that reasons about it (recursive depth two).

Turns are simultaneous (one parallel turn per round), so neither side moves first and the betrayal payoff is not
circular. The horizon makes the future of the relationship count (the pinned PsychSim ``value`` sums the rewards over
the horizon undiscounted; the ``discount`` attribute is set for a later solver): keeping (or helping, a sacrifice now)
grows the bond and the per-round alliance value, while betraying takes a one-shot loot and turns the relation into a
war for the remaining rounds. A raid is worth most while the opportunity window is open (this round), so the
question "betray now?" weighs the loot now against the future the betrayal throws away.

The server is stateless: the belief and the bond travel in the request and the answer, so a caller carries them
forward from call to call and sends only the partner acts observed since.
"""

from functools import lru_cache
import json

from psychsim.agent import Agent
from psychsim.probability import Distribution
from psychsim.pwl import (WORLD, KeyedMatrix, KeyedVector, equalRow, incrementMatrix, makeFuture, makeTree,
                          rewardKey, setToConstantMatrix, stateKey, actionKey)
from psychsim.world import World

LOYAL = 'loyal'
OPPORTUNIST = 'opportunist'

# Observed public or received acts -> the partner act PsychSim conditions on.
OBSERVED = {'kept': 'keep', 'keep': 'keep', 'help': 'help', 'tribute': 'help',
            'broken': 'betray', 'betray': 'betray', 'betrayal': 'betray', 'attack': 'betray'}

MAX_OBSERVATIONS = 12   # per call; a caller carries the belief forward and sends only new acts
MAX_HORIZON = 4
PARTNER_HORIZON = 2   # the partner models' own lookahead (each is re-planned at every node of the asker's lookahead)
RATIONALITY = 1.5       # quantal response of both partner models: acts are evidence, not certainties
DISCOUNT = 0.9
LOOT = 2.0              # one-shot loot scale of a betrayal (times the betrayer's relative strength)
HELP_COST = 1.0
HELP_GAIN = 1.5
BOND_VALUE = 0.4        # friendship value of each bond point per round
HONOUR = 3.0            # what betraying a friend costs a friendship motive


def _clip(x, lo, hi):
    return max(lo, min(hi, x))


def _traits(raw):
    """The asker's own motives from its public persona traits (0..1, default 0.5)."""
    t = {k: _clip(float(raw.get(k, 0.5)), 0.0, 1.0) for k in ('loyalty', 'honesty', 'ambition', 'cunning')}
    friendship = 0.5 * t['loyalty'] + 0.5 * t['honesty']
    interest = 0.5 * t['ambition'] + 0.5 * t['cunning']
    return friendship, interest


def _reward(agent, other, friendship, interest):
    """Per-round payoff of ``agent``, linear in the round's outcome features (no branch on an uncertain act).

    ``interest`` weighs the own gain of the agent's act plus what the other's act did to it; ``friendship`` weighs the
    bond (worth something every round) and the honour a betrayal costs.
    """
    key = rewardKey(agent.name)
    return makeTree(KeyedMatrix({makeFuture(key): KeyedVector({
        stateKey(agent.name, 'gain'): interest,
        stateKey(other.name, 'harm'): interest,
        stateKey(WORLD, 'bond'): friendship * BOND_VALUE,
        stateKey(agent.name, 'dishonour'): -friendship * HONOUR,
    })}))


class PartnerWorld:
    def __init__(self, ratio, threat, my_traits, horizon, bond, prior_loyal, window, observing=False):
        self.world = w = World()
        self.me, self.partner = Agent('Me'), Agent('Partner')
        w.addAgent(self.me)
        w.addAgent(self.partner)
        self.pact = w.defineState(WORLD, 'pact', list, ['intact', 'broken'])
        w.setFeature(self.pact, 'intact')
        self.window = w.defineState(WORLD, 'window', bool)
        w.setFeature(self.window, bool(window))
        self.bond = w.defineState(WORLD, 'bond', float)
        w.setFeature(self.bond, float(bond))

        ratio = _clip(float(ratio), 0.05, 20.0)
        threat = _clip(float(threat), 0.0, 3.0)
        alliance = 1.0 + threat     # an alliance is worth more under an outside threat
        strength = {self.me.name: 1.0 / ratio, self.partner.name: ratio}

        self.actions = {}
        for agent in (self.me, self.partner):
            agent.setAttribute('discount', DISCOUNT)
            agent.setHorizon(horizon)
            act = w.defineState(agent.name, 'act', list,
                                ['none', 'keep', 'help', 'raid', 'betray', 'fight'])
            w.setFeature(act, 'none')
            gain = w.defineState(agent.name, 'gain', float)
            harm = w.defineState(agent.name, 'harm', float)
            dishonour = w.defineState(agent.name, 'dishonour', float)
            for feature in (gain, harm, dishonour):
                w.setFeature(feature, 0.0)
            raid_loot = LOOT * strength[agent.name]          # a betrayer's loot grows with its relative strength
            war = 0.5 * (strength[agent.name] - 1.0) - threat  # war favours the stronger side, the threat costs both
            mine = self.actions[agent.name] = {}

            def by_pact(feature, intact, broken):
                """Every act is always legal; what it does depends on whether the pact still holds (an act in a
                broken pact is a war round). PsychSim's legality test cannot read an uncertain pact."""
                return makeTree({'if': equalRow(self.pact, 'intact'), True: intact, False: broken})

            for verb in ('keep', 'help', 'betray'):
                a = mine[verb] = agent.addAction({'verb': verb})
                if verb == 'betray':
                    # The raid is worth most while the opportunity window is open (this round).
                    def window(feature, now, late):
                        return {'if': equalRow(self.window, True), True: setToConstantMatrix(feature, now),
                                False: setToConstantMatrix(feature, late)}
                    effects = {act: (window(act, 'raid', 'betray'), setToConstantMatrix(act, 'fight')),
                               gain: (window(gain, raid_loot, 0.5 * raid_loot), setToConstantMatrix(gain, war)),
                               harm: (window(harm, -LOOT * strength[agent.name], -0.5 * LOOT * strength[agent.name]),
                                      setToConstantMatrix(harm, 0.0)),
                               dishonour: (setToConstantMatrix(dishonour, 1.0), setToConstantMatrix(dishonour, 0.0)),
                               self.pact: (setToConstantMatrix(self.pact, 'broken'),
                                           setToConstantMatrix(self.pact, 'broken')),
                               self.bond: (setToConstantMatrix(self.bond, 0.0), setToConstantMatrix(self.bond, 0.0))}
                else:
                    cost, effect, bonding = (0.0, 0.0, 0.5) if verb == 'keep' else (HELP_COST, HELP_GAIN, 1.0)
                    effects = {act: (setToConstantMatrix(act, verb), setToConstantMatrix(act, 'fight')),
                               gain: (setToConstantMatrix(gain, alliance - cost), setToConstantMatrix(gain, war)),
                               harm: (setToConstantMatrix(harm, effect), setToConstantMatrix(harm, 0.0)),
                               dishonour: (setToConstantMatrix(dishonour, 0.0), setToConstantMatrix(dishonour, 0.0)),
                               self.bond: (incrementMatrix(self.bond, bonding), incrementMatrix(self.bond, 0.0))}
                for feature, (intact, broken) in effects.items():
                    w.setDynamics(feature, a, by_pact(feature, intact, broken))
                w.setDynamics(self.window, a, makeTree(setToConstantMatrix(self.window, False)))
        w.setOrder([{self.me.name, self.partner.name}])

        # My own reward: motives from my traits.
        friendship, interest = _traits(my_traits)
        self.me.setReward(_reward(self.me, self.partner, friendship, interest), 1.0, self.me.get_true_model())
        # The partner's two models: friendship (loyal) and interest (opportunist).
        true_model = self.partner.get_true_model()
        self.loyal = self.partner.addModel(LOYAL, parent=true_model, rationality=RATIONALITY,
                                           selection='distribution', horizon=min(horizon, PARTNER_HORIZON))['name']
        self.partner.setReward(_reward(self.partner, self.me, 1.0, 0.3), 1.0, self.loyal)
        self.opportunist = self.partner.addModel(OPPORTUNIST, parent=true_model, rationality=RATIONALITY,
                                                 selection='distribution', horizon=min(horizon, PARTNER_HORIZON))['name']
        self.partner.setReward(_reward(self.partner, self.me, 0.0, 1.0), 1.0, self.opportunist)
        # Level 0 of me inside each partner model: the partner reasons about a non-reasoning me that follows the
        # norm of the relation (keeps the pact; in a war the same act is a war round). The asker reasons about a partner that
        # reasons about it: recursive depth two.
        mine = self.actions[self.me.name]
        zero = self.me.zero_level(null=mine['keep'])
        belief = Distribution({self.loyal: prior_loyal, self.opportunist: 1.0 - prior_loyal})
        if observing:
            # Observation world: the true state names one partner model only so the world can step; the asker's
            # own belief state holds the uncertainty, observes acts and public state (never the partner's model),
            # and PsychSim's belief update conditions it on each observed act.
            w.setModel(self.partner.name, self.loyal)
            for agent in (self.me, self.partner):
                for m in list(agent.models):
                    agent.create_belief_state(model=m)
            w.setMentalModel(self.me.name, self.partner.name, belief)
            self.me.set_observations()
        else:
            # Decision world: it is the asker's subjective world, so the partner's model in it is the asker's
            # posterior, and the asker plans over it (its beliefs are this state, projected through the lookahead).
            w.setModel(self.partner.name, self.loyal)
            for m in list(self.partner.models):
                self.partner.create_belief_state(model=m)
            w.dependency.getEvaluation()  # PsychSim builds its dependency graph from a unique true model
            w.setModel(self.partner.name, belief)
        for m in (self.loyal, self.opportunist):
            self.partner.setAttribute('static', True, m)
            w.setMentalModel(self.partner.name, self.me.name, Distribution({zero: 1.0}), model=m)

    def _my_beliefs(self):
        return [self.me.getBelief(model=m) for m in self.world.getModel(self.me.name).domain()]

    def p_loyal(self):
        beliefs = self._my_beliefs()
        total = 0.0
        for belief in beliefs:
            models = self.world.getModel(self.partner.name, belief)
            total += sum(models[m] for m in models.domain() if self._root(m) == self.loyal)
        return total / len(beliefs)

    def _root(self, model):
        while model not in (self.loyal, self.opportunist):
            parent = self.partner.models[model].get('parent')
            if parent is None:
                return model
            model = parent
        return model

    def _set_everywhere(self, key, value):
        self.world.setFeature(key, value)
        for belief in self._my_beliefs():
            self.world.setFeature(key, value, belief)

    def observe(self, partner_act):
        """One observed partner act inside an intact pact, conditioned on by PsychSim's belief update.

        A kept pact says only that the partner did not break it: the asker then observes the public state (the pact
        held) but not which loyal act it was, so the likelihood is that of keeping or helping. Help received and a
        betrayal are observed as the acts they are.
        """
        self._set_everywhere(self.pact, 'intact')
        self._set_everywhere(self.window, False)
        if partner_act == 'keep':
            hidden = {stateKey(self.partner.name, f) for f in ('act', 'gain', 'harm', 'dishonour')}
            self.me.set_observations(hidden | {actionKey(self.partner.name), stateKey(WORLD, 'bond')})
        else:
            self.me.set_observations()
        self.world.step({self.partner.name: self.actions['Partner'][partner_act],
                         self.me.name: self.actions['Me']['keep']})
        if partner_act == 'betray':
            # Concurrent effects add up in PsychSim, so the asker's own keep would leave a sliver of bond.
            self._set_everywhere(self.bond, 0.0)
        return self.world.getFeature(self.bond, unique=True)

    def decide(self):
        model = self.world.getModel(self.me.name, unique=True)
        result = self.me.decide(self.world.state, model=model)
        values = {action['verb']: round(v['__EV__'], 4) for action, v in result.get('V', {}).items()}
        return result['action']['verb'], values

def _canonical(item):
    return json.dumps(item, sort_keys=True, separators=(',', ':'))


@lru_cache(maxsize=4096)
def _answer(canonical):
    item = json.loads(canonical)
    ratio = float(item.get('points_ratio', 1.0))
    threat = float(item.get('threat', 0.0))
    my_traits = item.get('my_traits') or {}
    horizon = int(_clip(int(item.get('horizon', 3)), 1, MAX_HORIZON))
    bond = float(item.get('bond', 0.0))
    belief = item.get('belief') or {}
    prior = _clip(float(belief.get(LOYAL, 0.5)), 0.01, 0.99)

    history = item.get('history')
    if history is None:
        # No act stream: the public treaty record (pacts broken / kept) as observed acts.
        observed = ['betray'] * int(item.get('broken', 0)) + ['keep'] * int(item.get('kept', 0))
    else:
        ordered = sorted(history, key=lambda e: float(e.get('t', 0.0)))
        observed = [OBSERVED[e['action']] for e in ordered if e.get('action') in OBSERVED]
    dropped = max(0, len(observed) - MAX_OBSERVATIONS)
    observed = observed[dropped:]

    # Belief update over the observed acts (horizon 1 keeps the update cheap; the decision uses the full horizon).
    world = PartnerWorld(ratio, threat, my_traits, 1, bond, prior, False, observing=True)
    for act in observed:
        bond = world.observe(act)
    posterior = world.p_loyal()
    posterior = _clip(posterior, 0.001, 0.999)

    # Decisions from the updated belief, planning over the full horizon.
    # Accept a pact: the best value of being in one (no opportunity open yet) against staying out (0 per round).
    _, accept_values = PartnerWorld(ratio, threat, my_traits, horizon, bond, posterior, False).decide()
    accept = max(accept_values.values()) > 0.0
    # Inside the pact with the opportunity open now: keep, help (a sacrifice now) or betray.
    act_now, act_values = PartnerWorld(ratio, threat, my_traits, horizon, bond, posterior, True).decide()
    return {
        'p_loyal': round(posterior, 4),
        'accept_pact': accept,
        'betray_now': act_now == 'betray',
        'help_now': act_now == 'help',
        'act_now': act_now,
        'values': {'enter': accept_values, 'now': act_values},
        'belief': {LOYAL: round(posterior, 4), OPPORTUNIST: round(1.0 - posterior, 4)},
        'bond': round(bond, 4),
        'observed': len(observed),
        'dropped': dropped,
    }


def answer(item):
    if not isinstance(item, dict):
        return {'error': 'item must be an object'}
    try:
        return _answer(_canonical(item))
    except (KeyError, TypeError, ValueError) as exc:
        return {'error': str(exc)}


def batch(request):
    items = request.get('items')
    if not isinstance(items, list):
        raise ValueError('body must be {"items": [...]}')
    return {'results': [answer(item) for item in items]}
