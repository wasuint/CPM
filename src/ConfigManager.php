<?php

declare(strict_types=1);

namespace ClaudeProjectManager;

use Dotenv\Dotenv;

/**
 * Manages configuration, environment variables, and project settings
 */
class ConfigManager
{
    private array $config;
    private string $projectRoot;
    private Dotenv $dotenv;

    public function __construct(string $projectRoot)
    {
        $this->projectRoot = rtrim($projectRoot, '/');
        $this->config = [];
        
        $envPath = $this->getCpmPath();
        if (file_exists($envPath . '/.env')) {
            $this->dotenv = Dotenv::createImmutable($envPath);
            $this->dotenv->load();
        }
        
        $this->loadConfiguration();
    }

    /**
     * Loads configuration from .env and config files
     */
    public function loadConfiguration(): void
    {
        $this->config = $this->getDefaultConfig();
        
        // Load global config first (from CPM installation directory)
        $globalConfigPath = dirname(__DIR__) . '/config/rules.json';
        if (file_exists($globalConfigPath)) {
            $globalRules = json_decode(file_get_contents($globalConfigPath), true);
            if ($globalRules) {
                $this->config['rules'] = array_merge($this->config['rules'], $globalRules);
            }
        }
        
        // Then load project-specific config (which can override global config).
        // Order: .cpm/config/rules.json first (backward compat / published copy
        // of the global defaults), then project root rules.json LAST so the
        // project-level file is authoritative. Otherwise a regenerated
        // .cpm/config/rules.json (created by `cpm start`) silently clobbers
        // user-curated exclusion/inclusion patterns at the project root.
        $cpmPath = $this->getCpmPath();
        $configPath = $cpmPath . '/config';
        $rulesFile = $configPath . '/rules.json';

        if (file_exists($rulesFile)) {
            $rules = json_decode(file_get_contents($rulesFile), true);
            if ($rules) {
                $this->config['rules'] = array_merge($this->config['rules'], $rules);
            }
        }

        // Project root rules.json wins. This is the version a developer
        // commits to git alongside the codebase.
        $projectRulesFile = $this->projectRoot . '/rules.json';
        if (file_exists($projectRulesFile)) {
            $rules = json_decode(file_get_contents($projectRulesFile), true);
            if ($rules) {
                $this->config['rules'] = array_merge($this->config['rules'], $rules);
            }
        }
        
        $this->config['paths'] = [
            'project_root' => $this->projectRoot,
            'cpm_root' => $cpmPath,
            'database' => $cpmPath . '/db',
            'config' => $cpmPath . '/config',
            'context' => $cpmPath . '/context',
            'schemas' => $cpmPath . '/schemas',
            'templates' => $cpmPath . '/templates',
            'logs' => $cpmPath . '/logs'
        ];
    }

    /**
     * Gets configuration value with optional default
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $keys = explode('.', $key);
        $value = $this->config;
        
        foreach ($keys as $k) {
            if (!is_array($value) || !array_key_exists($k, $value)) {
                return $default;
            }
            $value = $value[$k];
        }
        
        return $value;
    }

    /**
     * Sets configuration value
     */
    public function set(string $key, mixed $value): void
    {
        $keys = explode('.', $key);
        $reference = &$this->config;
        
        for ($i = 0; $i < count($keys) - 1; $i++) {
            $k = $keys[$i];
            if (!isset($reference[$k]) || !is_array($reference[$k])) {
                $reference[$k] = [];
            }
            $reference = &$reference[$k];
        }
        
        $reference[end($keys)] = $value;
    }

    /**
     * Gets environment variable with fallback
     */
    public function env(string $key, mixed $default = null): mixed
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? $default;
        
        if (is_string($value)) {
            if (strtolower($value) === 'true') {
                return true;
            }
            if (strtolower($value) === 'false') {
                return false;
            }
            if (is_numeric($value)) {
                return strpos($value, '.') !== false ? (float)$value : (int)$value;
            }
        }
        
        return $value;
    }

    /**
     * Loads analysis rules for different file types
     */
    public function getAnalysisRules(string $fileType = 'php'): array
    {
        $defaultRules = $this->get('rules.' . $fileType, []);
        $allRules = $this->get('rules.all', []);
        
        return array_merge($allRules, $defaultRules);
    }

    /**
     * Gets Telegram bot configuration
     */
    public function getTelegramConfig(): array
    {
        return [
            'bot_token' => (string) $this->env('TELEGRAM_BOT_TOKEN', ''),
            'chat_id' => (string) $this->env('TELEGRAM_CHAT_ID', ''),
            'enabled' => (bool) $this->env('TELEGRAM_ENABLED', false),
            'notify_on_start' => $this->get('telegram.notify_on_start', true),
            'notify_on_completion' => $this->get('telegram.notify_on_completion', true),
            'notify_on_error' => $this->get('telegram.notify_on_error', true)
        ];
    }

    /**
     * Gets project-specific settings
     */
    public function getProjectSettings(): array
    {
        return [
            'type' => $this->get('project.type', 'mixed'),
            'include_patterns' => $this->getInclusionPatterns(),
            'exclude_patterns' => $this->getExclusionPatterns(),
            'max_file_size' => $this->get('analysis.max_file_size', 500),
            'enforce_strict_rules' => $this->get('analysis.enforce_strict_rules', true)
        ];
    }

    /**
     * Saves current configuration to files
     */
    public function save(): void
    {
        $configPath = $this->get('paths.config');
        if (!is_dir($configPath)) {
            mkdir($configPath, 0755, true);
        }
        
        $rulesFile = $configPath . '/rules.json';
        $rules = $this->get('rules', []);
        
        file_put_contents($rulesFile, json_encode($rules));
    }

    /**
     * Creates default configuration files
     */
    public function createDefaults(string $projectType): void
    {
        $cpmPath = $this->getCpmPath();
        $configPath = $cpmPath . '/config';
        
        if (!is_dir($configPath)) {
            mkdir($configPath, 0755, true);
        }
        
        $rules = $this->getDefaultRulesForType($projectType);
        file_put_contents($configPath . '/rules.json', json_encode($rules));
        
        $envExample = [
            '# Telegram Bot Configuration',
            'TELEGRAM_BOT_TOKEN=your_telegram_bot_token_here',
            'TELEGRAM_CHAT_ID=your_telegram_chat_id_here',
            'TELEGRAM_ENABLED=false',
            '',
            '# Analysis Settings',
            'MAX_FUNCTION_LENGTH=100',
            'ENFORCE_STRICT_TYPES=true'
        ];
        
        file_put_contents($cpmPath . '/.env.example', implode("\n", $envExample));
    }

    /**
     * Validates configuration completeness
     */
    public function validate(): array
    {
        $errors = [];
        
        $telegramConfig = $this->getTelegramConfig();
        if ($telegramConfig['enabled']) {
            if (empty($telegramConfig['bot_token'])) {
                $errors[] = 'TELEGRAM_BOT_TOKEN is required when Telegram is enabled';
            }
            if (empty($telegramConfig['chat_id'])) {
                $errors[] = 'TELEGRAM_CHAT_ID is required when Telegram is enabled';
            }
        }
        
        $projectRoot = $this->get('paths.project_root');
        if (!is_dir($projectRoot)) {
            $errors[] = 'Project root directory does not exist: ' . $projectRoot;
        }
        
        return $errors;
    }

    /**
     * Gets file exclusion patterns
     */
    public function getExclusionPatterns(): array
    {
        $defaults = [
            'vendor',
            'node_modules',
            '.git',
            '.cpm',
            '.claude-project',    // Legacy directory (to be migrated)
            'venv',              // Python virtual environment
            '.venv',             // Alternative venv name
            'env',               // Another common name
            '.env*',             // Environment files
            '__pycache__',       // Python bytecode cache
            '*.pyc',             // Python compiled files
            '*.pyo',             // Python optimized files
            '*.pyd',             // Python DLL files
            '.Python',           // Python virtualenv marker
            'pip-log.txt',       // Pip logs
            'pip-delete-this-directory.txt',
            '*.egg-info',        // Python package info
            '.pytest_cache',     // Pytest cache
            '.tox',              // Tox testing
            '.coverage',         // Coverage reports
            'htmlcov',           // HTML coverage
            '.hypothesis',       // Hypothesis testing
            '.mypy_cache',       // MyPy type checking cache
            '*.min.js',
            '*.min.css',
            'cache',
            'tmp',
            'temp',
            'build',             // Build directories
            'dist',              // Distribution directories
            'logs',              // Log directories
            '.DS_Store',         // macOS files
            'Thumbs.db',         // Windows files
            '.sass-cache',       // Sass cache
            '.npm',              // npm cache
            '.yarn',             // Yarn cache
            'coverage',          // Coverage reports
            '.nyc_output',       // NYC coverage
            'junit.xml',         // Test reports
            'test-results',      // Test results
            '.vscode',           // VS Code settings
            '.idea',             // IntelliJ settings
            '*.swp',             // Vim swap files
            '*.swo',             // Vim swap files
            '*~'                 // Backup files
        ];
        
        // First check rules.json exclusion_patterns
        $rulesExclusions = $this->get('rules.exclusion_patterns', []);
        
        // Then check analysis.exclude_patterns (legacy/override)
        $analysisExclusions = $this->get('analysis.exclude_patterns', []);
        
        // Merge all three: defaults, rules.json, and analysis overrides
        $allPatterns = array_merge($defaults, $rulesExclusions, $analysisExclusions);
        
        // Remove duplicates and return
        return array_unique($allPatterns);
    }

    /**
     * Gets file inclusion patterns
     *
     * When `rules.inclusion_patterns` is explicitly configured (non-empty),
     * it OVERRIDES the built-in defaults entirely. This lets a project pin
     * itself to a single language (e.g. only "*.py") without inheriting the
     * universal default set of PHP/JS/TS extensions.
     *
     * Falls back to the default extension set only when nothing is configured.
     */
    public function getInclusionPatterns(): array
    {
        $defaults = [
            '*.php',
            '*.py',
            '*.js',
            '*.ts',
            '*.jsx',
            '*.tsx'
        ];

        // Project-level inclusion override (highest priority). When set,
        // it is authoritative — defaults and the legacy analysis slot are
        // ignored. This lets a project pin itself strictly to one language.
        $rulesInclusions = $this->get('rules.inclusion_patterns', []);
        if (!empty($rulesInclusions)) {
            return array_unique($rulesInclusions);
        }

        // Legacy override slot under analysis.include_patterns. Same idea
        // but only consulted when rules.inclusion_patterns is absent.
        $analysisInclusions = $this->get('analysis.include_patterns', []);
        if (!empty($analysisInclusions)) {
            return array_unique($analysisInclusions);
        }

        // Nothing configured — fall back to the built-in default set.
        return array_unique($defaults);
    }

    /**
     * Gets default configuration structure
     *
     * @return array Default configuration
     */
    private function getDefaultConfig(): array
    {
        return [
            'analysis' => [
                'max_file_size' => 500,
                'max_function_length' => 100,
                'enforce_strict_rules' => true,
                'include_patterns' => ['*.php', '*.py'],
                'exclude_patterns' => []
            ],
            'telegram' => [
                'notify_on_start' => true,
                'notify_on_completion' => true,
                'notify_on_error' => true
            ],
            'rules' => [
                'all' => [
                    'max_line_length' => 120,
                    'require_documentation' => true,
                    'enforce_type_hints' => true
                ],
                'php' => [
                    'require_declare_strict_types' => true,
                    'max_function_length' => 100,
                    'require_return_types' => true
                ],
                'python' => [
                    'max_function_length' => 100,
                    'require_type_hints' => true,
                    'pep8_compliance' => true
                ]
            ]
        ];
    }

    /**
     * Gets default rules for specific project type
     *
     * @param string $projectType Project type (php, python, mixed)
     * @return array Default rules
     */
    private function getDefaultRulesForType(string $projectType): array
    {
        $baseRules = $this->getDefaultConfig()['rules'];
        
        switch ($projectType) {
            case 'php':
                return [
                    'all' => $baseRules['all'],
                    'php' => $baseRules['php']
                ];
            case 'python':
                return [
                    'all' => $baseRules['all'],
                    'python' => $baseRules['python']
                ];
            default:
                return $baseRules;
        }
    }

    /**
     * Loads configuration from .claude.md file
     */
    public function loadFromFile(string $filePath): void
    {
        if (!file_exists($filePath)) {
            throw new \RuntimeException("Configuration file not found: {$filePath}");
        }
        
        $content = file_get_contents($filePath);
        $config = [];
        
        // Extract project name
        if (preg_match('/(?:Project Name).*?[:*]\s*(.+)/i', $content, $matches)) {
            $config['project_name'] = trim($matches[1]);
        }
        
        // Extract project type  
        if (preg_match('/(?:Project Type).*?[:*]\s*(.+)/i', $content, $matches)) {
            $config['project_type'] = trim($matches[1]);
        }
        
        // Extract telegram settings
        $config['telegram_enabled'] = stripos($content, 'TELEGRAM_ENABLED=true') !== false;
        
        // Extract exclusion patterns from ANALYZE_FILES section
        if (preg_match('/ANALYZE_FILES:\s*.*?- Exclude:\s*(.+?)(?=\s*-|\s*```|$)/s', $content, $matches)) {
            $excludeString = trim($matches[1]);
            // Parse comma-separated patterns and clean them up
            $excludePatterns = array_map(function($pattern) {
                return trim(trim($pattern), '"\'');
            }, explode(',', $excludeString));
            
            // Remove empty patterns
            $excludePatterns = array_filter($excludePatterns);
            
            if (!empty($excludePatterns)) {
                $config['analysis.exclude_patterns'] = $excludePatterns;
            }
        }
        
        // Merge with existing config
        foreach ($config as $key => $value) {
            $this->set($key, $value);
        }
    }

    /**
     * Gets all configuration settings
     */
    public function getAllSettings(): array
    {
        return $this->config;
    }

    /**
     * Gets the CPM directory path with backward compatibility
     * Checks for .cpm first, then falls back to legacy directory
     */
    public function getCpmPath(): string
    {
        $cpmPath = $this->projectRoot . '/.cpm';
        $legacyPath = $this->projectRoot . '/.claude-project';
        
        // If .cpm exists, use it
        if (is_dir($cpmPath)) {
            return $cpmPath;
        }
        
        // If legacy directory exists, use it for backward compatibility
        if (is_dir($legacyPath)) {
            return $legacyPath;
        }
        
        // Default to .cpm for new projects
        return $cpmPath;
    }

    /**
     * Migrates from legacy directory to .cpm structure
     */
    public function migrateToNewStructure(): bool
    {
        $legacyPath = $this->projectRoot . '/.claude-project';
        $newPath = $this->projectRoot . '/.cpm';
        
        if (!is_dir($legacyPath)) {
            return false; // Nothing to migrate
        }
        
        if (is_dir($newPath)) {
            return false; // Migration already done or conflict
        }
        
        // Rename the directory
        if (rename($legacyPath, $newPath)) {
            echo "✅ Migrated legacy directory to .cpm structure\n";
            return true;
        }
        
        return false;
    }

    /**
     * Checks if migration is needed
     */
    public function needsMigration(): bool
    {
        $legacyPath = $this->projectRoot . '/.claude-project';
        $newPath = $this->projectRoot . '/.cpm';
        
        return is_dir($legacyPath) && !is_dir($newPath);
    }
}