# Configuration

CPM reads configuration from four places. They serve different purposes and are loaded
by different classes, which is worth understanding before changing anything.

## Table of contents

- [Where configuration lives](#where-configuration-lives)
- [Runtime configuration: `.cpm/config.json`](#runtime-configuration-cpmconfigjson)
- [Analysis rules: `rules.json`](#analysis-rules-rulesjson)
- [Include and exclude patterns](#include-and-exclude-patterns)
- [Derived paths](#derived-paths)
- [Environment variables](#environment-variables)
- [Telegram notifications](#telegram-notifications)
- [Validation](#validation)
- [Worked examples](#worked-examples)

## Where configuration lives

| Location | Read by | Contents | Managed with |
| --- | --- | --- | --- |
| `.cpm/config.json` | `ClaudeProjectManager\Services\ConfigManager` | Runtime behaviour: validation, logging, backups, tracking | `cpm config` |
| `<install>/config/rules.json` | `ClaudeProjectManager\ConfigManager` | Global default analysis rules shipped with CPM | Edit the file |
| `.cpm/config/rules.json` | `ClaudeProjectManager\ConfigManager` | Per-project analysis rules, written by `cpm start` | Edit the file |
| `<project>/rules.json` | `ClaudeProjectManager\ConfigManager` | Per-project analysis rules that override everything else | Edit the file, commit it |
| `.cpm/.env` | `ClaudeProjectManager\ConfigManager` via Dotenv | Telegram credentials | Edit the file, never commit it |

There are two classes named `ConfigManager`. `Services\ConfigManager` handles
`.cpm/config.json` and backs the `cpm config` command. The root `ConfigManager` handles
analysis rules, paths and the environment. They do not share keys.

**Precedence for analysis rules**, lowest to highest:

1. Built-in defaults in the root `ConfigManager`.
2. `<install>/config/rules.json`.
3. `.cpm/config/rules.json`.
4. `<project>/rules.json`.

The project-root file is loaded last deliberately, so that regenerating
`.cpm/config/rules.json` with `cpm start` cannot silently discard hand-curated patterns.

## Runtime configuration: `.cpm/config.json`

This is the file `cpm config` reads and writes. Missing keys fall back to the defaults
below; the file is merged over the defaults recursively, so a partial file is fine.

| Key | Type | Default | Meaning |
| --- | --- | --- | --- |
| `validation.strict` | boolean | `false` | Validate state files strictly against their JSON schemas. |
| `validation.fail_on_error` | boolean | `false` | Abort the command when validation fails instead of continuing. |
| `validation.auto_repair` | boolean | `true` | Attempt to repair recoverable data problems automatically. |
| `logging.enabled` | boolean | `true` | Write logs to `.cpm/logs/`. |
| `logging.level` | string | `"info"` | One of `debug`, `info`, `warning`, `error`. |
| `logging.rotate_size_mb` | integer | `10` | Rotate a log file once it exceeds this size in megabytes. |
| `backups.enabled` | boolean | `true` | Back up state files before destructive writes. |
| `backups.retention_count` | integer | `2` | How many backup generations to keep. |
| `tracking.mode` | string | `"full"` | One of `full`, `minimal`, `disabled`. Controls how much detail is tracked. |
| `tracking.auto_monitor` | boolean | `false` | Start monitoring automatically. |

Managed through the CLI:

```bash
cpm config show                        # current values
cpm config defaults                    # the defaults, for comparison
cpm config get tracking.mode
cpm config set tracking.mode minimal
cpm config set logging.level debug
cpm config set backups.retention_count 5
cpm config reset                       # back to defaults
cpm config show --json
```

`cpm config set` validates the type against the default: a key whose default is boolean
rejects a non-boolean value, and the same holds for strings and integers. Keys that do
not appear in the defaults accept any value.

### Keys used by the digest

Two additional keys are consulted when the context digest decides whether function-level
detail is trustworthy, and one bounds checkpoint retention:

| Key | Type | Default | Meaning |
| --- | --- | --- | --- |
| `tracking.enabled` | boolean | `true` | When false, the digest renders the journal only and omits function-level detail. |
| `tracking.max_age_days` | integer | `14` | Analysis older than this is labelled stale in the digest rather than presented as current. |
| `checkpoints.max_retained` | integer | `50` | Upper bound on stored checkpoints. |

## Analysis rules: `rules.json`

`rules.json` describes what CPM analyses and which standards it reports against. The
version shipped in `config/rules.json` is the default set; a project overrides it by
placing its own `rules.json` at the project root.

### Top level

| Key | Type | Meaning |
| --- | --- | --- |
| `version` | string | Schema version of this rules file. |
| `project_type` | string | Primary language, for example `php`, `python`, `javascript`, `mixed`. |
| `global_rules` | object | Language-independent thresholds. |
| `analysis` | object | Analysis behaviour. |
| `php_rules`, `python_rules` | object | Language-specific standards. |
| `analysis_patterns` | object | Which checks the analysers perform. |
| `exclusion_patterns` | array | Glob patterns never analysed. |
| `inclusion_patterns` | array | Glob patterns analysed. |
| `file_type_handlers` | object | Extension to analyser class mapping. |
| `notification_rules` | object | When notifications fire. |
| `task_templates` | object | Named task definitions surfaced as suggestions. |
| `wordpress` | object | WordPress-specific detection and exclusions. |

### `global_rules`

| Key | Type | Default | Meaning |
| --- | --- | --- | --- |
| `max_function_length` | integer | `50` | Reported ceiling on function length in lines. |
| `max_file_size` | integer | `500` | Reported ceiling on file length in lines. |
| `require_documentation` | boolean | `true` | Flag undocumented functions. |
| `require_type_declarations` | boolean | `true` | Flag missing type declarations. |
| `require_error_handling` | boolean | `true` | Flag functions without error handling. |
| `enforce_naming_conventions` | boolean | `true` | Apply the naming conventions below. |

### `analysis`

| Key | Type | Default | Meaning |
| --- | --- | --- | --- |
| `max_scan_depth` | integer | `2` | How deep the directory scan descends when discovering files. |

### `php_rules`

| Key | Type | Default | Meaning |
| --- | --- | --- | --- |
| `require_strict_types` | boolean | `true` | Expect `declare(strict_types=1);`. |
| `require_return_types` | boolean | `true` | Expect a return type on every function. |
| `require_parameter_types` | boolean | `true` | Expect a type on every parameter. |
| `visibility_required` | boolean | `true` | Expect explicit visibility on members. |
| `follow_psr4` | boolean | `true` | Expect namespace to match path. |
| `docblock_style` | string | `"phpDocumentor"` | Expected docblock convention. |
| `exception_handling` | string | `"required"` | Whether error handling is expected. |
| `logging_required` | boolean | `true` | Expect errors to be logged. |
| `naming_convention.functions` | string | `"camelCase"` | Function naming style. |
| `naming_convention.variables` | string | `"camelCase"` | Variable naming style. |
| `naming_convention.constants` | string | `"UPPER_CASE"` | Constant naming style. |
| `naming_convention.classes` | string | `"PascalCase"` | Class naming style. |

### `python_rules`

| Key | Type | Default | Meaning |
| --- | --- | --- | --- |
| `follow_pep8` | boolean | `true` | Apply PEP 8 expectations. |
| `require_type_hints` | boolean | `true` | Expect type hints. |
| `require_docstrings` | boolean | `true` | Expect docstrings. |
| `docstring_style` | string | `"google"` | Expected docstring convention. |
| `exception_handling` | string | `"required"` | Whether error handling is expected. |
| `logging_required` | boolean | `true` | Expect errors to be logged. |
| `naming_convention.functions` | string | `"snake_case"` | Function naming style. |
| `naming_convention.variables` | string | `"snake_case"` | Variable naming style. |
| `naming_convention.constants` | string | `"UPPER_CASE"` | Constant naming style. |
| `naming_convention.classes` | string | `"PascalCase"` | Class naming style. |

### `analysis_patterns`

| Key | Type | Default | Meaning |
| --- | --- | --- | --- |
| `type_checking.check_input_types` | boolean | `true` | Check parameter types. |
| `type_checking.check_return_types` | boolean | `true` | Check return types. |
| `type_checking.check_variable_types` | boolean | `true` | Check local variable types. |
| `type_checking.suggest_union_types` | boolean | `true` | Suggest a union type where one fits. |
| `documentation.require_function_description` | boolean | `true` | Expect a description. |
| `documentation.require_parameter_description` | boolean | `true` | Expect parameter documentation. |
| `documentation.require_return_description` | boolean | `true` | Expect return documentation. |
| `documentation.require_example_usage` | boolean | `false` | Expect a usage example. |
| `code_quality.check_complexity` | boolean | `true` | Compute cyclomatic complexity. |
| `code_quality.max_cyclomatic_complexity` | integer | `10` | Complexity ceiling. |
| `code_quality.check_duplication` | boolean | `true` | Look for duplicated code. |
| `code_quality.check_unused_variables` | boolean | `true` | Look for unused variables. |
| `code_quality.check_dead_code` | boolean | `true` | Look for unreachable code. |
| `error_handling.require_try_catch` | boolean | `true` | Expect error handling around risky calls. |
| `error_handling.require_specific_exceptions` | boolean | `true` | Discourage catching the base exception type. |
| `error_handling.require_error_logging` | boolean | `true` | Expect caught errors to be logged. |
| `error_handling.check_exception_chaining` | boolean | `true` | Expect the previous exception to be passed on. |

### `file_type_handlers`

Maps a file extension to the analyser class that handles it.

| Extension | Analyser |
| --- | --- |
| `php` | `ClaudeProjectManager\Analyzers\PhpAnalyzer` |
| `py` | `ClaudeProjectManager\Analyzers\PythonAnalyzer` |
| `js` | `ClaudeProjectManager\Analyzers\JavaScriptAnalyzer` |

An extension without a handler is inventoried but not parsed for functions.

### `notification_rules`

| Key | Type | Default | Meaning |
| --- | --- | --- | --- |
| `progress_milestones` | array of integers | `[25, 50, 75, 100]` | Completion percentages that trigger a notification. |
| `error_severity_levels` | array of strings | `["critical", "major"]` | Severities that trigger a notification. |
| `completion_notifications` | boolean | `true` | Notify when work completes. |
| `session_notifications` | boolean | `true` | Notify on session events. |

These take effect only when a notification channel is configured. See
[Telegram notifications](#telegram-notifications).

### `task_templates`

Named task definitions that the suggestion commands can surface. Each entry has a
`name`, a `description`, a list of `rules` it satisfies and a `priority`. The shipped
set covers type checking, documentation completeness and error handling review. Add your
own by adding keys to this object.

### `wordpress`

Used when analysing a WordPress installation, so that core and parent-theme files are
not counted as project code.

| Key | Type | Default | Meaning |
| --- | --- | --- | --- |
| `detect_wordpress` | boolean | `true` | Detect a WordPress installation. |
| `exclude_core` | boolean | `true` | Skip WordPress core files. |
| `exclude_parent_themes` | boolean | `true` | Skip parent themes. |
| `track_child_themes` | boolean | `true` | Analyse child themes. |
| `track_mu_plugins` | boolean | `true` | Analyse must-use plugins. |
| `core_files` | array | `["wp-*.php", "xmlrpc.php", "license.txt", "readme.html"]` | Files treated as core. |
| `core_dirs` | array | `["wp-admin", "wp-includes"]` | Directories treated as core. |
| `exclude_dirs` | array | `["uploads", "cache", "languages", "upgrade", "backups", ...]` | Directories never analysed. |

## Include and exclude patterns

**`inclusion_patterns`** decides which files are analysed. When it is non-empty it
*replaces* the built-in defaults entirely, so a project can pin itself to a single
language:

```json
{
  "inclusion_patterns": ["*.py"]
}
```

Built-in defaults, used only when nothing is configured: `*.php`, `*.py`, `*.js`,
`*.ts`, `*.jsx`, `*.tsx`. A legacy slot, `analysis.include_patterns`, is consulted when
`inclusion_patterns` is absent.

**`exclusion_patterns`** is additive: configured patterns are merged with a substantial
built-in list covering `vendor`, `node_modules`, `.git`, `.cpm`, virtual environments,
caches, build and distribution directories, minified assets, coverage output, editor
directories and OS metadata files. `analysis.exclude_patterns` is merged in as well.

Because exclusions are additive and inclusions are replacing, the safe way to narrow the
analysis is to set `inclusion_patterns`, and the safe way to skip something is to add to
`exclusion_patterns`.

```json
{
  "inclusion_patterns": ["*.php", "*.js"],
  "exclusion_patterns": ["database/migrations/**/*", "public/assets/**/*"]
}
```

## Derived paths

The root `ConfigManager` computes these and exposes them under the `paths.` prefix. They
are derived, not configurable.

| Key | Value |
| --- | --- |
| `paths.project_root` | The detected project root |
| `paths.cpm_root` | `<project>/.cpm`, or `<project>/.claude-project` on an unmigrated project |
| `paths.database` | `<cpm_root>/db` |
| `paths.config` | `<cpm_root>/config` |
| `paths.context` | `<cpm_root>/context` |
| `paths.schemas` | `<cpm_root>/schemas` |
| `paths.templates` | `<cpm_root>/templates` |
| `paths.logs` | `<cpm_root>/logs` |

The project root is found by walking up from the current directory until a `.cpm/` or
`.claude-project/` directory appears.

## Environment variables

CPM reads a small, fixed set of variables. They come from the process environment or
from `.cpm/.env`, which is loaded with `vlucas/phpdotenv` when present.

| Variable | Type | Default | Meaning |
| --- | --- | --- | --- |
| `CPM_AGENT` | string | unset | Identifier recorded as `created_by`, `updated_by` or `agent` on issues, comments and checkpoints. The `--agent` option overrides it per command. |
| `TELEGRAM_ENABLED` | boolean | `false` | Enable Telegram notifications. |
| `TELEGRAM_BOT_TOKEN` | string | empty | Bot token. Required when Telegram is enabled. |
| `TELEGRAM_CHAT_ID` | string | empty | Destination chat. Required when Telegram is enabled. |

The templates under `templates/` list many further variable names. Most of them are
aspirational and are not read by the current code; treat the table above as the
authoritative list.

`ConfigManager::env()` coerces values: `"true"` and `"false"` become booleans, and
numeric strings become integers or floats.

## Telegram notifications

Telegram is an optional, self-contained integration. It is the only feature that makes a
network request, and it is disabled unless you enable it explicitly. CPM works fully
without it.

### Enabling it

1. Create a bot with Telegram's BotFather and note the token.
2. Obtain the chat ID of the destination chat or channel.
3. Write `.cpm/.env`:

```dotenv
TELEGRAM_ENABLED=true
TELEGRAM_BOT_TOKEN=123456789:replace-with-your-token
TELEGRAM_CHAT_ID=-1001234567890
```

4. Confirm that `.cpm/` is in `.gitignore`. The token is a credential.

### What gets sent

| Key | Type | Default | Meaning |
| --- | --- | --- | --- |
| `telegram.notify_on_start` | boolean | `true` | Notify when a session starts. |
| `telegram.notify_on_completion` | boolean | `true` | Notify when work is marked complete. |
| `telegram.notify_on_error` | boolean | `true` | Notify on errors. |

These live in the analysis configuration and are read through the root `ConfigManager`.
The `notification_rules` section of `rules.json` decides which milestones and severities
are worth a message.

Messages contain project and progress information — project name, completion
percentages, function and file names. Send them to a chat you control.

### Disabling it

```dotenv
TELEGRAM_ENABLED=false
```

Or delete `.cpm/.env` entirely. With `TELEGRAM_ENABLED` unset, notifications are off and
no network request is made.

## Validation

`ConfigManager::validate()` checks configuration consistency and returns a list of
problems. It reports:

- `TELEGRAM_BOT_TOKEN is required when Telegram is enabled`;
- `TELEGRAM_CHAT_ID is required when Telegram is enabled`;
- a project root that does not exist.

`cpm verify` surfaces these along with its other health checks.

## Worked examples

### Track a Python project only

`<project>/rules.json`:

```json
{
  "project_type": "python",
  "inclusion_patterns": ["*.py"],
  "exclusion_patterns": ["migrations/**/*", "docs/_build/**/*"],
  "global_rules": {
    "max_function_length": 60,
    "require_documentation": true
  }
}
```

### Keep the journal, drop function-level tracking

For a project where you want issues, checkpoints and the digest but not per-function
progress:

```bash
cpm config set tracking.enabled false
cpm config set tracking.mode minimal
```

### Debug an analysis problem

```bash
cpm config set logging.level debug
cpm start --force
tail -f .cpm/logs/*.log
cpm config set logging.level info
```

### Keep more backups on a project you experiment with

```bash
cpm config set backups.enabled true
cpm config set backups.retention_count 10
```
