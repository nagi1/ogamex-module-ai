# WIK-097 — Warrior

- source: `plan/research/ogame/raw/wiki/ogame.fandom.com/combat/WIK-097.md`
- provenance: DOCUMENTED
- confidence: medium
- module opinion: none — nothing may be encoded into alliance decisions
- guard: `tests/Unit/Wik097WarriorClaimTest.php`

## Claim

The wiki documents a Warrior role in the Alliance Combat System. The bundle's
section 2 states no numbers and section 4 shows the module has no opinion on
WIK-097, so nothing may be encoded into alliance decisions: no Warrior
constant, no per-account Warrior attribute, no config key and no class-based
branch. The entry is community documented at medium confidence — the host has
to confirm the mechanics before any of this becomes code.

## Numbers

## Open questions

- Does the raw wiki file state any numbers, where the bundle's section 2 states none?
- Is "Warrior" a player class in the host's OGame version, or only an alliance-combat role?
- Which host contact confirms the Alliance Combat System mechanics WIK-097 leans on?

## Risks

- The bundle's section 2 is empty, so nothing here can be validated against the wiki text.
- This guard forbids Warrior symbols and must be relaxed deliberately once the host confirms real mechanics.
- A spec-only change adds no gameplay behaviour, so it can rot unnoticed.

## Guard

`tests/Unit/Wik097WarriorClaimTest.php` pins this spec. It fails if the numeric
section above gains content, and it fails if a per-account Warrior constant or
an object-universe class enumeration naming Warrior appears under the
application's `app/` or `config/` trees — the repository's own trees and the AI
module's.
