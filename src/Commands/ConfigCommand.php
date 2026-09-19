<?php

declare(strict_types=1);

namespace ClaudeProjectManager\Commands;

use ClaudeProjectManager\DatabaseManager;
use ClaudeProjectManager\Services\ConfigManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Configuration management command
 * Allows users to view and modify CPM configuration
 */
class ConfigCommand extends Command
{
    private DatabaseManager $database;
    private ConfigManager $configManager;

    protected static $defaultName = 'config';
    protected static $defaultDescription = 'Manage CPM configuration settings';

    public function __construct(DatabaseManager $database)
    {
        parent::__construct();
        $this->database = $database;

        // Config file location: .cpm/config.json
        $configPath = dirname($this->database->getDatabasePath()) . '/config.json';
        $this->configManager = new ConfigManager($configPath);
    }

    protected function configure(): void
    {
        $this
            ->addArgument(
                'action',
                InputArgument::REQUIRED,
                'Action to perform: get, set, show, reset, defaults'
            )
            ->addArgument(
                'key',
                InputArgument::OPTIONAL,
                'Configuration key (dot notation, e.g., validation.strict)'
            )
            ->addArgument(
                'value',
                InputArgument::OPTIONAL,
                'Value to set (for "set" action)'
            )
            ->addOption(
                'json',
                null,
                InputOption::VALUE_NONE,
                'Output JSON format'
            )
            ->setHelp('
This command manages CPM configuration settings.

Actions:
  get <key>           Get configuration value
  set <key> <value>   Set configuration value
  show                Show all configuration
  reset               Reset configuration to defaults
  defaults            Show default configuration

Configuration Keys:
  validation.strict              Enable strict validation (boolean)
  validation.fail_on_error       Fail on validation errors (boolean)
  validation.auto_repair         Auto-repair data issues (boolean)
  logging.enabled                Enable logging (boolean)
  logging.level                  Log level (string: debug, info, warning, error)
  logging.rotate_size_mb         Log rotation size in MB (integer)
  backups.enabled                Enable backups (boolean)
  backups.retention_count        Number of backups to keep (integer)
  tracking.mode                  Tracking mode (string: full, minimal, disabled)
  tracking.auto_monitor          Auto-start monitoring (boolean)

Examples:
  cpm config show
  cpm config get validation.strict
  cpm config set validation.strict true
  cpm config set logging.level debug
  cpm config reset
  cpm config defaults
  cpm config show --json

Value Types:
  Boolean: true, false
  String: "value" or value
  Integer: 123

Notes:
- Configuration is stored in .cpm/config.json
- Changes take effect immediately
- Use "reset" to restore defaults
            ');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $action = $input->getArgument('action');
        $key = $input->getArgument('key');
        $value = $input->getArgument('value');
        $json = $input->getOption('json');

        try {
            $result = match ($action) {
                'get' => $this->getConfig($key, $json, $output, $io),
                'set' => $this->setConfig($key, $value, $json, $output, $io),
                'show' => $this->showConfig($json, $output, $io),
                'reset' => $this->resetConfig($json, $output, $io),
                'defaults' => $this->showDefaults($json, $output, $io),
                default => $this->handleUnknownAction($action, $json, $output, $io)
            };

            return $result;

        } catch (\Exception $e) {
            if ($json) {
                $output->writeln(json_encode([
                    'success' => false,
                    'error' => $e->getMessage()
                ]));
            } else {
                $io->error('Config command failed: ' . $e->getMessage());
            }

            return Command::FAILURE;
        }
    }

    private function getConfig(?string $key, bool $json, OutputInterface $output, SymfonyStyle $io): int
    {
        if (!$key) {
            if ($json) {
                $output->writeln(json_encode(['success' => false, 'error' => 'Key required for get action']));
            } else {
                $io->error('Please specify a key: cpm config get <key>');
            }
            return Command::FAILURE;
        }

        $value = $this->configManager->get($key);

        if ($value === null) {
            if ($json) {
                $output->writeln(json_encode(['success' => false, 'error' => 'Key not found', 'key' => $key]));
            } else {
                $io->error("Configuration key '$key' not found");
            }
            return Command::FAILURE;
        }

        if ($json) {
            $output->writeln(json_encode(['success' => true, 'key' => $key, 'value' => $value]));
        } else {
            $io->success(sprintf('%s = %s', $key, $this->formatValue($value)));
        }

        return Command::SUCCESS;
    }

    private function setConfig(?string $key, $value, bool $json, OutputInterface $output, SymfonyStyle $io): int
    {
        if (!$key || $value === null) {
            if ($json) {
                $output->writeln(json_encode(['success' => false, 'error' => 'Key and value required']));
            } else {
                $io->error('Please specify key and value: cpm config set <key> <value>');
            }
            return Command::FAILURE;
        }

        // Convert string value to appropriate type
        $typedValue = $this->parseValue($value);

        if (!$this->configManager->set($key, $typedValue)) {
            if ($json) {
                $output->writeln(json_encode(['success' => false, 'error' => 'Invalid value type', 'key' => $key]));
            } else {
                $io->error("Invalid value type for '$key'");
            }
            return Command::FAILURE;
        }

        $this->configManager->save();

        if ($json) {
            $output->writeln(json_encode(['success' => true, 'key' => $key, 'value' => $typedValue]));
        } else {
            $io->success(sprintf('Set %s = %s', $key, $this->formatValue($typedValue)));
        }

        return Command::SUCCESS;
    }

    private function showConfig(bool $json, OutputInterface $output, SymfonyStyle $io): int
    {
        $config = $this->configManager->getAll();

        if ($json) {
            $output->writeln(json_encode($config, JSON_PRETTY_PRINT));
        } else {
            $io->title('CPM Configuration');
            $this->displayConfigSection($io, 'Validation', $config['validation'] ?? []);
            $this->displayConfigSection($io, 'Logging', $config['logging'] ?? []);
            $this->displayConfigSection($io, 'Backups', $config['backups'] ?? []);
            $this->displayConfigSection($io, 'Tracking', $config['tracking'] ?? []);
        }

        return Command::SUCCESS;
    }

    private function resetConfig(bool $json, OutputInterface $output, SymfonyStyle $io): int
    {
        $this->configManager->reset();
        $this->configManager->save();

        if ($json) {
            $output->writeln(json_encode(['success' => true, 'message' => 'Configuration reset to defaults']));
        } else {
            $io->success('Configuration reset to defaults');
        }

        return Command::SUCCESS;
    }

    private function showDefaults(bool $json, OutputInterface $output, SymfonyStyle $io): int
    {
        $defaults = $this->configManager->getDefaults();

        if ($json) {
            $output->writeln(json_encode($defaults, JSON_PRETTY_PRINT));
        } else {
            $io->title('CPM Default Configuration');
            $this->displayConfigSection($io, 'Validation', $defaults['validation'] ?? []);
            $this->displayConfigSection($io, 'Logging', $defaults['logging'] ?? []);
            $this->displayConfigSection($io, 'Backups', $defaults['backups'] ?? []);
            $this->displayConfigSection($io, 'Tracking', $defaults['tracking'] ?? []);
        }

        return Command::SUCCESS;
    }

    private function handleUnknownAction(string $action, bool $json, OutputInterface $output, SymfonyStyle $io): int
    {
        $validActions = ['get', 'set', 'show', 'reset', 'defaults'];

        if ($json) {
            $output->writeln(json_encode([
                'success' => false,
                'error' => 'Unknown action',
                'action' => $action,
                'valid_actions' => $validActions
            ]));
        } else {
            $io->error("Unknown action '$action'");
            $io->note('Valid actions: ' . implode(', ', $validActions));
        }

        return Command::FAILURE;
    }

    private function displayConfigSection(SymfonyStyle $io, string $title, array $config): void
    {
        $io->section($title);

        foreach ($config as $key => $value) {
            $io->writeln(sprintf('  %s: %s', $key, $this->formatValue($value)));
        }
    }

    private function formatValue($value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_array($value)) {
            return json_encode($value);
        }

        return (string) $value;
    }

    private function parseValue(string $value)
    {
        // Parse boolean
        if (strtolower($value) === 'true') {
            return true;
        }

        if (strtolower($value) === 'false') {
            return false;
        }

        // Parse integer
        if (is_numeric($value) && strpos($value, '.') === false) {
            return (int) $value;
        }

        // Parse float
        if (is_numeric($value)) {
            return (float) $value;
        }

        // Return as string
        return $value;
    }
}
