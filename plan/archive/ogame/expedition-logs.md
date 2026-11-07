# Expedition logs carry no AI doctrine

Source page: WIK-232 (community-maintained expedition-log page).

## What the source states

The page states no doctrine and no numbers: no threshold, no ratio, no cap, no cost and no
duration that an account could act on. It is community-maintained and unverified, so there is
no host-confirmed mechanic to cross-check it against.

## What that means for the module

Expeditions are a fleet mission the module already queues. Expedition logs are not read, not
stored and not reasoned about:

- no candidate action, candidate reason or work kind mentions expedition logs;
- no behaviour data file holds an expedition-log rule, because there is no rule to hold;
- no config key switches expedition-log handling on or off.

## What a modder would edit

Nothing yet. If a host-confirmed source later states expedition-log mechanics, the knob belongs
in the behaviour data files under resources/behavior, beside the other income-source rules, and
this note is replaced by a plan that names that file.
