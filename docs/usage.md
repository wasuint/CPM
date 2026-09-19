# Usage

Day-to-day use of Claude Project Manager: the commands, the workflows they support, and
how an AI assistant is meant to consume the state CPM keeps.

## Table of contents

- [The model in one page](#the-model-in-one-page)
- [Starting and inspecting a project](#starting-and-inspecting-a-project)
- [Tracking progress](#tracking-progress)
- [Issue tracking](#issue-tracking)
- [Checkpoints](#checkpoints)
- [Context for AI assistants](#context-for-ai-assistants)
- [Monitoring](#monitoring)
- [Analysis commands](#analysis-commands)
- [Code generation and templates](#code-generation-and-templates)
- [Maintenance commands](#maintenance-commands)
- [Working session walkthrough](#working-session-walkthrough)
- [Scripting and CI](#scripting-and-ci)
- [Practices worth adopting](#practices-worth-adopting)

## The model in one page

CPM keeps four kinds of state in `.cpm/db/`:

| State | File | Written by |
| --- | --- | --- |
| Project metadata and analysis statistics | `project.json` | `start`, `monitor` |
| File and function inventory | `inventory.json` | `start`, `monitor` |
| Per-function status | `progress.json` | `progress`, `monitor` |
| Session history | `sessions.json` | `start`, `monitor` |
| Issues | `issues.json`, `issues_archive.json` | `issue` |
| Dependency graph, impact, metrics, state summary, generation history | `dependencies.json`, `impacts.json`, `metrics.json`, `state_summary.json`, `code_generation.json`, `refactoring_history.json` | the analysis commands |

Every command reads and writes these files under an exclusive transaction lock, so
several CPM invocations — including ones started by different assistants — can run
against the same project without losing writes.

Almost every command accepts `--json`. The shape of that JSON is not uniform across
the whole CLI. Commands that use `JsonResponseTrait` — `verify`, `repair`, `monitor`,
`checkpoint`, `ai-context`, `ai-suggest`, `smart-suggest`, `learn`, `template`,
`generate-code`, `auto-test-docs` — emit the envelope
`{success, action, timestamp, data}`. `issue` emits the same envelope, with the legacy
top-level keys retained for compatibility. Others, notably `status`, emit a
command-specific object. Inspect the output of the command you intend to script before
relying on a particular key.

## Starting and inspecting a project

```bash
cd ~/projects/my-app
cpm start                      # analyse and begin tracking
cpm start --force              # re-analyse from scratch
cpm start --quick              # skip deep parsing
cpm start --fix-permissions    # repair permissions while starting
```

```bash
cpm status                     # headline status
cpm status --detailed          # function-level breakdown
cpm status --history           # recent session history
cpm status --health            # health indicators
cpm status --file=src/Order/TotalCalculator.php
cpm status --top=10            # the ten files needing most attention
cpm status --focus             # only what is in progress
cpm status --ai-priority       # ordered by the priority scorer
cpm status --json
```

`cpm summary` is the lighter alternative when you want a short overview rather than a
full status pass:

```bash
cpm summary
cpm summary --lightweight
cpm summary --refresh          # recompute instead of using the cached summary
cpm summary --json
```

## Tracking progress

Progress is recorded per function. `cpm progress` takes an action and usually a target.

```bash
cpm progress show
cpm progress pending                                  # what is still outstanding
cpm progress mark-complete src/Order/TotalCalculator.php
cpm progress mark-function src/Order/TotalCalculator.php --status=completed
cpm progress mark-by-name calculateTotal --status=verified
cpm progress mark-range src/Order --status=in_progress
cpm progress bulk-mark --status=completed
cpm progress validate                                 # check the data against the schema
cpm progress repair                                   # repair what validation found
cpm progress verify
cpm progress reset                                    # clear progress, keep the inventory
```

| Action | Purpose |
| --- | --- |
| `show` | Print current progress |
| `pending` | List functions not yet completed |
| `mark-complete` | Mark a target complete |
| `mark-function` | Set the status of one function |
| `mark-by-name` | Set status by function name rather than path |
| `mark-range` | Set status across a path or range |
| `bulk-mark` | Apply a status to many targets at once |
| `validate` | Validate `progress.json` against its schema |
| `repair` | Repair validation failures |
| `verify` | Cross-check progress against the inventory |
| `reset` | Clear progress data |

Valid statuses are `pending`, `in_progress`, `completed`, `verified` and `has_issues`.

Useful options: `--status=`, `--reason=`, `--confidence=`, `--pattern=`, `--dry-run`,
`--force`, `--auto-fix`, `--debug`, `--json`.

`--dry-run` shows what a bulk operation would change without writing. `--force` skips
validation when the data is in a state you intend to repair afterwards.

## Issue tracking

```bash
# Create
cpm issue add --title "Order totals ignore discounts" --category bug --priority high
cpm issue add --title "Add CSV export" --category feature \
              --description-file ./notes/csv-export.md

# Read
cpm issue list
cpm issue list --status open --priority high
cpm issue list --category bug --limit 20
cpm issue show MY-BUG-001

# Update
cpm issue update MY-BUG-001 --status in_progress --assignee alice
cpm issue comment MY-BUG-001 --text "Reproduced on the 2026-09 dataset."
cpm issue link MY-BUG-001 src/Order/TotalCalculator.php
cpm issue close MY-BUG-001 --resolution "Discount rows were excluded from the subtotal."
cpm issue reopen MY-BUG-001 --reason "Regression in the nightly build."

# Housekeeping
cpm issue export --json > issues.json
cpm issue archive --older-than 30 --dry-run
cpm issue archive --before 2026-01-01
```

| Field | Allowed values |
| --- | --- |
| Category | `hardware`, `software`, `documentation`, `feature`, `bug`, `task`, `other` |
| Priority | `critical`, `high`, `medium`, `low` |
| Status | `open`, `in_progress`, `blocked`, `resolved`, `closed` |

Category aliases `hw`, `sw`, `doc`, `docs`, `feat` and `fix` are accepted, as are the
status aliases `in-progress`, `wip` and `done`.

Long text can come from a file or stdin rather than a shell argument, which matters when
an assistant generates a multi-paragraph description:

```bash
cpm issue add --title "Rework the importer" --description-file ./plan.md
cpm issue close MY-BUG-001 --resolution=-  < ./resolution.txt
cpm issue comment MY-BUG-001 --text-file ./review-notes.md
```

Mutations record who made them. Pass `--agent` explicitly, or set `CPM_AGENT` in the
environment and let every command pick it up:

```bash
export CPM_AGENT="assistant-a"
cpm issue update MY-BUG-001 --status blocked
```

## Checkpoints

A checkpoint is a light snapshot of aggregate project state, used to mark a milestone
and to give the next session a sense of recent history.

```bash
cpm checkpoint create --description "Importer rewrite complete"
cpm checkpoint create --auto-description
cpm checkpoint list
cpm checkpoint list --limit 10
cpm checkpoint restore CHECKPOINT-ID
cpm checkpoint auto                      # create one only when enough has changed
cpm checkpoint prune --keep 20
```

Checkpoints store statistics rather than full copies of the state files, so they stay
small. Retention is bounded by `checkpoints.max_retained`.

## Context for AI assistants

### `cpm context:digest`

The main integration point. It merges the state files into a markdown digest:

```bash
cpm context:digest
cpm context:digest --out-dir=.cpm/context
```

The digest opens with a journal — in-progress and blocked issues, the top open issues by
priority, the last three checkpoints — and then adds function-level detail when the
analysis is fresh. When the analysis is older than `tracking.max_age_days` the digest
says so explicitly instead of presenting stale numbers as current.

The digest is regenerated from the state files every time. Editing it by hand
accomplishes nothing.

### `cpm ai-context`

A context payload aimed at session handoff rather than human reading:

```bash
cpm ai-context
cpm ai-context --minimal          # smallest useful payload
cpm ai-context --detailed
cpm ai-context --include-todos
cpm ai-context --include-history
cpm ai-context --json
```

### Suggestion commands

```bash
cpm suggest                       # workflow suggestions
cpm suggest --mode=guided

cpm ai-suggest                    # suggestions for the next actions
cpm ai-suggest debugging
cpm ai-suggest --priority=high --limit=5 --include-commands

cpm smart-suggest                 # adds predictive and learned signals
cpm smart-suggest --predictive --include-reasoning
cpm smart-suggest --confidence-threshold=0.7
```

`cpm docs` prints the long-form command reference, written for an assistant to read
directly.

## Monitoring

`cpm monitor` re-checks the project for changes and updates progress accordingly.

```bash
cpm monitor                       # one pass
cpm monitor --continuous --interval=60
cpm monitor --daemon              # detach into the background
cpm monitor --status
cpm monitor --stop
cpm monitor --json
```

The daemon writes its PID and log into `.cpm/logs/`. One daemon per project.

## Analysis commands

```bash
cpm dependencies                                 # overview
cpm dependencies --show=src/Order/TotalCalculator.php
cpm dependencies --graph                         # graph statistics
cpm dependencies --circular                      # circular dependency check
cpm dependencies --refresh                       # recompute

cpm impact --file=src/Order/TotalCalculator.php
cpm impact --function=calculateTotal
cpm impact --cached --json
```

`dependencies` builds the graph from the analysers' output; `impact` uses it to estimate
what a change to a file or function would touch.

## Code generation and templates

These commands produce scaffolding from patterns observed in the project. They generate
text locally; they do not call an AI model.

```bash
cpm template list
cpm template analyze                       # learn patterns from the codebase
cpm template generate
cpm template learn
cpm template export

cpm generate-code class InvoiceService --language=php --output-path=src/Billing
cpm generate-code test InvoiceServiceTest --pattern=unit --dry-run
cpm generate-code class ReportBuilder --interface=Buildable --extends=AbstractBuilder
```

`cpm auto-test-docs` combines test and documentation scaffolding:

```bash
cpm auto-test-docs generate-tests --target=src/Billing
cpm auto-test-docs generate-docs
cpm auto-test-docs analyze-coverage
cpm auto-test-docs full-suite
```

The learning subsystem records which patterns a project actually uses:

```bash
cpm learn status
cpm learn analyze
cpm learn analyze --from-session=SESSION-ID
cpm learn predict --predict-file=src/Billing/InvoiceService.php
cpm learn adapt --enable-learning
```

Treat generated code as a first draft. Review it before committing it.

## Maintenance commands

```bash
cpm verify                    # health check with suggested fixes
cpm verify --fix
cpm verify --json

cpm repair --dry-run          # show what would be repaired
cpm repair --auto-fix --backup
cpm repair --force

cpm diagnostics               # concurrency and file-locking diagnostics
cpm diagnostics --detailed
cpm diagnostics --file=progress
cpm diagnostics --monitor

cpm compact                   # shrink oversized documentation files
cpm compact --target=context --force

cpm config show
cpm config get tracking.mode
cpm config set logging.level debug
cpm config defaults
cpm config reset

cpm tools:fix-perms           # diagnose and fix permissions
cpm migrate                   # move .claude-project/ to .cpm/
cpm update --dry-run          # refresh project template files
cpm update --backup
```

See [configuration.md](configuration.md) for every key `cpm config` accepts, and
[troubleshooting.md](troubleshooting.md) for what to do when one of these reports a
problem.

`cpm list` prints the commands registered in the current directory, and `cpm help
<command>` the full definition of one. The set differs depending on where you are:
outside an initialised project only `docs`, `context:digest`, `tools:fix-perms`,
`migrate`, `update` and `start` are registered, plus Symfony's own `help`, `list` and
`completion`.

## Working session walkthrough

A session that an assistant and a person can both follow.

```bash
# --- Session start ---------------------------------------------------------
cd ~/projects/my-app
export CPM_AGENT="assistant-a"

cpm context:digest                    # refresh the digest
cat .cpm/context/DIGEST.md            # the assistant reads this first
cpm status --focus                    # what was in flight
cpm ai-suggest --priority=high --limit=5

# --- During the work -------------------------------------------------------
cpm issue add --title "Discount rows excluded from subtotal" \
              --category bug --priority high
cpm issue update MY-BUG-002 --status in_progress

# ... editing code ...

cpm progress mark-complete src/Order/TotalCalculator.php
cpm impact --file=src/Order/TotalCalculator.php      # what else this touches
cpm issue close MY-BUG-002 --resolution "Subtotal now includes discount rows."

# --- Session end -----------------------------------------------------------
cpm checkpoint create --description "Order total fix landed"
cpm context:digest                    # leave the digest current for next time
cpm status
```

The discipline that makes this work is small: refresh the digest at the start, record
progress and issues as you go, checkpoint and refresh the digest at the end.

## Scripting and CI

Every state-reading command has a `--json` mode. Check the shape first, because it
differs per command — see [The model in one page](#the-model-in-one-page):

```bash
cpm status --json
cpm verify --json
cpm issue list --status open --json
cpm progress show --json
```

Example: fail a build when the project reports an unhealthy state.

`cpm verify --json` uses the standard envelope, so its `success` field can gate a
build:

```bash
#!/usr/bin/env bash
set -euo pipefail

if [ "$(cpm verify --json | jq -r '.success')" != "true" ]; then
  echo "CPM reports a problem with the project state" >&2
  cpm verify
  exit 1
fi
```

`cpm status --json` emits its own structure — `{overview, summary, session}`, plus
`ai_priority` when `--ai-priority` is given — rather than the envelope:

```bash
completion="$(cpm status --json | jq -r '.summary.CompletionPercentage')"
echo "Completion: ${completion}%"
```

Example: record a checkpoint on every tagged build.

```bash
cpm checkpoint create --description "CI build ${CI_BUILD_NUMBER}" --json
```

Inspect the state files directly when a command does not expose what you need:

```bash
jq '.functions | length' .cpm/db/inventory.json
jq '.issues[] | select(.status == "open") | .id' .cpm/db/issues.json
```

Read them; do not write them. Direct edits bypass the transaction lock and schema
validation.

## Practices worth adopting

- **Read the digest, not the raw state.** `DIGEST.md` is a few kilobytes; the state
  files can be megabytes and will waste an assistant's context window.
- **Regenerate the digest at both ends of a session.** A stale digest is worse than no
  digest, because it looks current.
- **Never edit files under `.cpm/db/` by hand.** Use the commands, which lock and
  validate.
- **Do not run CPM as root.** Root-owned files inside a user-owned repository break
  every later run.
- **Set `CPM_AGENT`** when more than one assistant or person works on the project, so
  that attribution in issues and checkpoints is meaningful.
- **Use `--dry-run` before any bulk operation.**
- **Run `cpm verify` when something looks wrong** before trying to repair anything.
