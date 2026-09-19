# CPM Test Suite

Comprehensive tests for Claude Project Manager to ensure reliability and prevent regressions.

## Running Tests

```bash
# Run all tests
./vendor/bin/phpunit

# Run specific test suite
./vendor/bin/phpunit --testsuite Unit
./vendor/bin/phpunit --testsuite Integration

# Run specific test file
./vendor/bin/phpunit tests/Unit/Database/SchemaValidatorTest.php

# Run with verbose output
./vendor/bin/phpunit --verbose

# Run specific test method
./vendor/bin/phpunit --filter it_validates_correct_progress_data
```

## Test Structure

```
tests/
├── Unit/              # Unit tests (test individual components)
│   ├── Database/      # Database layer tests
│   │   └── SchemaValidatorTest.php
│   ├── Commands/      # Command tests
│   │   ├── ProgressCommandBasicTest.php
│   │   └── ProgressCommandEarlyValidationTest.php
│   └── Services/      # Service layer tests
│       ├── LoggerTest.php
│       └── ConfigManagerTest.php
└── Integration/       # Integration tests (test full workflows)
    └── (to be added)
```

## Test Coverage

Current coverage:
- **SchemaValidator**: 13 tests - Full coverage of validation logic
- **ProgressCommand (Basic)**: 4 tests - Basic structure tests
- **ProgressCommand (Early Validation)**: 7 tests - Fail-fast validation tests
- **Logger (PSR-3)**: 10 tests - Complete logging functionality
- **ConfigManager**: 13 tests - Configuration management with TDD
- **Total**: 47 tests, 115 assertions

Goals:
- Critical paths: 100%
- Normal paths: 80%+
- Edge cases: 60%+

## Writing Tests

### Unit Test Example

```php
<?php

namespace Tests\Unit\Database;

use PHPUnit\Framework\TestCase;

class YourTest extends TestCase
{
    /** @test */
    public function it_does_something(): void
    {
        $result = yourFunction();

        $this->assertTrue($result);
        $this->assertEquals('expected', $actual);
    }
}
```

### Running Your Test

```bash
./vendor/bin/phpunit tests/Unit/Database/YourTest.php
```

## CI/CD Integration

Tests can be run in CI/CD pipelines:

```yaml
# .github/workflows/tests.yml
- name: Run Tests
  run: ./vendor/bin/phpunit --coverage-text
```

## Test Philosophy

1. **Test behavior, not implementation**
2. **Keep tests simple and readable**
3. **One assertion per test when possible**
4. **Use descriptive test names** (it_validates_correct_data)
5. **Test edge cases and error conditions**

## Troubleshooting

**Tests not running?**
```bash
# Check PHP version
php --version  # Should be 8.1+

# Check PHPUnit installation
./vendor/bin/phpunit --version

# Reinstall dependencies
composer install
```

**Test failures?**
- Read the error message carefully
- Check if your changes broke existing functionality
- Update tests if behavior intentionally changed
- Add new tests for new features

---

**Status:** 47 tests passing ✅ (115 assertions)