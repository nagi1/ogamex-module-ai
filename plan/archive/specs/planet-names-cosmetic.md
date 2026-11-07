# Planet names are cosmetic to the AI

Status: spec note. No runtime behaviour changes, no data file, no scenario — this source states no
mechanic, so there is no decision for a scenario to expect.

## What the source says

The community wiki page on planet naming describes how a player labels a planet. It states no cost,
no threshold and no ratio, it names no AI behaviour, and no existing AI code takes a name as an
input. Section 4 of the bundle shows no AI code with an opinion on the subject.

## Rule

The AI never consumes a planet name. Every decision — what to build, when to raid, which target to
pick — is made from planet state: resources, buildings, fleet, research, coordinates. The label a
player gives a planet is cosmetic. Renaming a planet cannot change what the AI does.

## Enforcement

`tests/Feature/PlanetNameNotConsumedTest.php` scans the module's decision runtime and fails as soon
as a file reads a planet name: `getPlanetName(`, `->planetName` or `planet_name`.

## Boundary of the guard, and why

`SeedAiTestUniverseAction` writes names onto the fixture planets it creates. Writing a label onto a
test fixture is not reading a name as a decision input, so that one file is excluded from the scan.
Nothing else is excluded — a second exclusion means the rule above is no longer true and the source
must be re-read rather than the guard widened.

## Note on this plan's acceptance line

The acceptance line says the guard scans `app/Ai/**` and `app/Domain/**` and asserts that **no** file
there reads a planet-name field. Those two paths do not exist in this module (the runtime lives under
`Modules/AI/app/**`), and one file that does live there — `SeedAiTestUniverseAction` — reads a name
to label its fixtures. The guard therefore covers the runtime the AI actually decides in
(`Modules/AI/app/**`, minus universe seeding). Applied to the literal paths, the acceptance line is
unsatisfiable; it is satisfied here in the only reading that keeps a failing guard meaningful.

## If the host answers otherwise

The open question is whether the host AI ever takes a planet name as an input (target labelling,
report text, target selection). If it does, this spec and its guard are wrong: delete the guard and
record the real rule. Planet names would then be an input like any other and belong in a behaviour
data file under `resources/behavior/`, so a modder can change how the account plays without touching
PHP. Until the host confirms, there is no knob to turn here, because the AI has no naming behaviour.
