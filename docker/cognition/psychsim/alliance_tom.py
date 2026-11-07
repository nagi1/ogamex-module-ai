import sys
sys.path.insert(0, '/driver')
from server import NOT_DECIDED, COOPERATE, DEFECT, INVALID_PAYOFF, COOPERATION_PAYOFF, SUCKER_PAYOFF
from psychsim.pwl import equalRow, rewardKey

def reward_tree(agent, mine, theirs, temptation, war):
    """The dilemma with a war payoff: mutual defection is a war, worth more to the stronger side."""
    key = rewardKey(agent.name)
    return makeTree({'if': equalRow(mine, NOT_DECIDED), True: setToConstantMatrix(key, INVALID_PAYOFF),
                     False: {'if': equalRow(theirs, NOT_DECIDED), True: setToConstantMatrix(key, INVALID_PAYOFF),
                             False: {'if': equalRow(mine, COOPERATE),
                                     True: {'if': equalRow(theirs, COOPERATE), True: setToConstantMatrix(key, COOPERATION_PAYOFF), False: setToConstantMatrix(key, SUCKER_PAYOFF)},
                                     False: {'if': equalRow(theirs, COOPERATE), True: setToConstantMatrix(key, temptation), False: setToConstantMatrix(key, war)}}}})
from psychsim.agent import Agent
from psychsim.probability import Distribution
from psychsim.pwl import makeTree, setToConstantMatrix
from psychsim.world import World

def alliance(ratio, broken, kept, prior=0.35):
    """Should the account cooperate (accept or keep a pact) with a partner leader whose type it does not know?
    ratio: partner points / own points. broken/kept: the partner club's public pact history."""
    p = (2 * prior + broken) / (2 + broken + kept)
    world = World()
    me, other = Agent('Account'), Agent('Partner')
    decisions = []
    for agent in (me, other):
        world.addAgent(agent)
        agent.setAttribute('discount', 1); agent.setHorizon(3)
        dec = world.defineState(agent.name, 'decision', list, [NOT_DECIDED, COOPERATE, DEFECT])
        world.setFeature(dec, NOT_DECIDED); decisions.append(dec)
        for verb, val in (('cooperate', COOPERATE), ('defect', DEFECT)):
            a = agent.addAction({'verb': '', 'action': verb})
            world.setDynamics(dec, a, makeTree(setToConstantMatrix(dec, val)))
    # My own incentive to defect grows with how much stronger I am.
    war_me, war_other = 2.0 * max(0.0, 1.0 - ratio), 2.0 * max(0.0, 1.0 - 1.0 / max(ratio, 1e-3))
    me.setReward(reward_tree(me, decisions[0], decisions[1], 1.0 + 1.0 / max(ratio, 1e-3), war_me), 1)
    loyal = other.get_true_model()
    other.setReward(reward_tree(other, decisions[1], decisions[0], 0.5, war_other), 1)
    opp = other.addModel('Partner_opportunist', parent=loyal, horizon=3, rationality=10)['name']
    other.setReward(reward_tree(other, decisions[1], decisions[0], 1.0 + ratio, war_other), 1, model=opp)
    world.setOrder([{me.name}, {other.name}])
    world.setMentalModel(other.name, me.name, Distribution({me.get_true_model(): 1}))
    world.setMentalModel(me.name, other.name, Distribution({loyal: 1 - p, opp: p}))
    for agent in (me, other):
        for m in list(agent.models):
            agent.create_belief_state(model=m)
    world.step()
    return world.getFeature(decisions[0], unique=True), round(p, 3)

if __name__ == '__main__':
    for ratio in (0.2, 0.33, 0.5, 0.75, 1.0, 2.0, 4.0):
        for broken, kept in ((0, 0), (0, 3), (1, 0), (2, 1)):
            print(ratio, broken, kept, alliance(ratio, broken, kept))
