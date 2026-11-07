# Wave-8 re-measure on the committed build — 16 September 2026 (REV-003)

The midday read registered Wave 8 from the running containers, which mount the working tree. Four
rows were decision-core claims the committed build (`bf064ac` module + `30b50cef`/`d65ada1e` host)
was written to change. Each is re-checked here on a frozen clock — the same before/after the
implementing slice was written to satisfy — rather than re-read from the live grand universe, which
must stay untouched.

| Row | Re-measured on | Frozen-clock evidence | Verdict |
| --- | --- | --- | --- |
| W8-L1 (dispatch ceiling) | `DecisionEngineTest`, `BuildingChainReachabilityTest` | a spy and a colony are withheld when no fleet slot is free; a slot-bound account reaches the ceiling technology via the host's `getObjectByCalculationType(MAX_FLEET_SLOTS)` | **closed (IMPL-035 + R11)** |
| W8-L3 (fleeter plays like a miner) | `ScarcityResourceNeedTest` | a severe scarcity makes a mine outrank the ship habit (IMPL-025's bounded `resource_need` boost) | **closed (IMPL-025)** — the remaining "Raid 0" is L2 (raid starvation), not a ranking defect |
| W8-L4 (full warehouse refused a warehouse) | `BuildingChainReachabilityTest` | a full warehouse is spent, not grown (`spendSurplus` pass before the chain) | **closed (IMPL-036)** |
| W8-L5 (colonise outranks development) | `DecisionEngineTest` | a colony is withheld when the account cannot develop it even with a free slot (`colonize_eligible`) | **closed (IMPL-037)** |

All four evidence tests are green in the full suite (789 Pest tests / 2535 assertions, PCOV
100.00% 6431/6431). The un-materialised colony stats named in W8-L5 stay open as DISC-005, and the
raid-starvation half of W8-L2/L3 stays open as DISC-006.

The rows are corrected in [`GAP-REGISTER.md`](../GAP-REGISTER.md#wave-8--grand-test-live-play-read-16-september-2026).
