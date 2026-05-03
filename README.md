# package-updater

A tiny Laravel Zero CLI that bulk-updates a Composer package across every repo
under `~/reps` that depends on it. Runs `git pull` on `develop` (falling back to
`staging` → `main` → `master`), then `ddev composer update <package>`. Skips
repos with uncommitted changes, collects failures, and prints a summary at the
end.

## Install

```bash
cd ~/reps
git clone <repo-url> package-updater   # or copy the directory in
cd package-updater
composer install
chmod +x package-updater
```

### Git credentials

The tool runs `git pull` via the shell, which uses your CLI git credentials
(GitKraken's auth is separate and won't be picked up). For HTTPS GitHub remotes,
set up the GitHub CLI as a credential helper once:

```bash
brew install gh
gh auth login          # pick HTTPS, browser auth
gh auth setup-git      # register gh as git's credential helper
```

After this, every repo with an `https://github.com/...` remote will pull
without prompting.

For Bitbucket / GitLab / Azure DevOps HTTPS remotes, install Git Credential
Manager — same idea, multi-host:

```bash
brew install --cask git-credential-manager
git-credential-manager configure
```

### Host SSH agent for SSH remotes

Repos with SSH remotes (`git@bitbucket.org:…`, `git@github.com:…`) bypass GCM
and require your host's SSH agent to have the right key loaded. To auto-load
on every login (macOS), add per-host entries to `~/.ssh/config`:

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

After that, the agent picks the key up on every login automatically. Add a
similar block for any other SSH host you pull from (different `IdentityFile`
if it uses a different key).

To find which local key matches the fingerprint shown in your git host's UI:

```bash
for f in ~/.ssh/*.pub; do ssh-keygen -lf "$f"; done
```

### DDEV SSH for private composer dependencies

`composer update` runs inside ddev, which uses a global, shared SSH-agent
container. The tool runs `ddev auth ssh` for you once at the start of a real
run (after the confirm prompt, before processing any repo) — so private
GitHub composer sources work without per-repo setup.

If your SSH keys have passphrases, you'll be prompted at that point. Pass
`--no-ssh-auth` to skip this step (e.g. if you've already loaded keys in this
shell).

### Output

In sequential mode (`--parallel=1`, the default) every step (`git pull`,
`ddev start`, `ddev composer update …`) streams its output live to the
terminal, so you can watch composer's progress in real time.

Parallel mode does not stream — multiple processes' output would interleave.
Use logs for parallel runs.

### Logs

When a repo fails, the full stdout/stderr of the failing command is written to
`logs/` next to the binary, and the path is shown in the summary table.

Optional — make it callable from anywhere:

```bash
ln -s ~/reps/package-updater/package-updater /usr/local/bin/package-updater
```

## Upgrade

```bash
cd ~/reps/package-updater
git pull
composer install
```

## Run

Fully interactive (prompts for package name and parallelism):

```bash
./package-updater
```

Preview which repos match and what version each has locked:

```bash
./package-updater update webhubworks/panoptikum-cell --dry-run
```

Try a single repo end-to-end before unleashing on all of them:

```bash
./package-updater update webhubworks/panoptikum-cell --limit=1
```

Run for real, four repos in parallel, no confirmation prompt:

```bash
./package-updater update webhubworks/panoptikum-cell --parallel=4 --yes
```

## Options

| Option        | Description                                                  |
|---------------|--------------------------------------------------------------|
| `--reps-dir=` | Directory containing repos (default: `~/reps`)               |
| `--parallel=` | Number of repos to process concurrently (`1` = sequential)   |
| `--dry-run`   | List matching repos with version + dep type (direct/transitive) and exit |
| `--limit=`    | Process at most N repos (after alphabetical sort). Also prompted interactively. |
| `--target-version=` | Skip repos whose lock already has this version (e.g. `1.5.0`) |
| `--update-package=` | Run `composer update` on this package instead of the target. Useful when a parent's constraint blocks reaching the desired version of a transitive target. Implies `-W`. |
| `--with-all-dependencies` | Pass `-W` to composer (set automatically when `--update-package` differs from the target) |
| `--no-ssh-auth` | Skip the initial `ddev auth ssh` step                      |
| `--yes`       | Skip the confirmation prompt (also skips target-version + -W prompts) |

## What happens per repo

1. `git status --porcelain` — if dirty, skip
2. Pick branch: `develop` → `staging` → `main` → `master`
3. `git checkout <branch>` + `git pull --ff-only`
4. `ddev start` → `ddev composer update <package>` → `ddev stop` (only on success)
5. Note any uncommitted `composer.lock` for the summary

The summary lists three groups: repos with uncommitted `composer.lock`
(your review queue), skipped repos with reason, and failed repos with the
branch and truncated error.
