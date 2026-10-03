# Doctrine

Written the way a veteran writes a guide (architecture diagnosis, section 4.1). Gate 1 as amended on
3 Oct 2026: these files may name host objects; code never does. An object the host does not have is
skipped, and every manager falls back to its generic derived rule once a list is done or for any object no
file names, so mods keep working.

- `managers.yaml`: how much each manager does per login (probes, raid waves, errands).
- `<archetype>.yaml`:
  - `opening`: ordered `[object, level]` steps the economy places first on every planet, "skip if done,
    wait if unaffordable" like an RTS build list. Research rows go to the lab. After the list, payback rules.
  - `research_path`: ordered `[technology, level]` the lab follows once the opening's research is done.
  - `fleet_template`: share of the war fleet per hull; the shipyard builds the hull furthest below its share.
  - `defence_template`: share of a planet's wall per defence; the wall order builds the furthest below.

`trader` reads `miner.yaml`, `casual` reads `hybrid.yaml` (the PERS-008 migration).
