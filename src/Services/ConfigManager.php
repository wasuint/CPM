<?php

declare(strict_types=1);

namespace ClaudeProjectManager\Services;

/**
 * Configuration manager for CPM
 * Allows customization of behavior and graceful degradation
 */
class ConfigManager
{
    private string $configPath;
    private array $config;
    private array $defaults;

    public function __construct(string $configPath)
    {
        $this->configPath = $configPath;
        $this->defaults = $this->getDefaultConfig();
        $this->load();
    }

    /**
     * Get default configuration
     */
    public function getDefaults(): array
    {
        return $this->defaults;
    }

    /**
     * Get default configuration structure
     */
    private function getDefaultConfig(): array
    {
        return [
            'validation' => [
                'strict' => false,
                'fail_on_error' => false,
                'auto_repair' => true,
            ],
            'logging' => [
                'enabled' => true,
                'level' => 'info',
                'rotate_size_mb' => 10,
            ],
            'backups' => [
                'enabled' => true,
                'retention_count' => 2,
            ],
            'tracking' => [
                'mode' => 'full',  // full, minimal, disabled
                'auto_monitor' => false,
            ],
        ];
    }

    /**
     * Load configuration from file or use defaults
     */
    private function load(): void
    {
        if (file_exists($this->configPath)) {
            $fileConfig = json_decode(file_get_contents($this->configPath), true);

            if (json_last_error() === JSON_ERROR_NONE && is_array($fileConfig)) {
                // Merge file config with defaults (file config takes precedence)
                $this->config = $this->mergeConfig($this->defaults, $fileConfig);
            } else {
                $this->config = $this->defaults;
            }
        } else {
            $this->config = $this->defaults;
        }
    }

    /**
     * Recursively merge configurations
     */
    private function mergeConfig(array $defaults, array $custom): array
    {
        foreach ($custom as $key => $value) {
            if (is_array($value) && isset($defaults[$key]) && is_array($defaults[$key])) {
                $defaults[$key] = $this->mergeConfig($defaults[$key], $value);
            } else {
                $defaults[$key] = $value;
            }
        }

        return $defaults;
    }

    /**
     * Get all configuration
     */
    public function getAll(): array
    {
        return $this->config;
    }

    /**
     * Get configuration value by dot notation
     * Example: get('validation.strict')
     */
    public function get(string $key, $default = null)
    {
        $keys = explode('.', $key);
        $value = $this->config;

        foreach ($keys as $k) {
            if (!isset($value[$k])) {
                return $default;
            }
            $value = $value[$k];
        }

        return $value;
    }

    /**
     * Check if configuration key exists
     */
    public function has(string $key): bool
    {
        $keys = explode('.', $key);
        $value = $this->config;

        foreach ($keys as $k) {
            if (!isset($value[$k])) {
                return false;
            }
            $value = $value[$k];
        }

        return true;
    }

    /**
     * Set configuration value by dot notation
     * Returns true on success, false on validation failure
     */
    public function set(string $key, $value): bool
    {
        // Validate value type
        if (!$this->validateValue($key, $value)) {
            return false;
        }

        $keys = explode('.', $key);
        $config = &$this->config;

        foreach ($keys as $i => $k) {
            if ($i === count($keys) - 1) {
                $config[$k] = $value;
            } else {
                if (!isset($config[$k]) || !is_array($config[$k])) {
                    $config[$k] = [];
                }
                $config = &$config[$k];
            }
        }

        return true;
    }

    /**
     * Validate value type based on defaults
     */
    private function validateValue(string $key, $value): bool
    {
        $defaultValue = $this->get($key);

        if ($defaultValue === null) {
            // Key doesn't exist in defaults, allow any value
            return true;
        }

        // Check type compatibility
        $expectedType = gettype($defaultValue);
        $actualType = gettype($value);

        // Allow setting boolean values
        if ($expectedType === 'boolean' && !is_bool($value)) {
            return false;
        }

        // Allow setting string values
        if ($expectedType === 'string' && !is_string($value)) {
            return false;
        }

        // Allow setting integer values
        if ($expectedType === 'integer' && !is_int($value)) {
            return false;
        }

        return true;
    }

    /**
     * Save configuration to file
     */
    public function save(): bool
    {
        $configDir = dirname($this->configPath);

        if (!is_dir($configDir)) {
            mkdir($configDir, 0755, true);
        }

        $json = json_encode($this->config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        return file_put_contents($this->configPath, $json) !== false;
    }

    /**
     * Reset configuration to defaults
     */
    public function reset(): void
    {
        $this->config = $this->defaults;
    }
}
