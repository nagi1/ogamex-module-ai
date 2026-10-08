# Alliance politics: elites, races, wars, pacts and betrayal

Written 8 Oct 2026 on the owner's direction ("real alliances, not every AI in one; elites hard to get into, several of them,
going to war at the end; spying, conspiracy, betrayal; use the engines, the LLM and classifiers like Jev"). It replaces the
"keep the lane moving" design of ALLY-001 and unfreezes the social-cognition work it needs.

## 1. What is wrong today (measured)

| Observation (late worlds 9363-9382, six empires) | Cause in code |
| --- | --- |
| Six empires in six alliances, every account seated | `AdvanceAiAllianceLifeAction::foundWhenLockedOut`: any ranked account no club takes founds its own |
| No club ever had two members late | The leader's elite rule took only the top 15 % by rank; with six empires that is nobody (fixed in 4bc74e3 with a peer rule) |
| Churn instead of politics | `leaveOneMisfit` frees one member every pass and clears the host's re-join cooldown, so the application lane "moves" |
| Threat 1.0 between nearly every pair, no war between alliances | Grudges are per player; nothing lifts them to the alliance or turns them into a declared war, a pact or a betrayal |

The goal of ALLY-001 (an application lane that never stops) is a metric, not a player. Real servers have a few elite
alliances that are hard to enter, mid and small clubs, and many players in none; the elites fight each other at the end.

## 2. Target behaviour (gate 3)

1. **Ambition, not obligation.** Whether an account wants an alliance, and which tier it aims at, is persona taste in
   YAML (`resources/behavior/alliance-ambition.yaml`): fleeters and raiders aim at an elite, miners and turtles at a
   quiet mid club or none, casuals at a small club or none. A solo account never applies. Expected: a third of a
   cohort in no alliance at all.
2. **Elites are hard to enter.** An elite (the top alliances by points per member) takes only a peer of its members, has a
   size ceiling, and decides one application per pass, the strongest first. A rejected applicant waits the host's
   cooldown, keeps growing, and reapplies; the race is real: when two apply, the stronger gets the seat.
3. **Several elites emerge.** A leader-type account (persona taste) at the top of the ranks that no elite will take, or
   whose rival sits in every elite, founds one. Nobody else founds. Mid clubs form the same way one tier down.
4. **Recruiting and poaching.** An elite with a free seat invites the strongest unaffiliated peer; an elite may offer a
   seat to a strong member of a weaker alliance, who leaves for it (the defection players know).
5. **Wars are provoked, not scheduled.** When the grudges between the members of two alliances pass a line (the summed
   threat the members already record from raids), the leader of the side that was hit more declares war: an
   authored broadcast to the members, a line in the external text ("War: [TAG]"), a message to the other leader.
   At war, every member of the other alliance is an enemy (`Enemies`), so the scouting, enemy-first raids, moon
   destruction and ACS already built aim at them. End-game empires raid each other the most, so the war between
   elites is the natural end state.
6. **Pacts.** A weaker alliance not at war with a stronger one offers a non-aggression pact; the stronger leader
   accepts when its stance toward the other leader cooperates (theory of mind). Members of a pact partner are never
   raided while the pact stands.
7. **Betrayal.** A pact holder breaks a pact when the partner has grown weak and rich and the leader's stance reads
   defect: the war starts with a surprise ACS rather than a declaration. A member at war with its own leader's
   decisions (affinity under the kick line) leaves, sometimes for the enemy.
8. **Conspiracy.** When one alliance holds more than half of the top points, the next two leaders agree privately
   (leader-to-leader messages, no broadcast) on a pact plus a shared war on the leader. Their members learn of it from
   the war, not before.
9. **Spying.** At war, members scout the enemy's planets and moons first (built: 4bc74e3) and share reports inside the
   alliance (built: the ACS join reads mates' reports); a phalanx on an enemy moon is read before an attack.

## 3. Where each part lives (gate 1, gate 2)

| Part | Mechanism | New or changed |
| --- | --- | --- |
| Ambition and tier | `alliance-ambition.yaml` read by `AllianceChoice` | data + one read |
| Who founds | `AdvanceAiAllianceLifeAction`: only a leader-type account at its tier's top that no club of its tier takes | change; `foundWhenLockedOut` goes |
| Churn hacks | `leaveOneMisfit`, the cooldown clears | deleted; leaving happens for a reason (enemy in the club, a better seat accepted, a grudge with the leader) |
| Elite bar, race, size | `ReviewAiAllianceApplicationsAction`: peer share and size from YAML, strongest pending first | change |
| Rejection memory | `AllianceChoice`: a rejection blocks reapplying for the cooldown, not for ever | change |
| Alliance relations | one table `ai_alliance_relations` (alliance, other, stance war/pact, since, cause) and one action `AdvanceAiAlliancePoliticsAction` per pass: declare war, offer and answer a pact, break a pact, conspire | new (one class, one table) |
| Enemies at war, partners spared | `Enemies::of` adds members of alliances at war and removes members of pact partners | change |
| Words | authored templates for declaration, pact offer and answer, betrayal, invitation; delivered through the host's broadcast, alliance texts and private messages | data |
| Stance | the existing `SocialCognition` seam: PsychSim when configured, the native engine otherwise | reuse |

No new driver, no planner beside the existing ones: the war layer only changes who `Enemies` returns, and the military
planners already act on that.

## 4. The engines, the language model and the classifier

| Need | Tool | Why that tool |
| --- | --- | --- |
| Keep or break a pact, accept a pact, poach | PsychSim stance (`stanceToward`), native fallback | Theory of mind is what decides whether the other side will cooperate; the module never ports it |
| How angry a leader is after a hit, which raises war propensity | FAtiMA affect (native default) | Appraisal is the driver's job; the module reads the emotion, never sets it |
| Which social exchange a leader starts (invite, threaten, offer pact) | CiF volition (native default) | Exchange choice already sits behind `SocialCognition` |
| What the AI writes to another AI | Authored templates, zero model calls | AI-to-AI carries the intent; prose is for humans |
| What the AI writes to a human leader in a negotiation | Authored first; the language model only behind `GenerateAiReplyAction`'s existing budget and gates | Human-facing polish, never the decision |
| What a human means in a message (war, pact, threat, plea, betrayal hint, recruitment pitch) | Jev through `ClassificationGateway`, stored as a fact on arrival | Reading meaning is the classifier's home; the decision stays in the module |

### Classifier uses this design adds (beyond `classification-use-plan.md` G1-G5)

| # | Use | Question shape | Consumer | Verdict |
| --- | --- | --- | --- | --- |
| P1 | A human leader's message declares war, offers or accepts a pact, or breaks one | `Choice` over the four stances + none; the alliance named must resolve to a real tag | `ai_alliance_relations` row (the same row the AI-to-AI path writes) | **Build first**: it is the only way human diplomacy reaches the AI alliances |
| P2 | A human applicant's text: serious, farm, spy (a known enemy's alt fishing for intel), spam | `Choice` | Leader review: a spy read is refused like an exploitative stance | Build with P1 |
| P3 | A human member asks the alliance for help or for an ACS on a named target | `Boolean` + validated coordinates | ACS join (`AllianceUnionTargets`) and defend | Build after P1 |
| P4 | Deception: a message offering a pact whose sender is staging fleets at the receiver (host-visible) | `Score` "reads as a lure", paired with host evidence, never alone | PsychSim evidence, not the stance | Measure first |
| P5 | Out of loop: "does this alliance's week read like real politics?" over the authored broadcast and relations log | `Score` + `Choice` per week | Cohort report beside the human-likeness judge (O1) | Build as an offline command |
| P6 | Out of loop: label a war or betrayal as plausible / forced / nonsensical to tune the YAML lines | `Choice` | Owner tuning, never the account | Offline only |

Refused, with the reasons from the driver note: choosing the stance or the emotion, setting the war line, ranking
targets, any call on the login path, any label used as an RL reward.

## 5. Slices

State 8 Oct 2026: S1 (39d0760), S2 (3eea3b4) and S3's pact, betrayal and conspiracy (9fb8cf3 and the conspiracy commit) are in;
poaching and the human-facing P1-P3 readers are open. Pacts are signed between AI leaders only until P1 reads a human's answer.
Wars do not end yet: an ending needs a measured reason (a side's fleet crushed, a surrender), not a timer.

| Slice | Done when (situation or invariant) |
| --- | --- |
| S1 Ambition, founders, elite bar, race, no churn | A late six-to-twelve-empire world ends with at least one solo account, at least one club with three or more members, no club founded by a non-leader, and rejected applicants reapplying after the cooldown |
| S2 Relations table, war from grudges, `Enemies` at war, pact sparing | A world where two clubs raid each other ends with a declared war and ACS attacks between them; pact partners never raid each other |
| S3 Pact offer and answer, betrayal, conspiracy, poaching | Situation tests per move with the native stance; a 36 h world shows at least one pact and one war that began without a declaration or with a coalition |
| S4 P1-P3 classifier consumers behind settings, default off | Replay digest identical with the provider off; situation tests with `Classification::fake()` |
| S5 P5 offline judge | Agreement with owner-scored weeks |
