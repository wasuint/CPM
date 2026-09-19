<?php

declare(strict_types=1);

namespace ClaudeProjectManager\Database;

/**
 * Represents the result of a schema validation operation
 */
class ValidationResult
{
    private bool $isValid;
    private array $errors;
    private array $warnings;

    /**
     * Initializes validation result
     *
     * @param bool $isValid Whether validation passed
     * @param array $errors List of validation errors
     * @param array $warnings List of validation warnings
     */
    public function __construct(bool $isValid, array $errors = [], array $warnings = [])
    {
        $this->isValid = $isValid;
        $this->errors = $errors;
        $this->warnings = $warnings;
    }

    /**
     * Check if validation was successful
     *
     * @return bool True if validation passed
     */
    public function isValid(): bool
    {
        return $this->isValid;
    }

    /**
     * Get validation errors
     *
     * @return array List of error messages
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * Get validation warnings
     *
     * @return array List of warning messages
     */
    public function getWarnings(): array
    {
        return $this->warnings;
    }

    /**
     * Check if there are any errors
     *
     * @return bool True if errors exist
     */
    public function hasErrors(): bool
    {
        return !empty($this->errors);
    }

    /**
     * Check if there are any warnings
     *
     * @return bool True if warnings exist
     */
    public function hasWarnings(): bool
    {
        return !empty($this->warnings);
    }

    /**
     * Get all messages (errors and warnings)
     *
     * @return array Combined list of errors and warnings
     */
    public function getAllMessages(): array
    {
        return array_merge($this->errors, $this->warnings);
    }

    /**
     * Add an error message
     *
     * @param string $error Error message to add
     * @return void
     */
    public function addError(string $error): void
    {
        $this->errors[] = $error;
        $this->isValid = false;
    }

    /**
     * Add a warning message
     *
     * @param string $warning Warning message to add
     * @return void
     */
    public function addWarning(string $warning): void
    {
        $this->warnings[] = $warning;
    }
}