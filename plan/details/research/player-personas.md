# OGame player personas and human behaviour

Researched 14 September 2026 from public sources only. This narrows
[player research](player-research.md) to the *people* rather than the game, and it is the
evidence base behind [account authenticity](account-authenticity.md). Communication channels
and reply cadence stay in [player communication](player-communication.md); nothing here
repeats that document.

Owner: product/gameplay. Consumed by [player model](../specs/player-model.md) and the
Phase 3 conversation slice.

## Why this document exists

The product goal is that an AI account is not distinguishable from a human one in ordinary
play. That is a claim about human behaviour, so it has to be grounded in observed human
behaviour rather than in our intuition about it. The plan's archetype shares were explicitly
"a test population, not a measured distribution"; this research confirms that no measured
distribution exists publicly, and records where the community's own account of itself
differs from the plan's assumptions.

## Evidence quality, stated before the findings

- **The Gameforge boards are the primary source and they are readable.** The English board
  carried **13,149 members, 121,423 threads and 921,674 posts** with about **115 posts/day**
  when checked (HIGH, direct observation). It preserves 2008-era FAQ threads, 2017 discussion
  threads and live 2026 hit reports side by side, which is what makes long-run comparison
  possible.
- **r/OGame was not readable and is small.** Direct requests returned HTTP 403 and the
  subreddit was served as private; only Wayback captures were available, showing roughly
  **2,274 readers (June 2023)** and **2.9K members (November 2024)** (MEDIUM). Reddit is
  therefore a *minor* channel for this game, not the centre of its culture. Any future
  sampling should weight the boards first.
- **No source publishes an OGame demographic or archetype distribution.** Age, gender,
  country mix, logins per day, session length, archetype share and message statistics are all
  **not established**. Nothing below is an estimate, and no percentage appears here that was
  not published by the source it is attributed to.
- Two of the most useful modern glossaries are **run by private-server operators selling a
  product**. Their *definitions* match the board and wiki and are usable; their *comparative
  figures* are marketing. Every such use is marked MEDIUM at best.

## The named archetypes, and what they actually do

The community vocabulary is small, stable and cross-attested by at least five independent
sources: the OGame wiki, a Gameforge board guide, a 2026 community glossary, a 2026 vendor
glossary and Reddit threads.

| Archetype | Description in the community's own words | Confidence |
| --- | --- | --- |
| Miner | "very high mines as major source of income and often trade resources with other players"; keeps a set number of cargos per planet; climbs ranks slower but "mines are solid points that cannot be lost" | HIGH — [board guide 01](https://board.en.ogame.gameforge.com/index.php?thread/810247-guide-01-player-styles-guide/) (revision dated 2016) |
| Fleeter | Large fleets of "every attacking ship type"; profits from crashes and debris; "a fleeter MUST be able to predict when he will be online"; "For many players, a fleet crash is the end of the game" | HIGH — same guide |
| Turtle | Defence-dominant; "may even spend all of his or her resources on defense, with the goal of making an impregnable fortress" | HIGH — [OGame wiki: Playing Styles](https://web.archive.org/web/20250203001603/https://ogame.fandom.com/wiki/Playing_Styles) |
| Raider | "small, fast fleet … attack weaker/inactive players regularly resulting in many small profits"; "Raiders tend to develop into fleeters as they grow" | MEDIUM — [board: Player Types](https://board.en.ogame.gameforge.com/index.php?thread/404447-player-types/) (2008; the term is still current) |
| Explorer | Post-v7 expedition spam; "will even avoid researching combat technologies"; "frowned upon by long time players" | MEDIUM — wiki, above |
| Pratt | "a fleeter who stays in vacation mode much of the time" | LOW — one source only; treat as unpublished vocabulary |

**Hybrid is the norm, and the community says so.** The 2008 FAQ states outright that it is
describing "the extremes", and "the most efficiant way of playing is to be a mix of
everything"; the wiki agrees that "most players are not a 'pure' player in a certain style"
(HIGH). This is a direct argument against our own archetype table being read as six separate
populations.

**Terms the plan should stop using unless sourced:** "sim city player", "deut dealer" and a
named "protector" archetype are **not established** in OGame usage. The *functions* are real
(deuterium supply, helping newcomers) but nobody in the sources labels players with those
names. "Farmer" is an *activity* — "repeatedly raiding the same target" — not an identity.

### Daily shape, and the coupling that makes an account readable

| Factor | Miner / turtle | Fleeter |
| --- | --- | --- |
| Daily time requirement | Low — check in, fleetsave, queue upgrades | High — raiding, timed saves, probe cycles |
| Catastrophic loss risk | Low — mines rebuild | High — a fleet can be destroyed overnight |
| Ranking growth | Slow and steady | Fast bursts, crashes score backwards |
| Dependence on opponents | None | High — needs active unprotected targets |

Confidence MEDIUM (single vendor table) but every row is independently supported by the 2016
board guide and the 2008 FAQ. The design consequence is the important part: **login
frequency, aggression and risk appetite are coupled to declared archetype in the community's
own model.** An account that says it is a turtle and behaves like a fleeter, or a fleeter
that is never online when its own fleet lands, is internally inconsistent to someone who
knows this taxonomy.

Also directly attested: **the miner is structurally the diplomat.** "By trading regularly
with highly ranked players in his area, a miner can acquire NAPs (non attacking pact) with
potential attackers as well as gaining their protection", and "without NAPs or allied
fleeters, a miner cannot really defend themselves from attacks" (HIGH). Trade here is not
economic only; it buys protection.

## Why people play, and why they stay

The best single source is the board thread ["Why do you play OGame?"](https://board.en.ogame.gameforge.com/index.php?thread/788950-why-do-you-play-ogame/)
(17 substantive posts, April 2017). Five themes recur, and they reappear in a 2021 Reddit
returning-player thread and in 2020–22 US board threads:

- **People, not mechanics.** "I currently play for the human interaction, I love talking to
  and meeting people from all over the world." · "Why I play is simple. It's because of the
  people I have met over the years."
- **Playable around a real job.** "I find it perfect that I can bring OGame with me in my
  phone at all times… while working or waiting outside the dentist office."
- **A very low effort floor.** "I can spend as much as a couple of hours a day raiding, or as
  little as 5 minutes a day fleet-saving, while still making regular progress."
- **Difficulty as the attraction.** "The fact that someone could lose years' work with one
  careless decision was the primary attraction for me."
- **A goal ladder that never terminates.** "Now I have a drive to be Rank 1 in researches …
  I just can't bring my self to stop playing even after the 8-10 years it's been."

Confidence MEDIUM for the thread as a whole (self-selected, one board, 2017), HIGH that the
themes recur.

**The community's own answer to "why do you still play" is that it does not know.** A member
with 1,937 posts: "Ive quit and come back 2 or 3 times and I think there is some empty void
in my life I use ogame to fill … one can never 'win'." Another: "i've been planning to retire
for about 5 years now and i'm still here." The in-group joke for the game is "ocrack".

This is the most important motivational finding for the project: **continued play is framed
as habit, an absent win condition and social ties, not as moment-to-moment enjoyment.** An
account that plays *because it is enjoying the current action* is modelling the wrong
motive.

### Why people leave

From the US board thread ["Why is .us Dying?"](https://board.us.ogame.gameforge.com/index.php?thread/101012-why-is-us-dying/&pageNo=4)
(71+ posts, 2020–22, MEDIUM as a sample):

- **One mistake is demotivating.** "you can have destroyed your fleet and resources with one
  mistake, it's very demotivating".
- **Pay-to-win at the top.** "getting to the point on a server where it's impossible to climb
  higher in ranks because the top 25-50 accounts are usually paid to win"; casual accounts
  "quit and leave after less than a month … because they get crashed by a player who Spent
  $500".
- **Automation, asserted openly.** "Three words. Bots Bots Bots. The code that is available
  appears to be smarter than the OGame detection efforts."
- **New-universe cannibalisation.** "Log on do expos/FS and dip out."

**Returning players are a structural feature, not an exception.** The English board's
Introduction & Goodbye board (1,136 threads, 58,432 posts) is dominated by it, with titles
such as "20 years later…", "Logged back in after 7 years", "Comback???" and decade-spanning
personal threads still receiving replies (HIGH — titles and dates observed).

## Culture, register and language

- **Non-native English is the majority by construction.** The EN board hosts parallel
  national communities (CZ, DK, GR, JP, NL, SI, SK, TW), rules § 8 lets the publisher exclude
  players who cannot speak the game's language, and the wiki states that messages "must be in
  English". The EN universe is therefore the common language of many locales — exactly the
  population whose typing we have to imitate.
- **Range, not a norm.** In one thread, two-word replies ("Nice one", "GG nice find") and
  multi-paragraph mission statements are both unremarkable. Fluent and sloppy English coexist
  in the same poster and nobody corrects anyone. Modern reaction emoji did not appear;
  **ASCII smileys (`:)`, `;)`, `:D`) are the community norm** (MEDIUM, observation across many
  threads, not a measured frequency).
- **Visible moderation is normal.** A 2017 spamboard post carries an inline
  `::Edit by NoMoreAngel|Warned for insult/choice of words::` marker.
- **Signature culture is heavy** on the boards: alliance tags, past universes, trophy counts,
  quoted poetry, status lines such as "Retired in Merkur - Miner- top 70". Observed 2008
  through 2026 (HIGH).
- **The hit report is the prestige currency**, and it is *machine-shaped*. Per-universe
  "CR Section" boards, a rigid title format `[TOT: <damage>] Attacker [TAG] vs. Defender
  [TAG]`, tier labels (`Basic / Advanced / Super Advanced 20%`, `New Number 1 Solo`,
  `RIP-Kill`, `Top 10 ACS`), and bodies that render with `[Powered by OGotcha CR Converter
  5.1.0]` or `[Powered by OGameDB.com 0.9.0]`. **Human authorship in these posts is
  concentrated in a one-line preamble and a one-line sign-off** (HIGH, directly observed).
- **The short-term register around loss is fixed**: `GG`, `congratz`, `nice hit`, `GLOTR`,
  `FR`, `BR`, and occasionally a joke about taking your turn next. Replies run one line, two
  to twelve words, frequently ending in a smiley (HIGH, multiple live 2026 threads).
- **NAP-breaking is the culture's cardinal sin**: "NAP breaking is the worst reputation
  damage in OGame. Alliances known for NAP breaking rarely find new partners and are actively
  attacked" (HIGH — [official alliance guide](https://gameforge.com/en-GB/games/ogame-alliance-guide.html)).
- **Alliances are constitutional, not chat channels**: founder → officer → member → recruit
  probation, written NAP terms with a commonly 24–48 hour cancellation period, a designated
  diplomat, logged trades, and removal after about seven days of inactivity (official guide
  HIGH; one real alliance rulebook MEDIUM).

### A real first-contact message

The best sourced exemplar of a stranger introducing itself is a board "Looking for Alliance"
post (February 2025):

> "Hello everyone! I'm a semi-active player, looking for an alliance with a discoverer class.
> I'm not much of a fleeter, but might become a deuterium supplier in the future! If your
> alliance is looking for new members and match my (only) criteria, hit me up here in personal
> messages or in game!"

The shape to copy: **brief · states playstyle and activity level · mildly self-deprecating ·
offers one concrete future contribution · names the channel.** (HIGH as an exemplar; one
post, structurally echoed elsewhere.)

### Slang: attested, ambiguous, and unattested

**Cross-attested and in live 2026 use (HIGH):** `FS`, `DF`, `deut`, `res`, `RIP/DS`, `BS`,
`BC`, `LC/SC/HC`, `probe`, `ninja`, `lanx` (noun and verb), `moonshot`, `MD`, `ACS` (+ `ACS
Defend`), `IPM`, `ABM`, `RL`, `PT`, `SSD`, `def`, `turtle`, `bunker`, `farm`, `HoF`, `CR`,
`NAP`, `ally`, `PPM`, `PM`, `sitter`, `multi`, `vac`, `bash`, `push`, `blind lanx`, `recall`,
`deploy`, `coords`, `GLOTR`, `FR`, `B7`.

**Documented ambiguities — the community itself warns about them (HIGH):** `RL` is Rocket
Launcher *or* Real Life, `MS` is Moonshot *or* Missile Silo, `LC` is Large Cargo *or* Lunar
Colony. One glossary's advice is literally "Confirm abbreviations before assuming" and "Read
before you write". A linguistic system that guesses will get these wrong in front of the
exact audience that reads them.

**Not established — do not use:** `sim city`, `deut dealer`, a `protector` archetype,
"sorry for my english" as a community catchphrase, and any published typo or message-length
distribution.

## What the community's own guidance implies

Two sourced instructions matter more than any vocabulary list:

1. **The game itself teaches anti-pattern-matching as human behaviour.** The wiki's
   fleetsaving guidance: "avoid sending your fleets at 100% speed. Avoid using the same speed
   each time you fleetsave. And avoid fleetsaving at the same times each day. In other words,
   be unpredictable and difficult to time." Non-determinism is not a security trick we are
   adding; it is what the culture trains players to do.
2. **The alliance guide says what to do when a member is hit**: "the alliance's first
   response should be a depot withdrawal and a conversation about what happened — not
   silence." Silence is the response the sources single out as *wrong*; four words are not.

## Implications for the module

Each is traceable to a finding above.

1. **Over-invest in rhythm and absence, not in message polish.** Long effort and long silence
   are both normal. A neighbour replying in nine hours is unremarkable; a neighbour whose
   fleet was never in motion at a log-off time is not.
2. **Couple behaviour to the declared archetype.** Login frequency, aggression and risk must
   co-vary with it, because the community models that coupling explicitly.
3. **Write short and formulaic where the genre is short and formulaic, and spend words only
   where humans spend them.** First contact follows the exemplar above; congratulations and
   sympathy are one line with a smiley; long-form output is a machine-shaped report with a
   thin human layer.
4. **Use the real lexicon and respect its ambiguities.** Misusing `RL`/`MS`/`LC` is exactly
   what the community says identifies a newcomer.
5. **Model a non-native-English majority.** Target casual, lowercase, occasional slip, ASCII
   smileys. Perfect punctuation is the outlier; broken English is a different outlier.
6. **Policy consistency beats any single message.** NAP-breaking is the strongest negative
   reputation signal in the game, so an account that keeps the same alliance, the same terms
   and the same promises over months reads as human for reasons no wording can substitute.
7. **Follow the loss ritual.** Going quiet after an ally is hit is the one documented wrong
   answer.
8. **Let the motive be habit and ties, not fun.** Continued play is described as an absent win
   condition and social obligation, which is also why returning after months is normal and
   should not reset an identity.

## Gap register

| # | Question | Status |
| --- | --- | --- |
| G1 | Age, gender, country or language distribution | **Not published.** Individual self-reports only. |
| G2 | Archetype share (miner / fleeter / turtle / …) | **Not published.** Multiple sources say hybrids dominate, without numbers. |
| G3 | Sessions per day, session length, reply rate to a message | **Not published.** Prose only, "5 minutes a day" to "hours". |
| G4 | Message length and typo rate in *in-game* messages | **Not published.** The one-line register above is a *forum* artefact. |
| G5 | Player reaction to silence, slow replies, or over-eager messages | **Not found.** Nearest indirect signal: recruitment guides warn that generic spam converts far worse. |
| G6 | What players consciously read as "human" versus automated | **Not found.** See [account authenticity](account-authenticity.md); the community's detection talk is about scripting, multi-accounting and pay-to-win, not about persona quality. |
| G7 | r/OGame as a research corpus | **Inaccessible and small.** Wayback only; ≈2.3–2.9K members. |
| G8 | r/OGame and non-English board corpora as message samples | **Not sampled.** The current corpus is English boards only, matching the English-only decision. |

Next research iteration: sample the live board's message-adjacent text over a fixed window
instead of relying on CR threads, and repeat the archetype check against a non-English board
only if the language decision is ever reopened.
