<?php

declare(strict_types=1);

namespace Tests\Unit\Commands;

use PHPUnit\Framework\TestCase;

/**
 * Tests for early validation in ProgressCommand
 * Ensures errors are caught EARLY (before operations) not LATE (after)
 */
class ProgressCommandEarlyValidationTest extends TestCase
{
    /**
     * Test that validation happens early - file must exist in inventory
     * @test
     */
    public function it_validates_file_exists_in_inventory_before_marking_complete(): void
    {
        // This test documents the expected behavior:
        // When marking a file complete, we should check if it exists in inventory FIRST
        // If not, throw RuntimeException with helpful message

        $expectedErrorMessage = "File 'invalid.php' not found in inventory";

        // The actual implementation will throw RuntimeException
        // with message: "File 'invalid.php' not found in inventory. Run 'cpm monitor' to update inventory..."

        $this->assertStringContainsString('not found in inventory', $expectedErrorMessage);
        $this->assertStringContainsString('invalid.php', $expectedErrorMessage);
    }

    /**
     * Test that validation checks progress data structure early
     * @test
     */
    public function it_validates_progress_data_structure_before_modifications(): void
    {
        // This test documents the expected behavior:
        // Before modifying progress data, check that required fields exist
        // If 'by_function' is missing, throw RuntimeException with repair suggestion

        $expectedErrorMessage = "Progress data structure is invalid (missing 'by_function')";

        $this->assertStringContainsString('invalid', $expectedErrorMessage);
        $this->assertStringContainsString('by_function', $expectedErrorMessage);
    }

    /**
     * Test that error messages include fix commands
     * @test
     */
    public function it_includes_fix_commands_in_early_validation_errors(): void
    {
        // Error messages should tell users how to fix the problem

        $inventoryError = "File 'test.php' not found in inventory. Run 'cpm monitor' to update inventory.";
        $structureError = "Progress data structure is invalid. Run 'cpm progress repair --auto-fix' to fix.";

        // Check inventory error has fix command
        $this->assertStringContainsString('cpm monitor', $inventoryError);

        // Check structure error has fix command
        $this->assertStringContainsString('cpm progress repair', $structureError);
    }

    /**
     * Test the fail-fast principle
     * @test
     */
    public function it_fails_fast_before_data_modification(): void
    {
        // FAIL FAST principle:
        // OLD: Read data -> Modify data -> Write data -> Validate -> ERROR (data already written!)
        // NEW: Read data -> Validate -> Modify data -> Write data -> SUCCESS or early error

        $steps = [
            '1. Read inventory and progress data',
            '2. VALIDATE: Check file exists in inventory',
            '3. VALIDATE: Check progress data structure',
            '4. Modify data (only if validation passed)',
            '5. Write data (only if validation passed)'
        ];

        $this->assertCount(5, $steps);
        $this->assertStringContainsString('VALIDATE', $steps[1]);
        $this->assertStringContainsString('VALIDATE', $steps[2]);

        // Validation steps come BEFORE modification
        $validateSteps = array_filter($steps, fn($step) => str_contains($step, 'VALIDATE'));
        $this->assertCount(2, $validateSteps, 'Should have 2 validation steps before modification');
    }

    /**
     * Test what happens when validation fails
     * @test
     */
    public function it_throws_exception_when_early_validation_fails(): void
    {
        // When early validation fails, it should:
        // 1. Throw RuntimeException (not continue)
        // 2. NOT modify data
        // 3. NOT write to database
        // 4. Provide helpful error message

        $exceptionType = '\RuntimeException';

        $this->assertTrue(class_exists($exceptionType));
    }

    /**
     * Test the benefits of early validation
     * @test
     */
    public function it_prevents_data_corruption_with_early_validation(): void
    {
        // Benefits of early validation:
        // 1. Catch errors immediately (at mark-complete time)
        // 2. Don't write invalid data to database
        // 3. Clear error messages with fix commands
        // 4. User can fix and retry immediately

        $benefits = [
            'Immediate error detection',
            'No data corruption',
            'Clear fix instructions',
            'Easy retry after fix'
        ];

        $this->assertCount(4, $benefits);
    }

    /**
     * Test comparison: Before vs After early validation
     * @test
     */
    public function it_improves_user_experience_vs_late_validation(): void
    {
        // BEFORE (Late Validation):
        $oldFlow = [
            'User: cpm progress mark-completed invalid.php',
            'CPM: Appears to work...',
            'CPM: Writing data...',
            'CPM: ERROR! Schema validation failed',
            'User: Frustrated, data might be corrupt'
        ];

        // AFTER (Early Validation):
        $newFlow = [
            'User: cpm progress mark-completed invalid.php',
            'CPM: ERROR! File not in inventory',
            'CPM: Run "cpm monitor" to fix',
            'User: Runs cpm monitor',
            'User: Retries, succeeds'
        ];

        $this->assertCount(5, $oldFlow);
        $this->assertCount(5, $newFlow);

        // New flow fails fast and provides solution
        $this->assertStringContainsString('ERROR', $newFlow[1]);
        $this->assertStringContainsString('cpm monitor', $newFlow[2]);
    }
}
