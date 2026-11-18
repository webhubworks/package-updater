# package-updater

A Laravel Zero CLI for bulk-updating Composer packages — and Craft itself /
Craft plugins — across every repo on your machine. Fetches from `origin`,
then runs `git pull` on the appropriate long-lived branch (`develop` →
`dev` → `staging` → `stag` → `stage` → `main` → `master` → `prod` →
`live`), picking it up even when it only exists on the remote so far.
After the pull come the package update, optional `composer prep`, and
optional `site-crawler`. Skips dirty repos, captures full per-repo
transcripts, summarises everything, and offers to open repos with
uncommitted changes in GitKraken for review.

## 1. Installation

### Global (recommended)

```bash
composer global require webhubworks/package-updater
```

Make sure Composer's global bin directory is on your `PATH` — typically
`~/.composer/vendor/bin` (macOS/Linux) or `%APPDATA%\Composer\vendor\bin`
(Windows). After that, both `package-updater` and the shorter alias `pu`
are available from anywhere.

Set `REPOS_DIR` in your shell (or via `.env` next to the tool) so it
points at the directory you want scanned (defaults to `$HOME/reps`). The
walker descends through grouping folders (e.g. `~/reps/my7steps/<repo>`),
considers a directory a candidate only when it contains a
`composer.lock`, and filters out anything that's not a git repo or that
itself declares `"type": "craft-plugin"`.

To upgrade later:

```bash
composer global update webhubworks/package-updater
```

### Local clone (for development)

```bash
cd ~/reps
git clone <repo-url> package-updater
cd package-updater
composer install
./package-updater list
```

## 2. Commands

Run `pu <name>` (or the longer `package-updater <name>`). Bare `pu`
prints the command list. Every command is fully interactive — it will
prompt for any answer it needs.

### `update`

Single-repo helper. Run it from inside a repo: it verifies the working
tree is clean, runs `composer update` (auto-detecting ddev when
`.ddev/config.yaml` exists), parses Upgrading / Downgrading / Installing
/ Removing lines from composer's output, and commits the result with
title `Package updates` and a body listing each change. Optional package
arguments (`pu update vendor/foo vendor/bar`) restrict the update to
those packages. Use `--no-ddev` to force host composer, `--commit` /
`--no-commit` to skip the commit prompt.

`--key=<panoptikum key>` runs the update in the repo under the repos
directory whose Panoptikum key (composer name, else origin owner/repo)
matches, so it works from any directory. Panoptikum's cell page shows
copyable commands such as `pu update --key=acme/shop guzzlehttp/guzzle`.
The update runs on the branch the repo has checked out.

### `update:all`

Universal bulk update — one composer package across every local repo
that depends on it. Pick a `vendor/name`, pick a target version, pick
which of the matching repos to run on, pick parallelism, confirm. Each
repo runs `git fetch --prune` → `git pull` → `ddev start` →
`ddev composer update` → `composer prep` (if defined) → `ddev stop`
(optional). After checking out the lowest long-lived branch, the run
checks the higher branches (e.g. `main`/`master` a teammate committed to
directly) for work it doesn't have. A `main` that's ahead only by a
merge of this branch into it carries nothing, so the routine merge-down
of `develop` into `main` is ignored. Work that this branch really is
missing is merged down for you when git can fast-forward to it, which is
the case whenever the branch has no commits of its own yet. Only a real
divergence, both branches carrying work the other doesn't have, aborts
the repo, because updating a stale branch would base the commit on the
wrong tree; that one is left for you to merge down first. Repos
already at the target version are pre-skipped; with a bare target
version, repos on a different major are pre-skipped too (prefix the
version with `!` to force across majors).

`--filter-name=<substring>` narrows the match set to repos whose
`composer.json` `name` contains the given substring — e.g.
`pu update:all vendor/foo --filter-name=mvb` only considers repos with
`mvb` in their composer name.

The package argument also accepts a wildcard — e.g.
`pu update:all 'laravel-lang/*'` matches every locked package under
that vendor prefix and runs `composer update laravel-lang/* -W` per
repo. Wildcards skip the target-version and transitive-parent prompts
and always pass `-W` (composer almost always needs it to bump siblings
together). Quote the pattern in your shell so it isn't glob-expanded.

### `remove`

Bulk counterpart for removing a package. Same scan as `update:all`,
including wildcard support (`pu remove 'laravel-lang/*'`). Pre-skips
repos where the package is only a transitive dependency (composer
remove only works on declared deps) and pre-skips dirty repos.
For each remaining repo it detects whether the package is in `require`
or `require-dev` and groups the removals so composer is called with
`--dev` for the dev-only ones (plain for the rest). On success it
commits the change with title `Remove <package>` (or
`Remove N packages` for wildcards) and a body listing the removed
names.

```bash
pu remove vendor/foo                   # exact name
pu remove 'laravel-lang/*' --dry-run   # preview which packages would be removed
```

### `update:craft`

Craft-aware variant. Same flow, but:

- Identifies repos by Craft plugin handle (or the literal `craft` to match
  every site with `craftcms/cms` in `composer.json`).
- After `ddev start`, always syncs the working copy before the update:
  `ddev composer install` → `ddev php craft migrate/all` →
  `ddev php craft project-config/apply`.
- Runs `ddev php craft update <handle>` (with sensible defaults you can
  edit at the prompt) instead of `composer update`.
- After `composer prep`, optionally runs `site-crawler crawl:ddev` in a
  second multiselect-chosen subset of repos. Parses the crawler's
  "Failed requests" table and warns on any 5xx URLs even if the crawler
  itself exited cleanly.
- Skips **both verification steps — `composer prep` and the crawl — for
  any repo the run left untouched.** They're there to catch breakage the
  run introduced, so when there was nothing to update, `git pull` brought
  no new commits, and the working tree came out clean, the repo is
  exactly what it was and re-running the test suite and the crawler only
  re-confirms what already passed. The summary marks those repos
  `prep + crawl skipped (repo unchanged)` and their Tests column reads
  `-`. Anything that could have changed the site vetoes the skip: a
  parsed update, a dirty working tree (which also covers an update whose
  output we failed to parse), a moved `HEAD`, or a `--maintenance`
  dirty-repo reset. Because a colleague's pushed commits move `HEAD`,
  new code still gets the full tests-and-crawl treatment — what's
  dropped is only the repeat work on repos nothing happened to. This is
  what makes a back-to-back run over an already up-to-date set of repos
  fast.

`--filter-name=<substring>` works the same way as on `update:all`:
restricts the match set to repos whose `composer.json` `name` contains
the substring.

`--maintenance` is a one-flag preset for a dedicated update server. It
implies `--yes` and pre-seeds every prompt with the semi-automated
defaults: handle `all` (update every Craft package in every matched
repo), `--parallel=3`, `--stop-ddev` (stop each project after a
successful update), commit + push, crawl every repo, and a craft command
with `--backup=0`. When a Slack webhook is configured (see
[Configuration](#slack-webhook-maintenance-runs)) it also posts a run
summary at the end. Any explicit flag still wins, so
`pu update:craft --maintenance --parallel=5 --no-push` overrides just
those two defaults.

Because a dedicated update server never carries real local work,
`--maintenance` also **hard-resets any repo with uncommitted changes**
before updating it (`git reset --hard HEAD` + `git clean -fd`) instead of
skipping it, then lets the normal sync steps (`ddev composer install`,
`migrate/all`, `project-config/apply --force`) bring dependencies and
project config back in line. Gitignored paths (`vendor/`, `.env`,
`storage/`) are left untouched. This only happens under `--maintenance` —
a normal `update:craft` run still skips dirty repos.

`--maintenance` also **retries the craft update once on a transient
composer download failure** (a corrupt/0-byte dist zip, a truncated
download). Before retrying it restores a healthy state — `git reset
--hard` + `git clean -fd`, `ddev composer clear-cache`, then `ddev
composer install` from the committed lock — so a half-extracted vendor
tree (which can stop Craft from even booting) can't wedge the retry.
Genuine dependency conflicts are never retried.

`--maintenance` also **resets a diverged branch to origin**. When the
branch carries local commits that were never pushed (usually a previous
run whose push was held back by failing tests or crawler errors) and
origin has moved on in the meantime, the fast-forward pull can't work.
Instead of failing, the run does `git reset --hard origin/<branch>`,
discards the local commits, and rebuilds the update on top. The summary
and the Slack message both name the repo and how many commits were
discarded. A branch that's only ahead of origin is left alone, so its
commit goes out with the next push. A normal `update:craft` run still
fails on a diverged branch.

#### Excluding a project

To pause a project, put an empty `.nopu` file in its root, the way you'd
add a `.gitkeep`, and commit it. Every command (`update:all`,
`update:craft`, `remove`) then finds the repo but leaves it completely
untouched: no reset, no pull, no ddev. It shows up as `excluded via
.nopu` in the summary, and in its own section of the Slack message
instead of among the failures. To resume, commit the removal.

The marker is read from origin's branch right after the fetch, so a
marker a colleague just pushed takes effect on the next run, and so does
its removal, even though the server's own checkout is still stale. An
untracked `.nopu` on one machine also works, as a local override that
never leaves that machine.

A marker that is already in the working tree, committed or not, is
checked before the fetch, so the repo's remote is never contacted. That
protects repos whose git server bans the office IP after failed logins,
such as one where only GitKraken holds the credentials. The price: when
a colleague removes a committed marker, a machine whose checkout still
has it keeps skipping the repo until someone pulls it by hand.

### `update:npm`

Applies the npm fixes that Panoptikum lists, and nothing else. Panoptikum
decides what needs an update: every open npm threat on production cells,
whatever its relevance and including dev dependencies, merged per
repository. Packages without an advisory are never touched. `pu update:npm` reads that list
from Panoptikum's `GET /api/v1/npm-updates` and works through every local
repo that appears in it. Configure the endpoint first, see
[Panoptikum](#panoptikum-npm-updates).

A local folder matches a Panoptikum repository by its composer `name`, or,
without a `composer.json`, by the lowercased `owner/repo` of its `origin`
remote. That's the same key panoptikum-cell reports, so Node and Nuxt apps
match too. The walker considers any git folder with a `package-lock.json`.
A key that appears in more than one local folder (a generic composer name
such as `laravel/laravel`) matches none of them, and the run lists it.

For each repo, the command does the following:

1. Prepares the branch exactly like `update:craft`: fetch, lowest
   long-lived branch, `.nopu`, the higher-branch guard, and under
   `--maintenance` the reset of dirty or diverged repos.
2. Skips the repo when it has a `yarn.lock`. A `package-lock.json` with
   `lockfileVersion` 1 is converted first with
   `npm install --package-lock-only --lockfile-version=2`, which keeps the
   installed versions. Version 2 because a server still on npm 6 reads it
   but fails `npm ci` on version 3. The conversion gets its own commit,
   `chore: convert package-lock.json to lockfileVersion 2`, ahead of the
   update commit, and the gate checks both.
3. Updates only fixes on the installed caret line (same major, or same
   minor below 1.0). A fix that needs a new major, and a package Panoptikum
   reports as not fixable, are only listed in the run notes.
4. Runs `npm update <packages> --ignore-scripts --before=<7 days ago>`, so
   install scripts never run and only releases older than a week get in.
   `--before` works on every npm version.
5. Adds an `overrides` entry (`"<package>": "^<fixed>"`) to `package.json`
   when a transitive package is still below its fix because a parent holds
   it back, and installs again.
6. Checks the result against Panoptikum's blocked versions. A package that
   lands inside a blocked range is dropped from the update and noted as
   "blocked by WBHB-xxxx". The other packages still update.
7. Gates the result: `npm ci --ignore-scripts`, the project's
   production build (`npm run build`, else `production` or `prod` for
   Laravel Mix), `ddev browser-test` when
   `.ddev/commands/web/browser-test` exists, then the site-crawler.
   `composer prep` doesn't run. `npm ci` and the build use the Node
   version in the project's `.nvmrc` with its own npm, so the gate installs
   and builds like the server deploys. Inside ddev, `n` downloads that
   version into ddev's global cache. On the host, nvm provides it and
   installs a missing version. The update steps before the gate keep the
   default npm, because npm 6 can't write lockfile v2 or apply overrides.
   On Apple Silicon, Node versions before 16 need Rosetta.
8. Commits only `package-lock.json`, plus `package.json` when an override
   was added, as `npm security updates`. The body lists each change with
   its relevance and advisories, the source files that import a changed
   package (for a transitive package, the files that import its direct
   parent), and a GitHub compare link per changed direct dependency.
9. Pushes only when the tests and the crawl were clean.

Repos with a `.ddev/config.yaml` run `ddev npm`. Other repos, such as
Node apps, run the host's `npm` and get no crawl.

```bash
pu update:npm --dry-run                  # list repos and the packages Panoptikum wants updated
pu update:npm --repo=$HOME/reps/mesh     # one repo, interactive
pu update:npm --key=acme/shop            # one repo by its Panoptikum key, from any directory
pu update:npm --maintenance              # dedicated server: commit, push, Slack summary
```

It takes the same flags as `update:craft`, except the handle,
`--target-version`, `--craft-command`, and the composer sweep.
`--filter-name=` matches the Panoptikum key instead of the composer name.

### `retry`

Re-runs the most recent `update:all`, `update:craft`, or `update:npm` non-interactively
using the answers persisted in `logs/last-run.json`. Useful for working
through a batch in chunks — already-up-to-date repos are skipped by the
target-version filter, so each retry picks up where the previous one left
off.

Without a target version (e.g. `--maintenance`, which updates `all`)
nothing is filtered, so a plain `retry` processes every repo again. Pass
`--failed` to replay only against the repos marked failed in
`logs/last-results.json`. The crawler selection is narrowed to match. If
the last run never reached its summary, the stored results don't describe
it and `--failed` refuses to guess.

A replay never rewrites `logs/last-run.json` (`retry` passes the internal
`--retrying` flag), so every `retry` replays the original run. It does
write `logs/last-results.json`, so `open` and `retry --failed` see the
latest outcome and each `retry --failed` only takes on what is still
failing.

### `open`

Opens repos from the most recent run in GitKraken (one tab per repo via
the `gitkraken://` URL scheme). By default it surfaces repos that
warrant review: uncommitted working-tree changes, failed steps, failing
tests after `composer prep`, crawler failures, or 5xx URLs from the
crawler. You can narrow the pool with `--filter=changed`,
`--filter=failed`, or `--filter=all`. Both `update:all` and
`update:craft` also offer this prompt directly at the end of a run —
push the resulting commits via GitKraken without context-switching.

### Logs and transcripts

- `logs/transcripts/<repo>-<timestamp>.log` — full output of every step
  for one repo, from `git status` to `ddev stop`. Always written, success
  or fail.
- `logs/<repo>-<step>-<timestamp>.log` — narrow per-step log written when
  a specific command fails or its tests don't pass.
- `logs/last-run.json` — the resolved command + arguments + options of
  the last run (powers `retry`).
- `logs/last-results.json` — per-repo results of the last run (powers
  `open`).

In sequential mode (`--parallel=1`) every step's output streams live to
the terminal. Parallel mode does not stream (output would interleave) —
the transcript and per-step logs are how you investigate.

## 3. Configuration

### Repo directory

`REPOS_DIR` (env var, or `.env` next to the tool when running from a
local clone) — single source of truth for where to scan. Default:
`$HOME/reps`. Per-run override available on every command via
`--reps-dir=`.

### Slack webhook (maintenance runs)

`pu update:craft --maintenance` posts a run summary to Slack when a
webhook is configured. To set it up:

1. At <https://api.slack.com/apps>, create a Slack app (or reuse one),
   enable **Incoming Webhooks**, then **Add New Webhook to Workspace**
   and pick the channel the summary should post to.
2. Copy the generated `https://hooks.slack.com/services/...` URL.
3. Save it - interactively via `pu setup`, or non-interactively:

```bash
pu setup --slack-webhook-url="https://hooks.slack.com/services/T.../B.../..."
pu setup --no-slack   # persist an empty webhook (notifications off)
```

The URL is stored in `~/.config/package-updater/config.json`. Leave it
blank to disable notifications; once you have answered, `pu setup` won't
keep asking.

The summary groups the run into three buckets: repos updated, committed
and pushed; repos updated but not pushed (uncommitted, or committed with
the push held back by failing tests / PHPStan / a site-crawler issue);
and failed, skipped, or otherwise-flagged repos. The last bucket also
catches an otherwise up-to-date repo that still hit a problem during the
run — a 5xx during the crawl (with the offending URLs listed), a crawler
crash, or failing tests — so those never disappear into the "already up
to date" count. It's only sent on `--maintenance` runs, and only when a
webhook is configured. Any send error is logged as a warning and never
fails the run.

The message posts under the Slack app's own name and icon (the app the
webhook belongs to). Slack ignores per-message sender overrides for
app-based webhooks, so rename the Slack app itself if you want a
different display name.

### Panoptikum (npm updates)

`pu update:npm` reads its work list from Panoptikum. Save the base URL and
the token once:

```bash
pu setup --panoptikum-url=https://panoptikum.example --panoptikum-token=<token>
```

The token is Panoptikum's `PANOPTIKUM_PACKAGE_UPDATER_TOKEN`, stored in the
1Password vault "Environment Secrets" as "Panoptikum package-updater
token". Both values go into `~/.config/package-updater/config.json`, which
the tool keeps readable for your user only. Panoptikum also checks the
caller's IP address, so the machine that runs the command must be on its
allowlist.

### Git credentials (HTTPS remotes)

The tool shells out to `git`, which uses your CLI credentials — not
GitKraken's. For HTTPS GitHub remotes, install and configure the GitHub
CLI once:

```bash
brew install gh
gh auth login          # pick HTTPS, browser auth
gh auth setup-git      # register gh as git's credential helper
```

For Bitbucket / GitLab / Azure DevOps HTTPS remotes, use Git Credential
Manager:

```bash
brew install --cask git-credential-manager
git-credential-manager configure
```

After either, `git pull` runs without prompting on matching remotes.

### Host SSH agent (SSH remotes)

Repos cloned over SSH (`git@bitbucket.org:…`, `git@github.com:…`) bypass
those credential helpers and need your host's SSH agent to have the right
key loaded. Add a per-host block to `~/.ssh/config` so macOS loads it on
login:

```
Host bitbucket.org
  AddKeysToAgent yes
  UseKeychain yes
  IdentityFile ~/.ssh/id_rsa
```

Then once:

```bash
ssh-add --apple-use-keychain ~/.ssh/id_rsa
```

To find which local key matches the fingerprint shown in your git host's
UI:

```bash
for f in ~/.ssh/*.pub; do ssh-keygen -lf "$f"; done
```

### DDEV SSH for private composer dependencies

`composer update` runs inside ddev, which uses a global, shared SSH-agent
container. The tool runs `ddev auth ssh` for you once at the start of a
real run (after the confirm prompt, before any repo work) so private
GitHub composer sources resolve without per-repo setup. If your SSH keys
have passphrases, you'll be prompted at that point. The step can be
skipped if you've already loaded keys in this shell.

### DDEV hostnames / sudo

The first time ddev starts a given project on this device it may need
sudo to add the project's `.ddev.site` hostname to `/etc/hosts`. In a
piped/parallel run that would hang forever on the password prompt — the
tool watches for the trigger lines (`needs to run with administrative
privileges` / `may need to enter your password for sudo`) and kills ddev
immediately, then fails that repo with a hint telling you to run `ddev
start` manually once and then `retry`.

### GitKraken (for the open feature)

Uses the `gitkraken://repo/path/<absolute-path>` URL scheme via macOS
`open`. No extra setup beyond installing the GitKraken app.
