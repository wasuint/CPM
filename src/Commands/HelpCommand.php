<?php

declare(strict_types=1);

namespace ClaudeProjectManager\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * AI-optimized help command providing comprehensive documentation
 * Designed specifically for AI assistants like Claude Code
 */
class HelpCommand extends Command
{
    protected static $defaultName = 'docs';
    protected static $defaultDescription = 'Show comprehensive help and command reference for AI assistants';

    private const COMMAND_CATEGORIES = [
        'core' => 'Core Project Management',
        'progress' => 'Progress & Status Tracking', 
        'analysis' => 'Code Analysis & Metrics',
        'context' => 'Context & Integration',
        'maintenance' => 'System Maintenance'
    ];

    private const COMMANDS_DATA = [
        'core' => [
            'start' => [
                'description' => 'Initialize project analysis and tracking',
                'usage' => [
                    'cpm start' => 'Initialize project with default settings',
                    'cpm start --force' => 'Force re-analysis of existing project', 
                    'cpm start --quick' => 'Quick analysis (skip deep parsing)'
                ],
                'ai_notes' => 'Use this first to initialize CPM tracking. Always check success with --json output.',
                'returns' => 'Project initialization status and detected functions count'
            ],
            'status' => [
                'description' => 'Display current project status and progress',
                'usage' => [
                    'cpm status' => 'Human-readable status overview',
                    'cpm status --json' => 'Machine-readable JSON output (AI preferred)',
                    'cpm status --detailed' => 'Function-level breakdown',
                    'cpm status --history' => 'Show session history'
                ],
                'ai_notes' => 'Use --json flag for easy parsing. Contains completion percentages and progress data.',
                'returns' => 'Project overview, progress metrics, session info'
            ]
        ],
        'progress' => [
            'progress' => [
                'description' => 'Explicit progress management and control',
                'usage' => [
                    'cpm progress show' => 'Show current progress state',
                    'cpm progress mark-complete <file>' => 'Mark file as completed',
                    'cpm progress mark-function <function-id> <status>' => 'Update function status',
                    'cpm progress bulk-mark "*.py" --status=completed' => 'Bulk update files'
                ],
                'ai_notes' => 'Essential for AI workflow. Provides explicit control over completion tracking.',
                'returns' => 'Progress updates with confirmation of changes made'
            ],
            'issue' => [
                'description' => 'Manage project issues, bugs, and tasks',
                'usage' => [
                    'cpm issue add --title "Fix bug" --category bug --priority high' => 'Create new issue',
                    'cpm issue list --limit 5' => 'List open issues (most recent 5)',
                    'cpm issue list --json' => 'Machine-readable issue list',
                    'cpm issue show CPM-BUG-001' => 'Show issue details',
                    'cpm issue update CPM-BUG-001 --status in_progress' => 'Update issue status',
                    'cpm issue comment CPM-BUG-001 --text=-' => 'Add comment (text from stdin)',
                    'cpm issue close CPM-BUG-001 --resolution-file /tmp/res.md' => 'Close with resolution from file',
                    'cpm issue archive --dry-run' => 'Preview archiving old closed issues to cold storage',
                    'cpm issue export --format markdown' => 'Export issues to markdown'
                ],
                'ai_notes' => 'Full issue tracking system. Categories: hardware, software, documentation, feature, bug, task (aliases: hw, sw, doc, feat, fix; status aliases: wip, in-progress, done). Long text: --description-file/--resolution-file/--text-file or =- for stdin, never long shell args. Attribution: --agent or $CPM_AGENT. JSON envelope: {success, action, timestamp, data}.',
                'returns' => 'Issue data with unique IDs (PREFIX-CATEGORY-NUMBER format)'
            ],
            'checkpoint' => [
                'description' => 'Session checkpoints for AI workflow continuity',
                'usage' => [
                    'cpm checkpoint create -d "Done X. Pending: Y"' => 'Create handoff checkpoint',
                    'cpm checkpoint list --json --limit 3' => 'Where did the last session leave off?',
                    'cpm checkpoint prune --keep 20' => 'Drop old checkpoints beyond the newest 20'
                ],
                'ai_notes' => 'End every work session with a checkpoint; include "Pending:" in the description. Attribution via --agent or $CPM_AGENT. Retention: checkpoints.max_retained config (default 50).',
                'returns' => 'Checkpoint IDs and summaries with agent attribution'
            ]
        ],
        'analysis' => [
            'summary' => [
                'description' => 'Generate project state summary',
                'usage' => [
                    'cpm summary' => 'Basic project summary',
                    'cpm summary --detailed' => 'Comprehensive analysis'
                ],
                'ai_notes' => 'Provides high-level project insights for context building.',
                'returns' => 'Project metrics, code quality indicators, completion status'
            ],
            'dependencies' => [
                'description' => 'Analyze code dependencies',
                'usage' => [
                    'cpm dependencies --show <file>' => 'Show dependencies for file',
                    'cpm dependencies --all' => 'Show all project dependencies'
                ],
                'ai_notes' => 'Use to understand code relationships before refactoring.',
                'returns' => 'Dependency graph and relationship mappings'
            ],
            'impact' => [
                'description' => 'Analyze change impact',
                'usage' => [
                    'cpm impact --file <file>' => 'Show impact of changing file',
                    'cpm impact --function <function>' => 'Show function impact'
                ],
                'ai_notes' => 'Essential before making changes to understand effects.',
                'returns' => 'Impact analysis with affected files and functions'
            ]
        ],
        'context' => [
            'context:digest' => [
                'description' => 'Generate Claude context files (Journal-first digest)',
                'usage' => [
                    'cpm context:digest' => 'Create DIGEST.md and STATE.json for Claude',
                    'cat .cpm/context/DIGEST.md' => 'Read it: Journal (issues + checkpoints) comes first'
                ],
                'ai_notes' => 'DIGEST.md opens with the Journal — in-progress/blocked/open issues and latest checkpoints — the authoritative session-handoff state. Function-tracking detail appears only when analysis is fresh; stale data is labeled STALE. Run at session start.',
                'returns' => 'Context files created in .cpm/context/ directory'
            ],
            'ai-context' => [
                'description' => 'Generate AI-optimized context summary',
                'usage' => [
                    'cpm ai-context' => 'Generate context perfect for AI handoff',
                    'cpm ai-context --include-todos' => 'Include pending tasks'
                ],
                'ai_notes' => 'Optimized for AI consumption. Use for session handoffs.',
                'returns' => 'AI-formatted context with actionable information'
            ]
        ],
        'maintenance' => [
            'monitor' => [
                'description' => 'File monitoring and change detection', 
                'usage' => [
                    'cpm monitor' => 'Single monitoring check',
                    'cpm monitor --daemon' => 'Start background monitoring',
                    'cpm monitor --status' => 'Check daemon status'
                ],
                'ai_notes' => 'Use --daemon for background tracking. Essential for session continuity.',
                'returns' => 'Change detection results and monitoring status'
            ],
            'tools:fix-perms' => [
                'description' => 'Fix file permissions for CPM files',
                'usage' => [
                    'cpm tools:fix-perms' => 'Fix .cpm directory permissions'
                ],
                'ai_notes' => 'Run if getting permission errors accessing CPM files.',
                'returns' => 'Permission fixing results'
            ],
            'verify' => [
                'description' => 'Verify CPM integration and consistency',
                'usage' => [
                    'cpm verify' => 'Check CPM system health',
                    'cpm verify --json' => 'Machine-readable verification'
                ],
                'ai_notes' => 'Use to diagnose tracking issues. Always run with --json.',
                'returns' => 'System health status and any issues found'
            ]
        ]
    ];

    protected function configure(): void
    {
        $this
            ->addArgument(
                'command-name',
                InputArgument::OPTIONAL,
                'Get detailed help for specific command'
            )
            ->addOption(
                'json',
                null,
                InputOption::VALUE_NONE,
                'Output JSON format for AI consumption'
            )
            ->addOption(
                'ai-examples',
                null,
                InputOption::VALUE_NONE,
                'Show AI-specific usage examples'
            )
            ->addOption(
                'commands',
                null,
                InputOption::VALUE_NONE,
                'List all commands with brief descriptions'
            )
            ->setHelp('
This command provides comprehensive documentation for CPM commands,
specifically optimized for AI assistants like Claude Code.

Examples:
  cpm help                    # Show complete command reference
  cpm help status            # Detailed help for status command
  cpm help --json            # JSON output for AI parsing
  cpm help --ai-examples     # AI workflow examples
  cpm help --commands        # Quick command list
            ');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $commandName = $input->getArgument('command-name');
        $json = $input->getOption('json');
        $aiExamples = $input->getOption('ai-examples');
        $listCommands = $input->getOption('commands');

        try {
            if ($json) {
                return $this->outputJson($output, $commandName);
            }

            if ($listCommands) {
                return $this->listCommands($io);
            }

            if ($aiExamples) {
                return $this->showAiExamples($io);
            }

            if ($commandName) {
                return $this->showCommandHelp($io, $commandName);
            }

            return $this->showCompleteReference($io);

        } catch (\Exception $e) {
            if ($json) {
                $output->writeln(json_encode(['error' => $e->getMessage()]));
            } else {
                $io->error('Help system error: ' . $e->getMessage());
            }
            return Command::FAILURE;
        }
    }

    private function outputJson(OutputInterface $output, ?string $commandName): int
    {
        if ($commandName) {
            $commandData = $this->findCommand($commandName);
            if (!$commandData) {
                $output->writeln(json_encode(['error' => "Command '$commandName' not found"]));
                return Command::FAILURE;
            }
            $output->writeln(json_encode(['command' => $commandName, 'help' => $commandData]));
        } else {
            $output->writeln(json_encode([
                'categories' => self::COMMAND_CATEGORIES,
                'commands' => self::COMMANDS_DATA,
                'ai_workflow' => $this->getAiWorkflowData()
            ], ));
        }
        return Command::SUCCESS;
    }

    private function listCommands(SymfonyStyle $io): int
    {
        $io->title('Claude Project Manager - Available Commands');
        
        foreach (self::COMMAND_CATEGORIES as $category => $categoryTitle) {
            $io->section($categoryTitle);
            
            if (!isset(self::COMMANDS_DATA[$category])) {
                continue;
            }
            
            $commands = [];
            foreach (self::COMMANDS_DATA[$category] as $command => $data) {
                $commands[] = [
                    'cpm ' . $command,
                    $data['description']
                ];
            }
            
            $io->table(['Command', 'Description'], $commands);
        }
        
        $io->note([
            'Use "cpm help <command>" for detailed help',
            'Use "cpm help --json" for machine-readable output',
            'Use "cpm help --ai-examples" for AI workflow examples'
        ]);
        
        return Command::SUCCESS;
    }

    private function showAiExamples(SymfonyStyle $io): int
    {
        $io->title('AI Assistant Usage Examples');
        
        $workflows = [
            'Initial Project Setup' => [
                'cpm start --json',
                'cpm status --json',
                'cpm context:digest'
            ],
            'Progress Tracking' => [
                'cpm progress show --json',
                'cpm progress mark-complete src/utils.py --reason="Fixed all pylance errors"',
                'cpm status --json'
            ],
            'Batch Operations' => [
                'cpm progress bulk-mark "tests/**/*.py" --status=completed',
                'cpm verify --json'
            ],
            'Session Handoff' => [
                'cpm ai-context --include-todos',
                'cpm summary --detailed --json'
            ],
            'Error Recovery' => [
                'cpm verify --json',
                'cpm tools:fix-perms',
                'cpm repair --auto-fix'
            ]
        ];
        
        foreach ($workflows as $workflow => $commands) {
            $io->section($workflow);
            foreach ($commands as $command) {
                $io->text('  $ ' . $command);
            }
            $io->newLine();
        }
        
        $io->note([
            'Always use --json flags for machine-readable output',
            'Verify operations with "cpm verify --json"',
            'Use "cpm progress" commands for explicit control'
        ]);
        
        return Command::SUCCESS;
    }

    private function showCommandHelp(SymfonyStyle $io, string $commandName): int
    {
        $commandData = $this->findCommand($commandName);
        
        if (!$commandData) {
            $io->error("Command '$commandName' not found.");
            $io->note('Use "cpm help --commands" to see all available commands.');
            return Command::FAILURE;
        }
        
        $io->title("Help: cpm $commandName");
        
        $io->section('Description');
        $io->text($commandData['description']);
        
        if (!empty($commandData['usage'])) {
            $io->section('Usage');
            foreach ($commandData['usage'] as $command => $description) {
                $io->text("  <info>$command</info>");
                $io->text("    $description");
                $io->newLine();
            }
        }
        
        if (!empty($commandData['ai_notes'])) {
            $io->section('AI Assistant Notes');
            $io->note($commandData['ai_notes']);
        }
        
        if (!empty($commandData['returns'])) {
            $io->section('Returns');
            $io->text($commandData['returns']);
        }
        
        return Command::SUCCESS;
    }

    private function showCompleteReference(SymfonyStyle $io): int
    {
        $io->title('Claude Project Manager - Complete Reference');
        $io->text('Comprehensive command reference optimized for AI assistants');
        $io->newLine();
        
        foreach (self::COMMAND_CATEGORIES as $category => $categoryTitle) {
            $io->section($categoryTitle);
            
            if (!isset(self::COMMANDS_DATA[$category])) {
                continue;
            }
            
            foreach (self::COMMANDS_DATA[$category] as $command => $data) {
                $io->text("<info>cpm $command</info> - {$data['description']}");
                
                if (!empty($data['usage']) && count($data['usage']) > 0) {
                    $mainUsage = array_key_first($data['usage']);
                    $io->text("  Example: <comment>$mainUsage</comment>");
                }
                
                if (!empty($data['ai_notes'])) {
                    $io->text("  🤖 " . $data['ai_notes']);
                }
                
                $io->newLine();
            }
        }
        
        $io->section('Getting Started for AI Assistants');
        $io->listing([
            'Initialize: <comment>cmp start --json</comment>',
            'Check status: <comment>cpm status --json</comment>',
            'Get help: <comment>cpm help <command></comment>',
            'List commands: <comment>cpm help --commands</comment>',
            'AI examples: <comment>cpm help --ai-examples</comment>'
        ]);
        
        return Command::SUCCESS;
    }

    private function findCommand(string $commandName): ?array
    {
        foreach (self::COMMANDS_DATA as $category => $commands) {
            if (isset($commands[$commandName])) {
                return $commands[$commandName];
            }
        }
        return null;
    }

    private function getAiWorkflowData(): array
    {
        return [
            'initialization' => [
                'commands' => ['cpm start --json', 'cpm context:digest'],
                'purpose' => 'Set up CPM tracking and generate context files'
            ],
            'progress_tracking' => [
                'commands' => ['cpm progress show --json', 'cpm status --json'],
                'purpose' => 'Monitor and verify progress state'
            ],
            'explicit_control' => [
                'commands' => ['cpm progress mark-complete <file>', 'cpm verify --json'],
                'purpose' => 'Manually control progress tracking'
            ],
            'error_recovery' => [
                'commands' => ['cpm verify --json', 'cpm tools:fix-perms'],
                'purpose' => 'Diagnose and fix tracking issues'
            ]
        ];
    }
}