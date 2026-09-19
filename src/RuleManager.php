<?php

declare(strict_types=1);

namespace ClaudeProjectManager;

/**
 * Manages flexible rule enforcement for legacy vs new code
 * Handles enabling/disabling checks based on file age and origin
 */
class RuleManager
{
    private DatabaseManager $database;
    private ConfigManager $config;
    private array $ruleOverrides;
    private array $fileOriginTracker;

    /**
     * Initializes rule manager with configuration and database
     *
     * @param DatabaseManager $database Database for storing rule overrides
     * @param ConfigManager $config Configuration manager for default rules
     */
    public function __construct(DatabaseManager $database, ConfigManager $config)
    {
        $this->database = $database;
        $this->config = $config;
        $this->ruleOverrides = [];
        $this->fileOriginTracker = [];
        
        $this->loadRuleOverrides();
        $this->loadFileOriginData();
    }

    /**
     * Determines which rules should be applied to a specific file
     *
     * @param string $filePath Path to the file being analyzed
     * @param array $defaultRules Default rules from configuration
     * @return array Effective rules to apply for this file
     */
    public function getEffectiveRules(string $filePath, array $defaultRules): array
    {
        $origin = $this->getFileOrigin($filePath);
        $rules = $defaultRules;
        
        switch ($origin['type']) {
            case 'legacy':
                $rules = $this->applyLegacyRules($rules);
                break;
            case 'claude_generated':
            case 'claude_modified':
                $rules = $this->applyStrictRules($rules);
                break;
            case 'user_new':
                // Apply standard rules
                break;
        }
        
        // Apply any manual overrides
        $overrides = $this->getOverridesForFile($filePath);
        foreach ($overrides as $rule => $enabled) {
            if ($enabled) {
                $rules[$rule] = $defaultRules[$rule] ?? true;
            } else {
                unset($rules[$rule]);
            }
        }
        
        return $rules;
    }

    /**
     * Tracks the origin of a file (user-created vs Claude-generated)
     *
     * @param string $filePath Path to the file
     * @param string $origin Origin type (legacy, user_new, claude_generated, claude_modified)
     * @param array $metadata Additional metadata about the file origin
     * @return void
     */
    public function trackFileOrigin(string $filePath, string $origin, array $metadata = []): void
    {
        $relativePath = $this->getRelativePath($filePath);
        
        $this->fileOriginTracker[$relativePath] = [
            'type' => $origin,
            'timestamp' => time(),
            'metadata' => $metadata,
            'last_modified' => filemtime($filePath) ?: time()
        ];
        
        $this->saveFileOriginData();
    }

    /**
     * Enables or disables specific rules for a file or pattern
     *
     * @param string $scope Scope for the rule (file path or pattern)
     * @param array $rules Rules to enable/disable
     * @param bool $enabled Whether to enable (true) or disable (false)
     * @param string $reason Reason for the rule change
     * @return void
     */
    public function setRuleOverride(string $scope, array $rules, bool $enabled, string $reason = ''): void
    {
        if (!isset($this->ruleOverrides[$scope])) {
            $this->ruleOverrides[$scope] = [
                'rules' => [],
                'created_at' => time(),
                'reason' => $reason
            ];
        }
        
        foreach ($rules as $ruleName) {
            $this->ruleOverrides[$scope]['rules'][$ruleName] = $enabled;
        }
        
        $this->ruleOverrides[$scope]['updated_at'] = time();
        $this->ruleOverrides[$scope]['reason'] = $reason;
        
        $this->saveRuleOverrides();
    }

    /**
     * Checks if a specific rule should be enforced for a file
     *
     * @param string $filePath Path to the file
     * @param string $ruleName Name of the rule to check
     * @return bool True if rule should be enforced, false otherwise
     */
    public function shouldEnforceRule(string $filePath, string $ruleName): bool
    {
        $overrides = $this->getOverridesForFile($filePath);
        
        if (isset($overrides[$ruleName])) {
            return $overrides[$ruleName];
        }
        
        $origin = $this->getFileOrigin($filePath);
        
        // Legacy files have relaxed rules
        if ($origin['type'] === 'legacy') {
            $lenientRules = ['max_file_size', 'max_function_length', 'require_documentation'];
            if (in_array($ruleName, $lenientRules)) {
                return false;
            }
        }
        
        return true;
    }

    /**
     * Gets file origin information
     *
     * @param string $filePath Path to the file
     * @return array File origin data with metadata
     */
    public function getFileOrigin(string $filePath): array
    {
        $relativePath = $this->getRelativePath($filePath);
        
        if (isset($this->fileOriginTracker[$relativePath])) {
            return $this->fileOriginTracker[$relativePath];
        }
        
        // Analyze legacy status if not tracked
        $legacyAnalysis = $this->analyzeLegacyStatus($filePath);
        
        return [
            'type' => $legacyAnalysis['is_legacy'] ? 'legacy' : 'user_new',
            'timestamp' => filemtime($filePath) ?: time(),
            'metadata' => $legacyAnalysis,
            'confidence' => $legacyAnalysis['confidence'] ?? 0.5
        ];
    }

    /**
     * Marks file as modified by Claude Code
     *
     * @param string $filePath Path to the file modified
     * @param array $modifications Description of modifications made
     * @return void
     */
    public function markClaudeModification(string $filePath, array $modifications): void
    {
        $this->trackFileOrigin($filePath, 'claude_modified', [
            'modifications' => $modifications,
            'session_id' => uniqid('session_', true)
        ]);
    }

    /**
     * Creates rule preset for different file categories
     *
     * @param string $category Category name (legacy, new, claude_generated)
     * @param array $rules Rule configuration for the category
     * @return void
     */
    public function createRulePreset(string $category, array $rules): void
    {
        $this->config->set('rules.presets.' . $category, $rules);
        $this->config->save();
    }

    /**
     * Gets all rule overrides and their current status
     *
     * @return array Complete list of rule overrides
     */
    public function getRuleOverrides(): array
    {
        return $this->ruleOverrides;
    }

    /**
     * Analyzes file to determine if it's legacy code
     *
     * @param string $filePath Path to the file
     * @return array Analysis results with legacy determination
     */
    public function analyzeLegacyStatus(string $filePath): array
    {
        $result = [
            'is_legacy' => false,
            'confidence' => 0.5,
            'reasons' => []
        ];
        
        if (!file_exists($filePath)) {
            return $result;
        }
        
        $fileSize = filesize($filePath);
        $lineCount = count(file($filePath));
        
        // Large files are likely legacy
        if ($lineCount > 1000) {
            $result['is_legacy'] = true;
            $result['confidence'] += 0.3;
            $result['reasons'][] = 'File is large (' . $lineCount . ' lines)';
        }
        
        // Check if project was recently analyzed
        try {
            $projectData = $this->database->read('project');
            $analysisDate = strtotime($projectData['created_at'] ?? 'now');
            $fileModified = filemtime($filePath);
            
            if ($fileModified < $analysisDate - 3600) { // Modified more than 1 hour before analysis
                $result['is_legacy'] = true;
                $result['confidence'] += 0.4;
                $result['reasons'][] = 'File existed before project analysis';
            }
        } catch (\Exception $e) {
            // If we can't read project data, assume medium confidence
        }
        
        $result['confidence'] = min(1.0, $result['confidence']);
        
        return $result;
    }

    /**
     * Applies lenient rules for legacy code files
     *
     * @param array $defaultRules Standard rule set
     * @return array Modified rules for legacy code
     */
    private function applyLegacyRules(array $defaultRules): array
    {
        $rules = $defaultRules;
        
        // Remove or relax strict size limits
        unset($rules['max_file_size']);
        unset($rules['max_function_length']);
        unset($rules['require_documentation']);
        unset($rules['require_return_types']);
        unset($rules['require_declare_strict_types']);
        
        // Keep critical rules
        $criticalRules = [
            'no_sql_injection',
            'no_xss_vulnerabilities',
            'no_unused_variables',
            'no_syntax_errors'
        ];
        
        foreach ($criticalRules as $rule) {
            if (isset($defaultRules[$rule])) {
                $rules[$rule] = $defaultRules[$rule];
            }
        }
        
        return $rules;
    }

    /**
     * Applies strict rules for new/Claude-generated code
     *
     * @param array $defaultRules Standard rule set
     * @return array Enhanced rules for new code
     */
    private function applyStrictRules(array $defaultRules): array
    {
        $rules = $defaultRules;
        
        // Enforce all strict rules
        $rules['max_file_size'] = 500;
        $rules['max_function_length'] = 100;
        $rules['require_documentation'] = true;
        $rules['require_return_types'] = true;
        $rules['require_declare_strict_types'] = true;
        $rules['enforce_type_hints'] = true;
        $rules['require_parameter_docs'] = true;
        $rules['enforce_naming_conventions'] = true;
        
        return $rules;
    }

    /**
     * Removes rule override for specified scope
     *
     * @param string $scope Scope to remove override for
     * @param string $reason Reason for removing override
     * @return void
     */
    public function removeRuleOverride(string $scope, string $reason = ''): void
    {
        if (isset($this->ruleOverrides[$scope])) {
            unset($this->ruleOverrides[$scope]);
            $this->saveRuleOverrides();
        }
    }

    /**
     * Gets relative path from project root
     *
     * @param string $filePath Absolute file path
     * @return string Relative path
     */
    private function getRelativePath(string $filePath): string
    {
        $projectRoot = $this->config->get('paths.project_root', getcwd());
        return str_replace($projectRoot . '/', '', $filePath);
    }

    /**
     * Gets rule overrides for a specific file
     *
     * @param string $filePath Path to the file
     * @return array Rule overrides for this file
     */
    private function getOverridesForFile(string $filePath): array
    {
        $relativePath = $this->getRelativePath($filePath);
        $overrides = [];

        // Check exact file match
        if (isset($this->ruleOverrides[$relativePath])) {
            $overrides = array_merge($overrides, $this->ruleOverrides[$relativePath]['rules']);
        }

        // Check pattern matches
        foreach ($this->ruleOverrides as $scope => $data) {
            if (fnmatch($scope, $relativePath)) {
                $overrides = array_merge($overrides, $data['rules']);
            }
        }

        return $overrides;
    }

    /**
     * Loads rule overrides from database
     *
     * @return void
     */
    private function loadRuleOverrides(): void
    {
        try {
            $data = $this->database->read('project');
            $this->ruleOverrides = $data['rule_overrides'] ?? [];
        } catch (\Exception $e) {
            $this->ruleOverrides = [];
        }
    }

    /**
     * Saves rule overrides to database
     *
     * @return void
     */
    private function saveRuleOverrides(): void
    {
        try {
            $this->database->update('project', 'rule_overrides', $this->ruleOverrides);
        } catch (\Exception $e) {
            // Log error but don't throw - this is not critical
            error_log("Failed to save rule overrides: " . $e->getMessage());
        }
    }

    /**
     * Loads file origin tracking data from database
     *
     * @return void
     */
    private function loadFileOriginData(): void
    {
        try {
            $data = $this->database->read('project');
            $this->fileOriginTracker = $data['file_origins'] ?? [];
        } catch (\Exception $e) {
            $this->fileOriginTracker = [];
        }
    }

    /**
     * Saves file origin tracking data to database
     *
     * @return void
     */
    private function saveFileOriginData(): void
    {
        try {
            $this->database->update('project', 'file_origins', $this->fileOriginTracker);
        } catch (\Exception $e) {
            // Log error but don't throw - this is not critical
            error_log("Failed to save file origin data: " . $e->getMessage());
        }
    }
}