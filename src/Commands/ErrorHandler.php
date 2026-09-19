<?php

declare(strict_types=1);

namespace ClaudeProjectManager\Commands;

use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Enhanced error handling with AI-friendly actionable suggestions
 */
class ErrorHandler
{
    private const ERROR_SOLUTIONS = [
        // File system errors
        'permission denied' => [
            'type' => 'filesystem',
            'suggestion' => 'Fix file permissions with: cpm tools:fix-perms',
            'additional_commands' => ['cpm tools:fix-perms', 'chmod 755 .cpm/', 'chown -R $USER .cpm/'],
            'ai_note' => 'This is a common setup issue. Run fix-perms command and retry.'
        ],
        'no such file or directory' => [
            'type' => 'filesystem', 
            'suggestion' => 'Initialize project first with: cpm start',
            'additional_commands' => ['cpm start', 'cpm verify --json'],
            'ai_note' => 'Project may not be initialized. Run start command first.'
        ],
        'file does not exist' => [
            'type' => 'filesystem',
            'suggestion' => 'Check file path or initialize project with: cpm start',
            'additional_commands' => ['cpm start', 'ls -la', 'cpm status --json'],
            'ai_note' => 'Verify the file exists or project is properly initialized.'
        ],
        
        // Database/JSON errors
        'json_decode' => [
            'type' => 'data_corruption',
            'suggestion' => 'Corrupted data file. Reset with: cpm start --force',
            'additional_commands' => ['cpm start --force', 'cpm verify --json'],
            'ai_note' => 'Data corruption detected. Force reinitialize to fix.'
        ],
        'syntax error' => [
            'type' => 'data_corruption', 
            'suggestion' => 'Invalid JSON data. Reset with: cpm start --force',
            'additional_commands' => ['cpm start --force', 'cpm tools:fix-perms'],
            'ai_note' => 'JSON syntax error indicates corruption. Force reset required.'
        ],
        
        // Configuration errors
        'class not found' => [
            'type' => 'configuration',
            'suggestion' => 'Run composer install to install dependencies',
            'additional_commands' => ['composer install', 'composer update'],
            'ai_note' => 'Missing PHP dependencies. Run composer install in project.'
        ],
        'autoloader' => [
            'type' => 'configuration',
            'suggestion' => 'Composer autoloader missing. Run: composer install',
            'additional_commands' => ['composer install', 'composer dump-autoload'],
            'ai_note' => 'Autoloader issue. Ensure composer install was run.'
        ],
        
        // Command errors
        'command not found' => [
            'type' => 'command',
            'suggestion' => 'Use: cpm help --commands to see available commands',
            'additional_commands' => ['cpm help --commands', 'cpm help <command>'],
            'ai_note' => 'Invalid command. Check available commands with help.'
        ],
        'required argument' => [
            'type' => 'command',
            'suggestion' => 'Missing required argument. Use: cpm help <command> for usage',
            'additional_commands' => ['cpm help <command>', 'cpm help --ai-examples'],
            'ai_note' => 'Missing required parameter. Check command help for syntax.'
        ],
        
        // System errors
        'memory limit' => [
            'type' => 'system',
            'suggestion' => 'Increase PHP memory limit or use: cpm start --quick',
            'additional_commands' => ['cpm start --quick', 'php -d memory_limit=512M bin/claude-project'],
            'ai_note' => 'Memory limit exceeded. Try quick mode or increase PHP memory.'
        ],
        'timeout' => [
            'type' => 'system',
            'suggestion' => 'Operation timed out. Try: cpm start --quick for faster analysis',
            'additional_commands' => ['cpm start --quick', 'cpm monitor --daemon'],
            'ai_note' => 'Timeout occurred. Use quick mode or background monitoring.'
        ],
        
        // Progress tracking errors
        'function not found' => [
            'type' => 'progress',
            'suggestion' => 'Function ID invalid. Use: cpm progress pending --json to see valid IDs',
            'additional_commands' => ['cpm progress pending --json', 'cpm status --detailed'],
            'ai_note' => 'Invalid function ID. Check pending functions for valid IDs.'
        ],
        'tracking inconsistency' => [
            'type' => 'progress',
            'suggestion' => 'Progress data inconsistent. Fix with: cpm verify --json',
            'additional_commands' => ['cpm verify --json', 'cpm start --force'],
            'ai_note' => 'Tracking data is inconsistent. Verify and possibly reset.'
        ]
    ];

    public static function handleError(
        string $errorMessage,
        OutputInterface $output,
        bool $jsonOutput = false,
        string $action = 'unknown',
        array $context = []
    ): void {
        $solution = self::findSolution($errorMessage);
        
        if ($jsonOutput) {
            self::outputJsonError($output, $errorMessage, $solution, $action, $context);
        } else {
            self::outputHumanError($output, $errorMessage, $solution);
        }
    }

    private static function findSolution(string $errorMessage): array
    {
        $lowerError = strtolower($errorMessage);
        
        foreach (self::ERROR_SOLUTIONS as $pattern => $solution) {
            if (strpos($lowerError, $pattern) !== false) {
                return $solution;
            }
        }
        
        // Default fallback solution
        return [
            'type' => 'unknown',
            'suggestion' => 'Check cpm help for available commands or run: cpm verify --json',
            'additional_commands' => ['cpm help', 'cpm verify --json', 'cpm status --json'],
            'ai_note' => 'Unknown error. Use verify command to diagnose or check help.'
        ];
    }

    private static function outputJsonError(
        OutputInterface $output,
        string $errorMessage,
        array $solution,
        string $action,
        array $context
    ): void {
        $response = [
            'success' => false,
            'action' => $action,
            'error' => $errorMessage,
            'solution' => [
                'type' => $solution['type'],
                'suggestion' => $solution['suggestion'],
                'commands' => $solution['additional_commands'],
                'ai_note' => $solution['ai_note']
            ],
            'context' => $context,
            'timestamp' => date('Y-m-d H:i:s')
        ];

        $output->writeln(json_encode($response, JSON_UNESCAPED_SLASHES));
    }

    private static function outputHumanError(
        OutputInterface $output,
        string $errorMessage,
        array $solution
    ): void {
        $io = new SymfonyStyle(new \Symfony\Component\Console\Input\ArrayInput([]), $output);
        
        $io->error($errorMessage);
        
        $io->section('💡 Suggested Solution');
        $io->text('Type: ' . ucfirst($solution['type']) . ' Error');
        $io->text('Suggestion: ' . $solution['suggestion']);
        
        if (!empty($solution['additional_commands'])) {
            $io->section('🔧 Try These Commands');
            foreach ($solution['additional_commands'] as $command) {
                $io->text("  <info>$command</info>");
            }
        }
        
        $io->note('💡 AI Note: ' . $solution['ai_note']);
        
        $io->section('📚 Need More Help?');
        $io->listing([
            '<info>cpm help</info> - List all commands',
            '<info>cpm help <command></info> - Detailed command help', 
            '<info>cpm verify --json</info> - Check system health',
            '<info>cpm help --ai-examples</info> - AI workflow examples'
        ]);
    }

    /**
     * Enhanced exception handling with context
     */
    public static function handleException(
        \Exception $e,
        OutputInterface $output,
        bool $jsonOutput = false,
        string $action = 'unknown',
        array $context = []
    ): int {
        $errorMessage = $e->getMessage();
        $enhancedContext = array_merge($context, [
            'exception_type' => get_class($e),
            'file' => $e->getFile(),
            'line' => $e->getLine()
        ]);

        self::handleError($errorMessage, $output, $jsonOutput, $action, $enhancedContext);
        return 1; // Command::FAILURE
    }

    /**
     * Validate common preconditions and provide helpful errors
     */
    public static function validatePreconditions(string $action): ?array
    {
        $errors = [];
        
        // Check if CPM is initialized
        if (!file_exists('.cpm') && !file_exists('.claude-project')) {
            $errors[] = [
                'type' => 'initialization',
                'message' => 'CPM not initialized in this project',
                'suggestion' => 'Run: cpm start',
                'ai_note' => 'Project needs CPM initialization first'
            ];
        }
        
        // Check permissions on CPM directory
        if (file_exists('.cpm') && !is_writable('.cpm')) {
            $errors[] = [
                'type' => 'permissions',
                'message' => 'No write permissions to .cpm directory',
                'suggestion' => 'Run: cpm tools:fix-perms',
                'ai_note' => 'Permission issue needs fixing'
            ];
        }
        
        return empty($errors) ? null : $errors;
    }

    /**
     * Get actionable error message for common scenarios
     */
    public static function getActionableMessage(string $scenario): string
    {
        $messages = [
            'no_project' => 'No CPM project found. Initialize with: cpm start',
            'no_functions' => 'No functions detected. Ensure project has analyzable code files.',
            'permission_error' => 'Permission denied. Fix with: cpm tools:fix-perms',
            'corrupted_data' => 'Data corruption detected. Reset with: cpm start --force',
            'invalid_command' => 'Invalid command. See available commands: cpm help --commands',
            'missing_dependency' => 'Missing dependencies. Install with: composer install'
        ];
        
        return $messages[$scenario] ?? 'Unknown error. Check: cpm verify --json';
    }
}