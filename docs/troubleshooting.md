# Troubleshooting

What to do when CPM reports a problem, refuses to write, or shows numbers that do not
match reality.

## Table of contents

- [First response](#first-response)
- [Installation and command availability](#installation-and-command-availability)
- [Permission problems](#permission-problems)
- [Schema validation failures](#schema-validation-failures)
- [Progress will not update](#progress-will-not-update)
- [Progress percentages look wrong](#progress-percentages-look-wrong)
- [Corrupted or unreadable state](#corrupted-or-unreadable-state)
- [Locking and concurrency](#locking-and-concurrency)
- [The monitor daemon](#the-monitor-daemon)
- [The digest is stale or empty](#the-digest-is-stale-or-empty)
- [Memory and performance](#memory-and-performance)
- [Disk usage](#disk-usage)
- [Telegram notifications](#telegram-notifications)
- [Recovery procedures](#recovery-procedures)
- [Inspecting state directly](#inspecting-state-directly)
- [Preventive maintenance](#preventive-maintenance)
- [Filing a bug report](#filing-a-bug-report)

## First response

Run these three, in order, before changing anything:

```bash
cpm verify              # health check with suggested fixes
cpm status --health     # health indicators from the state files
cpm diagnostics         # file access and locking
```

Between them they identify most problems and name the command that fixes each one. Add
`--json` to any of them when scripting.

If `cpm verify` proposes a fix and you agree with it:

```bash
cpm verify --fix
```

## Installation and command availability

### `cpm: command not found`

```bash
which cpm
ls -l /usr/local/bin/cpm
echo "$PATH"
php /opt/cpm/bin/cpm --version    # does the binary itself work?
```

If the binary works but the name does not, the symlink or `PATH` entry is missing. See
[installation.md](installation.md).

### `Error: Could not find autoloader`

`composer install` has not been run in the installation directory, or `vendor/` was
deleted:

```bash
cd /opt/cpm && composer install --no-dev --optimize-autoloader
```

### A command is missing from `cpm list`

Most commands register only inside an initialised project. Outside one, only `docs`,
`context:digest`, `tools:fix-perms`, `migrate`, `update` and `start` are available.

```bash
ls -d .cpm 2>/dev/null || echo "not an initialised project"
cpm start
```

If `.cpm/` exists but commands are still missing, you may be in a subdirectory of a
different project. CPM walks upward from the working directory to find the nearest
`.cpm/` or `.claude-project/`.

The `generate`, `metrics` and `refactoring` commands were removed before the first
public release and will not appear in any version from this release onward. Their
absence is not a sign of a broken installation; see [DECISIONS.md](DECISIONS.md).
Refactoring detection itself still runs, as part of progress tracking, and still writes
`refactoring_history.json`. Only the unreachable command wrapper was removed.

## Permission problems

**Symptoms.** `Permission denied` writing `.cpm/`, state changes that appear to succeed
but do not persist, a daemon that cannot write its PID file.

**Cause, nearly always.** CPM was run once as root, or with `sudo`, leaving root-owned
files inside a user-owned repository.

```bash
cpm tools:fix-perms              # diagnose and repair
cpm start --fix-permissions      # repair while initialising
```

By hand:

```bash
sudo chown -R "$USER:$USER" .cpm
find .cpm -type d -exec chmod 770 {} +
find .cpm -type f -exec chmod 660 {} +
```

Then stop running CPM as root. It writes only into the project directory and needs no
elevated privileges.

## Schema validation failures

**Symptom.** `Schema validation failed` when writing a state file.

The state file no longer matches its JSON schema — usually after a hand edit, an
interrupted write, or an upgrade that changed a schema.

Work through these in order:

```bash
# 1. See what is actually wrong
cpm progress validate
cpm verify --json

# 2. Repair the data
cpm repair --dry-run             # what would change
cpm repair --auto-fix --backup   # apply, keeping a backup

# 3. Relax validation temporarily while you work
cpm config set validation.strict false
cpm config set validation.fail_on_error false

# 4. Force one operation past validation, then repair afterwards
cpm progress mark-complete src/Example.php --force
cpm repair --auto-fix
```

`--force` is a way past a blockage, not a fix. Follow it with `cpm repair`.

If repair cannot recover the file, restore from a backup or reset that subsystem — see
[Recovery procedures](#recovery-procedures).

## Progress will not update

**Symptom.** `cpm progress mark-complete` reports success but `cpm status` does not
change, or the command refuses outright.

```bash
# Is the target actually in the inventory?
cpm status --file=src/Order/TotalCalculator.php
jq '.functions | keys[]' .cpm/db/inventory.json | grep -i totalcalculator

# Is the inventory current?
cpm start --force

# Is the progress data consistent with the inventory?
cpm progress validate
cpm progress verify
cpm progress repair
```

A target that is not in the inventory cannot be marked. Either the file is excluded by
the patterns in `rules.json`, or its extension has no analyser, or the inventory predates
the file. `cpm start --force` resolves the last of these; see
[configuration.md](configuration.md) for the first two.

For a bulk operation, preview first:

```bash
cpm progress bulk-mark --status=completed --dry-run
```

## Progress percentages look wrong

Percentages are derived from `global_stats` in `progress.json`, which is recomputed from
the inventory. They drift when the inventory and progress data disagree — typically after
a large refactor, where functions were renamed or moved.

```bash
cpm start --force        # re-analyse, reconciling progress with the new inventory
cpm progress verify      # cross-check
cpm progress repair      # fix what verify found
```

Reconciliation keeps the status of functions that still exist, adds new ones as
`pending`, and drops ones that no longer exist. If the numbers are still wrong
afterwards, the inventory is probably including or excluding files you did not intend:

```bash
jq '.files | length' .cpm/db/inventory.json
jq '.functions | length' .cpm/db/inventory.json
```

Compare that against what you expect, and adjust `inclusion_patterns` and
`exclusion_patterns`.

## Corrupted or unreadable state

**Symptoms.** `json_decode` errors, a command that reports an empty project on a
repository that is not empty, a state file of zero bytes.

```bash
# 1. Which file is broken?
for f in .cpm/db/*.json; do jq empty "$f" >/dev/null 2>&1 || echo "INVALID: $f"; done

# 2. Try the built-in repair
cpm repair --dry-run
cpm repair --auto-fix --backup

# 3. Restore that one file from a backup
ls -lt .cpm/backups/
cp .cpm/backups/<timestamp>/progress.json .cpm/db/progress.json
cpm verify
```

Backups are written before destructive operations, bounded by
`backups.retention_count` (default 2). Raise it on a project where you experiment:

```bash
cpm config set backups.retention_count 10
```

If no usable backup exists, see [Recovery procedures](#recovery-procedures).

## Locking and concurrency

**Symptoms.** A command hangs for about ten seconds and then reports a lock timeout; two
assistants working at once see each other's changes disappear (this should no longer
happen since 1.2.0).

```bash
cpm diagnostics --detailed
cpm diagnostics --file=progress
cpm diagnostics --monitor         # watch access in real time
ls -la .cpm/db/*.txlock
```

A `.txlock` file left behind by a killed process is harmless in itself — `flock` releases
the lock when the process dies, regardless of whether the file remains. If a command
times out acquiring a lock, something is genuinely holding it:

```bash
pgrep -af 'bin/cpm'               # other CPM processes
cpm monitor --status              # is the daemon busy?
```

Stop the daemon if it is the culprit, let a long analysis finish, and retry.

## The monitor daemon

```bash
cpm monitor --status
cpm monitor --stop
cpm monitor --daemon
tail -f .cpm/logs/*.log
```

If `--status` reports a daemon that is not running, a stale PID file is the usual cause:

```bash
cpm monitor --stop                     # the clean way
rm -f .cpm/logs/monitor.pid            # only when no daemon is actually running
cpm monitor --daemon
```

Confirm with `pgrep -af 'bin/cpm'` before deleting a PID file.

## The digest is stale or empty

```bash
cpm context:digest                     # regenerate
cat .cpm/context/DIGEST.md
```

### The digest says the analysis is stale

That is the digest doing its job. Function-level detail is rendered only when the
analysis is newer than `tracking.max_age_days` (default 14). Re-analyse:

```bash
cpm start --force
cpm context:digest
```

Or, on a project that deliberately uses only the journal:

```bash
cpm config set tracking.enabled false
```

### The digest has no function-level section at all

Check both gates:

```bash
cpm config get tracking.enabled
cpm config get tracking.max_age_days
```

### The digest is empty or missing

```bash
ls -la .cpm/context/
cpm verify
cpm context:digest --out-dir=.cpm/context
```

An empty digest generally means the state files are empty, which means `cpm start` has
not completed successfully. Run it and watch for errors.

### Edits to the digest keep disappearing

`DIGEST.md` is generated. Every regeneration overwrites it. Record information in issues,
checkpoints or progress, which are the inputs the digest is built from.

## Memory and performance

**Symptoms.** `Allowed memory size exhausted`, or an analysis that takes far too long.

```bash
# Give the CLI more memory for one run
php -d memory_limit=1G /opt/cpm/bin/cpm start --force

# Or analyse less
cpm start --quick
```

Almost always the real problem is that the analysis is including files it should not.
Check what is being analysed:

```bash
jq '.files | length' .cpm/db/inventory.json
```

Then narrow it in `rules.json`:

```json
{
  "inclusion_patterns": ["*.php"],
  "exclusion_patterns": [
    "vendor/**/*",
    "node_modules/**/*",
    "storage/**/*",
    "public/build/**/*"
  ]
}
```

Remember the asymmetry: `inclusion_patterns` replaces the defaults, while
`exclusion_patterns` is merged with them. Reducing `analysis.max_scan_depth` also limits
how deep discovery descends.

## Disk usage

```bash
du -sh .cpm
du -sh .cpm/*
```

| Directory | If it is large |
| --- | --- |
| `.cpm/backups/` | Lower `backups.retention_count`, or delete old backup directories. |
| `.cpm/logs/` | Lower `logging.level` to `warning`; check `logging.rotate_size_mb`. |
| `.cpm/db/checkpoints.json` | Run `cpm checkpoint prune --keep 20` and check `checkpoints.max_retained`. |
| `.cpm/db/issues.json` | Run `cpm issue archive --older-than 30`. |

Since 1.2.0 checkpoints store aggregate statistics rather than embedded state, so a
`checkpoints.json` in the hundreds of megabytes indicates a project still carrying
entries written by an older version. Prune them.

`cpm compact` shrinks oversized generated documentation:

```bash
cpm compact --target=context
cpm compact --target=all --force
```

## Telegram notifications

Nothing arrives:

```bash
cat .cpm/.env          # TELEGRAM_ENABLED, TELEGRAM_BOT_TOKEN, TELEGRAM_CHAT_ID
cpm verify             # reports a missing token or chat ID when enabled
```

`ConfigManager::validate()` reports `TELEGRAM_BOT_TOKEN is required when Telegram is
enabled` and the equivalent for the chat ID. Beyond that, check that the bot has been
added to the destination chat and that the machine has outbound network access.

To turn it off, set `TELEGRAM_ENABLED=false` or delete `.cpm/.env`. With it off, CPM
makes no network requests at all.

## Recovery procedures

Ordered from least to most destructive. Try them in order.

### 1. Repair

```bash
cpm repair --dry-run
cpm repair --auto-fix --backup
cpm verify
```

### 2. Restore one file from a backup

```bash
ls -lt .cpm/backups/
cp .cpm/backups/<timestamp>/progress.json .cpm/db/progress.json
cpm verify
```

### 3. Reset progress, keep everything else

Clears per-function status but preserves the inventory, issues and checkpoints.

```bash
cp -r .cpm .cpm.bak
cpm progress reset
cpm start --force
cpm verify
```

### 4. Reinitialise, keeping the issues

Issues are usually the state you least want to lose, and they are independent of the
analysis.

```bash
cpm issue export --json > /tmp/issues-backup.json
cp .cpm/db/issues.json /tmp/issues.json

rm -rf .cpm
cpm start

cp /tmp/issues.json .cpm/db/issues.json
cpm verify
cpm context:digest
```

### 5. Full reset

Everything CPM knows about the project is discarded. The project's source code is
untouched.

```bash
cp -r .cpm ~/cpm-state-backup-$(date +%Y%m%d)
rm -rf .cpm
cpm start
cpm context:digest
```

Keep the backup copy until you are sure nothing was needed from it.

## Inspecting state directly

Reading the state files is fine and often the fastest way to understand a problem.
Writing them is not: direct edits bypass the transaction lock and schema validation, and
are the most common cause of the corruption described above.

```bash
jq '.global_stats' .cpm/db/progress.json
jq '.files | length' .cpm/db/inventory.json
jq '.functions | length' .cpm/db/inventory.json
jq '.issues[] | select(.status == "open") | {id, title, priority}' .cpm/db/issues.json
jq '.checkpoints | length' .cpm/db/checkpoints.json
jq '.last_analysis' .cpm/db/project.json

ls -lh .cpm/db/
```

## Preventive maintenance

**After a significant change**

```bash
cpm monitor
cpm context:digest
```

**Weekly**

```bash
cpm verify
cpm status --detailed
cpm repair --dry-run
```

**Monthly**

```bash
cpm checkpoint prune --keep 20
cpm issue archive --older-than 60 --dry-run
du -sh .cpm
```

**Habits that prevent most problems**

- Never run CPM as root.
- Never edit files under `.cpm/db/` by hand.
- Regenerate the digest at the start and end of every session.
- Use `--dry-run` before any bulk or destructive operation.
- Keep `.cpm/` out of version control.

## Filing a bug report

If none of the above resolves it, open an issue using the bug report form. Include:

```bash
cpm --version
php -v
uname -a
cpm verify --json
cpm diagnostics --detailed
```

Plus the exact command you ran and its complete output, including any stack trace.

Review what you paste. `.cpm/` state contains file paths, function names and issue text
from your codebase. Security problems go to the address in [SECURITY.md](../SECURITY.md),
not to the public tracker.
