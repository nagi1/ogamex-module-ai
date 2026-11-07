# Benchmarks used by plan/rl

Investigation tooling, not module code. Nothing here is autoloaded or wired into the module.

## Environment

1. A scratch copy of the host with the module at `Modules/AI` (do not run these on a cohort database).
2. `docker build -t ogx-php -f docker/Dockerfile.php .` (PHP 8.5 CLI with ffi, pdo_mysql, pcntl, bcmath, gd).
3. MariaDB 11.3.2 on port 3306 (`mysql/my.cnf` from the host), or `docker/my-fast.cnf` + `--tmpfs /var/lib/mysql`
   for the reduced-durability baseline (B6a).
4. `composer install --no-dev --prefer-source`, plus `symfony/yaml` (the module needs it and does not declare it).
5. `.env`: `CACHE_STORE=array`, `QUEUE_CONNECTION=sync`, `BROADCAST_CONNECTION=null`.
6. Seed: create one human account, set economy/research/fleet speed 8, `php artisan ai:seed-test-universe --players=20 --confirm`.

The PHP scripts expect to live in the host's `storage/` directory (`require __DIR__.'/../vendor/autoload.php'`).

## Scripts

| Script | Measures | Example |
| --- | --- | --- |
| `simbench.php` | `ai:sim` with a statement counter (count, DB time, top tables) | `php storage/simbench.php '{"--hours":24,"--native-cognition":true,"--from":"2026-10-05T00:00:00Z"}'` |
| `sessionprof.php` | one login per account split into host advance / alliance life / decision / scheduling / executors (rolled back) | `php storage/sessionprof.php 2026-10-08T00:00:00Z` |
| `decisionprof.php` | perception, each candidate generator, scorer, building and unit planners | same |
| `memsim.php` | the whole thing on SQLite `:memory:` in one process: migrate, seed, sim | `php storage/memsim.php '{"--hours":24,"--native-cognition":true,"--from":"2026-10-05T00:00:00Z"}'` |
| `parity.php` | copies a MySQL state (port 3307) into SQLite `:memory:` **or** plays it on MySQL, then prints a state digest; diff two digests | `php storage/parity.php sqlite 12 2026-10-15T00:00:00Z` vs `... mysql ...` |
| `econ.php`, `econrs/` | identical economy toy in PHP and Rust (bit-exact) | `php econ.php 10000 30`; `cargo run --release --bin econ 100000 30 4` |
| `ffi.php` | PHP FFI no-op cost, Rust economy via FFI, battle engine per fight | needs `libeconrs.so` and the host's `libbattle_engine_ffi.so` |
| `pyo3env/` | PyO3 call overhead (`maturin build --release`) | |
| `onnx_latency.py` | candidate-scorer inference latency in ONNX Runtime | `python3 onnx_latency.py` |

Results are recorded in [../benchmark-plan.md](../benchmark-plan.md) and
[../rust-performance-research.md](../rust-performance-research.md).
