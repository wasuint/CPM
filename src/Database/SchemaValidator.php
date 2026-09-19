<?php

declare(strict_types=1);

namespace ClaudeProjectManager\Database;

use JsonSchema\Validator;
use JsonSchema\Constraints\Constraint;
use ClaudeProjectManager\Database\ValidationResult;

/**
 * Validates JSON database files against defined schemas
 * Ensures data integrity and structure consistency
 */
class SchemaValidator
{
    private Validator $validator;
    private array $schemas;
    private string $schemaPath;

    /**
     * Initializes schema validator with schema definitions
     *
     * @param string $schemaPath Path to directory containing schema files
     */
    public function __construct(string $schemaPath)
    {
        $this->validator = new Validator();
        $this->schemaPath = rtrim($schemaPath, '/');
        $this->schemas = [];
    }

    /**
     * Validates data against appropriate schema for database file
     *
     * @param string $fileName Database file name (without extension)
     * @param array $data Data to validate
     * @return ValidationResult Validation results with any errors
     */
    public function validate(string $fileName, array $data): ValidationResult
    {
        try {
            // CRITICAL FIX: Reset validator state before each validation
            // The JsonSchema\Validator class is stateful and accumulates errors across validations
            // Without reset(), errors from previous validations leak into subsequent validations
            $this->validator->reset();

            $schema = $this->loadSchema($fileName);
            // Ensure proper typing before validation
            $normalizedData = $this->normalizeDataTypes($fileName, $data);
            $dataObject = json_decode(json_encode($normalizedData), false);

            $this->validator->validate($dataObject, $schema, Constraint::CHECK_MODE_COERCE_TYPES);

            if ($this->validator->isValid()) {
                return new ValidationResult(true);
            } else {
                $errors = $this->formatValidationErrors($this->validator->getErrors());
                return new ValidationResult(false, $errors);
            }
        } catch (\Exception $e) {
            return new ValidationResult(false, ["Schema validation failed: " . $e->getMessage()]);
        }
    }

    /**
     * Loads schema definition for specific database file
     *
     * @param string $fileName Database file name
     * @return object Schema definition object
     */
    private function loadSchema(string $fileName): object
    {
        if (isset($this->schemas[$fileName])) {
            return $this->schemas[$fileName];
        }

        $schemaFile = $this->schemaPath . '/' . $fileName . '.schema.json';

        if (!file_exists($schemaFile)) {
            // A project that has not published (or has an incomplete set of)
            // schemas still validates against the definitions shipped with the
            // package, rather than failing the whole write.
            $bundled = SchemaPathResolver::packageSchemaPath() . '/' . $fileName . '.schema.json';

            if (!file_exists($bundled)) {
                throw new \RuntimeException("Schema file not found: {$schemaFile}");
            }

            $schemaFile = $bundled;
        }
        
        $schemaContent = file_get_contents($schemaFile);
        if ($schemaContent === false) {
            throw new \RuntimeException("Cannot read schema file: {$schemaFile}");
        }
        
        $schema = json_decode($schemaContent);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException("Invalid JSON in schema file: {$schemaFile}");
        }
        
        $this->schemas[$fileName] = $schema;
        return $schema;
    }

    /**
     * Validates project.json structure and content
     *
     * @param array $data Project data to validate
     * @return ValidationResult Validation results
     */
    public function validateProjectData(array $data): ValidationResult
    {
        return $this->validate('project', $data);
    }

    /**
     * Validates inventory.json structure and content
     *
     * @param array $data Inventory data to validate
     * @return ValidationResult Validation results
     */
    public function validateInventoryData(array $data): ValidationResult
    {
        return $this->validate('inventory', $data);
    }

    /**
     * Validates tasks.json structure and content
     *
     * @param array $data Tasks data to validate
     * @return ValidationResult Validation results
     */
    public function validateTasksData(array $data): ValidationResult
    {
        return $this->validate('tasks', $data);
    }

    /**
     * Validates progress.json structure and content
     *
     * @param array $data Progress data to validate
     * @return ValidationResult Validation results
     */
    public function validateProgressData(array $data): ValidationResult
    {
        return $this->validate('progress', $data);
    }

    /**
     * Validates sessions.json structure and content
     *
     * @param array $data Sessions data to validate
     * @return ValidationResult Validation results
     */
    public function validateSessionsData(array $data): ValidationResult
    {
        return $this->validate('sessions', $data);
    }

    /**
     * Creates default schema definitions if they don't exist
     *
     * @return void
     */
    public function createDefaultSchemas(): void
    {
        if (!is_dir($this->schemaPath)) {
            mkdir($this->schemaPath, 0755, true);
        }
        
        $schemas = [
            'project' => [
                'type' => 'object',
                'required' => ['project_id', 'name', 'type', 'created_at'],
                'properties' => [
                    'project_id' => ['type' => 'string'],
                    'name' => ['type' => 'string'],
                    'type' => ['type' => 'string', 'enum' => ['php', 'python', 'mixed']],
                    'created_at' => ['type' => 'string', 'format' => 'date-time'],
                    'updated_at' => ['type' => 'string', 'format' => 'date-time'],
                    'root_path' => ['type' => 'string'],
                    'config' => ['type' => 'object']
                ]
            ],
            'inventory' => [
                'type' => 'object',
                'required' => ['files', 'functions', 'classes', 'stats'],
                'properties' => [
                    'files' => ['type' => 'array'],
                    'functions' => ['type' => 'array'],
                    'classes' => ['type' => 'array'],
                    'stats' => ['type' => 'object']
                ]
            ],
            'tasks' => [
                'type' => 'object',
                'required' => ['active_tasks', 'completed_tasks', 'execution_plan'],
                'properties' => [
                    'active_tasks' => ['type' => 'array'],
                    'completed_tasks' => ['type' => 'array'],
                    'execution_plan' => ['type' => 'object']
                ]
            ],
            'progress' => [
                'type' => 'object',
                'required' => ['by_file', 'by_function', 'global_stats'],
                'properties' => [
                    'by_file' => ['type' => 'object'],
                    'by_function' => ['type' => 'object'],
                    'global_stats' => ['type' => 'object']
                ]
            ],
            'sessions' => [
                'type' => 'object',
                'required' => ['current_session', 'session_history'],
                'properties' => [
                    'current_session' => ['type' => 'object'],
                    'session_history' => ['type' => 'array']
                ]
            ]
        ];
        
        foreach ($schemas as $name => $schema) {
            $schemaFile = $this->schemaPath . '/' . $name . '.schema.json';
            if (!file_exists($schemaFile)) {
                file_put_contents($schemaFile, json_encode($schema));
            }
        }
    }

    /**
     * Formats validation errors into readable messages with helpful guidance
     *
     * @param array $errors Raw validation errors
     * @return array Formatted error messages with context and solutions
     */
    private function formatValidationErrors(array $errors): array
    {
        $formatted = [];

        foreach ($errors as $error) {
            $property = $error['property'] ?? 'root';
            $message = $error['message'] ?? 'Unknown validation error';
            $constraint = $error['constraint'] ?? '';

            // Build human-readable error with context
            $humanMessage = $this->makeErrorHumanReadable($property, $message, $constraint);
            $formatted[] = $humanMessage;
        }

        return $formatted;
    }

    /**
     * Converts technical validation error into human-readable message with solutions
     *
     * @param string $property Property path that failed validation
     * @param string $message Error message from validator
     * @param string $constraint Constraint type that failed
     * @return string Human-readable error with suggested fixes
     */
    private function makeErrorHumanReadable(string $property, string $message, string $constraint): string
    {
        $fixes = [];
        $context = '';

        // Analyze error type and provide specific guidance
        switch ($constraint) {
            case 'required':
                $context = "Required field '$property' is missing from the data.";
                $fixes[] = "Run: cpm progress reset to reinitialize the data structure";
                $fixes[] = "Or run: cpm init --force to reset the entire database";
                break;

            case 'type':
                $context = "Field '$property' has wrong data type. Expected: " . $this->extractExpectedType($message);
                $fixes[] = "Run: cpm progress validate to check your data structure";
                $fixes[] = "Run: cpm progress repair --auto-fix to attempt automatic repair";
                break;

            case 'enum':
                $context = "Field '$property' contains invalid value. " . ucfirst($message);
                $fixes[] = "Valid values are listed in the error above";
                $fixes[] = "Run: cpm progress reset '$property' to fix this field";
                break;

            case 'format':
                $context = "Field '$property' has invalid format. " . ucfirst($message);
                $fixes[] = "Check date/time format (should be ISO 8601: YYYY-MM-DDTHH:MM:SSZ)";
                $fixes[] = "Run: cpm progress repair --auto-fix to fix formatting issues";
                break;

            default:
                $context = "Validation error in '$property': $message";
                $fixes[] = "Run: cpm progress validate --verbose for detailed diagnostics";
                $fixes[] = "Run: cpm progress repair --auto-fix to attempt automatic repair";
                break;
        }

        // Build comprehensive error message
        $output = "\n❌ Schema Validation Error\n";
        $output .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
        $output .= "Property: $property\n";
        $output .= "Issue: $context\n";
        $output .= "Constraint: $constraint\n\n";
        $output .= "🔧 Suggested Fixes:\n";
        foreach ($fixes as $i => $fix) {
            $output .= "  " . ($i + 1) . ". $fix\n";
        }
        $output .= "\n💡 Quick Workaround:\n";
        $output .= "  Use --force flag to bypass validation (not recommended)\n";
        $output .= "  Example: cpm progress mark-completed <file> <function> --force\n\n";
        $output .= "📚 Need Help?\n";
        $output .= "  • Run: cpm progress validate --verbose for detailed diagnostics\n";
        $output .= "  • See: CPM_TROUBLESHOOTING.md for common issues\n";
        $output .= "  • Report bug: https://github.com/wasuint/CPM/issues\n";

        return $output;
    }

    /**
     * Extracts expected type from validation error message
     *
     * @param string $message Error message
     * @return string Expected type
     */
    private function extractExpectedType(string $message): string
    {
        // Try to extract expected type from message like "String value found, but an object is required"
        if (preg_match('/but (?:a |an )?(\w+) is required/i', $message, $matches)) {
            return $matches[1];
        }

        // Try to extract from message like "The property must be an object"
        if (preg_match('/must be (?:a |an )?(\w+)/i', $message, $matches)) {
            return $matches[1];
        }

        return 'see error message';
    }

    /**
     * Checks if data structure matches expected format
     *
     * @param array $data Data to check
     * @param array $expectedStructure Expected structure definition
     * @return bool True if structure matches
     */
    private function checkStructure(array $data, array $expectedStructure): bool
    {
        foreach ($expectedStructure as $key => $expected) {
            if (!array_key_exists($key, $data)) {
                return false;
            }
            
            if (is_array($expected) && is_array($data[$key])) {
                if (!$this->checkStructure($data[$key], $expected)) {
                    return false;
                }
            }
        }
        
        return true;
    }

    /**
     * Validates data types and format constraints
     *
     * @param mixed $value Value to validate
     * @param array $constraints Type and format constraints
     * @return bool True if value meets constraints
     */
    private function validateConstraints($value, array $constraints): bool
    {
        if (isset($constraints['type'])) {
            $expectedType = $constraints['type'];
            $actualType = gettype($value);
            
            if ($expectedType === 'string' && !is_string($value)) {
                return false;
            }
            if ($expectedType === 'integer' && !is_int($value)) {
                return false;
            }
            if ($expectedType === 'array' && !is_array($value)) {
                return false;
            }
            if ($expectedType === 'object' && !is_object($value) && !is_array($value)) {
                return false;
            }
        }
        
        if (isset($constraints['enum']) && !in_array($value, $constraints['enum'], true)) {
            return false;
        }
        
        if (isset($constraints['format']) && is_string($value)) {
            switch ($constraints['format']) {
                case 'date-time':
                    return strtotime($value) !== false;
                case 'email':
                    return filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
            }
        }
        
        return true;
    }

    /**
     * Normalize data types to ensure proper JSON schema validation
     * 
     * @param string $fileName Schema file name
     * @param array $data Data to normalize
     * @return array Normalized data
     */
    private function normalizeDataTypes(string $fileName, array $data): array
    {
        switch ($fileName) {
            case 'sessions':
                // Ensure current_session is always an object, never an array
                if (isset($data['current_session'])) {
                    if (is_array($data['current_session']) && empty($data['current_session'])) {
                        $data['current_session'] = new \stdClass();
                    } elseif (is_array($data['current_session'])) {
                        $data['current_session'] = (object) $data['current_session'];
                    }
                }
                // Ensure session_history is always an array
                if (isset($data['session_history']) && !is_array($data['session_history'])) {
                    $data['session_history'] = [];
                }
                break;
        }
        
        return $data;
    }
}