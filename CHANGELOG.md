# Changelog

All notable changes to Claude Project Manager are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.3.1] - 2026-10-03

First public release, published as `wasuint/CPM`. This version includes the
preparation of the project for public release, and the documentation changes from the
internal 1.3.1 build of 2026-07-13.

### Added
- MIT licence (`LICENSE`), replacing the previous proprietary "Perpetual" terms.
- Community files: `CONTRIBUTING.md`, `CODE_OF_CONDUCT.md` (Contributor Covenant 2.1)
  and `SECURITY.md`, including guidance not to commit `.cpm/` to public repositories.
- GitHub Actions CI workflow running `composer validate --strict`, a `php -l` lint pass
  over `src/` and `bin/`, and the PHPUnit suite on PHP 8.1, 8.2, 8.3 and 8.4.
- GitHub issue forms for bug reports and feature requests, a pull request template, and
  weekly Dependabot updates for Composer and GitHub Actions.
- New documentation: `docs/configuration.md` (full configuration key reference),
  `docs/architecture.md` (namespace map, on-disk state format, request flow) and
  `docs/DECISIONS.md` (the engineering decisions behind this release).
- `.editorconfig` and `.gitattributes`.

### Changed
- README rewritten for a public audience: accurate command reference generated from the
  registered commands, installation instructions for both global and per-project
  installs, and a description of the `.cpm/` state directory.
- The three manuals were rewritten and renamed:
  `docs/INSTALLATION_MANUAL.md` to `docs/installation.md`,
  `docs/USAGE_MANUAL.md` to `docs/usage.md`,
  `docs/CPM_TROUBLESHOOTING.md` to `docs/troubleshooting.md`.
- The repository is published as `wasuint/CPM` on GitHub, and the Composer package is
  named `wasuint/cpm`.
- The minimum PHP version is 8.1. `composer.json` previously declared `>=7.4`, which the
  typed properties and `match` expressions in `src/` contradicted.
- `phpunit/phpunit` is pinned to `^9.6.33|^10.5.62`, excluding releases affected by a
  published security advisory.
- `deploy.sh` moved to `scripts/deploy.sh` and no longer appends to the user's shell
  profile. `docs/installation.md` shows the `PATH` change for the user to make
  themselves.
- `cpm docs` now documents the checkpoint command (`create`, `list`, `prune`), issue
  archiving, `--limit`, file and stdin text input, category and status aliases, agent
  attribution, and the journal-first digest.

### Fixed
- `.cpm/schemas` resolution, which picked the wrong directory depending on how CPM was
  installed and so skipped validation silently. Schema location now goes through
  `ClaudeProjectManager\Database\SchemaPathResolver`.
- `bin/cpm` is a real PHP wrapper rather than a symlink to `bin/claude-project`, so it
  survives checkouts and archive extraction on platforms without symlink support.
- Every invocation, including `cpm --version` and `cpm --help`, created a `.cpm/`
  directory in the working directory and printed `Failed to load current session`.
  Running CPM from a home directory left state behind there. Directory creation is now
  deferred until a command actually writes, schema defaults are no longer written at
  construction time, and the "no project here yet" case is recognised through a
  dedicated `Database\DatabaseFileNotFoundException` instead of being reported as an
  error.
- Outside an initialised project, `cpm start` is now registered lazily through a
  command loader, so no database or session service is constructed until `start`
  actually runs. `cpm context:digest` outside a project no longer creates
  `.cpm/context/STATE.json`; it stops with a hint to run `cpm start`. Covered by
  `tests/Integration/NoProjectSideEffectsTest.php`.

### Removed
- The `generate` and `metrics` commands, together with
  `src/Intelligence/CodeGenerationEngine.php`, `src/Intelligence/PatternAnalyzer.php`,
  `src/Intelligence/TemplateManager.php` and `tests/Intelligence/`. All of it depended on
  a `ClaudeProjectManager\InventoryManager` class that is not present in the repository;
  both commands were already unregistered and would have raised a fatal error if
  instantiated. See `docs/DECISIONS.md`.
- The unreachable `refactoring` command wrapper (`src/Commands/RefactoringCommand.php`),
  which was registered nowhere. `Services\RefactoringTracker` is unaffected and still
  runs as part of progress tracking.
- Internal planning and status documents under `docs/history/`.
- The duplicate legacy `src/TelegramNotifier.php`; `ClaudeProjectManager\Notifications\TelegramNotifier`
  is the only implementation.
- The stale root `schemas/` directory. `resources/schemas/` is the single source of the
  JSON schemas.
- The `version` field from `composer.json`. The git tag is the authoritative package
  version; `Console\Application::VERSION` remains authoritative for `cpm --version`.

## [1.3.0] - 2026-07-13

### Added
- Journal-based digest: `context:digest` opens with a journal section listing
  in-progress and blocked issues, the top ten open issues by priority, and the last
  three checkpoints — the session-handoff state an assistant actually needs.
- Agent attribution: the `--agent` flag or the `CPM_AGENT` environment variable is
  recorded as `created_by` and `updated_by` on issues, `agent` on comments and
  `metadata.agent` on checkpoints, and is shown in `checkpoint list` and the digest.
- Issue archiving: `cpm issue archive [--older-than DAYS|--before DATE] [--dry-run]`
  moves old closed issues to `.cpm/db/issues_archive.json`, atomically and deduplicated,
  inside the issues transaction lock.

### Changed
- Honest function tracking: the digest renders function-level detail only when the
  analysis is fresh (`tracking.max_age_days`, default 14) and enabled
  (`tracking.enabled`, default true). Stale data now shows an explicit notice with
  refresh instructions instead of claiming a fresh analysis. Projects that use only the
  journal can set `tracking.enabled` to false.

## [1.2.0] - 2026-07-13

### Fixed
- Lost-update race: concurrent CPM invocations could silently discard each other's
  writes, so `issue add` could print an ID for an issue that was then gone. Every
  read-modify-write cycle now holds an exclusive transaction lock (a `.txlock` sidecar
  using `flock`, with a 10 second timeout).
- `cpm monitor --status`, `--stop` and `--daemon --json` crashed with a PHP `TypeError`
  caused by a null output style. All daemon-control paths now emit proper JSON.
- The database read cache served stale data because of the PHP stat cache and
  one-second mtime granularity. Reads now call `clearstatcache` and compare file size.
- `DatabaseManager::safeUpdate()` called a method that did not exist.
- Checkpoint metadata reported a hardcoded `cpm_version` of 1.0.7.
- The test suite was not runnable: the configuration referenced a missing directory,
  there were PHPUnit 10 incompatibilities, and one mock expectation was stale.

### Changed
- Checkpoint snapshots are light, roughly 3 KB instead of 2.6 MB. They store aggregate
  statistics rather than embedding the full progress, inventory and session databases.
- Checkpoint retention is configurable through `checkpoints.max_retained`, default 50.
- Issue JSON output uses the standard envelope
  `{success, action, timestamp, data}`. Legacy top-level keys are kept for one release.
- `vendor/` is no longer tracked in git. Run `composer install --no-dev` after pulling.
- Historical planning and status documents moved to `docs/history/`.

### Added
- `cpm checkpoint prune [--keep N]`.
- `cpm issue list --limit N`.
- `--tag` as an alias for `--labels`, merged when both are given.
- Category aliases (`hw`, `sw`, `doc`, `docs`, `feat`, `fix`) and status aliases
  (`in-progress`, `wip`, `done`).
- Long-text input without shell arguments: `--description-file`, `--resolution-file`,
  `--text-file`, and `--description=-`, `--resolution=-`, `--text=-` for stdin.
- An integration test proving that parallel `issue add` calls lose no updates.

## [1.1.2] - 2026-03-21

### Changed
- Bumped the project version metadata and the CLI version output to 1.1.2.
- Updated the README version badge.

## [1.1.1] - 2026-01-03

### Fixed
- `cpm issue list` no longer filters by priority by default, which had been excluding
  low-priority issues.

## [1.1.0] - 2026-01-03

### Added
- Issue tracking: the `cpm issue` command with a full issue lifecycle — `add`, `list`,
  `show`, `update`, `close`, `reopen`, `comment`, `link` and `export`.
- Issue categories (`hardware`, `software`, `documentation`, `feature`, `bug`, `task`,
  `other`), priorities (`critical`, `high`, `medium`, `low`) and statuses (`open`,
  `in_progress`, `blocked`, `resolved`, `closed`).
- Issue IDs in the form `PREFIX-CATEGORY-NUMBER`, for example `CPM-BUG-001`.
- A new state file, `.cpm/db/issues.json`, validated against `issues.schema.json` and
  created automatically by `cpm start`.

### Changed
- `cpm help` and the README document the issue command.
- `DatabaseManager` recognises `issues` as a valid state file.

## [1.0.8]

### Fixed
- Assorted bug fixes, permission handling improvements and session continuity fixes.

## [1.0.7]

### Added
- First release: function-level progress tracking, session continuity, a monitoring
  daemon, context generation, pattern-aware code generation, quality metrics and impact
  analysis, and analysers for PHP, Python, JavaScript and TypeScript.

[Unreleased]: https://github.com/wasuint/CPM/compare/v1.3.1...HEAD
[1.3.1]: https://github.com/wasuint/CPM/releases/tag/v1.3.1
