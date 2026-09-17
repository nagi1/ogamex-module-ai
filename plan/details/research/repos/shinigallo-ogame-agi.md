## Overview

`Shinigallo/ogame-agi` is a single-author, 6-month-old, zero-star Python repo (96.5% Python, MIT) that describes itself as "Autonomous Game Intelligence for OGame — AI agent with Gemini 2.0 brain and strategic RAG system." It is a **demo/simulation-heavy project, not a working bot**: the real automation lives in `src/`, while the ~15 top-level `.py` files are narrative "deployment" scripts that mostly log fake rankings, hardcoded game state, and simulated progression. The README is in Italian and advertises two modes: **Full Autonomous** and **Smart Bot (event-driven, token-efficient)**.

Three layers, in increasing order of "realness":

1. **Top-level scripts** (`aggressive_bot.py`, `competitive_agi.py`, `fully_autonomous_agi.py`, `live_deployment.py`, `manual_live_campaign.py`, etc.) — mostly `self.logger.info(...)` + simulated numbers, no real game interaction beyond login.
2. **`src/` core** (`src/ogame_bot.py`, `src/smart_ogame_bot.py`, `src/automation/ogame_login.py`, `src/automation/ogame_resources.py`, `src/agents/gemini_brain.py`) — the only code that actually drives Playwright + Gemini.
3. **Placeholder/stub modules** (`src/vision/game_parser.py`, `src/strategy/strategic_planner.py`, `src/main.py`, `src/main_autonomous.py`) — classes exist but bodies are `TODO`/`return {}`/hardcoded values, and several imports name symbols that don't exist.

## Architecture & entry points

**Multiple competing entry points (no single source of truth):**

- `src/ogame_bot.py` — `OGameBot` (dataclasses `GameState`, `BotConfig`) — the "full autonomous bot." `main()` → `BotConfig.from_env()` → `OGameBot(config).run()`.
- `src/smart_ogame_bot.py` — `SmartOGameBot` (dataclasses `GameEvent`, `BotState`) — the "event-driven" bot. `main()` → `SmartOGameBot().run()`.
- `src/main.py` — `OGameAGI` controller; `main_loop()` is a `asyncio.sleep(10)` placeholder.
- `src/main_autonomous.py` — `AutonomousOGameAGI`; session loop with **hardcoded placeholder game state**.
- Top-level: `simple_bot.py` (monitor only), `aggressive_bot.py`, `competitive_agi.py`, `fully_autonomous_agi.py`, `live_deployment.py`, `simple_autonomous_demo.py`, `deploy_autonomous_agi.py`, `direct_autonomous_agi_launcher.py`, `manual_login.py`, `extract_session.py`.

**`src/` component graph:**

```
OGameBot / SmartOGameBot
   ├── OGameLogin          (src/automation/ogame_login.py)      → Playwright chromium
   ├── OGameResources      (src/automation/ogame_resources.py)  → resource parsing
   ├── GeminiBrain         (src/agents/gemini_brain.py)         → gemini-1.5-flash
   └── BUILDING/FLEET/RESEARCH_SELECTORS (src/automation/ogame_selectors.py)

main.py / main_autonomous.py (broken seams)
   ├── EnhancedGeminiBrain  (src/agents/enhanced_gemini_brain.py) → gemini-2.0-flash-exp
   ├── PlaywrightInterface  (src/automation/playwright_interface.py)
   ├── StrategicPlanner     (src/strategy/strategic_planner.py)   → all placeholders
   ├── GameParser           (src/vision/game_parser.py)           → all placeholders
   └── OGameRAG             (src/knowledge/rag_system.py)         → keyword-only "RAG"
```

**Broken seams (import/runtime errors, not dead code):**
- `src/main_autonomous.py` imports `from knowledge.rag_system import RAGSystem` — the module defines `OGameRAG`, **no `RAGSystem` class exists** → `ImportError` at startup.
- `src/main_autonomous.py` calls `self.rag.retrieve_strategies(...)` and `self.brain.analyze_game_state(...)`; `OGameRAG` only has `retrieve_relevant_strategies`, and `EnhancedGeminiBrain` has no `analyze_game_state` (it has `analyze_strategic_situation`).
- `src/strategy/strategic_planner.py` calls `self.brain.analyze_game_state(game_state)` — same missing method.
- `src/smart_ogame_bot.py` does `from tools import cron` inside methods — `tools` is an OpenClaw module absent from this repo, so cron scheduling always raises (caught and logged).
- `src/knowledge/__init__.py` is a one-line placeholder; `gemini_brain.py` loads `knowledge/buildings.md` etc. relative to CWD, but no such top-level `knowledge/` directory exists → always falls back to `_get_basic_knowledge()`.

## Scheduling & loop model

**Full autonomous (`src/ogame_bot.py`):** fixed-interval poll-and-act.
```
BotConfig.from_env(): cycle_interval = DECISION_INTERVAL (default 300s),
max_cycles = MAX_CYCLES (default 0 = infinite)
run(): initialize() → while running: run_cycle() → sleep(cycle_interval)
run_cycle(): get_game_state() → save_state() → make_decision() → execute_actions()
execute_actions(): per action → sleep(random.randint(2, 8))  # anti-detection
```

**Smart bot (`src/smart_ogame_bot.py`):** polling every `QUICK_CHECK_INTERVAL` (default 60s) with rule-based gating. The "cron" scheduling is aspirational — the actual loop is still `while True: run_smart_cycle(); await asyncio.sleep(check_interval)`.
```
run_smart_cycle(): quick_check() → schedule_smart_actions(events) → save → cleanup_session()
quick_check(): _check_building_timers + _check_research_timers + _check_fleet_timers + _check_resource_alerts
schedule_smart_actions(): action_time = event.trigger_time - 2min; immediate vs schedule_cron_action()
```

**Autonomous AGI (`src/main_autonomous.py`):** `session_duration = 7200s` (2h), `decision_interval = 300s` (5min), `max_actions_per_cycle = 3` (capped to 2 in conservative mode). Loop breaks when `elapsed >= session_duration`; on error sleeps 60s.

**Aggressive (`aggressive_bot.py`):** `SESSION_DURATION` default 28800s (8h), `QUICK_CHECK_INTERVAL` 60s.

**Demo/simulated:** `competitive_agi.py` uses `asyncio.sleep(2)` between fake phases; `fully_autonomous_agi.py` hardcodes `await asyncio.sleep(300)` per cycle and a 30-day duration.

There is **no true event-driven push**; "event-driven" = polling timers on a short interval and deciding rule-based vs. AI per event. README claims "AI calls every 30min / 90% token reduction," but `run_smart_cycle` never actually calls `brain` at all — AI is only reached via `schedule_ai_decision` → cron, which fails on the missing `tools` module.

## Decision engine & algorithms

**`GeminiBrain` (`src/agents/gemini_brain.py`):**
- `__init__(api_key, model="gemini-1.5-flash")`; loads knowledge into a string.
- `_create_decision_prompt(context)` — system-style prompt with knowledge base, resource state, strategy, risk tolerance, cycle, auto_fleetsave, and 6 numbered "DECISION RULES" (prioritize production, balance 3:2:1, build fleet, research, fleetsave if `auto_fleetsave=true`, avoid risk if `risk_tolerance=low`). Output contract: JSON array of `{type, target, priority, reason}` / `{type:"fleet_dispatch", mission, target, ships, priority, reason}`.
- `_parse_decisions` strips ``` code fences, `json.loads`, validates.
- `_validate_decision` — requires `type` and `priority`; valid types `['build','research','fleet_dispatch','wait']`.
- `_get_fallback_decisions` — `metal < 1000` → build `metal_mine`; `crystal < 500` → build `crystal_mine`; else `wait` 300s.

**`EnhancedGeminiBrain` (`src/agents/enhanced_gemini_brain.py`):** model `gemini-2.0-flash-exp`, long "elite OGame strategist" system prompt. `analyze_strategic_situation` → RAG retrieval → structured JSON prompt (immediate_priorities, strategic_plan next_hour/day/week, risk_assessment, resource_allocation, tactical_recommendations, success_metrics). `_calculate_confidence` and `_get_fallback_analysis` (safety-first: fleetsave).

**"Strategic RAG" (`src/knowledge/rag_system.py`) — not real RAG.** No embeddings (`sentence_transformers` and numpy are commented out; `TODO: Initialize embedding model`). Retrieval is **pure keyword scoring**:
- `categories` priority map: `fleetsaving:10, combat:9, resource_management:8, research:7, fleet_composition:6, expeditions:5, general_strategy:4`.
- `_calculate_relevance_score`: title substring `+10.0`, each query word in content `+2.0`, tag match `+3.0`, `fleet_at_risk`+`safety` tag `+5.0`, `low_resources`+`profit` tag `+4.0`, game-phase match `+2.0`.
- Sort `(score, priority)` desc, cap `max_results=5`; `get_strategic_advice` takes top 3, truncates content to `[:200]`.

**`StrategicPlanner` (`src/strategy/strategic_planner.py`):** `Priority` enum LOW=1..CRITICAL=4. `_generate_goals` produces goals with hardcoded `resources_required` (`{'metal':50000,'crystal':25000}`, `{'metal':100000,...}`) and `estimated_time` 120/180/240 min. `_needs_resource_boost/_needs_fleet_expansion/_needs_research` all `return True` (placeholders). `_prioritize_goals` sorts by `(priority.value, len(prerequisites))` reverse.

**Knowledge base content** (`docs/strategic_knowledge_base.md`): fleetsaving methods (harvest/deploy/colonization/expedition/attack), miner/fleeter/turtle styles, 2:1:1 mine ratio, 6-round combat, 30% debris, expedition ~200-300 ships, "256 Large Cargo + 1 Destroyer", 1-hour expedition duration, research path, moon development (20% chance @1.5M debris).

## Data model & persistence

- `src/ogame_bot.py`: `GameState` (timestamp, resources, buildings, research, fleet, planets) → `asdict`. `save_state` writes `data/current_state.json` + appends `data/state_history.jsonl`.
- `src/smart_ogame_bot.py`: `GameEvent` (event_type, trigger_time, data, processed) and `BotState` (last_login, last_check, pending_events, session_active, next_action_time). Persisted to `data/bot_state.json` via `_save_state` (`asdict(self.state)`).
- `src/automation/ogame_login.py`: session cookies → `data/session.json` (`save_session`/`load_session`; rejects on username mismatch).
- `src/automation/ogame_resources.py`: `get_resources()` returns `{metal, crystal, deuterium, energy, dark_matter}` ints; `get_resources_detailed()` adds formatted strings; `_parse_number` strips everything but digits/sign, collapses `.`/`,` thousands separators; `wait_for_resources_update` polls every 2s up to 10s.
- No database anywhere; flat JSON files only.

## Config surface

`.env.example` keys (with code defaults where they diverge):

| Key | Default | Consumer |
|---|---|---|
| `GEMINI_API_KEY` | — (required) | `GeminiBrain`, `EnhancedGeminiBrain` |
| `OGAME_USERNAME/PASSWORD/EMAIL` | — | login |
| `OGAME_UNIVERSE_URL` | — | login; `main_autonomous.py` falls back to `https://s161-en.ogame.gameforge.com` |
| `OGAME_UNIVERSE` | — | unused in code |
| `STRATEGIC_MODE` | `balanced` (conservative/balanced/aggressive) | `ogame_bot`, `smart` |
| `DECISION_INTERVAL` | 300 | `ogame_bot.cycle_interval` |
| `SESSION_DURATION` | 7200 / 28800 | varies per script |
| `MAX_ACTIONS_PER_CYCLE` | 3 | `.env` only (hardcoded in `main_autonomous`) |
| `QUICK_CHECK_INTERVAL` | 60 | `smart_ogame_bot`, `aggressive_bot`, `simple_bot` |
| `AI_DECISION_INTERVAL` | 1800 | `smart_ogame_bot.ai_interval` |
| `MAX_SESSION_TIME` | 3600 | `smart_ogame_bot` |
| `MAX_CYCLES` | 0 | `ogame_bot` |
| `HEADLESS` | true | `ogame_bot` |
| `AUTO_FLEETSAVE` | true | `ogame_bot` |
| `RISK_TOLERANCE` | low | `ogame_bot` |
| `METAL_THRESHOLD` | 50000 | `smart_ogame_bot` |
| `CRYSTAL_THRESHOLD` | 25000 | `smart_ogame_bot` |
| `DEUTERIUM_THRESHOLD` | 12500 | `smart_ogame_bot` |
| `DEBUG`, `LOG_LEVEL`, `ACTION_DELAY_MIN=2000`, `ACTION_DELAY_MAX=5000`, `RANDOMIZE_BEHAVIOR=true`, `STRATEGY_FOCUS`, `GAME_PHASE` | — | **declared in `.env` but not read by any code I found** |

## Edge cases & failure handling

- Broad `try/except` per cycle in every loop; failures are logged and the loop sleeps (60s) then retries.
- `SmartOGameBot.run()` catches `KeyboardInterrupt` and generic `Exception` separately.
- Login: `_fill_login_form` returns False if any of email/password/submit not found; `_select_universe` returns True even if selector missing ("Not a fatal error"); `is_logged_in()` falls back to URL heuristic (`'ogame.gameforge.com' in url and 'login' not in url`).
- Session expiry: `simple_bot`/`aggressive_bot` call `establish_session()` on `is_logged_in() == False`.
- Resource parse: returns 0 for missing values; `wait_for_resources_update` times out to `current`.
- Timer parse: `_parse_timer` returns `now + 5min` as default when format unrecognized; `_extract_fleet_return_time` assumes today, rolls to tomorrow if time already passed.
- AI failure: `GeminiBrain` → `_get_fallback_decisions`; `EnhancedGeminiBrain` → `_get_fallback_analysis` (fleetsave safety); `fully_autonomous_agi` CLI path → default `"upgrade_metal_mine"`.
- Health server: 503 if `data/current_state.json` missing or >10min stale; `/metrics` computes `resources_gained` from first/last line of `state_history.jsonl`.
- **Not handled:** the broken imports listed above are not guarded (crash at import, not runtime); the `tools.cron` ImportError is only logged; simulated scripts never actually validate game actions.

## Anti-detection & authenticity

- `src/automation/ogame_login.py` launches chromium with `--disable-blink-features=AutomationControlled`, `--no-sandbox`, `--disable-setuid-sandbox`, `--disable-dev-shm-usage`; context `viewport 1920x1080`, UA `Chrome/120 Windows`, `locale='en-US'`.
- `src/automation/playwright_interface.py`: same flags plus `--disable-web-security`, `--disable-features=VizDisplayCompositor`; UA `Chrome/120 Linux`; blocks `png/jpg/jpeg/gif/svg/css` via route abort (performance).
- `src/ogame_bot.py`: `delay = random.randint(2, 8)` between actions.
- `.env.example` declares `ACTION_DELAY_MIN=2000/MAX=5000`, `RANDOMIZE_BEHAVIOR=true` — **not implemented in code**.
- README claims: randomized timing, regular session breaks, human-like behavior, auto-fleetsave, `RISK_TOLERANCE=low`, combat disabled, max 1h session, auto-logout (`MAX_SESSION_TIME`).
- **Reality contradicts it:** `aggressive_bot.py` polls every 60s with "ULTRA-AGGRESSIVE MODE / DOMINATION" logging; `competitive_agi.py` / `live_deployment.py` log fabricated rankings ("#1 SERVER CHAMPION", "3247 players") as if real; hardcoded creds + key (see concerns). None of this is human-distinguishable.

## Discrete mechanisms

- M01 — Fixed-interval full-autonomous cycle — `OGameBot.run()`: collect→save→decide→execute→sleep(`cycle_interval`, default 300s from `DECISION_INTERVAL`) (`src/ogame_bot.py`).
- M02 — Cycle cap — stop when `max_cycles > 0 and cycle_count >= max_cycles`; default 0 = infinite (`src/ogame_bot.py`).
- M03 — Inter-action random delay — `delay = random.randint(2, 8)` seconds after each executed action (`src/ogame_bot.py`).
- M04 — Smart quick-check interval — `QUICK_CHECK_INTERVAL` default 60s; loop sleeps `check_interval` after each cycle (`src/smart_ogame_bot.py`).
- M05 — AI decision interval — `AI_DECISION_INTERVAL` default 1800s, stored but only used as a cron delay hint (`src/smart_ogame_bot.py`).
- M06 — Max session time — `MAX_SESSION_TIME` default 3600s; `cleanup_session()` logs out when `session_age > max_session_time` (`src/smart_ogame_bot.py`).
- M07 — Event polling — `quick_check()` = building + research + fleet timers + resource alerts (`src/smart_ogame_bot.py`).
- M08 — Resource-full thresholds — metal ≥ `METAL_THRESHOLD` 50000, crystal ≥ 25000, deuterium ≥ `DEUTERIUM_THRESHOLD` 12500 → `resources_full` event (`src/smart_ogame_bot.py`).
- M09 — Timer format detection — regex `\d+h\s*\d+m\s*\d+s`, `\d+:\d+:\d+`, `\d+d\s*\d+h`; unparsed → `now + 5min` (`src/smart_ogame_bot.py`).
- M10 — Fleet return time — regex `(\d{2}):(\d{2}):(\d{2})`, today-or-tomorrow assumption (`src/smart_ogame_bot.py`).
- M11 — Cron scheduling — `from tools import cron`, `action_time = event.trigger_time - timedelta(minutes=2)`, kind `"at"` (ISO + `Z`) (`src/smart_ogame_bot.py`).
- M12 — Rule-based quick spend — metal>50000 → `['metal_mine','light_fighter']`; crystal>25000 → `['crystal_mine','research_lab']`; deuterium>12000 → `['deuterium_synthesizer','heavy_fighter']` (`src/smart_ogame_bot.py`).
- M13 — AI fallback decisions — metal<1000 → build `metal_mine`; crystal<500 → build `crystal_mine`; else wait 300s (`src/agents/gemini_brain.py`).
- M14 — Decision validation — requires `type`,`priority`; valid types `build/research/fleet_dispatch/wait` (`src/agents/gemini_brain.py`).
- M15 — Gemini model selection — `GeminiBrain` default `gemini-1.5-flash`; `EnhancedGeminiBrain` hardcodes `gemini-2.0-flash-exp` (`src/agents/*.py`).
- M16 — Decision prompt — JSON-array output, rules: production first, 3:2:1 ratio, fleetsave if `auto_fleetsave=true`, no risk if `risk_tolerance=low` (`src/agents/gemini_brain.py`).
- M17 — RAG category priorities — fleetsaving=10, combat=9, resource_management=8, research=7, fleet_composition=6, expeditions=5, general_strategy=4 (`src/knowledge/rag_system.py`).
- M18 — RAG relevance scoring — title +10, word +2, tag +3, fleet_at_risk→safety +5, low_resources→profit +4, phase match +2; keyword-only, no embeddings (`src/knowledge/rag_system.py`).
- M19 — RAG truncation — retrieve max 5; advice top 3; content `[:200]` / prompt `[:300]` (`src/knowledge/rag_system.py`, `src/agents/enhanced_gemini_brain.py`).
- M20 — Game-phase classification — research_level <5 early, <15 mid, else late; `low_resources` if production_rate <1000; `resources_at_risk` if total >100000 (`src/agents/enhanced_gemini_brain.py`).
- M21 — Confidence formula — base 0.5; +0.2 (≥3 strategies), +0.1 (≥2), +0.2 (has resources/fleet/research keys), +0.1 (max priority ≥8); capped 1.0 (`src/agents/enhanced_gemini_brain.py`).
- M22 — Planner goals — all `_needs_*` return True; fixed `resources_required` (50000/25000 etc.) and `estimated_time` 120/180/240 min (`src/strategy/strategic_planner.py`).
- M23 — Goal priority sort — `(priority.value, len(prerequisites))` reverse (`src/strategy/strategic_planner.py`).
- M24 — Autonomous session limits — duration 7200s, interval 300s, max 3 actions/cycle (2 if conservative) (`src/main_autonomous.py`).
- M25 — Placeholder game state — hardcoded `{metal:1000, crystal:500, deuterium:100, energy:50}` (`src/main_autonomous.py`).
- M26 — Placeholder recommendations — hardcoded `upgrade_metal_mine` cost `{metal:150,crystal:38}`, `upgrade_solar_plant` `{metal:225,crystal:90}` (`src/main_autonomous.py`).
- M27 — Aggressive build queue — MetalMine 10, CrystalMine 8, DeuteriumSynthesizer 6, SolarPlant 12, MetalMine 15, RoboticsFactory 2, Shipyard 2, CrystalMine 12 (`aggressive_bot.py`).
- M28 — Aggressive research queue — EspionageTechnology 2, ComputerTechnology 2, WeaponsTechnology 3, ShieldingTechnology 2, EnergyTechnology 3, CombustionDrive 3 (`aggressive_bot.py`).
- M29 — Aggressive spend/fleetsave thresholds — act if `total_resources > 1000`; fleetsave if `total_resources > 10000`; build queue full at ≥2 (`aggressive_bot.py`).
- M30 — Aggressive session — `SESSION_DURATION` 28800s (8h), check every 60s (`aggressive_bot.py`).
- M31 — Competitive attack threshold — `attack_threshold = 0.7` (70% win probability) (`competitive_agi.py`).
- M32 — Competitive phase targets — economic 7d / fleet 14d; metal_mine 15, crystal_mine 12, deuterium 8, 5 colonies, 1000 LF, 500 LC, 100 destroyers, 50 battleships, 5 deathstars (`competitive_agi.py`).
- M33 — Fleet power formula — `LF*4 + destroyers*2000 + battleships*7000 + deathstars*200000` (`competitive_agi.py`).
- M34 — CLI Gemini decision — `subprocess.run(['gemini','-p',prompt], timeout=30)`; 5 fixed choices; default `"upgrade_metal_mine"` (`fully_autonomous_agi.py`).
- M35 — Session persistence — cookies → `data/session.json`; restore only on matching username (`src/automation/ogame_login.py`).
- M36 — Resource parsing — try NEW then OLD selectors; `_parse_number` strips non-digits, collapses `,`/`.` (`src/automation/ogame_resources.py`).
- M37 — Health endpoints — `/health` 503 if state missing or >10min old; `/metrics` = `last - first` resources from `state_history.jsonl` (`src/monitoring/health_server.py`).
- M38 — Stealth browser flags — `--disable-blink-features=AutomationControlled`, `--no-sandbox`, `--disable-dev-shm-usage`, UA Chrome/120, 1920x1080 (`src/automation/ogame_login.py`, `playwright_interface.py`).
- M39 — Hardcoded building/research IDs — `#building1..#building4`, `[data-building="..."]`, `[data-tech="106/108/109/110/115"]`, universe selectors `Scorpius`/`s161` (`src/automation/ogame_selectors.py`).
- M40 — Fallback knowledge (no files) — metal mine 1-10, crystal 1-8, deu 1-6, solar as-needed, shipyard 4+, research lab 1+; research energy 1-3, combustion 1-6, armor/weapons/shields 1-5; 3:2:1 ratio (`src/agents/gemini_brain.py`).

## Notable concerns

1. **Leaked secrets in source.** `fully_autonomous_agi.py` and `live_deployment.py` hardcode a Gemini API key (`AIzaSyCzMRF0wwVGLuuhxmdpgSJpa9pyxPDsR2Q`) and account credentials (`TestAgent2026` / `TestAGI2026!` / `TestAgent2026@yopmail.com`, universe Scorpius `s161-en.ogame.gameforge.com`). Security hazard and a hardcoded-identity problem.
2. **Broken seams make the "AGI" entry points unrunnable.** `RAGSystem` import, `retrieve_strategies`, and `brain.analyze_game_state` don't exist; `tools.cron` is external. The only code that could plausibly run is `src/ogame_bot.py`, `src/smart_ogame_bot.py`, `src/automation/*`, and `src/agents/gemini_brain.py`.
3. **Fake/aspirational outputs.** `competitive_agi.py`, `live_deployment.py`, `main_autonomous.py` log fabricated rankings, simulated resource growth (`metal *= 2.5`), and hardcoded game state while presenting them as live results.
4. **"Strategic RAG" is keyword search.** No embeddings, no vector store, no generation-from-retrieval; documents are `docs/strategic_knowledge_base.md` split on `###` and scored by substring/tag hits.
5. **Gate-1 violations everywhere.** Hardcoded building/tech/universe names and IDs in `ogame_selectors.py`, hardcoded build/research queues in `aggressive_bot.py` and `competitive_agi.py`, hardcoded thresholds in `smart_ogame_bot.py` and `enhanced_gemini_brain.py`. Adding a host object would require source edits.
6. **Gate-2 violations.** ~15 overlapping top-level bot scripts duplicating login/loop logic; `EnhancedGeminiBrain` vs `GeminiBrain`; `PlaywrightInterface` vs `OGameLogin` (both wrap Playwright); config keys declared but never read.
7. **Gate-3 violations.** Aggressive 60s polling, "domination" framing, and fabricated "human-like" claims; no realistic reaction latency, uptime shaping, or save-failure modeling. Anti-detection is 2–8s random sleeps + a disabled automation flag only.
8. **No tests of behavior.** `tests/test_playwright_fase1.py` is the only test (login + resource extraction); no test coverage of decisions, RAG, or loop logic. `GameParser` CV is fully stubbed.
9. **Data-integrity limits.** Flat JSON files only; `/metrics` relies on `state_history.jsonl` that is only appended by `ogame_bot.py`.

## Confidence

**High** on file inventory, entry points, class/function names, constants, thresholds, prompt text, and the broken seams — all read directly from the repo source via raw fetches and keyword search (README, `.env.example`, `src/` modules, and top-level scripts).

**Medium** on exact line numbers and on the presence/absence of a top-level `knowledge/*.md` set (keyword search found only references in `gemini_brain.py`, strongly implying the files are absent and the inline fallback is used, but I did not list the repo's full `knowledge/` directory contents).

**Low/None** on runtime behavior — the project ships no executed results; the "live" scripts are simulations, so any claim about actual in-game performance is unverifiable from the repository alone.