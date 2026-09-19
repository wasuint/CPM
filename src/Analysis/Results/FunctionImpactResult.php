<?php

declare(strict_types=1);

namespace ClaudeProjectManager\Analysis\Results;

/**
 * Represents the result of function-specific impact analysis
 * Contains information about how changing a specific function might affect the project
 */
class FunctionImpactResult
{
    public string $functionName;
    public string $declaringFile;
    public array $usedInFiles;
    public int $usageCount;
    public bool $isPublicApi;
    public string $breakingChangeRisk;
    public array $suggestions;
    
    public function __construct(array $data)
    {
        $this->functionName = $data['function_name'] ?? '';
        $this->declaringFile = $data['declaring_file'] ?? '';
        $this->usedInFiles = $data['used_in_files'] ?? [];
        $this->usageCount = $data['usage_count'] ?? 0;
        $this->isPublicApi = $data['is_public_api'] ?? false;
        $this->breakingChangeRisk = $data['breaking_change_risk'] ?? 'UNKNOWN';
        $this->suggestions = $data['suggestions'] ?? [];
    }
    
    /**
     * Format result for CLI display
     * @return array Formatted lines for console output
     */
    public function formatForCli(): array
    {
        $lines = [
            "🔧 Function Impact Analysis: {$this->functionName}",
            "Declared in: {$this->declaringFile}",
            "Usage Count: {$this->usageCount} files",
            "Public API: " . ($this->isPublicApi ? "✅ Yes" : "❌ No"),
            "Breaking Change Risk: " . $this->getRiskEmoji() . " {$this->breakingChangeRisk}",
        ];
        
        if (!empty($this->usedInFiles)) {
            $lines[] = "";
            $lines[] = "📁 Used in Files:";
            foreach ($this->usedInFiles as $file) {
                $lines[] = "  • $file";
            }
        }
        
        if (!empty($this->suggestions)) {
            $lines[] = "";
            $lines[] = "💡 Suggestions:";
            foreach ($this->suggestions as $suggestion) {
                $lines[] = "  $suggestion";
            }
        }
        
        return $lines;
    }
    
    /**
     * Convert to array for storage/serialization
     * @return array Array representation
     */
    public function toArray(): array
    {
        return [
            'function_name' => $this->functionName,
            'declaring_file' => $this->declaringFile,
            'used_in_files' => $this->usedInFiles,
            'usage_count' => $this->usageCount,
            'is_public_api' => $this->isPublicApi,
            'breaking_change_risk' => $this->breakingChangeRisk,
            'suggestions' => $this->suggestions
        ];
    }
    
    /**
     * Get emoji for risk level
     * @return string Appropriate emoji
     */
    private function getRiskEmoji(): string
    {
        return match ($this->breakingChangeRisk) {
            'LOW' => '🟢',
            'MEDIUM' => '🟡',
            'HIGH' => '🔴',
            default => '⚪'
        };
    }
    
    /**
     * Check if function is safe to modify
     * @return bool True if safe to modify
     */
    public function isSafeToModify(): bool
    {
        return $this->breakingChangeRisk === 'LOW' || 
               ($this->usageCount === 0 && !$this->isPublicApi);
    }
    
    /**
     * Get formatted summary line
     * @return string Single line summary
     */
    public function getSummary(): string
    {
        $apiStatus = $this->isPublicApi ? 'Public' : 'Private';
        return "Function: {$this->functionName} | Usage: {$this->usageCount} files | Risk: {$this->breakingChangeRisk} | $apiStatus API";
    }
}