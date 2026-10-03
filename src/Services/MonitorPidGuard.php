<?php

declare(strict_types=1);

namespace ClaudeProjectManager\Services;

/**
 * Validates the PID stored in .cpm/logs/monitor.pid before CPM acts on it.
 *
 * The PID file lives inside the analysed project, so a hostile repository can
 * commit one. A value such as "-1" or "0" would make posix_kill() signal every
 * process of the user or the whole process group, so only a plain positive
 * PID greater than 1 is accepted, and a process is only signalled after it
 * has been verified to be a CPM monitor.
 */
final class MonitorPidGuard
{
    /**
     * Parse raw PID file content. Returns null unless it is a plain decimal
     * PID greater than 1 (no sign, no leading zero, no exponent, no overflow).
     */
    public static function parse(string $raw): ?int
    {
        $raw = trim($raw);
        if (preg_match('/^[1-9][0-9]{0,9}$/', $raw) !== 1) {
            return null;
        }
        $pid = (int) $raw;

        return ($pid > 1 && $pid <= 2147483647) ? $pid : null;
    }

    /** Basenames of the CPM entry points the daemon can be launched with. */
    private const ENTRY_POINTS = ['cpm', 'claude-project', 'cpm.php'];

    /**
     * Verify that $pid is the monitor daemon of $projectRoot.
     *
     * MonitorCommand::startDaemon() launches `<php> <.../cpm> monitor
     * --continuous --interval=N` from the project root, so the argument vector
     * must contain a CPM entry point (as argv[0], or as argv[1] after a php
     * interpreter) followed directly by `monitor` and `--continuous`, and the
     * process must run in this project's directory.
     *
     * Sources, in order: /proc (Linux; argv and cwd are exact), then `ps` and
     * `lsof` (macOS/BSD; argv is split on whitespace, so it is best effort),
     * then PowerShell on Windows (argv only).
     *
     * Safe default: when the argument vector cannot be read, or the working
     * directory can be read but differs, the process is treated as NOT a
     * monitor, so CPM refuses to signal it and the user stops it by hand.
     */
    public static function isCpmMonitor(int $pid, string $projectRoot, string $procRoot = '/proc'): bool
    {
        if ($pid <= 1) {
            return false;
        }
        $realRoot = realpath($projectRoot);
        if ($realRoot === false) {
            return false;
        }

        $procRoot = rtrim($procRoot, '/');
        if (is_dir($procRoot)) {
            $raw = @file_get_contents($procRoot . '/' . $pid . '/cmdline');
            if ($raw === false || $raw === '') {
                return false;
            }
            $cwd = @readlink($procRoot . '/' . $pid . '/cwd');

            return self::matchesDaemonArgv(explode("\0", rtrim($raw, "\0")))
                && $cwd !== false
                && self::samePath($cwd, $realRoot);
        }

        if (PHP_OS_FAMILY === 'Windows') {
            $argv = self::windowsArgv($pid);

            return $argv !== null && self::matchesDaemonArgv($argv);
        }

        $argv = self::psArgv($pid);
        if ($argv === null || !self::matchesDaemonArgv($argv)) {
            return false;
        }
        // Without /proc the cwd comes from lsof when it is installed; a cwd
        // that is readable but different means another project's monitor.
        $cwd = self::lsofCwd($pid);

        return $cwd === null || self::samePath($cwd, $realRoot);
    }

    /**
     * Compare a reported working directory with the resolved project root.
     * The cwd is resolved too: on macOS /var and /tmp are symlinks into
     * /private, so the same directory can be reported under either name.
     */
    private static function samePath(string $cwd, string $realRoot): bool
    {
        $realCwd = realpath($cwd);

        return ($realCwd !== false ? $realCwd : $cwd) === $realRoot;
    }

    /**
     * @param list<string> $argv
     */
    private static function matchesDaemonArgv(array $argv): bool
    {
        $argv = array_values($argv);
        $index = null;
        if (isset($argv[0]) && self::isEntryPoint($argv[0])) {
            $index = 0;
        } elseif (
            isset($argv[0], $argv[1])
            && preg_match('/^php[0-9.]*(\.exe)?$/i', basename(str_replace('\\', '/', $argv[0]))) === 1
            && self::isEntryPoint($argv[1])
        ) {
            $index = 1;
        }

        return $index !== null
            && ($argv[$index + 1] ?? null) === 'monitor'
            && ($argv[$index + 2] ?? null) === '--continuous';
    }

    private static function isEntryPoint(string $arg): bool
    {
        return in_array(basename(str_replace('\\', '/', $arg)), self::ENTRY_POINTS, true);
    }

    /**
     * @return list<string>|null
     */
    private static function psArgv(int $pid): ?array
    {
        $output = [];
        $exitCode = 1;
        @exec('ps -o command= -p ' . (int) $pid . ' 2>/dev/null', $output, $exitCode);
        if ($exitCode !== 0 || !isset($output[0]) || trim($output[0]) === '') {
            @exec('ps -o args= -p ' . (int) $pid . ' 2>/dev/null', $output, $exitCode);
        }
        $line = trim(implode(' ', $output));
        if ($exitCode !== 0 || $line === '') {
            return null;
        }

        return preg_split('/\s+/', $line) ?: null;
    }

    private static function lsofCwd(int $pid): ?string
    {
        $output = [];
        $exitCode = 1;
        @exec('lsof -a -p ' . (int) $pid . ' -d cwd -Fn 2>/dev/null', $output, $exitCode);
        if ($exitCode !== 0) {
            return null;
        }
        foreach ($output as $line) {
            if (str_starts_with($line, 'n')) {
                return substr($line, 1);
            }
        }

        return null;
    }

    /**
     * @return list<string>|null
     */
    private static function windowsArgv(int $pid): ?array
    {
        $output = [];
        $exitCode = 1;
        $query = sprintf("(Get-CimInstance Win32_Process -Filter 'ProcessId=%d').CommandLine", $pid);
        exec('powershell -NoProfile -NonInteractive -Command ' . escapeshellarg($query) . ' 2>NUL', $output, $exitCode);
        $line = trim(implode(' ', $output));
        if ($exitCode !== 0 || $line === '') {
            return null;
        }
        // Windows command lines quote paths; str_getcsv on spaces honours that.
        return array_values(array_filter(str_getcsv($line, ' ', '"', ''), static fn ($a) => $a !== ''));
    }
}
