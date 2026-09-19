<?php

declare(strict_types=1);

namespace ClaudeProjectManager\Commands;

use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Standardized JSON response handling for AI-optimized commands
 */
trait JsonResponseTrait
{
    /**
     * Output standardized successful JSON response
     */
    protected function outputJsonSuccess(OutputInterface $output, string $action, array $data = [], array $metadata = []): void
    {
        $response = [
            'success' => true,
            'action' => $action,
            'timestamp' => date('Y-m-d H:i:s'),
        ];

        if (!empty($data)) {
            $response['data'] = $data;
        }

        if (!empty($metadata)) {
            $response['metadata'] = $metadata;
        }

        $output->writeln(json_encode($response, JSON_UNESCAPED_SLASHES));
    }

    /**
     * Output standardized error JSON response with actionable suggestions
     */
    protected function outputJsonError(OutputInterface $output, string $action, string $error, array $context = []): void
    {
        ErrorHandler::handleError($error, $output, true, $action, $context);
    }

    /**
     * Handle exception with enhanced error response
     */
    protected function handleJsonException(OutputInterface $output, string $action, \Exception $e, array $context = []): int
    {
        return ErrorHandler::handleException($e, $output, true, $action, $context);
    }

    /**
     * Output either JSON or human-readable response based on flag
     */
    protected function outputResponse(
        OutputInterface $output,
        bool $jsonFlag,
        string $action,
        callable $humanReadableCallback,
        array $jsonData = [],
        array $jsonMetadata = []
    ): void {
        if ($jsonFlag) {
            $this->outputJsonSuccess($output, $action, $jsonData, $jsonMetadata);
        } else {
            $humanReadableCallback();
        }
    }

    /**
     * Add standardized JSON option to command
     */
    protected function addJsonOption(): void
    {
        $this->addOption(
            'json',
            null,
            \Symfony\Component\Console\Input\InputOption::VALUE_NONE,
            'Output JSON format for AI consumption'
        );
    }

    /**
     * Get JSON flag from input
     */
    protected function isJsonRequested(\Symfony\Component\Console\Input\InputInterface $input): bool
    {
        return (bool) $input->getOption('json');
    }

    /**
     * Create SymfonyStyle only if not JSON mode (performance optimization)
     */
    protected function createStyleIfNeeded(
        \Symfony\Component\Console\Input\InputInterface $input,
        OutputInterface $output
    ): ?\Symfony\Component\Console\Style\SymfonyStyle {
        return $this->isJsonRequested($input) ? null : new SymfonyStyle($input, $output);
    }

    /**
     * Safe count for arrays or objects
     */
    protected function safeCount($value): int
    {
        if (is_array($value)) {
            return count($value);
        }
        if (is_object($value)) {
            return count((array) $value);
        }
        return 0;
    }

    /**
     * Standardized error handling for both JSON and human-readable output
     */
    protected function handleError(
        OutputInterface $output,
        bool $jsonFlag,
        string $action,
        string $errorMessage,
        array $context = []
    ): int {
        ErrorHandler::handleError($errorMessage, $output, $jsonFlag, $action, $context);
        return 1; // Command::FAILURE
    }
}