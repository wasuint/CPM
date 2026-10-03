<?php

declare(strict_types=1);

namespace ClaudeProjectManager\Commands;

use ClaudeProjectManager\AnalysisResult;
use ClaudeProjectManager\DatabaseManager;
use ClaudeProjectManager\ConfigManager;
use ClaudeProjectManager\ProjectAnalyzer;
use ClaudeProjectManager\SessionManager;
use ClaudeProjectManager\Services\PermissionManager;
use ClaudeProjectManager\Notifications\TelegramNotifier;
use ClaudeProjectManager\Session\ActivityTracker;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Finder\Finder;

/**
 * Main start command that initializes project analysis and tracking
 */
class StartCommand extends Command
{
    private DatabaseManager $database;
    private ConfigManager $config;
    private ProjectAnalyzer $analyzer;
    private SessionManager $sessionManager;
    private TelegramNotifier $notifier;
    private PermissionManager $permissionManager;

    protected static $defaultName = 'start';
    protected static $defaultDescription = 'Initialize project analysis and begin tracking';

    /**
     * Initialize command with dependencies
     */
    public function __construct(
        DatabaseManager $database,
        ConfigManager $config,
        ProjectAnalyzer $analyzer,
        SessionManager $sessionManager,
        TelegramNotifier $notifier
    ) {
        parent::__construct();
        $this->database = $database;
        $this->config = $config;
        $this->analyzer = $analyzer;
        $this->sessionManager = $sessionManager;
        $this->notifier = $notifier;
        $this->permissionManager = new PermissionManager($config);
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'force',
                'f',
                InputOption::VALUE_NONE,
                'Force re-analysis even if project is already initialized'
            )
            ->addOption(
                'quick',
                null,
                InputOption::VALUE_NONE,
                'Perform quick analysis (skip detailed code parsing)'
            )
            ->addOption(
                'skip-incremental',
                null,
                InputOption::VALUE_NONE,
                'Skip incremental analysis of changed files (faster startup)'
            )
            ->addOption(
                'fix-permissions',
                null,
                InputOption::VALUE_NONE,
                'Automatically fix permission issues if detected'
            )
            ->setHelp('
This command initializes the Claude Project Manager for the current directory.

It will:
- Read configuration from .claude.md
- Scan the entire codebase
- Create a complete function inventory
- Set up progress tracking database
- Start a new session for continuous development
- Send notification when ready (if configured)

Use --force to re-analyze an already initialized project.
Use --quick for faster analysis that skips detailed parsing.
Use --skip-incremental to skip analyzing changed files on resume (faster startup but less accurate).
            ');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $force = $input->getOption('force');
        $quick = $input->getOption('quick');
        
        $io->title('Claude Project Manager - Starting Analysis');
        
        // Universal welcome message
        $io->text('🚀 <info>Universal AI-Enhanced Development Workflow</info>');
        $io->text('   Compatible with: Claude Code, ChatGPT, GitHub Copilot, and other AI assistants');
        $io->text('   Project types: PHP, Python, JavaScript, TypeScript, Mixed codebases');
        $io->newLine();

        try {
            $projectRoot = getcwd();
            
            // Handle migration from legacy directory to .cpm (must be done first)
            $this->handleLegacyMigration($io, $projectRoot);

            // Check and fix permissions early
            $this->checkAndFixPermissions($io, $input->getOption('fix-permissions'));

            // Always publish config files if missing (even on resume)
            $this->publishConfig($io, $projectRoot);

            // Check if already initialized
            if (!$force && $this->isProjectInitialized()) {
                $io->success('✅ Project already initialized - resuming existing session');
                $io->text('💡 Use --force to re-analyze from scratch if needed');
                return $this->resumeExistingProject($io, $input);
            }

            // Step 1: Read configuration
            $io->section('Reading Configuration');
            if (!$this->readConfiguration($io, $projectRoot)) {
                return Command::FAILURE;
            }

            // Step 2: Publish schemas (auto-publish missing schemas)
            $io->section('Publishing Schemas');
            $this->publishSchemas($io, $projectRoot);

            // Step 3: Initialize database
            $io->section('Initializing Database');
            $this->initializeDatabase($io, $projectRoot);

            // Step 4: Analyze project
            $io->section('Analyzing Project');
            $analysisResult = $this->analyzeProject($io, $projectRoot, $quick);
            
            if (!$analysisResult) {
                return Command::FAILURE;
            }

            // Step 5: Setup progress tracking
            $io->section('Setting up Progress Tracking');
            $this->setupProgressTracking($io);

            // Step 6: Start session
            $io->section('Starting Session');
            $sessionContext = $this->startSession($io);

            // Step 7: Generate Claude discovery file
            $io->section('Generating Claude Code Integration');
            $this->generateClaudeDiscoveryFile($io, $analysisResult);

            // Step 8: Send notification
            $this->sendReadyNotification($io, $analysisResult);

            // Step 9: Trigger initial monitoring check
            $io->section('Initial Monitoring Check');
            $this->performInitialMonitoringCheck($io);

            // Display summary
            $this->displaySummary($io, $analysisResult, $sessionContext);

            // LLM Integration instructions
            $io->newLine();
            $io->section('🤖 AI/LLM Integration');
            $io->text('📋 <info>For AI assistants (Claude Code, ChatGPT, Copilot, etc.):</info>');
            $io->text('   1. Read: <comment>CLAUDE_DISCOVERY.md</comment> (universal LLM integration guide)');
            $io->text('   2. Status: <comment>cpm status</comment> (check project progress)');
            $io->text('   3. Context: <comment>cpm context:digest</comment> (generate AI context files)');
            $io->text('   4. Details: <comment>cpm status --detailed</comment> (function-level tracking)');
            $io->newLine();
            $io->text('💡 CPM provides universal AI integration with function-level tracking and session continuity!');

            $io->success('Project initialization completed successfully!');
            return Command::SUCCESS;

        } catch (\Exception $e) {
            $io->error('Failed to initialize project: ' . $e->getMessage());
            error_log('StartCommand failed: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }

    /**
     * Check if project is already initialized
     */
    private function isProjectInitialized(): bool
    {
        try {
            $project = $this->database->read('project');
            return !empty($project['initialized']);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Resume existing project instead of re-initializing
     */
    private function resumeExistingProject(SymfonyStyle $io, InputInterface $input): int
    {
        try {
            $io->section('🔄 Resuming Project Session');
            $io->text('📊 Checking for external changes since last session...');
            
            // Create a simple progress indicator for long operations
            $progressBar = null;
            
            // Hook into error_log to show reanalysis progress
            $originalErrorHandler = set_error_handler(function($errno, $errstr) use ($io, &$progressBar) {
                if (strpos($errstr, 'Starting incremental re-analysis') !== false) {
                    if (preg_match('/(\d+) files/', $errstr, $matches)) {
                        $totalFiles = (int)$matches[1];
                        if ($totalFiles > 50) {
                            $io->text("Large number of changes detected ({$totalFiles} files). This may take a moment...");
                            $progressBar = $io->createProgressBar($totalFiles);
                            $progressBar->setFormat(' %current%/%max% [%bar%] %percent:3s%% %message%');
                            $progressBar->setMessage('Analyzing changed files...');
                            $progressBar->start();
                        }
                    }
                } elseif ($progressBar && strpos($errstr, 'Re-analyzing files:') !== false) {
                    if (preg_match('/(\d+)\/(\d+)/', $errstr, $matches)) {
                        $current = (int)$matches[1];
                        $progressBar->setProgress($current);
                        $progressBar->setMessage("Processing file {$current}");
                    }
                } elseif ($progressBar && strpos($errstr, 'Incremental re-analysis completed') !== false) {
                    $progressBar->finish();
                    $io->newLine(2);
                    $io->text('✓ External changes processed successfully');
                    $progressBar = null;
                }
                return false; // Continue with normal error handling
            });
            
            $skipIncremental = $input->getOption('skip-incremental');
            if ($skipIncremental) {
                $io->text('Skipping incremental analysis (faster startup)...');
            }
            $sessionContext = $this->sessionManager->resumeSession($skipIncremental);
            
            // Restore original error handler
            if ($originalErrorHandler) {
                set_error_handler($originalErrorHandler);
            } else {
                restore_error_handler();
            }
            
            if ($progressBar) {
                $progressBar->finish();
                $io->newLine(2);
            }
            
            $io->text($sessionContext->generateClaudeContext());
            
            $statusReport = $this->sessionManager->generateStatusReport();
            $io->table(
                ['Metric', 'Value'],
                [
                    ['Session Duration', $statusReport['duration_minutes'] . ' minutes'],
                    ['Activities Count', $statusReport['activities_count']],
                    ['Current Status', $statusReport['status']],
                    ['Last Activity', $statusReport['last_activity'] ?? 'None']
                ]
            );

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $io->warning('Failed to resume session: ' . $e->getMessage());
            $io->note('Starting fresh analysis...');
            return Command::SUCCESS;
        }
    }

    /**
     * Read and validate configuration
     */
    private function readConfiguration(SymfonyStyle $io, string $projectRoot): bool
    {
        $configFile = $projectRoot . '/.claude.md';
        
        if (!file_exists($configFile)) {
            $this->createDefaultConfig($configFile);
        }

        try {
            $this->config->loadFromFile($configFile);
            $io->text('✓ Configuration loaded successfully');
            
            // Display key configuration settings
            $settings = $this->config->getAllSettings();
            $io->table(
                ['Setting', 'Value'],
                [
                    ['Project Name', $settings['project_name'] ?? 'Unnamed Project'],
                    ['Analysis Depth', $settings['analysis_depth'] ?? 'full'],
                    ['Telegram Notifications', $this->config->getTelegramConfig()['enabled'] ? 'Enabled' : 'Disabled']
                ]
            );
            
            return true;
        } catch (\Exception $e) {
            $io->error('Failed to load configuration: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Create default configuration file
     */
    private function createDefaultConfig(string $configFile): void
    {
        $defaultConfig = <<<MD
# Claude Project Manager Configuration

## Project Settings
- **Project Name**: My Project
- **Analysis Depth**: full
- **File Size Limit**: 500 lines (for new files only)

## Exclusions
- vendor/
- node_modules/
- .git/
- cache/
- tmp/

## Telegram Notifications (Optional)
- **Enabled**: false
- **Bot Token**: your_bot_token_here
- **Chat ID**: your_chat_id_here

## Development Rules
- Respect legacy code (no refactoring of existing large files)
- Enforce 500-line limit only for new Claude-generated files
- Function-level progress tracking
- Session continuity for resuming work

MD;
        file_put_contents($configFile, $defaultConfig);
    }

    /**
     * Publish schema files to project .cpm/schemas directory
     * Auto-creates missing schema files from CPM resources
     */
    private function publishSchemas(SymfonyStyle $io, string $projectRoot): void
    {
        $schemaSourceDir = dirname(__DIR__, 2) . '/resources/schemas';
        $schemaDestDir = $projectRoot . '/.cpm/schemas';
        
        // Ensure destination directory exists
        if (!is_dir($schemaDestDir)) {
            mkdir($schemaDestDir, 0755, true);
        }
        
        // Get all schema files from source
        $schemaFiles = glob($schemaSourceDir . '/*.json');
        $published = 0;
        $skipped = 0;
        
        foreach ($schemaFiles as $schemaFile) {
            $schemaName = basename($schemaFile);
            $destPath = $schemaDestDir . '/' . $schemaName;
            
            // Only copy if file doesn't exist or is newer
            if (!file_exists($destPath) || filemtime($schemaFile) > filemtime($destPath)) {
                copy($schemaFile, $destPath);
                $published++;
            } else {
                $skipped++;
            }
        }
        
        if ($published > 0) {
            $io->text("✓ Published {$published} schema files");
        }
        if ($skipped > 0) {
            $io->text("• Skipped {$skipped} up-to-date schema files");
        }
    }

    /**
     * Publish config files to project .cpm/config directory
     * Only copies missing config files (never overwrites existing ones)
     */
    private function publishConfig(SymfonyStyle $io, string $projectRoot): void
    {
        $configSourceDir = dirname(__DIR__, 2) . '/config';
        $configDestDir = $projectRoot . '/.cpm/config';

        // Ensure destination directory exists
        if (!is_dir($configDestDir)) {
            mkdir($configDestDir, 0755, true);
        }

        // Get all config files from source
        $configFiles = glob($configSourceDir . '/*.json');
        $published = 0;
        $skipped = 0;

        foreach ($configFiles as $configFile) {
            $configName = basename($configFile);
            $destPath = $configDestDir . '/' . $configName;

            // Only copy if file doesn't exist (never overwrite)
            if (!file_exists($destPath)) {
                copy($configFile, $destPath);
                $published++;
                $io->text("✓ Published config: {$configName}");
            } else {
                $skipped++;
            }
        }

        if ($skipped > 0) {
            $io->text("• Skipped {$skipped} existing config file(s) (no overwrite)");
        }
    }

    /**
     * Initialize project database
     */
    private function initializeDatabase(SymfonyStyle $io, string $projectRoot): void
    {
        // First initialize the database structure (creates JSON files)
        $this->database->initializeDatabase();

        // Then update with project-specific data
        $this->database->update('project', 'root_path', $projectRoot);
        $this->database->update('project', 'name', $this->config->get('project_name', 'Unknown Project'));
        $this->database->update('project', 'type', $this->detectProjectType($projectRoot));
        $this->database->update('project', 'initialized', true);
        $this->database->update('project', 'initialized_at', date('c'));

        $io->text('✓ Database initialized');
    }

    /**
     * Analyze project and create inventory
     */
    private function analyzeProject(SymfonyStyle $io, string $projectRoot, bool $quick): ?AnalysisResult
    {
        try {
            // First, count files to analyze for progress bar
            $totalFiles = $this->countAnalyzableFiles($projectRoot);
            
            if ($totalFiles > 0) {
                $progressBar = $io->createProgressBar($totalFiles);
                $progressBar->setFormat(' %current%/%max% [%bar%] %percent:3s%% %message%');
                $progressBar->setMessage('Scanning files...');
                $progressBar->start();
            } else {
                $progressBar = null;
                $io->text('Analyzing empty project (no files to process)...');
            }

            // Create progress callback
            $progressCallback = $progressBar ? function($current, $total) use ($progressBar) {
                $progressBar->setProgress($current);
                $progressBar->setMessage("Analyzed {$current}/{$total} files");
            } : null;

            $result = $this->analyzer->analyzeProject($projectRoot, $progressCallback);

            if ($progressBar) {
                $progressBar->finish();
                $io->newLine(2);
            }

            $stats = $result->getStatistics();
            $io->table(
                ['Metric', 'Count'],
                [
                    ['Files Analyzed', $stats['total_files'] ?? 0],
                    ['Functions Found', $stats['total_functions'] ?? 0],
                    ['Classes Found', $stats['total_classes'] ?? 0],
                    ['Lines of Code', number_format($stats['total_lines'] ?? 0)],
                    ['Analysis Time', $result->getCompletionTime() . 's']
                ]
            );

            return $result;
        } catch (\Exception $e) {
            $io->error('Analysis failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Setup progress tracking for all discovered functions
     */
    private function setupProgressTracking(SymfonyStyle $io): void
    {
        try {
            $inventory = $this->database->read('inventory');
            $functionCount = count($inventory['functions'] ?? []);
            
            $io->text("Initializing progress tracking for {$functionCount} functions...");
            
            // Read current progress data
            $progress = $this->database->read('progress');
            
            // Batch initialize all function progress at once
            $byFunction = [];
            $currentTime = date('c');
            
            foreach ($inventory['functions'] ?? [] as $function) {
                $functionId = $function['id'] ?? 0;
                if ($functionId > 0) {
                    $byFunction[$functionId] = [
                        'status' => 'pending',
                        'created_at' => $currentTime,
                        'last_updated' => $currentTime
                    ];
                }
            }
            
            // Update progress data structure
            $progress['by_function'] = (object) $byFunction;
            $progress['global_stats'] = [
                'total_functions' => $functionCount,
                'completed_functions' => 0,
                'completion_percentage' => 0.0,
                'last_updated' => $currentTime
            ];
            
            // Write all progress data in one operation
            $this->database->write('progress', $progress);

            $io->text("✓ Progress tracking initialized for {$functionCount} functions");
        } catch (\Exception $e) {
            $io->warning('Failed to setup progress tracking: ' . $e->getMessage());
        }
    }

    /**
     * Start new session
     */
    private function startSession(SymfonyStyle $io): mixed
    {
        try {
            $sessionContext = $this->sessionManager->startSession('Project initialization completed');
            
            $io->text('✓ Session started: ' . $sessionContext->getSessionId());
            return $sessionContext;
        } catch (\Exception $e) {
            $io->warning('Failed to start session: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Send ready notification
     */
    private function sendReadyNotification(SymfonyStyle $io, AnalysisResult $analysisResult): void
    {
        if (!$this->config->get('telegram_enabled', false)) {
            return;
        }

        try {
            $stats = $analysisResult->getStatistics();
            $message = "🚀 Claude Project Manager Ready!\n\n";
            $message .= "Project: " . $this->config->get('project_name') . "\n";
            $message .= "Functions: " . ($stats['total_functions'] ?? 0) . "\n";
            $message .= "Files: " . ($stats['total_files'] ?? 0) . "\n";
            $message .= "Ready for development work!";

            $this->notifier->sendMessage($message);
            $io->text('✓ Notification sent');
        } catch (\Exception $e) {
            $io->note('Notification failed: ' . $e->getMessage());
        }
    }

    /**
     * Perform initial monitoring check after setup
     */
    private function performInitialMonitoringCheck(SymfonyStyle $io): void
    {
        try {
            $results = $this->sessionManager->performMonitoringCheck();
            
            if (!empty($results['error'])) {
                $io->warning('Initial monitoring check failed: ' . $results['error']);
                return;
            }
            
            $activitiesDetected = $results['activities_detected'] ?? 0;
            
            if ($activitiesDetected > 0) {
                $io->text("✓ Initial monitoring detected {$activitiesDetected} activities");
            } else {
                $io->text('✓ Monitoring system initialized and ready');
            }
            
        } catch (\Exception $e) {
            $io->note('Initial monitoring check failed: ' . $e->getMessage());
        }
    }

    /**
     * Display initialization summary
     */
    private function displaySummary(SymfonyStyle $io, AnalysisResult $analysisResult, mixed $sessionContext): void
    {
        $io->section('Initialization Summary');
        
        if ($sessionContext) {
            $io->text($sessionContext->generateClaudeContext());
        }

        $io->note([
            'Project is ready for development work!',
            'Use "status" command to check progress',
            'Use "monitor" command to check for changes and updates',
            'Use "monitor --continuous" for ongoing monitoring',
            'Functions are tracked individually for precise progress monitoring'
        ]);
    }

    /**
     * Generate AI discovery file for universal LLM integration
     */
    private function generateClaudeDiscoveryFile(SymfonyStyle $io, AnalysisResult $analysisResult): void
    {
        try {
            $projectRoot = getcwd();
            $discoveryPath = $projectRoot . '/CLAUDE_DISCOVERY.md';
            
            // Use 'cpm' as the primary command for user instructions
            $cpmBinary = 'cpm';
            
            // Detect project framework and language
            $framework = $this->detectFramework($projectRoot);
            $language = $this->detectLanguage($projectRoot);
            
            // Get monitoring status
            $monitoringStatus = $this->isMonitoringActive() ? 'Active' : 'Inactive';
            
            // Get stats from analysis result
            $stats = $analysisResult->getStatistics();
            $functionsCount = count($analysisResult->getFunctionInventory());
            $filesCount = $stats['files_analyzed'] ?? 0;
            
            $discoveryContent = <<<MD
# 🤖 AI ASSISTANTS: READ THIS FILE FIRST!

⚡ **CRITICAL**: AI assistants do NOT auto-read this file - you must tell them to read it! ⚡

**Tell your AI: "Read the CLAUDE_DISCOVERY.md file"** 

This project uses **Claude Project Manager (CPM)** for enhanced AI-assisted development workflow.

## 🤖 Compatible AI Assistants
- **Claude Code** (Anthropic) - Full integration
- **ChatGPT** (OpenAI) - Function-level tracking  
- **GitHub Copilot** - Context awareness
- **Other LLMs** - Universal JSON/markdown context

## 🚀 Essential First Commands

**1. Verify CPM Installation:**
```bash
{$cpmBinary} --version
```

**2. Load Current Project Context:**
```bash
{$cpmBinary} context:digest
```

**3. Check Project Status:**
```bash
{$cpmBinary} status --json
```

## 📊 Project Overview
- **Framework**: {$framework}
- **Language**: {$language}  
- **Functions Analyzed**: {$functionsCount}
- **Files Processed**: {$filesCount}
- **CPM Monitoring**: {$monitoringStatus}

## 🎯 Claude Code Integration Workflow

### Immediate Setup (Do This First!)
1. Run: `{$cpmBinary} context:digest`
2. Read: `.cpm/context/DIGEST.md` (human-readable summary)
3. Read: `.claude.md` (project-specific rules)

### During Development
- **Before tasks**: `{$cpmBinary} monitor` (single check)
- **Function insights**: `{$cpmBinary} status --detailed`
- **File-specific progress**: `{$cpmBinary} status --detailed --file filename.php` (NEW!)
- **Progress tracking**: Check `.cpm/db/progress.json`
- **Code structure**: Check `.cpm/db/inventory.json`

### Key CPM Files for AI Integration
- `.cpm/context/DIGEST.md` - **Human-readable project briefing** (recommended)
- `.cpm/context/STATE.json` - **Complete state** (LARGE FILE - use carefully)
- `.cpm/db/inventory.json` - **Code structure and functions** (searchable)
- `.cpm/db/progress.json` - **Function completion tracking** (progress data)
- `.claude.md` - **Project conventions and rules** (AI instructions)

## ⚠️ IMPORTANT NOTES
- **ALWAYS** use CPM commands rather than manual JSON file editing
- The monitoring daemon runs in background (PID file in `.cpm/logs/`)
- Use `{$cpmBinary} status --json | jq` for machine-readable output
- This system provides function-level tracking for {$functionsCount} functions

## 📚 Available Commands
```bash
{$cpmBinary} start                    # Initialize/reinitialize project
{$cpmBinary} status                   # Human-readable status  
{$cpmBinary} status --json            # Machine-readable status
{$cpmBinary} status --detailed        # Function-level breakdown
{$cpmBinary} status --detailed --file # Filter functions by file path (NEW!)
{$cpmBinary} monitor                  # Single monitoring check
{$cpmBinary} monitor --daemon         # Start background monitoring
{$cpmBinary} monitor --status         # Check daemon status
{$cpmBinary} context:digest           # Generate context files
```

## 🚀 Universal AI Assistant Integration

**IMPORTANT**: AI assistants do NOT automatically discover this file. Users must:

1. **First step**: Tell your AI to "Read the CLAUDE_DISCOVERY.md file"  
2. **Then ask**: "Can you check project status with {$cpmBinary} status?"
3. **For progress**: "Run {$cpmBinary} status --detailed to see function progress"
4. **For specific files**: "Run {$cpmBinary} status --detailed --file filename.php"

**Benefits for all AI assistants:**
- Function-level progress tracking
- Session continuity across conversations  
- Automatic change detection
- File-specific function filtering (eliminates need for manual grep/rg)
- Project context awareness
- Enhanced development workflow

---
🤖 **AI Integration Active**: Your assistant is now integrated with Claude Project Manager! Use the commands above for optimal development workflow.

Generated: $(date -u)
MD;

            file_put_contents($discoveryPath, $discoveryContent);
            $io->text('✓ Claude discovery file created: CLAUDE_DISCOVERY.md');
            
            // Create cpm symlink for easier access
            $cpmPath = $projectRoot . '/cpm';
            $targetPath = './vendor/bin/claude-project';
            if (!file_exists($cpmPath) && file_exists($projectRoot . '/vendor/bin/claude-project')) {
                symlink($targetPath, $cpmPath);
                $io->text('✓ cpm shortcut created');
            }
            
            $io->comment('AI users: Tell your assistant to read CLAUDE_DISCOVERY.md for CPM integration!');
            
        } catch (\Exception $e) {
            $io->warning('Failed to generate Claude discovery file: ' . $e->getMessage());
        }
    }

    /**
     * Detect project framework
     */
    private function detectFramework(string $projectRoot): string
    {
        if (file_exists($projectRoot . '/composer.json')) {
            $composer = json_decode(file_get_contents($projectRoot . '/composer.json'), true);
            if (isset($composer['require']['symfony/framework-bundle'])) return 'Symfony';
            if (isset($composer['require']['laravel/framework'])) return 'Laravel';
            return 'PHP';
        }
        
        if (file_exists($projectRoot . '/package.json')) {
            $package = json_decode(file_get_contents($projectRoot . '/package.json'), true);
            if (isset($package['dependencies']['react'])) return 'React';
            if (isset($package['dependencies']['vue'])) return 'Vue';
            if (isset($package['dependencies']['@angular/core'])) return 'Angular';
            return 'Node.js';
        }
        
        if (file_exists($projectRoot . '/requirements.txt') || file_exists($projectRoot . '/pyproject.toml')) {
            return 'Python';
        }
        
        return 'Unknown';
    }

    /**
     * Detect primary language for display purposes
     * Returns user-friendly language names (not for database storage)
     */
    private function detectLanguage(string $projectRoot): string
    {
        if (file_exists($projectRoot . '/composer.json')) return 'PHP';
        if (file_exists($projectRoot . '/package.json')) return 'JavaScript/TypeScript';
        if (file_exists($projectRoot . '/requirements.txt') || file_exists($projectRoot . '/pyproject.toml')) return 'Python';
        if (file_exists($projectRoot . '/Cargo.toml')) return 'Rust';
        if (file_exists($projectRoot . '/go.mod')) return 'Go';

        return 'Multi-language';
    }

    /**
     * Detect project type for database storage
     * Returns values matching schema enum: ["php","python","mixed"]
     */
    private function detectProjectType(string $projectRoot): string
    {
        $hasPhp = file_exists($projectRoot . '/composer.json');
        $hasPython = file_exists($projectRoot . '/requirements.txt') || file_exists($projectRoot . '/pyproject.toml');
        $hasJs = file_exists($projectRoot . '/package.json');

        // Count language markers
        $languageCount = ($hasPhp ? 1 : 0) + ($hasPython ? 1 : 0) + ($hasJs ? 1 : 0);

        // Multiple languages = mixed
        if ($languageCount > 1) {
            return 'mixed';
        }

        // Single language
        if ($hasPhp) return 'php';
        if ($hasPython) return 'python';

        // Default to mixed for JS or unknown
        return 'mixed';
    }

    /**
     * Check if monitoring daemon is active
     */
    private function isMonitoringActive(): bool
    {
        try {
            $pidFile = getcwd() . '/.cpm/logs/monitor.pid';
            if (!file_exists($pidFile)) return false;
            
            $pid = \ClaudeProjectManager\Services\MonitorPidGuard::parse((string) file_get_contents($pidFile));
            if ($pid === null) {
                return false;
            }
            // Windows fallback when POSIX functions are unavailable
            if (!function_exists('posix_getsid')) {
                $output = [];
                exec('tasklist /FI ' . escapeshellarg("PID eq {$pid}") . ' /NH 2>NUL', $output);
                foreach ($output as $line) {
                    if (strpos($line, (string)$pid) !== false) {
                        return true;
                    }
                }
                return false;
            }
            return posix_getsid($pid) !== false;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Count analyzable files for progress bar initialization
     */
    private function countAnalyzableFiles(string $projectRoot): int
    {
        try {
            // Use same file discovery logic as ProjectAnalyzer
            $finder = new Finder();
            $finder->files()
                ->in($projectRoot)
                ->name(['*.php', '*.py', '*.js', '*.ts', '*.jsx', '*.tsx'])
                ->exclude(['vendor', 'node_modules', '.git', 'storage', 'cache', 'tmp']);

            return iterator_count($finder);
        } catch (\Exception $e) {
            // If count fails, return 1 to avoid division by zero
            return 1;
        }
    }

    /**
     * Handle migration from legacy directory to .cpm
     */
    private function handleLegacyMigration(SymfonyStyle $io, string $projectRoot): void
    {
        // Check for legacy directory name
        $legacyDir = $projectRoot . '/.claude-project';
        $newDir = $projectRoot . '/.cpm';
        
        // Only migrate if legacy directory exists and new one doesn't
        if (is_dir($legacyDir) && !is_dir($newDir)) {
            $io->text('🔄 Migrating from legacy directory to .cpm...');
            
            try {
                if (rename($legacyDir, $newDir)) {
                    $io->text('✓ Migration completed successfully');
                    
                    // Update any legacy symlinks pointing to old location
                    $this->updateLegacySymlinks($io, $projectRoot);
                } else {
                    $io->warning('Failed to migrate legacy directory');
                }
            } catch (\Exception $e) {
                $io->warning('Migration failed: ' . $e->getMessage());
                $io->note('Please manually rename legacy directory to .cpm if needed');
            }
        }
    }

    /**
     * Check and optionally fix permission issues
     */
    private function checkAndFixPermissions(SymfonyStyle $io, bool $autoFix): void
    {
        try {
            // Always ensure essential directories exist (especially logs for daemon)
            $this->ensureEssentialDirectories($io);
            
            $diagnosis = $this->permissionManager->diagnosePermissions();
            
            if ($diagnosis['status'] === 'ok') {
                return; // No issues found
            }
            
            $issueCount = count($diagnosis['issues']);
            $criticalIssues = array_filter($diagnosis['issues'], fn($issue) => $issue['type'] === 'critical');
            $errorIssues = array_filter($diagnosis['issues'], fn($issue) => $issue['type'] === 'error');
            
            if (empty($criticalIssues) && empty($errorIssues)) {
                return; // Only warnings, proceed
            }
            
            $io->warning("Permission issues detected ({$issueCount} issues)");
            
            // Show critical issues
            foreach ($criticalIssues as $issue) {
                $io->text("🔴 {$issue['title']}: {$issue['description']}");
            }
            
            foreach ($errorIssues as $issue) {
                $io->text("❌ {$issue['title']}: {$issue['description']}");
            }
            
            if ($autoFix) {
                $io->text('Auto-fixing permission issues...');
                $fixResult = $this->permissionManager->fixPermissions(false);
                
                foreach ($fixResult['fixes_applied'] as $fix) {
                    $io->text("  ✓ $fix");
                }
                
                if (!empty($fixResult['errors'])) {
                    $io->warning('Some permission fixes failed:');
                    foreach ($fixResult['errors'] as $error) {
                        $io->text("  ❌ $error");
                    }
                }
            } else {
                $io->note('Run with --fix-permissions to automatically fix these issues');
                $io->text('Or use: cpm tools:fix-perms');
                
                // Show first few recommendations
                $recommendations = array_slice($diagnosis['recommendations'], 0, 3);
                foreach ($recommendations as $rec) {
                    $io->text("💡 {$rec['action']}:");
                    foreach ($rec['commands'] as $cmd) {
                        $io->text("   $cmd");
                    }
                }
            }
            
        } catch (\Exception $e) {
            $io->note('Permission check failed: ' . $e->getMessage());
        }
    }

    /**
     * Ensure essential directories exist (always run, regardless of --fix-permissions)
     */
    private function ensureEssentialDirectories(SymfonyStyle $io): void
    {
        $essentialDirectories = [
            $this->config->get('paths.cpm_root'),
            $this->config->get('paths.logs'),
            $this->config->get('paths.database'),
            $this->config->get('paths.context')
        ];

        foreach ($essentialDirectories as $dir) {
            if (!is_dir($dir)) {
                try {
                    mkdir($dir, 0755, true);
                    $io->text("Created directory: {$dir}");
                } catch (\Exception $e) {
                    $io->warning("Could not create directory {$dir}: " . $e->getMessage());
                }
            }
        }
    }

    /**
     * Update legacy symlinks to point to new .cpm directory
     */
    private function updateLegacySymlinks(SymfonyStyle $io, string $projectRoot): void
    {
        $possibleSymlinks = [
            'claude-project',
            'cpm'
        ];

        foreach ($possibleSymlinks as $symlinkName) {
            $symlinkPath = $projectRoot . '/' . $symlinkName;
            
            if (is_link($symlinkPath)) {
                $target = readlink($symlinkPath);
                
                // Update if it points to old legacy path
                if (strpos($target, '.claude-project') !== false) {
                    $newTarget = str_replace('.claude-project', '.cpm', $target);
                    
                    unlink($symlinkPath);
                    symlink($newTarget, $symlinkPath);
                    
                    $io->text("✓ Updated symlink: {$symlinkName} → {$newTarget}");
                }
            }
        }
    }
}