<?php

declare(strict_types=1);

namespace ClaudeProjectManager;

use ClaudeProjectManager\Database\FileOperations;
use ClaudeProjectManager\Database\SchemaValidator;
use ClaudeProjectManager\Services\ConcurrentFileHandler;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Main database manager - coordinates JSON file operations
 * Provides high-level interface for database operations
 */
class DatabaseManager
{
    private const DEFAULT_DIR_PERMISSIONS = 0755;
    private const DEFAULT_BACKUP_RETENTION_COUNT = 2;
    private const VALID_DATABASE_FILES = [
        'project', 'inventory', 'tasks', 'progress', 'sessions',
        'state_summary', 'dependencies', 'impacts', 'refactoring_history',
        'metrics', 'code_generation', 'issues'
    ];
    private const PATH_VALIDATION_PATTERN = '/^[a-zA-Z0-9_.]+$/';
    
    private Filesystem $filesystem;
    private FileOperations $fileOps;
    private SchemaValidator $validator;
    private ConcurrentFileHandler $concurrentHandler;
    private string $databasePath;
    private array $loadedData;
    private array $schemaComplianceChecked;
    private array $fileMtimes;
    private array $fileSizes = [];

    /**
     * Initializes database manager with required components
     *
     * @param string $projectRoot Root directory of the project
     */
    public function __construct(string $projectRoot)
    {
        $this->filesystem = new Filesystem();
        $configManager = new ConfigManager($projectRoot);
        $this->databasePath = $configManager->get('paths.database');
        $this->fileOps = new FileOperations();
        $this->validator = new SchemaValidator($configManager->get('paths.schemas'));
        $this->concurrentHandler = new ConcurrentFileHandler($this->fileOps);
        $this->loadedData = [];
        $this->schemaComplianceChecked = [];
        $this->fileMtimes = [];
    }

    /**
     * Creates the database directory if it is missing.
     *
     * Called before a write rather than from the constructor: merely building a
     * DatabaseManager (which the console does on every invocation) must not
     * leave a `.cpm` directory behind in whatever directory the user happens
     * to be standing in.
     */
    private function ensureDatabaseDirectory(): void
    {
        if (!$this->filesystem->exists($this->databasePath)) {
            $this->filesystem->mkdir($this->databasePath, self::DEFAULT_DIR_PERMISSIONS);
        }
    }

    /**
     * Gets the database path
     *
     * @return string Path to database directory
     */
    public function getDatabasePath(): string
    {
        return $this->databasePath;
    }

    /**
     * Initializes empty database files with default structure
     * Creates all required JSON files with proper schemas
     */
    public function initializeDatabase(): void
    {
        $defaultData = $this->getDefaultDatabaseStructures();

        foreach ($defaultData as $fileName => $data) {
            $this->write($fileName, $data);
        }
    }

    /**
     * Reads data from specified database file with caching
     *
     * @param string $fileName Name of the database file (without extension)
     * @return array Parsed JSON data from the file
     * @throws \RuntimeException If file not found, invalid JSON, or schema validation fails after auto-repair
     */
    public function read(string $fileName): array
    {
        if (isset($this->loadedData[$fileName])) {
            $filePath = $this->databasePath . '/' . $fileName . '.json';
            // PHP's stat cache would otherwise serve a stale mtime for the
            // lifetime of a long-running process (e.g. the monitor daemon).
            clearstatcache(false, $filePath);
            $currentMtime = filemtime($filePath);
            $currentSize = filesize($filePath);
            $cachedMtime = $this->fileMtimes[$fileName] ?? 0;
            $cachedSize = $this->fileSizes[$fileName] ?? -1;

            // mtime has one-second granularity: a rewrite in the same second
            // as the cached read is invisible to it, so compare size as well.
            if ($currentMtime <= $cachedMtime && $currentSize === $cachedSize) {
                return $this->loadedData[$fileName];
            }
            // File was modified, need to reload
            unset($this->loadedData[$fileName]);
            unset($this->schemaComplianceChecked[$fileName]); // Re-check compliance for modified files
        }
        
        $filePath = $this->databasePath . '/' . $fileName . '.json';
        
        // Only check schema compliance once per file per session
        if (!isset($this->schemaComplianceChecked[$fileName])) {
            $this->ensureSchemaCompliance($fileName);
            $this->schemaComplianceChecked[$fileName] = true;
        }
        
        if (!$this->filesystem->exists($filePath)) {
            throw new \ClaudeProjectManager\Database\DatabaseFileNotFoundException("Database file not found: {$filePath}");
        }
        
        try {
            $content = $this->fileOps->readFile($filePath);
            $data = json_decode($content, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \RuntimeException("Invalid JSON in file: {$filePath} - " . json_last_error_msg());
            }
            
            // Only normalize data structure if actually needed
            $needsNormalization = $this->checkIfNormalizationNeeded($data, $fileName);
            if ($needsNormalization) {
                $data = $this->normalizeDataStructure($data, $fileName);
                // File will be rewritten with correct structure during next write
            }

            $validation = $this->validator->validate($fileName, $data);
            if (!$validation->isValid()) {
                $errors = implode(', ', $validation->getErrors());
                
                // Attempt auto-repair for common issues
                $repairedData = $this->autoRepairSchemaIssues($fileName, $data, $validation->getErrors());
                if ($repairedData !== null) {
                    // Auto-repair succeeded, use repaired data and save it
                    $this->write($fileName, $repairedData);
                    $data = $repairedData;
                    error_log("Auto-repaired schema issues for {$fileName}: {$errors}");
                } else {
                    // Auto-repair failed, throw exception
                    throw new \RuntimeException("Schema validation failed for {$fileName}: {$errors}");
                }
            }
            
            $this->loadedData[$fileName] = $data;
            clearstatcache(false, $filePath);
            $this->fileMtimes[$fileName] = filemtime($filePath) ?: time();
            $this->fileSizes[$fileName] = filesize($filePath) ?: -1;
            return $data;
            
        } catch (\Exception $e) {
            throw new \RuntimeException("Failed to read database file {$fileName}: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Writes data to specified database file with atomic operations
     *
     * @param string $fileName Name of the database file (without extension)
     * @param array $data Data to write to the file
     * @return void
     * @throws \RuntimeException If schema validation fails, JSON encoding fails, or file write fails
     */
    public function write(string $fileName, array $data): void
    {
        $validation = $this->validator->validate($fileName, $data);
        if (!$validation->isValid()) {
            $errors = implode(', ', $validation->getErrors());
            throw new \RuntimeException("Schema validation failed for {$fileName}: {$errors}");
        }
        
        $this->ensureDatabaseDirectory();

        $filePath = $this->databasePath . '/' . $fileName . '.json';
        $jsonContent = $this->encodeJson($data);
        
        try {
            // Use concurrent handler for safer writes
            if (!$this->concurrentHandler->safeWrite($filePath, $jsonContent, "DatabaseManager::write({$fileName})")) {
                throw new \RuntimeException("Failed to write database file after retries");
            }
            $this->loadedData[$fileName] = $data;
            clearstatcache(false, $filePath);
            $this->fileMtimes[$fileName] = filemtime($filePath) ?: time();
            $this->fileSizes[$fileName] = filesize($filePath) ?: -1;
        } catch (\Exception $e) {
            throw new \RuntimeException("Failed to write database file {$fileName}: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Updates specific section of database file using dot notation
     *
     * @param string $fileName Database file name
     * @param string $path Dot notation path to the data (e.g., "functions.123.status")
     * @param mixed $value Value to set at the specified path
     * @return void
     * @throws \InvalidArgumentException If parameters are invalid or value is inappropriate for the path
     * @throws \RuntimeException If file operations fail
     */
    public function update(string $fileName, string $path, mixed $value): void
    {
        // Validate input parameters
        $this->validateUpdateParameters($fileName, $path, $value);
        
        $data = $this->read($fileName);
        $pathParts = explode('.', $path);
        
        // Navigate and update without full conversion
        $reference = &$data;
        for ($i = 0; $i < count($pathParts) - 1; $i++) {
            $key = $pathParts[$i];
            
            // Handle object->array conversion only where needed
            if (is_object($reference)) {
                $reference = (array) $reference;
            }
            
            if (!isset($reference[$key])) {
                $reference[$key] = [];
            }
            $reference = &$reference[$key];
        }
        
        $lastKey = end($pathParts);
        if (is_object($reference)) {
            $reference = (array) $reference;
        }
        $reference[$lastKey] = $value;
        
        // Only fix object types for the specific path that was modified
        $data = $this->fixObjectTypesForPath($data, $fileName, $pathParts);
        
        $this->write($fileName, $data);
    }

    /**
     * Safely updates database file with concurrent access protection
     * 
     * @param string $fileName Database file name
     * @param callable $updateFunction Function that receives current data and returns updated data
     * @param string $context Context description for logging
     * @return bool True if update succeeded
     */
    public function safeUpdate(string $fileName, callable $updateFunction, string $context = 'unknown'): bool
    {
        $filePath = $this->databasePath . '/' . $fileName . '.json';
        
        return $this->concurrentHandler->atomicUpdate(
            $filePath,
            function(?string $currentContent) use ($fileName, $updateFunction) {
                // Parse existing data or use defaults
                $data = [];
                if ($currentContent !== null && $currentContent !== '') {
                    $decoded = json_decode($currentContent, true);
                    if ($decoded !== null) {
                        $data = $decoded;
                    }
                } else {
                    // File doesn't exist or is empty, use default structure
                    $data = $this->getDefaultStructure($fileName);
                }
                
                // Apply update function
                $updatedData = $updateFunction($data);
                
                // Validate updated data
                $validation = $this->validator->validate($fileName, $updatedData);
                if (!$validation->isValid()) {
                    $errors = implode(', ', $validation->getErrors());
                    throw new \RuntimeException("Schema validation failed for {$fileName}: {$errors}");
                }
                
                // Update cache
                $this->loadedData[$fileName] = $updatedData;

                // Return JSON content
                return $this->encodeJson($updatedData);
            },
            "DatabaseManager::safeUpdate({$fileName}, {$context})"
        ) && $this->refreshFileStatCache($fileName);
    }

    /**
     * Default structure for a single database file (used by safeUpdate when
     * the file does not exist yet).
     */
    private function getDefaultStructure(string $fileName): array
    {
        return $this->getDefaultDatabaseStructures()[$fileName] ?? [];
    }

    /**
     * Refresh cached mtime/size after a write performed outside write().
     * Always returns true so it can be chained after a successful update.
     */
    private function refreshFileStatCache(string $fileName): bool
    {
        $filePath = $this->databasePath . '/' . $fileName . '.json';
        clearstatcache(false, $filePath);
        if (file_exists($filePath)) {
            $this->fileMtimes[$fileName] = filemtime($filePath) ?: time();
            $this->fileSizes[$fileName] = filesize($filePath) ?: -1;
        }

        return true;
    }

    /**
     * Gets diagnostic information about database file access
     */
    public function getDiagnostics(string $fileName): array
    {
        $filePath = $this->databasePath . '/' . $fileName . '.json';
        return $this->concurrentHandler->getAccessStats($filePath);
    }

    /**
     * Creates timestamped backup of all database files
     *
     * @return string Path to the created backup archive
     */
    public function backup(): string
    {
        $timestamp = date('Y-m-d_H-i-s');
        $backupDir = dirname($this->databasePath) . '/backups/' . $timestamp;
        
        $this->filesystem->mkdir($backupDir, self::DEFAULT_DIR_PERMISSIONS);
        
        $files = glob($this->databasePath . '/*.json');
        foreach ($files as $file) {
            $this->filesystem->copy($file, $backupDir . '/' . basename($file));
        }
        
        $this->cleanupOldBackups();
        
        return $backupDir;
    }

    /**
     * Restores database from specified backup
     *
     * @param string $backupPath Path to the backup archive
     * @return void
     */
    public function restore(string $backupPath): void
    {
        if (!is_dir($backupPath)) {
            throw new \RuntimeException("Backup directory not found: {$backupPath}");
        }
        
        $restorationBackup = $this->backup();
        
        try {
            $backupFiles = glob($backupPath . '/*.json');
            foreach ($backupFiles as $backupFile) {
                $filename = basename($backupFile);
                $targetFile = $this->databasePath . '/' . $filename;
                $this->filesystem->copy($backupFile, $targetFile, true);
            }
            
            $this->clearCache();
            
        } catch (\Exception $e) {
            $this->restore($restorationBackup);
            throw new \RuntimeException("Failed to restore from backup: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Performs database maintenance operations
     * Optimizes file sizes, validates integrity, cleans up old data
     *
     * @return array Results of maintenance operations
     */
    public function maintenance(): array
    {
        $results = [
            'validation_results' => [],
            'cleanup_results' => [],
            'optimization_results' => [],
            'errors' => []
        ];
        
        $files = ['project', 'inventory', 'tasks', 'progress', 'sessions'];
        
        foreach ($files as $fileName) {
            try {
                if ($this->filesystem->exists($this->databasePath . '/' . $fileName . '.json')) {
                    $data = $this->read($fileName);
                    $validation = $this->validator->validate($fileName, $data);
                    
                    $results['validation_results'][$fileName] = $validation->isValid();
                    if (!$validation->isValid()) {
                        $results['errors'][$fileName] = [
                            'type' => 'validation_errors',
                            'details' => $validation->getErrors()
                        ];
                    }
                    
                    $this->write($fileName, $data);
                    $results['optimization_results'][$fileName] = 'reformatted';
                }
            } catch (\RuntimeException $e) {
                $results['errors'][$fileName] = [
                    'type' => 'runtime_error',
                    'message' => $e->getMessage(),
                    'context' => 'database_maintenance'
                ];
                error_log("Maintenance runtime error for {$fileName}: " . $e->getMessage());
            } catch (\InvalidArgumentException $e) {
                $results['errors'][$fileName] = [
                    'type' => 'validation_error', 
                    'message' => $e->getMessage(),
                    'context' => 'database_maintenance'
                ];
                error_log("Maintenance validation error for {$fileName}: " . $e->getMessage());
            } catch (\Exception $e) {
                $results['errors'][$fileName] = [
                    'type' => 'unexpected_error',
                    'message' => $e->getMessage(),
                    'context' => 'database_maintenance',
                    'file' => $fileName
                ];
                error_log("Unexpected maintenance error for {$fileName}: " . $e->getMessage());
            }
        }
        
        $this->cleanupOldBackups();
        $results['cleanup_results']['old_backups'] = 'cleaned';
        
        return $results;
    }

    /**
     * Gets database statistics and health information
     *
     * @return array Database statistics and health metrics
     */
    public function getStats(): array
    {
        $stats = [
            'total_size' => 0,
            'file_count' => 0,
            'files' => [],
            'health' => 'good'
        ];
        
        $files = glob($this->databasePath . '/*.json');
        foreach ($files as $file) {
            $fileName = basename($file, '.json');
            $size = filesize($file);
            $modified = filemtime($file);
            
            $stats['files'][$fileName] = [
                'size' => $size,
                'modified' => date('c', $modified),
                'exists' => true
            ];
            
            $stats['total_size'] += $size;
            $stats['file_count']++;
        }
        
        return $stats;
    }

    /**
     * Searches across database files for specific data
     *
     * @param string $query Search query or pattern
     * @param array $files Specific files to search (empty for all)
     * @return array Search results with file locations
     */
    public function search(string $query, array $files = []): array
    {
        $results = [];
        $searchErrors = [];
        $searchFiles = empty($files) ? ['project', 'inventory', 'tasks', 'progress', 'sessions'] : $files;
        
        foreach ($searchFiles as $fileName) {
            try {
                $data = $this->read($fileName);
                $matches = $this->searchInData($data, $query, $fileName);
                $results = array_merge($results, $matches);
            } catch (\RuntimeException $e) {
                $searchErrors[$fileName] = [
                    'type' => 'file_access_error',
                    'message' => $e->getMessage()
                ];
                error_log("Search skipped file {$fileName}: " . $e->getMessage());
                continue;
            } catch (\InvalidArgumentException $e) {
                $searchErrors[$fileName] = [
                    'type' => 'validation_error',
                    'message' => $e->getMessage()
                ];
                error_log("Search validation error for {$fileName}: " . $e->getMessage());
                continue;
            } catch (\Exception $e) {
                $searchErrors[$fileName] = [
                    'type' => 'unexpected_error',
                    'message' => $e->getMessage()
                ];
                error_log("Unexpected search error for {$fileName}: " . $e->getMessage());
                continue;
            }
        }
        
        // Include error information in results for debugging
        if (!empty($searchErrors)) {
            $results['_search_errors'] = $searchErrors;
        }
        
        return $results;
    }

    /**
     * Clears all cached data and forces reload from disk
     *
     * @return void
     */
    public function clearCache(): void
    {
        $this->loadedData = [];
    }

    /**
     * Recursively searches through data structure for query matches
     *
     * @param mixed $data Data to search through (arrays, objects, strings)
     * @param string $query Search query string
     * @param string $fileName Name of file being searched for context
     * @param string $path Current path in data structure for result location
     * @return array Array of search results with file, path, value, and type
     * @throws \InvalidArgumentException If query is empty
     */
    private function searchInData(mixed $data, string $query, string $fileName, string $path = ''): array
    {
        $results = [];
        
        if (is_string($data) && stripos($data, $query) !== false) {
            $results[] = [
                'file' => $fileName,
                'path' => $path,
                'value' => $data,
                'type' => 'string_match'
            ];
        } elseif (is_array($data)) {
            foreach ($data as $key => $value) {
                $currentPath = $path ? $path . '.' . $key : (string)$key;
                $subResults = $this->searchInData($value, $query, $fileName, $currentPath);
                $results = array_merge($results, $subResults);
            }
        }
        
        return $results;
    }

    /**
     * Cleans up old backup directories
     *
     * @param int $keepCount Number of backups to keep
     * @return void
     */
    private function cleanupOldBackups(int $keepCount = self::DEFAULT_BACKUP_RETENTION_COUNT): void
    {
        $backupDir = dirname($this->databasePath) . '/backups';
        
        if (!is_dir($backupDir)) {
            return;
        }
        
        $backups = glob($backupDir . '/*', GLOB_ONLYDIR);
        if (count($backups) <= $keepCount) {
            return;
        }
        
        usort($backups, function($a, $b) {
            return filemtime($b) - filemtime($a);
        });
        
        $toDelete = array_slice($backups, $keepCount);
        foreach ($toDelete as $backup) {
            $this->filesystem->remove($backup);
        }
    }

    /**
     * Normalizes data structure to match schema expectations
     * Converts empty arrays to objects where schemas expect objects
     *
     * @param array $data Data structure to normalize
     * @param string $fileName Database file name for schema-specific normalization
     * @return array Normalized data structure with correct object/array types
     */
    private function normalizeDataStructure(array $data, string $fileName): array
    {
        switch ($fileName) {
            case 'project':
                // Ensure config and analysis_stats are objects, not arrays
                if (isset($data['config']) && is_array($data['config']) && empty($data['config'])) {
                    $data['config'] = new \stdClass();
                }
                if (isset($data['analysis_stats']) && is_array($data['analysis_stats']) && empty($data['analysis_stats'])) {
                    $data['analysis_stats'] = new \stdClass();
                }
                break;
                
            case 'inventory':
                // Keep functions as arrays to match schema
                if (!isset($data['functions']) || !is_array($data['functions'])) {
                    $data['functions'] = [];
                }
                if (!isset($data['files']) || !is_array($data['files'])) {
                    $data['files'] = [];
                }
                if (!isset($data['classes']) || !is_array($data['classes'])) {
                    $data['classes'] = [];
                }
                break;
                
            case 'progress':
                // Ensure by_file and by_function are objects
                if (isset($data['by_file']) && is_array($data['by_file']) && empty($data['by_file'])) {
                    $data['by_file'] = new \stdClass();
                }
                if (isset($data['by_function']) && is_array($data['by_function']) && empty($data['by_function'])) {
                    $data['by_function'] = new \stdClass();
                }
                break;
        }
        
        return $data;
    }

    /**
     * Recursively converts objects to arrays for manipulation
     *
     * @param mixed $data Data structure to convert (object, array, or scalar)
     * @return mixed Converted data with all objects as arrays
     */
    private function objectsToArrays(mixed $data): mixed
    {
        if (is_object($data)) {
            $data = (array) $data;
        }
        
        if (is_array($data)) {
            foreach ($data as $key => $value) {
                $data[$key] = $this->objectsToArrays($value);
            }
        }
        
        return $data;
    }

    /**
     * Converts arrays back to objects where schemas expect objects
     *
     * @param array $data Data structure to convert
     * @param string $fileName Database file name for schema-specific conversions
     * @return array Data with appropriate arrays converted to objects
     * @throws \InvalidArgumentException If fileName is not supported
     */
    private function arraysToObjects(array $data, string $fileName): array
    {
        switch ($fileName) {
            case 'project':
                if (isset($data['config']) && is_array($data['config']) && empty($data['config'])) {
                    $data['config'] = new \stdClass();
                }
                if (isset($data['analysis_stats']) && is_array($data['analysis_stats']) && empty($data['analysis_stats'])) {
                    $data['analysis_stats'] = new \stdClass();
                }
                break;

            case 'inventory':
                // Keep functions as arrays to match schema
                if (!isset($data['functions']) || !is_array($data['functions'])) {
                    $data['functions'] = [];
                }
                if (!isset($data['files']) || !is_array($data['files'])) {
                    $data['files'] = [];
                }
                if (!isset($data['classes']) || !is_array($data['classes'])) {
                    $data['classes'] = [];
                }
                break;

            case 'progress':
                // Convert by_file and by_function back to objects if they should be objects
                if (isset($data['by_file'])) {
                    if (empty($data['by_file'])) {
                        $data['by_file'] = new \stdClass();
                    } else {
                        // Convert to object with string keys preserved
                        $data['by_file'] = (object) $data['by_file'];
                    }
                }
                if (isset($data['by_function'])) {
                    if (empty($data['by_function'])) {
                        $data['by_function'] = new \stdClass();
                    } else {
                        // Convert to object with string keys preserved
                        $data['by_function'] = (object) $data['by_function'];
                    }
                }
                break;

            case 'sessions':
                if (isset($data['current_session']) && is_array($data['current_session']) && empty($data['current_session'])) {
                    $data['current_session'] = new \stdClass();
                }
                break;
        }

        return $data;
    }

    /**
     * Ensures database file has schema-compliant structure
     * 
     * @param string $type Database type
     * @return void
     */
    private function ensureSchemaCompliance(string $type): void
    {
        $filePath = $this->databasePath . '/' . $type . '.json';
        
        if (!file_exists($filePath)) {
            return;
        }
        
        try {
            $content = $this->fileOps->readFile($filePath);
            $data = json_decode($content, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \RuntimeException("Invalid JSON in {$filePath}: " . json_last_error_msg());
            }
            if (!$data) {
                return;
            }
        } catch (\Exception $e) {
            error_log("Failed to read file for schema compliance: " . $e->getMessage());
            return; // Skip repair if file can't be read
        }
        
        $needsUpdate = false;
        
        // Fix common schema issues
        switch ($type) {
            case 'state_summary':
                // Ensure objects instead of arrays
                foreach (['project_overview', 'completion_summary', 'recent_activity', 'critical_metrics', 'file_summary'] as $key) {
                    if (isset($data[$key]) && is_array($data[$key]) && array_keys($data[$key]) === range(0, count($data[$key]) - 1)) {
                        $data[$key] = (object) [];
                        $needsUpdate = true;
                    }
                }
                break;
                
            case 'progress':
                // Ensure required keys exist
                if (!isset($data['by_file'])) {
                    $data['by_file'] = (object) [];
                    $needsUpdate = true;
                }
                if (!isset($data['by_function'])) {
                    $data['by_function'] = (object) [];
                    $needsUpdate = true;
                }
                if (!isset($data['global_stats'])) {
                    $data['global_stats'] = [
                        'total_functions' => 0,
                        'completed_functions' => 0,
                        'in_progress_functions' => 0,
                        'pending_functions' => 0,
                        'completion_percentage' => 0.0
                    ];
                    $needsUpdate = true;
                }
                break;
                
            case 'project':
                // Ensure required keys exist
                if (!isset($data['project_id'])) {
                    $data['project_id'] = uniqid('proj_', true);
                    $needsUpdate = true;
                }
                if (!isset($data['created_at'])) {
                    $data['created_at'] = date('c');
                    $needsUpdate = true;
                }
                break;
                
            case 'dependencies':
                // Ensure files is object not array
                if (isset($data['files']) && is_array($data['files']) && array_keys($data['files']) === range(0, count($data['files']) - 1)) {
                    $data['files'] = (object) [];
                    $needsUpdate = true;
                }
                // Ensure dependency_graph subobjects are objects not arrays
                if (isset($data['dependency_graph']['dependency_chains']) && is_array($data['dependency_graph']['dependency_chains']) && array_keys($data['dependency_graph']['dependency_chains']) === range(0, count($data['dependency_graph']['dependency_chains']) - 1)) {
                    $data['dependency_graph']['dependency_chains'] = (object) [];
                    $needsUpdate = true;
                }
                if (isset($data['dependency_graph']['statistics']) && is_array($data['dependency_graph']['statistics']) && array_keys($data['dependency_graph']['statistics']) === range(0, count($data['dependency_graph']['statistics']) - 1)) {
                    $data['dependency_graph']['statistics'] = (object) [];
                    $needsUpdate = true;
                }
                break;
                
            case 'code_generation':
                // Ensure templates are objects not arrays
                $templateTypes = ['function', 'class', 'method', 'test', 'documentation', 'interface'];
                foreach ($templateTypes as $type) {
                    if (isset($data['templates'][$type]) && is_array($data['templates'][$type]) && array_keys($data['templates'][$type]) === range(0, count($data['templates'][$type]) - 1)) {
                        $data['templates'][$type] = (object) [];
                        $needsUpdate = true;
                    }
                }
                
                // Ensure patterns are objects not arrays
                $patternTypes = ['functions', 'classes', 'methods', 'tests', 'documentation', 'interfaces'];
                foreach ($patternTypes as $type) {
                    if (isset($data['patterns'][$type]) && is_array($data['patterns'][$type]) && array_keys($data['patterns'][$type]) === range(0, count($data['patterns'][$type]) - 1)) {
                        $data['patterns'][$type] = (object) [];
                        $needsUpdate = true;
                    }
                }
                
                // Ensure generation_stats.by_type is object not array
                if (isset($data['generation_stats']['by_type']) && is_array($data['generation_stats']['by_type']) && array_keys($data['generation_stats']['by_type']) === range(0, count($data['generation_stats']['by_type']) - 1)) {
                    $data['generation_stats']['by_type'] = (object) [];
                    $needsUpdate = true;
                }
                
                // Ensure last_updated is string not null
                if (isset($data['generation_stats']['last_updated']) && $data['generation_stats']['last_updated'] === null) {
                    $data['generation_stats']['last_updated'] = date('c');
                    $needsUpdate = true;
                }
                break;
        }
        
        // Write back the corrected data only if changes were made
        if ($needsUpdate) {
            try {
                $jsonContent = $this->encodeJson($data);
            } catch (\RuntimeException $e) {
                error_log("Failed to encode schema compliance data for {$filePath}: " . $e->getMessage());
                return;
            }
            
            if (!$this->concurrentHandler->safeWrite($filePath, $jsonContent, "Schema compliance fix for {$type}")) {
                error_log("Failed to write schema compliance fixes to {$filePath}");
            }
        }
    }

    /**
     * Attempts to automatically repair common schema validation issues
     *
     * @param string $fileName Database file name
     * @param array $data Current data that failed validation
     * @param array $errors Validation errors to attempt to fix
     * @return array|null Repaired data if successful, null if no repairs possible
     */
    private function autoRepairSchemaIssues(string $fileName, array $data, array $errors): ?array
    {
        $repaired = $data;
        $wasRepaired = false;

        foreach ($errors as $error) {
            if (str_contains($error, 'expected object, got array')) {
                // Convert empty arrays to objects where schema expects objects
                $repaired = $this->normalizeDataStructure($repaired, $fileName);
                $wasRepaired = true;
            }

            // Fix invalid project type values (must be: php, python, or mixed)
            if ($fileName === 'project' && str_contains($error, "Field 'type'") && isset($repaired['type'])) {
                $currentType = $repaired['type'];
                $normalizedType = $this->normalizeProjectType($currentType);
                if ($normalizedType !== $currentType) {
                    $repaired['type'] = $normalizedType;
                    $wasRepaired = true;
                    error_log("Auto-repaired project type from '{$currentType}' to '{$normalizedType}'");
                }
            }

            // Additional repair patterns can be added here as needed
            if (str_contains($error, 'missing required property')) {
                // Extract property name and add default value if possible
                $wasRepaired = $this->addMissingRequiredProperties($repaired, $fileName, $error) || $wasRepaired;
            }
        }

        return $wasRepaired ? $repaired : null;
    }

    /**
     * Normalizes project type to match schema enum: ["php","python","mixed"]
     *
     * @param string $type Current type value
     * @return string Normalized type value
     */
    private function normalizeProjectType(string $type): string
    {
        // Convert to lowercase for comparison
        $typeLower = strtolower($type);

        // Direct matches
        if (in_array($typeLower, ['php', 'python', 'mixed'])) {
            return $typeLower;
        }

        // Map common variations
        $typeMap = [
            'javascript' => 'mixed',
            'typescript' => 'mixed',
            'javascript/typescript' => 'mixed',
            'js' => 'mixed',
            'ts' => 'mixed',
            'rust' => 'mixed',
            'go' => 'mixed',
            'multi-language' => 'mixed',
            'multilanguage' => 'mixed',
            'unknown' => 'mixed',
        ];

        return $typeMap[$typeLower] ?? 'mixed';
    }

    /**
     * Adds missing required properties with sensible defaults
     *
     * @param array &$data Data to modify (passed by reference)
     * @param string $fileName Database file name for context
     * @param string $error Error message containing property information
     * @return bool True if properties were added
     */
    private function addMissingRequiredProperties(array &$data, string $fileName, string $error): bool
    {
        $wasModified = false;
        
        // Common missing properties and their defaults
        switch ($fileName) {
            case 'project':
                if (!isset($data['project_id'])) {
                    $data['project_id'] = uniqid('proj_', true);
                    $wasModified = true;
                }
                if (!isset($data['created_at'])) {
                    $data['created_at'] = date('c');
                    $wasModified = true;
                }
                break;
                
            case 'progress':
                if (!isset($data['global_stats'])) {
                    $data['global_stats'] = [
                        'total_functions' => 0,
                        'completed_functions' => 0,
                        'in_progress_functions' => 0,
                        'pending_functions' => 0,
                        'completion_percentage' => 0.0
                    ];
                    $wasModified = true;
                }
                break;
        }
        
        return $wasModified;
    }

    /**
     * Checks if data structure needs normalization
     *
     * @param array $data Data to check
     * @param string $fileName Database file name for context
     * @return bool True if normalization is needed
     */
    private function checkIfNormalizationNeeded(array $data, string $fileName): bool
    {
        switch ($fileName) {
            case 'project':
                return isset($data['config']) && is_array($data['config']) && empty($data['config']) ||
                       isset($data['analysis_stats']) && is_array($data['analysis_stats']) && empty($data['analysis_stats']);
                       
            case 'progress':
                return (isset($data['by_file']) && is_array($data['by_file']) && empty($data['by_file'])) ||
                       (isset($data['by_function']) && is_array($data['by_function']) && empty($data['by_function']));
                       
            case 'sessions':
                return isset($data['current_session']) && is_array($data['current_session']) && empty($data['current_session']);
                
            default:
                return false;
        }
    }

    /**
     * Fixes object types for a specific path without converting entire structure
     *
     * @param array $data Full data structure
     * @param string $fileName Database file name for context
     * @param array $pathParts Path that was modified
     * @return array Data with correct object types for the modified path
     */
    private function fixObjectTypesForPath(array $data, string $fileName, array $pathParts): array
    {
        // Use schema-driven conversion for consistency
        return $this->convertBySchema($data, $fileName);
    }

    /**
     * Gets paths that should be objects for a given file type
     *
     * @param string $fileName Database file name
     * @return array Array of dot-notation paths that should be objects
     */
    private function getPathsRequiringObjects(string $fileName): array
    {
        return match($fileName) {
            'project' => ['config', 'analysis_stats'],
            'progress' => ['by_file', 'by_function'],
            'sessions' => ['current_session'],
            default => []
        };
    }

    /**
     * Ensures a specific path in data is an object if it's empty
     *
     * @param array $data Data structure to modify
     * @param string $path Dot notation path
     * @return array Modified data structure
     */
    private function ensurePathIsObject(array $data, string $path): array
    {
        $pathParts = explode('.', $path);
        $reference = &$data;
        
        foreach ($pathParts as $key) {
            if (!isset($reference[$key])) {
                $reference[$key] = new \stdClass();
                return $data;
            }
            
            if (is_array($reference[$key]) && empty($reference[$key])) {
                $reference[$key] = new \stdClass();
                return $data;
            }
            
            $reference = &$reference[$key];
        }
        
        return $data;
    }

    /**
     * Validates parameters for update operations
     *
     * @param string $fileName Database file name
     * @param string $path Dot notation path
     * @param mixed $value Value to validate
     * @return void
     * @throws \InvalidArgumentException If parameters are invalid
     */
    private function validateUpdateParameters(string $fileName, string $path, mixed $value): void
    {
        // Validate file name
        if (empty($fileName)) {
            throw new \InvalidArgumentException("File name cannot be empty");
        }
        
        if (!in_array($fileName, self::VALID_DATABASE_FILES)) {
            throw new \InvalidArgumentException("Invalid file name: {$fileName}");
        }
        
        // Validate path
        if (empty($path)) {
            throw new \InvalidArgumentException("Path cannot be empty");
        }
        
        if (!preg_match(self::PATH_VALIDATION_PATTERN, $path)) {
            throw new \InvalidArgumentException("Path contains invalid characters: {$path}");
        }
        
        // Add path-specific validation
        $this->validatePathSpecificValue($fileName, $path, $value);
    }

    /**
     * Validates values based on specific path context
     *
     * @param string $fileName Database file name
     * @param string $path Dot notation path
     * @param mixed $value Value to validate
     * @return void
     * @throws \InvalidArgumentException If value is invalid for the path
     */
    private function validatePathSpecificValue(string $fileName, string $path, mixed $value): void
    {
        // Progress file validations
        if ($fileName === 'progress') {
            if (str_contains($path, 'completion_percentage')) {
                if (!is_numeric($value) || $value < 0 || $value > 100) {
                    throw new \InvalidArgumentException("Completion percentage must be 0-100, got: " . var_export($value, true));
                }
            }
            
            if (str_contains($path, 'status') && is_string($value)) {
                $validStatuses = ['pending', 'in_progress', 'completed', 'verified', 'has_issues'];
                if (!in_array($value, $validStatuses)) {
                    throw new \InvalidArgumentException("Invalid status: {$value}. Must be one of: " . implode(', ', $validStatuses));
                }
            }
        }
        
        // Project file validations
        if ($fileName === 'project') {
            if ($path === 'project_id' && (!is_string($value) || empty($value))) {
                throw new \InvalidArgumentException("Project ID must be a non-empty string");
            }
            
            if (str_contains($path, 'created_at') || str_contains($path, 'updated_at')) {
                if (!is_string($value) || !strtotime($value)) {
                    throw new \InvalidArgumentException("Date fields must be valid ISO 8601 date strings");
                }
            }
        }
        
        // General validations for all files
        if (str_contains($path, 'timestamp') && !is_null($value)) {
            if (!is_int($value) && !is_string($value)) {
                throw new \InvalidArgumentException("Timestamp must be integer or string");
            }
            
            if (is_string($value) && !strtotime($value)) {
                throw new \InvalidArgumentException("String timestamp must be valid date format");
            }
        }
    }

    /**
     * Centralized JSON encoding with consistent flags
     *
     * @param mixed $data Data to encode as JSON
     * @param bool $prettyPrint Whether to format JSON with pretty printing
     * @return string Encoded JSON string
     * @throws \RuntimeException If JSON encoding fails
     */
    private function encodeJson(mixed $data, bool $prettyPrint = false): string
    {
        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
        if ($prettyPrint) {
            $flags |= JSON_PRETTY_PRINT;
        }
        
        $json = json_encode($data, $flags);
        if ($json === false) {
            throw new \RuntimeException("Failed to encode data as JSON: " . json_last_error_msg());
        }
        
        return $json;
    }

    /**
     * Converts data structure based on schema rules
     *
     * @param array $data Data structure to convert
     * @param string $fileName Database file name for schema lookup
     * @return array Data with correct types according to schema
     */
    private function convertBySchema(array $data, string $fileName): array
    {
        $schemaRules = $this->getSchemaConversionRules($fileName);
        
        foreach ($schemaRules as $path => $expectedType) {
            $data = $this->convertPathToType($data, $path, $expectedType);
        }
        
        return $data;
    }

    /**
     * Gets schema conversion rules for a database file
     *
     * @param string $fileName Database file name
     * @return array Array mapping paths to expected types
     */
    private function getSchemaConversionRules(string $fileName): array
    {
        return match($fileName) {
            'project' => [
                'config' => 'object',
                'analysis_stats' => 'object'
            ],
            'progress' => [
                'by_file' => 'object',
                'by_function' => 'object'
            ],
            'sessions' => [
                'current_session' => 'object'
            ],
            'state_summary' => [
                'project_overview' => 'object',
                'completion_summary' => 'object',
                'recent_activity' => 'object',
                'critical_metrics' => 'object',
                'file_summary' => 'object'
            ],
            'dependencies' => [
                'files' => 'object',
                'dependency_graph.dependency_chains' => 'object',
                'dependency_graph.statistics' => 'object'
            ],
            'code_generation' => [
                'templates.function' => 'object',
                'templates.class' => 'object',
                'templates.method' => 'object',
                'templates.test' => 'object',
                'templates.documentation' => 'object',
                'templates.interface' => 'object',
                'patterns.functions' => 'object',
                'patterns.classes' => 'object',
                'patterns.methods' => 'object',
                'patterns.tests' => 'object',
                'patterns.documentation' => 'object',
                'patterns.interfaces' => 'object',
                'generation_stats.by_type' => 'object'
            ],
            default => []
        };
    }

    /**
     * Converts a specific path to the expected type
     *
     * @param array $data Data structure
     * @param string $path Dot notation path
     * @param string $expectedType Expected type ('object' or 'array')
     * @return array Modified data structure
     */
    private function convertPathToType(array $data, string $path, string $expectedType): array
    {
        $pathParts = explode('.', $path);
        $reference = &$data;
        
        // Navigate to the parent of the target
        for ($i = 0; $i < count($pathParts) - 1; $i++) {
            $key = $pathParts[$i];
            if (!isset($reference[$key])) {
                $reference[$key] = [];
            }
            $reference = &$reference[$key];
        }
        
        $lastKey = end($pathParts);
        
        // Convert to expected type if needed
        if (isset($reference[$lastKey])) {
            if ($expectedType === 'object' && is_array($reference[$lastKey]) && empty($reference[$lastKey])) {
                $reference[$lastKey] = new \stdClass();
            } elseif ($expectedType === 'array' && is_object($reference[$lastKey])) {
                $reference[$lastKey] = [];
            }
        } else {
            // Create with expected type
            $reference[$lastKey] = $expectedType === 'object' ? new \stdClass() : [];
        }
        
        return $data;
    }

    /**
     * Gets default database structures for initialization
     * 
     * @return array Default data structures for all database files
     */
    private function getDefaultDatabaseStructures(): array
    {
        return [
            'project' => $this->getDefaultProjectStructure(),
            'inventory' => $this->getDefaultInventoryStructure(),
            'tasks' => $this->getDefaultTasksStructure(),
            'progress' => $this->getDefaultProgressStructure(),
            'sessions' => $this->getDefaultSessionsStructure(),
            'state_summary' => $this->getDefaultStateSummaryStructure(),
            'dependencies' => $this->getDefaultDependenciesStructure(),
            'impacts' => $this->getDefaultImpactsStructure(),
            'refactoring_history' => $this->getDefaultRefactoringHistoryStructure(),
            'metrics' => $this->getDefaultMetricsStructure(),
            'code_generation' => $this->getDefaultCodeGenerationStructure(),
            'issues' => $this->getDefaultIssuesStructure()
        ];
    }

    private function getDefaultProjectStructure(): array
    {
        return [
            'project_id' => uniqid('proj_', true),
            'name' => basename(dirname($this->databasePath, 2)),
            'type' => 'mixed',
            'created_at' => date('c'),
            'updated_at' => date('c'),
            'root_path' => dirname($this->databasePath, 2),
            'config' => (object) []
        ];
    }

    private function getDefaultInventoryStructure(): array
    {
        return [
            'files' => [],
            'functions' => [],
            'classes' => [],
            'stats' => [
                'total_files' => 0,
                'total_functions' => 0,
                'total_classes' => 0,
                'total_lines' => 0
            ]
        ];
    }

    private function getDefaultTasksStructure(): array
    {
        return [
            'active_tasks' => [],
            'completed_tasks' => [],
            'execution_plan' => [
                'current_phase' => 'initialization',
                'phases' => []
            ]
        ];
    }

    private function getDefaultProgressStructure(): array
    {
        return [
            'by_file' => (object) [],
            'by_function' => (object) [],
            'global_stats' => [
                'total_functions' => 0,
                'completed_functions' => 0,
                'in_progress_functions' => 0,
                'pending_functions' => 0,
                'completion_percentage' => 0.0
            ]
        ];
    }

    private function getDefaultSessionsStructure(): array
    {
        return [
            'current_session' => (object) [],
            'session_history' => []
        ];
    }

    private function getDefaultStateSummaryStructure(): array
    {
        return [
            'generated_at' => null,
            'project_overview' => (object) [],
            'completion_summary' => (object) [],
            'recent_activity' => (object) [],
            'critical_metrics' => (object) [],
            'file_summary' => (object) []
        ];
    }

    private function getDefaultDependenciesStructure(): array
    {
        return [
            'generated_at' => null,
            'files' => (object) [],
            'dependency_graph' => [
                'circular_dependencies' => [],
                'dependency_chains' => (object) [],
                'statistics' => (object) []
            ]
        ];
    }

    private function getDefaultImpactsStructure(): array
    {
        return [
            'generated_at' => null,
            'cached_analyses' => (object) [],
            'function_usage_map' => (object) []
        ];
    }

    private function getDefaultRefactoringHistoryStructure(): array
    {
        return [
            'generated_at' => null,
            'function_movements' => (object) [],
            'file_splits' => [],
            'merge_operations' => []
        ];
    }

    private function getDefaultMetricsStructure(): array
    {
        return [
            'generated_at' => null,
            'files' => (object) [],
            'project_trends' => [
                'overall_complexity' => (object) [],
                'maintainability_index' => (object) [],
                'technical_debt_hours' => (object) []
            ]
        ];
    }

    private function getDefaultCodeGenerationStructure(): array
    {
        return [
            'generations' => [],
            'templates' => [
                'function' => (object) [],
                'class' => (object) [],
                'method' => (object) [],
                'test' => (object) [],
                'documentation' => (object) [],
                'interface' => (object) []
            ],
            'patterns' => [
                'functions' => (object) [],
                'classes' => (object) [],
                'methods' => (object) [],
                'tests' => (object) [],
                'documentation' => (object) [],
                'interfaces' => (object) []
            ],
            'generation_stats' => [
                'total_generations' => 0,
                'by_type' => (object) [],
                'average_confidence' => 0.0,
                'recent_activity_7_days' => 0,
                'most_generated_type' => null,
                'last_updated' => date('c')
            ]
        ];
    }

    private function getDefaultIssuesStructure(): array
    {
        return [
            'version' => '1.0',
            'issues' => [],
            'metadata' => [
                'lastId' => 0,
                'prefix' => 'CPM',
                'categoryPrefix' => 'ISSUE'
            ]
        ];
    }
}