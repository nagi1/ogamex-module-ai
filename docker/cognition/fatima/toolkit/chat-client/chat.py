"""Terminal chat client reproducing the FAtiMA Toolkit paper's running example.

Dialogue tree: Figure 2 / Tables 2-3 of the paper (states s1..s4, lines d1..d5).
Decision rules: Section 5 (generic Speak rule + "Rude" override when mood < 0).
Appraisal rule: Section 3.7 (a Smile is appraised with Desirability taken from
the RapportLevel belief, generating Joy and lifting the mood).

Canonical FAtiMA loop:
  1. every utterance/action is executed via POST /actions, so all characters
     perceive it as an Action-End event;
  2. perception triggers Emotional Appraisal (OCC) and is stored in memory;
  3. the World Model applies the action's effects (DialogueState advances);
  4. the agent's reply comes from Emotional Decision Making (GET /decisions)
     and is itself executed as an action;
  5. time advances each round (POST /tick) so emotion intensities decay.
"""
import json
import re
import sys
from pathlib import Path

from fatima_client import FatimaClient

SEED_DIR = Path(__file__).resolve().parent.parent / "FAtiMA-Toolkit" / "Applications" / "Scenarios" / "seed"
SCENARIO_NAME = "chatdemo"
CHARACTER = "John"
PLAYER = "Player"
END_STATE = "s4"

SPEAK_RE = re.compile(r"Speak\(\s*([^,]+),\s*([^,]+),\s*([^,]+),\s*([^)]+)\)")


def load_seed():
    scenario = (SEED_DIR / "scenario.json").read_text(encoding="utf-8")
    assets = (SEED_DIR / "assets.json").read_text(encoding="utf-8")
    dialogues = json.loads((SEED_DIR / "dialogues.json").read_text(encoding="utf-8"))
    return scenario, assets, dialogues


def get_dialogue_state(client, instance_id):
    for belief in client.get_beliefs(SCENARIO_NAME, instance_id, CHARACTER):
        if belief["Name"] == f"DialogueState({PLAYER})":
            return belief["Value"]
    return None


def find_utterance(dialogues, cs, ns, meaning, style):
    for d in dialogues:
        if (d["CurrentState"], d["NextState"], d["Meaning"], d["Style"]) == (cs, ns, meaning, style):
            return d["Utterance"]
    return None


def print_status(client, instance_id):
    emo = client.get_emotions(SCENARIO_NAME, instance_id, CHARACTER)
    emotions = ", ".join(
        f"{e['Type']}({e['Intensity']:.1f})" for e in emo.get("Emotions", [])
    ) or "none"
    print(f"    [{CHARACTER}: mood {emo['Mood']:+.1f} | emotions: {emotions}]")


def agent_turn(client, instance_id, dialogues):
    """Let the agent decide, execute its chosen Speak action, print it."""
    decisions = client.get_decisions(SCENARIO_NAME, instance_id, CHARACTER)
    if not decisions:
        return False
    decision = max(decisions, key=lambda d: d["Utility"])
    match = SPEAK_RE.match(decision["Action"])
    if not match:
        return False
    cs, ns, meaning, style = (g.strip() for g in match.groups())
    utterance = decision["Utterance"] or find_utterance(dialogues, cs, ns, meaning, style) or ""
    print(f"{CHARACTER}: {utterance}")

    # Execute the agent's action so it is perceived (memory + world model).
    client.execute_actions(
        SCENARIO_NAME, instance_id,
        [{"Subject": CHARACTER, "Action": decision["Action"], "Target": decision["Target"]}],
    )
    return True


def main():
    client = FatimaClient()
    scenario, assets, dialogues = load_seed()

    client.delete_scenario(SCENARIO_NAME)  # start clean if a previous run left it registered
    client.create_scenario(scenario, assets)
    instance_id = client.create_instance(SCENARIO_NAME)

    print(f"Connected to {CHARACTER}. Choose what to say by number, 's' to smile, 'q' to quit.")
    print("(The paper's example: John starts in a bad mood; smiling raises his RapportLevel-based Joy.)")
    print_status(client, instance_id)
    print()

    while True:
        state = get_dialogue_state(client, instance_id)
        if state == END_STATE:
            print(f"[Conversation reached its end state '{END_STATE}'.]")
            break

        opts = [d for d in dialogues if d["CurrentState"] == state]
        if not opts:
            print(f"[No dialogue options for state '{state}'.]")
            break

        print("Your options:")
        for i, opt in enumerate(opts, 1):
            print(f"  {i}. {opt['Utterance']}")
        print("  s. (smile)")
        choice = input("> ").strip().lower()

        if choice in ("q", "quit", "exit"):
            break

        if choice == "s":
            print("You: (smile)")
            # Section 3.7 of the paper: the Smile event is appraised through the
            # agent's RapportLevel belief, generating Joy.
            client.execute_actions(
                SCENARIO_NAME, instance_id,
                [{"Subject": PLAYER, "Action": "Smile", "Target": CHARACTER}],
            )
            print_status(client, instance_id)
            client.tick(SCENARIO_NAME, instance_id, 1)
            print()
            continue

        if not choice.isdigit() or not (1 <= int(choice) <= len(opts)):
            print("Invalid choice.")
            continue

        chosen = opts[int(choice) - 1]
        print(f"You: {chosen['Utterance']}")

        # The player's utterance is an action: all characters perceive it,
        # appraise it emotionally, and the world model advances DialogueState.
        action = f"Speak({chosen['CurrentState']},{chosen['NextState']},{chosen['Meaning']},{chosen['Style']})"
        client.execute_actions(
            SCENARIO_NAME, instance_id,
            [{"Subject": PLAYER, "Action": action, "Target": CHARACTER}],
        )

        if not agent_turn(client, instance_id, dialogues):
            print(f"[{CHARACTER} stays silent.]")
        print_status(client, instance_id)

        # Advance time: emotion intensities decay tick by tick.
        client.tick(SCENARIO_NAME, instance_id, 1)
        print()


if __name__ == "__main__":
    try:
        main()
    except KeyboardInterrupt:
        sys.exit(0)
