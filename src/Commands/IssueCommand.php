<?php

declare(strict_types=1);

namespace ClaudeProjectManager\Commands;

use ClaudeProjectManager\DatabaseManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Issue tracking command for project management
 * Provides full lifecycle management of issues, bugs, tasks, and features
 */
class IssueCommand extends Command
{
    private DatabaseManager $database;

    protected static $defaultName = 'issue';
    protected static $defaultDescription = 'Manage project issues and tasks';

    private const VALID_CATEGORIES = ['hardware', 'software', 'documentation', 'feature', 'bug', 'task', 'other'];
    private const VALID_PRIORITIES = ['critical', 'high', 'medium', 'low'];
    private const VALID_STATUSES = ['open', 'in_progress', 'blocked', 'resolved', 'closed'];

    private const CATEGORY_CODES = [
        'hardware' => 'HW',
        'software' => 'SW',
        'documentation' => 'DOC',
        'feature' => 'FEAT',
        'bug' => 'BUG',
        'task' => 'TASK',
        'other' => 'OTH'
    ];

    private const CATEGORY_ALIASES = [
        'hw' => 'hardware',
        'sw' => 'software',
        'doc' => 'documentation',
        'docs' => 'documentation',
        'feat' => 'feature',
        'fix' => 'bug'
    ];

    private const STATUS_ALIASES = [
        'in-progress' => 'in_progress',
        'wip' => 'in_progress',
        'done' => 'resolved'
    ];

    public function __construct(DatabaseManager $database)
    {
        parent::__construct();
        $this->database = $database;
    }

    protected function configure(): void
    {
        $this
            ->addArgument(
                'action',
                InputArgument::REQUIRED,
                'Action: add, list, show, update, close, reopen, comment, link, export, archive'
            )
            ->addArgument(
                'target',
                InputArgument::OPTIONAL,
                'Issue ID for show/update/close/reopen/comment/link actions'
            )
            ->addOption(
                'title',
                't',
                InputOption::VALUE_REQUIRED,
                'Issue title (required for add)'
            )
            ->addOption(
                'description',
                'd',
                InputOption::VALUE_REQUIRED,
                'Issue description'
            )
            ->addOption(
                'category',
                'c',
                InputOption::VALUE_REQUIRED,
                'Category: ' . implode(', ', self::VALID_CATEGORIES)
            )
            ->addOption(
                'priority',
                'p',
                InputOption::VALUE_REQUIRED,
                'Priority: ' . implode(', ', self::VALID_PRIORITIES)
            )
            ->addOption(
                'status',
                's',
                InputOption::VALUE_REQUIRED,
                'Status: ' . implode(', ', self::VALID_STATUSES)
            )
            ->addOption(
                'assignee',
                'a',
                InputOption::VALUE_REQUIRED,
                'Assignee name'
            )
            ->addOption(
                'labels',
                'l',
                InputOption::VALUE_REQUIRED,
                'Comma-separated labels'
            )
            ->addOption(
                'tag',
                null,
                InputOption::VALUE_REQUIRED,
                'Comma-separated labels (alias of --labels; merged when both given)'
            )
            ->addOption(
                'resolution',
                'r',
                InputOption::VALUE_REQUIRED,
                'Resolution text (for close action); use "-" to read from stdin'
            )
            ->addOption(
                'resolution-file',
                null,
                InputOption::VALUE_REQUIRED,
                'Read resolution text from a file (avoids long shell arguments)'
            )
            ->addOption(
                'description-file',
                null,
                InputOption::VALUE_REQUIRED,
                'Read description text from a file (avoids long shell arguments)'
            )
            ->addOption(
                'reason',
                null,
                InputOption::VALUE_REQUIRED,
                'Reason for reopen'
            )
            ->addOption(
                'text',
                null,
                InputOption::VALUE_REQUIRED,
                'Comment text; use "-" to read from stdin'
            )
            ->addOption(
                'text-file',
                null,
                InputOption::VALUE_REQUIRED,
                'Read comment text from a file (avoids long shell arguments)'
            )
            ->addOption(
                'limit',
                null,
                InputOption::VALUE_REQUIRED,
                'Maximum number of issues to return (for list; most recent first)'
            )
            ->addOption(
                'agent',
                null,
                InputOption::VALUE_REQUIRED,
                'Acting agent recorded on mutations (default: $CPM_AGENT env)'
            )
            ->addOption(
                'older-than',
                null,
                InputOption::VALUE_REQUIRED,
                'Days since closedAt for archive action (default: 30)'
            )
            ->addOption(
                'before',
                null,
                InputOption::VALUE_REQUIRED,
                'Archive closed issues with closedAt before this date (YYYY-MM-DD)'
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'For archive: show what would be moved without changing anything'
            )
            ->addOption(
                'file',
                'f',
                InputOption::VALUE_REQUIRED,
                'File path to link'
            )
            ->addOption(
                'format',
                null,
                InputOption::VALUE_REQUIRED,
                'Export format: markdown, json',
                'markdown'
            )
            ->addOption(
                'output',
                'o',
                InputOption::VALUE_REQUIRED,
                'Output file for export'
            )
            ->addOption(
                'json',
                null,
                InputOption::VALUE_NONE,
                'Output JSON format for AI parsing'
            )
            ->addOption(
                'all',
                null,
                InputOption::VALUE_NONE,
                'Show all issues including closed (for list)'
            )
            ->setHelp('
Issue tracking for projects. Create, manage, and close issues.

Actions:
  add       Create a new issue
  list      List issues (open by default, use --all for closed)
  show      Show issue details
  update    Update issue properties
  close     Close an issue with resolution
  reopen    Reopen a closed issue
  comment   Add comment to an issue
  link      Link a file to an issue
  export    Export issues to markdown or JSON
  archive   Move old closed issues to issues_archive.json (cold storage)

Categories: hardware, software, documentation, feature, bug, task, other
  (aliases accepted: hw, sw, doc/docs, feat, fix)
Priorities: critical, high, medium, low
Statuses: open, in_progress, blocked, resolved, closed
  (aliases accepted: in-progress, wip, done)

Long text without shell-argument limits:
  --description-file / --resolution-file / --text-file read from a file;
  --description=- / --resolution=- / --text=- read from stdin (use the = form).

Examples:
  cpm issue add --title "Fix login bug" --category bug --priority high
  cpm issue add -t "Add dark mode" -c feat -p medium -d "Implement dark theme"
  cpm issue list
  cpm issue list --status open --category bug --limit 5
  cpm issue list --all --json
  cpm issue show CPM-BUG-001
  cpm issue update CPM-BUG-001 --status in_progress --assignee "developer"
  cpm issue close CPM-BUG-001 --resolution "Fixed in commit abc123"
  cpm issue close CPM-BUG-001 --resolution-file /tmp/resolution.md
  git log -1 --format=%B | cpm issue comment CPM-BUG-001 --text=-
  cpm issue reopen CPM-BUG-001 --reason "Issue recurred after update"
  cpm issue link CPM-BUG-001 --file /path/to/related/file.php
  cpm issue export --format markdown --output issues.md
  cpm issue archive --dry-run              (closed >30 days, preview)
  cpm issue archive --older-than 60 --json
  cpm issue archive --before 2026-06-01

Agent attribution: pass --agent <name> or set $CPM_AGENT; recorded as
created_by / updated_by on issues and agent on comments.

JSON output: every action returns {success, action, timestamp, data:{...}}.
(Legacy top-level keys are still included for backward compatibility.)
            ');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $action = $input->getArgument('action');
        $json = $input->getOption('json');

        try {
            return match ($action) {
                'add' => $this->handleAdd($input, $output),
                'list' => $this->handleList($input, $output),
                'show' => $this->handleShow($input, $output),
                'update' => $this->handleUpdate($input, $output),
                'close' => $this->handleClose($input, $output),
                'reopen' => $this->handleReopen($input, $output),
                'comment' => $this->handleComment($input, $output),
                'link' => $this->handleLink($input, $output),
                'export' => $this->handleExport($input, $output),
                'archive' => $this->handleArchive($input, $output),
                default => $this->handleUnknownAction($action, $json, $output)
            };
        } catch (\Exception $e) {
            if ($json) {
                $output->writeln(json_encode([
                    'success' => false,
                    'error' => $e->getMessage(),
                    'action' => $action
                ]));
            } else {
                $io->error('Issue command failed: ' . $e->getMessage());
            }
            return Command::FAILURE;
        }
    }

    private function handleAdd(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $json = $input->getOption('json');

        $title = $input->getOption('title');
        $description = $this->resolveTextOption($input, 'description') ?? '';
        $category = $this->normalizeCategory($input->getOption('category')) ?: 'task';
        $priority = $input->getOption('priority') ?: 'medium';
        $assignee = $input->getOption('assignee') ?? '';
        $labels = $this->resolveLabels($input);

        // Validation
        if (!$title) {
            $error = 'Title is required for add action (use --title or -t)';
            if ($json) {
                $output->writeln(json_encode(['success' => false, 'error' => $error]));
            } else {
                $io->error($error);
            }
            return Command::FAILURE;
        }

        if (!in_array($category, self::VALID_CATEGORIES)) {
            $error = 'Invalid category. Valid: ' . implode(', ', self::VALID_CATEGORIES);
            if ($json) {
                $output->writeln(json_encode(['success' => false, 'error' => $error]));
            } else {
                $io->error($error);
            }
            return Command::FAILURE;
        }

        if (!in_array($priority, self::VALID_PRIORITIES)) {
            $error = 'Invalid priority. Valid: ' . implode(', ', self::VALID_PRIORITIES);
            if ($json) {
                $output->writeln(json_encode(['success' => false, 'error' => $error]));
            } else {
                $io->error($error);
            }
            return Command::FAILURE;
        }

        // Create the issue inside a locked read-modify-write transaction so
        // concurrent invocations cannot allocate the same ID or lose writes.
        $agent = $this->resolveAgent($input);
        $issue = null;
        $this->database->safeUpdate('issues', function (array $issuesData) use ($title, $description, $category, $priority, $assignee, $labels, $agent, &$issue) {
            $issueId = $this->generateIssueId($issuesData, $category);

            $issue = [
                'id' => $issueId,
                'title' => $title,
                'description' => $description,
                'category' => $category,
                'priority' => $priority,
                'status' => 'open',
                'assignee' => $assignee,
                'labels' => $labels,
                'linkedFiles' => [],
                'comments' => [],
                'createdAt' => date('c'),
                'updatedAt' => date('c')
            ];

            if ($agent !== null) {
                $issue['created_by'] = $agent;
            }

            $issuesData['issues'][] = $issue;

            return $issuesData;
        }, 'issue add');

        if ($issue === null) {
            $error = 'Failed to create issue (could not update issues database)';
            if ($json) {
                $output->writeln(json_encode(['success' => false, 'error' => $error]));
            } else {
                $io->error($error);
            }
            return Command::FAILURE;
        }

        $issueId = $issue['id'];

        if ($json) {
            $this->outputIssueJson($output, 'add', ['issue' => $issue]);
        } else {
            $io->success("Created issue: {$issueId}");
            $io->table(
                ['Field', 'Value'],
                [
                    ['ID', $issueId],
                    ['Title', $title],
                    ['Category', $category],
                    ['Priority', $priority],
                    ['Status', 'open']
                ]
            );
        }

        return Command::SUCCESS;
    }

    private function handleList(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $json = $input->getOption('json');
        $showAll = $input->getOption('all');
        $filterStatus = $this->normalizeStatus($input->getOption('status'));
        $filterCategory = $this->normalizeCategory($input->getOption('category'));
        $filterPriority = $input->getOption('priority');
        $limit = $input->getOption('limit') !== null ? max(1, (int) $input->getOption('limit')) : null;

        $issuesData = $this->getIssuesData();
        $issues = $issuesData['issues'] ?? [];

        // Filter issues
        $filtered = array_filter($issues, function($issue) use ($showAll, $filterStatus, $filterCategory, $filterPriority) {
            // By default, hide closed issues unless --all
            if (!$showAll && !$filterStatus && ($issue['status'] ?? '') === 'closed') {
                return false;
            }

            if ($filterStatus && ($issue['status'] ?? '') !== $filterStatus) {
                return false;
            }

            if ($filterCategory && ($issue['category'] ?? '') !== $filterCategory) {
                return false;
            }

            if ($filterPriority && ($issue['priority'] ?? '') !== $filterPriority) {
                return false;
            }

            return true;
        });

        $totalMatching = count($filtered);
        if ($limit !== null && $totalMatching > $limit) {
            // Most recent last in storage order; keep the newest N
            $filtered = array_slice($filtered, -$limit);
        }

        if ($json) {
            $this->outputIssueJson($output, 'list', [
                'total' => $totalMatching,
                'showing' => count($filtered),
                'issues' => array_values($filtered)
            ]);
            return Command::SUCCESS;
        }

        if (empty($filtered)) {
            $io->note('No issues found matching criteria');
            return Command::SUCCESS;
        }

        $io->title('Issues (' . count($filtered) . ')');

        $rows = [];
        foreach ($filtered as $issue) {
            $rows[] = [
                $issue['id'] ?? 'N/A',
                $this->getPriorityIcon($issue['priority'] ?? 'medium') . ' ' . ($issue['priority'] ?? 'medium'),
                $issue['status'] ?? 'open',
                $issue['category'] ?? 'other',
                $this->truncate($issue['title'] ?? '', 40)
            ];
        }

        $io->table(['ID', 'Priority', 'Status', 'Category', 'Title'], $rows);

        return Command::SUCCESS;
    }

    private function handleShow(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $json = $input->getOption('json');
        $issueId = $input->getArgument('target');

        if (!$issueId) {
            $error = 'Issue ID is required for show action';
            if ($json) {
                $output->writeln(json_encode(['success' => false, 'error' => $error]));
            } else {
                $io->error($error);
            }
            return Command::FAILURE;
        }

        $issue = $this->findIssue($issueId);
        if (!$issue) {
            $error = "Issue not found: {$issueId}";
            if ($json) {
                $output->writeln(json_encode(['success' => false, 'error' => $error]));
            } else {
                $io->error($error);
            }
            return Command::FAILURE;
        }

        if ($json) {
            $this->outputIssueJson($output, 'show', ['issue' => $issue]);
            return Command::SUCCESS;
        }

        $io->title($issue['id'] . ': ' . $issue['title']);

        $io->table(
            ['Field', 'Value'],
            [
                ['ID', $issue['id']],
                ['Title', $issue['title']],
                ['Description', $issue['description'] ?: '(none)'],
                ['Category', $issue['category']],
                ['Priority', $this->getPriorityIcon($issue['priority']) . ' ' . $issue['priority']],
                ['Status', $issue['status']],
                ['Assignee', $issue['assignee'] ?: '(unassigned)'],
                ['Labels', implode(', ', $issue['labels'] ?? []) ?: '(none)'],
                ['Created', $issue['createdAt']],
                ['Updated', $issue['updatedAt'] ?? $issue['createdAt']]
            ]
        );

        if (!empty($issue['linkedFiles'])) {
            $io->section('Linked Files');
            foreach ($issue['linkedFiles'] as $file) {
                $io->text('  - ' . $file);
            }
        }

        if (!empty($issue['comments'])) {
            $io->section('Comments (' . count($issue['comments']) . ')');
            foreach ($issue['comments'] as $comment) {
                $io->text('[' . ($comment['timestamp'] ?? 'unknown') . '] ' . $comment['text']);
            }
        }

        if (isset($issue['resolution'])) {
            $io->section('Resolution');
            $io->text($issue['resolution']);
        }

        return Command::SUCCESS;
    }

    private function handleUpdate(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $json = $input->getOption('json');
        $issueId = $input->getArgument('target');

        if (!$issueId) {
            $error = 'Issue ID is required for update action';
            if ($json) {
                $output->writeln(json_encode(['success' => false, 'error' => $error]));
            } else {
                $io->error($error);
            }
            return Command::FAILURE;
        }

        $updates = [];
        $setters = [];

        // Collect and validate updates from options before touching the DB
        if ($title = $input->getOption('title')) {
            $setters['title'] = $title;
            $updates[] = "title → {$title}";
        }

        if ($description = $this->resolveTextOption($input, 'description')) {
            $setters['description'] = $description;
            $updates[] = 'description updated';
        }

        if ($category = $this->normalizeCategory($input->getOption('category'))) {
            if (!in_array($category, self::VALID_CATEGORIES)) {
                $error = 'Invalid category. Valid: ' . implode(', ', self::VALID_CATEGORIES);
                if ($json) {
                    $output->writeln(json_encode(['success' => false, 'error' => $error]));
                } else {
                    $io->error($error);
                }
                return Command::FAILURE;
            }
            $setters['category'] = $category;
            $updates[] = "category → {$category}";
        }

        if ($priority = $input->getOption('priority')) {
            if (!in_array($priority, self::VALID_PRIORITIES)) {
                $error = 'Invalid priority. Valid: ' . implode(', ', self::VALID_PRIORITIES);
                if ($json) {
                    $output->writeln(json_encode(['success' => false, 'error' => $error]));
                } else {
                    $io->error($error);
                }
                return Command::FAILURE;
            }
            $setters['priority'] = $priority;
            $updates[] = "priority → {$priority}";
        }

        if ($status = $this->normalizeStatus($input->getOption('status'))) {
            if (!in_array($status, self::VALID_STATUSES)) {
                $error = 'Invalid status. Valid: ' . implode(', ', self::VALID_STATUSES);
                if ($json) {
                    $output->writeln(json_encode(['success' => false, 'error' => $error]));
                } else {
                    $io->error($error);
                }
                return Command::FAILURE;
            }
            $setters['status'] = $status;
            $updates[] = "status → {$status}";
        }

        if ($assignee = $input->getOption('assignee')) {
            $setters['assignee'] = $assignee;
            $updates[] = "assignee → {$assignee}";
        }

        if ($labels = $this->resolveLabels($input)) {
            $setters['labels'] = $labels;
            $updates[] = 'labels updated';
        }

        if (empty($updates)) {
            $error = 'No updates provided. Use --title, --status, --priority, etc.';
            if ($json) {
                $output->writeln(json_encode(['success' => false, 'error' => $error]));
            } else {
                $io->warning($error);
            }
            return Command::FAILURE;
        }

        $updatedIssue = $this->mutateIssue($issueId, function (array &$issue) use ($setters) {
            foreach ($setters as $field => $value) {
                $issue[$field] = $value;
            }
        }, $this->resolveAgent($input));

        if ($updatedIssue === null) {
            $error = "Issue not found: {$issueId}";
            if ($json) {
                $output->writeln(json_encode(['success' => false, 'error' => $error]));
            } else {
                $io->error($error);
            }
            return Command::FAILURE;
        }

        if ($json) {
            $this->outputIssueJson($output, 'update', [
                'issue_id' => $issueId,
                'updates' => $updates,
                'issue' => $updatedIssue
            ]);
        } else {
            $io->success("Updated issue {$issueId}");
            foreach ($updates as $update) {
                $io->text("  - {$update}");
            }
        }

        return Command::SUCCESS;
    }

    private function handleClose(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $json = $input->getOption('json');
        $issueId = $input->getArgument('target');
        $resolution = $this->resolveTextOption($input, 'resolution');

        if (!$issueId) {
            $error = 'Issue ID is required for close action';
            if ($json) {
                $output->writeln(json_encode(['success' => false, 'error' => $error]));
            } else {
                $io->error($error);
            }
            return Command::FAILURE;
        }

        if (!$resolution) {
            $error = 'Resolution is required for close action (use --resolution or -r)';
            if ($json) {
                $output->writeln(json_encode(['success' => false, 'error' => $error]));
            } else {
                $io->error($error);
            }
            return Command::FAILURE;
        }

        $updatedIssue = $this->mutateIssue($issueId, function (array &$issue) use ($resolution) {
            $issue['status'] = 'closed';
            $issue['resolution'] = $resolution;
            $issue['closedAt'] = date('c');
        }, $this->resolveAgent($input));

        if ($updatedIssue === null) {
            $error = "Issue not found: {$issueId}";
            if ($json) {
                $output->writeln(json_encode(['success' => false, 'error' => $error]));
            } else {
                $io->error($error);
            }
            return Command::FAILURE;
        }

        if ($json) {
            $this->outputIssueJson($output, 'close', [
                'issue_id' => $issueId,
                'resolution' => $resolution,
                'closed_at' => $updatedIssue['closedAt']
            ]);
        } else {
            $io->success("Closed issue {$issueId}");
            $io->text("Resolution: {$resolution}");
        }

        return Command::SUCCESS;
    }

    private function handleReopen(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $json = $input->getOption('json');
        $issueId = $input->getArgument('target');
        $reason = $input->getOption('reason') ?? 'Reopened';

        if (!$issueId) {
            $error = 'Issue ID is required for reopen action';
            if ($json) {
                $output->writeln(json_encode(['success' => false, 'error' => $error]));
            } else {
                $io->error($error);
            }
            return Command::FAILURE;
        }

        $agent = $this->resolveAgent($input);
        $updatedIssue = $this->mutateIssue($issueId, function (array &$issue) use ($reason, $agent) {
            $issue['status'] = 'open';
            unset($issue['closedAt']);

            // Add reopen as a comment
            $comment = [
                'timestamp' => date('c'),
                'text' => "[Reopened] {$reason}"
            ];
            if ($agent !== null) {
                $comment['agent'] = $agent;
            }
            $issue['comments'][] = $comment;
        }, $agent);

        if ($updatedIssue === null) {
            $error = "Issue not found: {$issueId}";
            if ($json) {
                $output->writeln(json_encode(['success' => false, 'error' => $error]));
            } else {
                $io->error($error);
            }
            return Command::FAILURE;
        }

        if ($json) {
            $this->outputIssueJson($output, 'reopen', [
                'issue_id' => $issueId,
                'reason' => $reason
            ]);
        } else {
            $io->success("Reopened issue {$issueId}");
            $io->text("Reason: {$reason}");
        }

        return Command::SUCCESS;
    }

    private function handleComment(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $json = $input->getOption('json');
        $issueId = $input->getArgument('target');
        $text = $this->resolveTextOption($input, 'text');

        if (!$issueId) {
            $error = 'Issue ID is required for comment action';
            if ($json) {
                $output->writeln(json_encode(['success' => false, 'error' => $error]));
            } else {
                $io->error($error);
            }
            return Command::FAILURE;
        }

        if (!$text) {
            $error = 'Comment text is required (use --text)';
            if ($json) {
                $output->writeln(json_encode(['success' => false, 'error' => $error]));
            } else {
                $io->error($error);
            }
            return Command::FAILURE;
        }

        $agent = $this->resolveAgent($input);
        $comment = [
            'timestamp' => date('c'),
            'text' => $text
        ];
        if ($agent !== null) {
            $comment['agent'] = $agent;
        }

        $updatedIssue = $this->mutateIssue($issueId, function (array &$issue) use ($comment) {
            $issue['comments'][] = $comment;
        }, $agent);

        if ($updatedIssue === null) {
            $error = "Issue not found: {$issueId}";
            if ($json) {
                $output->writeln(json_encode(['success' => false, 'error' => $error]));
            } else {
                $io->error($error);
            }
            return Command::FAILURE;
        }

        if ($json) {
            $this->outputIssueJson($output, 'comment', [
                'issue_id' => $issueId,
                'comment' => $comment
            ]);
        } else {
            $io->success("Added comment to {$issueId}");
        }

        return Command::SUCCESS;
    }

    private function handleLink(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $json = $input->getOption('json');
        $issueId = $input->getArgument('target');
        $filePath = $input->getOption('file');

        if (!$issueId) {
            $error = 'Issue ID is required for link action';
            if ($json) {
                $output->writeln(json_encode(['success' => false, 'error' => $error]));
            } else {
                $io->error($error);
            }
            return Command::FAILURE;
        }

        if (!$filePath) {
            $error = 'File path is required (use --file or -f)';
            if ($json) {
                $output->writeln(json_encode(['success' => false, 'error' => $error]));
            } else {
                $io->error($error);
            }
            return Command::FAILURE;
        }

        $updatedIssue = $this->mutateIssue($issueId, function (array &$issue) use ($filePath) {
            // Add file if not already linked
            if (!in_array($filePath, $issue['linkedFiles'] ?? [])) {
                $issue['linkedFiles'][] = $filePath;
            }
        }, $this->resolveAgent($input));

        if ($updatedIssue === null) {
            $error = "Issue not found: {$issueId}";
            if ($json) {
                $output->writeln(json_encode(['success' => false, 'error' => $error]));
            } else {
                $io->error($error);
            }
            return Command::FAILURE;
        }

        if ($json) {
            $this->outputIssueJson($output, 'link', [
                'issue_id' => $issueId,
                'file' => $filePath
            ]);
        } else {
            $io->success("Linked {$filePath} to {$issueId}");
        }

        return Command::SUCCESS;
    }

    private function handleExport(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $json = $input->getOption('json');
        $format = $input->getOption('format') ?? 'markdown';
        $outputFile = $input->getOption('output');
        $filterStatus = $this->normalizeStatus($input->getOption('status'));
        $filterCategory = $this->normalizeCategory($input->getOption('category'));
        $showAll = $input->getOption('all');

        $issuesData = $this->getIssuesData();
        $issues = $issuesData['issues'] ?? [];

        // Filter issues
        $filtered = array_filter($issues, function($issue) use ($showAll, $filterStatus, $filterCategory) {
            if (!$showAll && !$filterStatus && ($issue['status'] ?? '') === 'closed') {
                return false;
            }
            if ($filterStatus && ($issue['status'] ?? '') !== $filterStatus) {
                return false;
            }
            if ($filterCategory && ($issue['category'] ?? '') !== $filterCategory) {
                return false;
            }
            return true;
        });

        if ($format === 'json') {
            $content = json_encode(['issues' => array_values($filtered)], JSON_PRETTY_PRINT);
        } else {
            $content = $this->generateMarkdownExport(array_values($filtered));
        }

        if ($outputFile) {
            file_put_contents($outputFile, $content);

            if ($json) {
                $this->outputIssueJson($output, 'export', [
                    'format' => $format,
                    'file' => $outputFile,
                    'issues_exported' => count($filtered)
                ]);
            } else {
                $io->success("Exported " . count($filtered) . " issues to {$outputFile}");
            }
        } else {
            $output->writeln($content);
        }

        return Command::SUCCESS;
    }

    /**
     * Move old closed issues to cold storage (issues_archive.json) so the hot
     * issues.json stays small as the journal grows.
     */
    private function handleArchive(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $json = $input->getOption('json');
        $dryRun = (bool) $input->getOption('dry-run');
        $agent = $this->resolveAgent($input);

        $before = $input->getOption('before');
        if ($before !== null) {
            $cutoffTs = strtotime($before . ' 00:00:00');
            if ($cutoffTs === false) {
                $error = "Invalid --before date: {$before} (expected YYYY-MM-DD)";
                if ($json) {
                    $output->writeln(json_encode(['success' => false, 'error' => $error]));
                } else {
                    $io->error($error);
                }
                return Command::FAILURE;
            }
        } else {
            $days = $input->getOption('older-than') !== null ? max(0, (int) $input->getOption('older-than')) : 30;
            $cutoffTs = time() - $days * 86400;
        }

        $archivePath = $this->database->getDatabasePath() . '/issues_archive.json';

        // Select + remove inside the locked transaction; the archive append
        // happens under the same lock so concurrent archives cannot interleave.
        // Archive append is idempotent (dedupe by id), so a retried
        // transaction cannot duplicate entries.
        $moved = [];
        $this->database->safeUpdate('issues', function (array $issuesData) use ($cutoffTs, $dryRun, $archivePath, $agent, &$moved) {
            $moved = [];
            $keep = [];

            foreach ($issuesData['issues'] ?? [] as $issue) {
                $closedAt = ($issue['status'] ?? '') === 'closed'
                    ? strtotime($issue['closedAt'] ?? '')
                    : false;

                if ($closedAt !== false && $closedAt < $cutoffTs) {
                    $moved[] = $issue;
                } else {
                    $keep[] = $issue;
                }
            }

            if ($dryRun || empty($moved)) {
                return $issuesData; // unchanged
            }

            $this->appendToArchive($archivePath, $moved, $agent);
            $issuesData['issues'] = $keep;

            return $issuesData;
        }, 'issue archive');

        $data = [
            'archived' => count($moved),
            'dry_run' => $dryRun,
            'cutoff' => date('c', $cutoffTs),
            'archive_file' => $archivePath,
            'ids' => array_column($moved, 'id')
        ];

        if ($json) {
            $this->outputIssueJson($output, 'archive', $data);
        } else {
            if ($dryRun) {
                $io->note('Dry run: no changes written');
            }
            $io->success(($dryRun ? 'Would archive ' : 'Archived ') . count($moved) . ' closed issue(s) with closedAt before ' . $data['cutoff']);
            foreach ($moved as $issue) {
                $io->text('  - ' . ($issue['id'] ?? '?') . ': ' . ($issue['title'] ?? ''));
            }
            if (!$dryRun && $moved) {
                $io->text('Archive file: ' . $archivePath);
            }
        }

        return Command::SUCCESS;
    }

    /**
     * Append issues to the archive file (atomic write, deduplicated by id).
     * Not a DatabaseManager-managed file: cold storage, no schema.
     */
    private function appendToArchive(string $archivePath, array $issues, ?string $agent): void
    {
        $archive = ['version' => '1.0', 'archived' => []];
        if (file_exists($archivePath)) {
            $decoded = json_decode((string) file_get_contents($archivePath), true);
            if (is_array($decoded) && isset($decoded['archived']) && is_array($decoded['archived'])) {
                $archive = $decoded;
            }
        }

        $existingIds = array_flip(array_column($archive['archived'], 'id'));
        foreach ($issues as $issue) {
            if (isset($existingIds[$issue['id'] ?? ''])) {
                continue;
            }
            $issue['archivedAt'] = date('c');
            if ($agent !== null) {
                $issue['archived_by'] = $agent;
            }
            $archive['archived'][] = $issue;
        }

        $tmp = $archivePath . '.tmp';
        if (file_put_contents($tmp, json_encode($archive, JSON_UNESCAPED_SLASHES)) === false || !rename($tmp, $archivePath)) {
            throw new \RuntimeException("Failed to write archive file: {$archivePath}");
        }
        @chmod($archivePath, 0660);
    }

    private function handleUnknownAction(string $action, bool $json, OutputInterface $output): int
    {
        $validActions = ['add', 'list', 'show', 'update', 'close', 'reopen', 'comment', 'link', 'export', 'archive'];
        $error = "Unknown action '{$action}'. Valid: " . implode(', ', $validActions);

        if ($json) {
            $output->writeln(json_encode(['success' => false, 'error' => $error]));
        } else {
            $io = new SymfonyStyle(new \Symfony\Component\Console\Input\ArrayInput([]), $output);
            $io->error($error);
            $io->note('Use "cpm help issue" for detailed usage');
        }

        return Command::FAILURE;
    }

    private function getIssuesData(): array
    {
        try {
            return $this->database->read('issues');
        } catch (\Exception $e) {
            // Initialize if not exists
            $default = [
                'version' => '1.0',
                'issues' => [],
                'metadata' => [
                    'lastId' => 0,
                    'prefix' => 'CPM',
                    'categoryPrefix' => 'ISSUE'
                ]
            ];
            $this->database->write('issues', $default);
            return $default;
        }
    }

    private function generateIssueId(array &$issuesData, string $category): string
    {
        $metadata = $issuesData['metadata'] ?? [];
        $prefix = $metadata['prefix'] ?? 'CPM';
        $categoryCode = self::CATEGORY_CODES[$category] ?? 'OTH';

        $lastId = ($metadata['lastId'] ?? 0) + 1;
        $issuesData['metadata']['lastId'] = $lastId;

        return sprintf('%s-%s-%03d', $prefix, $categoryCode, $lastId);
    }

    /**
     * Acting agent for attribution: --agent option, else $CPM_AGENT env.
     */
    private function resolveAgent(InputInterface $input): ?string
    {
        $agent = $input->getOption('agent');
        if ($agent === null || $agent === '') {
            $env = getenv('CPM_AGENT');
            $agent = ($env !== false && $env !== '') ? $env : null;
        }

        return $agent !== null ? trim($agent) : null;
    }

    /**
     * Normalize a category value, accepting common abbreviations
     * (feat, doc, docs, hw, sw, fix).
     */
    private function normalizeCategory(?string $category): ?string
    {
        if ($category === null || $category === '') {
            return $category;
        }

        $category = strtolower(trim($category));

        return self::CATEGORY_ALIASES[$category] ?? $category;
    }

    /**
     * Normalize a status value, accepting common variants
     * (in-progress, wip, done).
     */
    private function normalizeStatus(?string $status): ?string
    {
        if ($status === null || $status === '') {
            return $status;
        }

        $status = strtolower(trim($status));

        return self::STATUS_ALIASES[$status] ?? $status;
    }

    /**
     * Resolve a long-text option from --<option>, --<option>-file, or stdin
     * ("-"), so multi-line bodies never have to travel through shell argv.
     */
    private function resolveTextOption(InputInterface $input, string $option): ?string
    {
        $value = $input->getOption($option);
        $fileOption = $option . '-file';
        $file = $this->getDefinition()->hasOption($fileOption) ? $input->getOption($fileOption) : null;

        if ($value === '-' || ($value === null && $file === '-')) {
            $stdin = stream_get_contents(STDIN);
            return $stdin === false ? null : rtrim($stdin, "\n");
        }

        if ($value !== null) {
            return $value;
        }

        if ($file !== null) {
            if (!is_readable($file)) {
                throw new \InvalidArgumentException("Cannot read {$fileOption}: {$file}");
            }
            return rtrim((string) file_get_contents($file), "\n");
        }

        return null;
    }

    /**
     * Collect labels from --labels and --tag (merged, deduplicated).
     */
    private function resolveLabels(InputInterface $input): array
    {
        $raw = trim(($input->getOption('labels') ?? '') . ',' . ($input->getOption('tag') ?? ''), ',');
        if ($raw === '') {
            return [];
        }

        return array_values(array_unique(array_filter(array_map('trim', explode(',', $raw)))));
    }

    /**
     * Emit the standard JSON envelope {success, action, timestamp, data}.
     * Legacy top-level keys are kept alongside `data` for one release so
     * existing agent scripts do not break.
     */
    private function outputIssueJson(OutputInterface $output, string $action, array $data): void
    {
        $response = array_merge([
            'success' => true,
            'action' => $action,
            'timestamp' => date('Y-m-d H:i:s'),
            'data' => $data
        ], $data);

        $output->writeln(json_encode($response, JSON_UNESCAPED_SLASHES));
    }

    /**
     * Apply $apply to the issue with $issueId inside a locked read-modify-write
     * transaction. Returns the updated issue, or null when it does not exist.
     * The callback receives the issue array by reference; updatedAt is bumped
     * automatically when the issue was found.
     */
    private function mutateIssue(string $issueId, callable $apply, ?string $agent = null): ?array
    {
        $updated = null;

        $this->database->safeUpdate('issues', function (array $issuesData) use ($issueId, $apply, $agent, &$updated) {
            $index = $this->findIssueIndex($issuesData, $issueId);
            if ($index === -1) {
                return $issuesData; // unchanged; caller reports not-found
            }

            $apply($issuesData['issues'][$index]);
            $issuesData['issues'][$index]['updatedAt'] = date('c');
            if ($agent !== null) {
                $issuesData['issues'][$index]['updated_by'] = $agent;
            }
            $updated = $issuesData['issues'][$index];

            return $issuesData;
        }, "issue {$issueId}");

        return $updated;
    }

    private function findIssue(string $issueId): ?array
    {
        $issuesData = $this->getIssuesData();
        foreach ($issuesData['issues'] ?? [] as $issue) {
            if (($issue['id'] ?? '') === $issueId) {
                return $issue;
            }
        }
        return null;
    }

    private function findIssueIndex(array $issuesData, string $issueId): int
    {
        foreach ($issuesData['issues'] ?? [] as $index => $issue) {
            if (($issue['id'] ?? '') === $issueId) {
                return $index;
            }
        }
        return -1;
    }

    private function getPriorityIcon(string $priority): string
    {
        return match ($priority) {
            'critical' => '🔴',
            'high' => '🟠',
            'medium' => '🟡',
            'low' => '🟢',
            default => '⚪'
        };
    }

    private function truncate(string $text, int $length): string
    {
        if (strlen($text) <= $length) {
            return $text;
        }
        return substr($text, 0, $length - 3) . '...';
    }

    private function generateMarkdownExport(array $issues): string
    {
        $lines = ['# Issue Export', '', 'Generated: ' . date('Y-m-d H:i:s'), ''];

        // Group by category
        $byCategory = [];
        foreach ($issues as $issue) {
            $cat = $issue['category'] ?? 'other';
            $byCategory[$cat][] = $issue;
        }

        foreach ($byCategory as $category => $categoryIssues) {
            $lines[] = '## ' . ucfirst($category);
            $lines[] = '';

            foreach ($categoryIssues as $issue) {
                $priorityIcon = $this->getPriorityIcon($issue['priority'] ?? 'medium');
                $lines[] = "### {$issue['id']}: {$issue['title']}";
                $lines[] = '';
                $lines[] = "- **Priority:** {$priorityIcon} {$issue['priority']}";
                $lines[] = "- **Status:** {$issue['status']}";
                if ($issue['assignee'] ?? '') {
                    $lines[] = "- **Assignee:** {$issue['assignee']}";
                }
                $lines[] = "- **Created:** {$issue['createdAt']}";

                if ($issue['description'] ?? '') {
                    $lines[] = '';
                    $lines[] = $issue['description'];
                }

                if (!empty($issue['comments'])) {
                    $lines[] = '';
                    $lines[] = '**Comments:**';
                    foreach ($issue['comments'] as $comment) {
                        $lines[] = "- [{$comment['timestamp']}] {$comment['text']}";
                    }
                }

                $lines[] = '';
            }
        }

        return implode("\n", $lines);
    }
}
