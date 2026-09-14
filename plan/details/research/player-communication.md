# Player communication channels and response cadence

Inspected 14 September 2026 from public sources only: official Gameforge pages, the
official wiki, Gameforge board threads and third-party tool sites. No telemetry, no
player messages and no private data were used. Confidence is marked per finding, because
every sample here self-selects — forum posters and wiki editors are not a random draw of
the player base.

## Summary

The claim that "players don't use the in-game chat, they use Discord" is **half right, and
the half that is wrong matters for this module**.

- For **alliance society** — banter, planning, shared tools — Discord is the norm and is
  *officially recommended*. OGame's own alliance guide lists "Set up Discord or another
  external forum" as a step in founding an alliance.
- For **reaching another player in the game**, in-game private messages remain the
  mechanism, and they are the only one that works without a shared external server. A
  player who is not in your alliance and not on your Discord can only be reached this way.
- Response cadence is **hours to days, not seconds**. OGame's own "active now" signal has
  a fifteen-minute resolution, and formal diplomacy is measured in days.

## In-game channels and what they are for

| Channel | Shape | Evidence |
| --- | --- | --- |
| Private message | 1:1, from the message icon in galaxy view, search results, buddy list, alliance member list, statistics page, or by replying to a subject line | [Wiki: Messages](https://ogame.fandom.com/wiki/Messages) |
| Circular message | Alliance-wide or per-rank broadcast; permission-gated to leader/officer; many alliances police what may be posted | [Wiki: Circular Message](https://ogame.fandom.com/wiki/Circular_Message) |
| Alliance chat | Continuous channel; players complain it becomes "an endless stream of messages" that is hard to navigate | [US board suggestion, Aug 2023](https://board.us.ogame.gameforge.com/index.php?thread/105438-new-alliance-chat-feature-topics/) |
| Alliance internal forum | Rank-gated, listed as a member benefit alongside messages | [Gameforge alliance guide](https://gameforge.com/en-GB/games/ogame-alliance-guide.html) |
| Messages screen | Also carries system traffic: combat reports, espionage reports, fleet activity | [Wiki: Messages Screen](https://ogame.fandom.com/wiki/Messages_Screen) |

Two rules apply to official-server messages and are worth knowing before an AI sends one:
messages "cannot contain insults/profanity and must be in English", every received message
carries a **report-insult checkbox**, and **all messages can be viewed by the Game team**
([Wiki: Messages](https://ogame.fandom.com/wiki/Messages)). Message content is therefore
moderated and visible, not private in the way players might assume. Locale-specific wording
is a private-server choice, not something the official rules permit.

## External channels, and why they dominate alliance life

- **Official Discord** exists and is large: the invite advertised **23,452 members**, with
  separate servers for OGame US and for Origin/public test servers.
  Confidence: high on existence and scale.
- The **alliance guide** tells founders to set up "Discord or another external forum" —
  external chat is the expected default, not a workaround.
- **Third-party tooling is built around Discord**, which is the strongest evidence that
  real coordination happens there:
  - **PTRE** shares spy reports and galaxy information across "InGame, Discord and website",
    creates in-game activity profiles and alerts teammates to new mobiles.
  - **AGRbot v2** posts scheduled daily and weekly highscore/progression reports into a
    Discord channel.
  - **OStats** tracks players and universes for "intelligence across the OGame ecosystem".
- The wiki's alliance page states the coexistence plainly: alliances "may also communicate
  using Discord, IRC, or the in-game alliance chat and circulars".
- A player on the US board sums up the split from the other side: a lot of the player base
  "are part of the older generation and there either **don't use discord** or we don't have
  the ability to use discord at work to chat with our alliance". That player asked for
  organised topics inside the in-game alliance chat instead.

## Response cadence

There is no published distribution of reply times, so these are the anchors that do exist,
and they set the scale rather than a mean:

| Anchor | Value | Source |
| --- | --- | --- |
| Game's own "active now" indicator | **15 minutes**, treated as serious intelligence | [Activity tracking guide](https://ogames.net/blog/ogame-activity-tracking-guide) |
| NAP cancellation notice | **24–48 hours is common** | [Gameforge alliance guide](https://gameforge.com/en-GB/games/ogame-alliance-guide.html) |
| Formal war declaration | Must be announced on the OGame forum | [OGame rules](https://en.ogame.gameforge.com/ajax/main/rules) |
| Trade negotiation | Private with trusted players, or public on trade forums/market, at fixed ratios | [Wiki: Trade](https://ogame.fandom.com/wiki/Trade) |
| ACS coordination | **Second-precision**, the one genuinely real-time case | [Gameforge alliance guide](https://gameforge.com/en-GB/games/ogame-alliance-guide.html) |
| Fleetsave / vacation mode | Advice is to "play the game whenever you want" | [Board thread](https://board.en.ogame.gameforge.com/index.php?thread/833962-why-can-t-i-chat-ingame/) |

**Inference, clearly labelled:** an in-game private message is answered on the order of
hours when both players are in the same timezone and active, and a day or more across the
timezone gap or during a quiet period. Minutes-only replies are plausible solely for ACS
coordination, and a browser game with fleet-save as its core loop does not train players to
expect conversation latency. Nobody is surprised by a reply the next time they log in.

## What this changes for the module

1. **Private messages are the correct target, not alliance chat.** These are 1:1, the only
   channel that reaches a stranger, and the one an observing player can actually answer.
   Alliance chat is a moderated mass channel with an organisation problem; the module does
   not need to join it to be plausible.
2. **Reply latency should be recalibrated downward in ambition, not upward in speed.** An
   answer within the next active window — minutes to about an hour — reads as human here.
   The earlier framing of this decision asked "prompt (about a minute) or routine (about
   45 minutes)"; the evidence says both are inside the human band, so latency is *not* the
   deciding factor it was treated as. Pick on isolation and correctness instead.
3. **The earlier expiry risk is overstated for the same reason.** A reply TTL measured in
   hours is consistent with observed practice, so a routine-cadence reply does not have to
   race an expiry the module set for itself.
4. **Broadcast behaviour matters as much as conversation.** The heaviest real traffic is
   report sharing and diplomacy, which is exactly what the third-party tools automate. The
   module already reduces battle and espionage reports; "tell my allies what I saw" is a
   more player-authentic behaviour than small talk, and it fits the existing observation
   path.
5. **In-game text is moderated, reportable and staff-visible.** This supports the plan's
   existing "authored before LLM" stance: authored variants stay inside the rules, and an
   escalated generation is riskier than it looks.
6. **Locale is decided: English only.** The owner chose English on 14 September 2026, so the
   Arabic authored variants and the Arabic evaluation cases are removed rather than left
   dormant. That matches the official English-in-messages rule above, and it deletes a
   configuration value that could only ever hold one.

## Confidence and gaps

- **High:** which channels exist, the moderation rules, that Discord is officially
  recommended, that third-party coordination tools target Discord, the 15-minute activity
  resolution, and the 24–48 hour cancellation norm.
- **Medium:** that in-game private messages are still commonly used for stranger contact and
  diplomacy. Supported by the existence of the report-insult control, the "delete
  conversations with strangers" question, and the alliance guide's "alliance forum or by
  message" phrasing, but no volume data.
- **Low / not established:** any percentage split between Discord and in-game channels.
  No source publishes one, and this note deliberately does not invent it. The board
  suggestion cited above is a single player's post with one like, so it evidences that the
  sentiment exists, not that it is widespread.
- **Not researched:** whether OGameX (this project's host) has its own chat conventions, and
  whether its players differ from official-server players. That should be asked of the
  server owner rather than assumed from these sources.
