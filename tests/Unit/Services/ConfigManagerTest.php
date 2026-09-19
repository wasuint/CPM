<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use ClaudeProjectManager\Services\ConfigManager;

/**
 * Tests for ConfigManager service
 * Following TDD: Tests written BEFORE implementation
 */
class ConfigManagerTest extends TestCase
{
    private string $testConfigPath;
    private ConfigManager $configManager;

    protected function setUp(): void
    {
        $this->testConfigPath = sys_get_temp_dir() . '/cpm_config_test_' . uniqid() . '.json';
        $this->configManager = new ConfigManager($this->testConfigPath);
    }

    protected function tearDown(): void
    {
        if (file_exists($this->testConfigPath)) {
            unlink($this->testConfigPath);
        }
    }

    /** @test */
    public function it_returns_default_config_when_file_does_not_exist(): void
    {
        $config = $this->configManager->getAll();

        $this->assertIsArray($config);
        $this->assertArrayHasKey('validation', $config);
        $this->assertArrayHasKey('logging', $config);
        $this->assertArrayHasKey('backups', $config);
        $this->assertArrayHasKey('tracking', $config);
    }

    /** @test */
    public function it_gets_nested_config_value_by_dot_notation(): void
    {
        $value = $this->configManager->get('validation.strict');

        $this->assertIsBool($value);
    }

    /** @test */
    public function it_gets_top_level_config_section(): void
    {
        $validation = $this->configManager->get('validation');

        $this->assertIsArray($validation);
        $this->assertArrayHasKey('strict', $validation);
        $this->assertArrayHasKey('fail_on_error', $validation);
        $this->assertArrayHasKey('auto_repair', $validation);
    }

    /** @test */
    public function it_returns_null_for_non_existent_key(): void
    {
        $value = $this->configManager->get('non.existent.key');

        $this->assertNull($value);
    }

    /** @test */
    public function it_sets_nested_config_value(): void
    {
        $this->configManager->set('validation.strict', true);
        $value = $this->configManager->get('validation.strict');

        $this->assertTrue($value);
    }

    /** @test */
    public function it_persists_config_to_file(): void
    {
        $this->configManager->set('validation.strict', true);
        $this->configManager->save();

        // Create new instance to verify persistence
        $newConfigManager = new ConfigManager($this->testConfigPath);
        $value = $newConfigManager->get('validation.strict');

        $this->assertTrue($value);
    }

    /** @test */
    public function it_resets_config_to_defaults(): void
    {
        $this->configManager->set('validation.strict', true);
        $this->configManager->set('logging.level', 'debug');

        $this->configManager->reset();

        $config = $this->configManager->getAll();

        // Should have default structure
        $this->assertArrayHasKey('validation', $config);
        $this->assertArrayHasKey('logging', $config);
    }

    /** @test */
    public function it_validates_config_values(): void
    {
        // Valid value
        $result = $this->configManager->set('validation.strict', true);
        $this->assertTrue($result);

        // Invalid type (should fail or convert)
        $result = $this->configManager->set('validation.strict', 'invalid');
        $this->assertFalse($result);
    }

    /** @test */
    public function it_gets_default_value_when_key_missing(): void
    {
        $value = $this->configManager->get('non.existent', 'default_value');

        $this->assertEquals('default_value', $value);
    }

    /** @test */
    public function it_checks_if_key_exists(): void
    {
        $exists = $this->configManager->has('validation.strict');
        $this->assertTrue($exists);

        $notExists = $this->configManager->has('non.existent.key');
        $this->assertFalse($notExists);
    }

    /** @test */
    public function it_returns_all_default_values(): void
    {
        $defaults = $this->configManager->getDefaults();

        $this->assertIsArray($defaults);
        $this->assertArrayHasKey('validation', $defaults);
        $this->assertEquals(false, $defaults['validation']['strict']);
        $this->assertEquals(false, $defaults['validation']['fail_on_error']);
        $this->assertEquals(true, $defaults['validation']['auto_repair']);
    }

    /** @test */
    public function it_loads_existing_config_file(): void
    {
        // Create a config file manually
        $customConfig = [
            'validation' => ['strict' => true, 'fail_on_error' => true, 'auto_repair' => false],
            'logging' => ['enabled' => false, 'level' => 'error', 'rotate_size_mb' => 5],
            'backups' => ['enabled' => false, 'retention_count' => 5],
            'tracking' => ['mode' => 'minimal', 'auto_monitor' => true]
        ];

        file_put_contents($this->testConfigPath, json_encode($customConfig, JSON_PRETTY_PRINT));

        // Load it
        $configManager = new ConfigManager($this->testConfigPath);
        $loaded = $configManager->getAll();

        $this->assertEquals(true, $loaded['validation']['strict']);
        $this->assertEquals('error', $loaded['logging']['level']);
        $this->assertEquals('minimal', $loaded['tracking']['mode']);
    }

    /** @test */
    public function it_merges_partial_config_with_defaults(): void
    {
        // Create partial config (missing some keys)
        $partialConfig = [
            'validation' => ['strict' => true]
        ];

        file_put_contents($this->testConfigPath, json_encode($partialConfig, JSON_PRETTY_PRINT));

        $configManager = new ConfigManager($this->testConfigPath);
        $config = $configManager->getAll();

        // Should have the custom value
        $this->assertEquals(true, $config['validation']['strict']);

        // Should have default values for missing keys
        $this->assertArrayHasKey('logging', $config);
        $this->assertArrayHasKey('backups', $config);
        $this->assertArrayHasKey('tracking', $config);
    }
}
