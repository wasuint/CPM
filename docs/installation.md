# Installation

How to install Claude Project Manager, verify the installation, and resolve the
problems that come up most often during setup.

## Table of contents

- [Requirements](#requirements)
- [Choosing an installation method](#choosing-an-installation-method)
- [Global installation from a git clone](#global-installation-from-a-git-clone)
- [Per-project installation with Composer](#per-project-installation-with-composer)
- [Running directly from the repository](#running-directly-from-the-repository)
- [Initialising a project](#initialising-a-project)
- [What `cpm start` creates](#what-cpm-start-creates)
- [Excluding CPM state from version control](#excluding-cpm-state-from-version-control)
- [Permissions](#permissions)
- [Migrating from the legacy directory](#migrating-from-the-legacy-directory)
- [Verifying the installation](#verifying-the-installation)
- [Installation problems](#installation-problems)
- [Uninstalling](#uninstalling)

## Requirements

| Requirement | Version | Notes |
| --- | --- | --- |
| PHP | 8.1 or newer | CLI SAPI. The `json` and `mbstring` extensions are used. |
| Composer | 2.x | Needed to install dependencies. |
| Operating system | Linux, macOS, or Windows under WSL | CPM uses `flock` for concurrency control. |
| Disk | A few megabytes per tracked project | State scales with the number of functions, not with repository size. |

Check what you have:

```bash
php -v
composer --version
```

CPM has no database server, no daemon that must be installed system-wide, and no
network requirement. Telegram notifications are the only outbound feature, and they are
off by default.

## Choosing an installation method

| Method | Use it when |
| --- | --- |
| Global installation from a git clone | You track several projects and want one `cpm` on your `PATH`. |
| Per-project Composer dependency | CPM should be pinned per project and available to everyone who clones it. |
| Running from the repository | You are developing CPM itself. |

## Global installation from a git clone

```bash
# 1. Clone as your normal user, not as root
git clone https://github.com/wasuint/CPM.git /opt/cpm
cd /opt/cpm

# 2. Install runtime dependencies
composer install --no-dev --optimize-autoloader

# 3. Make the entry points executable
chmod +x bin/cpm bin/claude-project

# 4. Put cpm on the PATH (choose one)
sudo ln -s /opt/cpm/bin/cpm /usr/local/bin/cpm            # system-wide symlink
# or, for a single user:
echo 'export PATH="/opt/cpm/bin:$PATH"' >> ~/.bashrc && . ~/.bashrc
```

Choose an installation directory your user can write to, or make yourself the owner:

```bash
sudo chown -R "$USER:$USER" /opt/cpm
```

The clone directory is the *installation*; it is not a tracked project. CPM finds the
project you are working on from the current working directory, not from where the
binary lives.

## Per-project installation with Composer

```bash
cd ~/projects/my-app
composer require --dev wasuint/cpm
./vendor/bin/cpm --version
```

Composer installs both entry points into `vendor/bin/`. Add a shell alias if typing the
path becomes tedious:

```bash
alias cpm='./vendor/bin/cpm'
```

## Running directly from the repository

```bash
git clone https://github.com/wasuint/CPM.git
cd CPM
composer install          # includes PHPUnit
php bin/cpm --version
vendor/bin/phpunit
```

See [CONTRIBUTING.md](../CONTRIBUTING.md) for the full development workflow.

## Initialising a project

From the root of the project you want to track:

```bash
cd ~/projects/my-app
cpm start
```

Useful variations:

```bash
cpm start --quick              # skip deep parsing; much faster first pass
cpm start --force              # re-analyse a project that is already initialised
cpm start --skip-incremental   # ignore cached results and analyse everything
cpm start --fix-permissions    # repair ownership and modes while initialising
```

On a large repository the first run takes a while because every included file is
parsed. Later runs are incremental.

## What `cpm start` creates

```
my-app/
└── .cpm/
    ├── db/              JSON state: project, inventory, progress, sessions, issues, …
    ├── context/         Generated digests, including DIGEST.md
    ├── config/          rules.json — the analysis rules for this project
    ├── config.json      Runtime configuration
    ├── schemas/         JSON schemas used to validate db/
    ├── backups/         Rolling backups of db/
    └── logs/            Command and daemon logs
```

`docs/architecture.md` describes each state file. `docs/configuration.md` documents
every configuration key.

## Excluding CPM state from version control

Add this to the tracked project's `.gitignore`:

```gitignore
.cpm/
.claude-project/
```

`.cpm/` contains file paths, function names and issue text from your codebase. It also
holds `.cpm/.env` when Telegram notifications are configured. Keep it out of public
repositories. See [SECURITY.md](../SECURITY.md).

Some teams deliberately commit a curated subset — for example a project-root
`rules.json` — so that everyone analyses the project the same way. That file lives at
the project root, outside `.cpm/`, and is safe to commit.

## Permissions

CPM creates `.cpm/` with restrictive permissions. Two rules avoid almost every
permission problem:

1. **Never run CPM as root.** Root-owned files inside a user-owned repository break
   every subsequent run.
2. **Run it as the user who owns the project directory.**

If something has already gone wrong:

```bash
cpm tools:fix-perms            # diagnose and repair
cpm start --fix-permissions    # repair during initialisation
```

To repair by hand:

```bash
sudo chown -R "$USER:$USER" ~/projects/my-app/.cpm
find ~/projects/my-app/.cpm -type d -exec chmod 770 {} +
find ~/projects/my-app/.cpm -type f -exec chmod 660 {} +
```

## Migrating from the legacy directory

Projects initialised by CPM 1.0 used `.claude-project/` instead of `.cpm/`. CPM detects
the old layout and offers to migrate on the next run. To do it explicitly:

```bash
cpm migrate
```

The migration moves the directory and leaves the data intact. `ConfigManager` still
reads `.claude-project/` when `.cpm/` is absent, so an unmigrated project keeps working;
migrating simply makes the layout current.

## Verifying the installation

```bash
cpm --version                  # Claude Project Manager 1.3.1
cpm list                       # all registered commands
cpm docs                       # long-form reference

cd ~/projects/my-app
cpm start                      # initialise
cpm status                     # should print a status block
cpm verify                     # health check
cpm context:digest             # writes .cpm/context/DIGEST.md
cat .cpm/context/DIGEST.md
```

If all seven succeed, the installation is sound.

To undo a test initialisation, remove the directory:

```bash
rm -rf ~/projects/my-app/.cpm
```

## Installation problems

### `cpm: command not found`

The symlink or `PATH` entry is missing. Check and repair:

```bash
which cpm
ls -l /usr/local/bin/cpm
echo "$PATH"
```

Fall back to the full path while you sort it out: `php /opt/cpm/bin/cpm --version`.

### `Could not find autoloader`

`composer install` has not been run in the installation directory, or `vendor/` was
removed. Run it again:

```bash
cd /opt/cpm && composer install --no-dev --optimize-autoloader
```

### Composer refuses to install because of the PHP version

```bash
php -v                                     # what the CLI actually uses
composer install --ignore-platform-req=php # only as a temporary diagnostic
```

If several PHP versions are installed, make sure the CLI binary on your `PATH` is 8.1 or
newer. On Debian and Ubuntu, `update-alternatives --config php` switches it.

### `Permission denied` when writing `.cpm/`

See [Permissions](#permissions) above. The usual cause is an earlier run as root.

### The daemon cannot write its PID file

```bash
mkdir -p .cpm/logs && chmod 770 .cpm/logs
rm -f .cpm/logs/monitor.pid          # only when no daemon is actually running
cpm monitor --stop
cpm monitor --daemon
```

### A command is missing from `cpm list`

Most commands require an initialised project. If you are outside one, only `docs`,
`context:digest`, `tools:fix-perms`, `migrate`, `update` and `start` are registered.
Run `cpm start` first.

## Uninstalling

Remove the state from a tracked project:

```bash
rm -rf ~/projects/my-app/.cpm
```

Remove a global installation:

```bash
sudo rm -f /usr/local/bin/cpm
rm -rf /opt/cpm
```

Remove a Composer installation:

```bash
composer remove --dev wasuint/cpm
```
