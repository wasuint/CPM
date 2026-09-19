<?php

declare(strict_types=1);

namespace ClaudeProjectManager\Commands;

use ClaudeProjectManager\DatabaseManager;
use ClaudeProjectManager\Analysis\DependencyAnalyzer;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Analyze and display project dependencies
 * Shows import/export relationships between files
 */
class DependenciesCommand extends Command
{
    protected static $defaultName = 'dependencies';
    protected static $defaultDescription = 'Analyze project dependencies';
    
    private DatabaseManager $database;
    private DependencyAnalyzer $dependencyAnalyzer;
    
    public function __construct(DatabaseManager $database, DependencyAnalyzer $dependencyAnalyzer)
    {
        $this->database = $database;
        $this->dependencyAnalyzer = $dependencyAnalyzer;
        parent::__construct();
    }
    
    protected function configure(): void
    {
        $this
            ->addOption('show', 's', InputOption::VALUE_REQUIRED, 'Show dependencies for specific file')
            ->addOption('graph', 'g', InputOption::VALUE_NONE, 'Show dependency graph statistics')
            ->addOption('circular', 'c', InputOption::VALUE_NONE, 'Check for circular dependencies')
            ->addOption('refresh', 'r', InputOption::VALUE_NONE, 'Refresh dependency analysis')
            ->addOption('json', 'j', InputOption::VALUE_NONE, 'Output as JSON')
            ->setHelp('
The <info>dependencies</info> command analyzes import/export relationships between files.

<info>Examples:</info>
  <comment>Show dependencies for specific file:</comment>
    cpm dependencies --show src/MyClass.php

  <comment>Check for circular dependencies:</comment>
    cpm dependencies --circular

  <comment>Show dependency graph overview:</comment>
    cpm dependencies --graph

  <comment>Refresh dependency analysis:</comment>
    cpm dependencies --refresh
            ');
    }
    
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        
        try {
            // Refresh dependencies if requested
            if ($input->getOption('refresh')) {
                $io->text('🔄 Refreshing dependency analysis...');
                $this->refreshDependencies($io);
            }
            
            if ($file = $input->getOption('show')) {
                return $this->showFileDependencies($file, $input, $output, $io);
            }
            
            if ($input->getOption('circular')) {
                return $this->checkCircularDependencies($input, $output, $io);
            }
            
            if ($input->getOption('graph')) {
                return $this->showDependencyGraph($input, $output, $io);
            }
            
            return $this->showDependencyOverview($input, $output, $io);
            
        } catch (\Exception $e) {
            $io->error("Failed to analyze dependencies: " . $e->getMessage());
            return Command::FAILURE;
        }
    }
    
    /**
     * Show dependencies for a specific file
     */
    private function showFileDependencies(string $file, InputInterface $input, OutputInterface $output, SymfonyStyle $io): int
    {
        $dependencies = $this->database->read('dependencies');
        
        if (!isset($dependencies['files'][$file])) {
            $io->error("File not found in dependency analysis: $file");
            return Command::FAILURE;
        }
        
        $fileData = $dependencies['files'][$file];
        
        if ($input->getOption('json')) {
            $output->writeln(json_encode($fileData));
            return Command::SUCCESS;
        }
        
        $io->title("📁 Dependencies for: $file");
        
        // Imports section
        if (!empty($fileData['imports'])) {
            $io->section('📥 Imports (' . count($fileData['imports']) . ')');
            foreach ($fileData['imports'] as $import) {
                $io->text("  • $import");
            }
        } else {
            $io->section('📥 Imports');
            $io->text('  No imports found');
        }
        
        // Exports section
        if (!empty($fileData['exports'])) {
            $io->section('📤 Exports (' . count($fileData['exports']) . ')');
            foreach ($fileData['exports'] as $export) {
                if (is_array($export)) {
                    $visibility = $export['visibility'] ?? 'public';
                    $type = $export['type'] ?? 'unknown';
                    $name = $export['name'] ?? 'unknown';
                    $io->text("  • $name ($type, $visibility)");
                } else {
                    $io->text("  • $export");
                }
            }
        } else {
            $io->section('📤 Exports');
            $io->text('  No exports found');
        }
        
        // Imported by section
        if (!empty($fileData['imported_by'])) {
            $io->section('🔗 Imported By (' . count($fileData['imported_by']) . ')');
            foreach ($fileData['imported_by'] as $dependent) {
                $io->text("  • $dependent");
            }
        } else {
            $io->section('🔗 Imported By');
            $io->text('  No files import this');
        }
        
        // File dependencies (includes/requires)
        if (!empty($fileData['file_dependencies'])) {
            $io->section('📋 File Dependencies');
            foreach ($fileData['file_dependencies'] as $fileDep) {
                $io->text("  • $fileDep");
            }
        }
        
        // Metadata
        $io->section('📊 Metadata');
        $io->definitionList(
            ['Language' => $fileData['language'] ?? 'unknown'],
            ['Dependency Depth' => (string)($fileData['dependency_depth'] ?? 0)]
        );
        
        return Command::SUCCESS;
    }
    
    /**
     * Check for circular dependencies
     */
    private function checkCircularDependencies(InputInterface $input, OutputInterface $output, SymfonyStyle $io): int
    {
        $circular = $this->dependencyAnalyzer->detectCircularDependencies();
        
        if ($input->getOption('json')) {
            $output->writeln(json_encode($circular));
            return Command::SUCCESS;
        }
        
        $io->title('🔄 Circular Dependencies Analysis');
        
        if (empty($circular)) {
            $io->success('✅ No circular dependencies detected!');
            return Command::SUCCESS;
        }
        
        $io->warning("⚠️ Found " . count($circular) . " circular dependency chains:");
        
        foreach ($circular as $index => $cycle) {
            $io->section("Chain #" . ($index + 1));
            $cycleString = implode(' → ', $cycle) . ' → ' . $cycle[0];
            $io->text("  $cycleString");
        }
        
        $io->newLine();
        $io->text('<comment>💡 Circular dependencies can cause issues with:</comment>');
        $io->text('  • Module loading and initialization order');
        $io->text('  • Testing and mocking');
        $io->text('  • Code maintainability');
        $io->newLine();
        $io->text('<info>Consider refactoring to break these cycles.</info>');
        
        return Command::SUCCESS;
    }
    
    /**
     * Show dependency graph statistics
     */
    private function showDependencyGraph(InputInterface $input, OutputInterface $output, SymfonyStyle $io): int
    {
        $dependencies = $this->database->read('dependencies');
        $graph = $dependencies['dependency_graph'] ?? [];
        
        if ($input->getOption('json')) {
            $output->writeln(json_encode($graph));
            return Command::SUCCESS;
        }
        
        $io->title('📊 Dependency Graph Statistics');
        
        $stats = $graph['statistics'] ?? [];
        $chains = $graph['dependency_chains'] ?? [];
        
        // Overview statistics
        $io->section('📈 Overview');
        $io->definitionList(
            ['Total Files' => number_format($stats['total_files'] ?? 0)],
            ['Total Dependencies' => number_format($stats['total_dependencies'] ?? 0)],
            ['Average Dependency Depth' => (string)($stats['average_depth'] ?? 0)],
            ['Isolated Files' => number_format($stats['isolated_files'] ?? 0)],
            ['Circular Dependencies' => number_format($stats['circular_dependencies_count'] ?? 0)]
        );
        
        // Dependency chains
        $io->section('🔗 Dependency Chains');
        $io->definitionList(
            ['Longest Chain Depth' => (string)($chains['longest_chain'] ?? 0)],
            ['Most Depended On File' => $chains['most_depended_on'] ?? 'None']
        );
        
        // Health indicators
        $io->section('🏥 Health Indicators');
        $totalFiles = $stats['total_files'] ?? 1;
        $isolatedPercentage = round(($stats['isolated_files'] ?? 0) / $totalFiles * 100, 1);
        $avgDependencies = round(($stats['total_dependencies'] ?? 0) / $totalFiles, 1);
        
        $isolatedColor = $isolatedPercentage > 30 ? 'comment' : 'info';
        $avgDepColor = $avgDependencies > 10 ? 'comment' : 'info';
        $circularColor = ($stats['circular_dependencies_count'] ?? 0) > 0 ? 'error' : 'info';
        
        $io->text([
            "Isolated Files: <{$isolatedColor}>{$isolatedPercentage}%</{$isolatedColor}>",
            "Average Dependencies per File: <{$avgDepColor}>$avgDependencies</{$avgDepColor}>",
            "Circular Dependencies: <{$circularColor}>" . ($stats['circular_dependencies_count'] ?? 0) . "</{$circularColor}>"
        ]);
        
        return Command::SUCCESS;
    }
    
    /**
     * Show general dependency overview
     */
    private function showDependencyOverview(InputInterface $input, OutputInterface $output, SymfonyStyle $io): int
    {
        $dependencies = $this->database->read('dependencies');
        
        if ($input->getOption('json')) {
            $output->writeln(json_encode($dependencies));
            return Command::SUCCESS;
        }
        
        $io->title('🔗 Project Dependencies Overview');
        
        $totalFiles = count($dependencies['files'] ?? []);
        $generatedAt = $dependencies['generated_at'];
        
        if ($totalFiles === 0) {
            $io->warning('No dependency data found. Run with --refresh to analyze dependencies.');
            return Command::SUCCESS;
        }
        
        // Basic statistics
        $io->section('📊 Statistics');
        $io->text("Total Files Analyzed: <info>$totalFiles</info>");
        $io->text("Last Analysis: <comment>" . ($generatedAt ? date('Y-m-d H:i:s', strtotime($generatedAt)) : 'Never') . "</comment>");
        
        // Language breakdown
        $languageStats = [];
        foreach ($dependencies['files'] as $fileData) {
            $lang = $fileData['language'] ?? 'unknown';
            $languageStats[$lang] = ($languageStats[$lang] ?? 0) + 1;
        }
        
        if (!empty($languageStats)) {
            $io->section('🗣️ Languages');
            foreach ($languageStats as $lang => $count) {
                $percentage = round($count / $totalFiles * 100, 1);
                $io->text("$lang: <info>$count files</info> ($percentage%)");
            }
        }
        
        // Top imported files
        $importCounts = [];
        foreach ($dependencies['files'] as $filePath => $fileData) {
            $importedBy = count($fileData['imported_by'] ?? []);
            if ($importedBy > 0) {
                $importCounts[$filePath] = $importedBy;
            }
        }
        
        if (!empty($importCounts)) {
            arsort($importCounts);
            $io->section('🔝 Most Imported Files');
            $top5 = array_slice($importCounts, 0, 5, true);
            foreach ($top5 as $file => $count) {
                $io->text("$file: <info>$count imports</info>");
            }
        }
        
        // Quick health check
        $graph = $dependencies['dependency_graph'] ?? [];
        $circularCount = count($graph['circular_dependencies'] ?? []);
        
        $io->newLine();
        if ($circularCount > 0) {
            $io->warning("⚠️ $circularCount circular dependencies detected!");
            $io->text('Run with --circular to see details.');
        } else {
            $io->text('✅ No circular dependencies detected.');
        }
        
        $io->newLine();
        $io->text('<comment>💡 Available options:</comment>');
        $io->text('  --show <file>    Show dependencies for specific file');
        $io->text('  --circular       Check for circular dependencies');  
        $io->text('  --graph          Show dependency graph statistics');
        $io->text('  --refresh        Refresh dependency analysis');
        
        return Command::SUCCESS;
    }
    
    /**
     * Refresh dependency analysis
     */
    private function refreshDependencies(SymfonyStyle $io): void
    {
        try {
            $inventory = $this->database->read('inventory');
            $files = $inventory['files'] ?? [];

            // Inventory stores files as a list, but dependency analyzer expects
            // an associative array keyed by file path. Convert the list when needed.
            if (!empty($files) && array_keys($files) === range(0, count($files) - 1)) {
                $indexedFiles = [];
                foreach ($files as $fileEntry) {
                    if (!is_array($fileEntry)) {
                        continue;
                    }

                    $key = $fileEntry['path']
                        ?? $fileEntry['relative_path']
                        ?? null;

                    if ($key === null) {
                        continue;
                    }

                    $indexedFiles[$key] = $fileEntry;
                }

                if (!empty($indexedFiles)) {
                    $files = $indexedFiles;
                }
            }

            if (empty($files)) {
                $io->warning('No files found in inventory. Run project analysis first.');
                return;
            }
            
            $this->dependencyAnalyzer->analyzeDependencies($files);
            $io->success('✅ Dependencies refreshed successfully.');
            
        } catch (\Exception $e) {
            $io->error('Failed to refresh dependencies: ' . $e->getMessage());
            throw $e;
        }
    }
}
