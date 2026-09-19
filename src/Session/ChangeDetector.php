<?php

declare(strict_types=1);

namespace ClaudeProjectManager\Session;

use ClaudeProjectManager\DatabaseManager;
use ClaudeProjectManager\ProjectAnalyzer;
use ClaudeProjectManager\ConfigManager;

/**
 * Detects external changes to project files since last session
 */
class ChangeDetector
{
    private DatabaseManager $database;
    private ProjectAnalyzer $analyzer;
    private ConfigManager $config;

    /**
     * Initializes change detector
     *
     * @param DatabaseManager $database Database manager for comparison data
     * @param ProjectAnalyzer $analyzer Project analyzer for re-analysis
     */
    public function __construct(DatabaseManager $database, ProjectAnalyzer $analyzer)
    {
        $this->database = $database;
        $this->analyzer = $analyzer;
        $this->config = new ConfigManager(getcwd());
    }

    /**
     * Detects changes since last session
     *
     * @param string $projectRoot Project root directory
     * @return array Change detection results
     */
    public function detectChanges(string $projectRoot): array
    {
        $changes = [
            'modified_files' => [],
            'added_files' => [],
            'deleted_files' => [],
            'has_changes' => false,
            'requires_reanalysis' => false
        ];

        try {
            $lastInventory = $this->database->read('inventory');
            $lastAnalysisTime = strtotime($lastInventory['stats']['analysis_completed_at'] ?? 'now');

            // Get max scan depth from config (0 = unlimited, default = 0 for backward compatibility)
            $maxScanDepth = (int)$this->config->get('rules.analysis.max_scan_depth', 0);

            $currentFiles = $this->scanCurrentFiles($projectRoot, $maxScanDepth);
            $lastFiles = $this->getLastFileStates($lastInventory);

            // Detect modified files
            foreach ($currentFiles as $filePath => $fileInfo) {
                if (isset($lastFiles[$filePath])) {
                    if ($fileInfo['modified'] > $lastAnalysisTime) {
                        $changes['modified_files'][] = $filePath;
                        $changes['has_changes'] = true;
                        $changes['requires_reanalysis'] = true;
                    }
                } else {
                    $changes['added_files'][] = $filePath;
                    $changes['has_changes'] = true;
                    $changes['requires_reanalysis'] = true;
                }
            }

            // Detect deleted files
            foreach ($lastFiles as $filePath => $fileInfo) {
                if (!isset($currentFiles[$filePath])) {
                    $changes['deleted_files'][] = $filePath;
                    $changes['has_changes'] = true;
                    $changes['requires_reanalysis'] = true;
                }
            }

        } catch (\Exception $e) {
            error_log("Change detection failed: " . $e->getMessage());
            $changes['error'] = $e->getMessage();
        }

        return $changes;
    }

    /**
     * Performs incremental re-analysis for changed files
     *
     * @param array $changedFiles List of changed file paths
     * @param callable|null $progressCallback Optional progress callback
     * @param int $timeoutSeconds Maximum time to spend on reanalysis (0 = no timeout)
     * @return array Re-analysis results
     */
    public function performIncrementalReanalysis(array $changedFiles, ?callable $progressCallback = null, int $timeoutSeconds = 300): array
    {
        $startTime = time();
        $total = count($changedFiles);
        $results = [
            'reanalyzed_files' => 0,
            'updated_functions' => 0,
            'errors' => [],
            'total_files' => $total,
            'timeout_occurred' => false,
            'processing_time' => 0
        ];

        // If too many files, provide option to skip detailed analysis
        if ($total > 500) {
            error_log("Very large number of files to reanalyze ({$total}). This will be limited to prevent hanging.");
            $changedFiles = array_slice($changedFiles, 0, 500);
            $total = 500;
            $results['total_files'] = $total;
            $results['files_skipped'] = count($changedFiles) - $total;
        } elseif ($total > 100) {
            error_log("Large number of files to reanalyze ({$total}). Consider using --force to skip incremental analysis.");
        }

        foreach ($changedFiles as $index => $filePath) {
            // Check timeout
            if ($timeoutSeconds > 0 && (time() - $startTime) >= $timeoutSeconds) {
                $results['timeout_occurred'] = true;
                $results['errors'][] = "Analysis timeout after {$timeoutSeconds} seconds. Processed {$results['reanalyzed_files']}/{$total} files.";
                break;
            }

            try {
                $this->analyzer->incrementalAnalysis([$filePath]);
                $results['reanalyzed_files']++;
                
                // Call progress callback if provided
                if ($progressCallback) {
                    $progressCallback($index + 1, $total, $filePath);
                }
                
            } catch (\Exception $e) {
                $results['errors'][] = "Failed to re-analyze {$filePath}: " . $e->getMessage();
            }
        }

        $results['processing_time'] = time() - $startTime;
        return $results;
    }

    /**
     * Gets summary of changes for context building
     *
     * @param array $changes Change detection results
     * @return string Human-readable changes summary
     */
    public function getChangesSummary(array $changes): string
    {
        if (!$changes['has_changes']) {
            return "✅ No external changes detected since last session - project is current.";
        }

        $totalChanges = count($changes['modified_files'] ?? []) + 
                       count($changes['added_files'] ?? []) + 
                       count($changes['deleted_files'] ?? []);

        $summary = "📊 Detected {$totalChanges} changes since last session:\n";

        if (!empty($changes['modified_files'])) {
            $count = count($changes['modified_files']);
            $summary .= "  🔄 {$count} modified file" . ($count !== 1 ? 's' : '') . "\n";
        }

        if (!empty($changes['added_files'])) {
            $count = count($changes['added_files']);
            $summary .= "  ➕ {$count} new file" . ($count !== 1 ? 's' : '') . "\n";
        }

        if (!empty($changes['deleted_files'])) {
            $count = count($changes['deleted_files']);
            $summary .= "  ➖ {$count} deleted file" . ($count !== 1 ? 's' : '') . "\n";
        }

        if ($changes['requires_reanalysis']) {
            $summary .= "\n🔄 Function inventory will be updated to reflect these changes.";
        } else {
            $summary .= "\n💡 Changes detected but no function-level updates required.";
        }

        return $summary;
    }

    /**
     * Scans current project files
     *
     * @param string $projectRoot Project root directory
     * @param int $maxDepth Maximum directory depth to scan (0 = unlimited)
     * @return array Current file states
     */
    private function scanCurrentFiles(string $projectRoot, int $maxDepth = 0): array
    {
        $files = [];
        $extensions = ['php', 'py', 'js', 'ts'];

        try {
            $dirIterator = new \RecursiveDirectoryIterator(
                $projectRoot,
                \RecursiveDirectoryIterator::SKIP_DOTS | \RecursiveDirectoryIterator::FOLLOW_SYMLINKS
            );
            $iterator = new \RecursiveIteratorIterator(
                $dirIterator,
                \RecursiveIteratorIterator::SELF_FIRST,
                \RecursiveIteratorIterator::CATCH_GET_CHILD
            );

            // Apply depth limit if specified (0 = unlimited)
            if ($maxDepth > 0) {
                $iterator->setMaxDepth($maxDepth);
            }

            foreach ($iterator as $file) {
                try {
                    // Skip if we can't access the file/directory
                    if (!$file->isReadable()) {
                        continue;
                    }

                    if ($file->isFile() && in_array($file->getExtension(), $extensions)) {
                        $relativePath = str_replace($projectRoot . '/', '', $file->getPathname());

                        // Skip excluded directories
                        if ($this->shouldSkipFile($relativePath)) {
                            continue;
                        }

                        $files[$relativePath] = [
                            'size' => $file->getSize(),
                            'modified' => $file->getMTime()
                        ];
                    }
                } catch (\UnexpectedValueException $e) {
                    // Permission denied or other access errors - skip this file/directory silently
                    continue;
                } catch (\RuntimeException $e) {
                    // Other runtime errors - skip this file/directory silently
                    continue;
                }
            }
        } catch (\UnexpectedValueException $e) {
            // Root directory permission error - log and return empty array
            error_log("Error scanning directory: " . $e->getMessage());
            return [];
        } catch (\RuntimeException $e) {
            // Other runtime errors at root level
            error_log("Error scanning directory: " . $e->getMessage());
            return [];
        }

        return $files;
    }

    /**
     * Gets last file states from inventory
     *
     * @param array $inventory Last inventory data
     * @return array Last file states
     */
    private function getLastFileStates(array $inventory): array
    {
        $files = [];
        
        foreach ($inventory['files'] ?? [] as $file) {
            $files[$file['path']] = [
                'size' => $file['size'] ?? 0,
                'modified' => $file['modified'] ?? 0
            ];
        }

        return $files;
    }

    /**
     * Checks if file should be skipped during scanning
     *
     * @param string $relativePath Relative file path
     * @return bool True if file should be skipped
     */
    private function shouldSkipFile(string $relativePath): bool
    {
        // First check basic exclusion patterns
        $exclusionPatterns = $this->config->getExclusionPatterns();
        foreach ($exclusionPatterns as $pattern) {
            $cleanPattern = preg_replace('#/\*\*/\*$#', '', $pattern);

            // Simple patterns like *.min.js
            if (!str_contains($cleanPattern, '/') && str_contains($cleanPattern, '*')) {
                if (fnmatch($cleanPattern, basename($relativePath))) {
                    return true;
                }
            }
            // Directory patterns
            elseif (!str_contains($cleanPattern, '*')) {
                if (str_starts_with($relativePath, $cleanPattern . '/') || $relativePath === $cleanPattern) {
                    return true;
                }
            }
        }

        // Check for vendor/node_modules at any level
        $pathParts = explode('/', $relativePath);
        foreach ($pathParts as $part) {
            if (in_array($part, ['vendor', 'node_modules', '.git', 'cache', 'uploads', 'backups'])) {
                return true;
            }
        }

        // WordPress-specific filtering
        $wpConfig = $this->config->get('wordpress', []);
        if ($wpConfig['detect_wordpress'] ?? true) {
            if ($this->isWordPressFile($relativePath)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if file is a WordPress core file that should be excluded
     *
     * @param string $relativePath The relative path of the file
     * @return bool True if it's a WordPress core file to exclude
     */
    private function isWordPressFile(string $relativePath): bool
    {
        $wpConfig = $this->config->get('wordpress', []);
        $projectRoot = getcwd();

        // Check if path contains wp-config.php indicating WordPress site
        $pathParts = explode('/', $relativePath);
        $isInWordPressDir = false;
        $wpRootDepth = -1;

        // Find if we're in a WordPress directory
        for ($i = 0; $i < count($pathParts) - 1; $i++) {
            $checkPath = implode('/', array_slice($pathParts, 0, $i + 1));
            if (file_exists($projectRoot . '/' . $checkPath . '/wp-config.php')) {
                $isInWordPressDir = true;
                $wpRootDepth = $i;
                break;
            }
        }

        if (!$isInWordPressDir) {
            return false;
        }

        // Get the path relative to WordPress root
        $wpRelativePath = implode('/', array_slice($pathParts, $wpRootDepth + 1));

        // Check WordPress core files
        $coreFiles = $wpConfig['core_files'] ?? ['wp-*.php', 'xmlrpc.php', 'license.txt', 'readme.html'];
        $fileName = basename($relativePath);
        foreach ($coreFiles as $pattern) {
            if (fnmatch($pattern, $fileName)) {
                // But allow wp-config.php as it's often customized
                if ($fileName !== 'wp-config.php') {
                    return true;
                }
            }
        }

        // Check WordPress core directories
        $coreDirs = $wpConfig['core_dirs'] ?? ['wp-admin', 'wp-includes'];
        if (!empty($wpRelativePath)) {
            $firstDir = explode('/', $wpRelativePath)[0];
            if (in_array($firstDir, $coreDirs)) {
                return true;
            }
        }

        // Check wp-content exclusions
        if (str_starts_with($wpRelativePath, 'wp-content/')) {
            $wpContentPath = substr($wpRelativePath, 11);

            // Exclude certain wp-content subdirectories
            $excludeDirs = $wpConfig['exclude_dirs'] ?? ['uploads', 'cache', 'languages', 'upgrade', 'backups'];
            foreach ($excludeDirs as $dir) {
                if (str_starts_with($wpContentPath, $dir . '/') || $wpContentPath === $dir) {
                    return true;
                }
            }

            // Exclude parent themes but keep child themes
            if (str_starts_with($wpContentPath, 'themes/') && ($wpConfig['exclude_parent_themes'] ?? true)) {
                $themePath = substr($wpContentPath, 7);
                $themeName = explode('/', $themePath)[0] ?? '';

                // Keep child themes (those ending with -child)
                if (!str_ends_with($themeName, '-child')) {
                    return true;
                }
            }

            // For plugins, don't exclude the whole plugin, let vendor/node_modules exclusion handle it
            // This allows tracking custom plugin code while excluding dependencies
        }

        return false;
    }
}