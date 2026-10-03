# Security Policy

## Supported versions

| Version | Supported |
| --- | --- |
| 1.3.x | Yes |
| 1.2.x | Security fixes only |
| 1.1.x and older | No |

Only the latest minor release receives regular fixes. Please upgrade before
reporting a problem against an older line.

## Reporting a vulnerability

**Do not open a public GitHub issue or pull request for a security problem.**

Report it privately through GitHub's private vulnerability reporting: the
"Report a vulnerability" button on the repository's Security tab. If you cannot use
GitHub, email **hello@wasu.eu** instead.

Please include:

- a description of the issue and why you believe it is a security problem;
- the affected version and the platform you observed it on;
- the steps to reproduce it, ideally with a minimal example;
- any proof-of-concept code or command output, with your own paths and data removed.

### What to expect

| Stage | Target |
| --- | --- |
| Acknowledgement of your report | Within 3 working days |
| Initial assessment and severity | Within 10 working days |
| Fix or mitigation for a confirmed high-severity issue | Within 30 days |

We will keep you informed while we work, credit you in the release notes unless you
prefer otherwise, and coordinate a disclosure date with you. Please give us a
reasonable opportunity to release a fix before disclosing publicly.

## Security notes for users of CPM

### `.cpm/` contains information about your codebase

CPM writes its state into the working tree of the repository it is tracking. Those
files are not encrypted and are readable by anyone who can read the repository. They
contain, among other things:

- absolute and relative paths of the files in your project;
- the names of your classes, functions and methods, with line numbers;
- dependency relationships between files;
- issue titles, descriptions, comments and resolutions, which often quote code;
- checkpoint metadata and session history.

**Do not commit `.cpm/` to a public repository.** Add it to `.gitignore`:

```gitignore
.cpm/
.claude-project/
```

If a private project's `.cpm/` directory has already been pushed to a public remote,
treat the contents as disclosed: remove the directory, rewrite the affected history,
and review the issue text for anything sensitive that was quoted into it.

### Credentials

CPM reads optional Telegram credentials from `.cpm/.env`
(`TELEGRAM_BOT_TOKEN`, `TELEGRAM_CHAT_ID`). That file must never be committed. It is
covered by the `.cpm/` ignore rule above, and by the `.env` rules in the project's
`.gitignore`. CPM does not read or transmit any other credential, and it makes no
network requests unless Telegram notifications are explicitly enabled.

CPM ignores a `.cpm/.env` (or legacy `.claude-project/.env`) that is tracked by git and
prints a warning instead of loading it. A committed file comes from the repository, not
from the user, so a hostile repository cannot use it to enable Telegram with a foreign
bot token. CPM also ignores the file when it, or any directory on the way to it, is a
symlink, or when it resolves to a location outside the project root. An untracked,
local `.cpm/.env` keeps working as before.

The tracked-file check needs a git work tree. A project that arrives as a zip file or a
tarball has no git metadata, so CPM cannot tell a planted `.cpm/.env` from a local one.
Before running CPM on such an untrusted project, inspect `.cpm/.env` (and
`.claude-project/.env`) and delete the file if you did not create it.

### File permissions

CPM creates `.cpm/` with restrictive permissions: directories `0770` and files `0660`,
so the files are not readable by other users. `cpm tools:fix-perms` repairs the
permissions by tightening them to the same modes; it does not loosen them, and the
commands it suggests use the same modes with every path shell-quoted. Running CPM as
root leaves root-owned files in a user-owned repository, which breaks later runs; run
it as the user who owns the project.

### Monitor daemon

`cpm monitor --stop` reads the PID from `.cpm/logs/monitor.pid`, a file that a cloned
repository could contain. CPM only accepts a plain positive PID greater than 1, and it
only signals that process after it has verified that the process is the monitor daemon
of this project: its arguments must be a CPM entry point (`cpm`, `claude-project` or
`cpm.php`) followed directly by `monitor --continuous`, and its working directory must
be the project root. On Linux CPM reads `/proc/<pid>/cmdline` and `/proc/<pid>/cwd`; on
macOS and BSD it falls back to `ps` and, when installed, `lsof`. When the arguments
cannot be read, or the working directory is readable and differs, CPM refuses to signal
the process and asks the user to stop the monitor by hand.

### Untrusted repositories

Analysing a repository means parsing its source files. CPM uses a static parser and
does not execute the code it analyses, but running any tool over untrusted code carries
residual risk. Analyse untrusted repositories in a container or throwaway user account.
