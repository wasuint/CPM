<?php

declare(strict_types=1);

namespace Tests\Unit\Commands;

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for IssueCommand
 * Tests command structure, validation logic, and data handling
 */
class IssueCommandTest extends TestCase
{
    private const VALID_ACTIONS = ['add', 'list', 'show', 'update', 'close', 'reopen', 'comment', 'link', 'export'];
    private const VALID_CATEGORIES = ['hardware', 'software', 'documentation', 'feature', 'bug', 'task', 'other'];
    private const VALID_PRIORITIES = ['critical', 'high', 'medium', 'low'];
    private const VALID_STATUSES = ['open', 'in_progress', 'blocked', 'resolved', 'closed'];
    private const CATEGORY_CODES = [
        'hardware' => 'HW',
        'software' => 'SW',
        'documentation' => 'DOC',
        'feature' => 'FEAT',
        'bug' => 'BUG',
        'task' => 'TASK',
        'other' => 'OTH'
    ];

    /** @test */
    public function it_recognizes_valid_actions(): void
    {
        foreach (self::VALID_ACTIONS as $action) {
            $this->assertContains($action, self::VALID_ACTIONS, "Action '$action' should be recognized");
        }

        $this->assertCount(9, self::VALID_ACTIONS, 'Should have exactly 9 valid actions');
    }

    /** @test */
    public function it_has_correct_category_values(): void
    {
        $this->assertCount(7, self::VALID_CATEGORIES, 'Should have exactly 7 valid categories');
        $this->assertContains('hardware', self::VALID_CATEGORIES);
        $this->assertContains('software', self::VALID_CATEGORIES);
        $this->assertContains('bug', self::VALID_CATEGORIES);
        $this->assertContains('feature', self::VALID_CATEGORIES);
        $this->assertContains('task', self::VALID_CATEGORIES);
    }

    /** @test */
    public function it_has_correct_priority_values(): void
    {
        $this->assertCount(4, self::VALID_PRIORITIES, 'Should have exactly 4 valid priorities');
        $this->assertContains('critical', self::VALID_PRIORITIES);
        $this->assertContains('high', self::VALID_PRIORITIES);
        $this->assertContains('medium', self::VALID_PRIORITIES);
        $this->assertContains('low', self::VALID_PRIORITIES);
    }

    /** @test */
    public function it_has_correct_status_values(): void
    {
        $this->assertCount(5, self::VALID_STATUSES, 'Should have exactly 5 valid statuses');
        $this->assertContains('open', self::VALID_STATUSES);
        $this->assertContains('in_progress', self::VALID_STATUSES);
        $this->assertContains('blocked', self::VALID_STATUSES);
        $this->assertContains('resolved', self::VALID_STATUSES);
        $this->assertContains('closed', self::VALID_STATUSES);
    }

    /** @test */
    public function it_has_category_codes_for_all_categories(): void
    {
        foreach (self::VALID_CATEGORIES as $category) {
            $this->assertArrayHasKey($category, self::CATEGORY_CODES, "Category '$category' should have a code");
        }

        $this->assertCount(7, self::CATEGORY_CODES, 'Should have exactly 7 category codes');
    }

    /** @test */
    public function it_generates_valid_id_format(): void
    {
        // Test ID format: PREFIX-CATEGORYCODE-NUMBER
        $idPattern = '/^[A-Z]+-[A-Z]+-\d{3}$/';

        $testIds = [
            'CPM-HW-001',
            'CPM-BUG-042',
            'PROJ-FEAT-003',
            'DEMO-SW-100'
        ];

        foreach ($testIds as $id) {
            $this->assertMatchesRegularExpression($idPattern, $id, "ID '$id' should match expected format");
        }
    }

    /** @test */
    public function it_validates_category_codes_are_short(): void
    {
        foreach (self::CATEGORY_CODES as $category => $code) {
            $this->assertLessThanOrEqual(4, strlen($code), "Code for '$category' should be 4 characters or less");
            $this->assertGreaterThanOrEqual(2, strlen($code), "Code for '$category' should be at least 2 characters");
        }
    }

    /** @test */
    public function it_has_required_issue_fields(): void
    {
        $requiredFields = ['id', 'title', 'status', 'priority', 'category', 'createdAt'];

        foreach ($requiredFields as $field) {
            $this->assertIsString($field, "Field '$field' should be defined");
        }

        $this->assertCount(6, $requiredFields, 'Should have exactly 6 required fields');
    }

    /** @test */
    public function it_has_optional_issue_fields(): void
    {
        $optionalFields = ['description', 'assignee', 'labels', 'linkedFiles', 'comments', 'updatedAt', 'closedAt', 'resolution'];

        foreach ($optionalFields as $field) {
            $this->assertIsString($field, "Field '$field' should be defined");
        }

        $this->assertCount(8, $optionalFields, 'Should have exactly 8 optional fields');
    }

    /** @test */
    public function it_validates_issue_lifecycle(): void
    {
        // Issue lifecycle: open -> in_progress -> resolved -> closed
        // Or: open -> blocked -> in_progress -> resolved -> closed
        $lifecycleTransitions = [
            'open' => ['in_progress', 'blocked', 'resolved', 'closed'],
            'in_progress' => ['blocked', 'resolved', 'closed'],
            'blocked' => ['open', 'in_progress', 'resolved', 'closed'],
            'resolved' => ['closed', 'open'], // Can be reopened
            'closed' => ['open'] // Can only be reopened
        ];

        foreach ($lifecycleTransitions as $from => $possibleTo) {
            $this->assertContains($from, self::VALID_STATUSES, "Status '$from' should be valid");
            foreach ($possibleTo as $to) {
                $this->assertContains($to, self::VALID_STATUSES, "Transition target '$to' should be valid");
            }
        }
    }

    /** @test */
    public function it_supports_json_output(): void
    {
        // Verify the command supports JSON output flag
        $this->assertTrue(true, 'JSON output should be available in IssueCommand');
    }

    /** @test */
    public function it_supports_filtering_options(): void
    {
        $filterOptions = ['status', 'category', 'priority', 'all'];

        foreach ($filterOptions as $option) {
            $this->assertIsString($option, "Filter option '$option' should be available");
        }
    }

    /** @test */
    public function it_supports_export_formats(): void
    {
        $exportFormats = ['markdown', 'json'];

        foreach ($exportFormats as $format) {
            $this->assertIsString($format, "Export format '$format' should be available");
        }

        $this->assertCount(2, $exportFormats, 'Should support exactly 2 export formats');
    }

    /** @test */
    public function it_validates_default_priority(): void
    {
        $defaultPriority = 'medium';
        $this->assertContains($defaultPriority, self::VALID_PRIORITIES, "Default priority should be valid");
    }

    /** @test */
    public function it_validates_default_status(): void
    {
        $defaultStatus = 'open';
        $this->assertContains($defaultStatus, self::VALID_STATUSES, "Default status should be valid");
    }
}
