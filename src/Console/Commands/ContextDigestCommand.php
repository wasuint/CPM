<?php
declare(strict_types=1);

namespace ClaudeProjectManager\Console\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use ClaudeProjectManager\ConfigManager;
use ClaudeProjectManager\DatabaseManager;
use ClaudeProjectManager\Services\StateSummaryGenerator;
use ClaudeProjectManager\Intelligence\AiPriorityScorer;
use ClaudeProjectManager\Services\AdaptiveTrackingManager;

/**
 * Generate/update Claude context digest (markdown + merged JSON).
 */
final class ContextDigestCommand extends Command
{
    protected static $defaultName = 'context:digest';
    protected static $defaultDescription = 'Generate/update Claude context digest (markdown + merged JSON).';

    protected function configure(): void
    {
        $this->addOption('out-dir', null, InputOption::VALUE_OPTIONAL, 'Output dir', '.cpm/context');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $root = getcwd() ?: '.';
        $configManager = new ConfigManager($root);
        
        // Use the configured paths
        $contextPath = $configManager->get('paths.context');
        $dbPath = $configManager->get('paths.database');

        // Outside an initialised project there is nothing to digest. Refuse
        // before creating any output directory (D-020).
        if (!is_file($dbPath . '/project.json')) {
            $output->writeln('<error>No CPM project found in ' . $root . '. Run `cpm start` to initialise one.</error>');
            return Command::FAILURE;
        }

        $outDir = $input->getOption('out-dir') 
            ? $root . '/' . trim((string)$input->getOption('out-dir'), '/') 
            : $contextPath;
        
        @mkdir($outDir, 0770, true);
        $db = $dbPath;
        $project  = @file_get_contents($db . '/project.json') ?: '{}';
        $progress = @file_get_contents($db . '/progress.json') ?: '{}';
        $tasks    = @file_get_contents($db . '/tasks.json') ?: '{}';
        $sessions = @file_get_contents($db . '/sessions.json') ?: '{}';

        $merged = json_encode([
            'project'  => json_decode($project, true),
            'progress' => json_decode($progress, true),
            'tasks'    => json_decode($tasks, true),
            'sessions' => json_decode($sessions, true),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if (!is_string($merged)) {
            $output->writeln('<error>Failed to merge CPM JSON state.</error>');
            return Command::FAILURE;
        }

        $jsonOut = $outDir . '/STATE.json';
        $mdOut   = $outDir . '/DIGEST.md';

        @file_put_contents($jsonOut, $merged);
        @chmod($jsonOut, 0660);

        // Generate rich summary using StateSummaryGenerator
        $dbManager = new DatabaseManager($root);
        $summaryGenerator = new StateSummaryGenerator($dbManager);
        $summaryData = $summaryGenerator->generateSummary();

        // Journal (issues + checkpoints) is the primary session-handoff data
        $journal = $this->buildJournalData($dbManager);

        // Honest function-tracking state: only show detail when fresh & enabled
        $tracking = $this->assessTrackingState($configManager, $summaryData);

        if ($tracking['active']) {
            // Add AI priority and tracking data (function-tracking dependent)
            $summaryData['ai_enhancements'] = $this->generateAiEnhancements($dbManager);
        }

        $summary = $this->generateRichDigest($summaryData, $journal, $tracking);
        @file_put_contents($mdOut, $summary);
        @chmod($mdOut, 0660);

        $output->writeln("<info>Digest written:</info> $mdOut");
        $output->writeln("<info>State written:</info>  $jsonOut");
        return Command::SUCCESS;
    }
    
    /**
     * Collect journal data (issues + checkpoints) — the part of CPM that is
     * actively used for session handoff.
     */
    private function buildJournalData(DatabaseManager $db): array
    {
        $journal = [
            'in_progress' => [],
            'blocked' => [],
            'open' => [],
            'checkpoints' => [],
            'total_open' => 0
        ];

        try {
            $issuesData = $db->read('issues');
            foreach ($issuesData['issues'] ?? [] as $issue) {
                switch ($issue['status'] ?? 'open') {
                    case 'in_progress':
                        $journal['in_progress'][] = $issue;
                        break;
                    case 'blocked':
                        $journal['blocked'][] = $issue;
                        break;
                    case 'open':
                        $journal['open'][] = $issue;
                        break;
                }
            }

            $priorityRank = ['critical' => 0, 'high' => 1, 'medium' => 2, 'low' => 3];
            usort($journal['open'], fn($a, $b) =>
                ($priorityRank[$a['priority'] ?? 'medium'] ?? 2) <=> ($priorityRank[$b['priority'] ?? 'medium'] ?? 2));

            $journal['total_open'] = count($journal['open']) + count($journal['in_progress']) + count($journal['blocked']);
        } catch (\Exception $e) {
            // no issues database yet
        }

        try {
            $checkpointData = $db->read('checkpoints');
            $journal['checkpoints'] = array_reverse(array_slice($checkpointData['checkpoints'] ?? [], -3));
        } catch (\Exception $e) {
            // no checkpoints database yet
        }

        return $journal;
    }

    /**
     * Assess whether function tracking is enabled and fresh enough to be
     * trustworthy. Stale tracking data used to be presented as "Fresh project
     * analysis complete", which taught agents to distrust the digest.
     */
    private function assessTrackingState(ConfigManager $config, array $summaryData): array
    {
        $enabled = filter_var($config->get('tracking.enabled', true), FILTER_VALIDATE_BOOL);

        $analyzedAt = $summaryData['project_overview']['analyzed_at'] ?? null;
        $analyzedTs = $analyzedAt ? strtotime($analyzedAt) : false;
        $ageDays = $analyzedTs !== false ? (int) floor((time() - $analyzedTs) / 86400) : null;
        $maxAge = (int) ($config->get('tracking.max_age_days', 14) ?: 14);
        $stale = $ageDays === null || $ageDays > $maxAge;

        return [
            'enabled' => $enabled,
            'stale' => $stale,
            'active' => $enabled && !$stale,
            'age_days' => $ageDays,
            'max_age_days' => $maxAge,
            'analyzed_at' => $analyzedAt
        ];
    }

    private function formatJournalSection(array $journal): string
    {
        $md = "## 📓 Journal — Session Handoff (read this first)\n\n";

        $formatIssue = function (array $issue): string {
            $icon = match ($issue['priority'] ?? 'medium') {
                'critical' => '🔴', 'high' => '🟠', 'medium' => '🟡', 'low' => '🟢', default => '⚪'
            };
            $by = $issue['updated_by'] ?? $issue['created_by'] ?? null;
            $byText = $by ? " (by {$by})" : '';
            $age = $this->timeAgo($issue['updatedAt'] ?? $issue['createdAt'] ?? '');

            return "- {$icon} **{$issue['id']}** [{$issue['priority']}] {$issue['title']} — updated {$age}{$byText}\n";
        };

        if ($journal['in_progress']) {
            $md .= '### 🔄 In Progress (' . count($journal['in_progress']) . ")\n";
            foreach ($journal['in_progress'] as $issue) {
                $md .= $formatIssue($issue);
            }
            $md .= "\n";
        }

        if ($journal['blocked']) {
            $md .= '### ⛔ Blocked (' . count($journal['blocked']) . ")\n";
            foreach ($journal['blocked'] as $issue) {
                $md .= $formatIssue($issue);
            }
            $md .= "\n";
        }

        $openShown = array_slice($journal['open'], 0, 10);
        $md .= '### 📥 Open Issues (' . count($journal['open']);
        if (count($journal['open']) > count($openShown)) {
            $md .= ', top ' . count($openShown) . ' by priority';
        }
        $md .= ")\n";
        if ($openShown) {
            foreach ($openShown as $issue) {
                $md .= $formatIssue($issue);
            }
        } else {
            $md .= "- none\n";
        }
        $md .= "\n";

        $md .= "### 📌 Latest Checkpoints\n";
        if ($journal['checkpoints']) {
            foreach ($journal['checkpoints'] as $cp) {
                $idTail = substr($cp['id'] ?? '?', -12);
                $age = $this->timeAgo($cp['created_at'] ?? '');
                $agent = $cp['metadata']['agent'] ?? $cp['agent'] ?? null;
                $agentText = $agent ? ", {$agent}" : '';
                $desc = trim((string) ($cp['description'] ?? ''));
                if (mb_strlen($desc) > 300) {
                    $desc = mb_substr($desc, 0, 297) . '...';
                }
                $md .= "- `{$idTail}` ({$age}{$agentText}): {$desc}\n";
            }
        } else {
            $md .= "- none yet — create one with `cpm checkpoint create -d \"...\"`\n";
        }
        $md .= "\n";

        $md .= "**Drill down**: `cpm issue show <id>` · `cpm issue list --status in_progress --json` · `cpm checkpoint list --json --limit 3`\n";

        return $md;
    }

    private function timeAgo(string $timestamp): string
    {
        $time = strtotime($timestamp);
        if (!$time) {
            return 'unknown';
        }

        $diff = max(0, time() - $time);
        if ($diff < 3600) {
            return floor($diff / 60) . 'm ago';
        }
        if ($diff < 86400) {
            return floor($diff / 3600) . 'h ago';
        }

        return floor($diff / 86400) . 'd ago';
    }

    /**
     * Generate rich, informative DIGEST.md content
     */
    private function generateRichDigest(array $summaryData, array $journal, array $tracking): string
    {
        $journalSection = $this->formatJournalSection($journal);
        $trackingSection = $tracking['active']
            ? $this->formatActiveTrackingSections($summaryData)
            : $this->formatInactiveTrackingNotice($tracking);

        return <<<MD
# 🤖 AI Assistant Context – Project Digest

*Generated: {$summaryData['generated_at']}*

{$journalSection}

{$trackingSection}
---

💡 **For AI Assistants**: The Journal section above is the authoritative session-handoff state (issues + checkpoints). Function-level tracking detail appears only when its analysis data is fresh.

📚 **Data Sources**:
- Issues (journal): `.cpm/db/issues.json` (archive: `.cpm/db/issues_archive.json`)
- Checkpoints: `.cpm/db/checkpoints.json`
- Progress tracking: `.cpm/db/progress.json`
- Code inventory: `.cpm/db/inventory.json`

MD;
    }

    /**
     * Honest replacement for the function-tracking sections when the data is
     * disabled or stale.
     */
    private function formatInactiveTrackingNotice(array $tracking): string
    {
        if (!$tracking['enabled']) {
            return "## ⚙️ Function Tracking: disabled\n\n"
                . "Function-level tracking is turned off for this project (`tracking.enabled = false`).\n"
                . "Enable it when you need function-level progress on a bounded coding effort:\n"
                . "`cpm config set tracking.enabled true && cpm start --force`\n\n";
        }

        $analyzed = $tracking['analyzed_at'] ? date('Y-m-d', strtotime($tracking['analyzed_at'])) : 'never';
        $age = $tracking['age_days'] !== null ? "{$tracking['age_days']} days old" : 'unknown age';

        return "## ⚙️ Function Tracking: STALE — detail omitted\n\n"
            . "Last analysis: **{$analyzed}** ({$age}; freshness limit: {$tracking['max_age_days']} days).\n"
            . "Function-level numbers would be misleading, so they are not shown.\n\n"
            . "- To refresh and use tracking: `cpm start --force` (re-analyze), then `cpm monitor`\n"
            . "- Not using tracking on this project? Silence this notice: `cpm config set tracking.enabled false`\n\n";
    }

    /**
     * The full function-tracking sections (only rendered when fresh).
     */
    private function formatActiveTrackingSections(array $summaryData): string
    {
        $overview = $summaryData['project_overview'];
        $completion = $summaryData['completion_summary'];
        $activity = $summaryData['recent_activity'];
        $metrics = $summaryData['critical_metrics'];
        $files = $summaryData['file_summary'];

        $lastSession = $activity['last_session'] ? date('Y-m-d H:i:s', strtotime($activity['last_session'])) : 'Never';
        $analyzedAt = $overview['analyzed_at'] ? date('Y-m-d H:i:s', strtotime($overview['analyzed_at'])) : 'Never';

        $recentChanges = $this->formatRecentChanges($activity['recent_changes']);
        $keyFiles = $this->formatKeyFiles($files['priority_files']);
        $nextSteps = $this->formatNextSteps($completion, $files, $overview);
        $aiTasks = $this->formatAiTasks($summaryData['ai_enhancements'] ?? []);
        $trackingRec = $this->formatTrackingRecommendation($summaryData['ai_enhancements'] ?? []);

        return <<<MD
## 🚀 Getting Started (For AI Assistants)
{$this->formatGettingStarted($overview, $completion)}

## 📊 Project Overview
- **Name**: {$overview['name']}
- **Framework**: {$overview['framework']}
- **Primary Language**: {$overview['language']}
- **Total Files**: {$overview['total_files']}
- **Total Functions**: {$overview['total_functions']}
- **Last Analysis**: {$analyzedAt}

## 🎯 Progress Summary
- **Completion**: {$completion['completed']}/{$completion['total_functions']} functions ({$completion['completion_percentage']}%)
- **Status Breakdown**:
  - ⏳ Pending: {$completion['pending']} functions
  - 🔄 In Progress: {$completion['in_progress']} functions
  - ✅ Completed: {$completion['completed']} functions

## 📈 Recent Activity
- **Last Session**: {$lastSession}
- **Files Modified Today**: {$activity['files_modified_today']}
- **Functions Completed Today**: {$activity['functions_completed_today']}

### Recent Changes
{$recentChanges}

## 🔍 Critical Metrics
- **Code Quality Score**: {$metrics['quality_score']}/100
- **Technical Debt**: {$metrics['debt_ratio']}%
- **Test Coverage**: {$metrics['test_coverage']}%
- **Complexity Issues**: {$metrics['complexity_issues']}

## 📁 Key Files Requiring Attention
{$keyFiles}

## 🤖 AI Priority Task Queue
{$aiTasks}

## ⚙️  Recommended Tracking Mode
{$trackingRec}

## 🚀 Recommended Next Steps
{$nextSteps}

MD;
    }
    
    /**
     * Format recent changes for display
     */
    private function formatRecentChanges(array $changes): string
    {
        if (empty($changes)) {
            return "- No recent changes detected\n";
        }
        
        $formatted = "";
        foreach (array_slice($changes, 0, 5) as $change) {
            $time = isset($change['timestamp']) ? date('H:i', strtotime($change['timestamp'])) : 'Unknown';
            $file = $change['file'] ?? 'Unknown';
            $action = $change['action'] ?? 'modified';
            $formatted .= "- **{$time}**: {$action} `{$file}`\n";
        }
        
        return $formatted;
    }
    
    /**
     * Format key files that need attention
     */
    private function formatKeyFiles(array $files): string
    {
        if (empty($files)) {
            return "- All files are up to date\n";
        }
        
        $formatted = "";
        foreach (array_slice($files, 0, 8) as $file) {
            $name = $file['file'] ?? 'Unknown';
            $pending = $file['pending_functions'] ?? 0;
            $issues = $file['issues'] ?? 0;
            $priority = $file['priority'] ?? 'medium';
            
            $priorityIcon = match($priority) {
                'high' => '🔥',
                'medium' => '⚠️',
                'low' => 'ℹ️',
                default => '📄'
            };
            
            $formatted .= "- {$priorityIcon} **{$name}** ({$pending} functions pending";
            if ($issues > 0) {
                $formatted .= ", {$issues} issues";
            }
            $formatted .= ")\n";
        }
        
        return $formatted;
    }
    
    /**
     * Generate contextual next steps based on project state and type
     */
    private function formatNextSteps(array $completion, array $files, array $overview): string
    {
        $steps = [];
        
        // If no functions completed, suggest starting
        if ($completion['completed'] === 0) {
            $steps[] = "1. **Start Development**: Begin with high-priority functions in key files";
            if (!empty($files['priority_files'])) {
                $firstFile = $files['priority_files'][0]['file'] ?? '';
                if ($firstFile) {
                    $steps[] = "2. **Focus Area**: Start with `{$firstFile}`";
                }
            }
        } else {
            // Ongoing project suggestions
            $pendingPercent = $completion['total_functions'] > 0 
                ? round($completion['pending'] / $completion['total_functions'] * 100, 1)
                : 0;
                
            if ($pendingPercent > 80) {
                $steps[] = "1. **Continue Progress**: {$pendingPercent}% of functions still pending";
            } elseif ($pendingPercent > 50) {
                $steps[] = "1. **Maintain Momentum**: Good progress, {$pendingPercent}% remaining";
            } else {
                $steps[] = "1. **Final Push**: Excellent progress, only {$pendingPercent}% remaining";
            }
        }
        
        // Add specific file recommendations
        if (!empty($files['priority_files'])) {
            $steps[] = "3. **Priority Files**: Focus on files marked with 🔥 high priority";
        }
        
        // Add project-type specific suggestions
        $framework = $overview['framework'] ?? 'Unknown';
        $language = $overview['language'] ?? 'Multi-language';
        $projectSpecificSteps = $this->getProjectSpecificSteps($framework, $language);
        if ($projectSpecificSteps) {
            $steps[] = $projectSpecificSteps;
        }
        
        // Add quality improvement suggestions
        $steps[] = "4. **Quality Check**: Run tests and address any complexity issues";
        $steps[] = "5. **Documentation**: Ensure all completed functions have proper docblocks";
        
        return implode("\n", $steps) . "\n";
    }
    
    /**
     * Generate project-adaptive getting started guide for AI assistants
     */
    private function formatGettingStarted(array $overview, array $completion): string
    {
        $framework = $overview['framework'] ?? 'Unknown';
        $language = $overview['language'] ?? 'Multi-language';
        $totalFunctions = $overview['total_functions'] ?? 0;
        $completedFunctions = $completion['completed'] ?? 0;
        $isNewProject = $completedFunctions === 0;
        
        $guide = "### 🎯 Project Type: {$framework} ({$language})\n";
        
        if ($isNewProject) {
            $guide .= "### 📋 First-Time Setup\n";
            $guide .= "- **Status**: Fresh project analysis complete ({$totalFunctions} functions discovered)\n";
            $guide .= "- **Next Action**: Start with highest priority files marked with 🔥\n";
            $guide .= "- **Workflow**: Use `cpm status --detailed` to see function-level breakdown\n\n";
        } else {
            $progress = $totalFunctions > 0 ? round($completedFunctions / $totalFunctions * 100, 1) : 0;
            $guide .= "### 🔄 Resuming Development\n";
            $guide .= "- **Progress**: {$completedFunctions}/{$totalFunctions} functions completed ({$progress}%)\n";
            $guide .= "- **Next Action**: Continue with pending functions in priority files\n";
            $guide .= "- **Workflow**: Check 'Recent Activity' section for last session context\n\n";
        }
        
        // Add framework-specific guidance
        $guide .= $this->getFrameworkSpecificGuidance($framework, $language);
        
        return $guide;
    }
    
    /**
     * Get framework-specific development guidance
     */
    private function getFrameworkSpecificGuidance(string $framework, string $language): string
    {
        return match($framework) {
            'Laravel' => "### 🔧 Laravel Specific\n- Focus on Controllers, Models, and Services\n- Check artisan commands and middleware\n- Ensure proper validation and error handling\n\n",
            'Symfony' => "### 🔧 Symfony Specific\n- Review Commands, Controllers, and Services\n- Check dependency injection and configuration\n- Ensure proper exception handling\n\n",
            'React' => "### ⚛️ React Specific\n- Focus on Components and Hooks\n- Check state management and effects\n- Ensure proper TypeScript types\n\n",
            'Vue' => "### 💚 Vue Specific\n- Review Components and Composables\n- Check reactive data and computed properties\n- Ensure proper template usage\n\n",
            'Python' => "### 🐍 Python Specific\n- Focus on Classes and Functions\n- Check type hints and docstrings\n- Ensure proper exception handling\n\n",
            'PHP' => "### 🐘 PHP Specific\n- Review Classes, Methods, and Functions\n- Check type declarations and return types\n- Ensure PSR compliance\n\n",
            default => "### 🚀 Universal Approach\n- Start with high-priority files\n- Focus on core business logic first\n- Ensure proper documentation and testing\n\n"
        };
    }
    
    /**
     * Get project-type specific next step recommendations
     */
    private function getProjectSpecificSteps(string $framework, string $language): string
    {
        return match($framework) {
            'Laravel' => "3. **Laravel Focus**: Review Routes, Controllers, Models, and Artisan commands",
            'Symfony' => "3. **Symfony Focus**: Check Console Commands, Services, and Controller actions",
            'React' => "3. **React Focus**: Review Components, Hooks, and state management patterns",
            'Vue' => "3. **Vue Focus**: Check Components, Composables, and reactive data handling",
            'Python' => "3. **Python Focus**: Review Classes, Functions, and ensure proper type hints",
            'PHP' => "3. **PHP Focus**: Check Classes, Methods, and PSR compliance",
            'Node.js' => "3. **Node.js Focus**: Review modules, middleware, and async patterns",
            'JavaScript/TypeScript' => "3. **JS/TS Focus**: Check functions, classes, and type definitions",
            default => ""
        };
    }

    /**
     * Generate AI enhancements (priority scoring and tracking recommendations)
     */
    private function generateAiEnhancements(DatabaseManager $dbManager): array
    {
        try {
            $inventory = $dbManager->read('inventory');
            $progress = $dbManager->read('progress');

            $priorityScorer = new AiPriorityScorer();
            $trackingManager = new AdaptiveTrackingManager();

            $scoredFunctions = $priorityScorer->scoreFunctions($inventory, $progress);
            $topFunctions = $priorityScorer->getTopPriorityFunctions($scoredFunctions, 5, 50000);

            $totalFunctions = count($inventory['functions'] ?? []);
            $trackingRec = $trackingManager->getAiRecommendations($totalFunctions);
            $trackingStats = $trackingManager->getTrackingStatistics(
                $inventory['functions'] ?? [],
                $trackingRec['recommended_mode']
            );

            return [
                'top_tasks' => $topFunctions['functions'],
                'total_tokens' => $topFunctions['total_tokens'],
                'tracking' => $trackingRec,
                'tracking_stats' => $trackingStats
            ];
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Format AI tasks for display in DIGEST.md
     */
    private function formatAiTasks(array $aiEnhancements): string
    {
        if (empty($aiEnhancements['top_tasks'])) {
            return "✅ **All functions completed!** No pending work.\n";
        }

        $tasks = $aiEnhancements['top_tasks'];
        $totalTokens = $aiEnhancements['total_tokens'] ?? 0;

        $formatted = "*Top 5 priority tasks optimized for AI workflow (Total: ~{$totalTokens} tokens)*\n\n";

        foreach ($tasks as $idx => $task) {
            $num = $idx + 1;
            $priority = strtoupper($task['priority_level']);
            $name = $task['name'];
            $file = basename($task['file']);
            $tokens = number_format($task['estimated_tokens']);
            $complexity = $task['complexity'];
            $reason = $task['reason'];

            $priorityIcon = match($task['priority_level']) {
                'critical' => '🔴',
                'high' => '🟠',
                'medium' => '🟡',
                'low' => '🟢',
                default => '⚪'
            };

            $formatted .= "{$num}. {$priorityIcon} **[{$priority}]** `{$name}`\n";
            $formatted .= "   - File: `{$file}`\n";
            $formatted .= "   - Reason: {$reason}\n";
            $formatted .= "   - Estimated tokens: ~{$tokens} | Complexity: {$complexity}\n";
            $formatted .= "\n";
        }

        $formatted .= "💡 **Usage**: Run `cpm status --focus` for complete AI-optimized view\n";
        $formatted .= "📝 **Workflow**: Run `cpm suggest` for detailed AI workflow guidance\n";

        return $formatted;
    }

    /**
     * Format tracking recommendation for display
     */
    private function formatTrackingRecommendation(array $aiEnhancements): string
    {
        if (empty($aiEnhancements['tracking'])) {
            return "No tracking recommendations available.\n";
        }

        $tracking = $aiEnhancements['tracking'];
        $stats = $aiEnhancements['tracking_stats'] ?? [];

        $mode = $tracking['recommended_mode'] ?? 'balanced';
        $reason = $tracking['reason'] ?? '';

        $formatted = "**Recommended Mode**: `{$mode}`\n\n";
        $formatted .= "**Reason**: {$reason}\n\n";

        if (!empty($stats)) {
            $formatted .= "**Statistics**:\n";
            $formatted .= "- Tracks {$stats['tracked_functions']} of {$stats['total_functions']} functions\n";
            $formatted .= "- Reduces tracking overhead by {$stats['reduction_percentage']}%\n";
            $formatted .= "- Estimated token savings: ~" . number_format($stats['estimated_token_savings']) . " tokens\n\n";
        }

        if (!empty($tracking['benefits'])) {
            $formatted .= "**Benefits**:\n";
            foreach ($tracking['benefits'] as $benefit) {
                $formatted .= "- {$benefit}\n";
            }
            $formatted .= "\n";
        }

        if (!empty($tracking['ai_workflow'])) {
            $formatted .= "**AI Workflow Tips**:\n";
            foreach ($tracking['ai_workflow'] as $tip) {
                $formatted .= "- {$tip}\n";
            }
        }

        return $formatted;
    }
}