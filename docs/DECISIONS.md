# Decision log: open-sourcing CPM

This file records the decisions taken while preparing Claude Project Manager for public
release, and why each one was taken. It is written for whoever maintains the project
next, so that a choice that looks arbitrary can be re-examined on its merits rather than
guessed at.

Each entry states the decision, the reasoning, and what would justify revisiting it.

## Table of contents

- [D-001: Fresh git history](#d-001-fresh-git-history)
- [D-002: Drop the internal planning documents](#d-002-drop-the-internal-planning-documents)
- [D-003: MIT licence](#d-003-mit-licence)
- [D-004: Placeholder organisation and package names](#d-004-placeholder-organisation-and-package-names)
- [D-005: Remove the duplicate legacy TelegramNotifier](#d-005-remove-the-duplicate-legacy-telegramnotifier)
- [D-006: Remove the stale root schemas directory](#d-006-remove-the-stale-root-schemas-directory)
- [D-007: Keep Telegram as an optional integration](#d-007-keep-telegram-as-an-optional-integration)
- [D-008: Rename and rewrite the manuals](#d-008-rename-and-rewrite-the-manuals)
- [D-009: Contact addresses](#d-009-contact-addresses)
- [D-010: Delete unloadable dead code rather than reconstruct it](#d-010-delete-unloadable-dead-code-rather-than-reconstruct-it)
- [D-011: Raise the PHP requirement to 8.1](#d-011-raise-the-php-requirement-to-81)
- [D-012: Remove the `version` field from composer.json](#d-012-remove-the-version-field-from-composerjson)
- [D-013: Pin PHPUnit to patched releases](#d-013-pin-phpunit-to-patched-releases)
- [D-014: `bin/cpm` is a real PHP wrapper, not a symlink](#d-014-bincpm-is-a-real-php-wrapper-not-a-symlink)
- [D-015: Move the deploy script and stop it editing shell profiles](#d-015-move-the-deploy-script-and-stop-it-editing-shell-profiles)
- [D-016: Resolve the schema directory explicitly](#d-016-resolve-the-schema-directory-explicitly)
- [D-017: Document from `cpm list`, not from the source tree](#d-017-document-from-cpm-list-not-from-the-source-tree)
- [D-018: Corporate commit authorship, single initial commit](#d-018-corporate-commit-authorship-single-initial-commit)
- [D-019: Clean the shipped rule and environment templates](#d-019-clean-the-shipped-rule-and-environment-templates)
- [D-020: No side effects before a command writes](#d-020-no-side-effects-before-a-command-writes)
- [D-021: Remove the unreachable `refactoring` command](#d-021-remove-the-unreachable-refactoring-command)
- [Known limitations](#known-limitations)
- [Before you publish](#before-you-publish)

## D-001: Fresh git history

**Decision.** The public repository starts from a single initial commit. The 148 commits
of private history are not imported, and no attempt is made to rewrite them.

**Reasoning.** The private history contains references to internal infrastructure,
private host names and paths, and work on unrelated projects that happened to share the
repository. Rewriting it would mean filtering every commit message, every diff and every
file that ever existed on any branch — and a single missed blob remains permanently
retrievable through the object store, even after the branch that contained it is gone.
Scrubbing history is the kind of task where being 99 percent right is indistinguishable
from being wrong. Starting fresh is cheap, verifiable by inspection, and loses only
archaeology that no external contributor would use.

**What is lost.** `git blame` and `git log` begin at the open-source release. The
changelog preserves the release history, which is the part that has practical value.

**Revisit if.** Never, in practice. Once the public repository has contributors, its own
history is the history.

## D-002: Drop the internal planning documents

**Decision.** `docs/history/` — the internal planning, phase and status documents — is
not published.

**Reasoning.** Those documents describe an internal roadmap, priorities and deployment
context that do not apply to anyone outside the original setup. They reference private
infrastructure, and they describe decisions in terms of a specific environment. For an
external reader they would be noise at best and misleading at worst, because a reader
cannot tell which parts still apply.

**What replaces them.** The changelog covers what shipped and when. This file covers why
the public release looks the way it does. `docs/architecture.md` covers how the code is
organised, which is what the planning documents were most often consulted for.

**Revisit if.** A specific historical document turns out to explain a design that is
otherwise inexplicable. In that case, extract the explanation into `architecture.md`
rather than publishing the document.

## D-003: MIT licence

**Decision.** MIT, with the copyright line `Copyright (c) 2026 WASU International Ltd`,
replacing the original `"license": "Perpetual"` declaration.

**Reasoning.** "Perpetual" is not a recognised SPDX identifier and not a licence anyone
can act on. Composer, GitHub and every corporate licence scanner would flag it, and a
potential user would have no way to determine what they are permitted to do. A tool of
this kind gains value through adoption, so the licence should be the one that creates the
fewest obstacles. MIT is permissive, short, universally understood, and compatible with
every dependency the project has (Symfony components under MIT, `nikic/php-parser` under
BSD-3-Clause, `monolog/monolog`, `vlucas/phpdotenv` and `justinrainbow/json-schema` under
MIT).

**Consequences.** The `license` field in `composer.json` must read `MIT`. Contributions
are accepted under the same terms; there is no contributor licence agreement.

**Revisit if.** The project needs a copyleft guarantee, or a trademark policy. Changing
the licence later requires the agreement of every contributor, so this is a decision to
get right now rather than later.

## D-004: Organisation and package names

**Decision.** The public repository is `wasuint/CPM` on GitHub
(https://github.com/wasuint/CPM). The Composer package name is `wasuint/cpm`, in lower
case, because Composer package names must be lower case.

**Reasoning.** During preparation the organisation was not yet decided, so every
occurrence read `your-org/claude-project-manager`: an obvious placeholder that a single
search could find. At publication the maintaining company's GitHub organisation,
`wasuint`, was chosen. That matches the copyright holder in `LICENSE` and the `authors`
block in `composer.json`, and keeps the repository free of personal accounts (D-018).

**What this affects.** README installation commands, `docs/installation.md`,
`CONTRIBUTING.md`, the `CHANGELOG.md` links, `.github/ISSUE_TEMPLATE/config.yml`, the
bug-report link in `Database\SchemaValidator`, and the `name` field in `composer.json`.

**Revisit if.** The package is registered on Packagist under a different vendor name.

## D-005: Remove the duplicate legacy TelegramNotifier

**Decision.** The legacy `src/TelegramNotifier.php` is removed.
`ClaudeProjectManager\Notifications\TelegramNotifier` is the only implementation.

**Reasoning.** Two classes with the same name in different namespaces implemented the
same feature, and only the `Notifications\` one was wired into `Console\Application`. The
root-level copy was dead code that had drifted from the live version. A new contributor
reading `src/` would have had no way to tell which one mattered, and a plausible chance
of fixing a bug in the wrong file.

**Revisit if.** Never. If a second notification channel is added, it belongs in
`Notifications\` beside the first, behind a shared interface.

## D-006: Remove the stale root schemas directory

**Decision.** The root `schemas/` directory is removed. `resources/schemas/` is the
single source of the JSON schemas.

**Reasoning.** The same problem as D-005, with a sharper edge: the two directories had
diverged, and schemas are a contract. `composer.json` publishes `resources/schemas`
through its `extra.claude.publish` configuration, and `cpm start` copies from there into
`.cpm/schemas/`. The root copy was consulted by nothing and validated nothing, but it
looked authoritative. A contributor editing the wrong copy would have seen no effect and
no error.

**Revisit if.** Never. One contract, one location.

## D-007: Keep Telegram as an optional integration

**Decision.** The Telegram notification feature stays in the public release, documented
neutrally as an optional integration, disabled by default.

**Reasoning.** It was tempting to strip it, since the original use of it was tied to a
private setup. But the feature itself is generic — a bot token, a chat ID and a set of
events worth a message — and it is genuinely useful for a long-running analysis or a
monitor daemon on a remote machine. Removing a working feature because of how it happened
to be used is the wrong reason. What needed removing was the private configuration, not
the capability.

**How it is presented.** `docs/configuration.md` documents it as one optional
integration among the configuration keys: how to enable it, which credentials it needs,
what the messages contain, and how to turn it off. The default is off, and CPM makes no
network request unless it is explicitly enabled. `SECURITY.md` notes that the token lives
in `.cpm/.env` and must not be committed.

**Revisit if.** A second channel is wanted. At that point the notifier should be refactored
behind an interface rather than special-cased.

## D-008: Rename and rewrite the manuals

**Decision.** `docs/INSTALLATION_MANUAL.md`, `docs/USAGE_MANUAL.md` and
`docs/CPM_TROUBLESHOOTING.md` become `docs/installation.md`, `docs/usage.md` and
`docs/troubleshooting.md`, and their contents are rewritten rather than edited.

**Reasoning.** Two problems. The names were shouting and inconsistent — one file carried
a redundant `CPM_` prefix, all three used a `_MANUAL` suffix that says nothing — and
lowercase names match the convention of every other file in `docs/`. More importantly the
contents assumed a specific machine: a specific user name in `chown` commands, specific
absolute paths, specific host names. Patching those out one by one risks leaving a
reference behind and produces prose that reads as though something was cut from it. A
rewrite against the actual source was both safer and shorter.

**What was verified.** Every documented command, option and action was checked against
`src/Commands/` and `src/Console/Application.php`. Commands that exist as classes but are
not registered are not documented as available — see D-010.

## D-009: Contact addresses

**Decision.** Both `CODE_OF_CONDUCT.md` and `SECURITY.md` direct reports to
`hello@wasu.eu`, the monitored address of the maintaining company.

**Reasoning.** Both documents are worthless without a working address, and both are worse
than worthless with a wrong one: a security report sent to an address nobody reads is a
vulnerability disclosed and not fixed. The two documents share one address deliberately,
because a single monitored inbox is more reliable than two aliases of which one is
forgotten.

**Revisit if.** Report volume makes a shared inbox impractical, or GitHub private
vulnerability reporting is enabled, at which point `SECURITY.md` should point at that
instead and keep the address only as a fallback.

## D-010: Delete unloadable dead code rather than reconstruct it

**Decision.** The `generate` and `metrics` commands were deleted, together with
`src/Intelligence/CodeGenerationEngine.php`, `src/Intelligence/PatternAnalyzer.php`,
`src/Intelligence/TemplateManager.php` and `tests/Intelligence/`.

**Reasoning.** All of it depended on a class, `ClaudeProjectManager\InventoryManager`,
that does not exist anywhere in the repository. The two commands were already
unregistered in `Console\Application` behind "temporarily disabled" comments, so they had
never appeared in `cpm list`, and instantiating either would have raised a fatal error.

There were three options: reconstruct `InventoryManager` from the call sites, ship the
classes as they were, or delete them. Reconstruction means inventing behaviour and
calling it a restoration, with no test that says whether the invention is right. Shipping
them means a reader of `src/` cannot tell working code from code that fatals on
instantiation, which is worse for a newcomer than a smaller feature set: fewer features
are a known quantity, while landmines are not. Deletion leaves a repository where
everything present loads and runs.

**What was kept.** `Intelligence\AiPriorityScorer` stays; it is used by
`StatusCommand --ai-priority` and by the digest, and it has no dependency on the missing
class. The `template`, `learn`, `generate-code` and `auto-test-docs` commands stay: they
were verified to load and execute against a scratch project without touching the deleted
classes.

**Candidate for reinstatement.** A future contributor who wants code-quality metrics or
the richer generation engine should treat this as a green field rather than an archaeology
problem: write `InventoryManager` to a specification, with tests, and reintroduce the
commands on top of it. The deleted files remain in the private history that preceded this
repository, but per D-001 that history is not published, so there is nothing to recover
and nothing to be constrained by.

**Still outstanding.** `RefactoringCommand` and `Services\RefactoringTracker` are in the
same position — `RefactoringTracker` needs `InventoryManager`, and `RefactoringCommand` is
now imported by nothing at all — but were left in place. See
[Known limitations](#known-limitations).

## D-011: Raise the PHP requirement to 8.1

**Decision.** `composer.json` requires `php: >=8.1`, replacing `>=7.4`.

**Reasoning.** The declared constraint was simply false. `src/` uses typed properties,
constructor property promotion, `match` expressions, `mixed` and enum-style union types
throughout; none of it runs on 7.4, and the installation would have failed at the first
command rather than at install time, which is the worst place to discover a version
problem. 8.1 is also the oldest version any of the dependencies support, and PHP 7.4 has
been out of security support for years. The CI matrix covers 8.1 through 8.4.

## D-012: Remove the `version` field from composer.json

**Decision.** The `"version": "1.3.1"` key was removed from `composer.json`.

**Reasoning.** Composer infers the version from the VCS tag, and recommends against
declaring it in the file precisely because the two drift: the file is edited by hand and
the tag is not, so a release ends up claiming two different versions. The authoritative
version for the CLI remains `Console\Application::VERSION`, which is what `cpm --version`
prints; the authoritative version for the package is the git tag.

**Consequence.** Releasing means tagging. See [Before you publish](#before-you-publish).

## D-013: Pin PHPUnit to patched releases

**Decision.** `require-dev` pins `phpunit/phpunit` to `^9.6.33|^10.5.62` rather than the
previous `^9.0|^10.0`.

**Reasoning.** The open ranges resolved to releases affected by a published security
advisory. The constraint keeps both major lines available — the suite runs on either —
while excluding the affected patch releases. Pinning the floor rather than dropping a
major line keeps the test suite runnable on the older PHP versions in the CI matrix.

## D-014: `bin/cpm` is a real PHP wrapper, not a symlink

**Decision.** `bin/cpm` is a PHP file in its own right, rather than a symlink to
`bin/claude-project`.

**Reasoning.** A checked-in symlink does not survive every way a repository is obtained.
Git on Windows without developer mode materialises it as a text file containing a path,
Composer's zip-based `--prefer-dist` installs can flatten it, and some archive and
deployment tools do the same. The result is a `cpm` that is not executable and an error
message that does not explain why. A real file costs a few duplicated lines and works
everywhere.

## D-015: Move the deploy script and stop it editing shell profiles

**Decision.** `deploy.sh` moved from the repository root to `scripts/deploy.sh`, and it no
longer appends to the user's shell profile.

**Reasoning.** Two separate problems. A script at the repository root reads as part of the
public interface, which for a Composer package it is not; `scripts/` says what it is. More
importantly, a tool that writes to `~/.bashrc` on the user's behalf is doing something the
user did not ask for, in a file they own, with no record of what changed and no way to undo
it. `docs/installation.md` now prints the `PATH` line for the user to add themselves, or
offers a symlink into `/usr/local/bin` as the alternative. `scripts/` is marked
`export-ignore`, so it is not shipped in the distributed package.

## D-016: Resolve the schema directory explicitly

**Decision.** A new class, `src/Database/SchemaPathResolver.php`, locates the JSON schema
directory, fixing a bug in `.cpm/schemas` resolution.

**Reasoning.** The schema directory can be in three different places depending on how CPM
was installed: `resources/schemas/` in a global clone, the same path several directory
levels deeper under `vendor/`, or the copy published into the tracked project's
`.cpm/schemas/`. The previous code guessed, and guessed wrong for at least one of the
three, so validation silently did nothing instead of failing loudly. Since the schemas are
the contract for every state file, validation that does not run is worse than no
validation, because the state looks checked. One class with one responsibility makes the
resolution testable.

## D-017: Document from `cpm list`, not from the source tree

**Decision.** The command reference in `README.md` and `docs/usage.md` is derived from what
`php bin/cpm list` actually registers, cross-checked against
`Console\Application::registerCommands()`, and not from the contents of `src/Commands/`.

**Reasoning.** The two disagree. `src/Commands/` contains classes that are registered
nowhere, and `Console\Application` registers two commands that live in a different
namespace. Documenting the directory listing would have advertised commands that do not
appear in `cpm list`, which a user reads as a broken installation rather than as an
undocumented limitation.

**How to maintain it.** When a command is added or removed, regenerate the reference from
`cpm list --raw` rather than from the directory. Note that the registered set depends on
the working directory: outside an initialised project only `docs`, `context:digest`,
`tools:fix-perms`, `migrate`, `update` and `start` appear, plus Symfony's own `help`,
`list` and `completion`.

## D-018: Corporate commit authorship, single initial commit

The history is a single commit authored as
`WASU International Ltd <hello@wasu.eu>`, set through the repository-local git
configuration rather than the machine's global identity.

Two requirements shaped this. The release was prepared on the condition that no
personal data about the individual author appear anywhere in the published
repository, and the git author field is published data like any other. The
repository was also to start with a clean history rather than carry the
preparation work as a sequence of commits, since that sequence documents the
open-sourcing process and not the software. The decision log is the appropriate
place for that record, and it is checked in.

Attributing the initial commit to the company rather than to an individual also
matches the copyright line in `LICENSE` and the `authors` block in
`composer.json`, so all three agree on who owns the work.

Contributions from this point forward are authored normally, by whoever writes
them.

## D-019: Clean the shipped rule and environment templates

`config/rules.json` carried an exclusion pattern (`IBJts/**/*`) belonging to a
private codebase of the original author, `templates/.env.example` defaulted the
notification timezone to a specific European city, and `templates/env.example`
still pointed `DATABASE_PATH` at the pre-1.1 `.claude-project` directory.

All three were corrected: the private pattern was removed, the timezone default
is now `UTC`, and `DATABASE_PATH` defaults to `.cpm`. Shipped defaults are
visible to every user of the tool, so they must be neutral and current.

## D-020: No side effects before a command writes

Running any invocation — including `cpm --version`, `cpm --help` and `cpm list` —
created a `.cpm/` directory in the current working directory and printed
`Failed to load current session: Database file not found: .../.cpm/db/sessions.json`.
Running CPM once from a home directory left state behind there.

Three construction-time side effects caused it, and all three were removed:

- `Database\SchemaValidator` wrote default schema files from its constructor whenever
  the schema directory was absent. The eager call is gone; `loadSchema()` already falls
  back to the schemas bundled in `resources/schemas`, and `cpm start` publishes the
  full set.
- `DatabaseManager` created the database directory in its constructor. Creation moved
  to first write.
- `SessionManager` logged a failure whenever no project existed. A dedicated
  `Database\DatabaseFileNotFoundException` now distinguishes "this project has not been
  initialised" from a genuine read failure, and only the former is silent.

The rule this establishes: a command may touch the filesystem when the user asks it to
do work, and not before. Bootstrapping, help and version output must leave no trace. A
tool that litters a directory merely for being asked its version will not be trusted
with a repository.

## D-021: Remove the unreachable `refactoring` command

`src/Commands/RefactoringCommand.php` was registered nowhere in
`src/Console/Application.php`, so no user could invoke it. It was removed for the same
reason as `generate` and `metrics` (see D-010).

`Services\RefactoringTracker` was deliberately kept. Unlike the command wrapper it is
live code: `ProgressTracker` instantiates it and calls
`recordRefactoringOperations()`, and it still populates `refactoring_history.json`.
Only the unreachable entry point was deleted, not the feature behind it.

## Known limitations

Things that are true of this release and that a contributor should know before being
surprised by them. None is a blocker; each is a reasonable first contribution.

- **Roughly 40 `@` error-suppression operators remain in `src/`.** They were not swept,
  because removing suppression blindly is unsafe: some of them hide a warning the
  surrounding code genuinely handles by checking the return value, and removing those
  turns a working path into a noisy or fatal one. Each occurrence needs reading in
  context and replacing with an explicit check. Worth doing incrementally, with a test
  per site, rather than in one pass.
- **JSON output is not uniform.** Commands using `JsonResponseTrait` emit
  `{success, action, timestamp, data}`; `status` emits its own structure. Converging them
  is a breaking change for anyone scripting against the current shape, so it belongs in a
  major release. `docs/usage.md` documents the difference in the meantime.

## Before you publish

A checklist for the maintainer preparing the first public release.

### Names and identity

- [x] Replace `your-org` with the real GitHub organisation or user throughout:
      `README.md`, `CHANGELOG.md` (comparison links), `CONTRIBUTING.md`,
      `docs/installation.md`, `docs/DECISIONS.md` and
      `.github/ISSUE_TEMPLATE/config.yml`.
      Verify with `grep -rn 'your-org' .`
- [x] Set the Composer package name in `composer.json`: it is `wasuint/cpm` (D-004).
- [ ] Register `wasuint/cpm` on Packagist, so that `composer require --dev wasuint/cpm`
      works as the README describes.
- [x] The copyright holder in `LICENSE` is `WASU International Ltd`, and the `authors`
      block in `composer.json` names the same company with `hello@wasu.eu`.
- [x] `composer.json` declares `"license": "MIT"`, replacing the original
      `"Perpetual"`, and its `authors` block names WASU International Ltd.
- [x] `composer.json` requires `php: >=8.1`, matching the code and the CI matrix. The
      original declared `>=7.4`, which the typed properties and `match` expressions in
      `src/` contradicted.

### Contacts

- [x] `CODE_OF_CONDUCT.md` and `SECURITY.md` both direct reports to `hello@wasu.eu`.
- [ ] Consider enabling GitHub private vulnerability reporting once the repository
      exists, and point `SECURITY.md` at that as the preferred channel, keeping
      `hello@wasu.eu` as the fallback.

### Repository settings

- [ ] Enable GitHub Actions and confirm the CI workflow runs green on PHP 8.1 through
      8.4.
- [ ] Enable Dependabot alerts and security updates.
- [ ] Enable private vulnerability reporting under Settings, Security.
- [ ] Enable Discussions, or remove the Discussions link from
      `.github/ISSUE_TEMPLATE/config.yml`.
- [ ] Protect `main`: require the CI check to pass, and require a pull request for
      changes.
- [ ] Set the repository description and topics (`php`, `cli`, `ai`, `developer-tools`,
      `project-management`).

### Code

- [x] The `InventoryManager` question is resolved for `generate` and `metrics` (D-010):
      both commands and the three unloadable `Intelligence` classes were deleted, and the
      dead imports in `Console\Application` are gone.
- [x] `RefactoringCommand` was removed and `Services\RefactoringTracker` kept as live code (D-021).
- [x] Fix the stray `.cpm/` directory created by any command run outside a project.
      `start` is registered lazily when no project exists, and `context:digest` refuses
      to write outside a project (D-020). `tests/Integration/NoProjectSideEffectsTest.php`
      guards both.
- [x] `composer.json` defines `scripts.test`, `scripts.test:unit`, `scripts.test:integration`
      and `scripts.lint`, so `composer test` works as `CONTRIBUTING.md` describes.
- [ ] Confirm `symfony/console ^5.0|^6.0` behaves on PHP 8.4. The CI matrix covers it;
      widening the constraint to `^7.0` may be worthwhile once that is verified.
- [x] `config/rules.json` was reviewed at publication; the author-specific `download/**/*`
      exclusion was removed.
- [ ] Run the test suite on every PHP version in the CI matrix.

### Final check

- [x] Search the whole tree for private references — personal names, email addresses,
      host names, private IP ranges, absolute paths from the author's machine — before
      the first push.
- [x] Confirm `.gitignore` excludes `.cpm/`, `.claude-project/`, `vendor/` and `.env`.
- [x] Confirm no `.cpm/` directory is staged.
- [x] Tag the release: `git tag -a v1.3.1 -m "First public release"` and push the tag.
      The tag is now the authoritative package version, since `composer.json` no longer
      declares one (D-012). Keep it in step with `Console\Application::VERSION`.
- [x] Create the GitHub release from the tag, using the 1.3.1 changelog section as the
      release notes, and move the `## [Unreleased]` open-sourcing entries into it if you
      prefer to describe this as a new version instead.
