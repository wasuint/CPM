<?php

declare(strict_types=1);

namespace ClaudeProjectManager\Commands;

use ClaudeProjectManager\DatabaseManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class DiagnosticsCommand extends Command
{
    protected static $defaultName = 'diagnostics';
    protected static $defaultDescription = 'Diagnose concurrent access and file locking issues';

    private DatabaseManager $database;
    private string $projectRoot;

    public function __construct(DatabaseManager $database, string $projectRoot)
    {
        parent::__construct();
        $this->database = $database;
        $this->projectRoot = $projectRoot;
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Diagnose file access issues and concurrent operations')
            ->setHelp('This command helps diagnose and resolve file locking and concurrent access issues')
            ->addOption('file', 'f', InputOption::VALUE_REQUIRED, 'Specific database file to diagnose')
            ->addOption('detailed', 'd', InputOption::VALUE_NONE, 'Show detailed diagnostics')
            ->addOption('monitor', 'm', InputOption::VALUE_NONE, 'Monitor file access in real-time');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $targetFile = $input->getOption('file');
        $detailed = $input->getOption('detailed');
        $monitor = $input->getOption('monitor');

        $io->title('🔧 Claude Project Manager - File Access Diagnostics');

        // List of database files to check
        $databaseFiles = [
            'project', 'progress', 'sessions', 'tasks', 'inventory', 
            'dependencies', 'impacts', 'refactoring_history', 'metrics'
        ];

        if ($targetFile) {
            $databaseFiles = [$targetFile];
        }

        if ($monitor) {
            return $this->monitorFileAccess($io, $databaseFiles);
        }

        // Check each database file
        foreach ($databaseFiles as $fileName) {
            $this->diagnoseFile($io, $fileName, $detailed);
        }

        // Check for stale locks and processes
        $this->checkForStaleProcesses($io);

        // Show recommendations
        $this->showRecommendations($io);

        return Command::SUCCESS;
    }

    private function diagnoseFile(SymfonyStyle $io, string $fileName, bool $detailed): void
    {
        $io->section("📁 Diagnosing: {$fileName}.json");

        try {
            $stats = $this->database->getDiagnostics($fileName);
            
            $status = '✅ OK';
            $issues = [];
            
            if (!$stats['file_exists']) {
                $status = '⚠️ Missing';
                $issues[] = 'File does not exist';
            } elseif (!$stats['file_readable']) {
                $status = '❌ Read Error';
                $issues[] = 'File is not readable';
            } elseif (!$stats['file_writable']) {
                $status = '❌ Write Error';
                $issues[] = 'File is not writable';
            }
            
            if ($stats['lock_exists']) {
                $lockAge = $stats['lock_age'] ?? 0;
                if ($lockAge > 30) {
                    $status = '⚠️ Stale Lock';
                    $issues[] = "Stale lock file (age: {$lockAge}s)";
                } else {
                    $status = '🔒 Locked';
                    $issues[] = "Currently locked (age: {$lockAge}s)";
                }
            }

            $table = [
                ['Property', 'Value'],
                ['Status', $status],
                ['File Exists', $stats['file_exists'] ? 'Yes' : 'No'],
                ['Readable', $stats['file_readable'] ? 'Yes' : 'No'],
                ['Writable', $stats['file_writable'] ? 'Yes' : 'No'],
                ['Lock Active', $stats['lock_exists'] ? 'Yes' : 'No'],
                ['File Size', $stats['file_size'] ? $this->formatBytes($stats['file_size']) : 'N/A'],
                ['Last Modified', $stats['last_modified'] ?? 'N/A']
            ];

            if ($stats['lock_age'] !== null) {
                $table[] = ['Lock Age', $stats['lock_age'] . 's'];
            }

            $io->table(['Property', 'Value'], array_slice($table, 1));

            if (!empty($issues)) {
                $io->warning('Issues detected: ' . implode(', ', $issues));
            }

            if ($detailed) {
                $this->showDetailedDiagnostics($io, $fileName, $stats);
            }

        } catch (\Exception $e) {
            $io->error("Failed to diagnose {$fileName}: " . $e->getMessage());
        }
    }

    private function showDetailedDiagnostics(SymfonyStyle $io, string $fileName, array $stats): void
    {
        $filePath = $this->projectRoot . '/.cpm/db/' . $fileName . '.json';
        
        // File permissions
        if (file_exists($filePath)) {
            $perms = fileperms($filePath);
            $io->writeln(sprintf('File permissions: %o', $perms & 0777));
            
            // Process information
            if (function_exists('posix_getpwuid') && function_exists('posix_getgrgid')) {
                $owner = posix_getpwuid(fileowner($filePath));
                $group = posix_getgrgid(filegroup($filePath));
                $io->writeln(sprintf('Owner: %s, Group: %s', $owner['name'] ?? 'unknown', $group['name'] ?? 'unknown'));
            }
            
            // Check for multiple processes
            $this->checkProcessAccess($io, $filePath);
        }
    }

    private function checkProcessAccess(SymfonyStyle $io, string $filePath): void
    {
        // Use lsof to check which processes have the file open
        $cmd = "lsof " . escapeshellarg($filePath) . " 2>/dev/null || true";
        $output = shell_exec($cmd);
        
        if ($output !== null && !empty(trim($output))) {
            $io->writeln('Processes accessing file:');
            $io->writeln($output);
        }
    }

    private function checkForStaleProcesses(SymfonyStyle $io): void
    {
        $io->section('🔍 Checking for Stale Processes');
        
        $pidFile = $this->projectRoot . '/.cpm/logs/monitor.pid';
        
        if (file_exists($pidFile)) {
            $pid = trim(file_get_contents($pidFile));
            
            if ($pid && is_numeric($pid)) {
                // Check if process is still running
                $running = posix_kill((int)$pid, 0);
                
                if (!$running) {
                    $io->warning("Stale monitor PID file found: {$pid} (process not running)");
                    $io->writeln("Consider removing: {$pidFile}");
                } else {
                    $io->writeln("Monitor daemon running with PID: {$pid}");
                }
            }
        } else {
            $io->writeln('No monitor daemon PID file found');
        }
    }

    private function monitorFileAccess(SymfonyStyle $io, array $files): int
    {
        $io->writeln('🔍 Monitoring file access patterns (Ctrl+C to stop)...');
        
        $lastStats = [];
        
        while (true) {
            $io->writeln("\n" . date('H:i:s') . ' - Checking files...');
            
            foreach ($files as $fileName) {
                try {
                    $currentStats = $this->database->getDiagnostics($fileName);
                    $previous = $lastStats[$fileName] ?? null;
                    
                    if ($previous && $currentStats['last_modified'] !== $previous['last_modified']) {
                        $io->writeln("📝 {$fileName}.json was modified");
                    }
                    
                    if ($currentStats['lock_exists'] && (!$previous || !$previous['lock_exists'])) {
                        $io->writeln("🔒 {$fileName}.json is now locked");
                    }
                    
                    if (!$currentStats['lock_exists'] && $previous && $previous['lock_exists']) {
                        $io->writeln("🔓 {$fileName}.json lock released");
                    }
                    
                    $lastStats[$fileName] = $currentStats;
                    
                } catch (\Exception $e) {
                    $io->writeln("❌ Error checking {$fileName}: " . $e->getMessage());
                }
            }
            
            sleep(2);
        }
        
        return Command::SUCCESS;
    }

    private function showRecommendations(SymfonyStyle $io): void
    {
        $io->section('💡 Recommendations');
        
        $recommendations = [
            'Ensure monitoring daemon is not running during heavy development',
            'Use safeUpdate() method for concurrent-safe database operations',
            'Check file permissions if write errors persist',
            'Monitor for stale lock files older than 30 seconds',
            'Run diagnostics with --detailed for process-level information'
        ];
        
        $io->listing($recommendations);
        
        $io->note([
            'Common solutions for "Error writing file":',
            '1. Stop monitoring daemon: cpm monitor --stop',
            '2. Check file permissions: ls -la .cpm/db/',
            '3. Remove stale locks: rm .cpm/db/*.lock',
            '4. Use safeUpdate() in code instead of direct write()'
        ]);
    }

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $factor = floor((strlen((string)$bytes) - 1) / 3);
        return sprintf('%.1f %s', $bytes / pow(1024, $factor), $units[$factor]);
    }
}