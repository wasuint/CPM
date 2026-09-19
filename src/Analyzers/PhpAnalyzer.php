<?php

declare(strict_types=1);

namespace ClaudeProjectManager\Analyzers;

use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use PhpParser\Node;
use ClaudeProjectManager\ConfigManager;
use ClaudeProjectManager\FileAnalysis;

/**
 * Converts a PhpParser Node for a type into its string representation.
 * This is a helper function to handle different type nodes from PhpParser.
 */
function getTypeAsString(?Node $typeNode): ?string
{
    if (!$typeNode) {
        return null;
    }

    if ($typeNode instanceof Node\NullableType) {
        return '?' . getTypeAsString($typeNode->type);
    }

    if ($typeNode instanceof Node\UnionType) {
        $types = array_map(__FUNCTION__, $typeNode->types);
        return implode('|', $types);
    }

    if ($typeNode instanceof Node\IntersectionType) {
        $types = array_map(__FUNCTION__, $typeNode->types);
        return implode('&', $types);
    }

    if ($typeNode instanceof Node\Name || $typeNode instanceof Node\Identifier) {
        return $typeNode->toString();
    }

    return (string)$typeNode;
}

/**
 * Specialized analyzer for PHP files using PHP-Parser
 */
class PhpAnalyzer
{
    private ParserFactory $parserFactory;
    private NodeTraverser $traverser;
    private ConfigManager $config;
    private array $analysisRules;

    public function __construct(ConfigManager $config)
    {
        $this->config = $config;
        $this->parserFactory = new ParserFactory();
        $this->traverser = new NodeTraverser();
        $this->analysisRules = $config->getAnalysisRules('php');
    }

    public function analyzeFile(string $filePath): FileAnalysis
    {
        if (!file_exists($filePath)) {
            throw new \RuntimeException("File not found: {$filePath}");
        }

        try {
            $code = file_get_contents($filePath);
            $parser = $this->parserFactory->create(ParserFactory::PREFER_PHP7);
            $ast = $parser->parse($code);

            if ($ast === null) {
                throw new \RuntimeException("Failed to parse PHP file: {$filePath}");
            }

            $functions = $this->extractFunctions($ast);
            $classes = $this->extractClasses($ast);
            $lines = count(file($filePath));
            $size = filesize($filePath);

            $metrics = [
                'lines' => $lines,
                'size' => $size,
                'functions_count' => count($functions),
                'classes_count' => count($classes)
            ];

            $issues = [];
            if ($lines > 500) {
                $issues[] = [
                    'type' => 'file_size',
                    'severity' => 'warning',
                    'message' => "File exceeds 500 lines ({$lines} lines)"
                ];
            }

            return new FileAnalysis(
                $filePath,
                'php',
                $functions,
                $classes,
                $metrics,
                $issues
            );

        } catch (\Exception $e) {
            throw new \RuntimeException("Failed to analyze PHP file {$filePath}: " . $e->getMessage(), 0, $e);
        }
    }

    private function extractFunctions(array $ast): array
    {
        $functions = [];
        $visitor = new class($functions) extends \PhpParser\NodeVisitorAbstract {
            private array $functions;
            private array $contextStack = [];

            public function __construct(array &$functions) {
                $this->functions = &$functions;
            }

            public function enterNode(Node $node) {
                // Regular function declarations
                if ($node instanceof Node\Stmt\Function_) {
                    $this->functions[] = [
                        'name' => $node->name->name,
                        'start_line' => $node->getStartLine(),
                        'end_line' => $node->getEndLine(),
                        'parameters' => $this->extractParameters($node->params),
                        'return_type' => getTypeAsString($node->returnType),
                        'visibility' => 'public',
                        'type' => 'function',
                        'context' => implode('::', $this->contextStack),
                        'body' => $this->extractBody($node)
                    ];
                }
                
                // Closures (anonymous functions)
                elseif ($node instanceof Node\Expr\Closure) {
                    $name = 'closure@' . $node->getStartLine();
                    $this->functions[] = [
                        'name' => $name,
                        'start_line' => $node->getStartLine(),
                        'end_line' => $node->getEndLine(),
                        'parameters' => $this->extractParameters($node->params),
                        'return_type' => getTypeAsString($node->returnType),
                        'visibility' => 'public',
                        'type' => 'closure',
                        'context' => implode('::', $this->contextStack),
                        'body' => $this->extractBody($node),
                        'uses' => $this->extractUses($node->uses ?? [])
                    ];
                }
                
                // Arrow functions (PHP 7.4+)
                elseif ($node instanceof Node\Expr\ArrowFunction) {
                    $name = 'arrow@' . $node->getStartLine();
                    $this->functions[] = [
                        'name' => $name,
                        'start_line' => $node->getStartLine(),
                        'end_line' => $node->getEndLine(),
                        'parameters' => $this->extractParameters($node->params),
                        'return_type' => getTypeAsString($node->returnType),
                        'visibility' => 'public',
                        'type' => 'arrow_function',
                        'context' => implode('::', $this->contextStack),
                        'body' => $this->extractArrowBody($node)
                    ];
                }
                
                // Track context for nested detection
                if ($node instanceof Node\Stmt\Class_) {
                    $className = $node->name ? $node->name->toString() : 'anonymous';
                    $this->contextStack[] = $className;
                } elseif ($node instanceof Node\Stmt\Function_) {
                    $this->contextStack[] = $node->name->name;
                }
            }

            public function leaveNode(Node $node) {
                // Pop context when leaving classes or functions
                if ($node instanceof Node\Stmt\Class_ || $node instanceof Node\Stmt\Function_) {
                    array_pop($this->contextStack);
                }
            }

            private function extractParameters(array $params): array {
                $parameters = [];
                foreach ($params as $param) {
                    if (!$param->var instanceof Node\Expr\Variable || !is_string($param->var->name)) {
                        continue;
                    }
                    $parameters[] = [
                        'name' => $param->var->name,
                        'type' => getTypeAsString($param->type),
                        'default' => $param->default ? 'yes' : 'no'
                    ];
                }
                return $parameters;
            }
            
            private function extractUses(array $uses): array {
                $usedVars = [];
                foreach ($uses as $use) {
                    if ($use->var instanceof Node\Expr\Variable && is_string($use->var->name)) {
                        $usedVars[] = [
                            'name' => $use->var->name,
                            'by_reference' => $use->byRef
                        ];
                    }
                }
                return $usedVars;
            }
            
            private function extractBody(Node $node): string {
                if ($node instanceof Node\Stmt\Function_ || $node instanceof Node\Expr\Closure) {
                    if (isset($node->stmts) && is_array($node->stmts)) {
                        $printer = new \PhpParser\PrettyPrinter\Standard();
                        return $printer->prettyPrint($node->stmts);
                    }
                }
                return '';
            }
            
            private function extractArrowBody(Node\Expr\ArrowFunction $node): string {
                $printer = new \PhpParser\PrettyPrinter\Standard();
                return $printer->prettyPrintExpr($node->expr);
            }
        };

        $this->traverser->addVisitor($visitor);
        $this->traverser->traverse($ast);
        $this->traverser->removeVisitor($visitor);

        return array_values($functions);
    }

    private function extractClasses(array $ast): array
    {
        $classes = [];
        $visitor = new class($classes) extends \PhpParser\NodeVisitorAbstract {
            private array $classes;

            public function __construct(array &$classes) {
                $this->classes = &$classes;
            }

            public function enterNode(Node $node) {
                if ($node instanceof Node\Stmt\Class_) {
                    $methods = [];
                    foreach ($node->stmts as $stmt) {
                        if ($stmt instanceof Node\Stmt\ClassMethod) {
                            $methods[] = [
                                'name' => $stmt->name->name,
                                'start_line' => $stmt->getStartLine(),
                                'end_line' => $stmt->getEndLine(),
                                'visibility' => $this->getVisibility($stmt),
                                'parameters' => $this->extractParameters($stmt->params),
                                'return_type' => getTypeAsString($stmt->returnType),
                                'type' => 'method',
                                'static' => $stmt->isStatic(),
                                'abstract' => $stmt->isAbstract(),
                                'final' => $stmt->isFinal(),
                                'body' => $this->extractMethodBody($stmt)
                            ];
                        }
                    }

                    $className = $node->name ? $node->name->toString() : 'anonymous@' . $node->getStartLine();

                    $this->classes[] = [
                        'name' => $className,
                        'line_start' => $node->getStartLine(),
                        'line_end' => $node->getEndLine(),
                        'methods' => array_values($methods),
                        'extends' => $node->extends ? (string)$node->extends : null,
                        'implements' => array_map('strval', $node->implements)
                    ];
                }
            }

            private function getVisibility(Node\Stmt\ClassMethod $method): string {
                if ($method->isPublic()) return 'public';
                if ($method->isProtected()) return 'protected';
                if ($method->isPrivate()) return 'private';
                return 'public';
            }

            private function extractParameters(array $params): array {
                $parameters = [];
                foreach ($params as $param) {
                    if (!$param->var instanceof Node\Expr\Variable || !is_string($param->var->name)) {
                        continue; // Skip invalid parameters
                    }
                    $parameters[] = [
                        'name' => $param->var->name,
                        'type' => getTypeAsString($param->type),
                        'default' => $param->default ? 'yes' : 'no'
                    ];
                }
                return array_values($parameters);
            }
            
            private function extractMethodBody(Node\Stmt\ClassMethod $method): string {
                if (isset($method->stmts) && is_array($method->stmts)) {
                    $printer = new \PhpParser\PrettyPrinter\Standard();
                    return $printer->prettyPrint($method->stmts);
                }
                return '';
            }
        };

        $this->traverser->addVisitor($visitor);
        $this->traverser->traverse($ast);
        $this->traverser->removeVisitor($visitor);

        return array_values($classes);
    }
}
