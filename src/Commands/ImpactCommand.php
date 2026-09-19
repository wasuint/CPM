<?php

declare(strict_types=1);

namespace ClaudeProjectManager\Commands;

use ClaudeProjectManager\DatabaseManager;
use ClaudeProjectManager\Analysis\ImpactAnalyzer;
use ClaudeProjectManager\Analysis\DependencyAnalyzer;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Analyze the impact of potential changes to files or functions
 * Shows what might be affected when making modifications
 */
class ImpactCommand extends Command
{
    protected static $defaultName = 'impact';
    protected static $defaultDescription = 'Analyze change impact';
    
    private DatabaseManager $database;
    private ImpactAnalyzer $impactAnalyzer;
    
    public function __construct(DatabaseManager $database, ImpactAnalyzer $impactAnalyzer)
    {
        $this->database = $database;
        $this->impactAnalyzer = $impactAnalyzer;
        parent::__construct();
    }
    
    protected function configure(): void
    {
        $this
            ->addOption('file', 'f', InputOption::VALUE_REQUIRED, 'Analyze impact for specific file')
            ->addOption('function', null, InputOption::VALUE_REQUIRED, 'Analyze impact for specific function')
            ->addOption('in-file', null, InputOption::VALUE_REQUIRED, 'File containing the function (use with --function)')
            ->addOption('json', 'j', InputOption::VALUE_NONE, 'Output as JSON')
            ->addOption('cached', null, InputOption::VALUE_NONE, 'Use cached analysis if available')
            ->setHelp('
The <info>impact</info> command analyzes the potential impact of changes to files or functions.

<info>Examples:</info>
  <comment>Analyze impact of changing a file:</comment>
    cpm impact --file src/UserService.php

  <comment>Analyze impact of changing a specific function:</comment>
    cpm impact --function validateUser --in-file src/UserService.php

  <comment>Show detailed analysis:</comment>
    cpm impact --file src/UserService.php --verbose

  <comment>Output as JSON:</comment>
    cpm impact --file src/UserService.php --json

This command helps you understand what files, functions, and tests might be affected
by your changes, allowing you to plan modifications more safely.
            ');
    }
    
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        
        try {
            $file = $input->getOption('file');
            $function = $input->getOption('function');
            $inFile = $input->getOption('in-file');
            
            // Validate input
            if (!$file && !$function) {
                $io->error('Please specify either --file or --function to analyze.');
                return Command::FAILURE;
            }
            
            if ($function && !$inFile) {
                $io->error('When using --function, you must also specify --in-file.');
                return Command::FAILURE;
            }
            
            if ($file) {
                return $this->analyzeFileImpact($file, $input, $output, $io);
            }
            
            if ($function && $inFile) {
                return $this->analyzeFunctionImpact($function, $inFile, $input, $output, $io);
            }
            
            return Command::FAILURE;
            
        } catch (\Exception $e) {
            $io->error("Failed to analyze impact: " . $e->getMessage());
            return Command::FAILURE;
        }
    }
    
    /**
     * Analyze impact of file changes
     */
    private function analyzeFileImpact(string $file, InputInterface $input, OutputInterface $output, SymfonyStyle $io): int
    {
        // Check if file exists
        if (!file_exists($file)) {
            $io->error("File not found: $file");
            return Command::FAILURE;
        }
        
        // Try to use cached analysis if requested
        if ($input->getOption('cached')) {
            $cached = $this->impactAnalyzer->getCachedAnalysis($file);
            if ($cached) {
                $io->text('<comment>Using cached analysis...</comment>');
                $result = $cached;
            } else {
                $io->text('<comment>No valid cache found, performing fresh analysis...</comment>');
                $result = $this->impactAnalyzer->analyzeFileImpact($file);
            }
        } else {
            $io->text('<comment>Analyzing file impact...</comment>');
            $result = $this->impactAnalyzer->analyzeFileImpact($file);
        }
        
        // Output results
        if ($input->getOption('json')) {
            $output->writeln(json_encode($result->toArray(), ));
            return Command::SUCCESS;
        }
        
        // Display formatted results
        $lines = $result->formatForCli();
        foreach ($lines as $line) {
            $io->text($line);
        }
        
        // Additional verbose information
        if ($input->getOption('verbose')) {
            $this->showVerboseFileAnalysis($result, $io);
        }
        
        // Show risk summary
        $this->showRiskSummary($result, $io);
        
        return Command::SUCCESS;
    }
    
    /**
     * Analyze impact of function changes
     */
    private function analyzeFunctionImpact(string $function, string $file, InputInterface $input, OutputInterface $output, SymfonyStyle $io): int
    {
        // Check if file exists
        if (!file_exists($file)) {
            $io->error("File not found: $file");
            return Command::FAILURE;
        }
        
        $io->text('<comment>Analyzing function impact...</comment>');
        $result = $this->impactAnalyzer->analyzeFunctionImpact($function, $file);
        
        // Output results
        if ($input->getOption('json')) {
            $output->writeln(json_encode($result->toArray(), ));
            return Command::SUCCESS;
        }
        
        // Display formatted results
        $lines = $result->formatForCli();
        foreach ($lines as $line) {
            $io->text($line);
        }
        
        // Additional verbose information
        if ($input->getOption('verbose')) {
            $this->showVerboseFunctionAnalysis($result, $io);
        }
        
        return Command::SUCCESS;
    }
    
    /**
     * Show verbose file analysis information
     */
    private function showVerboseFileAnalysis($result, SymfonyStyle $io): void
    {
        $io->newLine();
        $io->section('🔍 Detailed Analysis');
        
        // File statistics
        $dependencies = $this->database->read('dependencies');
        $fileData = $dependencies['files'][$result->targetFile] ?? null;
        
        if ($fileData) {
            $io->text('<info>File Dependencies:</info>');
            $io->text("  • Imports: " . count($fileData['imports'] ?? []));
            $io->text("  • Exports: " . count($fileData['exports'] ?? []));
            $io->text("  • Dependency Depth: " . ($fileData['dependency_depth'] ?? 0));
            $io->text("  • Language: " . ($fileData['language'] ?? 'unknown'));
        }
        
        // Risk factors
        $io->newLine();
        $io->text('<info>Risk Factors:</info>');
        
        if ($result->isHighRisk()) {
            $io->text('  🔴 HIGH RISK CHANGE');
            $io->text('  • This change could have significant impact');
            $io->text('  • Consider extensive testing and gradual rollout');
        } else {
            $io->text('  🟡 Moderate risk - normal precautions recommended');
        }
        
        // Test coverage information
        $testFiles = array_filter($result->directlyAffected, [$this, 'isTestFile']);
        if (!empty($testFiles)) {
            $io->text("  ✅ Test files found: " . count($testFiles));
        } else {
            $io->text("  ⚠️ No test files detected - consider adding tests");
        }
    }
    
    /**
     * Show verbose function analysis information
     */
    private function showVerboseFunctionAnalysis($result, SymfonyStyle $io): void
    {
        $io->newLine();
        $io->section('🔍 Detailed Function Analysis');
        
        // Function characteristics
        $io->text('<info>Function Characteristics:</info>');
        $io->text("  • Public API: " . ($result->isPublicApi ? 'Yes' : 'No'));
        $io->text("  • Usage Count: {$result->usageCount} files");
        $io->text("  • Breaking Change Risk: {$result->breakingChangeRisk}");
        
        // Safety assessment
        $io->newLine();
        $io->text('<info>Safety Assessment:</info>');
        
        if ($result->isSafeToModify()) {
            $io->text('  ✅ Relatively safe to modify');
        } else {
            $io->text('  ⚠️ Modifications require careful consideration');
            $io->text('  • High usage or public API function');
            $io->text('  • Consider deprecation process for major changes');
        }
    }
    
    /**
     * Show risk summary
     */
    private function showRiskSummary($result, SymfonyStyle $io): void
    {
        $io->newLine();
        $io->section('⚡ Quick Summary');
        
        $io->text($result->getSummary());
        
        // Action recommendations based on impact
        $io->newLine();
        switch ($result->impactScore) {
            case 'HIGH':
                $io->text('<error>🚨 HIGH IMPACT - Proceed with extreme caution</error>');
                $io->text('  • Plan comprehensive testing strategy');
                $io->text('  • Consider feature flags or gradual rollout');
                $io->text('  • Notify team of potentially breaking changes');
                break;
                
            case 'MEDIUM':
                $io->text('<comment>⚠️ MEDIUM IMPACT - Standard precautions recommended</comment>');
                $io->text('  • Review affected files before deployment');
                $io->text('  • Ensure adequate test coverage');
                break;
                
            case 'LOW':
                $io->text('<info>✅ LOW IMPACT - Minimal risk</info>');
                $io->text('  • Standard testing procedures should be sufficient');
                break;
        }
    }
    
    /**
     * Check if file is a test file
     */
    private function isTestFile(string $filePath): bool
    {
        $testPatterns = [
            '/test/i',
            '/spec/i',
            '/_test\./i',
            '/\.test\./i',
            '/\.spec\./i',
            '/tests\//i',
            '/__tests__\//i'
        ];
        
        foreach ($testPatterns as $pattern) {
            if (preg_match($pattern, $filePath)) {
                return true;
            }
        }
        
        return false;
    }
}