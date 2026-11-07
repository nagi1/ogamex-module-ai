# Unverified attack concept

Status: UNVERIFIED. This file records a concept, not a rule. The AI module does not act on
it, and nothing here may be turned into code until the host confirms the mechanic.

## Why this file exists

A community-sourced description of automated attack behaviour was reviewed against the AI
module. The source states no thresholds, ratios, caps or costs, and no existing AI
principle covers the attack concept. There was therefore nothing to confirm against host
behaviour and nothing to encode.

## What the module does instead

Nothing changes. An account under attack is planned by exactly the same principles as an
account that is not under attack. Adding a reaction to attack without host confirmation
would invent a rule the game does not have, and would decide how an account plays from a
page nobody has verified.

## What a modder would change to make this real

Nothing yet. Once the host confirms the mechanic, its numbers belong in the behaviour
data under `resources/behavior`, where the planner reads them, rather than in code that
ships a guess.
