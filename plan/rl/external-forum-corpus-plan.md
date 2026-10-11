# What the forum and Reddit corpus is for (11 Oct 2026)

`storage/rl/external/sources/` holds the public OGame boards and r/OGame (about 115,000 posts and comments when this was
written: EN forum 44k, DE 50k, US 7k, Reddit 9.7k comments and 2k threads; FR/IT/RU/RO/TR/HU smaller or still crawling).
It is **human talk and human opinion**, with no game state and no outcomes. That decides what it can and cannot train.

## What it cannot do

- Train the economy or war policy. A forum post says "I would build a deuterium tank", not "at this state this build
  order won". The economy model needs state, action and result, which only worlds and the real battle and growth data
  (OGMem, OSTATS) hold.
- Be the style of in-game chat. Forum posts are long, quoted and edited; in-game messages are short and fast. A classifier
  tells ours from the forum 87% of the time, but most of that is genre (forum prose against a two-line DM), so it is a
  vocabulary mine, not a chat-style target. `rl/scripts/real_vs_ours.py` prints the number and the word lists.

## What it can do, in order of value

1. **Veteran situations (the pass/fail checks).** Threads on diplomacy, betrayal, bashing, recruitment and fleet saves
   state what players actually do and what draws complaints. Mined into situations with a "veteran does / never does"
   (first 48 written by DeepSeek, committed as `plan/research/veteran-situations.jsonl`: 12 each on truces and pacts, alliance war and fleet saves, recruitment and discipline, trade and etiquette; to be checked against threads before any becomes a test). They
   become scripted situations in `tests/Feature` and `scripts/cohort-scenario.php`, so politics is read in minutes.
2. **Vocabulary and slop list from data.** `real_vs_ours.py` lists words real players use that ours never do and words
   ours use far more than any player. The second list is the composer's banned words (`resources/behavior/composer.yaml`),
   replacing the hand-written one.
3. **Few-shot style for the composer.** Real short messages of the same act (recruit, refuse, thank, warn) retrieved by
   act and given to the composer as style examples (not copied). Measured with `ai:eval-composer` and the real-vs-ours
   spotted share; a change counts only if the spotted share falls and facts are still kept.
4. **Act classifier training data.** The text classifier (act labels) is retrained on real posts labelled by the existing
   classifier plus a DeepSeek check on the ambiguous ones, split by thread. Installed only if it beats the live one on
   a hand-checked held-out set (consolidation-plan stage 6).
5. **Doctrine candidates.** Recurring advice ("keep deut for the fleet save", "never farm below X points") extracted with
   thread counts, written as YAML proposals under `resources/doctrine/` for the strategy lane (`WIK-*` rules, frozen
   unless it names an `aspect:`); never code, never named in a planner.

## Not worth doing

- Fine-tuning a language model on the corpus: the composer works through laravel/ai with a hosted model, and the gain over
  few-shot is unmeasured; cost and drift are real.
- Training on non-English posts until a language is a product requirement. Keep them for the vocabulary check only.
- Full-corpus embedding search at runtime. A curated few hundred lines per act is enough and keeps zero retrieval cost.

## Order of work

1. Veteran situations checked against threads (DeepSeek reads sampled threads per topic and confirms or corrects each).
2. Data-driven banned words and few-shot per act into `composer.yaml`; re-run `ai:eval-composer`.
3. Scripted situations in Pest, run in minutes, replacing judge prose for politics.
4. Classifier retrain on the thread-split set, installed only on a held-out win.

Privacy: authors are pseudonymised by the collector; nothing here stores handles. Raw pages stay out of git.

## First reading (11 Oct)

- Already guarded by a test or a host rule: bashing cap of 6 per day (`RaidBashingCapTest`), pact partners spared
  (`Enemies::spared`), truce kept and a breach remembered (`CeasefireTest`).
- Gaps the situations name that we do not do: a truce or NAP with **stated terms and an end date** (ours is a fixed 24 h with
  no terms); bringing a NAP to the alliance before answering; apology and compensation after a NAP breach; checking a
  recruit's spy history before accepting; never saving a fleet at the same minute every day. These are the next
  scripted situations, in that order.
