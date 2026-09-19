<?php

declare(strict_types=1);

namespace ClaudeProjectManager;

use ClaudeProjectManager\Analyzers\PhpAnalyzer;
use ClaudeProjectManager\Analyzers\PythonAnalyzer;
use ClaudeProjectManager\Analyzers\ArchitectureAnalyzer;

/**
 * Main project analysis coordinator - orchestrates different analyzers
 * Handles high-level analysis workflow and result compilation
 */
class ProjectAnalyzer
{
    private DatabaseManager $database;
    private ConfigManager $config;
    private PhpAnalyzer $phpAnalyzer;
    private PythonAnalyzer $pythonAnalyzer;
    private ArchitectureAnalyzer $architectureAnalyzer;
    private array $functionIdLookup = [];

    /**
     * Initializes the project analyzer with required dependencies
     *
     * @param DatabaseManager $database Database manager for storing results
     * @param ConfigManager $config Configuration manager for analysis rules
     */
    public function __construct(DatabaseManager $database, ConfigManager $config)
    {
        $this->database = $database;
        $this->config = $config;
        $this->phpAnalyzer = new PhpAnalyzer($config);
        $this->pythonAnalyzer = new PythonAnalyzer($config);
        $this->architectureAnalyzer = new ArchitectureAnalyzer($config);
    }

    /**
     * Main entry point for complete project analysis
     * Reads .claude.md instructions and performs comprehensive codebase analysis
     *
     * @param string $projectRoot Root directory of the project to analyze
     * @param callable|null $progressCallback Optional callback to report progress
     * @return AnalysisResult Complete analysis results with statistics
     */
    public function analyzeProject(string $projectRoot, ?callable $progressCallback = null): AnalysisResult
    {
        $startTime = microtime(true);
        
        // Read analysis instructions
        $instructions = $this->readInstructions($projectRoot);
        
        // Discover all relevant files
        $files = $this->discoverFiles($projectRoot);
        
        $fileAnalyses = [];
        $totalFiles = count($files);
        
        // Analyze each file
        foreach ($files as $index => $fileInfo) {
            try {
                $analysis = $this->analyzeFile($fileInfo['path']);
                $fileAnalyses[] = $analysis;
                
                // Report progress if callback provided
                if ($progressCallback !== null) {
                    $progressCallback($index + 1, $totalFiles);
                }
                
                error_log("Analyzed file " . ($index + 1) . "/{$totalFiles}: " . basename($fileInfo['path']));
            } catch (\Exception $e) {
                error_log("Failed to analyze file {$fileInfo['path']}: " . $e->getMessage());
                
                // Still report progress even if file failed
                if ($progressCallback !== null) {
                    $progressCallback($index + 1, $totalFiles);
                }
            }
        }
        
        // Perform architecture analysis
        $architectureAnalysis = $this->architectureAnalyzer->analyze($projectRoot, $fileAnalyses);
        
        // Compile results
        $result = $this->compileResults($fileAnalyses, $architectureAnalysis);

        // Calculate completion time
        $completionTime = microtime(true) - $startTime;

        // Save to database
        $this->saveResults($result, $completionTime, $fileAnalyses);
        
        return new AnalysisResult(
            array_map(fn($fa) => $fa->toArray(), $fileAnalyses),
            $result['function_inventory'],
            $result['statistics'],
            $architectureAnalysis,
            $result['quality_metrics'],
            $completionTime
        );
    }

    /**
     * Discovers all relevant source files in the project
     * Applies inclusion/exclusion patterns from configuration
     *
     * @param string $projectRoot Root directory to scan
     * @return array Array of discovered files with metadata
     */
    private function discoverFiles(string $projectRoot): array
    {
        $files = [];
        $inclusionPatterns = $this->config->getInclusionPatterns();

        // Get max scan depth from config (0 = unlimited)
        $maxDepth = (int)$this->config->get('rules.analysis.max_scan_depth', 0);

        $this->scanDirectory($projectRoot, '', $files, $inclusionPatterns, 0, $maxDepth);

        return $files;
    }

    /**
     * Simple recursive directory scanner that respects exclusions and depth limits
     *
     * @param string $baseDir Base directory being scanned
     * @param string $relativePath Current relative path
     * @param array &$files Array to collect found files
     * @param array $inclusionPatterns File patterns to include
     * @param int $currentDepth Current recursion depth
     * @param int $maxDepth Maximum recursion depth (0 = unlimited)
     */
    private function scanDirectory(string $baseDir, string $relativePath, array &$files, array $inclusionPatterns, int $currentDepth = 0, int $maxDepth = 0): void
    {
        // Check depth limit (0 means unlimited)
        if ($maxDepth > 0 && $currentDepth >= $maxDepth) {
            return;
        }

        $fullPath = $baseDir . ($relativePath ? '/' . $relativePath : '');

        if (!is_dir($fullPath) || !is_readable($fullPath)) {
            return;
        }

        $items = @scandir($fullPath);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $itemRelativePath = $relativePath ? $relativePath . '/' . $item : $item;
            $itemFullPath = $fullPath . '/' . $item;

            // Check exclusions BEFORE descending into directories
            if ($this->shouldExcludeFile($itemRelativePath, $baseDir)) {
                continue;
            }

            if (is_dir($itemFullPath)) {
                // Check if directory is readable before descending
                if (is_readable($itemFullPath)) {
                    $this->scanDirectory($baseDir, $itemRelativePath, $files, $inclusionPatterns, $currentDepth + 1, $maxDepth);
                }
            } elseif (is_file($itemFullPath) && is_readable($itemFullPath)) {
                // Check if file matches inclusion patterns
                $matches = false;
                foreach ($inclusionPatterns as $pattern) {
                    if (fnmatch($pattern, $item)) {
                        $matches = true;
                        break;
                    }
                }

                if ($matches) {
                    $files[] = [
                        'path' => $itemFullPath,
                        'relative_path' => $itemRelativePath,
                        'size' => @filesize($itemFullPath) ?: 0,
                        'modified' => @filemtime($itemFullPath) ?: 0,
                        'extension' => pathinfo($itemFullPath, PATHINFO_EXTENSION)
                    ];
                }
            }
        }
    }

    /**
     * Analyzes a single file using the appropriate analyzer
     * Delegates to language-specific analyzers based on file extension
     *
     * @param string $filePath Path to the file to analyze
     * @return FileAnalysis Structured analysis results for the file
     */
    private function analyzeFile(string $filePath): FileAnalysis
    {
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        
        switch ($extension) {
            case 'php':
                return $this->phpAnalyzer->analyzeFile($filePath);
            case 'py':
                return $this->pythonAnalyzer->analyzeFile($filePath);
            default:
                // For unsupported file types, return basic analysis
                return new FileAnalysis(
                    $filePath,
                    $extension,
                    [], // functions
                    [], // classes  
                    [
                        'lines' => count(file($filePath)),
                        'size' => filesize($filePath)
                    ]
                );
        }
    }

    /**
     * Creates complete function inventory from all analyzed files
     * Generates unique identifiers and initial progress status
     *
     * @param array $fileAnalyses Array of file analysis results
     * @return array Structured function inventory for tracking
     */
    private function createFunctionInventory(array $fileAnalyses): array
    {
        $inventory = [];
        $this->functionIdLookup = [];
        $functionId = 1;
        
        foreach ($fileAnalyses as $fileAnalysis) {
            $filePath = $fileAnalysis->getFilePath();
            
            // Process standalone functions
            foreach ($fileAnalysis->getFunctions() as $function) {
                $inventory[$functionId] = [
                    'id' => $functionId,
                    'name' => $function['name'],
                    'file_path' => $filePath,
                    'line_start' => $function['line_start'] ?? 0,
                    'line_end' => $function['line_end'] ?? 0,
                    'class' => null,
                    'visibility' => $function['visibility'] ?? 'public',
                    'parameters' => $function['parameters'] ?? [],
                    'return_type' => $function['return_type'] ?? null,
                    'complexity' => $function['complexity'] ?? 1,
                    'status' => 'pending',
                    'created_at' => date('c'),
                    'updated_at' => date('c')
                ];
                $this->functionIdLookup[$filePath][] = $functionId;
                $functionId++;
            }
            
            // Process class methods
            foreach ($fileAnalysis->getClasses() as $class) {
                foreach ($class['methods'] ?? [] as $method) {
                    $inventory[$functionId] = [
                        'id' => $functionId,
                        'name' => $method['name'],
                        'file_path' => $filePath,
                        'line_start' => $method['line_start'] ?? 0,
                        'line_end' => $method['line_end'] ?? 0,
                        'class' => $class['name'],
                        'visibility' => $method['visibility'] ?? 'public',
                        'parameters' => $method['parameters'] ?? [],
                        'return_type' => $method['return_type'] ?? null,
                        'complexity' => $method['complexity'] ?? 1,
                        'status' => 'pending',
                        'created_at' => date('c'),
                        'updated_at' => date('c')
                    ];
                    $this->functionIdLookup[$filePath][] = $functionId;
                    $functionId++;
                }
            }
        }

        return array_values($inventory);
    }

    /**
     * Compiles analysis results from all analyzers
     * Merges results and calculates aggregate statistics
     *
     * @param array $fileAnalyses Individual file analysis results
     * @param array $architectureAnalysis Project architecture analysis
     * @return array Complete compiled analysis results
     */
    private function compileResults(array $fileAnalyses, array $architectureAnalysis): array
    {
        $functionInventory = $this->createFunctionInventory($fileAnalyses);
        
        $statistics = [
            'total_files' => count($fileAnalyses),
            'total_functions' => count($functionInventory),
            'total_classes' => 0,
            'total_lines' => 0,
            'files_by_type' => [],
            'complexity_distribution' => []
        ];
        
        $qualityMetrics = [
            'documentation_coverage' => 0.0,
            'type_hint_coverage' => 0.0,
            'test_coverage' => 0.0,
            'code_quality_score' => 0.0
        ];
        
        foreach ($fileAnalyses as $analysis) {
            $fileType = $analysis->getFileType();
            $statistics['files_by_type'][$fileType] = ($statistics['files_by_type'][$fileType] ?? 0) + 1;
            
            $metrics = $analysis->getMetrics();
            $statistics['total_lines'] += $metrics['lines'] ?? 0;
            $statistics['total_classes'] += count($analysis->getClasses());
        }
        
        return [
            'function_inventory' => $functionInventory,
            'statistics' => $statistics,
            'quality_metrics' => $qualityMetrics
        ];
    }

    /**
     * Performs incremental analysis for changed files only
     * Updates existing inventory with new analysis while preserving progress
     *
     * @param array $changedFiles List of files that have been modified
     * @return void
     */
    public function incrementalAnalysis(array $changedFiles): void
    {
        try {
            $inventory = $this->database->read('inventory');
            $existingFunctions = $inventory['functions'] ?? [];
            
            foreach ($changedFiles as $filePath) {
                $analysis = $this->analyzeFile($filePath);
                
                // Remove old functions for this file
                $existingFunctions = array_filter(
                    $existingFunctions,
                    fn($func) => $func['file_path'] !== $filePath
                );
                
                // Add new functions from analysis
                $newFunctions = $this->createFunctionInventory([$analysis]);
                $existingFunctions = array_merge($existingFunctions, $newFunctions);
            }
            
            // Update inventory functions
            $inventory['functions'] = array_values($existingFunctions);

            // Update file inventory for the changed file
            $updatedFileInventory = $this->createFileInventory([$analysis]);
            $updatedFileData = reset($updatedFileInventory) ?: [];
            $fileKey = key($updatedFileInventory);

            if ($fileKey !== null) {
                $inventory['files'][$fileKey] = $updatedFileData;
            }

            // Refresh class inventory for the changed file
            $existingClasses = array_filter(
                $inventory['classes'] ?? [],
                fn(array $class) => ($class['file_path'] ?? '') !== $filePath
            );
            $inventory['classes'] = array_values(array_merge(
                $existingClasses,
                $this->collectClassesForFile($analysis)
            ));

            // Refresh statistics summary
            $inventory['stats']['total_files'] = count($inventory['files'] ?? []);
            $inventory['stats']['total_functions'] = count($inventory['functions'] ?? []);
            $inventory['stats']['analysis_completed_at'] = date('c');
            $inventory['stats']['analysis_duration'] = $inventory['stats']['analysis_duration'] ?? 0;
            $inventory['stats']['last_incremental_analysis'] = date('c');

            $this->database->write('inventory', $inventory);

        } catch (\Exception $e) {
            error_log("Incremental analysis failed: " . $e->getMessage());
            throw new \RuntimeException("Failed to perform incremental analysis: " . $e->getMessage());
        }
    }

    /**
     * Validates analysis results for completeness and consistency
     * Ensures all required data is present and properly structured
     *
     * @param AnalysisResult $result Analysis results to validate
     * @return bool True if results are valid, false otherwise
     */
    private function validateResults(AnalysisResult $result): bool
    {
        $statistics = $result->getStatistics();
        $functionInventory = $result->getFunctionInventory();
        
        // Check required fields
        if (!isset($statistics['total_files']) || !isset($statistics['total_functions'])) {
            return false;
        }
        
        // Validate function inventory structure
        foreach ($functionInventory as $function) {
            if (!isset($function['id']) || !isset($function['name']) || !isset($function['file_path'])) {
                return false;
            }
        }
        
        // Validate consistency
        if (count($functionInventory) !== $statistics['total_functions']) {
            return false;
        }
        
        return true;
    }

    /**
     * Reads and parses .claude.md instruction file
     * Extracts analysis rules and project-specific requirements
     *
     * @param string $projectRoot Project root directory
     * @return array Parsed instruction configuration
     */
    private function readInstructions(string $projectRoot): array
    {
        $instructionFile = $projectRoot . '/.claude.md';
        $instructions = [
            'analysis_rules' => [],
            'project_type' => 'mixed',
            'special_requirements' => []
        ];
        
        if (!file_exists($instructionFile)) {
            return $instructions;
        }
        
        try {
            $content = file_get_contents($instructionFile);
            
            // Basic parsing of markdown content
            if (preg_match('/## Analysis Rules\s*\n([\s\S]*?)(?=\n##|$)/', $content, $matches)) {
                $rulesText = trim($matches[1]);
                $instructions['analysis_rules'] = array_filter(array_map('trim', explode('\n', $rulesText)));
            }
            
            if (preg_match('/Project Type:\s*(\w+)/', $content, $matches)) {
                $instructions['project_type'] = strtolower($matches[1]);
            }
            
        } catch (\Exception $e) {
            error_log("Failed to parse .claude.md: " . $e->getMessage());
        }
        
        return $instructions;
    }

    /**
     * Saves analysis results to the database
     *
     * @param array $results Compiled analysis results
     * @param float $completionTime Analysis completion time
     * @return void
     */
    private function saveResults(array $results, float $completionTime, array $fileAnalyses): void
    {
        try {
            $fileInventory = $this->createFileInventory($fileAnalyses);
            $classInventory = $this->createClassInventory($fileAnalyses);

            // Save inventory
            $inventoryData = [
                'files' => $fileInventory,
                'functions' => $results['function_inventory'],
                'classes' => $classInventory,
                'stats' => array_merge($results['statistics'], [
                    'analysis_completed_at' => date('c'),
                    'analysis_duration' => $completionTime
                ])
            ];
            
            $this->database->write('inventory', $inventoryData);
            
            // Update project data
            $projectData = $this->database->read('project');
            $projectData['last_analysis'] = date('c');
            $projectData['analysis_stats'] = $results['statistics'];
            $this->database->write('project', $projectData);
            
        } catch (\Exception $e) {
            error_log("Failed to save analysis results: " . $e->getMessage());
            throw new \RuntimeException("Failed to save analysis results: " . $e->getMessage());
        }
    }

    /**
     * Builds per-file inventory entries including functions and metadata.
     *
     * @param FileAnalysis[] $fileAnalyses
     * @return array
     */
    private function createFileInventory(array $fileAnalyses): array
    {
        $inventory = [];
        $projectRoot = rtrim((string) $this->config->get('paths.project_root', ''), DIRECTORY_SEPARATOR);

        foreach ($fileAnalyses as $fileAnalysis) {
            if (!$fileAnalysis instanceof FileAnalysis) {
                continue;
            }

            $filePath = $fileAnalysis->getFilePath();
            $functions = $this->collectFunctionsForFile($fileAnalysis);
            $relativePath = $this->makeRelativePath($filePath, $projectRoot);

            $inventory[$filePath] = [
                'path' => $filePath,
                'relative_path' => $relativePath,
                'file_type' => $fileAnalysis->getFileType(),
                'language' => $this->resolveLanguage($fileAnalysis->getFileType()),
                'functions' => $functions,
                'total_functions' => count($functions),
                'functions_analyzed' => count($functions),
                'classes' => $fileAnalysis->getClasses(),
                'metrics' => $fileAnalysis->getMetrics(),
                'issues' => $fileAnalysis->getIssues(),
                'dependencies' => $fileAnalysis->getDependencies(),
                'last_analyzed_at' => date('c')
            ];
        }

        return array_values($inventory);
    }

    /**
     * Collects class entries for inventory storage with file references.
     *
     * @param FileAnalysis[] $fileAnalyses
     * @return array
     */
    private function createClassInventory(array $fileAnalyses): array
    {
        $classes = [];

        foreach ($fileAnalyses as $fileAnalysis) {
            if (!$fileAnalysis instanceof FileAnalysis) {
                continue;
            }

            foreach ($fileAnalysis->getClasses() as $class) {
                $classData = $class;
                $classData['file_path'] = $fileAnalysis->getFilePath();
                $classes[] = $classData;
            }
        }

        return $classes;
    }

    /**
     * Collects all functions (standalone + methods) for a file.
     */
    private function collectFunctionsForFile(FileAnalysis $fileAnalysis): array
    {
        $functions = [];
        $filePath = $fileAnalysis->getFilePath();

        foreach ($fileAnalysis->getFunctions() as $function) {
            $functions[] = $function + [
                'type' => 'function',
                'id' => $this->shiftFunctionIdForFile($filePath)
            ];
        }

        foreach ($fileAnalysis->getClasses() as $class) {
            $className = $class['name'] ?? null;
            foreach ($class['methods'] ?? [] as $method) {
                $functions[] = array_merge($method, [
                    'class' => $className,
                    'type' => 'method',
                    'id' => $this->shiftFunctionIdForFile($filePath)
                ]);
            }
        }

        return $functions;
    }

    /**
     * Collects class inventory entries for a single file analysis.
     */
    private function collectClassesForFile(FileAnalysis $fileAnalysis): array
    {
        $classes = [];

        foreach ($fileAnalysis->getClasses() as $class) {
            $classData = $class;
            $classData['file_path'] = $fileAnalysis->getFilePath();
            $classes[] = $classData;
        }

        return $classes;
    }

    /**
     * Converts an absolute path into a project-relative path when possible.
     */
    private function makeRelativePath(string $path, string $projectRoot): string
    {
        if ($projectRoot === '' || !str_starts_with($path, $projectRoot)) {
            return $path;
        }

        $relative = substr($path, strlen($projectRoot));
        return ltrim($relative, DIRECTORY_SEPARATOR);
    }

    /**
     * Maps file extensions to human-readable language labels.
     */
    private function resolveLanguage(string $fileType): string
    {
        return match (strtolower($fileType)) {
            'php' => 'php',
            'py' => 'python',
            'js' => 'javascript',
            'ts' => 'typescript',
            'jsx' => 'javascript',
            'tsx' => 'typescript',
            default => strtolower($fileType)
        };
    }

    /**
     * Retrieves the next generated function identifier for a file.
     */
    private function shiftFunctionIdForFile(string $filePath): ?int
    {
        if (!isset($this->functionIdLookup[$filePath]) || empty($this->functionIdLookup[$filePath])) {
            return null;
        }

        $id = array_shift($this->functionIdLookup[$filePath]);

        if (empty($this->functionIdLookup[$filePath])) {
            unset($this->functionIdLookup[$filePath]);
        }

        return $id;
    }

    /**
     * Simple pattern-based exclusion check
     *
     * @param string $relativePath The relative path of the file
     * @param string $projectRoot The project root directory
     * @return bool True if file should be excluded, false otherwise
     */
    private function shouldExcludeFile(string $relativePath, string $projectRoot): bool
    {
        // Quick directory name checks (most common exclusions)
        $pathParts = explode('/', $relativePath);
        foreach ($pathParts as $part) {
            if (in_array($part, [
                'vendor', 'node_modules', '.git', 'cache', 'uploads', 'backups',
                '.cache', '.npm', '.pyenv', '.local', '.config', '.cpm', '.claude', '.claude-project',
                'wp-admin', 'wp-includes', 'languages', 'upgrade', 'logs', 'tmp', 'temp'
            ])) {
                return true;
            }
        }

        // WordPress parent theme detection
        if (str_contains($relativePath, 'wp-content/themes/')) {
            // Keep child themes only (ending with -child)
            preg_match('#wp-content/themes/([^/]+)/#', $relativePath, $matches);
            if (!empty($matches[1]) && !str_ends_with($matches[1], '-child')) {
                return true;
            }
        }

        // Check config exclusion patterns
        $exclusionPatterns = $this->config->getExclusionPatterns();
        foreach ($exclusionPatterns as $pattern) {
            $cleanPattern = preg_replace('#/\*\*/\*$#', '', $pattern);

            // Simple wildcard matching
            if (fnmatch($cleanPattern, $relativePath)) {
                return true;
            }

            // Check if path starts with pattern (for directory patterns)
            if (!str_contains($cleanPattern, '*') && str_starts_with($relativePath, $cleanPattern)) {
                return true;
            }
        }

        return false;
    }

}
