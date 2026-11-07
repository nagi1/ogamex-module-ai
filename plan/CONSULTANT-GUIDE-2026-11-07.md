# External consultant review: how to get out of the loop (7 November 2026)

Written after reading `AGENTS.md`, the north-star plan, RL-FLOW, the strong-player plan and its log, the escalations
file, the social plan, the memory files, and the size of the code and the plans. No code here. Diagnosis, rules and a
work order.

---

## 1. Diagnosis: why you are going in circles

### 1.1 Four north stars in five weeks, not one
- 1 Oct: "make the existing engine play every aspect of the game".
- 6 Oct: "one trained player that plays like a good professional human across the whole game".
- 26 Oct: "an account a human cannot tell from other humans", measured by HDS and a Turing AUC.
- 5 Nov: "strength first".
- 6 Nov: "a grinding war world until one player dominates".
- Today: "plays like humans, beats them, and is fun to play against".

Every change of direction rebuilds the yardstick and the reference world from scratch. Worse: the yardstick you
settled on (HDS) was built from real servers full of inactive accounts, farms and lifeforms, and then the owner decided
"no quitters, no farms, no lifeforms". You are now measuring distance to a target you rejected. This is the single
biggest cause of the circling.

### 1.2 You have two players, not one
- The PHP module: 573 classes, 61k lines. This is what humans will play against.
- The scripted player inside the Rust simulator: `players.rs` alone is 5k lines. This is what the models train against.
- Every finding in the simulator is ported by hand to PHP, and vice versa. The log is full of "port bugs", "PHP ported",
  "training/serving skew". On 26 Oct this was named "the slow manual step". It is still there today.
- Result: half the effort goes into keeping two copies aligned instead of into play.

### 1.3 Knob explosion
- About 90 optional simulator "arms", 13 tuning numbers per persona, evolutionary search on the teacher, ES on the
  student, genetic evolution, look-ahead search.
- The strong-player log has 56 entries that read "NO-GO", "n.s.", "CI crosses 0", "no gain" or "STOPPED". Every
  confirmed gain is +1% to +3%, most of them below your own minimum detectable effect (about 2.8%).
- The 26 Oct plan said in writing "stop all learner tuning". Tuning continued through 7 Nov. The understanding is not
  broken. The discipline is.

### 1.4 The product has never been tested
- There is no recorded human play session anywhere in the plans. All measurement is simulator against simulator.
- The only question that matters, "when a person plays against these accounts, do they feel human, do they win, is it
  fun?", has never been asked.

### 1.5 The documentation outweighs the code
- 828 plan files, 65k lines. Six "work order" files that override each other (5.6k lines). `AGENTS.md` itself stacks
  three directions with "where this differs, this wins". A fresh agent reads contradictory orders and picks one.
- 392 commits in 30 days in the module, and 174 modified uncommitted files at one moment (night report of 7 Nov).
  That is noise, not throughput.

### 1.6 RL is the wrong tool for most of the problem, and your own notes say so
- Your pitfalls file, item 11: in NetHack, Lux and Freeciv the scripted bots beat the learned ones, and the correct end
  state is a hybrid.
- Your own results: the economy model is +25% over the persona builds (the one real win). The fleet-errand model "barely
  matters" in the 1000-account world and loses 27% in the war world. Social decisions "do not move points". One learned
  component earned its place. The rest did not.

### 1.7 Many agents at once, despite the "one agent" rule
- A social session ran in parallel with the war session, plus a separate spy-report worker, all editing the same world.
  The rule exists since 26 Oct and is not enforced.

---

## 2. What is right. Keep it and do not touch it

1. **The host-exact Rust simulator** (missions, score, economy, battles with zero difference). This is your most
   valuable asset. Use it. Do not grow it.
2. **The economy model** (`train4/best-es3-s10`). The only real win. Freeze it and serve it.
3. **The no-cheating rule** and the "changing unseen state does not change the decision" test. This becomes the one
   invariant with a mandatory test.
4. **The measurement method**: paired seeds, IQM with bootstrap CI, promotion seeds and quarantine seeds. It exists;
   it must be enforced by the script, not by good intentions.
5. **Doctrine as data and Gate 1** (no object names in code). Correct and respected.
6. **Measure before fixing** (the raid-gate ledger that showed the fixed four slots were the brake). This is the right
   method. The mistake is doing it in the simulator and porting afterwards.
7. **The war-world findings** (real fleet slots, every body as a target, bashing limit, newbie bands, attacking under
   uncertainty with an estimate of the hidden section, re-scouting). These are real player behaviours. Port them to PHP
   once and let them live only there.

---

## 3. The hard rules (90 days, no changes)

### Rule 1: one north star, one sentence, frozen
> "A human plays OGameX on a normal-speed server against 20 AI accounts and says at the end of the session: these are
> real players, the best of them beat me, and I want to play again."

- Measured by humans only (Rule 6). Every simulator number is an instrument, not the goal.
- "Fun" means the opponent is legible: it probes before it attacks, you see its fleet in the galaxy, it leaves traces,
  you can trick it, it has a stable personality, it talks a little. All of that is rules, not learning.
- Any change to the north star before 90 days is written down and deferred to the quarterly review.

### Rule 2: one brain, in PHP
- Behaviour (spying, raiding, fleet saves, colonising, expeditions, moons, defence) lives in one place: the PHP module.
  It is the product.
- The simulator keeps its scripted players for two purposes only: opponents for training the economy model, and a fast
  regression check. **No new behaviour goes into the simulator before it exists in PHP.** A behaviour that is in the
  simulator and not in PHP after two weeks is deleted.
- Red line: any feature being written twice stops until its single home is decided.

### Rule 3: training and tuning frozen for 60 days
- No PPO, no ES, no evolution, no knob sweeps, no "search as player". The numbers have spoken: all of it is ≤ 2% and
  inside the noise.
- Served models are frozen: economy `best-es3-s10`. The fleet model `g16` is taken out of service and replaced by the
  scripted raider (the one that proved it fights). Social RL is cancelled (option C in the 7 Nov report).
- Training reopens only when both hold: (a) a human play session named a specific weakness, and (b) a 15-minute
  simulator test showed ≥ 10% headroom before any training run.

### Rule 4: the arms die
- The reference world has one fixed configuration in one file. Arms that were "kept" become the default and lose their
  flag. Arms that were not kept are deleted.
- Any flag still alive two weeks after creation is deleted. Target: from about 90 to under 10.
- Same for the 13 per-persona numbers: fixed at the adopted g16 values and locked.

### Rule 5: one page
- `AGENTS.md` rewritten in ≤ 80 lines: the north star, these ten rules, the three gates. No "direction on top of
  direction".
- The work order is one file ≤ 200 lines: `plan/WORK-ORDER.md`. Everything else moves to `plan/archive/` (nothing is
  deleted).
- No new handoff file per day. One log, one line per result, with date and commit hash.
- A new agent reads exactly two files: `AGENTS.md` and `WORK-ORDER.md`.

### Rule 6: the real feedback loop is a weekly human play session
- Every week: the owner (ideally plus 2-3 friends who know the game) play one hour on a 1000x universe against 20 AI
  accounts.
- After the session, a five-question card:
  1. Did any account do something no human would do? Write it down.
  2. Did anyone probe you? Attack you? Save their fleet when you threatened them? React in a believable time?
  3. Could you tell which accounts were bots? How?
  4. Who was stronger than you and why?
  5. Did you enjoy it? Do you want to play again?
- The answers to questions 1 and 3 are the **tells**. That list, ranked by frequency, is the only backlog. There is no
  other queue of work.
- The simulator's job afterwards is to confirm that fixing a tell did not break the 60-day world (regression), not to
  discover tells.

### Rule 7: rules first, learning only where proven
- The correct end state is a hybrid (your pitfall 11). The only accepted learned component today is the economy model.
- Every new component is written as rules first and measured by Rule 6. Learning is considered only when the rules hit
  a clear, measured ceiling.

### Rule 8: the judge rejects noise mechanically
- The verdict script prints "NOISE" and refuses to print a number when the CI crosses zero or the effect is below the
  MDE. No "promising, not confirmed" in the log.
- At least 10 seeds. Promotion on seeds 11-60, quarantine on 61-100, once. A result that passes promotion and fails
  quarantine is logged as a failure.
- HDS is demoted from goal to smoke alarm: it may not drop more than 0.05 below the reference. It is measured only on
  the top and mid cells, because the bottom cells compare against quitters you decided do not exist.

### Rule 9: one agent, nothing open overnight
- One session edits at a time. A second session only reads.
- End of day: everything committed or discarded. 174 uncommitted files must never happen again.
- Every logged result carries its commit hash, or it is marked "not reproducible".

### Rule 10: no cheating, with a test
- Every new decision path in PHP ships with a test that changing a rival's hidden state does not change the decision.
  This is the one invariant with a mandatory test.

---

## 4. Work order: the first 30 days

### Week 1: freeze and clean (no new feature)
1. Commit or discard everything modified. Clean branch.
2. Write the new `AGENTS.md` and `WORK-ORDER.md`. Move the rest of the plans to `plan/archive/`.
3. One configuration file for the reference world. Delete the arms that were not kept.
4. Port the confirmed simulator findings to PHP once: real fleet slots, every body as a target, bashing limit, newbie
   protection bands, attacking under uncertainty, re-scouting, the probe-stock fix. Each with a `situation` test.
5. Take the fleet model out of service; the scripted raider flies.

### Week 2: first human session
1. 1000x universe (the existing `grand`), one human plus 20 AI accounts with mixed personas (miner, raider, saver).
2. The owner plays three one-hour sessions in the week and fills in the card.
3. The ranked list of tells becomes the backlog.

### Weeks 3-4: fix the top three tells
1. Each tell: a rule-based fix in PHP, a situation test, the simulator regression (≤ 15 min), commit.
2. Second human session. A tell that disappeared is closed. One that remains goes back on the list with a note.
3. End of month: what does the card say? That is the only number reported to the owner.

### After that: the same cycle every two weeks
- Human session, tells, rule-based fix, regression, commit. Training returns only when Rule 3 is satisfied.

---

## 5. Stop immediately (short list)

| Stop | Why |
| --- | --- |
| Teacher tuning, ES, evolution, search-as-player | all ≤ 2% inside the noise; the log says so |
| Adding a new simulator arm | 90 are enough, and each arm is a second copy of a decision whose home is PHP |
| Porting behaviour between simulator and PHP in both directions | one brain; the simulator follows, it does not lead |
| A new plan or handoff file per day | one page |
| Chasing HDS on the bottom cells | they compare against quitters you decided do not exist |
| Social RL | its decisions do not move points (your result) |
| Changing the north star | frozen for 90 days |
| More than one editing session at a time | 174 uncommitted files |
| Believing a number without a CI and a commit hash | not reproducible means it does not exist |

---

## 6. The answer to "are we doing many things wrong?"

Yes, but not in understanding. Your understanding is excellent: the pitfalls file and the 26 Oct diagnosis are both
correct. The mistakes are three:
1. The goal changes faster than the measurement can follow.
2. The player is written twice, so every finding is paid for twice.
3. The right rules get written and not enforced, because nothing mechanical stops them from being broken.

The fix is not a new idea. The fix is committing to your own correct old ideas and deleting everything around them.
