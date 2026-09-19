# Architecture

How CPM is organised: the namespace map, the on-disk state format, the path a command
takes from the shell to its output, and how to add a command of your own.

## Table of contents

- [Overview](#overview)
- [Namespace map](#namespace-map)
- [Request flow](#request-flow)
- [Project root discovery and command registration](#project-root-discovery-and-command-registration)
- [The `.cpm/` state directory](#the-cpm-state-directory)
- [State files](#state-files)
- [JSON schemas](#json-schemas)
- [Concurrency](#concurrency)
- [The analysis pipeline](#the-analysis-pipeline)
- [Context digest generation](#context-digest-generation)
- [Adding a new command](#adding-a-new-command)
- [Testing](#testing)

## Overview

CPM is a Symfony Console application with a JSON file store. There is no database
server, no persistent process other than the optional monitor daemon, and no network
dependency apart from optional Telegram notifications.

```
   shell
     |
     v
 bin/cpm                      entry point, locates the Composer autoloader
     |
     v
 Console\Application          finds the project root, wires services, registers commands
     |
     v
 Commands\*Command            argument parsing, orchestration, output
     |
     +--> Services\*          configuration, logging, permissions, health, concurrency
     +--> Analysis\*          dependency graph, impact, metrics
     +--> Analyzers\*         per-language parsing
     +--> Intelligence\*      patterns, templates, generation, priority scoring
     +--> Session\*           activity tracking, change detection, context building
     +--> Progress\*          metric calculation
     |
     v
 DatabaseManager              locking, schema validation, backup, atomic write
     |
     v
 .cpm/db/*.json               the state on disk
```

## Namespace map

Everything lives under the PSR-4 root `ClaudeProjectManager\`, mapped to `src/`.

| Namespace | Directory | Responsibility |
| --- | --- | --- |
| `ClaudeProjectManager` | `src/` | Top-level services: `ConfigManager`, `DatabaseManager`, `ProjectAnalyzer`, `ProgressTracker`, `SessionManager`, `TaskManager`, `RuleManager`, `Installer`, `AnalysisResult`, `FileAnalysis`, `SessionContext` |
| `ClaudeProjectManager\Console` | `src/Console/` | `Application`, the wiring and registration layer |
| `ClaudeProjectManager\Console\Commands` | `src/Console/Commands/` | Namespaced commands: `context:digest`, `tools:fix-perms` |
| `ClaudeProjectManager\Commands` | `src/Commands/` | The remaining console commands, plus `ErrorHandler` and `JsonResponseTrait` |
| `ClaudeProjectManager\Services` | `src/Services/` | `ConfigManager` (runtime config), `Logger`, `PermissionManager`, `HealthChecker`, `ConcurrentFileHandler`, `AdaptiveTrackingManager`, `StateSummaryGenerator`, `DocumentationCompactor`, `RefactoringTracker` |
| `ClaudeProjectManager\Analysis` | `src/Analysis/` | `DependencyAnalyzer`, `ImpactAnalyzer`, `MetricsCollector`, plus `Calculators/`, `DependencyExtractors/` and `Results/` |
| `ClaudeProjectManager\Analyzers` | `src/Analyzers/` | `PhpAnalyzer`, `PythonAnalyzer`, `JavaScriptAnalyzer`, `ArchitectureAnalyzer` |
| `ClaudeProjectManager\Intelligence` | `src/Intelligence/` | `AiPriorityScorer` |
| `ClaudeProjectManager\Session` | `src/Session/` | `ActivityTracker`, `ChangeDetector`, `ContextBuilder` |
| `ClaudeProjectManager\Progress` | `src/Progress/` | `MetricsCalculator` |
| `ClaudeProjectManager\Database` | `src/Database/` | `FileOperations`, `SchemaValidator`, `SchemaPathResolver`, `ValidationResult` |
| `ClaudeProjectManager\Notifications` | `src/Notifications/` | `TelegramNotifier` |
| `ClaudeProjectManager\Tasks` | `src/Tasks/` | `CodeQualityTask`, `DocumentationTask`, `TypeCheckingTask` |

Two classes share the name `ConfigManager`. `ClaudeProjectManager\ConfigManager` owns
analysis rules, derived paths and environment variables.
`ClaudeProjectManager\Services\ConfigManager` owns `.cpm/config.json` and backs the
`cpm config` command. They are separate concerns with no shared keys; see
[configuration.md](configuration.md).

## Request flow

Take `cpm issue add --title "..." --category bug` as the example.

1. **`bin/cpm`** searches a list of candidate paths for the Composer autoloader — one
   for a global clone, three for the various depths of a `vendor/` installation — and
   fails with a clear message if none is found. It then instantiates
   `Console\Application` and calls `run()`.

2. **`Console\Application::__construct()`** sets the application name and the version
   constant (`Application::VERSION`), then calls `registerCommands()`.

3. **`registerCommands()`** registers the commands that need no project context, walks
   up from the working directory to find the project root, performs a legacy directory
   migration if one is pending, constructs the shared services, and registers the
   project-scoped commands.

4. **Symfony Console** parses `argv`, matches `issue`, binds the definition from
   `IssueCommand::configure()` and calls `execute()`.

5. **`IssueCommand::execute()`** dispatches on the action argument, validates the
   category, priority and status against its class constants, resolves the acting agent
   from `--agent` or `CPM_AGENT`, and calls into `DatabaseManager`.

6. **`DatabaseManager`** acquires the exclusive transaction lock for `issues`, reads
   `.cpm/db/issues.json`, applies the change, validates the result against
   `issues.schema.json`, writes a backup, writes the file atomically and releases the
   lock.

7. **Output** goes back through the command: a `SymfonyStyle` block for a human, or a
   JSON envelope when `--json` was given.

## Project root discovery and command registration

`Application::findProjectRoot()` walks upward from `getcwd()` until it finds a `.cpm`
directory, or a legacy `.claude-project` directory, or reaches the filesystem root. This
is why CPM commands work from anywhere inside a tracked project.

Registration depends on the result:

| Situation | Registered commands |
| --- | --- |
| Always | `docs`, `context:digest`, `tools:fix-perms`, `migrate`, `update` |
| No project root found | the above, plus `start` |
| Project root found | the above, plus `status`, `progress`, `issue`, `verify`, `config`, `ai-context`, `ai-suggest`, `suggest`, `checkpoint`, `repair`, `learn`, `smart-suggest`, `template`, `generate-code`, `auto-test-docs`, `compact`, `diagnostics`, `monitor`, `summary`, `dependencies`, `impact` |

Symfony Console adds `help`, `list` and `completion` on top of these in every case.

A command absent from `cpm list` usually means you are outside an initialised project.

`RefactoringCommand` still exists under `src/Commands/` but is registered nowhere and is
not imported by `Application`. It depends, through `Services\RefactoringTracker`, on an
`InventoryManager` class that is not present in the source tree. Two sibling commands in
the same position, `generate` and `metrics`, were deleted during the open-sourcing work;
see [DECISIONS.md](DECISIONS.md). `refactoring` is not part of the supported command surface, though
`RefactoringTracker` still runs inside progress tracking.

## The `.cpm/` state directory

```
<project>/
├── rules.json           optional, project-level analysis rules (safe to commit)
└── .cpm/
    ├── db/              JSON state files
    │   ├── project.json
    │   ├── inventory.json
    │   ├── progress.json
    │   ├── sessions.json
    │   ├── issues.json
    │   ├── issues_archive.json
    │   ├── checkpoints.json
    │   ├── tasks.json
    │   ├── state_summary.json
    │   ├── dependencies.json
    │   ├── impacts.json
    │   ├── metrics.json
    │   ├── code_generation.json
    │   ├── refactoring_history.json
    │   └── *.txlock          transaction lock sidecars
    ├── context/
    │   └── DIGEST.md         the generated digest
    ├── config/
    │   └── rules.json        per-project analysis rules
    ├── config.json           runtime configuration
    ├── schemas/              JSON schemas published from resources/schemas/
    ├── backups/              rolling backups of db/
    └── logs/                 command and daemon logs
```

`.cpm/` belongs in `.gitignore`. See [SECURITY.md](../SECURITY.md).

## State files

`DatabaseManager` recognises a fixed set of state file names. Reading or writing a name
outside this set is rejected.

| File | Contents | Principal writers |
| --- | --- | --- |
| `project.json` | Project identity, detected type, configuration snapshot, analysis statistics | `start`, `monitor` |
| `inventory.json` | Files, classes and functions discovered by the analysers, with line numbers | `start`, `monitor` |
| `progress.json` | Per-function status, plus `global_stats` with aggregate counts and the completion percentage | `progress`, `monitor` |
| `sessions.json` | Session records and the current session | `start`, `monitor` |
| `issues.json` | Active issues with comments, labels, linked files and attribution | `issue` |
| `issues_archive.json` | Closed issues moved out of the active file | `issue archive` |
| `checkpoints.json` | Light snapshots: aggregate statistics and metadata, not embedded state | `checkpoint` |
| `tasks.json` | Task definitions derived from the rules | `TaskManager` |
| `state_summary.json` | The condensed summary the digest and `summary` read | `summary`, `context:digest` |
| `dependencies.json` | The dependency graph | `dependencies` |
| `impacts.json` | Cached change-impact results | `impact` |
| `metrics.json` | Collected code metrics | the metrics collector |
| `code_generation.json` | History of generated code | `generate-code` |
| `refactoring_history.json` | Detected refactoring operations | `RefactoringTracker` |

Checkpoints deserve a note. They used to embed complete copies of `progress.json`,
`inventory.json` and `sessions.json`, which produced multi-megabyte entries and, in one
observed case, a 124 MB `checkpoints.json`. Since 1.2.0 they store aggregate statistics
only, roughly 3 KB each, bounded by `checkpoints.max_retained`.

## JSON schemas

The schemas in `resources/schemas/` are the contract for the state files. `cpm start`
publishes them into `.cpm/schemas/`, and `Database\SchemaValidator` validates against
them using `justinrainbow/json-schema`. `Database\SchemaPathResolver` locates the schema
directory, which differs between a global clone, a `vendor/` installation and a project's
published `.cpm/schemas/` copy.

```
resources/schemas/
├── project.schema.json
├── inventory.schema.json
├── progress.schema.json
├── sessions.schema.json
├── issues.schema.json
├── checkpoints.schema.json
├── tasks.schema.json
├── state_summary.schema.json
├── dependencies.schema.json
├── impacts.schema.json
├── metrics.schema.json
├── code_generation.schema.json
└── refactoring_history.schema.json
```

Validation behaviour is configurable: `validation.strict` decides how hard the check is,
`validation.fail_on_error` decides whether a failure aborts the command, and
`validation.auto_repair` allows `DatabaseManager` to correct recoverable problems — a
missing required field with a known default, for example — rather than refusing to
write.

Changing a schema is a compatibility change. Existing `.cpm/` directories in the wild
were written against the old one, so a schema change needs either a repair path in
`DatabaseManager` or a note in the changelog telling users to run `cpm repair`.

## Concurrency

Several CPM processes can run against one project at once — a person in a terminal, an
assistant in another, the monitor daemon in the background. Before 1.2.0 this lost
writes: two processes would read the same file, each apply its own change, and the
second write would discard the first.

Every read-modify-write cycle now holds an exclusive lock:

- a `.txlock` sidecar file next to the state file, held with `flock`;
- a 10 second acquisition timeout, after which the command fails rather than proceeding
  unlocked;
- the whole read, modify, validate and write sequence inside the lock, including
  `issue archive`, which moves records between two files.

Reads use a small cache. Because the PHP stat cache and one-second `mtime` granularity
could both serve stale data, the cache calls `clearstatcache()` and compares file size as
well as modification time.

`cpm diagnostics` inspects lock state and file access when concurrency is suspect.

## The analysis pipeline

`cpm start` and `cpm monitor` both run this sequence.

1. **Discovery.** `ProjectAnalyzer` walks the project, applying the inclusion patterns,
   then the exclusion patterns, then `analysis.max_scan_depth`.
2. **Type detection.** The mix of extensions determines the project type recorded in
   `project.json`.
3. **Parsing.** Each file is handed to the analyser its extension maps to.
   `PhpAnalyzer` uses `nikic/php-parser` over a real AST. `PythonAnalyzer` and
   `JavaScriptAnalyzer` use pattern-based extraction. A file with no mapped analyser is
   inventoried without function-level detail.
4. **Inventory.** Files, classes and functions are written to `inventory.json` with
   their line ranges.
5. **Progress reconciliation.** `ProgressTracker` merges the new inventory with existing
   progress: statuses of functions that still exist are preserved, new functions enter
   as `pending`, and functions that have disappeared are removed.
6. **Metrics and graphs.** `MetricsCollector`, `DependencyAnalyzer` and `ImpactAnalyzer`
   fill `metrics.json`, `dependencies.json` and `impacts.json`.
7. **Session record.** `ActivityTracker` and `SessionManager` record what the run did.

`cpm start --skip-incremental` forces a full re-analysis; by default, unchanged files are
skipped using `Session\ChangeDetector`.

## Context digest generation

`ContextDigestCommand` is deliberately simple. It resolves `paths.context` and
`paths.database` from the root `ConfigManager`, reads the state files, and renders
markdown plus a merged JSON payload into the output directory (`--out-dir`, default
`.cpm/context`).

The digest is ordered by what a session actually needs first:

1. **Journal** — in-progress and blocked issues, the top ten open issues by priority,
   the last three checkpoints.
2. **Function-level detail** — rendered only when `tracking.enabled` is true and the
   analysis is newer than `tracking.max_age_days`. Otherwise the digest prints an
   explicit stale notice with instructions to re-run `cpm start`.

That second rule exists because a digest that presents month-old numbers as current is
worse than one that admits it does not know. `AdaptiveTrackingManager` and
`AiPriorityScorer` decide which files are worth listing and in what order.

## Adding a new command

1. **Create the class** in `src/Commands/`, extending
   `Symfony\Component\Console\Command\Command`:

```php
<?php

declare(strict_types=1);

namespace ClaudeProjectManager\Commands;

use ClaudeProjectManager\DatabaseManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Report the ten largest files in the inventory.
 */
class LargestFilesCommand extends Command
{
    use JsonResponseTrait;

    protected static $defaultName = 'largest-files';
    protected static $defaultDescription = 'List the largest files in the inventory';

    public function __construct(private DatabaseManager $database)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'How many files to list', '10')
             ->addOption('json', null, InputOption::VALUE_NONE, 'Output JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $limit = (int) $input->getOption('limit');
        $inventory = $this->database->read('inventory');

        // ... build $rows from $inventory ...

        if ($input->getOption('json')) {
            $this->outputJsonSuccess($output, 'largest-files', ['files' => $rows]);
            return Command::SUCCESS;
        }

        (new SymfonyStyle($input, $output))->table(['File', 'Lines'], $rows);

        return Command::SUCCESS;
    }
}
```

2. **Register it** in `src/Console/Application.php`. Add the `use` statement, then the
   `$this->add(...)` call in the branch that matches your command's needs: the
   always-registered block if it works without project state, or the project block if it
   reads `.cpm/`.

3. **Inject, do not construct.** `Application` already builds `DatabaseManager`,
   `ConfigManager`, `ProjectAnalyzer`, `ProgressTracker`, `SessionManager`,
   `ActivityTracker`, `TelegramNotifier`, `MetricsCalculator`, `StateSummaryGenerator`,
   `DependencyAnalyzer` and `ImpactAnalyzer`. Take what you need as a constructor
   argument. This is what keeps the commands testable.

4. **Support `--json`** by using `JsonResponseTrait`, which emits the standard
   `{success, action, timestamp, data}` envelope. New commands should use it.

5. **Never write a state file directly.** Go through `DatabaseManager` so that locking,
   schema validation and backups apply.

6. **Write the test** in `tests/Unit/Commands/`, using Symfony's `CommandTester` and a
   mocked `DatabaseManager`.

7. **Document it** in the README command table, in `docs/usage.md`, and in
   `HelpCommand`, which is what `cpm docs` prints for an assistant.

## Testing

```bash
composer install            # PHPUnit comes from require-dev
vendor/bin/phpunit
vendor/bin/phpunit --testsuite Unit
vendor/bin/phpunit --filter IssueCommandTest
```

Two suites are configured in `phpunit.xml`:

| Suite | Directory | Scope |
| --- | --- | --- |
| `Unit` | `tests/Unit/` | Individual classes with mocked collaborators |
| `Integration` | `tests/Integration/` | Real state files in a temporary directory, including the concurrency test that proves parallel `issue add` calls lose no updates |

The configuration is strict: warnings and risky tests fail the run, and output during a
test is an error. Write assertions rather than `echo`.
