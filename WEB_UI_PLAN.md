# Plan: Web UI for `package-updater` (Laravel + Inertia + Vue, local via ddev)

## Context

`package-updater` (`pu`) is a Laravel Zero CLI that bulk-updates Composer/Craft
packages across local repos under `~/reps`. Its two headline flows are:

- **`pu update:all <package>`** — find every repo whose `composer.lock` contains a
  package (exact or `vendor/*` wildcard), then `ddev composer update <pkg> [-W]`
  each one.
- **`pu update:craft <handle|craft|all>`** — find Craft repos / repos with a given
  plugin handle, then `ddev php craft update <handle> --interactive=0 --with-expired
  --minor-only --backup=1`, plus an optional post-update **composer sweep**
  (`webhubworks/*`) and **site-crawler** pass.

Both share a large orchestration layer (`UpdateAllCommand` / `UpdateCraftCommand` →
`RunsBulkRepoTasks`) that prompts for many decisions, fans out per-repo work to a
hidden **`pu update:single`** subprocess, and renders a live multi-worker spinner.
`update:single` already emits a **machine-readable protocol**: one JSON line per
progress event (`{"pu_event","pu_type","pu_payload"}`, via `ChildProgressEmitter`)
and a final `RepoUpdateResult` JSON line. The per-repo engine is `UpdateRepoAction`
(git status/checkout/pull → ddev start → craft/composer steps → `composer prep`
tests/phpstan → crawler → commit), with parsers for composer/craft update lists,
audit summaries, test summaries, phpstan errors and crawler 5xx.

**Goal:** a local-only web app that replaces the interactive terminal with a clean
Inertia/Vue dashboard — pick Craft vs Composer updates, a dynamic wizard walks the
same decisions the CLI asks, and every per-repo process renders as a big live card.

## Decisions (locked with the user)

1. **Execution split:** UI/HTTP run in a **ddev** web container; the long-running
   work (which must call `ddev`/`git`/`composer` against `~/reps`) runs in a Laravel
   **queue worker on the host**. Shared DB/queue between the two.
2. **Reuse strategy:** **wrap the `pu` CLI** — don't copy its Action classes. The web
   app spawns `pu update:single …` per repo and parses its existing JSON event
   stream; scanning is exposed via a small additive `--json` flag on the CLI.
3. **Live updates:** **polling** — the host worker writes progress to the DB; Vue
   polls (Inertia v2 `usePoll`) ~1 s while anything is running.
4. **Fidelity:** **full parity** — surface every CLI decision in the wizard.

## Architecture

```
Browser ──Inertia──▶ Laravel web (ddev container)  ── reads/writes ─▶ MySQL (ddev db)
                                                                          ▲
                                                          shared DB/queue │
                                                                          │
                        host: `php artisan queue:work`  ◀────────────────┘
                              └─ ScanJob / RunUpdateJob
                                 └─ spawn `pu update:single <repo> …`
                                    └─ parse pu_event JSON  ──▶ write progress rows
                                       └─ run ddev/git/composer on ~/reps/*
```

**Why MySQL, not SQLite:** on macOS ddev defaults to **mutagen** async sync, so a
SQLite file in the bind-mounted project dir is effectively two copies syncing —
concurrent host+container writes corrupt it. ddev's MySQL is a single real server
reachable from both sides, so it's the safe shared store for app data **and** the
`database` queue. Container connects via `DB_HOST=db`; the host worker connects via
`127.0.0.1:<published-port>` (pin the port in `.ddev/config.yaml` with
`database` → host port, or read it from `ddev describe -j`).

## Scope: two repos

### A) New Laravel app (the bulk of the work) — e.g. `package-updater-ui`

Laravel 12 + official **Vue starter kit** (Inertia 2, Vue 3 + TS, Tailwind,
shadcn-vue). Per webhub guidelines: Lucide SVG icons (no emoji), English
identifiers, use installed packages over custom code.

**Data model (migrations + Eloquent):**

- `runs`: `id`, `mode` (`all`|`craft`), `target` (package name or handle),
  `options` (json: parallel, target_version, raw_target, update_package,
  with_all_dependencies, keep_ddev_running, commit, sweep_patterns, crawl_repos,
  crawler_command, no_ssh_auth, filter_name, dry_run, open), `status`
  (`scanning`|`selecting`|`running`|`done`), `reps_dir`, timestamps.
- `repo_runs`: `id`, `run_id`, `path` (host abs path), `name`, `locked_version`,
  `is_direct`, `matched_packages` (json), `package` (per-repo target for craft),
  `selected` (bool), `status` (`candidate`|`queued`|`running`|`success`|`skipped`
  |`failed`), `current_step`, `output_tail` (json, last 5 lines `{kind,text}`),
  and result columns mirroring `RepoUpdateResult` (`branch`, `previous_version`,
  `installed_version`, `prep_ran`, `tests_failed`, `tests_summary`,
  `phpstan_errors`, `crawler_ran`, `crawler_failed`, `crawler_server_error_urls`,
  `package_updates` json, `committed`, `has_uncommitted_changes`, `message`,
  `log_path`, `prep_log_path`, `transcript_path`), `started_at`, `finished_at`.

**Host-run jobs** (dispatched to the `updates` queue, executed by the host worker):

- `ScanReposJob(run)`: shells `pu update:all <pkg> --dry-run --json` /
  `pu update:craft <handle> --dry-run --json` (see repo B), inserts one
  `repo_runs` row per match (`status=candidate`), records `isPattern` /
  `parentCandidates` on the run, sets `run.status=selecting`.
- `RunUpdateJob(run)`: the orchestrator. Mirrors `UpdateAllCommand`/
  `UpdateCraftCommand` glue **and** `RunsBulkRepoTasks::runParallel`:
  - build the per-repo command exactly like `UpdateAllCommand::$buildCmd` /
    `UpdateCraftCommand::$buildCmd` (`pu update:single <repo> <pkg>` +
    `--with-all-dependencies`/`--update-package=`/`--stop-ddev`/`--craft-command=`/
    `--crawler-command=`/`--composer-sweep=*`/`--commit`);
  - run a `Symfony\Process` pool sized to `options.parallel`; per tick read
    `getIncrementalOutput()`, split lines, `json_decode` the `pu_event` envelope,
    keep the last 5 → update `repo_runs.current_step` + `output_tail` (throttle DB
    writes to ~500 ms / only-on-change to limit MySQL churn);
  - on each process exit, parse the final `RepoUpdateResult` JSON → fill result
    columns + terminal `status`; when the queue drains set `run.status=done`.
  - one-time `ddev auth ssh` warm-up unless `no_ssh_auth` (mirrors
    `ensureDdevSshAuth`).

**Orchestration glue re-implemented in PHP** (small; not in `update:single`):
target-version pre-skip + cross-major guard (`!` prefix) and "already at" skip
(`applyTargetVersionFilter`/`parseTargetVersion`/`extractMajor`), transitive
parent selection (uses `parentCandidates` from scan JSON), composer-sweep default
allowlist persisted in a `settings` row (replaces `UserConfig`'s
`~/.config/package-updater/config.json`). These are ~30 lines total of
version-compare / fnmatch logic.

**HTTP / Inertia (container):**

- `GET /` — dashboard: two big cards, **Craft updates** vs **Composer packages**.
- Wizard (`runs.*`): `POST /runs` creates a `run` + dispatches `ScanReposJob`;
  wizard pages poll the run until `status=selecting`, then show matches (version,
  dep type, matched packages); full-parity option steps; `POST /runs/{run}/start`
  persists selection + options and dispatches `RunUpdateJob`.
- `GET /runs/{run}` — run view (cards). Returns run + `repo_runs`; Inertia
  `usePoll(1000, { only: ['repoRuns'] })` while any repo_run is non-terminal.
- Settings page for `reps_dir` + default sweep allowlist (replaces `pu setup`).

**Wizard ↔ CLI decision map (full parity):**

| CLI prompt / option | Wizard step |
|---|---|
| package / handle / `craft` / `all` | step 1 text/select |
| `--dry-run` | "preview only" toggle (lists matches, no run) |
| `--filter-name` | name-contains filter on results |
| target version + `!` cross-major | version input + "force across majors" switch |
| `--update-package` (transitive parent) | shown only when transitive deps exist; lists `parentCandidates` |
| `--with-all-dependencies` (-W) | switch (forced on for parent/pattern) |
| repo multiselect | checkbox list (select all / none) |
| `--parallel` | number input (1 = sequential) |
| keep/stop ddev | switch |
| `--commit` / `--no-commit` | switch |
| `--composer-sweep` / `--no-composer-sweep` (craft) | tag input, default from settings |
| crawler selection + command (craft) | per-repo checkboxes + editable command |
| `--no-ssh-auth` | switch (default off) |
| `--open` GitKraken | post-run: render `gitkraken://` links (browser opens them) |

**UI / cards:** responsive grid; each card = header (repo name, branch, status
badge, Lucide spinner while running), body (`current_step` + monospace block of the
last 5 `output_tail` lines), footer on completion (from→to pills, tests ✓/✗,
phpstan count, crawler 5xx URLs, committed/uncommitted, log/transcript paths). Top
summary bar: "N updated · M skipped · K failed", reusing the buckets from
`UpdateAllCommand::printSummary`. Invoke the **`ui-ux-pro-max`** skill for the
wizard flow + card layout before building.

### B) `package-updater` — small additive change (read-only, safe)

Add a `--json` branch to the **existing `--dry-run`** path of both
`UpdateAllCommand` and `UpdateCraftCommand` (right where they currently call
`table(...)`). It prints, then exits:

```json
{ "isPattern": false,
  "matches": [{ "path": "...", "name": "...", "version": "...",
                "isDirect": true, "matchedPackages": [...] }],
  "parentCandidates": [{ "name": "...", "repoCount": 3 }] }
```

Reuses `FindReposAction::find` / `findParentCandidates` and
`FindCraftReposAction::find` already invoked there — no new scan logic. This keeps
the web app on the "wrap the CLI" path (no copied Action classes) for scanning too.
`update:single` already accepts every per-repo flag the web app needs; no change
required there.

## ddev + host worker setup

- `ddev config` for the new app (php 8.2+, mysql). Pin the DB published port.
- Container `.env`: `DB_HOST=db`, `QUEUE_CONNECTION=database`.
- Host worker helper `bin/host-worker.sh` (committed): exports
  `DB_HOST=127.0.0.1 DB_PORT=<pinned>` and runs
  `php artisan queue:work --queue=updates --tries=1 --timeout=0`. The host PHP
  already satisfies `pu`'s `^8.2`; `pu`, `ddev`, `docker` are on the host PATH.
- README: "1) `ddev start`  2) run `bin/host-worker.sh` in a host terminal  3) open
  the ddev URL." Note the worker must stay running for scans/updates to execute.

## Build phases

1. Scaffold app (starter kit, ddev, MySQL, migrations, models, settings + reps_dir).
2. Add `--dry-run --json` to `package-updater` (repo B) + a Pest test for the shape.
3. `ScanReposJob` + dashboard + wizard step 1 + results listing (poll to `selecting`).
4. Full-parity option steps + `RunUpdateJob` (process pool, pu_event parsing, result
   persistence, target-version/parent/sweep glue).
5. Run view: live cards + summary bar + GitKraken links (ui-ux-pro-max).
6. Polish: errors/hints (reuse `hintFor` strings via the result message), empty
   states, settings page.

## Verification

- **Repo B:** `pu update:all <pkg> --dry-run --json | jq` returns valid match JSON;
  Pest unit test asserts the shape.
- **Jobs:** Pest feature tests with a faked/stubbed `Process` feeding a recorded
  `pu update:single` transcript (pu_event lines + final result) → assert `repo_runs`
  rows reach the right `status`/result columns and `output_tail` tracks last 5 lines.
  Unit-test the target-version/cross-major/parent glue.
- **End-to-end (manual):** `ddev start`; run `bin/host-worker.sh`; in the UI run a
  **Craft** update against one known test repo and a **Composer** update for a small
  package; confirm cards stream live steps, the final card shows from→to + tests +
  commit state, and the summary counts match. Cross-check against the same
  `pu update:craft`/`pu update:all` run in the terminal.
