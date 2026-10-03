<?php

declare(strict_types=1);

namespace ClaudeProjectManager\Commands;

use ClaudeProjectManager\SessionManager;
use ClaudeProjectManager\Services\MonitorPidGuard;
use ClaudeProjectManager\Console\Commands\ContextDigestCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Command to manually trigger monitoring and progress checks
 */
class MonitorCommand extends Command
{
    use JsonResponseTrait;
    
    private SessionManager $sessionManager;

    protected static $defaultName = 'monitor';
    protected static $defaultDescription = 'Manually trigger monitoring for changes and progress updates';

    public function __construct(SessionManager $sessionManager)
    {
        parent::__construct();
        $this->sessionManager = $sessionManager;
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'continuous',
                'c',
                InputOption::VALUE_NONE,
                'Run continuous monitoring (checks every 30 seconds)'
            )
            ->addOption(
                'interval',
                'i',
                InputOption::VALUE_OPTIONAL,
                'Interval in seconds for continuous monitoring',
                30
            )
            ->addOption(
                'daemon',
                'd',
                InputOption::VALUE_NONE,
                'Run as background daemon'
            )
            ->addOption(
                'stop',
                null,
                InputOption::VALUE_NONE,
                'Stop background daemon'
            )
            ->addOption(
                'status',
                's',
                InputOption::VALUE_NONE,
                'Check daemon status'
            )
            ->addOption(
                'json',
                null,
                InputOption::VALUE_NONE,
                'Output JSON format for AI consumption'
            )
            ->setHelp('
This command manually triggers the monitoring system to check for:

- File changes in the project
- Completed functions based on code quality heuristics  
- Automatic progress updates
- Activity tracking

Use --continuous for ongoing monitoring that runs until interrupted.
Use --daemon to run monitoring in the background.
Use --stop to stop background daemon.
Use --status to check daemon status.
Use --interval to specify check frequency (default: 30 seconds).
            ');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $json = $this->isJsonRequested($input);
        $io = $this->createStyleIfNeeded($input, $output);
        $continuous = $input->getOption('continuous');
        $daemon = $input->getOption('daemon');
        $stop = $input->getOption('stop');
        $status = $input->getOption('status');
        $interval = (int) $input->getOption('interval');

        // Handle daemon control commands
        if ($stop) {
            return $this->stopDaemon($io, $output, $json);
        }

        if ($status) {
            return $this->checkDaemonStatus($io, $output, $json);
        }

        if ($daemon) {
            return $this->startDaemon($io, $output, $json, $interval);
        }

        if ($json) {
            if ($continuous) {
                $this->outputJsonError($output, 'monitor', 'Continuous mode does not support --json output');
                return Command::FAILURE;
            }

            try {
                $results = $this->sessionManager->performMonitoringCheck();

                if (!empty($results['error'])) {
                    $this->outputJsonError($output, 'monitor', $results['error']);
                    return Command::FAILURE;
                }

                $this->outputJsonSuccess($output, 'monitor', $results);
                return Command::SUCCESS;
            } catch (\Exception $e) {
                return $this->handleJsonException($output, 'monitor', $e);
            }
        }

        if ($continuous) {
            $io->title('Claude Project Manager - Continuous Monitoring');
            $io->note("Starting continuous monitoring (checking every {$interval} seconds)");
            $io->note('Press Ctrl+C to stop monitoring');

            try {
                $this->runContinuousMonitoring($io, $interval);
            } catch (\Exception $e) {
                $io->error('Monitoring failed: ' . $e->getMessage());
                return Command::FAILURE;
            }
        } else {
            $io->title('Claude Project Manager - Single Monitoring Check');
            
            try {
                $results = $this->runSingleCheck($io);
                
                if (!empty($results['error'])) {
                    $io->error($results['error']);
                    return Command::FAILURE;
                }
                
                $io->success('Monitoring check completed successfully');
            } catch (\Exception $e) {
                $io->error('Monitoring check failed: ' . $e->getMessage());
                return Command::FAILURE;
            }
        }

        return Command::SUCCESS;
    }

    /**
     * Run a single monitoring check
     */
    private function runSingleCheck(SymfonyStyle $io): array
    {
        $io->section('Running Monitoring Check');
        
        $results = $this->sessionManager->performMonitoringCheck();
        
        // Display results
        $this->displayResults($io, $results);
        
        // Auto-refresh digest after successful check
        if (empty($results['error'])) {
            $this->refreshContextDigest($io);
        }
        
        return $results;
    }

    /**
     * Run continuous monitoring
     */
    private function runContinuousMonitoring(SymfonyStyle $io, int $interval): void
    {
        $checkCount = 0;
        $totalActivities = 0;
        
        while (true) {
            $checkCount++;
            
            try {
                $io->section("Monitoring Check #{$checkCount} - " . date('H:i:s'));
                
                $results = $this->sessionManager->performMonitoringCheck();
                
                if (!empty($results['error'])) {
                    $io->warning('Check failed: ' . $results['error']);
                } else {
                    $activitiesDetected = $results['activities_detected'] ?? 0;
                    $totalActivities += $activitiesDetected;
                    
                    if ($activitiesDetected > 0) {
                        $io->success("Detected {$activitiesDetected} activities");
                        $this->displayResults($io, $results);
                    } else {
                        $io->text('No changes detected');
                    }
                    
                    // Auto-refresh digest after successful cycle
                    $this->refreshContextDigest($io);
                }
                
                $io->note("Total activities detected: {$totalActivities}");
                $io->text("Sleeping for {$interval} seconds...");
                
            } catch (\Exception $e) {
                $io->error('Monitoring error: ' . $e->getMessage());
            }
            
            sleep($interval);
        }
    }

    /**
     * Display monitoring results
     */
    private function displayResults(SymfonyStyle $io, array $results): void
    {
        if (empty($results) || !empty($results['error'])) {
            return;
        }

        // File changes
        $fileChanges = $results['file_changes'] ?? [];
        if (!empty($fileChanges['modified']) || !empty($fileChanges['added']) || !empty($fileChanges['deleted'])) {
            $io->text('<info>File Changes Detected:</info>');
            
            if (!empty($fileChanges['modified'])) {
                $io->listing(array_map(fn($f) => '📝 Modified: ' . basename($f), $fileChanges['modified']));
            }
            
            if (!empty($fileChanges['added'])) {
                $io->listing(array_map(fn($f) => '➕ Added: ' . basename($f), $fileChanges['added']));
            }
            
            if (!empty($fileChanges['deleted'])) {
                $io->listing(array_map(fn($f) => '❌ Deleted: ' . basename($f), $fileChanges['deleted']));
            }
        }

        // Completed functions
        $completedFunctions = $results['completed_functions'] ?? [];
        if (!empty($completedFunctions)) {
            $io->text('<info>Functions Completed:</info>');
            $io->listing(array_map(fn($id) => "✅ Function ID: {$id}", $completedFunctions));
        }

        // Summary table
        $summaryData = [
            ['Activities Detected', $results['activities_detected'] ?? 0],
            ['File Changes', count($fileChanges['modified'] ?? []) + count($fileChanges['added'] ?? []) + count($fileChanges['deleted'] ?? [])],
            ['Completed Functions', count($completedFunctions)]
        ];

        $io->table(['Metric', 'Count'], $summaryData);
    }

    /**
     * Refresh context digest after monitoring check
     */
    private function refreshContextDigest(SymfonyStyle $io): void
    {
        try {
            $digestCommand = new ContextDigestCommand();
            $digestInput = new ArrayInput([]);
            $digestCommand->run($digestInput, $io);
        } catch (\Exception $e) {
            // Silently continue if digest fails - don't crash monitor
            error_log('Failed to refresh context digest: ' . $e->getMessage());
        }
    }

    /**
     * Start monitoring as a background daemon
     */
    private function startDaemon(?SymfonyStyle $io, OutputInterface $output, bool $json, int $interval): int
    {
        $projectRoot = getcwd() ?: '.';
        $pidFile = $projectRoot . '/.cpm/logs/monitor.pid';
        $logFile = $projectRoot . '/.claude-project/logs/monitor.log';

        // Check if daemon is already running
        if ($this->isDaemonRunning($pidFile)) {
            if ($json) {
                $this->outputJsonError($output, 'monitor.daemon', 'Monitoring daemon is already running. Use --stop to stop it first.');
            } else {
                $io->error('Monitoring daemon is already running. Use --stop to stop it first.');
            }
            return Command::FAILURE;
        }

        // Ensure log directory exists
        @mkdir(dirname($pidFile), 0770, true);
        @mkdir(dirname($logFile), 0770, true);

        if (!$json) {
            $io->title('Claude Project Manager - Background Monitoring');
            $io->note("Starting background monitoring daemon (checking every {$interval} seconds)");
        }

        // Build the command to run in background
        $phpBinary = PHP_BINARY;
        $scriptPath = $_SERVER['SCRIPT_FILENAME'] ?? __FILE__;
        $command = sprintf(
            '%s %s monitor --continuous --interval=%d > %s 2>&1 & echo $!',
            escapeshellarg($phpBinary),
            escapeshellarg($scriptPath),
            $interval,
            escapeshellarg($logFile)
        );

        // Execute the command and capture the PID
        $pid = trim(shell_exec($command));

        if (empty($pid) || !is_numeric($pid)) {
            if ($json) {
                $this->outputJsonError($output, 'monitor.daemon', 'Failed to start monitoring daemon');
            } else {
                $io->error('Failed to start monitoring daemon');
            }
            return Command::FAILURE;
        }

        // Write PID file
        file_put_contents($pidFile, $pid);
        @chmod($pidFile, 0660);

        if ($json) {
            $this->outputJsonSuccess($output, 'monitor.daemon', [
                'started' => true,
                'pid' => (int)$pid,
                'interval' => $interval,
                'log_file' => $logFile,
            ]);
        } else {
            $io->success("Monitoring daemon started with PID: {$pid}");
            $io->note("Log file: {$logFile}");
            $io->note("Use 'cpm monitor --stop' to stop the daemon");
            $io->note("Use 'cpm monitor --status' to check daemon status");
        }

        return Command::SUCCESS;
    }

    /**
     * Stop the monitoring daemon
     */
    private function stopDaemon(?SymfonyStyle $io, OutputInterface $output, bool $json): int
    {
        $projectRoot = getcwd() ?: '.';
        $pidFile = $projectRoot . '/.cpm/logs/monitor.pid';

        if (!file_exists($pidFile)) {
            if ($json) {
                $this->outputJsonSuccess($output, 'monitor.stop', ['stopped' => false, 'message' => 'No daemon PID file found. Daemon may not be running.']);
            } else {
                $io->warning('No daemon PID file found. Daemon may not be running.');
            }
            return Command::SUCCESS;
        }

        $pid = MonitorPidGuard::parse((string) file_get_contents($pidFile));

        if ($pid === null) {
            @unlink($pidFile);
            if ($json) {
                $this->outputJsonError($output, 'monitor.stop', 'Invalid PID in daemon file');
            } else {
                $io->error('Invalid PID in daemon file');
            }
            return Command::FAILURE;
        }

        // Check if process is running
        $running = $this->isProcessRunning($pid);

        if (!$running) {
            @unlink($pidFile);
            if ($json) {
                $this->outputJsonSuccess($output, 'monitor.stop', ['stopped' => false, 'pid' => (int)$pid, 'message' => "Process with PID {$pid} is not running"]);
            } else {
                $io->warning("Process with PID {$pid} is not running");
            }
            return Command::SUCCESS;
        }

        // Never signal a process that cannot be verified as a CPM monitor: the
        // PID file may come from a hostile repository (see MonitorPidGuard).
        if (!MonitorPidGuard::isCpmMonitor($pid, $projectRoot)) {
            $message = "PID {$pid} is not a verifiable CPM monitor process; refusing to signal it. "
                . "Stop the monitor manually and remove {$pidFile}";
            if ($json) {
                $this->outputJsonError($output, 'monitor.stop', $message);
            } else {
                $io->error($message);
            }
            return Command::FAILURE;
        }

        // Try to terminate the process gracefully
        if (!function_exists('posix_kill')) {
            // Windows fallback: use taskkill
            $execOutput = [];
            $exitCode = 0;
            exec('taskkill /PID ' . escapeshellarg((string) $pid) . ' /F 2>&1', $execOutput, $exitCode);
            if ($exitCode === 0) {
                @unlink($pidFile);
                if ($json) {
                    $this->outputJsonSuccess($output, 'monitor.stop', ['stopped' => true, 'pid' => (int)$pid]);
                } else {
                    $io->success("Monitoring daemon stopped (PID: {$pid}, via taskkill)");
                }
                return Command::SUCCESS;
            }
            if ($json) {
                $this->outputJsonError($output, 'monitor.stop', "Failed to stop daemon with PID: {$pid} via taskkill");
            } else {
                $io->error("Failed to stop daemon with PID: {$pid} via taskkill");
            }
            return Command::FAILURE;
        }

        if (posix_kill($pid, SIGTERM)) {
            // Wait a bit for graceful shutdown
            sleep(2);

            // Check if it's still running (and still the same monitor, in case
            // the PID was recycled), then force kill if needed
            if ($this->isProcessRunning($pid) && MonitorPidGuard::isCpmMonitor($pid, $projectRoot)) {
                posix_kill($pid, SIGKILL);
                sleep(1);
            }

            @unlink($pidFile);
            if ($json) {
                $this->outputJsonSuccess($output, 'monitor.stop', ['stopped' => true, 'pid' => (int)$pid]);
            } else {
                $io->success("Monitoring daemon stopped (PID: {$pid})");
            }
            return Command::SUCCESS;
        } else {
            if ($json) {
                $this->outputJsonError($output, 'monitor.stop', "Failed to stop daemon with PID: {$pid}");
            } else {
                $io->error("Failed to stop daemon with PID: {$pid}");
            }
            return Command::FAILURE;
        }
    }

    /**
     * Check daemon status
     */
    private function checkDaemonStatus(?SymfonyStyle $io, OutputInterface $output, bool $json): int
    {
        $projectRoot = getcwd() ?: '.';
        $pidFile = $projectRoot . '/.cpm/logs/monitor.pid';
        $logFile = $projectRoot . '/.claude-project/logs/monitor.log';

        if (!file_exists($pidFile)) {
            if ($json) {
                $this->outputJsonSuccess($output, 'monitor.status', ['running' => false, 'message' => 'Monitoring daemon is not running (no PID file found)']);
            } else {
                $io->info('Monitoring daemon is not running (no PID file found)');
            }
            return Command::SUCCESS;
        }

        $pid = MonitorPidGuard::parse((string) file_get_contents($pidFile));

        if ($pid === null) {
            if ($json) {
                $this->outputJsonError($output, 'monitor.status', 'Invalid PID in daemon file');
            } else {
                $io->error('Invalid PID in daemon file');
            }
            return Command::FAILURE;
        }

        $running = $this->isProcessRunning($pid);

        if ($json) {
            $data = ['running' => $running, 'pid' => $pid];
            if (!$running) {
                @unlink($pidFile);
                $data['message'] = 'Daemon PID file existed but process is not running; PID file removed';
            } elseif (file_exists($logFile)) {
                $logContent = shell_exec("tail -n 10 " . escapeshellarg($logFile));
                if ($logContent) {
                    $data['recent_log'] = explode("\n", trim($logContent));
                }
            }
            $this->outputJsonSuccess($output, 'monitor.status', $data);
            return Command::SUCCESS;
        }

        if ($running) {
            $io->success("Monitoring daemon is running (PID: {$pid})");

            // Show recent log entries if available
            if (file_exists($logFile)) {
                $io->section('Recent Log Entries');
                $logContent = shell_exec("tail -n 10 " . escapeshellarg($logFile));
                if ($logContent) {
                    $io->text($logContent);
                }
            }
        } else {
            $io->warning("Daemon PID file exists but process is not running (PID: {$pid})");
            @unlink($pidFile);
        }

        return Command::SUCCESS;
    }

    /**
     * Check if daemon is already running
     */
    private function isDaemonRunning(string $pidFile): bool
    {
        if (!file_exists($pidFile)) {
            return false;
        }

        $pid = MonitorPidGuard::parse((string) file_get_contents($pidFile));
        return $pid !== null && $this->isProcessRunning($pid);
    }

    /**
     * Check if a process with given PID is running
     *
     * Windows note: posix_kill not available — falls back to tasklist.
     */
    private function isProcessRunning(int $pid): bool
    {
        if ($pid <= 1) {
            return false;
        }
        if (function_exists('posix_kill')) {
            return posix_kill($pid, 0);
        }
        // Windows fallback
        $output = [];
        exec('tasklist /FI ' . escapeshellarg("PID eq {$pid}") . ' /NH 2>NUL', $output);
        foreach ($output as $line) {
            if (strpos($line, (string)$pid) !== false) {
                return true;
            }
        }
        return false;
    }
}