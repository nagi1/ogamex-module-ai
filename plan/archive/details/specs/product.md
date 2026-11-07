# Product and population

Owner: product/gameplay. Read the [main roadmap](../../README.md) first.

The target is the existing OGameX Next AI module. [Phase 5 PvE](pve-empire.md) runs separately from the normal population described here.

## Experience to create

A miner recognizes a trading partner; a raider learns a rival's approximate habits; an alliance remembers assistance; a defeated account changes course and rebuilds. The world has consequences even when nobody is chatting.

Do not optimize for tricking humans into believing every account is human. Server rules explain automation; account information identifies it. Chat uses a fictional commander persona without invented claims about real-world employment, family or human identity. Timezones and busy windows are simulation parameters.

Normal accounts may fight other automated accounts and humans under the same rules. An AI account is not a loot dispenser, an invincible sentinel or a scheduled quest.

## What "real" has to mean, and what it does not

The bar for ordinary play is that a human observer cannot tell an account apart from another
human. That is a behavioural claim, so it is defined by what a player can actually observe:
reaction latency to a probe or attack and whether a save ever fails, the shape of the account's
day, its publicly readable hourly growth curve, the repetitiveness of its action sequence, and
the breadth of its social contact. The evidence for those signals — and for the one signal no
design can hide, a request footprint that can stamp an activity star — is recorded in
[account authenticity](../research/account-authenticity.md).

Indistinguishability is not deception, and the two must not be blended:

- **Behaviour** is the bar above, and it is ours to meet.
- **Disclosure** is a server-policy choice. On official OGame this module is prohibited by
  construction: playing without a human click and never emitting an activity star are mutually
  exclusive, and the tolerated-tool whitelist has no disclosure path. OGameX is the operator, so
  the rule there is ours to write, and the position above — server rules explain automation,
  account information identifies it — is the honest one.
- **Anonymity from an operator is not a goal.** No invented real-world biography, and no attempt
  to defeat an operator who forbids automation.

## Population admission

Start small, cluster some accounts near plausible interaction ranges, and mix economic roles. Avoid filling every empty coordinate or surrounding every newcomer with raiders. Position and starting state must follow the configured account-creation rules.

For mature servers prefer gradual enrollment and ordinary progression. If operators offer catch-up starts, define a visible universe-wide eligibility rule usable by comparable human newcomers; record every grant. Never secretly match AI wealth to nearby humans.

Review population weekly using active regions, interaction opportunities, economy effects and service capacity. Use hysteresis and a daily admission cap to prevent growth oscillation. Keep established identities; do not delete a rival because a human logs in. Retiring accounts complete existing flights and use normal inactivity/vacation behavior where supported. They do not vanish with another player's promised resources.

The population controller may use aggregate server metrics for admission and protection budgets. Individual decision policies receive no privileged targeting data from it.

## Conflict that remains enjoyable

Use profitable, intelligible motives, limited attention and personality-dependent restraint. A disappointed attacker can switch targets; a destroyed fleet can trigger risk aversion, a miner pivot or a break. Do not script consolation wins or revenge attacks against a human who just logged in.

Alongside core protections, publish configurable module limits for repeated pressure on one defender. A server-level target reservation counter can deny additional AI attacks when a cap is reached; it returns only allow/deny, never other attackers' intel or plans. Apply consistently to defenders and test against farming exploits. Exact caps need pilot tuning, not a universal OGame assumption.

## Boundaries

Do not rebuild monetization, the combat engine, a market, quests or core progression to support this module. Use trading, ACS, expeditions, moons or vacation only if the fork supports their actual semantics. The inspected capabilities and remaining host contracts are recorded in [extension work](module-extension-points.md).

Release decisions use [human outcomes and rollout gates](validation.md). More logins or more generated messages alone are insufficient.
