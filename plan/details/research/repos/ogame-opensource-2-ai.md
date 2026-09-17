## Overview

`AntonBeletskyForks/ogame-opensource-2` is a fork (116 commits behind `ogamespec/ogame-opensource`) of a revived OGame v0.84 PHP server. Its built-in "AI" is a **graph-interpreter bot system**: a player account whose actions are driven by a chain of "strategy" blocks stored as JSON in a database table (`botstrat`), compiled at runtime into queue tasks of type `QTYP_AI`, and executed one block at a time by `eval()`-ing the block's text. It is not a planner, a state machine in code, or an ML agent — there is **no decision logic written in PHP**. All "intelligence" lives in the strategy graphs, which are authored in a GoJS-based visual editor in the admin panel and are explicitly **not shipped** with the repository.

The wiki (`wiki/en/ai.md`) states the current capability plainly: *"At the moment bots are able to evolve up to small cargo and after its construction 'hibernate'."* That behavior is **described, not implemented in code** — the installer seeds only one empty `backup` strategy row. There is no `_start` strategy, no small-cargo rule, and no hibernate function anywhere in PHP.

Key source files (all under `game/`):

- `core/bot.php` — the block interpreter and bot lifecycle.
- `core/botapi.php` — the small set of `Bot*` built-in functions a strategy may call.
- `core/queue.php` — the global event queue; `UpdateQueue()` dispatches `QTYP_AI`.
- `core/defs.php` — `QTYP_AI`, `QUEUE_PRIO_BOT` and other constants.
- `core/install_tabs.php` — `botstrat` and `botvars` table schemas.
- `install.php` — seeds `INSERT INTO botstrat VALUES (1, 'backup', '')`.
- `pages_admin/admin_bots.php` — admin "Bot Controls" (add/stop).
- `pages_admin/admin_botedit.php` — the GoJS graphical strategy editor (import/export/new/rename/save).
- `pages_admin/admin_users.php` — per-user `bot_start` action.
- `core/user.php` — `CreateUser(..., bool $bot=false)`.
- `wiki/en/ai.md` — the only documentation of intended behavior.

---

## Architecture & entry points

The system is three layers:

1. **Strategy (template)** — a row in `botstrat` (`id`, `name`, `source` where `source` is a GoJS `go.GraphLinksModel` JSON with `nodeDataArray` and `linkDataArray`).
2. **Compilation** — `BotExec()` / `AddBotQueue()` turn graph nodes into `queue` rows of `type = 'AI'`.
3. **Execution** — `UpdateQueue()` (in `core/queue.php`) finds expired AI tasks and calls `Queue_Bot_End()`, which finds the block in the strategy JSON and calls `ExecuteBlock()`.

Entry points:

- **Admin creates a bot** — `pages_admin/admin_bots.php` POST: `if (BotStrategyExists("_start")) AddBot($_POST['name'])`. A bot is just a normal `users` row created by `CreateUser($name, $pass, '', true)`.
- **Admin starts/stops** — `pages_admin/admin_users.php` `action === "bot_start"`; stop via `admin_bots.php` `?id=…` → `StopBot($id)`.
- **Strategy editor** — `pages_admin/admin_botedit.php` (GoJS, JSON import/export/backup to `botstrat.id = 1`).

`game/core/bot.php` globals:

```php
$BotID = 0;   // current bot's player_id
$BotNow = 0;  // start time of the current bot task execution
```

Core function `AddBotQueue` (`core/bot.php`):

```php
function AddBotQueue (int $player_id, int $strat_id, int $block_id, int $when, int $seconds) : int
{
    return AddQueue ($player_id, QTYP_AI, $strat_id, $block_id, 0, $when, $when+$seconds, QUEUE_PRIO_BOT);
}
```

`QTYP_AI` queue row semantics (from `core/defs.php`): `sub_id` = strategy id, `obj_id` = current block key.

---

## Scheduling & loop model

The AI rides on the **global event queue**, not on a timer thread.

- `core/queue.php` `UpdateQueue(int $until)` is invoked **only when a player navigates a page** (see `testing/PageRenderer.php`: `if ($update_queue) UpdateQueue($now);`). The README advertises a "CRON-less event queue"; bots therefore step only when someone (human or bot) generates a page hit.
- `UpdateQueue` returns immediately if `$GlobalUni['freeze']` (universe frozen → bots frozen too).
- It locks tables, then:

```php
$query = "SELECT * FROM ".$db_prefix."queue WHERE end <= $until AND freeze=0 ORDER BY end ASC, prio DESC LIMIT " . QUEUE_BATCH;
```

`QUEUE_BATCH = 16` (defined at the top of `core/queue.php`) caps work per request.

- Dispatch switch in `UpdateQueue`:

```php
case QTYP_AI: Queue_Bot_End ($queue); break;
```

- **Loop**: each block, when it completes, schedules the next block via `AddBotQueue(..., $BotNow, $sleep)`. The `$sleep` value is the integer returned by the block's PHP expression (e.g. a build duration in seconds). `End` blocks do `RemoveQueue` and schedule nothing — that is how a chain (and the bot) "hibernates".
- **Timing model**: `AddBotQueue` sets `start = $when`, `end = $when + $seconds`. A block with `$sleep == 0` is therefore due on the *next* queue sweep.
- `QUEUE_PRIO_BOT = 1000` — the **highest** priority in the game (`core/defs.php`), so AI tasks run before any other event that ends on the same second.

`Queue_Bot_End` (`core/bot.php`):

```php
$query = "SELECT * FROM ".$db_prefix."botstrat WHERE id = ".$queue['sub_id']." LIMIT 1";
...
$strat = json_decode ( $row['source'], true );
...
foreach ( $strat['nodeDataArray'] as $i=>$arr )
    if ( $arr['key'] == $queue['obj_id'] ) { ...collect linkDataArray children...; ExecuteBlock ($queue, $block, $childs); }
```

---

## Decision engine & algorithms

The whole decision engine is `ExecuteBlock()` in `core/bot.php`. Block categories:

**`Start`** — enqueue the first child and remove self:
```php
$block_id = $childs[0]['to'];
AddBotQueue ( $BotID, $strat_id, $block_id, $BotNow, 0 );
RemoveQueue ( $queue['task_id'] );
```

**`End`** — terminate the chain:
```php
RemoveQueue ( $queue['task_id'] );
```

**`Label`** — a "skewer"/chain header; follows the child whose `fromPort === "B"` (bottom), i.e. continues down the chain.

**`Branch`** — unconditional jump to another `Label` by matching `block['text']` against the strategy's `nodeDataArray` entries with `category === "Label"`.

**`Cond`** — the only "decision". The condition text is **eval'd PHP**:
```php
$result = eval ( "return ( " . $block['text'] . " );" );
```
Children are matched by their link text:
- `"yes"` → taken if `$result == true`
- `"no"` → taken if `$result == false`
- **random jump** `"[N]%"` — if `$result == true`, roll: `$roll = mt_rand(1, 100); if ($roll <= $prc) YES else NO`. The regex is `/([0-9]{1,2}|100)%/`, so 1–100 are legal; 100% = always YES.

If no branch matches, `$block_id` stays at the sentinel `0xdeadbeef`, a `Debug()` is emitted, and the task is removed (chain stops).

**default (regular action block)** — the block's text is **eval'd as a statement**, and its return value becomes the sleep:
```php
$sleep = eval ( $block['text'] . ";" );
if ( $sleep == NULL ) $sleep = 0;
$block_id = $childs[0]['to'];
AddBotQueue ( $BotID, $strat_id, $block_id, $BotNow, $sleep );
```

So an action block is typically a call like `BotBuild(GID_B_METAL_MINE)` which returns the build duration in seconds; the interpreter then waits that long before advancing. There are **no other algorithms** — no resource optimization, no threat evaluation, no priority scoring. Any such behavior must be encoded by the strategy author as eval'd expressions and `Cond` blocks.

The built-in callable surface (`core/botapi.php`) is the complete list of `Bot*` functions:

- `BotIdle()` — no-op (the "wait/do nothing" primitive).
- `BotStrategyExists(string $name)` / `BotExec(string $name)` — start another strategy in parallel.
- `BotGetVar($var, $def=null)` / `BotSetVar($var, $value)` — per-bot scratchpad (table `botvars`).
- `BotCanBuild(int $obj_id)` — true iff `CanBuild(...) === ''`.
- `BotBuild(int $obj_id)` — start a building; returns duration or 0:
  ```php
  $duration = floor (TechDuration ($obj_id, $level, PROD_BUILDING_DURATION_FACTOR, $aktplanet[GID_B_ROBOTS], $aktplanet[GID_B_NANITES], $speed));
  BuildEnque ($user, $user['aktplanet'], $obj_id, 0, $BotNow);
  ```
- `BotGetBuild(int $n)` — current building level on the active planet.
- `BotResourceSettings(...)` — set `prod1..prod6` percentages (clamped 0–100, rounded to multiples of 10, written as `round($x/10)*10/100`).
- `BotEnergyAbove(int $energy)` — compare `$aktplanet['e']`.
- `BotBuildFleet(int $obj_id, int $n)` — `AddShipyard(...)` + `AddQueue(..., QTYP_SHIPYARD, ...)`; duration via `PROD_SHIPYARD_DURATION_FACTOR`.
- `BotGetResearch(int $n)`, `BotCanResearch(int $obj_id)`, `BotResearch(int $obj_id)` — `StartResearch(...)`; technocrat multiplies speed by 1.1.

**Crucially**, nothing restricts a block's text to these helpers. Because `eval()` runs arbitrary PHP in global scope, a strategy may call **any engine function directly** (fleet dispatch, phalanx, rename, vacation, galaxy view, IPM). That is the only way the wiki's full capability list ("send fleets and fleetsave", "launch IPMs", "rename planets", "phalanx", "gates", "change name", "vacation mode") is realizable — none of those have `Bot*` wrappers.

---

## Data model & persistence

Table schemas (`core/install_tabs.php`):

```php
$tab_botstrat = array (
    'id'=>'INT AUTO_INCREMENT PRIMARY KEY', 'name'=>'TEXT', 'source'=>'TEXT',
);
$tab_botvars = array (
    'id'=>'INT AUTO_INCREMENT PRIMARY KEY', 'owner_id'=>'INT', 'var'=>'TEXT', 'value'=>'TEXT'
);
```

The `queue` table (shared with the whole game):

```php
$tab_queue = array (
    'task_id'=>'INT AUTO_INCREMENT PRIMARY KEY', 'owner_id'=>'INT', 'type'=>'CHAR(20)',
    'sub_id'=>'INT', 'obj_id'=>'INT', 'level'=>'INT', 'start'=>'INT UNSIGNED',
    'end'=>'INT UNSIGNED', 'prio'=>'INT', 'freeze'=>'INT DEFAULT 0', 'frozen'=>'INT UNSIGNED DEFAULT 0'
);
```

- For `type = 'AI'`: `owner_id` = bot player id, `sub_id` = `botstrat.id`, `obj_id` = block `key` in the JSON.
- `BotID`/`BotNow` are globals, set inside `ExecuteBlock` from the queue row:
  ```php
  $BotNow = $queue['end'];
  $BotID = $queue['owner_id'];
  ```
- Bot identity is **not a column** — `IsBot()` is defined by the existence of an AI queue row:
  ```php
  function IsBot (int $player_id) : bool {
      $query = "SELECT * FROM ".$db_prefix."queue WHERE type = 'AI' AND owner_id = $player_id";
      return ( dbrows ($result) > 0 );
  }
  ```
- Bot passwords are stored in `botvars` (`SetVar($player_id, 'password', $pass)`), and the bot's account is force-validated: `UPDATE users SET validatemd = '', validated = 1 WHERE player_id = …`.

---

## Config surface

There is **no configuration file** for the AI and no env vars. The entire configuration surface is:

- **Strategy rows** in `botstrat` (name + GoJS JSON `source`). These are the only "program". The default `textarea` seed in `admin_botedit.php` and the `new` action produce an empty graph:
  ```php
  $source = "{ \"class\": \"go.GraphLinksModel\", \"linkFromPortIdProperty\": \"fromPort\", \"linkToPortIdProperty\": \"toPort\", \"nodeDataArray\": [ ], \"linkDataArray\": [ ]}";
  ```
- **Per-bot variables** in `botvars` (key/value strings).
- **Admin panel switches**: add bot by name, stop bot by id, edit/rename/save/load/new/import/export/preview strategies (`admin_bots.php`, `admin_botedit.php`).
- **Universe freeze** (`uni.freeze`) — globally pauses queue (and thus bots).
- Constants that affect bot action durations live in the engine, not the AI: `PROD_BUILDING_DURATION_FACTOR = 2500`, `PROD_SHIPYARD_DURATION_FACTOR = 2500`, `PROD_RESEARCH_DURATION_FACTOR = 1000` (`core/defs.php`).

The installer seeds exactly one strategy (`game/install.php`):

```php
dbquery ( "INSERT INTO ".$db_prefix."botstrat VALUES ( 1, 'backup', '')" );
```

There is no `_start` strategy, so `AddBot` is a no-op until an admin imports/creates one (the admin page checks `BotStrategyExists("_start")` first).

---

## Edge cases & failure handling

- **Missing `_start` strategy** — `StartBot()` logs `Debug ("Starting strategy not found.")`; the bot account exists but has zero AI tasks, so `IsBot()` returns false.
- **Conditional dead-end** — `Cond` with no matching `yes`/`no`/`N%` child leaves `$block_id = 0xdeadbeef`; it `Debug`s "Failed to choose conditional branch." and removes the task → chain halts silently.
- **Branch to unknown label** — `Debug ("Unable to find branch label \"…\"")`, task removed → halt.
- **Failed action = 0 sleep** — `BotBuild`/`BotResearch`/`BotBuildFleet` return `0` when `CanBuild`/`CanResearch`/`AddShipyard` fail. A strategy that loops "until built" then re-schedules with `$sleep = 0`, i.e. it re-runs on the next page hit. Nothing throttles this; a naive strategy can spin the interpreter every sweep.
- **`eval()` of arbitrary PHP** — no try/catch, no sandbox. A syntax error or undefined call in a block's text raises a PHP error mid-request, leaving the AI queue row in place (it will be retried/errored again on the next sweep). The editor is admin-only, but the mechanism is an RCE-equivalent by design.
- **Universe freeze** — `UpdateQueue()` returns early when `$GlobalUni['freeze']`, so bot chains pause with the rest of the game.
- **Locking** — `UpdateQueue()` runs inside `LockTables()`; `botstrat` and `botvars` are in the locked table list (`core/db_mysql.php` / `db_sqlite.php`).
- **Hibernation vs. identity** — a bot whose chain ends has no `type='AI'` rows and therefore `IsBot()` returns false. `Queue_CleanPlayers_End` (`core/queue.php`) deletes 35-day-inactive accounts but skips bots via `if (!IsBot(...))`. A hibernated bot is thus **not protected** from the inactivity purge, contrary to the wiki's "always present" claim — unless its strategy keeps an AI task (or its `lastclick` is refreshed) alive.
- **Relogin/activity simulation** — the wiki says after re-login a "Make activity on the main page" variable is set and the bot artificially creates login activity; this is not implemented as a `Bot*` function and is only reachable through strategy eval of engine calls.

---

## Anti-detection & authenticity

The wiki's stated design intent (`wiki/en/ai.md`):

- *"Externally bots do not differ from the players… bots do not 'draw' anything for themselves, and honestly achieve everything by the same means as normal players."*
- Bots are real `users` rows, created through `CreateUser(..., true)` (the `$bot` flag only skips welcome emails/cookie language handling), then `validated = 1`.
- All actions go through the ordinary engine paths — `BuildEnque`, `AddShipyard`, `StartResearch` — which themselves call `UpdatePlanetActivity($planet_id, $BotNow)`. So building/fleet actions light activity stars exactly like a human.
- *"Bot intelligence is classified information"* — strategy graphs are DB-stored and not shipped, to prevent players predicting bot behavior.
- Bots never reply to private messages (wiki admits this, while noting some humans don't either).

Honest assessment of the gaps (relevant to OGameX gate 3):

- **Always-on presence** — the wiki concedes bots have "no analog of logging into the game"; they exist purely as queue events. Uptime is constant unless a strategy explicitly simulates logins.
- **Zero-reaction loops** — `$sleep = 0` on failure means actions can be effectively instantaneous and deterministic; nothing models human reaction latency.
- **Determinism** — the only randomness is the optional `N%` jump on a `Cond` block; otherwise a strategy is a fixed, periodic schedule.
- **No timing/session shaping** — no daily uptime curve, no idle periods, no fleetsave-may-fail behavior (beyond what a strategy author writes by hand).

---

## Discrete mechanisms

- **M01 — Bot creation** — `AddBot($name)` generates a trivial password, calls `CreateUser($name,$pass,'',true)`, force-validates the account, then `StartBot()` (`game/core/bot.php`).
- **M02 — Bot identity** — `IsBot($player_id)` is true iff a `queue` row with `type='AI' AND owner_id=$player_id` exists (`game/core/bot.php`).
- **M03 — Start strategy** — `StartBot()` sets `$BotID/$BotNow` and runs `BotExec("_start")` (`game/core/bot.php`).
- **M04 — Strategy compile** — `BotExec($name)` finds the `botstrat` row by name, decodes JSON `source`, locates `nodeDataArray` entry with `category === "Start"`, and queues its `key` (`game/core/botapi.php`).
- **M05 — AI queue task** — `AddBotQueue()` = `AddQueue(..., QTYP_AI, strat_id, block_id, 0, when, when+seconds, QUEUE_PRIO_BOT)` (`game/core/bot.php`).
- **M06 — Queue dispatch** — `UpdateQueue()` processes `end <= $until AND freeze=0 ORDER BY end ASC, prio DESC LIMIT QUEUE_BATCH(16)`; `case QTYP_AI: Queue_Bot_End($queue)` (`game/core/queue.php`).
- **M07 — AI priority** — `QUEUE_PRIO_BOT = 1000`, the highest queue priority (`game/core/defs.php`).
- **M08 — Block lookup** — `Queue_Bot_End()` re-reads the strategy JSON and matches `nodeDataArray[].key == $queue['obj_id']`, collecting `linkDataArray` children where `from == block key` (`game/core/bot.php`).
- **M09 — Start block** — enqueue `$childs[0]['to']` with 0 delay; remove self (`game/core/bot.php`).
- **M10 — End block / hibernate** — `RemoveQueue(task_id)` with no successor → no AI task remains, chain (and `IsBot`) ends (`game/core/bot.php`).
- **M11 — Label block** — follow the child whose `fromPort === "B"` (bottom port) (`game/core/bot.php`).
- **M12 — Branch block** — jump to a `Label` whose `text` equals `$block['text']` by scanning the strategy JSON (`game/core/bot.php`).
- **M13 — Condition block** — `eval("return (".$block['text'].");")`; children dispatched by link text (`game/core/bot.php`).
- **M14 — Probabilistic jump** — link text `[N]%`: `$roll = mt_rand(1,100); if ($roll <= $prc) YES else NO`; regex `/([0-9]{1,2}|100)%/` (`game/core/bot.php`).
- **M15 — Action block** — `$sleep = eval($block['text'].";")` (NULL→0); enqueue single child with `$sleep` seconds (`game/core/bot.php`).
- **M16 — Idle primitive** — `BotIdle()` does nothing (0-cost wait) (`game/core/botapi.php`).
- **M17 — Parallel strategy** — `BotExec($name)` can launch additional strategies concurrently with the running one (`game/core/botapi.php`).
- **M18 — Bot variables** — `GetVar`/`SetVar` upsert rows in `botvars` keyed by `owner_id + var`; `BotGetVar`/`BotSetVar` are the strategy-facing wrappers (`game/core/bot.php`, `game/core/botapi.php`).
- **M19 — Build check** — `BotCanBuild($obj_id)` returns `CanBuild($user,$planet,$obj_id,$level,false) === ''` (`game/core/botapi.php`).
- **M20 — Build action** — `BotBuild($obj_id)` computes `TechDuration(..., PROD_BUILDING_DURATION_FACTOR=2500, robots, nanites, speed)`, calls `BuildEnque`, returns duration or 0 (`game/core/botapi.php`).
- **M21 — Resource settings** — `BotResourceSettings()` clamps each of 6 inputs to 0–100 and writes `round($x/10)*10/100` into `planets.prod1..prod6`, then `UpdatePlanetActivity` (`game/core/botapi.php`).
- **M22 — Energy check** — `BotEnergyAbove($energy)` returns `$aktplanet['e'] >= $energy` (`game/core/botapi.php`).
- **M23 — Fleet/defense build** — `BotBuildFleet($obj_id,$n)` → `AddShipyard()` then `AddQueue(..., QTYP_SHIPYARD, planet_id, obj_id, n, now, seconds)` (`game/core/botapi.php`).
- **M24 — Research check** — `BotCanResearch($obj_id)` returns `CanResearch(...) === ''` (`game/core/botapi.php`).
- **M25 — Research action** — `BotResearch($obj_id)` → `StartResearch`; duration `TechDuration(..., PROD_RESEARCH_DURATION_FACTOR=1000, reslab, 0, speed * (technocrat?1.1:1.0))` (`game/core/botapi.php`).
- **M26 — Stop bot** — `StopBot($player_id)` = `DELETE FROM queue WHERE type='AI' AND owner_id=$player_id` (`game/core/bot.php`).
- **M27 — Strategy persistence** — editor `save`/`import` back up the old source into `botstrat.id = 1` then overwrite the target row (`game/pages_admin/admin_botedit.php`).
- **M28 — Strategy seed** — installer inserts only `(1,'backup','')`; no `_start` is shipped (`game/install.php`).
- **M29 — Bot purge exemption** — `Queue_CleanPlayers_End` skips accounts where `IsBot()` is true during the 35-day inactivity purge (`game/core/queue.php`).

Notably absent as code: small-cargo evolution, hibernate logic, fleetsave, IPM, phalanx, rename, delete-colony, vacation, name-change, galaxy view, login simulation. These are wiki-listed capabilities achievable only via strategy eval of engine functions; the concrete "evolve to small cargo then hibernate" `_start` graph is not in the repository.

---

## Notable concerns

1. **`eval()` of unbounded PHP in every block** — the decision engine and action engine are raw `eval`. This is an intentional RCE surface restricted only by admin authorship, and it makes strategies impossible to sandbox, validate, or test independently.
2. **No shipped strategy** — the game ships with zero working AI. Bots cannot be added until an admin hand-imports a `_start` graph; the documented "small cargo then hibernate" behavior exists only as prose.
3. **Wiki/code mismatch** — the wiki's "What bots can do" list far exceeds the `Bot*` API. Many items are only possible via direct eval of engine internals, which the wiki does not explain.
4. **Bot = AI-queue-row** — identity is derived from the presence of AI tasks. A bot that finishes/hibernates is indistinguishable (to `IsBot`) from a human, and loses purge protection.
5. **Unbounded 0-delay re-entry** — action failures return `0`, which the interpreter treats as "advance immediately"; a poorly authored loop spins the queue on every page hit with no backoff.
6. **Schedule depends on human traffic** — no dedicated worker advances the queue; bots stall when the universe is quiet, and "always present" only holds while other players are clicking.
7. **Determinism & constant uptime** — apart from `N%` random jumps, behavior is periodic and latency-free; there is no human timing model, no session shaping, no deliberate failure of a fleetsave.

---

## Confidence

- **High** on the interpreter mechanics, queue integration, constants, table schemas, admin pages, and the installed seed — all read directly from `game/core/bot.php`, `botapi.php`, `queue.php`, `defs.php`, `install_tabs.php`, `install.php`, `pages_admin/admin_bots.php`, `pages_admin/admin_botedit.php`, and `wiki/en/ai.md`.
- **High** that the built-in `Bot*` API surface is exactly the 14 functions listed (the full `botapi.php` was read end-to-end; it terminates at `BotResearch`).
- **High** that no `_start`/small-cargo/hibernate logic exists in code — `hibernate` appears only in `wiki/en/ai.md`; the only `botstrat` seed is the empty `backup` row.
- **Medium** on some wiki claims vs. code (login simulation, fleetsave, IPM) — these are inferred as "possible via eval", not observed as shipped logic; the fork is 116 commits behind upstream `ogamespec/ogame-opensource`, so exact parity of the two snapshots was not re-verified file-by-file.

---

### Gate flags for OGameX

- **Gate 1 (no hardcoded AI)** — *mostly clean*: the AI has no object list of its own; reachability is delegated to the host's `CanBuild`/`CanResearch`/`TechPrice`/`TechMeetRequirement`, so adding a host object makes it usable with no AI edit. The hardcoding lives in the engine (`GID_*` constants, `$requirements`, `$UnitParam`), which is host data, not AI data.
- **Gate 2 (never over-engineered)** — *violated by design*: a full GoJS graphical programming environment + JSON-compile-to-queue is heavy machinery for a bot that "evolves to small cargo then hibernates". The *mechanism* (one interpreter, one sort key per block) is simple; the authoring tooling is not.
- **Gate 3 (human-like play)** — *weak*: constant virtual presence, zero-reaction loops, deterministic schedules, and no latency/session/failure modeling are all observable tells. Only the activity-star lighting and the honest use of the normal build/fleet paths match human behavior.