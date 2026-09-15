# Review record — strategy mining kickoff (15 September 2026)

Cheap, bounded, machine-parsable record per the [improvement loop](../specs/improvement-loop.md). No
runtime cost: this pass read existing research and code only.

```json
{
  "window": "strategy-mining-kickoff",
  "date": "2026-09-15",
  "artifacts": {
    "plan": "specs/strategy-mining.md",
    "source_registry": "research/source-registry.md",
    "principles_catalog": "research/strategy-principles.md"
  },
  "counters": {
    "principles_total": 107,
    "principles_shipped": 21,
    "principles_partial": 7,
    "principles_researched": 76,
    "principles_deferred": 3,
    "principles_gap": 0,
    "counts_note": "final counts after the gap pass; the earlier 67/79/83 tallies undercounted",
    "host_mechanics_supported_but_unwired": ["phalanx", "moon", "jump_gate", "acs_attack", "acs_defend", "debris_recycle", "activity_timestamp", "recall"]
  },
  "findings": [
    "raid: activity risk, per-type intel decay, travel/slot cost, personality/relationship all absent; travel_cost placeholder 0.0",
    "spy: first-fit in id order, no target score",
    "fleetsave: reactive only (currentPlayerUnderAttack), first-other-planet, no proactive exposure",
    "units: fixed role order, cargo fixed at 1, no composition/counters",
    "economy: payback constants archetype-blind (skill_band + routine only)",
    "host supports phalanx/moon/jumpgate/ACS/debris/activity/recall but no module caller reaches them"
  ],
  "next": "implementation slices blocked on catalog review; U-series fleet composition is the next increment",
  "research_pass_2": {
    "date": "2026-09-15",
    "agents": 3,
    "new_principles": 25,
    "sources_recovered": ["ORG-008 Tactic 05a (Wayback)", "ORG-011 Tutorial 15 Moon (Wayback)", "WIK-004 Rapid_Fire (?action=raw)"],
    "corrections": ["same-planet relocation is NOT recallable; deploy between two own planets is (fixed CRASH-003)"],
    "contradictions_recorded": ["RAID-014 debris-in-profit vs module doctrine", "FS-007 buffer magnitude 10-20 vs 30-60 min"]
  },
  "research_pass_3": {
    "date": "2026-09-15",
    "agents": 3,
    "acs_principles": 12,
    "acs_anchor": "GF-003 (Gameforge alliance guide); ORG-009/010 Wayback captures failed, stay open",
    "architecture_mapping": "research/architecture-mapping.md — 43 researched principles mapped to code",
    "key_finding": "host supports phalanx/moon/jumpgate/debris/recycle/ACS/recall but zero module callers"
  },
  "research_pass_4": {
    "date": "2026-09-15",
    "classical_patterns": 23,
    "catalog": "research/classical-ai-patterns.md",
    "algorithm_blocks": ["SP7", "T6-T8", "N4-N5", "V6-V8", "F1-F6"],
    "blocks_status": "planned, blocked on catalog review",
    "hypotheses_hold": ["H1", "H2", "H3", "H5", "H6", "H7", "H9", "H10"],
    "hypotheses_plausible": ["H8"],
    "hypotheses_open": ["H4"]
  },
  "research_pass_5": {
    "date": "2026-09-15",
    "claims_catalog": "research/strategy-claims.md",
    "claim_types": 8,
    "contested_claims": 7,
    "principles_total_final": 83,
    "canonical_fold_back": ["specs/decision-policies.md", "WORK-PACKAGES.md"],
    "integration_gates": 10,
    "m6_m7_scope": "pattern-level; vanilla-vs-mod file diff deferred"
  },
  "research_pass_6": {
    "date": "2026-09-15",
    "agents": 3,
    "gap_domains_closed": ["ninja_baiting", "non_english_de_pl", "fleet_composition", "moon_economics", "colony_positioning", "expeditions"],
    "new_sources": 26,
    "new_principles": 24,
    "new_domains": ["ninja", "expeditions"],
    "principles_total_final": 107,
    "still_open": ["ORG-009/010 ACS tutorials", "board.fr guide library URLs", "ogamewiki.de"]
  }
}
```

The register additions from this pass are recorded in
[`GAP-REGISTER.md`](../GAP-REGISTER.md#wave-6--strategy-mining-gap-scan-15-september-2026).
