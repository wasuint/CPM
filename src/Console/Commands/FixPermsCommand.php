<?php

declare(strict_types=1);

namespace ClaudeProjectManager\Console\Commands;

use ClaudeProjectManager\Services\PermissionManager;
use ClaudeProjectManager\ConfigManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'tools:fix-perms',
    description: 'Diagnose and fix CPM permission issues'
)]
class FixPermsCommand extends Command
{
    private PermissionManager $permissionManager;
    private ConfigManager $config;

    public function __construct(ConfigManager $config = null)
    {
        parent::__construct();
        $this->config = $config ?? new ConfigManager(getcwd());
        $this->permissionManager = new PermissionManager($this->config);
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'check-only',
                'c',
                InputOption::VALUE_NONE,
                'Only check permissions, do not fix'
            )
            ->addOption(
                'dry-run',
                'd',
                InputOption::VALUE_NONE,
                'Show what would be fixed without making changes'
            )
            ->addOption(
                'report',
                'r',
                InputOption::VALUE_NONE,
                'Generate detailed report'
            )
            ->addOption(
                'json',
                'j',
                InputOption::VALUE_NONE,
                'Output in JSON format'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        
        $checkOnly = $input->getOption('check-only');
        $dryRun = $input->getOption('dry-run');
        $report = $input->getOption('report');
        $json = $input->getOption('json');

        try {
            // Diagnose permissions
            $diagnosis = $this->permissionManager->diagnosePermissions();

            if ($json) {
                $output->writeln(json_encode($diagnosis));
                return Command::SUCCESS;
            }

            if ($report) {
                $reportContent = $this->permissionManager->generateReport();
                $output->writeln($reportContent);
                return Command::SUCCESS;
            }

            $this->displayDiagnosis($io, $diagnosis);

            if ($diagnosis['status'] === 'ok') {
                $io->success('All permissions are correct!');
                return Command::SUCCESS;
            }

            if ($checkOnly) {
                $io->warning('Permission issues found. Use without --check-only to fix them.');
                return Command::FAILURE;
            }

            // Ask for confirmation unless it's a dry run
            if (!$dryRun) {
                if (!$io->confirm('Do you want to fix these permission issues?', false)) {
                    $io->note('Permission fix cancelled.');
                    return Command::SUCCESS;
                }
            }

            // Fix permissions
            $fixResult = $this->permissionManager->fixPermissions($dryRun);

            if ($dryRun) {
                $io->info('Dry run - showing what would be fixed:');
            } else {
                $io->info('Applying permission fixes:');
            }

            foreach ($fixResult['fixes_applied'] as $fix) {
                $io->writeln("  ✓ $fix");
            }

            foreach ($fixResult['errors'] as $error) {
                $io->error($error);
            }

            if (empty($fixResult['errors'])) {
                if ($dryRun) {
                    $io->success('Dry run completed. Run without --dry-run to apply fixes.');
                } else {
                    $io->success('Permission fixes applied successfully!');
                }
                return Command::SUCCESS;
            } else {
                $io->error('Some permission fixes failed. You may need to run with sudo.');
                return Command::FAILURE;
            }

        } catch (\Exception $e) {
            $io->error('Permission check failed: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }

    private function displayDiagnosis(SymfonyStyle $io, array $diagnosis): void
    {
        $io->title('CPM Permission Diagnosis');
        
        $io->definitionList(
            ['Current User' => $diagnosis['current_user']],
            ['Web Server User' => $diagnosis['detected_web_user'] ?? 'Not detected'],
            ['CPM Path' => $diagnosis['cpm_path']],
            ['Status' => $diagnosis['status'] === 'ok' ? 'Good' : 'Issues Found']
        );

        if (!empty($diagnosis['issues'])) {
            $io->section('Issues Found');
            
            foreach ($diagnosis['issues'] as $issue) {
                $type = match($issue['type']) {
                    'critical' => '🔴',
                    'error' => '❌',
                    'warning' => '⚠️',
                    default => 'ℹ️'
                };

                $io->writeln("$type <fg=red>{$issue['title']}</>");
                $io->writeln("   Path: <fg=yellow>{$issue['path']}</>");
                $io->writeln("   {$issue['description']}");
                
                if (isset($issue['current_permissions'])) {
                    $io->writeln("   Current permissions: {$issue['current_permissions']}");
                }
                if (isset($issue['current_owner'])) {
                    $io->writeln("   Current owner: {$issue['current_owner']}");
                }
                $io->newLine();
            }
        }

        if (!empty($diagnosis['recommendations'])) {
            $io->section('Recommended Fixes');
            
            foreach ($diagnosis['recommendations'] as $rec) {
                $priority = match($rec['priority']) {
                    'high' => '🔴 High',
                    'medium' => '🟡 Medium',
                    'low' => '🟢 Low',
                    default => $rec['priority']
                };

                $io->writeln("<fg=cyan>{$rec['action']}</> ($priority priority)");
                $io->writeln("   {$rec['explanation']}");
                
                if (!empty($rec['commands'])) {
                    $io->writeln("   Commands:");
                    foreach ($rec['commands'] as $cmd) {
                        $io->writeln("   <fg=green>$cmd</>");
                    }
                }
                $io->newLine();
            }
        }
    }
}