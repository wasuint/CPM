<?php

declare(strict_types=1);

namespace Tests\Unit\Compatibility;

use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use PHPUnit\Framework\TestCase;

/**
 * PHP 8.4 deprecates implicitly nullable parameters (`Foo $x = null`). With
 * display_errors on STDOUT the deprecation text corrupts `--json` output, so
 * every such parameter in src/ must be declared explicitly nullable.
 * The scan uses the AST, so multi-line signatures are covered too.
 */
final class ImplicitNullableTest extends TestCase
{
    public function test_src_has_no_implicitly_nullable_parameters(): void
    {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $finder = new NodeFinder();
        $offenders = [];

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(dirname(__DIR__, 3) . '/src', \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $ast = $parser->parse((string) file_get_contents($file->getPathname())) ?? [];
            foreach ($finder->findInstanceOf($ast, Node\Param::class) as $param) {
                if ($this->isImplicitlyNullable($param)) {
                    $offenders[] = $file->getPathname() . ':' . $param->getStartLine();
                }
            }
        }

        $this->assertSame([], $offenders);
    }

    private function isImplicitlyNullable(Node\Param $param): bool
    {
        $default = $param->default;
        if ($param->type === null || !$default instanceof Node\Expr\ConstFetch
            || strtolower($default->name->toString()) !== 'null') {
            return false;
        }
        $type = $param->type;
        if ($type instanceof Node\NullableType) {
            return false;
        }
        if ($type instanceof Node\Identifier) {
            return !in_array($type->toLowerString(), ['mixed', 'null'], true);
        }
        if ($type instanceof Node\UnionType) {
            foreach ($type->types as $member) {
                if ($member instanceof Node\Identifier && $member->toLowerString() === 'null') {
                    return false;
                }
            }
        }

        return true;
    }
}
