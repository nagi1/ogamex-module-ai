# Moon dependency tree

Fact-only record of the moon dependencies the community wiki documents. It exists so
the AI module can later evaluate moon behaviour without anyone reinventing the tree
from memory, and so that unverified moon numbers cannot slip in unnoticed.

## Status

- source: community wiki page on moons
- confidence: medium, community-documented only
- confirmed by the host: not yet
- quantities in this file: none permitted
- companion guard: the unit test beside this spec fails if any digit character
  appears below, which forces a deliberate edit before a moon number is committed

## Formation dependency

A moon is never built; it is granted. The moon depends on a planet being attacked
and on the attacking fleet crashing there. Until that event happens the moon does
not exist, so every other edge in this file is conditional on the formation edge.

## Building dependency edges

Each edge reads "left depends on right". Every edge is what the wiki documents as
the requirement for the moon installation to be available at all, with the size of
the requirement deliberately left out.

- lunar base depends on the moon
- robotics factory depends on the lunar base
- shipyard depends on the robotics factory
- sensor phalanx depends on the lunar base
- jump gate depends on the lunar base
- metal store depends on the lunar base
- crystal store depends on the lunar base
- deuterium tank depends on the lunar base

## The lunar base is the root

Every moon installation traces back to the lunar base, and the shipyard branch
traces back through the robotics factory. There is no second root, so the tree is
evaluated as a single chain from the lunar base outward rather than as a flat set
of independent requirements.

## Out of scope

Fleet, defence, technology and resource requirements are not part of this tree and
belong to their own pages. Technology prerequisites for the jump gate and the sensor
phalanx are documented elsewhere on the wiki and are intentionally not recorded here
until the host confirms them.
