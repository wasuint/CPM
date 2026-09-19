<?php

declare(strict_types=1);

namespace ClaudeProjectManager\Console;

use ClaudeProjectManager\Commands\StartCommand;
use ClaudeProjectManager\Commands\StatusCommand;
use ClaudeProjectManager\Commands\MonitorCommand;
use ClaudeProjectManager\Commands\UpdateCommand;
use ClaudeProjectManager\Commands\SummaryCommand;
use ClaudeProjectManager\Commands\DependenciesCommand;
use ClaudeProjectManager\Commands\ImpactCommand;
use ClaudeProjectManager\Commands\MigrateCommand;
use ClaudeProjectManager\Commands\HelpCommand;
use ClaudeProjectManager\Commands\ProgressCommand;
use ClaudeProjectManager\Commands\VerifyCommand;
use ClaudeProjectManager\Commands\AiContextCommand;
use ClaudeProjectManager\Commands\AiSuggestCommand;
use ClaudeProjectManager\Commands\CheckpointCommand;
use ClaudeProjectManager\Commands\RepairCommand;
use ClaudeProjectManager\Commands\LearnCommand;
use ClaudeProjectManager\Commands\SmartSuggestCommand;
use ClaudeProjectManager\Commands\TemplateCommand;
use ClaudeProjectManager\Commands\GenerateCodeCommand;
use ClaudeProjectManager\Commands\AutoTestDocsCommand;
use ClaudeProjectManager\Commands\CompactCommand;
use ClaudeProjectManager\Commands\DiagnosticsCommand;
use ClaudeProjectManager\Commands\IssueCommand;
use ClaudeProjectManager\Console\Commands\ContextDigestCommand;
use ClaudeProjectManager\Console\Commands\FixPermsCommand;
use ClaudeProjectManager\ConfigManager;
use ClaudeProjectManager\DatabaseManager;
use ClaudeProjectManager\ProjectAnalyzer;
use ClaudeProjectManager\ProgressTracker;
use ClaudeProjectManager\SessionManager;
use ClaudeProjectManager\Progress\MetricsCalculator;
use ClaudeProjectManager\Notifications\TelegramNotifier;
use ClaudeProjectManager\Session\ActivityTracker;
use ClaudeProjectManager\Services\StateSummaryGenerator;
use ClaudeProjectManager\Analysis\DependencyAnalyzer;
use ClaudeProjectManager\Analysis\ImpactAnalyzer;
use Symfony\Component\Console\Application as SymfonyApplication;
use Symfony\Component\Console\CommandLoader\FactoryCommandLoader;

class Application extends SymfonyApplication
{
    private const NAME = 'Claude Project Manager';
    public const VERSION = '1.3.1';

    public function __construct()
    {
        parent::__construct(self::NAME, self::VERSION);
        $this->registerCommands();
    }

    private function registerCommands(): void
    {
        // Always register commands that don't need project context
        $this->add(new HelpCommand());
        $this->add(new ContextDigestCommand());
        $this->add(new FixPermsCommand());
        $this->add(new MigrateCommand());
        $this->add(new UpdateCommand());

        $projectRoot = $this->findProjectRoot();

        if ($projectRoot === null) {
            // No project here yet: only `start` is offered, and it is registered
            // lazily so that no service touches the filesystem until a user
            // actually runs it (D-020). `--version`, `--help` and `list` leave
            // no trace in the current directory.
            $this->setCommandLoader(new FactoryCommandLoader([
                'start' => static function (): StartCommand {
                    $currentDir = getcwd() ?: '.';
                    $configManager = new ConfigManager($currentDir);
                    $dbManager = new DatabaseManager($currentDir);
                    $notifier = new TelegramNotifier($configManager);
                    $projectAnalyzer = new ProjectAnalyzer($dbManager, $configManager);
                    $activityTracker = new ActivityTracker($dbManager, $projectAnalyzer, $notifier);
                    $sessionManager = new SessionManager($dbManager, $activityTracker);

                    return new StartCommand($dbManager, $configManager, $projectAnalyzer, $sessionManager, $notifier);
                },
            ]));
            return;
        }

        $configManager = new ConfigManager($projectRoot);
        
        // Check for and perform migration if needed
        if ($configManager->needsMigration()) {
            echo "🔄 Detecting legacy directory structure...\n";
            if ($configManager->migrateToNewStructure()) {
                echo "✅ Successfully migrated to .cpm structure\n";
            } else {
                echo "⚠️ Migration failed, continuing with legacy structure\n";
            }
        }
        
        $dbManager = new DatabaseManager($projectRoot);

        // Notifier and its dependencies
        $notifier = new TelegramNotifier($configManager);

        // ProgressTracker and its dependencies
        $progressTracker = new ProgressTracker($dbManager, $notifier);

        $projectAnalyzer = new ProjectAnalyzer($dbManager, $configManager);

        // Create ActivityTracker with its dependencies
        $activityTracker = new ActivityTracker($dbManager, $projectAnalyzer, $notifier);

        // SessionManager and its dependencies
        $sessionManager = new SessionManager($dbManager, $activityTracker);
        
        $this->add(new StartCommand($dbManager, $configManager, $projectAnalyzer, $sessionManager, $notifier));
        
        // MetricsCalculator (no dependencies)
        $metricsCalculator = new MetricsCalculator();
        
        // Create new Phase 1 feature services
        $stateSummaryGenerator = new StateSummaryGenerator($dbManager);
        $dependencyAnalyzer = new DependencyAnalyzer($dbManager);
        $impactAnalyzer = new ImpactAnalyzer($dbManager, $dependencyAnalyzer);
        
        $this->add(new StatusCommand($dbManager, $sessionManager, $metricsCalculator));
        $this->add(new ProgressCommand($dbManager, $progressTracker));
        $this->add(new IssueCommand($dbManager));
        $this->add(new VerifyCommand($dbManager));
        $this->add(new \ClaudeProjectManager\Commands\ConfigCommand($dbManager));
        $this->add(new AiContextCommand($dbManager, $sessionManager));
        $this->add(new AiSuggestCommand($dbManager, $sessionManager));
        $this->add(new \ClaudeProjectManager\Commands\SuggestCommand($dbManager));
        $this->add(new CheckpointCommand($dbManager, $sessionManager));
        $this->add(new RepairCommand($dbManager, $sessionManager));
        $this->add(new LearnCommand($dbManager, $sessionManager));
        $this->add(new SmartSuggestCommand($dbManager, $sessionManager));
        $this->add(new TemplateCommand($dbManager, $sessionManager));
        $this->add(new GenerateCodeCommand($dbManager, $sessionManager));
        $this->add(new AutoTestDocsCommand($dbManager, $sessionManager));
        $this->add(new CompactCommand($dbManager, $projectRoot));
        $this->add(new DiagnosticsCommand($dbManager, $projectRoot));
        $this->add(new MonitorCommand($sessionManager));
        
        // Add Phase 1 commands
        $this->add(new SummaryCommand($dbManager, $stateSummaryGenerator));
        $this->add(new DependenciesCommand($dbManager, $dependencyAnalyzer));
        $this->add(new ImpactCommand($dbManager, $impactAnalyzer));

    }

    private function findProjectRoot(): ?string
    {
        $dir = getcwd();
        $prevDir = null;

        // Loop until we reach filesystem root (cross-platform)
        while ($dir !== $prevDir) {
            // Check for new .cpm structure first
            if (file_exists($dir . '/.cpm')) {
                return $dir;
            }
            // Fall back to legacy directory for backward compatibility
            if (file_exists($dir . '/.claude-project')) {
                return $dir;
            }

            $prevDir = $dir;
            $dir = dirname($dir);
        }

        return null;
    }
}
