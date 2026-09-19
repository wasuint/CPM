<?php

declare(strict_types=1);

namespace Tests\Unit\Database;

use PHPUnit\Framework\TestCase;
use ClaudeProjectManager\Database\SchemaValidator;
use ClaudeProjectManager\Database\ValidationResult;

/**
 * Tests for SchemaValidator
 * Ensures schema validation works correctly and provides helpful error messages
 */
class SchemaValidatorTest extends TestCase
{
    private SchemaValidator $validator;
    private string $testSchemaPath;

    protected function setUp(): void
    {
        // Use the actual schema path
        $this->testSchemaPath = __DIR__ . '/../../../resources/schemas';

        // Create temporary schema directory if needed for tests
        if (!is_dir($this->testSchemaPath)) {
            mkdir($this->testSchemaPath, 0755, true);
        }

        $this->validator = new SchemaValidator($this->testSchemaPath);
    }

    /** @test */
    public function it_validates_correct_progress_data(): void
    {
        $validData = [
            'by_file' => new \stdClass(),
            'by_function' => new \stdClass(),
            'global_stats' => [
                'total_functions' => 0,
                'completed_functions' => 0,
                'completion_percentage' => 0,
                'last_updated' => date('c')
            ]
        ];

        $result = $this->validator->validateProgressData($validData);

        $this->assertInstanceOf(ValidationResult::class, $result);
        $this->assertTrue($result->isValid(), 'Valid progress data should pass validation');
        $this->assertEmpty($result->getErrors(), 'Valid data should have no errors');
    }

    /** @test */
    public function it_rejects_missing_required_fields(): void
    {
        $invalidData = [
            'by_file' => new \stdClass()
            // Missing by_function and global_stats
        ];

        $result = $this->validator->validateProgressData($invalidData);

        $this->assertFalse($result->isValid(), 'Data missing required fields should fail validation');
        $this->assertNotEmpty($result->getErrors(), 'Should have error messages');

        $errorString = implode(' ', $result->getErrors());
        $this->assertStringContainsString('by_function', $errorString, 'Error should mention missing by_function');
    }

    /** @test */
    public function it_provides_human_readable_error_messages(): void
    {
        $invalidData = [
            'by_file' => [],  // Should be object, not array
            'by_function' => new \stdClass(),
            'global_stats' => []
        ];

        $result = $this->validator->validateProgressData($invalidData);
        $errors = $result->getErrors();

        $this->assertNotEmpty($errors, 'Should have errors');

        // Check for human-readable components
        $errorString = $errors[0];
        $this->assertStringContainsString('🔧', $errorString, 'Should have fix suggestions icon');
        $this->assertStringContainsString('cpm progress', $errorString, 'Should suggest cpm commands');
        $this->assertStringContainsString('repair', $errorString, 'Should mention repair command');
    }

    /** @test */
    public function it_handles_wrong_data_types(): void
    {
        $invalidData = [
            'by_file' => 'string',  // Should be object
            'by_function' => 123,    // Should be object
            'global_stats' => 'invalid'
        ];

        $result = $this->validator->validateProgressData($invalidData);

        $this->assertFalse($result->isValid(), 'Wrong data types should fail validation');
        $this->assertNotEmpty($result->getErrors());
    }

    /** @test */
    public function it_validates_empty_but_correct_structure(): void
    {
        $validData = [
            'by_file' => new \stdClass(),
            'by_function' => new \stdClass(),
            'global_stats' => [
                'total_functions' => 0,
                'completed_functions' => 0,
                'completion_percentage' => 0,
                'last_updated' => date('c')
            ]
        ];

        $result = $this->validator->validateProgressData($validData);

        $this->assertTrue($result->isValid(), 'Empty but correctly structured data should be valid');
    }

    /** @test */
    public function it_validates_progress_data_with_functions(): void
    {
        $validData = [
            'by_file' => (object)[
                'test.php' => [
                    'status' => 'in_progress',
                    'functions' => ['func1', 'func2']
                ]
            ],
            'by_function' => (object)[
                'func_123' => [
                    'file' => 'test.php',
                    'function' => 'func1',
                    'status' => 'completed',
                    'updated_at' => date('c')
                ]
            ],
            'global_stats' => [
                'total_functions' => 2,
                'completed_functions' => 1,
                'completion_percentage' => 50,
                'last_updated' => date('c')
            ]
        ];

        $result = $this->validator->validateProgressData($validData);

        $this->assertTrue($result->isValid(), 'Progress data with functions should be valid');
    }

    /** @test */
    public function it_extracts_expected_type_from_error_message(): void
    {
        $invalidData = [
            'by_file' => 'string',  // Wrong type
            'by_function' => new \stdClass(),
            'global_stats' => []
        ];

        $result = $this->validator->validateProgressData($invalidData);
        $errors = $result->getErrors();

        $this->assertNotEmpty($errors);

        $errorString = $errors[0];
        // Should explain what type is expected
        $this->assertMatchesRegularExpression('/Expected:.*object/i', $errorString,
            'Error should mention expected type');
    }

    /** @test */
    public function it_provides_fix_commands_in_errors(): void
    {
        $invalidData = [
            'by_file' => new \stdClass(),
            // Missing by_function
            'global_stats' => []
        ];

        $result = $this->validator->validateProgressData($invalidData);
        $errors = $result->getErrors();

        $this->assertNotEmpty($errors);

        $errorString = implode(' ', $errors);
        $this->assertStringContainsString('cpm progress validate', $errorString,
            'Should suggest validate command');
        $this->assertStringContainsString('cpm progress repair', $errorString,
            'Should suggest repair command');
        $this->assertStringContainsString('--force', $errorString,
            'Should mention force flag option');
    }

    /** @test */
    public function it_handles_invalid_global_stats_structure(): void
    {
        $invalidData = [
            'by_file' => new \stdClass(),
            'by_function' => new \stdClass(),
            'global_stats' => 'not an object or array'
        ];

        $result = $this->validator->validateProgressData($invalidData);

        $this->assertFalse($result->isValid());
    }

    /** @test */
    public function it_validates_project_data(): void
    {
        $validProjectData = [
            'project_id' => 'test_123',
            'name' => 'Test Project',
            'type' => 'php',
            'created_at' => date('c'),
            'updated_at' => date('c'),
            'root_path' => '/test/path',
            'config' => new \stdClass()
        ];

        $result = $this->validator->validateProjectData($validProjectData);

        $this->assertTrue($result->isValid(), 'Valid project data should pass validation');
    }

    /** @test */
    public function it_validates_inventory_data(): void
    {
        $validInventoryData = [
            'files' => [],
            'functions' => [],
            'classes' => [],
            'stats' => new \stdClass()
        ];

        $result = $this->validator->validateInventoryData($validInventoryData);

        $this->assertTrue($result->isValid(), 'Valid inventory data should pass validation');
    }

    /** @test */
    public function it_validates_tasks_data(): void
    {
        $validTasksData = [
            'active_tasks' => [],
            'completed_tasks' => [],
            'execution_plan' => new \stdClass()
        ];

        $result = $this->validator->validateTasksData($validTasksData);

        $this->assertTrue($result->isValid(), 'Valid tasks data should pass validation');
    }

    /** @test */
    public function it_validates_sessions_data(): void
    {
        $validSessionsData = [
            'current_session' => new \stdClass(),
            'session_history' => []
        ];

        $result = $this->validator->validateSessionsData($validSessionsData);

        $this->assertTrue($result->isValid(), 'Valid sessions data should pass validation');
    }
}
