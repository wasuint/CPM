<?php

declare(strict_types=1);

namespace ClaudeProjectManager\Commands;

use ClaudeProjectManager\DatabaseManager;
use ClaudeProjectManager\Services\DocumentationCompactor;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class CompactCommand extends Command
{
    protected static $defaultName = 'compact';
    protected static $defaultDescription = 'Compact project documentation files for optimal Claude Code performance';

    private DatabaseManager $database;
    private DocumentationCompactor $compactor;
    private string $projectRoot;

    public function __construct(DatabaseManager $database, string $projectRoot)
    {
        parent::__construct();
        $this->database = $database;
        $this->projectRoot = $projectRoot;
        $this->compactor = new DocumentationCompactor($database, $projectRoot);
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Compact large documentation files to improve Claude Code performance')
            ->setHelp('This command compacts .claude.md and context files when they become too large')
            ->addOption('target', null, InputOption::VALUE_REQUIRED, 'Target file to compact (claude-md, context, all)', 'all')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Force compaction even if files are below threshold')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would be compacted without making changes');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $target = $input->getOption('target');
        $force = $input->getOption('force');
        $dryRun = $input->getOption('dry-run');

        $io->title('📦 Claude Project Manager - Documentation Compaction');

        // Check file sizes
        $files = $this->analyzeFiles();
        
        $io->section('Current File Sizes');
        $io->table(
            ['File', 'Size', 'Status'],
            array_map(function($file) {
                return [
                    $file['name'],
                    $this->formatBytes($file['size']),
                    $file['needs_compaction'] ? '⚠️ Needs compaction' : '✅ OK'
                ];
            }, $files)
        );

        $needsCompaction = array_filter($files, fn($f) => $f['needs_compaction']);
        
        if (empty($needsCompaction) && !$force) {
            $io->success('All documentation files are within optimal size limits.');
            return Command::SUCCESS;
        }

        if ($dryRun) {
            $io->note('Dry run mode - no changes will be made');
            
            foreach ($needsCompaction as $file) {
                $io->writeln(sprintf(
                    'Would compact: %s (current: %s, target: ~30KB)',
                    $file['name'],
                    $this->formatBytes($file['size'])
                ));
            }
            
            return Command::SUCCESS;
        }

        // Perform compaction
        $results = [];
        
        if ($target === 'all' || $target === 'claude-md') {
            if ($files['.claude.md']['needs_compaction'] || $force) {
                $io->section('Compacting .claude.md');
                $result = $this->compactor->compactClaudeMd();
                
                if ($result['success']) {
                    $io->success(sprintf(
                        'Compacted .claude.md: %s → %s (%.1f%% reduction)',
                        $this->formatBytes($result['original_size']),
                        $this->formatBytes($result['new_size']),
                        $result['reduction']
                    ));
                    $io->writeln('Backup saved to: ' . basename($result['backup_path']));
                } else {
                    $io->warning($result['message']);
                }
                $results['claude-md'] = $result;
            }
        }

        if ($target === 'all' || $target === 'context') {
            $io->section('Compacting Context Files');
            $contextResults = $this->compactor->compactContextFiles();
            
            foreach ($contextResults as $file => $result) {
                $io->success(sprintf(
                    'Compacted %s: %s → %s (%.1f%% reduction)',
                    $file,
                    $this->formatBytes($result['original_size']),
                    $this->formatBytes($result['new_size']),
                    $result['reduction']
                ));
            }
            $results['context'] = $contextResults;
        }

        // Display Claude Code guidance
        $this->displayGuidance($io);

        return Command::SUCCESS;
    }

    /**
     * Analyze documentation files
     */
    private function analyzeFiles(): array
    {
        $files = [];
        
        $paths = [
            '.claude.md' => $this->projectRoot . '/.claude.md',
            '.cpm/context/DIGEST.md' => $this->projectRoot . '/.cpm/context/DIGEST.md',
            '.cpm/context/STATE.json' => $this->projectRoot . '/.cpm/context/STATE.json'
        ];
        
        foreach ($paths as $name => $path) {
            $size = file_exists($path) ? filesize($path) : 0;
            $threshold = strpos($name, '.json') !== false ? 100000 : 50000;
            
            $files[$name] = [
                'name' => $name,
                'path' => $path,
                'size' => $size,
                'needs_compaction' => $size > $threshold,
                'exists' => file_exists($path)
            ];
        }
        
        return $files;
    }

    /**
     * Format bytes to human readable
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $factor = floor((strlen((string)$bytes) - 1) / 3);
        return sprintf('%.1f %s', $bytes / pow(1024, $factor), $units[$factor]);
    }

    /**
     * Display guidance for Claude Code
     */
    private function displayGuidance(SymfonyStyle $io): void
    {
        $io->section('📚 Claude Code Guidance');
        
        $io->listing([
            'Documentation files have been optimized for performance',
            'Essential project information has been preserved',
            'Update .claude.md when discovering new patterns',
            'Run this command periodically to maintain optimal file sizes',
            'Use "cpm compact --dry-run" to preview changes'
        ]);
        
        $io->note([
            'Automatic compaction reminders will appear in status output',
            'Files over 50KB will trigger compaction suggestions',
            'Backups are created before any compaction'
        ]);
    }
}