# Persona taste — every account is a different player

Written 21 Sep 2026. Closes the authenticity gap the owner named: 100 accounts must not be the
same player 100 times. The research (`account-authenticity.md`) is explicit — "there are no player
types", motivations are continuous, and self-similarity is the best-validated detector — yet the
persona is categorical (5 archetypes × 3 skill bands = 15 slots) and the seed only nudges a few
numbers. This spec adds the missing half: continuous, seeded dimensions that make each account a
distinct character, without touching the safety-critical decision scorer.

## The dimensions

One seeded value object, `PersonaTaste`, derived deterministically from the account's `random_seed`
via `RandomSource::unitInterval` (same source as every other per-account draw, so a replay is
reproducible). Three dimensions, each named as something an experienced player is:

| dimension | range | what it means in play | wiring |
|---|---|---|---|
| `diligence` | 0..1 | how often the account opens the game | `sessionsPerDay` inside the archetype's presence band |
| `aggression` | 0..1 | how much fleet risk the account tolerates | fleetsave `exposureBand` |
| `sociability` | 0..1 | how much the account keeps a conversation going | per-session initiation cap |

The archetype stays the *label* (a Fleeter still fleetsaves, a Miner still never raids); the taste
shifts *how much*, so two accounts of one archetype are two different players, not two copies.

## Wiring

- **aggression → save threshold.** `QueueableFleetSavePlanner::exposureBand` scales the archetype
  base by `0.5 + aggression`, so the band is neutral at `aggression = 0.5`. A bold account leaves a
  larger fleet unsaved (riskier), a cautious one saves smaller fleets. Proactive saves only — the
  reactive save under an inbound is untouched, so "a save fires under attack" is never traded away.
- **diligence → cadence.** `RoutineProfile::sessionsPerDay` places the account inside its own
  archetype presence band (`[fewest, most]` from the analogue benchmark): a lazy miner plays the
  floor of the band and a diligent one the ceiling. The band itself, not a multiplier around a
  single base, is what keeps a casual account above its two-visit floor and a fleeter below its
  sixteen-visit ceiling.
- **sociability → initiation.** `InitiateAiSocialContactAction` caps the thank-yous one session
  sends at `round(5 × sociability)`, floored at one. A chatty account works through a whole pile of
  senders; a quiet one thanks the first and leaves the rest for a later session. The richer
  initiation surface (greet a prober, share a report) still waits on host surfaces.

## What this does not touch

- The decision scorer's action weights. The safety margin ("a save always wins") is thin, so a
  taste term there needs a separate safety-preserving design; it is deliberately out of scope.
- Gate 1: no object machine name, id or requirement appears; the dimensions are module policy over
  host state. Gate 2: one value object, three small call-site changes, no new abstraction.

## Acceptance

- Two accounts with the same archetype and different seeds play a different number of sessions a
  day (diligence), have different save bands (aggression) and thank a different number of senders
  per session (sociability), each proven by a test.
- Every draw is deterministic per seed, so a replay reproduces the taste.
- The reactive save still fires under an inbound fleet regardless of aggression.
- The dark-period and presence guarantees of `RoutineCadenceTest` hold for the band-bounded cadence.

## Slices

- `DEF-028` — `PersonaTaste` + the aggression wiring + the divergence test.
- `DEF-029` — diligence into cadence (band-bounded), sociability into initiation, and the
  dark-period measurement fix that the band-bounded cadence needed.
- follow-up — aggression into unit composition and the scorer (safety-preserving), and the richer
  initiation surface once the host exposes inbound-probe and report-share events.
