# Behaviour resources

Behaviour YAML will live here: per-account personality and policy that the module loads at runtime.
This directory is scaffolded only — no behaviour values exist yet.

## What belongs here

Declarative behaviour resources whose values are derived from the research pipeline, not invented
here. Each value cites the stable source id it came from
(`plan/research/ogame/SOURCE-REGISTRY.yaml` or `plan/details/research/strategy/sources.yaml`); a
value whose id does not resolve is not accepted.

## What does not belong here

- Object ids, prices, requirements — gate 1: the object universe is read from the host at planning
  time, never encoded as a source of truth.
- Anything a task from `plan/tasks/tasks.db` does not authorize. Research alone never changes
  gameplay behaviour.

## Pipeline

`plan/research/ogame/queries/` → `raw/` → `synthesis/` → this directory. See
`plan/research/ogame/README.md` for the provenance rules the pipeline enforces.
