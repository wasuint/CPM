<?php

declare(strict_types=1);

namespace ClaudeProjectManager\Services;

use ClaudeProjectManager\DatabaseManager;

class DocumentationCompactor
{
    private DatabaseManager $database;
    private string $projectRoot;
    private array $compactionRules;
    
    public function __construct(DatabaseManager $database, string $projectRoot)
    {
        $this->database = $database;
        $this->projectRoot = $projectRoot;
        $this->compactionRules = [
            'max_file_size' => 50000, // 50KB
            'target_size' => 30000,    // 30KB target after compaction
            'priority_sections' => [
                'critical_rules',
                'project_structure',
                'key_patterns',
                'active_tasks',
                'recent_changes'
            ]
        ];
    }

    /**
     * Check if a file needs compaction
     */
    public function needsCompaction(string $filePath): bool
    {
        if (!file_exists($filePath)) {
            return false;
        }
        
        $size = filesize($filePath);
        return $size > $this->compactionRules['max_file_size'];
    }

    /**
     * Compact .claude.md file while preserving essential information
     */
    public function compactClaudeMd(): array
    {
        $filePath = $this->projectRoot . '/.claude.md';
        
        if (!file_exists($filePath)) {
            return ['success' => false, 'message' => '.claude.md not found'];
        }
        
        $content = file_get_contents($filePath);
        $originalSize = strlen($content);
        
        // Parse sections
        $sections = $this->parseSections($content);
        
        // Prioritize and compact
        $compactedSections = $this->prioritizeAndCompact($sections);
        
        // Rebuild document
        $newContent = $this->rebuildDocument($compactedSections);
        
        // Add compaction notice
        $newContent = "<!-- Automatically compacted by CPM on " . date('Y-m-d H:i:s') . " -->\n" . $newContent;
        
        // Save backup
        $backupPath = $filePath . '.backup.' . date('Ymd_His');
        copy($filePath, $backupPath);
        
        // Write compacted version
        file_put_contents($filePath, $newContent);
        
        $newSize = strlen($newContent);
        $reduction = round((1 - $newSize / $originalSize) * 100, 1);
        
        return [
            'success' => true,
            'original_size' => $originalSize,
            'new_size' => $newSize,
            'reduction' => $reduction,
            'backup_path' => $backupPath
        ];
    }

    /**
     * Compact context files (DIGEST.md, STATE.json)
     */
    public function compactContextFiles(): array
    {
        $results = [];
        
        // Compact DIGEST.md
        $digestPath = $this->projectRoot . '/.cpm/context/DIGEST.md';
        if ($this->needsCompaction($digestPath)) {
            $results['digest'] = $this->compactDigest($digestPath);
        }
        
        // Compact STATE.json (more aggressive)
        $statePath = $this->projectRoot . '/.cpm/context/STATE.json';
        if (file_exists($statePath) && filesize($statePath) > 100000) { // 100KB for JSON
            $results['state'] = $this->compactState($statePath);
        }
        
        return $results;
    }

    /**
     * Parse markdown content into sections
     */
    private function parseSections(string $content): array
    {
        $sections = [];
        $lines = explode("\n", $content);
        $currentSection = 'preamble';
        $currentContent = [];
        
        foreach ($lines as $line) {
            if (preg_match('/^#+\s+(.+)$/', $line, $matches)) {
                // Save previous section
                if (!empty($currentContent)) {
                    $sections[$currentSection] = implode("\n", $currentContent);
                }
                
                // Start new section
                $currentSection = $this->normalizeSection($matches[1]);
                $currentContent = [$line];
            } else {
                $currentContent[] = $line;
            }
        }
        
        // Save last section
        if (!empty($currentContent)) {
            $sections[$currentSection] = implode("\n", $currentContent);
        }
        
        return $sections;
    }

    /**
     * Normalize section names for matching
     */
    private function normalizeSection(string $section): string
    {
        return strtolower(preg_replace('/[^a-z0-9]+/i', '_', $section));
    }

    /**
     * Prioritize and compact sections
     */
    private function prioritizeAndCompact(array $sections): array
    {
        $compacted = [];
        $currentSize = 0;
        $targetSize = $this->compactionRules['target_size'];
        
        // First pass: Include priority sections
        foreach ($this->compactionRules['priority_sections'] as $priority) {
            foreach ($sections as $name => $content) {
                if (stripos($name, $priority) !== false) {
                    $compactedContent = $this->compactContent($content);
                    $compacted[$name] = $compactedContent;
                    $currentSize += strlen($compactedContent);
                    unset($sections[$name]);
                }
            }
        }
        
        // Second pass: Add remaining sections if space allows
        foreach ($sections as $name => $content) {
            if ($currentSize >= $targetSize) {
                break;
            }
            
            $compactedContent = $this->compactContent($content, true); // More aggressive
            if ($currentSize + strlen($compactedContent) <= $targetSize) {
                $compacted[$name] = $compactedContent;
                $currentSize += strlen($compactedContent);
            }
        }
        
        return $compacted;
    }

    /**
     * Compact individual content sections
     */
    private function compactContent(string $content, bool $aggressive = false): string
    {
        // Remove extra whitespace
        $content = preg_replace('/\n\s*\n\s*\n/', "\n\n", $content);
        
        // Remove comments
        $content = preg_replace('/<!--.*?-->/s', '', $content);
        
        if ($aggressive) {
            // Remove code examples if too long
            $content = preg_replace('/```[\s\S]{500,}?```/', '```\n[Code example removed for brevity]\n```', $content);
            
            // Truncate long lists
            $lines = explode("\n", $content);
            $newLines = [];
            $listCount = 0;
            
            foreach ($lines as $line) {
                if (preg_match('/^\s*[-*+]\s/', $line)) {
                    $listCount++;
                    if ($listCount <= 5) {
                        $newLines[] = $line;
                    } elseif ($listCount == 6) {
                        $newLines[] = '  ... [additional items truncated]';
                    }
                } else {
                    $listCount = 0;
                    $newLines[] = $line;
                }
            }
            
            $content = implode("\n", $newLines);
        }
        
        return trim($content);
    }

    /**
     * Rebuild document from compacted sections
     */
    private function rebuildDocument(array $sections): string
    {
        $document = [];
        
        // Add header
        $document[] = "# Project Documentation (Compacted)\n";
        $document[] = "*Auto-maintained by Claude Project Manager v1.0.7*\n";
        
        foreach ($sections as $name => $content) {
            $document[] = $content;
            $document[] = "";
        }
        
        // Add footer with guidance
        $document[] = "\n---\n";
        $document[] = "## 📝 Claude Code: Documentation Maintenance";
        $document[] = "- This file was automatically compacted to improve performance";
        $document[] = "- Update this file when discovering new patterns or conventions";
        $document[] = "- Keep content concise and focused on essential project knowledge";
        $document[] = "- Run `cpm context:digest` to refresh context";
        
        return implode("\n", $document);
    }

    /**
     * Compact DIGEST.md file
     */
    private function compactDigest(string $filePath): array
    {
        $content = file_get_contents($filePath);
        $originalSize = strlen($content);
        
        // Focus on current state, remove historical data
        $lines = explode("\n", $content);
        $compacted = [];
        $inHistoricalSection = false;
        
        foreach ($lines as $line) {
            if (preg_match('/recent|history|previous/i', $line)) {
                $inHistoricalSection = true;
            } elseif (preg_match('/^#+/', $line)) {
                $inHistoricalSection = false;
            }
            
            if (!$inHistoricalSection) {
                $compacted[] = $line;
            }
        }
        
        $newContent = implode("\n", $compacted);
        file_put_contents($filePath, $newContent);
        
        return [
            'original_size' => $originalSize,
            'new_size' => strlen($newContent),
            'reduction' => round((1 - strlen($newContent) / $originalSize) * 100, 1)
        ];
    }

    /**
     * Compact STATE.json file
     */
    private function compactState(string $filePath): array
    {
        $state = json_decode(file_get_contents($filePath), true);
        $originalSize = filesize($filePath);
        
        // Remove completed tasks older than 7 days
        if (isset($state['progress']['by_function'])) {
            foreach ($state['progress']['by_function'] as $key => $function) {
                if ($function['status'] === 'completed' && 
                    isset($function['updated_at']) &&
                    (time() - strtotime($function['updated_at']) > 604800)) {
                    unset($state['progress']['by_function'][$key]);
                }
            }
        }
        
        // Remove old session data
        if (isset($state['sessions']['sessions'])) {
            $sessions = $state['sessions']['sessions'];
            uasort($sessions, function($a, $b) {
                return $b['startTime'] - $a['startTime'];
            });
            $state['sessions']['sessions'] = array_slice($sessions, 0, 5, true);
        }
        
        // Remove verbose activity logs
        if (isset($state['activity_log'])) {
            $state['activity_log'] = array_slice($state['activity_log'], -50);
        }
        
        // Use compact JSON without pretty printing to save space
        $newContent = json_encode($state);
        file_put_contents($filePath, $newContent);
        
        return [
            'original_size' => $originalSize,
            'new_size' => strlen($newContent),
            'reduction' => round((1 - strlen($newContent) / $originalSize) * 100, 1)
        ];
    }
}
