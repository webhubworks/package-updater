# package-updater

A Laravel Zero CLI for bulk-updating Composer packages — and Craft itself /
Craft plugins — across every repo on your machine. Runs `git pull` on the
appropriate long-lived branch (`develop` → `dev` → `staging` → `stag` →
`stage` → `main` → `master` → `prod` → `live`), then the package update,
optional `composer prep`, optional `site-crawler`. Skips dirty repos,
captures full per-repo transcripts, summarises everything, and offers to
open repos with uncommitted changes in GitKraken for review.

## 1. Installation

```bash
cd ~/reps
git clone <repo-url> package-updater
cd package-updater
composer install
chmod +x package-updater
cp .env.example .env
```

Edit `.env` so `REPOS_DIR` points at the directory you want scanned
(defaults to `$HOME/reps`). The walker descends through grouping folders
(e.g. `~/reps/my7steps/<repo>`), considers a directory a candidate only
when it contains a `composer.lock`, and filters out anything that's not a
git repo or that itself declares `"type": "craft-plugin"`.

Optional — make it callable from anywhere:

```bash
ln -s ~/reps/package-updater/package-updater /usr/local/bin/package-updater
```

To upgrade later:

```bash
cd ~/reps/package-updater
git pull
composer install
```

## 2. Commands

Run `./package-updater <name>` (or just `./package-updater` for the default
`update`). Every command is fully interactive — it will prompt for any
answer it needs.

### `update` (default)

Universal composer-package update. Pick a `vendor/name`, pick a target
version, pick which of the matching repos to run on, pick parallelism,
confirm. Each repo runs `git pull` → `ddev start` → `ddev composer update`
→ `composer prep` (if defined) → `ddev stop` (optional). Repos already
at the target version are pre-skipped; with a bare target version, repos
on a different major are pre-skipped too (prefix the version with `!` to
force across majors).

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

### `retry`

Re-runs the most recent `update` or `update:craft` non-interactively
using the answers persisted in `logs/last-run.json`. Useful for working
through a batch in chunks — already-up-to-date repos are skipped by the
target-version filter, so each retry picks up where the previous one left
off.

### `open`

Opens repos from the most recent run in GitKraken (one tab per repo via
the `gitkraken://` URL scheme). By default it surfaces repos that
warrant review: uncommitted working-tree changes, failed steps, failing
tests after `composer prep`, crawler failures, or 5xx URLs from the
crawler. You can narrow the pool with `--filter=changed`,
`--filter=failed`, or `--filter=all`. Both `update` and `update:craft`
also offer this prompt directly at the end of a run — push the resulting
commits via GitKraken without context-switching.

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

`REPOS_DIR` in `.env` — single source of truth for where to scan. Default:
`$HOME/reps`. Per-run override available on every command.

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
