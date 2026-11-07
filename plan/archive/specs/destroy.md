# Destroy (moon destruction) — unconfirmed concept

Status: unconfirmed. The module implements nothing for it, on purpose.

## What the source gives

The community wiki describes the concept: a fleet arriving at a moon can, under some conditions,
destroy it. The section that would carry the mechanics states no numbers at all — no chance, no
cost, no limit on how often it may happen. Nothing in the bundle has been confirmed by the host.

## Why the module stays out of it

The AI module plays an account from data. Every decision it takes resolves to a value in
`resources/behavior`, and every action it can choose appears in its action catalogue
(`app/Actions` for the action classes, `app/Enums` for the decisions, stop reasons and
archetypes). With no numbers there is nothing to read and nothing to choose between, so:

- no destroy action is added to the catalogue,
- no constant is declared for it anywhere under `app`,
- no tunable is written into `resources/behavior`.

Inventing the numbers would make the account act on a guess the host has not confirmed, which is
exactly what `tests/Feature/Ai/DestroyMechanicsUnconfirmedTest.php` refuses.

## What would replace this

A host-confirmed source stating the numbers: which fleet is required, the chance the moon is
destroyed, any cap on attempts, and what the attempt costs. Then the numbers land in
`resources/behavior` and this note is replaced by a real spec. The guard test is deleted at that
point, never relaxed — it exists only while the concept is unconfirmed.

## For a modder

There is nothing to tune yet. Do not add a destroy entry to a behaviour data file or a destroy
action class to `app/Actions`; the guard test rejects both.
