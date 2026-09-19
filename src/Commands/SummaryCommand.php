<?php

declare(strict_types=1);

namespace ClaudeProjectManager\Commands;

use ClaudeProjectManager\DatabaseManager;
use ClaudeProjectManager\Services\StateSummaryGenerator;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Generate lightweight project summary
 * Provides fast access to key project metrics without loading full STATE.json
 */
class SummaryCommand extends Command
{
    protected static $defaultName = 'summary';
    protected static $defaultDescription = 'Generate lightweight project summary';
    
    private DatabaseManager $database;
    private StateSummaryGenerator $summaryGenerator;
    
    public function __construct(DatabaseManager $database, StateSummaryGenerator $summaryGenerator)
    {
        $this->database = $database;
        $this->summaryGenerator = $summaryGenerator;
        parent::__construct();
    }
    
    protected function configure(): void
    {
        $this
            ->addOption('lightweight', 'l', InputOption::VALUE_NONE, 'Generate optimized summary')
            ->addOption('json', 'j', InputOption::VALUE_NONE, 'Output as JSON')
            ->addOption('refresh', 'r', InputOption::VALUE_NONE, 'Force regenerate summary')
            ->setHelp('
The <info>summary</info> command generates a lightweight project summary for quick overview.

<info>Examples:</info>
  <comment>Generate and display summary:</comment>
    cpm summary

  <comment>Output as JSON:</comment>
    cpm summary --json

  <comment>Force refresh summary:</comment>
    cpm summary --refresh

This command is much faster than reading the full STATE.json file and provides
essential project information including completion status, recent activity, and
critical metrics.
            ');
    }
    
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        
        try {
            // Check if we need to regenerate or use cached version
            $forceRefresh = $input->getOption('refresh');
            $summary = $this->getSummary($forceRefresh);
            
            if ($input->getOption('json')) {
                $output->writeln(json_encode($summary));
                return Command::SUCCESS;
            }
            
            // Display human-readable summary
            $this->displayHumanReadableSummary($io, $summary);
            
            return Command::SUCCESS;
            
        } catch (\Exception $e) {
            $io->error("Failed to generate summary: " . $e->getMessage());
            return Command::FAILURE;
        }
    }
    
    /**
     * Get summary data, using cache if available and not forcing refresh
     * @param bool $forceRefresh Force regeneration of summary
     * @return array Summary data
     */
    private function getSummary(bool $forceRefresh): array
    {
        // Try to get cached summary if not forcing refresh
        if (!$forceRefresh) {
            try {
                $cached = $this->database->read('state_summary');
                if ($this->isSummaryCurrent($cached)) {
                    return $cached;
                }
            } catch (\Exception $e) {
                // Cache doesn't exist or is invalid, generate new one
            }
        }
        
        // Generate fresh summary
        $summary = $this->summaryGenerator->generateSummary();
        $this->database->write('state_summary', $summary);
        
        return $summary;
    }
    
    /**
     * Check if cached summary is still current (less than 5 minutes old)
     * @param array $summary Cached summary data
     * @return bool True if summary is current
     */
    private function isSummaryCurrent(array $summary): bool
    {
        if (empty($summary['generated_at'])) {
            return false;
        }
        
        $generatedAt = strtotime($summary['generated_at']);
        $now = time();
        $maxAge = 5 * 60; // 5 minutes
        
        return ($now - $generatedAt) < $maxAge;
    }
    
    /**
     * Display human-readable summary
     * @param SymfonyStyle $io Console style helper
     * @param array $summary Summary data to display
     */
    private function displayHumanReadableSummary(SymfonyStyle $io, array $summary): void
    {
        $io->title('📊 Project Summary');
        
        // Project Overview
        $overview = $summary['project_overview'];
        $io->section('🎯 Project Overview');
        $io->definitionList(
            ['Project Name' => $overview['name']],
            ['Framework' => $overview['framework']],
            ['Primary Language' => $overview['language']],
            ['Total Files' => number_format($overview['total_files'])],
            ['Total Functions' => number_format($overview['total_functions'])],
            ['Last Analysis' => $overview['analyzed_at'] ? date('Y-m-d H:i:s', strtotime($overview['analyzed_at'])) : 'Never']
        );
        
        // Completion Summary
        $completion = $summary['completion_summary'];
        $io->section('✅ Completion Status');
        
        // Progress bar visualization
        $percentage = $completion['completion_percentage'];
        $progressBar = $this->createProgressBar($percentage);
        
        $io->text([
            "Progress: <comment>$progressBar</comment> <info>{$percentage}%</info>",
            "Completed: <info>{$completion['completed']}</info> functions",
            "In Progress: <comment>{$completion['in_progress']}</comment> functions", 
            "Pending: <comment>{$completion['pending']}</comment> functions"
        ]);
        
        // Recent Activity
        $activity = $summary['recent_activity'];
        $io->section('🔄 Recent Activity');
        $io->definitionList(
            ['Last Session' => $activity['last_session'] ? date('Y-m-d H:i:s', strtotime($activity['last_session'])) : 'No sessions'],
            ['Files Modified Today' => $activity['files_modified_today']],
            ['Functions Completed Today' => $activity['functions_completed_today']]
        );
        
        // Recent changes
        if (!empty($activity['recent_changes'])) {
            $io->text('<info>Recent Changes:</info>');
            foreach (array_slice($activity['recent_changes'], 0, 3) as $change) {
                $time = date('H:i', strtotime($change['timestamp']));
                $io->text("  • <comment>$time</comment> {$change['action']} <info>{$change['function']}</info> in {$change['file']}");
            }
        }
        
        // Critical Metrics
        $metrics = $summary['critical_metrics'];
        $io->section('⚠️  Critical Metrics');
        
        $criticalColor = $metrics['critical_issues'] > 0 ? 'error' : 'info';
        $complexityColor = $metrics['high_complexity_functions'] > 10 ? 'comment' : 'info';
        $docsColor = $metrics['missing_documentation'] > 20 ? 'comment' : 'info';
        
        $io->text([
            "Critical Issues: <{$criticalColor}>{$metrics['critical_issues']}</{$criticalColor}>",
            "High Complexity Functions: <{$complexityColor}>{$metrics['high_complexity_functions']}</{$complexityColor}>",
            "Missing Documentation: <{$docsColor}>{$metrics['missing_documentation']}</{$docsColor}>",
            "Type Coverage: <info>{$metrics['type_coverage']}%</info>"
        ]);
        
        // File Summary
        $fileSummary = $summary['file_summary'];
        $io->section('📁 File Analysis');
        
        $statusStats = $fileSummary['by_status'];
        $io->text([
            "Fully Analyzed: <info>{$statusStats['fully_analyzed']}</info> files",
            "Partially Analyzed: <comment>{$statusStats['partially_analyzed']}</comment> files", 
            "Pending Analysis: <comment>{$statusStats['pending_analysis']}</comment> files"
        ]);
        
        // Language breakdown
        if (!empty($fileSummary['by_language'])) {
            $io->text('<info>Languages:</info>');
            foreach ($fileSummary['by_language'] as $lang => $count) {
                $io->text("  • $lang: $count files");
            }
        }
        
        // Footer
        $generatedAt = date('Y-m-d H:i:s', strtotime($summary['generated_at']));
        $io->newLine();
        $io->text("<comment>Summary generated at: $generatedAt</comment>");
        $io->text('<info>💡 Use --json for machine-readable output</info>');
    }
    
    /**
     * Create ASCII progress bar
     * @param float $percentage Completion percentage
     * @return string Progress bar string
     */
    private function createProgressBar(float $percentage): string
    {
        $barLength = 20;
        $filledLength = (int) round($barLength * $percentage / 100);
        $emptyLength = $barLength - $filledLength;
        
        return '[' . str_repeat('█', $filledLength) . str_repeat('░', $emptyLength) . ']';
    }
}