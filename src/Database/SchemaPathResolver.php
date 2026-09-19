<?php

declare(strict_types=1);

namespace ClaudeProjectManager\Database;

/**
 * Resolves the directory that holds the JSON schema definitions.
 *
 * Schemas are published into the consumer project at `.cpm/schemas` (see
 * StartCommand::publishSchemas). Callers only know the database directory
 * (`.cpm/db`), so the published directory is its sibling. When a project has
 * not published schemas yet, the package's own `resources/schemas` is used.
 */
final class SchemaPathResolver
{
    /**
     * @param string $databasePath Path to the `.cpm/db` directory
     * @return string Directory containing the schema files
     */
    public static function fromDatabasePath(string $databasePath): string
    {
        $published = rtrim(dirname(rtrim($databasePath, '/')), '/') . '/schemas';

        if (is_dir($published)) {
            return $published;
        }

        $bundled = self::packageSchemaPath();

        return is_dir($bundled) ? $bundled : $published;
    }

    /**
     * Directory of the schema files shipped with this package.
     */
    public static function packageSchemaPath(): string
    {
        return dirname(__DIR__, 2) . '/resources/schemas';
    }
}
