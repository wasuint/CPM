<?php

declare(strict_types=1);

namespace ClaudeProjectManager;

use Symfony\Component\Filesystem\Filesystem;

/**
 * Handles installation and setup of the Claude Project Manager in target projects
 */
class Installer
{
    /** Mode for .cpm/ directories: owner and group only, no world access. */
    public const CPM_DIR_MODE = 0770;

    /** Mode for files under .cpm/: owner and group only, no world access. */
    public const CPM_FILE_MODE = 0660;

    private Filesystem $filesystem;

    public function __construct()
    {
        $this->filesystem = new Filesystem();
    }

    /**
     * Post-install hook: publish schemas/templates into the consumer project.
     */
    public static function postInstall(): void
    {
        $root = self::projectRoot();
        $srcSchemas = self::toolPath('resources/schemas');
        $dstSchemas = $root . '/.cpm/schemas';
        $srcTemplates = self::toolPath('templates');

        @mkdir($root . '/.cpm', self::CPM_DIR_MODE, true);
        @mkdir($dstSchemas, self::CPM_DIR_MODE, true);
        @mkdir($root . '/.cpm/logs', self::CPM_DIR_MODE, true);
        @mkdir($root . '/.cpm/backups', self::CPM_DIR_MODE, true);
        @mkdir($root . '/.cpm/config', self::CPM_DIR_MODE, true);

        // Publish schemas (do not overwrite)
        if (is_dir($srcSchemas)) {
            foreach (glob($srcSchemas . '/*.json') ?: [] as $file) {
                $base = basename($file);
                $dst  = $dstSchemas . '/' . $base;
                if (!file_exists($dst)) {
                    @copy($file, $dst);
                }
            }
        }

        // Publish templates (do not overwrite)
        if (is_dir($srcTemplates)) {
            $templateFiles = [
                'env.example' => '.cpm/.env.example',
                'rules.json.template' => '.cpm/rules.json.template',
                '.claude.md' => '.claude.md',
                'CLAUDE_AUTO.md' => 'CLAUDE_AUTO.md'
            ];

            foreach ($templateFiles as $srcFile => $dstPath) {
                $srcPath = $srcTemplates . '/' . $srcFile;
                $dstFullPath = $root . '/' . $dstPath;
                
                if (is_file($srcPath) && !file_exists($dstFullPath)) {
                    @copy($srcPath, $dstFullPath);
                    @chmod($dstFullPath, self::CPM_FILE_MODE);
                }
            }
        }

        // Seed .env (do not overwrite)
        $envFile = $root . '/.cpm/.env';
        if (!file_exists($envFile)) {
            $seed = "TELEGRAM_ENABLED=false\nNOTIFICATION_TIMEZONE=UTC\n";
            @file_put_contents($envFile, $seed);
            @chmod($envFile, self::CPM_FILE_MODE);
        }
    }

    /**
     * Executed after composer update - handles version migrations
     */
    public static function postUpdate(): void
    {
    }

    private static function projectRoot(): string
    {
        // If installed via composer, get CWD where composer ran.
        // For Direct Clone method, go up to project root
        $cwd = getcwd() ?: __DIR__;
        
        // If we're inside tools/claude-project-manager, go to project root
        if (strpos($cwd, '/tools/claude-project-manager') !== false) {
            $cwd = dirname(dirname($cwd));
        }
        
        return $cwd;
    }

    private static function toolPath(string $rel): string
    {
        // Find the tool root directory by looking for composer.json
        $dir = __DIR__;
        while ($dir !== '/' && !file_exists($dir . '/composer.json')) {
            $dir = dirname($dir);
        }
        return $dir . '/' . ltrim($rel, '/');
    }
}