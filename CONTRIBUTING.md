# Contributing to Claude Project Manager

Thank you for considering a contribution. This document describes how to set up a
development environment, the standards the code follows, and what a good pull request
looks like.

By participating you agree to abide by the [Code of Conduct](CODE_OF_CONDUCT.md).

## Table of contents

- [Development environment](#development-environment)
- [Project layout](#project-layout)
- [Coding standards](#coding-standards)
- [Running the tests](#running-the-tests)
- [Branches and pull requests](#branches-and-pull-requests)
- [Commit messages](#commit-messages)
- [Reporting a bug](#reporting-a-bug)
- [Proposing a feature](#proposing-a-feature)

## Development environment

Requirements: PHP 8.1 or newer with the CLI SAPI, and Composer 2.

```bash
git clone https://github.com/wasuint/CPM.git
cd CPM
composer install
php bin/cpm --version
```

`composer install` (without `--no-dev`) installs PHPUnit, which the test suite needs.

To exercise the tool against a real project without installing it globally, run the
binary by path from inside that project:

```bash
cd ~/projects/my-app
php /path/to/CPM/bin/cpm start
```

Do not run CPM as root. It creates files inside the target repository, and root
ownership is the most common cause of later permission failures.

## Project layout

| Path | Contents |
| --- | --- |
| `bin/` | The `cpm` and `claude-project` entry points |
| `src/Commands/` | Symfony Console commands |
| `src/Console/` | The application wiring and two namespaced commands |
| `src/Analysis/`, `src/Analyzers/` | Language analysers, dependency and impact analysis |
| `src/Services/` | Configuration, logging, permissions, health, file concurrency |
| `src/Intelligence/` | Pattern analysis, templating, code generation, priority scoring |
| `src/Session/`, `src/Progress/`, `src/Database/`, `src/Notifications/`, `src/Tasks/` | Supporting subsystems |
| `resources/schemas/` | JSON schemas validating the on-disk state |
| `tests/Unit/`, `tests/Integration/` | PHPUnit suites |

[docs/architecture.md](docs/architecture.md) explains how these fit together and how to
add a new command.

## Coding standards

- **PSR-12** formatting and **PSR-4** autoloading under the `ClaudeProjectManager\`
  namespace. A file's path must match its namespace.
- **`declare(strict_types=1);`** at the top of every PHP file.
- Type declarations on every parameter, return value and property. Use union types
  rather than omitting a type.
- Class names `PascalCase`, methods and variables `camelCase`, constants
  `UPPER_SNAKE_CASE`. Command classes end in `Command`.
- A docblock on every public method describing what it does, its parameters and its
  return value.
- Keep functions under roughly 50 lines and files under roughly 500. These are the
  limits CPM enforces on its own users, so they apply here too.
- Throw specific exceptions, catch them where they can be handled, and log rather than
  silently swallow.
- No secrets, tokens or absolute paths from your own machine in committed code, tests
  or fixtures.

## Running the tests

```bash
composer test          # or: vendor/bin/phpunit
```

Run a single suite or a single test while developing:

```bash
vendor/bin/phpunit --testsuite Unit
vendor/bin/phpunit --filter IssueCommandTest
```

The suite must pass before a pull request is merged. The CI workflow runs it against
PHP 8.1, 8.2, 8.3, 8.4 and 8.5, plus `composer validate --strict` and a `php -l` lint pass
over `src/` and `bin/`.

## Branches and pull requests

1. Fork the repository and create a branch off `main`. Use a descriptive name:
   `fix/issue-archive-race`, `feat/python-analyser-decorators`,
   `docs/configuration-reference`.
2. Keep a pull request focused on one change. Unrelated refactoring belongs in its own
   pull request.
3. **Pull requests are expected to include tests.** A bug fix should come with a test
   that fails before the fix and passes after it; a new feature should come with tests
   covering its main path and its error handling. If a change is genuinely untestable,
   say so in the description and explain why.
4. Update the documentation in the same pull request when behaviour changes, and add a
   line to the `## [Unreleased]` section of [CHANGELOG.md](CHANGELOG.md).
5. Fill in the pull request template. Describe what changed, why, and how you verified
   it.
6. Be prepared to rebase on `main` and to respond to review comments.

## Commit messages

Use a short imperative subject line of 72 characters or fewer, optionally prefixed with
a scope:

```
issue: archive closed issues inside the transaction lock

The archive action read issues.json outside the exclusive lock, so a
concurrent `issue add` could be dropped. Move the read inside the
existing transaction and add an integration test for the race.
```

Separate the subject from the body with a blank line and explain *why* in the body.
Reference issues with `Fixes #123` or `Refs #123`.

## Reporting a bug

Open an issue using the bug report form. Please include:

- the CPM version (`cpm --version`) and PHP version (`php -v`);
- the operating system;
- the exact command you ran and its complete output, including any stack trace;
- what you expected to happen;
- the output of `cpm verify --json` and `cpm diagnostics` if the problem involves
  project state.

Do not paste the contents of `.cpm/db/` into a public issue without checking it first:
it contains file paths and code metadata from your project.

Security vulnerabilities do not belong in the public tracker. Follow
[SECURITY.md](SECURITY.md).

## Proposing a feature

Open an issue using the feature request form and describe the problem you are trying to
solve before proposing an implementation. Features that require network access, call
external AI services, or add a runtime dependency on a database server are outside the
current scope; CPM is deliberately a local, offline, file-based tool.
