<?php

declare(strict_types=1);

namespace ClaudeProjectManager\Database;

/**
 * Thrown when a database file does not exist.
 *
 * Distinguished from a generic failure so that callers can treat "this project
 * has not been initialised yet" as an ordinary state rather than an error.
 */
class DatabaseFileNotFoundException extends \RuntimeException
{
}
