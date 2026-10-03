# Claude Project Manager (CPM)

A PHP command-line tool that gives AI coding assistants persistent, structured context about a codebase.

[![PHP Version](https://img.shields.io/badge/php-%3E%3D8.1-777bb4.svg)](https://www.php.net/)
[![License: MIT](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)
[![Version](https://img.shields.io/badge/version-1.3.2-blue.svg)](CHANGELOG.md)

---

## What it is

CPM analyses a project, records what it finds as plain JSON under a `.cpm/`
directory in the repository, and renders that state into a compact digest an AI
assistant can read at the start of a session.

It tracks four kinds of state:

- **Inventory and progress** — the files and functions found by the analysers, and
  their per-function status (`pending`, `in_progress`, `completed`, `verified`,
  `has_issues`).
- **Issues** — a small local issue tracker with categories, priorities, statuses,
  comments, file links, export and archiving.
- **Sessions and checkpoints** — named snapshots of project state, so a new session
  can pick up where the previous one stopped.
- **Analysis results** — dependency graphs, change-impact estimates and code metrics.

## What problem it solves

An AI assistant starts every session with no memory of the previous one. The usual
workarounds — pasting long summaries, or asking the assistant to re-read the whole
repository — are slow, expensive and inconsistent.

CPM keeps that state on disk instead. `cpm context:digest` writes a short markdown
digest listing in-progress work, open issues by priority and the most recent
checkpoints. A session begins by reading that file; it ends by recording progress and
regenerating it. The state lives in the repository, not in a chat transcript, so it
survives restarts, tool changes and handoffs between different assistants or people.

CPM is a bookkeeping tool. It does not call any AI model and does not need network
access.

## Requirements

| Requirement | Version | Notes |
| --- | --- | --- |
| PHP | 8.1 or newer | CLI SAPI; `json`, `mbstring` extensions |
| Composer | 2.x | For dependency installation |
| Operating system | Linux, macOS, WSL | Uses POSIX file locking for concurrent access |

No database server is required. All state is stored as JSON files inside the project.

## Installation

### Global installation from a git clone

Use this when you want one `cpm` binary available across several projects.

```bash
git clone https://github.com/wasuint/CPM.git /opt/cpm
cd /opt/cpm
composer install --no-dev --optimize-autoloader
chmod +x bin/cpm bin/claude-project
sudo ln -s /opt/cpm/bin/cpm /usr/local/bin/cpm
```

Verify:

```bash
cpm --version
# Claude Project Manager 1.3.2
```

### Per-project installation with Composer

```bash
composer require --dev wasuint/cpm
./vendor/bin/cpm --version
```

Both entry points are equivalent: `cpm` is the short name, `claude-project` the long
one.

## Quick start

```bash
cd ~/projects/my-app

# 1. Analyse the project and create the .cpm state directory
cpm start
```

```
Initializing Claude Project Manager
  Project type: php
  Files analysed: 214
  Functions found: 1180
  State written to .cpm/db
Project initialization complete.
```

```bash
# 2. See where the project stands
cpm status
```

```
Project: my-app (php)
Progress: 41% (484/1180 functions completed)
Open issues: 7 (2 high, 1 critical)
Last session: 2026-09-18 16:22
```

```bash
# 3. Record something that needs doing
cpm issue add --title "Order totals ignore discounts" --category bug --priority high
# Created MY-BUG-001
```

```bash
# 4. Generate the digest an assistant should read
cpm context:digest
# Wrote .cpm/context/DIGEST.md

# 5. Mark work done and refresh the digest
cpm progress mark-complete src/Order/TotalCalculator.php
cpm context:digest
```

## Core concepts

### The `.cpm/` state directory

Everything CPM knows lives in one directory at the project root:

```
.cpm/
├── db/            JSON state files (project, inventory, progress, sessions, issues, …)
├── context/       Generated digests, including DIGEST.md
├── config/        rules.json — analysis rules for this project
├── config.json    Runtime configuration (validation, logging, backups, tracking)
├── schemas/       JSON schemas used to validate the db files
├── backups/       Rolling backups of the db files
└── logs/          Command and daemon logs
```

`.cpm/` is normally excluded from version control. See [SECURITY.md](SECURITY.md) for
why that matters on public repositories.

Projects created by older versions used a `.claude-project/` directory. CPM detects it
and offers to migrate; `cpm migrate` performs the move explicitly.

### Sessions

`cpm start` opens a session and records it in `sessions.json`. Activity during the
session — files changed, functions marked, issues touched — is attributed to it, which
is what makes `cpm status --history` and the digest's "what happened last" section
possible.

### Progress

Progress is tracked per function, not per file. The analysers (PHP, Python,
JavaScript/TypeScript) enumerate functions into `inventory.json`; `progress.json`
carries a status for each one. Aggregate percentages are derived, never stored by hand.

### Issues

`cpm issue` is a self-contained tracker whose data lives in `.cpm/db/issues.json`.
Issue IDs follow `PREFIX-CATEGORY-NUMBER`, for example `CPM-BUG-001`. Closed issues can
be archived to `issues_archive.json` to keep the active file small.

### Context digests

`cpm context:digest` merges the state files into `.cpm/context/DIGEST.md`. The digest
leads with a journal — in-progress and blocked issues, the top open issues by priority,
the last three checkpoints — followed by function-level detail when the analysis is
recent enough to be trustworthy. Stale analysis is labelled as stale rather than
presented as current.

The digest is generated. Editing it by hand is pointless; the next regeneration
overwrites it.

## Command reference

Every command below appears in `cpm list`. Commands marked *anywhere* work outside an
initialised project; the rest require a `.cpm/` directory in the current directory or an
ancestor. Symfony Console adds `help`, `list` and `completion` on top of these.

### Project lifecycle

| Command | Purpose |
| --- | --- |
| `cpm start` | Analyse the project and begin tracking. Options: `--force`, `--quick`, `--skip-incremental`, `--fix-permissions` |
| `cpm status` | Current status and progress. Options: `--detailed`, `--history`, `--json`, `--health`, `--summary`, `--file=`, `--top=`, `--focus`, `--ai-priority` |
| `cpm summary` | Lightweight project summary. Options: `--lightweight`, `--json`, `--refresh` |
| `cpm monitor` | Check for changes and update progress. Options: `--continuous`, `--interval=`, `--daemon`, `--stop`, `--status`, `--json` |

### Progress and issues

| Command | Purpose |
| --- | --- |
| `cpm progress <action> [target]` | Actions: `show`, `mark-complete`, `mark-function`, `mark-range`, `mark-by-name`, `bulk-mark`, `reset`, `validate`, `repair`, `pending`, `verify` |
| `cpm issue <action> [id]` | Actions: `add`, `list`, `show`, `update`, `close`, `reopen`, `comment`, `link`, `export`, `archive` |
| `cpm checkpoint <action> [id]` | Actions: `create`, `list`, `restore`, `auto`, `prune` |

### Context for AI assistants

| Command | Purpose | Scope |
| --- | --- | --- |
| `cpm context:digest` | Generate or update the markdown digest and merged JSON. Option: `--out-dir=` | anywhere |
| `cpm ai-context` | AI-optimised context for session handoff. Options: `--json`, `--minimal`, `--detailed`, `--include-todos`, `--include-history` | project |
| `cpm suggest` | Workflow suggestions. Options: `--mode=`, `--json` | project |
| `cpm ai-suggest [context]` | Suggestions for next actions. Options: `--priority=`, `--limit=`, `--include-commands`, `--json` | project |
| `cpm smart-suggest [context]` | Suggestions using predictive analytics and learned patterns. Options: `--adaptive`, `--predictive`, `--personalized`, `--confidence-threshold=`, `--include-reasoning`, `--json` | project |
| `cpm docs` | Full command reference, written for AI assistants | anywhere |

### Analysis

| Command | Purpose |
| --- | --- |
| `cpm dependencies` | Dependency analysis. Options: `--show=`, `--graph`, `--circular`, `--refresh` |
| `cpm impact` | Change-impact analysis. Options: `--file=`, `--function=`, `--cached`, `--json` |
| `cpm learn <action>` | Pattern learning. Actions: `status`, `analyze`, `predict`, `adapt` |
| `cpm template <action>` | Template system. Actions: `list`, `generate`, `analyze`, `learn`, `export` |
| `cpm generate-code <type> <name>` | Pattern-aware code generation. Options include `--pattern=`, `--language=`, `--output-path=`, `--dry-run` |
| `cpm auto-test-docs <action>` | Actions: `generate-tests`, `generate-docs`, `analyze-coverage`, `full-suite` |

### Maintenance

| Command | Purpose | Scope |
| --- | --- | --- |
| `cpm config <action> [key] [value]` | Actions: `get`, `set`, `show`, `reset`, `defaults` | project |
| `cpm verify` | Health check with actionable suggestions. Options: `--fix`, `--json` | project |
| `cpm repair` | Repair common state problems. Options: `--auto-fix`, `--dry-run`, `--backup`, `--force`, `--json` | project |
| `cpm diagnostics` | Diagnose concurrent access and file locking. Options: `--file=`, `--detailed`, `--monitor` | project |
| `cpm compact` | Compact large documentation files. Options: `--target=`, `--force` | project |
| `cpm tools:fix-perms` | Diagnose and fix permission problems under `.cpm/` | anywhere |
| `cpm migrate` | Migrate a legacy `.claude-project/` directory to `.cpm/` | anywhere |
| `cpm update` | Refresh project template files from the CPM installation. Options: `--dry-run`, `--force`, `--backup` | anywhere |

Most commands accept `--json` for scripting, though the shape differs per command;
[docs/usage.md](docs/usage.md) explains which commands use the standard
`{success, action, timestamp, data}` envelope. Run `cpm list` for the Symfony Console
listing, or `cpm docs` for the long-form reference.

## Configuration

Configuration comes from three places: `.cpm/config.json` (runtime behaviour),
`rules.json` (analysis rules), and an optional `.cpm/.env` (Telegram credentials). See
[docs/configuration.md](docs/configuration.md) for the full key reference.

```bash
cpm config show
cpm config set tracking.mode minimal
cpm config set logging.level debug
```

## Documentation

| Document | Contents |
| --- | --- |
| [docs/installation.md](docs/installation.md) | Installation methods, permissions, verification |
| [docs/usage.md](docs/usage.md) | Day-to-day workflows and command usage |
| [docs/configuration.md](docs/configuration.md) | Every configuration key and its default |
| [docs/architecture.md](docs/architecture.md) | Namespace map, state format, request flow |
| [docs/troubleshooting.md](docs/troubleshooting.md) | Common failures and recovery procedures |
| [docs/DECISIONS.md](docs/DECISIONS.md) | Engineering decisions behind this release |

## Contributing

Contributions are welcome. Please read [CONTRIBUTING.md](CONTRIBUTING.md) for the
development setup, coding standards and pull request expectations, and
[CODE_OF_CONDUCT.md](CODE_OF_CONDUCT.md) for community expectations. Security reports
should follow [SECURITY.md](SECURITY.md) rather than the public issue tracker.

## License

MIT. See [LICENSE](LICENSE).
