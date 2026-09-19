<?php

declare(strict_types=1);

namespace ClaudeProjectManager\Commands;

use ClaudeProjectManager\ConfigManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Command to migrate from legacy directory to .cpm structure
 */
class MigrateCommand extends Command
{
    protected static $defaultName = 'migrate';
    protected static $defaultDescription = 'Migrate from legacy directory to new .cpm structure';

    private ConfigManager $configManager;

    public function __construct()
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setDescription('Migrate from legacy directory to new .cpm directory structure');
        $this->setHelp(
            'This command migrates an existing legacy directory to the new .cpm structure. ' .
            'This provides better alignment with industry standards like .git, .vscode, etc.'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $projectRoot = getcwd();

        if (!$projectRoot) {
            $io->error('Could not determine current working directory');
            return Command::FAILURE;
        }

        $this->configManager = new ConfigManager($projectRoot);

        if (!$this->configManager->needsMigration()) {
            $legacyPath = $projectRoot . '/.claude-project';
            $newPath = $projectRoot . '/.cpm';
            
            if (is_dir($newPath)) {
                $io->success('Project already uses .cpm structure - no migration needed');
                return Command::SUCCESS;
            }
            
            if (!is_dir($legacyPath)) {
                $io->warning('No legacy directory found - nothing to migrate');
                return Command::SUCCESS;
            }
            
            $io->error('Migration conflict detected - both directories exist');
            return Command::FAILURE;
        }

        $io->section('CPM Structure Migration');
        $io->text('Migrating from legacy directory to modern .cpm structure...');

        if ($this->configManager->migrateToNewStructure()) {
            $io->success([
                '✅ Successfully migrated to .cpm structure!',
                '',
                'Benefits of the new structure:',
                '• Follows industry standards (.git, .vscode, .idea)',
                '• Cleaner project root directory',
                '• Better tool integration and recognition',
                '• Easier to exclude from version control'
            ]);
            
            $io->note([
                'What happened:',
                '• .claude-project directory renamed to .cpm',
                '• All existing data and configuration preserved',  
                '• CPM will automatically use the new structure',
                '',
                'Next steps:',
                '• Update your .gitignore to exclude .cpm/ instead of .claude-project/',
                '• Update any custom scripts that reference .claude-project paths'
            ]);

            return Command::SUCCESS;
        }

        $io->error([
            'Migration failed!',
            'The .claude-project directory could not be renamed to .cpm',
            'Please check file permissions and try again.'
        ]);

        return Command::FAILURE;
    }
}
