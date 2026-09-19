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

**Do not open a public GitHub issue for a security problem.**

Report it privately to **hello@wasu.eu**, or through GitHub's private
vulnerability reporting on the repository's Security tab.

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

### File permissions

CPM creates its directories with restrictive permissions and provides
`cpm tools:fix-perms` to repair them. Running CPM as root leaves root-owned files in a
user-owned repository, which breaks later runs; run it as the user who owns the
project.

### Untrusted repositories

Analysing a repository means parsing its source files. CPM uses a static parser and
does not execute the code it analyses, but running any tool over untrusted code carries
residual risk. Analyse untrusted repositories in a container or throwaway user account.
