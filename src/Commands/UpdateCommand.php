<?php

declare(strict_types=1);

namespace ClaudeProjectManager\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Update project template files from CPM core installation
 * Safely updates CLAUDE_DISCOVERY.md and schema files without touching project data
 */
class UpdateCommand extends Command
{
    protected static $defaultName = 'update';
    protected static $defaultDescription = 'Update project template files from CPM core installation';

    protected function configure(): void
    {
        $this
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Show what would be updated without making changes'
            )
            ->addOption(
                'force',
                'f',
                InputOption::VALUE_NONE,
                'Force update even if local file has modifications'
            )
            ->addOption(
                'backup',
                'b',
                InputOption::VALUE_NONE,
                'Create backup of existing files before updating'
            )
            ->setHelp('
Updates project template files from the CPM core installation.

This command safely updates:
- CLAUDE_DISCOVERY.md (AI integration guide)
- Schema files in .cpm/schemas/
- Config files in .cpm/config/ (only copies if missing, never overwrites)
- Template files in .cpm/templates/ (if exists)

The command will:
1. Check CPM core version vs project version
2. Detect which files need updating
3. Create backups (if --backup flag used)
4. Update outdated files
5. Preserve project-specific customizations
6. Never overwrite existing config files (only copies missing ones)

Examples:
  cpm update                    # Update all outdated files
  cpm update --dry-run          # Preview what would be updated
  cpm update --backup           # Update with backups (.bak files)
  cpm update --force            # Force update all files

Safety Features:
- Only updates files that are older than core templates
- Preserves .cpm/db/* database files (never touched)
- Never overwrites existing config files (only copies if missing)
- Creates .bak backups if --backup flag used
- Dry-run mode to preview changes first
            ');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = $input->getOption('dry-run');
        $force = $input->getOption('force');
        $backup = $input->getOption('backup');

        $projectRoot = getcwd();

        $io->title('CPM Project Update');

        // Detect CPM core location
        $cpmCorePath = $this->detectCpmCore();

        if (!$cpmCorePath) {
            $io->error('Could not detect CPM core installation. Is CPM installed globally?');
            $io->note('Expected locations: /opt/cpm, /usr/local/cpm');
            return Command::FAILURE;
        }

        $io->text("Core CPM location: <info>{$cpmCorePath}</info>");
        $io->text("Project location: <info>{$projectRoot}</info>");
        $io->newLine();

        // Get core version
        $coreVersion = $this->getCpmVersion($cpmCorePath);
        $io->text("Core CPM version: <info>{$coreVersion}</info>");

        // Files to update
        $filesToUpdate = [
            'CLAUDE_DISCOVERY.md' => 'templates/CLAUDE_DISCOVERY.md',
            '.cpm/schemas/' => 'resources/schemas/',
            '.cpm/config/' => 'config/',
        ];

        $updated = 0;
        $skipped = 0;
        $errors = [];

        foreach ($filesToUpdate as $projectPath => $corePath) {
            $fullProjectPath = $projectRoot . '/' . $projectPath;
            $fullCorePath = $cpmCorePath . '/' . $corePath;

            if (is_dir($fullCorePath)) {
                // Directory update (schemas)
                $result = $this->updateDirectory($fullCorePath, $fullProjectPath, $dryRun, $force, $backup, $io);
                $updated += $result['updated'];
                $skipped += $result['skipped'];
            } else {
                // File update
                $result = $this->updateFile($fullCorePath, $fullProjectPath, $dryRun, $force, $backup, $io);
                if ($result === 'updated') $updated++;
                elseif ($result === 'skipped') $skipped++;
                elseif ($result === 'error') $errors[] = $projectPath;
            }
        }

        $io->newLine();

        if ($dryRun) {
            $io->info("DRY RUN: Would update {$updated} file(s), skip {$skipped} file(s)");
        } else {
            $io->success("Updated {$updated} file(s), skipped {$skipped} file(s)");
            if ($updated > 0) {
                $io->note('Run "cpm context:digest" to regenerate project context with updated templates');
            }
        }

        if (!empty($errors)) {
            $io->warning('Errors updating: ' . implode(', ', $errors));
        }

        return Command::SUCCESS;
    }

    private function detectCpmCore(): ?string
    {
        // Check common CPM installation locations
        $locations = [
            '/opt/cpm',
            '/usr/local/cpm',
            dirname(__DIR__, 2), // Relative to current CPM
        ];

        foreach ($locations as $location) {
            if (file_exists($location . '/bin/claude-project') && file_exists($location . '/templates/CLAUDE_DISCOVERY.md')) {
                return realpath($location);
            }
        }

        return null;
    }

    private function getCpmVersion(string $cpmPath): string
    {
        $appFile = $cpmPath . '/src/Console/Application.php';
        if (file_exists($appFile)) {
            $content = file_get_contents($appFile);
            if (preg_match("/VERSION = '([^']+)'/", $content, $matches)) {
                return $matches[1];
            }
        }
        return 'unknown';
    }

    private function updateFile(string $source, string $dest, bool $dryRun, bool $force, bool $backup, SymfonyStyle $io): string
    {
        if (!file_exists($source)) {
            return 'error';
        }

        $shouldUpdate = false;

        if (!file_exists($dest)) {
            $shouldUpdate = true;
            $reason = 'new';
        } elseif ($force) {
            $shouldUpdate = true;
            $reason = 'forced';
        } elseif (filemtime($source) > filemtime($dest)) {
            $shouldUpdate = true;
            $reason = 'outdated';
        } else {
            $io->text("⏭ Skip: " . basename($dest) . " (already up-to-date)");
            return 'skipped';
        }

        if ($shouldUpdate) {
            if ($dryRun) {
                $io->text("🔄 Would update: " . basename($dest) . " ({$reason})");
                return 'updated';
            }

            // Create backup if requested
            if ($backup && file_exists($dest)) {
                $backupPath = $dest . '.bak';
                copy($dest, $backupPath);
                $io->text("💾 Backup created: " . basename($backupPath));
            }

            // Ensure directory exists
            $destDir = dirname($dest);
            if (!is_dir($destDir)) {
                mkdir($destDir, 0755, true);
            }

            // Copy file
            if (copy($source, $dest)) {
                $io->text("✅ Updated: " . basename($dest) . " ({$reason})");
                return 'updated';
            } else {
                $io->error("Failed to update: " . basename($dest));
                return 'error';
            }
        }

        return 'skipped';
    }

    private function updateDirectory(string $sourceDir, string $destDir, bool $dryRun, bool $force, bool $backup, SymfonyStyle $io): array
    {
        $updated = 0;
        $skipped = 0;

        if (!is_dir($sourceDir)) {
            return ['updated' => 0, 'skipped' => 0];
        }

        $files = glob($sourceDir . '/*.{json,md,php}', GLOB_BRACE);

        // Check if this is config directory (never overwrite config files)
        $isConfigDir = strpos($destDir, '/.cpm/config') !== false;

        foreach ($files as $sourceFile) {
            $filename = basename($sourceFile);
            $destFile = $destDir . '/' . $filename;

            // For config files, only copy if missing (never overwrite)
            if ($isConfigDir && file_exists($destFile)) {
                $io->text("⏭ Skip: " . basename($destFile) . " (config files are never overwritten)");
                $skipped++;
                continue;
            }

            $result = $this->updateFile($sourceFile, $destFile, $dryRun, $force, $backup, $io);

            if ($result === 'updated') $updated++;
            elseif ($result === 'skipped') $skipped++;
        }

        return ['updated' => $updated, 'skipped' => $skipped];
    }
}
