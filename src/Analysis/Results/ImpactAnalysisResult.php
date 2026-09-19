<?php

declare(strict_types=1);

namespace ClaudeProjectManager\Analysis\Results;

/**
 * Represents the result of file impact analysis
 * Contains all information about how changing a file might affect the project
 */
class ImpactAnalysisResult
{
    public string $targetFile;
    public array $directlyAffected;
    public array $indirectlyAffected; 
    public array $exportedFunctions;
    public array $externalUsage;
    public string $impactScore;
    public array $recommendations;
    
    public function __construct(array $data)
    {
        $this->targetFile = $data['target_file'] ?? '';
        $this->directlyAffected = $data['directly_affected'] ?? [];
        $this->indirectlyAffected = $data['indirectly_affected'] ?? [];
        $this->exportedFunctions = $data['exported_functions'] ?? [];
        $this->externalUsage = $data['external_usage'] ?? [];
        $this->impactScore = $data['impact_score'] ?? 'UNKNOWN';
        $this->recommendations = $data['recommendations'] ?? [];
    }
    
    /**
     * Format result for CLI display
     * @return array Formatted lines for console output
     */
    public function formatForCli(): array
    {
        $lines = [
            "🎯 Impact Analysis for: {$this->targetFile}",
            "Impact Level: " . $this->getImpactEmoji() . " {$this->impactScore}",
            "",
            "📊 Summary:",
            "  • Files directly affected: " . count($this->directlyAffected),
            "  • Files indirectly affected: " . count($this->indirectlyAffected),
            "  • Exported functions: " . count($this->exportedFunctions),
            "  • External usage points: " . count($this->externalUsage),
        ];
        
        if (!empty($this->directlyAffected)) {
            $lines[] = "";
            $lines[] = "🔗 Directly Affected Files:";
            foreach ($this->directlyAffected as $file) {
                $lines[] = "  • $file";
            }
        }
        
        if (!empty($this->indirectlyAffected)) {
            $lines[] = "";
            $lines[] = "🔗 Indirectly Affected Files:";
            foreach (array_slice($this->indirectlyAffected, 0, 5) as $file) {
                $lines[] = "  • $file";
            }
            if (count($this->indirectlyAffected) > 5) {
                $remaining = count($this->indirectlyAffected) - 5;
                $lines[] = "  ... and $remaining more files";
            }
        }
        
        if (!empty($this->exportedFunctions)) {
            $lines[] = "";
            $lines[] = "🔧 Exported Functions:";
            foreach ($this->exportedFunctions as $func) {
                $visibility = $func['visibility'] ?? 'public';
                $usage = $func['usage_count'] ?? 0;
                $lines[] = "  • {$func['name']} ($visibility) - used in $usage places";
            }
        }
        
        if (!empty($this->recommendations)) {
            $lines[] = "";
            $lines[] = "💡 Recommendations:";
            foreach ($this->recommendations as $recommendation) {
                $lines[] = "  $recommendation";
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
            'target_file' => $this->targetFile,
            'directly_affected' => $this->directlyAffected,
            'indirectly_affected' => $this->indirectlyAffected,
            'exported_functions' => $this->exportedFunctions,
            'external_usage' => $this->externalUsage,
            'impact_score' => $this->impactScore,
            'recommendations' => $this->recommendations
        ];
    }
    
    /**
     * Get emoji for impact level
     * @return string Appropriate emoji
     */
    private function getImpactEmoji(): string
    {
        return match ($this->impactScore) {
            'LOW' => '🟢',
            'MEDIUM' => '🟡',
            'HIGH' => '🔴',
            default => '⚪'
        };
    }
    
    /**
     * Get total affected files count
     * @return int Total affected files
     */
    public function getTotalAffectedFiles(): int
    {
        return count(array_unique(array_merge($this->directlyAffected, $this->indirectlyAffected)));
    }
    
    /**
     * Check if this is a high-risk change
     * @return bool True if high risk
     */
    public function isHighRisk(): bool
    {
        return $this->impactScore === 'HIGH' || 
               count($this->directlyAffected) > 10 || 
               count($this->exportedFunctions) > 15;
    }
    
    /**
     * Get formatted summary line
     * @return string Single line summary
     */
    public function getSummary(): string
    {
        $totalAffected = $this->getTotalAffectedFiles();
        $functions = count($this->exportedFunctions);
        
        return "Impact: {$this->impactScore} | Affected: $totalAffected files | Functions: $functions";
    }
}