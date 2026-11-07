# OGameX AI Players Module

## 1. Summary

The goal is to add persistent AI players to OGameX that feel like good human players, not NPCs and not perfect bots.

They should use normal accounts, follow normal OGame rules, build real economies, research, colonize, spy, fleetsave, attack, lose fleets, recover, join alliances, remember other players, and chat.

The important part is not raw intelligence. The important part is believable behavior.

A good AI player should:

- Play well, but not perfectly.
- Have a timezone, sleep schedule, work or busy hours, and normal play sessions.
- React with human delays instead of instantly.
- Miss some opportunities.
- Make decisions using only information a normal player could know.
- Use the same game actions and validation as human players.
- Use the existing Rust battle engine when evaluating combat.
- Use battle simulations with a realistic budget instead of brute-forcing every possible target.
- Remember players, rivalries, favors, attacks, and alliance history.
- Chat naturally, with realistic response times and context from previous conversations.
- Change priorities after important events such as losing a fleet, being attacked repeatedly, or joining a war.

The AI should live in a separate OGameX module. The core game stays responsible for rules and execution. The AI module decides what it wants to do.

```text
OGameX Core
    ↓
Legal game state and domain actions
    ↓
AI Players Module
    ↓
Perception
    ↓
Decision
    ↓
Normal OGameX action
    ↓
Core validates and executes
```

There are two different game modes worth supporting.

### Normal Universe

AI players exist alongside human players as normal persistent accounts.

They can:

- Compete in rankings.
- Join alliances.
- Form rivalries.
- Trade information.
- Spy.
- Attack.
- Defend.
- Chat.
- Build relationships.

They should not be marked with an `[AI]` badge during normal play. The point is to make the universe feel populated by believable players, not to constantly remind everyone that some accounts are automated.

### Humans vs AI Empire

This is a separate mode.

Human players are effectively on one side while a coordinated AI empire or AI alliance grows, expands, and fights them.

The same AI player engine should be reused. The difference is the strategic coordination layer.

Normal mode is about believable individual players.

Empire mode is about a long-running PvE conflict against a coherent AI faction.

The first implementation should still be small.

Start with:

1. Module foundation
2. AI account identity
3. Scheduler and realistic player routines
4. AI state and legal information
5. Utility decisions
6. Economy and research
7. Human-like sessions and reaction delays
8. Memory and personality
9. A small population of persistent AI accounts
10. Basic chat

Then add:

- Colonization
- Espionage
- Fleet management
- Rust-based battle simulation
- Attacks and defense
- Alliances
- Diplomacy
- Humans vs AI Empire mode

Do not start by building a general AGI agent, a large planning engine, or an LLM-controlled game loop.

The product question is simple:

> Can believable autonomous players make an empty OGame universe feel alive enough that human players want to keep playing?

That is the thing to prove.

---

## 2. Why This Should Be a Module

OGameX should stay as close as possible to the original game.

AI players are an extension of the game, not a reason to fill the core with AI-specific logic.

Avoid this:

```php
if ($user->is_ai) {
    // special game behavior
}
```

across many parts of the core.

The intended structure is:

```text
OGameX Core
    ├── Economy
    ├── Buildings
    ├── Research
    ├── Fleets
    ├── Combat
    ├── Espionage
    ├── Alliances
    ├── Messaging
    └── Domain events

Modules
    ├── AI Players
    ├── Future PvE modules
    ├── UI experiments
    └── Other extensions
```

Upstream OGameX can remain a clean clone.

Servers that want AI players can enable the module.

The AI module is also a good test of the module system itself. It needs access to scheduling, events, domain actions, UI, messaging, game state, and configuration. If this module can work without polluting the core, the module boundaries are probably useful.

---

## 3. Module System Direction

The practical direction is Laravel-native modules using `nwidart/laravel-modules`.

The AI module may contain:

- Service providers
- Config
- Migrations
- Commands
- Jobs
- Events
- Listeners
- Routes
- Views
- Tests
- Domain services
- Admin configuration

Do not build a large plugin platform before the AI module needs it.

Avoid early work on:

- Module marketplaces
- Remote installers
- Complex dependency solvers
- Custom sandboxing
- Custom permission languages
- Generic plugin APIs for hypothetical modules

Add extension points because a real module needs them.

---

## 4. The Core Boundary

The most important architecture rule is:

> The AI decides intent. OGameX owns legality and execution.

The AI should never implement a second copy of OGame rules.

Bad:

```text
AI decides to build Metal Mine
AI calculates cost
AI checks requirements
AI deducts resources
AI creates queue entry
```

Good:

```text
AI decides to build Metal Mine
    ↓
Existing OGameX building action
    ↓
Core validates requirements
    ↓
Core deducts resources
    ↓
Core creates queue entry
```

The same applies to:

- Research
- Fleet dispatch
- Colonization
- Espionage
- Combat
- Alliance actions
- Messaging where game rules apply

The AI module should use the same domain actions a human request would eventually use.

The core should not need to know why an action came from a human, an AI player, or another module.

---

## 5. Core Extension Points

Prefer normal Laravel extension mechanisms:

- Events
- Service providers
- Interfaces
- Domain actions
- Jobs
- Commands

Useful events may include:

```text
PlanetCreated
BuildingCompleted
ResearchCompleted
FleetDispatched
FleetArrived
BattleFinished
EspionageReportCreated
MessageReceived
AllianceJoined
AllianceLeft
```

The event list should grow from real module requirements.

Do not add a large generic event system in advance.

---

## 6. AI Players Are Not NPCs

An AI player is a persistent account.

It owns normal game state:

```text
Player
    ├── Planets
    ├── Resources
    ├── Buildings
    ├── Research
    ├── Fleet
    ├── Messages
    ├── Alliance
    ├── Rankings
    └── History
```

It should be able to:

- Build
- Research
- Colonize
- Spy
- Attack
- Defend
- Fleetsave
- Lose fleets
- Rebuild
- Change strategy
- Join alliances
- Leave alliances
- Help allies
- Hold grudges
- Forgive old events
- Chat with players

NPC systems can still exist later.

Examples:

- Pirates
- Aliens
- Boss fleets
- Scripted events

They should be separate modules or systems.

---

## 7. Identity in Normal Play

AI accounts need a reliable internal identity, such as:

```text
users.is_ai
```

or module-owned metadata.

That identity is for the server.

It does not need to be shown next to the player name during normal gameplay.

Do not use:

```text
Athena [AI]
```

as the default presentation.

The purpose of normal mode is to create believable active players.

A permanent AI label changes how humans treat the account before any interaction happens and works against that goal.

The server can still decide how much it wants to disclose at a policy or server-rules level. The gameplay UI itself does not need to identify each automated account.

---

## 8. The Target Behavior

The goal is not to imitate a random weak player.

The target should be a good OGame player with a real life.

That means the AI should generally understand:

- Economy progression
- Energy balance
- Research priorities
- Colony expansion
- Fleetsaving
- Espionage
- Target evaluation
- Risk
- Fleet composition
- Battle simulation
- Recovery after losses
- Alliance behavior

But it should also behave like someone who:

- Sleeps
- Works
- Gets busy
- Checks the game quickly sometimes
- Has longer sessions sometimes
- Does not respond instantly
- Does not scan the whole universe every minute
- Does not simulate thousands of battles for every possible action
- Does not always make the mathematically optimal move

The target is competent and believable.

Not perfect.

---

## 9. Realistic Player Routine

Each AI account should have a persistent routine profile.

Example:

```text
Timezone: Europe/Berlin
Sleep: 01:30 to 07:30
Work: 09:00 to 17:30
Weekday activity: medium
Weekend activity: high
Typical quick session: 3 to 8 minutes
Typical long session: 20 to 45 minutes
Message response style: delayed
Risk tolerance: medium
```

Do not make every day identical.

Add variation around:

- Wake time
- Sleep time
- Session start
- Session length
- Number of sessions
- Busy periods
- Weekends
- Occasional inactivity

A player may normally check the game at 08:00 but appear at 08:17 one day and 09:05 another.

A believable routine should have patterns, not a cron expression that another player can reverse-engineer after two days.

---

## 10. Sessions Instead of Constant Ticks

The AI should behave as if it opens the game in sessions.

A session can include several actions:

```text
Login-like wakeup
    ↓
Check messages
    ↓
Check incoming fleets
    ↓
Review completed jobs
    ↓
Queue a building
    ↓
Scan a few targets
    ↓
Respond to one message
    ↓
Maybe launch a fleet
    ↓
Go inactive
```

This is more believable than treating every action as an unrelated timer.

The scheduler can still use `next_action_at` internally, but the behavior layer should understand sessions.

Some events can wake the AI outside its normal routine, but not with perfect instant reaction.

---

## 11. Reaction Latency

Humans do not react in milliseconds.

AI players should have reaction delays.

Examples:

```text
Incoming casual message
→ maybe reply in 5 to 40 minutes if active

Message during work hours
→ maybe reply hours later

Message during sleep
→ no response until next active period

Incoming attack while active
→ react after a short human delay

Incoming attack while offline
→ may not react at all
```

Reaction time should depend on:

- Current session state
- Routine
- Personality
- Importance of the event
- Whether the player is asleep
- Whether the player is busy
- How often this AI normally checks OGame

Do not wake every AI instantly for every event.

That would make the system feel mechanical and unfair.

---

## 12. Imperfect Play

Good players still make mistakes.

AI players should have controlled imperfection.

Examples:

- Miss a profitable raid.
- Delay a building.
- Forget to check one target.
- Overestimate risk.
- Underestimate a player based on old intel.
- Choose a safe action instead of the absolute optimum.
- Fail to respond to a message.
- Skip a session.
- Fleetsave conservatively.
- Change plans after new information.

Mistakes should come from incomplete information, time constraints, risk preferences, and imperfect memory.

Do not create fake stupidity with random nonsense.

---

## 13. Fair Information

The AI must not know information that a normal player cannot know.

It should not directly access:

- Hidden resources
- Hidden fleets
- Exact defense without valid intel
- Real online status
- Future player actions
- Private internal game state
- Other players' plans

The flow should be:

```text
Game state
    ↓
Perception builder
    ↓
Information this player is allowed to know
    ↓
AI decision logic
```

If it wants better information, it should:

- Spy
- Observe activity
- Read battle reports
- Remember previous behavior
- Talk to allies
- Infer from legal public data

---

## 14. AI State and Perception

The decision system should consume a limited state object.

Example:

```text
AIState
    ├── Own planets
    ├── Resources
    ├── Buildings
    ├── Research
    ├── Fleets
    ├── Incoming fleets
    ├── Known targets
    ├── Espionage reports
    ├── Alliance information
    ├── Messages
    ├── Recent events
    ├── Relationships
    └── Memory
```

Example:

```json
{
  "metal": 128000,
  "crystal": 34000,
  "deuterium": 15000,
  "energy": 120,
  "under_attack": false,
  "known_targets": 4
}
```

This boundary is important for:

- Fairness
- Testing
- Debugging
- Future Rust integration
- Future LLM integration

---

## 15. Legal Action Generation

The AI should choose from legal actions.

Example:

```text
BUILD Metal Mine 17
BUILD Crystal Mine 15
BUILD Solar Plant 18
RESEARCH Energy Technology 7
SEND Espionage Probe to X
WAIT
```

The game should produce or validate these candidates before execution.

The AI should not invent impossible actions and rely on validation failures as normal control flow.

A clean flow is:

```text
Perception
    ↓
Candidate generation
    ↓
Scoring
    ↓
Decision
    ↓
Core validation
    ↓
Execution
```

---

## 16. Utility AI

The first general decision engine should be Utility AI.

Example:

```text
Metal Mine       0.82
Crystal Mine     0.67
Solar Plant      0.51
Energy Research  0.43
Wait             0.05
```

Scores can use:

- Economy state
- Energy
- Research goals
- Colony goals
- Threats
- Fleet state
- Personality
- Relationships
- Risk
- Routine
- Current session length
- Recent events

Utility AI gives us predictable behavior without turning the project into a machine-learning problem.

---

## 17. Player Skill Model

AI accounts do not need to be identical.

The default target should be a good player.

A skill profile can affect:

- How often it spies before attacking
- How well it values economy
- How disciplined its fleetsave is
- How many battle simulations it runs
- How aggressively it reacts to profit
- How much old intelligence it trusts
- How often it checks dangerous situations

This creates variation without needing completely different AI systems.

A strong AI should make better decisions because it has better habits and evaluation, not because it can see hidden data.

---

## 18. Personality

Personality should modify priorities.

Useful profiles include:

- Miner
- Researcher
- Expansionist
- Aggressive
- Defensive
- Opportunist
- Diplomatic

Example:

```text
Attack score *= aggression bias
Research score *= research bias
Colony score *= expansion bias
Safety score *= defensive bias
```

Personality should affect chat and relationships too.

An aggressive player may answer threats differently from a diplomatic one.

---

## 19. Memory

Persistent memory is required.

The AI should remember meaningful events:

- This player attacked me.
- This player destroyed my fleet.
- This player helped me.
- This player probes me every day.
- This player kept a deal.
- This ally ignored me during a war.
- This player lied in chat.
- This player is usually online around a certain time.

Memory should be structured.

Example:

```text
Player: Nagi
Relationship: -0.72
Reason: Destroyed colony fleet
Importance: High
Last event: 3 days ago
Confidence: High
```

Memory should decay.

A small probe six months ago should not matter forever.

A destroyed moon or major betrayal may remain important for much longer.

---

## 20. Chat

Chat is part of the player simulation, not a separate cosmetic feature.

AI players should be able to:

- Reply to direct messages
- Talk to allies
- Negotiate
- Ask questions
- Threaten
- Joke
- Refuse requests
- Discuss attacks
- React to previous conversations
- Continue a conversation later

The chat system needs access to:

- Personality
- Relationship state
- Relevant memory
- Current game situation
- Previous messages
- What the AI is legally allowed to know

It must not expose private server information through chat.

---

## 21. Chat Response Timing

Do not answer every message immediately.

A believable reply flow is:

```text
MessageReceived
    ↓
Store conversation event
    ↓
Check routine and activity
    ↓
Decide whether this player would answer
    ↓
Schedule response
    ↓
Generate reply when response time arrives
```

Examples:

```text
AI is currently active
→ reply after a few minutes

AI is at work
→ reply much later

AI is sleeping
→ reply after waking up

AI is annoyed with the sender
→ short reply or no reply

AI is in the same alliance and a fleet is incoming
→ faster response
```

Some messages should receive no response.

That is normal.

---

## 22. LLM Use

An LLM can be useful in two places.

### Chat

Natural conversation is a good use case.

The LLM receives a limited context:

```text
Personality
Relationship
Relevant memories
Recent conversation
Current legal game context
Desired tone
```

It returns text only.

It does not get database access.

### Rare strategic advice

An LLM can optionally help after major events:

- Major fleet loss
- War declaration
- Repeated attacks
- Alliance conflict
- New colony
- Major change in rank

Example output:

```json
{
  "strategy": "defensive_recovery",
  "risk_tolerance": 0.2,
  "military_priority": 0.8
}
```

The deterministic AI system still chooses and executes actual actions.

Do not call an LLM for every normal tick.

---

## 23. Scheduler

Each AI player should have:

```text
next_action_at
```

A scheduler loads only due players.

Conceptually:

```sql
WHERE next_action_at <= NOW()
```

The next action can represent:

- Starting a session
- Continuing a session
- Checking a completed building
- Checking a returning fleet
- Replying to a message
- Reviewing an attack
- Going inactive

This keeps the system cheap and maps well to OGame's timer-based design.

---

## 24. AI Tick

A normal AI execution should look roughly like this:

```text
AI becomes due
    ↓
Acquire player lock
    ↓
Advance OGame state
    ↓
Update routine/session state
    ↓
Build legal perception
    ↓
Handle high-priority events
    ↓
Generate legal actions
    ↓
Score actions
    ↓
Choose action
    ↓
Execute through OGameX
    ↓
Update memory
    ↓
Schedule next action
    ↓
Release lock
```

A tick should be short.

One AI should not hold a worker while thinking about the whole universe.

---

## 25. Global Game Progression

Human players naturally trigger game progression through requests.

AI accounts do not.

Before an AI makes a decision, OGameX needs to advance the relevant game state.

Conceptually:

```text
AI tick
    ↓
Advance GlobalGame
    ↓
Resolve completed buildings
Resolve research
Resolve fleet events
Update resources
    ↓
Build AI perception
```

Otherwise the AI can make decisions using stale state.

---

## 26. Queues and Locks

AI actions should run through queue jobs.

The same account must not execute two AI jobs at the same time.

Use a per-player lock.

Example:

```text
ai-player:{id}
```

Jobs should be:

- Short
- Retry-safe
- Idempotent where practical
- Protected from duplicate execution

This matters around fleet and resource state.

---

## 27. Event-Driven Wakeups

Some events should move the next action closer.

Examples:

```text
MessageReceived
IncomingAttack
FleetReturned
BuildingCompleted
ResearchCompleted
AllianceWarStarted
```

But event-driven does not mean instant.

The event should influence scheduling according to the AI's routine and urgency.

Example:

```text
Incoming attack
+
AI is active
→ react soon

Incoming attack
+
AI is sleeping
→ maybe miss it
```

That difference matters.

---

## 28. Rust Battle Engine

Battle simulation is an exception to a simple "keep everything in Laravel" rule.

OGameX already has a Rust battle engine and PHP integration.

Use it.

There is no reason to build a second battle simulator in PHP for the AI.

The same fixed Rust engine should be the source used for:

- Actual battle calculation
- AI battle prediction
- Tests
- Future tooling

Laravel remains responsible for:

- Selecting candidate targets
- Gathering legal intel
- Choosing fleet candidates
- Deciding how much simulation effort is worth spending
- Making the final decision
- Executing the real fleet action

Rust calculates battle outcomes.

It does not mutate game state.

---

## 29. Human-Like Battle Simulation

A good OGame player uses a battle simulator.

The AI should too.

The important limit is not whether the AI can simulate.

The limit is how often it simulates.

Bad:

```text
For every planet in the universe:
    for every fleet composition:
        run 10,000 simulations
```

Good:

```text
Filter targets using known intel
    ↓
Pick a small set worth considering
    ↓
Build reasonable fleet options
    ↓
Run a realistic number of simulations
    ↓
Choose based on expected profit and risk
```

For a meaningful attack, around 50 simulations is a reasonable baseline for estimating an average outcome.

Possible budgets:

```text
Obvious low-risk raid
→ small simulation set or cached result

Normal uncertain attack
→ around 50 runs

High-value or high-risk commitment
→ 50 to 100 runs
```

The exact numbers should be configurable.

The important part is having a budget.

---

## 30. Battle Simulation Budget

Simulation should be treated as a limited reasoning resource.

Possible limits:

```text
Per candidate: 50 simulations by default
Per AI decision cycle: 200 total simulations
Per player per hour: configurable
Server-wide simulation budget: configurable
```

The AI should also:

- Cache recent results when state has not materially changed.
- Avoid resimulating obviously bad targets.
- Avoid testing every possible fleet permutation.
- Use player skill and personality to decide how much analysis is worth doing.

A cautious good player may simulate more than an aggressive opportunist.

This keeps CPU use under control and makes the behavior more human.

---

## 31. Combat Decision Flow

A future attack decision can look like this:

```text
Known targets
    ↓
Cheap heuristic filter
    ↓
Profit/risk shortlist
    ↓
Generate a few realistic fleet options
    ↓
Rust battle simulations
    ↓
Estimate:
    - win rate
    - losses
    - profit
    - deuterium cost
    - risk
    ↓
Utility score
    ↓
Attack, spy again, wait, or skip
```

Do not start the Rust simulator before cheap filtering.

Most targets should be rejected without expensive simulation.

---

## 32. Fleetsave and Defensive Play

A good AI player needs defensive habits.

It should understand:

- Fleetsave
- Resource saving
- Incoming attack risk
- Returning fleet timing
- When to move a fleet
- When not to fight
- When to accept a small loss

Perfect defense is not the goal.

Its ability to defend should depend on:

- Whether it is currently active
- Whether it noticed the threat
- Available information
- Skill profile
- Reaction delay
- Risk tolerance

A sleeping AI should sometimes lose a fleet.

That is part of making the universe believable.

---

## 33. AI Alliances in Normal Mode

In normal mode, AI players should be independent.

They may eventually:

- Join human alliances
- Join AI-heavy alliances
- Leave alliances
- Create alliances
- Cooperate
- Disagree
- Refuse requests
- Betray alliances
- Build rivalries

Do not turn an AI alliance into a perfect shared brain.

Members should have:

- Different personalities
- Different schedules
- Different information
- Different priorities
- Different risk tolerance
- Delayed communication
- Imperfect coordination

That keeps alliance behavior close to human play.

---

## 34. Humans vs AI Empire Mode

This should be a separate game mode, not the default behavior of normal AI players.

The concept is:

```text
Human players
    VS
AI Empire
```

The AI Empire can be implemented as a group of normal AI-controlled accounts under a shared strategic layer.

Each account still has:

- Normal planets
- Normal resources
- Normal research
- Normal fleets
- Normal battle rules

The empire layer handles:

- Shared long-term objectives
- Territorial priorities
- War priorities
- Resource support
- Target prioritization
- Alliance-level coordination

The player-level AI still handles the actual account.

---

## 35. Normal Mode vs Empire Mode

### Normal Mode

Goal:

> Make the universe feel like it has real active players.

Behavior:

- AI players are independent.
- Alliances emerge naturally.
- Humans may cooperate with AI.
- Humans may fight AI.
- AI players have individual relationships and goals.
- Coordination is imperfect.

### Humans vs AI Empire

Goal:

> Create a persistent PvE war where human players have a common enemy.

Behavior:

- AI accounts belong to one strategic faction.
- The empire has shared objectives.
- Coordination can be stronger than normal mode.
- Humans are encouraged to cooperate against it.
- The AI faction can plan expansion and military campaigns at alliance level.

These modes should reuse the same player AI.

Do not build two separate AI engines.

---

## 36. Empire Coordination

The empire should not get hidden cheats just because it is PvE.

If the mode needs asymmetry, make it an explicit mode rule.

Examples of legitimate mode-level advantages could eventually include:

- More starting accounts
- Different starting positions
- Faster strategic coordination
- Shared faction goals

Do not silently give the empire:

- Infinite resources
- Hidden player fleet data
- Perfect online knowledge
- Impossible reaction times

The interesting version is an empire that wins because it plays well and coordinates, not because the server secretly helps it.

---

## 37. Empire Strategy Layer

The empire layer should operate at a slower timescale than individual player decisions.

Example:

```text
Empire strategy
    ↓
Expand toward region X
Pressure alliance Y
Protect mining cluster Z
Rebuild military strength
Focus research
```

Then individual AI players receive goals.

Example:

```text
Player A
→ economy and ship production

Player B
→ espionage and scouting

Player C
→ attack priority targets

Player D
→ defensive support
```

The player AI still decides how to carry out its job under normal OGame rules.

This keeps the architecture reusable.

---

## 38. Memory and Relationships in Empire Mode

Empire members can share some strategic information, but not necessarily every personal memory.

Separate:

```text
Personal memory
```

from:

```text
Faction intelligence
```

Personal memory can include:

- Rivalries
- Trust
- Chat history
- Personal losses

Faction intelligence can include:

- Known enemy planets
- Recent battle reports
- Important strategic targets
- Alliance-level objectives

This prevents the empire from becoming one giant omniscient brain.

---

## 39. Scalability

Hundreds of AI players, and potentially around one thousand per server, is realistic if most of the system remains event-driven.

Important controls:

- `next_action_at`
- Session scheduling
- Queue jobs
- Per-player locks
- Event-driven wakeups
- Human-like inactivity
- Limited target scanning
- Battle simulation budgets
- No LLM call on every tick

Most AI players should be doing nothing most of the time.

That matches both OGame and real player behavior.

---

## 40. Laravel and Rust Responsibilities

Use Laravel for orchestration:

- Scheduler
- Player routines
- Sessions
- State building
- Legal candidates
- Utility scoring
- Memory
- Chat scheduling
- Jobs
- Locks
- Domain action execution

Use the existing Rust engine where it already has a clear job:

- Battle calculation
- Battle simulation

Future Rust work may make sense for:

- Monte Carlo planning
- Large search trees
- Heavy fleet optimization
- Large strategic simulations

Only move those parts when profiling shows a reason.

Do not move normal orchestration into Rust.

---

## 41. Testing

There should be separate test layers.

### Core rule tests

OGameX should continue to own tests for:

- Building rules
- Research
- Costs
- Fleet validation
- Combat
- Resource handling
- Queues

### AI decision tests

Example:

```text
Given:
- Low energy
- Enough resources
- Solar Plant available

Expect:
- Solar Plant scores highest
```

### Routine tests

Example:

```text
Given:
- AI sleeping
- Casual message received

Expect:
- No immediate response
- Response scheduled after next active period
```

### Combat tests

Example:

```text
Given:
- Valid espionage report
- Candidate fleet
- Simulation budget of 50

Expect:
- Rust simulator used
- No more than configured budget
- Decision based on returned outcome distribution
```

### Integration tests

Example:

```text
AI chooses Metal Mine
    ↓
Normal OGameX action executes
    ↓
Building is queued correctly
```

---

## 42. Determinism

AI tests must be reproducible.

Any randomness used for:

- Session timing
- Personality variation
- Decision noise
- Target selection
- Chat delays
- Mistakes

should support seeded random sources.

Example:

```text
Seed: 123
State: X
Expected action: Y
```

Production behavior can still vary.

Tests should not.

---

## 43. Suggested Implementation Order

### Phase 1: Module foundation

- Finish the module system.
- Establish module loading.
- Add only required core extension points.

### Phase 2: AI identity and accounts

- Internal AI account marker.
- AI account creation.
- Module configuration.
- No public AI badge in normal gameplay.

### Phase 3: Scheduler and routine

- `next_action_at`
- Time zones
- Sleep
- Busy hours
- Sessions
- Reaction delays
- Queue jobs
- Locks

### Phase 4: Perception

- AI state DTO
- Legal information only
- Event handling
- Candidate generation

### Phase 5: Economy and research

- Utility AI
- Buildings
- Research
- Resource priorities
- Wait action

### Phase 6: Human behavior

- Memory
- Personality
- Session variation
- Missed actions
- Imperfect decisions

### Phase 7: Chat

- Conversation memory
- Response scheduling
- Personality-aware replies
- Optional LLM-backed message generation

### Phase 8: Expansion

- Colonization
- Planet evaluation
- Long-term growth

### Phase 9: Espionage and combat

- Target discovery
- Espionage
- Fleet management
- Rust battle simulation
- Simulation budgets
- Attacks
- Defense
- Fleetsave

### Phase 10: Alliances

- Alliance membership
- Cooperation
- Rivalries
- Limited information sharing
- Normal-mode diplomacy

### Phase 11: Humans vs AI Empire

- Empire strategy layer
- Shared objectives
- Faction intelligence
- Coordinated campaigns
- Mode-specific balancing

### Phase 12: Advanced planning

Only if needed:

- More advanced Rust planning
- Larger simulations
- Optional strategic LLM advisor

---

## 44. First MVP

The first MVP should prove that AI accounts can make the universe feel active.

Recommended scope:

```text
Module System
    ↓
Persistent AI accounts
    ↓
Human-like scheduler
    ↓
Economy and research
    ↓
Memory and personality
    ↓
Basic chat
    ↓
10 to 50 AI players
```

They do not need advanced combat on day one.

What matters first is whether players start treating these accounts as part of the world.

Signals to watch:

- Humans check their rankings.
- Humans message them.
- Humans remember specific accounts.
- Humans compete with them.
- Humans react to their growth.
- Humans talk about them to other players.
- The universe feels less empty.
- Retention improves.

If none of that happens, adding a smarter combat planner will not solve the core problem.

---

## 45. What To Avoid

### Perfect bots

A bot that never sleeps, never misses information, and reacts instantly is not a good player simulation.

### Dumb randomness

Human-like behavior does not mean randomly making stupid decisions.

### Hidden game knowledge

The AI should not win because it can read internal state.

### Duplicating OGame rules

The AI module should never become a second implementation of the game.

### AI-specific branches everywhere in core

That defeats the module architecture.

### Infinite combat simulation

A player does not brute-force every possible fleet against every planet all day.

The AI should not either.

### LLM-controlled gameplay

LLMs are useful for chat and occasional strategic advice.

They should not be the authority for game actions.

### Building Empire mode as a separate AI codebase

Reuse the same player AI and add a faction coordination layer.

### Overbuilding the module framework

Build extension points when the AI module proves they are needed.

---

## 46. Architecture Rules

Keep these rules in front of the project:

1. AI players use normal accounts and normal game rules.
2. The target is a good human player with a real life, not a perfect optimizer.
3. AI accounts are not individually marked as AI in normal gameplay.
4. The AI sees only information a normal player could legally know.
5. The AI decides intent. OGameX owns legality and execution.
6. Use sessions, sleep, busy hours, and reaction delays.
7. Missed opportunities and imperfect decisions are part of the design.
8. Memory and relationships must persist.
9. Chat should feel like part of the same player, not a separate chatbot.
10. Use the existing Rust battle engine for battle simulation.
11. Give simulation a realistic budget.
12. Filter targets before running expensive simulations.
13. Laravel owns orchestration and normal decision flow.
14. Rust owns battle calculation and any future proven hot paths.
15. LLMs can handle chat and rare strategic advice, not direct game execution.
16. Normal mode and Humans vs AI Empire mode reuse the same player AI.
17. Normal alliances should not become perfect hive minds.
18. Empire mode can coordinate more strongly, but should still use legal game information.
19. Most AI players should be inactive most of the time.
20. Validate the player experience before building advanced AI.

---

## 47. Long-Term Architecture

```text
                         OGameX Core
                  ┌─────────────────────┐
                  │ Economy             │
                  │ Research            │
                  │ Fleets              │
                  │ Espionage           │
                  │ Alliances           │
                  │ Messaging           │
                  │ Domain Actions      │
                  │ Domain Events       │
                  └──────────┬──────────┘
                             │
                             ▼
                    AI Players Module
                  ┌─────────────────────┐
                  │ Routine / Sessions  │
                  │ Scheduler           │
                  │ Perception          │
                  │ Candidate Actions   │
                  │ Utility AI          │
                  │ Personality         │
                  │ Memory              │
                  │ Chat                │
                  │ Diplomacy           │
                  │ Fleet Decisions     │
                  └──────┬───────┬──────┘
                         │       │
              battle sim│       │chat / rare strategy
                         ▼       ▼
                  ┌─────────┐  ┌────────────┐
                  │ Rust    │  │ Optional   │
                  │ Battle  │  │ LLM        │
                  │ Engine  │  │            │
                  └─────────┘  └────────────┘

Normal Mode:
Independent believable players

Empire Mode:
Same AI players
        ↓
Empire Strategy Layer
        ↓
Shared faction objectives
```

The architecture is intentionally simple.

The core remains authoritative.

The AI module controls behavior.

The Rust engine handles battle simulation.

The LLM handles language and optional high-level advice.

The player experience remains the thing we are trying to improve.
